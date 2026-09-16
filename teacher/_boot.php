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
        teacher_redirect('classes.php');
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
 * Das erste Glied des Pfades: die Schule.
 *
 * Es steht auf jeder Seite und fuehrt zur Uebersicht aller Klassen. Damit
 * ist die Schule zugleich die Wurzel der Navigation - eine Lehrkraft
 * arbeitet immer in genau einer, und ein Reiter "Klassen" neben dem Namen
 * der Schule waere dasselbe zweimal.
 */
function teacher_school_crumb(array $user): array
{
    $name = (string) qv('SELECT name FROM schools WHERE id = ?',
                        [(int) ($user['school_id'] ?? 0)]);

    return [
        'label' => $name !== '' ? $name : 'Ohne Schule',
        'href'  => teacher_url('classes.php'),
    ];
}

function teacher_head(string $title, array $user, array $crumbs = []): void
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
    <span class="tiny muted" style="margin-left:auto">
        <?= h($user['display_name']) ?>
    </span>
    <form method="post" action="<?= h(teacher_url('classes.php')) ?>" class="compact">
        <?= teacher_csrf_field() ?>
        <button class="linkbtn" name="teacher_logout" value="1">Abmelden</button>
    </form>
</div>
<main class="adminmain">
<h1><?= h($title) ?></h1>
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
