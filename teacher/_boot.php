<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/html.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/access.php';
require_once __DIR__ . '/../lib/courses.php';
require_once __DIR__ . '/../lib/roster.php';
require_once __DIR__ . '/../lib/worldlanguages.php';
require_once __DIR__ . '/../lib/flags.php';
require_once __DIR__ . '/../lib/throttle.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/errors.php';
require_once __DIR__ . '/../lib/schema.php';
require_once __DIR__ . '/../lib/version.php';

boot_error_handling();
session_boot();

/*
 * Der Bereich für Lehrkräfte.
 *
 * Aufgebaut wie das Admin-Backend - dieselben Formularmuster, dieselben
 * Tabellen, dasselbe "nach jedem POST wird weitergeleitet". Der Unterschied
 * steckt in der Anmeldung: Hier meldet sich ein echtes Konto aus der
 * users-Tabelle mit der Rolle "teacher" an, nicht ein gemeinsames Passwort.
 * Damit ist auch klar, wessen Kurse gezeigt werden.
 *
 * Bewusst KEIN ensure_schema(): Schemaänderungen sind Sache des Betreibers.
 * Liefen sie auch hier, könnten mehrere Lehrkräfte gleichzeitig dasselbe
 * ALTER anstossen. Stattdessen wird geprüft, ob etwas aussteht, und in dem
 * Fall nicht weitergearbeitet - lieber eine verständliche Meldung als eine
 * Oberfläche, die auf einem halben Schema rechnet.
 */

function teacher_url(string $file = 'index.php'): string
{
    return url('/teacher/' . $file);
}

function teacher_csrf_token(): string
{
    if (empty($_SESSION['teacher_csrf'])) {
        $_SESSION['teacher_csrf'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['teacher_csrf'];
}

function teacher_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(teacher_csrf_token()) . '">';
}

function teacher_csrf_check(): void
{
    $sent = (string) ($_POST['csrf'] ?? '');
    if ($sent === '' || !hash_equals(teacher_csrf_token(), $sent)) {
        http_response_code(403);
        exit('Abgelaufen. Bitte die Seite neu laden.');
    }
}

function teacher_flash(string $message, string $kind = 'good'): void
{
    $_SESSION['teacher_flash'] = ['text' => $message, 'kind' => $kind];
}

function teacher_flash_render(): void
{
    $f = $_SESSION['teacher_flash'] ?? null;
    unset($_SESSION['teacher_flash']);
    if (is_array($f)) {
        printf('<div class="notice %s">%s</div>', h($f['kind']), h($f['text']));
    }
}

function teacher_redirect(string $file): never
{
    header('Location: ' . teacher_url($file));
    exit;
}

/**
 * Weiterleiten und danach noch weiterarbeiten.
 *
 * Dasselbe Mittel wie json_out_and_continue() beim Einlesen, nur für eine
 * HTML-Seite: Das Freigeben stösst die Satzerzeugung an, und die dauert je
 * Portion um die zwanzig Sekunden. Die Lehrkraft soll währenddessen ihre
 * Seite sehen und nicht in einen Zeitablauf laufen.
 *
 * Bleibt der Vorgang trotzdem stecken - kein FPM, ein Server, der nicht
 * durchlässt -, ist nichts verloren: Der Zustand steht auf "läuft", und der
 * Weg über den Lückentext des Kindes stösst denselben Lauf noch einmal an.
 * Dieser Trick ist eine Abkürzung, kein tragender Teil.
 */
function teacher_redirect_and_continue(string $file): void
{
    $stray = ob_get_level() > 0 ? (string) ob_get_clean() : '';
    if (trim($stray) !== '') {
        error_log('[vokabeltrainer] Unerwartete Ausgabe vor der Weiterleitung: '
            . substr(trim($stray), 0, 500));
    }

    ignore_user_abort(true);

    http_response_code(303);
    header('Location: ' . teacher_url($file));
    header('Content-Length: 0');
    header('Connection: close');

    // Die Sitzung freigeben, sonst wartet die weitergeleitete Anfrage
    // derselben Lehrkraft auf das Ende dieses Vorgangs.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}

/**
 * Sorgt für eine angemeldete Lehrkraft - oder zeigt die Anmeldung.
 *
 * Die Sitzung ist dieselbe wie in der App. Das ist Absicht: Zum Einlesen der
 * Buchseiten braucht die Lehrkraft die Kamera und damit die PWA, und sie soll
 * sich dafür nicht ein zweites Mal anmelden.
 */
function teacher_require(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['teacher_login'])) {
        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');

        $ip    = login_client_ip();
        $sperr = login_guard($username, $ip);
        if ($sperr !== null) {
            teacher_login_page($sperr);
        }

        $row = q1('SELECT * FROM users WHERE username = ? AND active = 1', [$username]);

        // password_verify auch ohne Treffer aufrufen, damit die Antwortzeit
        // nichts über vorhandene Konten verrät.
        $hash = $row['password_hash'] ?? '$2y$12$' . str_repeat('.', 53);
        if ($row === null || !password_verify($password, $hash) || !user_is_teacher($row)) {
            login_attempt_record($username, $ip);
            usleep(random_int(200_000, 500_000));
            teacher_login_page('Benutzername oder Passwort stimmt nicht.');
        }

        login_attempts_reset($username);

        login_user((int) $row['id']);
        teacher_redirect('index.php');
    }

    $user = current_user();
    if ($user === null) {
        teacher_login_page(null);
    }
    if (!user_is_teacher($user)) {
        teacher_login_page('Dieses Konto ist keine Lehrkraft.');
    }

    // Ein halbes Schema ist schlimmer als eine Pause.
    $offen = schema_pending();
    if ($offen !== []) {
        error_log('[vokabeltrainer] Lehrkraft-Bereich wartet auf Schema: '
                  . implode(', ', $offen));
        teacher_blocked_page();
    }

    return $user;
}

