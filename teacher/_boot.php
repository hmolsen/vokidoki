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
 * Die Antwort abschicken und danach weiterarbeiten.
 *
 * Der Kern von teacher_redirect_and_continue(), aber ohne Weiterleitung -
 * gebraucht dort, wo die Antwort JSON ist: Die frische Vokabelzeile steht
 * beim Tippenden schon, waehrend der Lueckensatz dazu noch entsteht.
 */
function teacher_flush_and_continue(): void
{
    ignore_user_abort(true);
    header('Connection: close');

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
 * Die Leiste oben: zwei Menüs und ein Name.
 *
 * Hier stand ein Pfad aus Knöpfen - Haus › Kurs › Lerneinheit -, und am
 * Rechner war das richtig. Auf einem Telefon nicht: Drei Knöpfe mit
 * Kursnamen darin brauchen zwei Zeilen, und die Leiste war damit so hoch
 * wie der halbe Bildschirm. Was man selten braucht, darf nicht dauernd
 * dastehen.
 *
 * Jetzt links ein Burger, rechts der eigene Name und ein zweiter Knopf
 * derselben Bauart. Beide schieben eine Leiste herein: links die
 * Navigation, rechts das eigene Konto.
 *
 * Gebaut als <details>, nicht als Skript: Der Browser kann das Auf- und
 * Zuklappen von selbst, mit Tastatur und Vorleseprogramm, und ohne
 * JavaScript funktioniert es genauso - nur ohne das Hereinschieben und
 * ohne den Schleier, der sich wegklicken lässt.
 */
function teacher_nav(array $user, ?int $kursId = null): void
{
    $meine = courses_for_teacher((int) $user['id'], (int) ($user['school_id'] ?? 0));
    ?>
<div class="adminbar">
    <details class="menue" id="menuLinks">
        <summary class="burger" aria-label="Menü" title="Menü">
            <span aria-hidden="true">&#9776;</span>
        </summary>
        <span class="schleier" data-zu></span>
        <nav class="schublade" aria-label="Navigation">
            <a class="mitem haupt" href="<?= h(teacher_url('index.php')) ?>">
                <span class="micon" aria-hidden="true">&#127968;</span>
                <span>Meine Kurse</span>
            </a>

            <?php
            /*
             * Die eigenen Kurse eingerückt darunter - der Wechsel von
             * "Englisch - 5a" nach "Französisch - 7b" ist von jeder Seite
             * aus ein Griff. Genau das konnte vorher der Kurskrumen, und
             * genau das ist von ihm übriggeblieben.
             */
            ?>
            <?php if ($meine === []): ?>
                <p class="mleer tiny muted">Noch kein eigener Kurs.</p>
            <?php else: ?>
                <div class="mgruppe">
                    <?php foreach ($meine as $k): ?>
                        <a class="mitem<?= (int) $k['id'] === (int) $kursId ? ' on' : '' ?>"
                           href="<?= h(teacher_url('course.php') . '?id=' . (int) $k['id']) ?>"
                           <?= (int) $k['id'] === (int) $kursId ? 'aria-current="page"' : '' ?>>
                            <span class="micon" aria-hidden="true"><?=
                                flag_html((string) $k['flag_emoji'] ?: FLAG_FALLBACK, 'mflagge')
                            ?></span>
                            <span><?= h((string) $k['name']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <a class="mitem" href="<?= h(teacher_url('index.php') . '#alle') ?>">
                <span class="micon" aria-hidden="true">&#127979;</span>
                <span>Alle Kurse der Schule</span>
            </a>

            <hr class="mtrenner">

            <a class="mitem" href="<?= h(teacher_url('classes.php')) ?>">
                <span class="micon" aria-hidden="true">&#128101;</span>
                <span>Klassen und Kinder</span>
            </a>
        </nav>
    </details>

    <span class="barname"><?= h($user['display_name']) ?></span>

    <?php
    /*
     * Rechts dasselbe noch einmal, für das eigene Konto.
     *
     * Es lag einmal in der App - Name, Farbe und Passwort sind dieselben,
     * egal von welcher Seite man kommt, und eine zweite Fassung davon
     * schien zwei Orte für eine Sache. In der Bedienung war es das
     * Gegenteil: Wer hier drückte, stand in einer anderen Anwendung, und
     * der Zurück-Knopf führte an den Anfang der Kinderansicht. Jetzt
     * bleibt man hier; doppelt ist nur die Oberfläche, die Regeln stehen
     * einmal in lib/profile.php.
     *
     * Und Abmelden ist ein Knopf, kein unterstrichenes Wort: Es tut etwas,
     * statt woandershin zu führen.
     */
    ?>
    <details class="menue rechts" id="menuRechts">
        <summary class="burger" aria-label="Einstellungen" title="Einstellungen">
            <span aria-hidden="true">&#9881;</span>
        </summary>
        <span class="schleier" data-zu></span>
        <nav class="schublade" aria-label="Einstellungen">
            <a class="mitem" href="<?= h(teacher_url('konto.php')) ?>">
                <span class="micon" aria-hidden="true">&#128100;</span>
                <span>Mein Profil</span>
            </a>
            <a class="mitem" href="<?= h(teacher_url('konto.php') . '#passwort') ?>">
                <span class="micon" aria-hidden="true">&#128273;</span>
                <span>Passwort ändern</span>
            </a>

            <hr class="mtrenner">

            <form method="post" action="<?= h(teacher_url('index.php')) ?>">
                <?= teacher_csrf_field() ?>
                <button class="mitem" name="teacher_logout" value="1">
                    <span class="micon" aria-hidden="true">&#9099;</span>
                    <span>Abmelden</span>
                </button>
            </form>
        </nav>
    </details>
</div>
    <?php
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

/**
 * Der Kopf der Seite.
 *
 * $titelHtml ersetzt die Ueberschrift durch fertiges HTML, wenn sie mehr
 * ist als ein Wort - die Lerneinheit setzt dort den Kurs als Knopf, ihren
 * eigenen Namen und den Stift zum Umbenennen hinein. $title bleibt
 * trotzdem noetig: Er steht im Titel des Fensters, und dort hat Auszeichnung
 * nichts zu suchen.
 *
 * $kursId markiert den aktuellen Kurs im Menue links.
 */
function teacher_head(
    string $title,
    array $user,
    string $neben = '',
    string $titelHtml = '',
    ?int $kursId = null,
): void {
    ?><!doctype html>
<html lang="de"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> - Vokabeltrainer</title>
<link rel="stylesheet" href="<?= h(url('/style.css')) ?>">
<link rel="stylesheet" href="<?= h(url('/admin/admin.css')) ?>">
</head><body class="admin" data-base="<?= h(base_path()) ?>">
<?php teacher_nav($user, $kursId); ?>
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
    <h1><?= $titelHtml === '' ? h($title) : $titelHtml ?></h1>
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