function teacher_login_page(?string $error): never
{
    http_response_code($error === null ? 200 : 401);
    ?><!doctype html>
<html lang="de"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Anmeldung - Vokabeltrainer</title>
<link rel="stylesheet" href="<?= h(url('/style.css')) ?>">
<link rel="stylesheet" href="<?= h(url('/admin/admin.css')) ?>">
</head><body class="admin"><main class="adminmain" style="max-width:420px">
<h1>Vokabeltrainer</h1>
<p class="muted">Bereich für Lehrkräfte</p>
<?php if ($error !== null): ?><div class="notice"><?= h($error) ?></div><?php endif; ?>
<form method="post" class="card">
    <?= teacher_csrf_field() ?>
    <label for="u">Benutzername</label>
    <input type="text" id="u" name="username" autocapitalize="off" autocomplete="username" autofocus>
    <label for="p">Passwort</label>
    <input type="password" id="p" name="password" autocomplete="current-password">
    <button class="btn" name="teacher_login" value="1">Anmelden</button>
</form>
</main></body></html>
    <?php
    exit;
}

function teacher_blocked_page(): never
{
    http_response_code(503);
    ?><!doctype html>
<html lang="de"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kurz Geduld - Vokabeltrainer</title>
<link rel="stylesheet" href="<?= h(url('/style.css')) ?>">
<link rel="stylesheet" href="<?= h(url('/admin/admin.css')) ?>">
</head><body class="admin"><main class="adminmain" style="max-width:520px">
<h1>Kurz Geduld</h1>
<div class="notice">
    Die Anwendung wurde aktualisiert, die Datenbank ist aber noch nicht so weit.
    Das erledigt der Betreiber mit einem Aufruf des Admin-Bereichs. Bitte in
    ein paar Minuten noch einmal versuchen.
</div>
</main></body></html>
    <?php
    exit;
}

/**
 * Der Pfad, und er ist zugleich die Navigation.
 *
 * Schule > Klasse > Kurs > Lerneinheit. Vorher standen oben zwei feste
 * Reiter ("Klassen", "Kurse") und darunter, auf manchen Seiten, ein Pfad -
 * zwei Navigationen uebereinander, die dasselbe meinten. Jetzt gibt es eine:
 * Der Pfad steht in der Leiste, und sein erstes Glied ist die Schule. Wer
 * dorthin klickt, sieht alle Klassen.
 *
 * Jeder Eintrag: ['label' => ..., 'href' => ... oder null, 'flag' => ...].
 * Ohne href wird daraus die aktuelle Seite - kein Knopf, sondern die
 * Beschriftung, die zeigt, wo man steht.
 */
function teacher_crumbs(array $crumbs): void
{
    if ($crumbs === []) {
        return;
    }

    echo '<nav class="crumbs" aria-label="Pfad">';
    $erster = true;
    foreach ($crumbs as $c) {
        if (!$erster) {
            echo '<span class="crumbsep" aria-hidden="true">&#8250;</span>';
        }
        $erster = false;

        $inhalt = (($c['flag'] ?? '') !== '' ? flag_html((string) $c['flag'], 'crumbflag') : '')
                . '<span>' . h($c['label']) . '</span>';

        /*
         * Ein Krumen mit Menü: der Weg zu den Geschwistern.
         *
         * Wer Englisch in der 5a und Französisch in der 7b gibt, musste
         * bisher hoch zur Schule und durch eine andere Klasse wieder
         * hinunter. Hier hängt die Liste am Namen des Kurses selbst.
         *
         * <details> und kein Skript: Der Browser kann das Auf- und Zuklappen
         * von sich aus, mit Tastatur und Vorleseprogramm. Ein eigenes Panel
         * müsste seine Lage von Hand berechnen, wie beim Sprachfeld - das
         * lohnt für eine Liste, die nur aufklappt, nicht.
         */
        if (($c['menu'] ?? []) !== []) {
            echo '<details class="crumb crumbmenu"><summary>' . $inhalt
               . '<span class="crumbchev" aria-hidden="true">&#9662;</span></summary><div>';
            foreach ($c['menu'] as $m) {
                printf(
                    '<a href="%s"%s>%s%s</a>',
                    h((string) $m['href']),
                    ($m['on'] ?? false) ? ' class="on" aria-current="page"' : '',
                    ($m['flag'] ?? '') !== '' ? flag_html((string) $m['flag'], 'crumbflag') : '',
                    h((string) $m['label']),
                );
            }
            echo '</div></details>';
            continue;
        }

        if (($c['href'] ?? null) === null) {
            printf('<span class="crumb on" aria-current="page">%s</span>', $inhalt);
        } else {
            printf('<a class="crumb" href="%s">%s</a>', h((string) $c['href']), $inhalt);
        }
    }
    echo "</nav>
";
}

/**
 * Der Krumen fuer einen Kurs - mit der Liste der eigenen Kurse daran.
 *
 * Gebraucht von course.php und unit.php. Wer keinen zweiten eigenen Kurs
 * hat, bekommt keine Liste: Ein Menue mit einem Eintrag ist ein Menue zu
 * viel.
 */
function teacher_course_crumb(array $user, array $kurs, bool $aktuell): array
{
    $krumen = [
        'label' => (string) $kurs['name'],
        'href'  => $aktuell ? null : teacher_url('course.php') . '?id=' . (int) $kurs['id'],
        'flag'  => (string) ($kurs['flag_emoji'] ?? ''),
    ];

    $meine = courses_for_teacher((int) $user['id'], (int) ($user['school_id'] ?? 0));
    if (count($meine) < 2) {
        return $krumen;
    }

    $krumen['menu'] = [];
    foreach ($meine as $k) {
        $krumen['menu'][] = [
            'label' => (string) $k['name'],
            'href'  => teacher_url('course.php') . '?id=' . (int) $k['id'],
            'flag'  => (string) $k['flag_emoji'],
            'on'    => (int) $k['id'] === (int) $kurs['id'],
        ];
    }
    $krumen['menu'][] = [
        'label' => 'Alle Kurse der Schule',
        'href'  => teacher_url('index.php'),
    ];
    // Und der Weg zu einem, den es noch nicht gibt - aus jedem Kurs heraus.
    $krumen['menu'][] = [
        'label' => '+ Neuer Kurs',
        'href'  => teacher_url('neu.php'),
    ];

    return $krumen;
}

/**
 * Das erste Glied des Pfades: die Schule.
 *
 * Es steht auf jeder Seite und fuehrt auf die eigenen Kurse. Damit ist die
 * Schule die Wurzel der Navigation - eine Lehrkraft arbeitet immer in genau
 * einer -, und die Wurzel ist zugleich der Arbeitsplatz. Was daran haengt,
 * sind Ziele und keine Durchgaenge: der Kurs, und von ihm aus die Klasse.
 */
function teacher_school_crumb(array $user): array
{
    $name = (string) qv('SELECT name FROM schools WHERE id = ?',
                        [(int) ($user['school_id'] ?? 0)]);

    return [
        'label' => $name !== '' ? $name : 'Ohne Schule',
        'href'  => teacher_url('index.php'),
    ];
}

/**
 * „Hier ist noch nichts" - immer gleich aussehend.
 *
 * Es gab vier Formen dafuer: eine Karte, ein grauer Absatz, ein Hinweisband
 * und an einer Stelle gar nichts. Ein Leerzustand ist aber immer dasselbe:
 * eine Feststellung und, wenn es einen gibt, der Weg heraus.
 *
 * Nicht zu verwechseln mit teacher_flash() und div.notice - die melden, was
 * gerade geschehen ist oder schiefsteht. Das hier beschreibt einen Zustand.
 */
function teacher_leer(string $text, string $knoepfe = ''): string
{
    return '<div class="leer"><p>' . $text . '</p>'
         . ($knoepfe === '' ? '' : '<div class="buttonrow">' . $knoepfe . '</div>')
         . '</div>';
}

function teacher_head(string $title, array $user, array $crumbs = [], string $neben = ''): void
{
    ?><!doctype html>
<html lang="de"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> - Vokabeltrainer</title>
<link rel="stylesheet" href="<?= h(url('/style.css')) ?>">
<link rel="stylesheet" href="<?= h(url('/admin/admin.css')) ?>">
</head><body class="admin" data-base="<?= h(base_path()) ?>">
<div class="adminbar">
    <?php teacher_crumbs(array_merge([teacher_school_crumb($user)], $crumbs)); ?>
    <?php
    /*
     * Rechts das eigene Konto.
     *
     * Die Einstellungen liegen in der App, nicht hier - Name, Farbe und
     * Passwort sind dieselben, egal von welcher Seite man kommt, und eine
     * zweite Fassung davon im Lehrkraft-Bereich waeren zwei Orte fuer eine
     * Sache. Das Zahnrad ist dasselbe wie in der App.
     *
     * Und Abmelden ist ein Knopf, kein unterstrichenes Wort: Es tut etwas,
     * statt woandershin zu fuehren.
     */
    ?>
    <span class="barright">
        <span class="tiny muted"><?= h($user['display_name']) ?></span>
        <a class="iconbtn" href="<?= h(url('/') . '#/konto') ?>"
           title="Mein Konto: Name, Farbe, Passwort" aria-label="Mein Konto">&#9881;</a>
        <form method="post" action="<?= h(teacher_url('index.php')) ?>" class="compact">
            <?= teacher_csrf_field() ?>
            <button class="btn small secondary" name="teacher_logout" value="1">
                Abmelden
            </button>
        </form>
    </span>
</div>
<main class="adminmain">
<?php
/*
 * Die Ueberschrift, und daneben Platz fuer einen Knopf.
 *
 * Gebraucht von Kurs und Lerneinheit: Von dort fuehrt ein Weg in die
 * Schueleransicht - nicht als Randnotiz weiter unten, sondern dort, wo der
 * Name der Sache steht.
 */
?>
<div class="titelzeile">
    <h1><?= h($title) ?></h1>
    <?= $neben ?>
</div>
    <?php
}

function teacher_foot(): void
{
    // Der Versionsstempel in der Adresse: Sonst liefert der Browser nach
    // einer Aenderung noch tagelang die alte Fassung aus.
    printf("<script src=\"%s\"></script>\n",
        h(url('/teacher/teacher.js?v=' . app_version())));
    echo "</main></body></html>\n";
}
