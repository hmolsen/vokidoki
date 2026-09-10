<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/cost.php';
require_once __DIR__ . '/../lib/errors.php';
require_once __DIR__ . '/../lib/keyvault.php';
require_once __DIR__ . '/../lib/schema.php';
require_once __DIR__ . '/../lib/wordtypes.php';

boot_error_handling();

session_boot();

// Bringt das Schema auf den Stand des Codes, falls per FTP aktualisiert wurde.
ensure_schema();

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function admin_url(string $file = 'index.php'): string
{
    return url('/admin/' . $file);
}

// ---------------------------------------------------------------- CSRF

function csrf_token(): string
{
    if (empty($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['admin_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

/** Prüft das CSRF-Token jedes schreibenden Formulars. */
function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(403);
        exit('Ungültiges Formular-Token. Bitte Seite neu laden.');
    }
}

// ---------------------------------------------------------------- Anmeldung

function admin_logged_in(): bool
{
    return !empty($_SESSION['is_admin']);
}

/**
 * Erzwingt die Admin-Anmeldung. Beim ersten Login wird das Passwort aus
 * config.php übernommen und als Hash in der Datenbank abgelegt; danach ist
 * der Wert in der Konfiguration wirkungslos.
 */
function admin_require(): void
{
    if (admin_logged_in()) {
        return;
    }

    $error = null;

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['admin_password'])) {
        csrf_check();
        $password = (string) $_POST['admin_password'];
        $hash     = setting('admin_password_hash', '');

        if ($hash === '') {
            $bootstrap = (string) cfg('admin_bootstrap_password', '');
            if ($bootstrap !== '' && hash_equals($bootstrap, $password)) {
                setting_set('admin_password_hash', password_hash($password, PASSWORD_DEFAULT));
                $hash = setting('admin_password_hash', '');
            }
        }

        if ($hash !== '' && password_verify($password, $hash)) {
            session_regenerate_id(true);
            $_SESSION['is_admin'] = true;
            header('Location: ' . admin_url());
            exit;
        }

        usleep(random_int(200_000, 500_000));
        $error = 'Passwort stimmt nicht.';
    }

    admin_login_page($error);
}

function admin_login_page(?string $error): never
{
    ?>
    <!doctype html>
    <html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>Admin - Vokabeltrainer</title>
        <link rel="stylesheet" href="<?= h(url('/style.css')) ?>">
        <link rel="stylesheet" href="<?= h(admin_url('admin.css')) ?>">
    </head>
    <body>
    <div class="app" style="max-width:420px">
        <div class="center" style="margin:12vh 0 6px">
            <div style="font-size:3rem">&#128274;</div>
            <h1>Administration</h1>
            <p class="sub">Vokabeltrainer</p>
        </div>
        <?php if ($error !== null): ?>
            <div class="notice"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="post" class="card">
            <?= csrf_field() ?>
            <label for="pw">Passwort</label>
            <input type="password" id="pw" name="admin_password" autocomplete="current-password" autofocus required>
            <button class="btn" type="submit">Anmelden</button>
        </form>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// ---------------------------------------------------------------- Layout

function admin_head(string $title, string $active): void
{
    $nav = [
        'index.php'     => 'Kosten',
        'users.php'     => 'Accounts',
        'vocab.php'     => 'Vokabeln',
        'settings.php'  => 'Einstellungen',
        'selfcheck.php' => 'Selbsttest',
    ];
    ?>
    <!doctype html>
    <html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title><?= h($title) ?> - Vokabeltrainer Admin</title>
        <link rel="stylesheet" href="<?= h(url('/style.css')) ?>">
        <link rel="stylesheet" href="<?= h(admin_url('admin.css')) ?>">
    </head>
    <body class="admin">
    <header class="adminbar">
        <strong>Vokabeltrainer</strong>
        <nav>
            <?php foreach ($nav as $file => $label): ?>
                <a href="<?= h(admin_url($file)) ?>"<?= $file === $active ? ' class="on"' : '' ?>><?= h($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <form method="post" action="<?= h(admin_url('index.php')) ?>" class="logout">
            <?= csrf_field() ?>
            <button name="admin_logout" value="1" class="linkbtn">Abmelden</button>
        </form>
    </header>
    <main class="adminmain">
        <h1><?= h($title) ?></h1>
    <?php
}

function admin_foot(): void
{
    echo '</main></body></html>';
}

/** Meldung für die nächste Seite hinterlegen (Redirect-nach-POST). */
function flash(string $message, string $kind = 'good'): void
{
    $_SESSION['admin_flash'] = ['msg' => $message, 'kind' => $kind];
}

function flash_render(): void
{
    $f = $_SESSION['admin_flash'] ?? null;
    unset($_SESSION['admin_flash']);
    if (is_array($f)) {
        printf('<div class="notice %s">%s</div>', h($f['kind']), h($f['msg']));
    }
}

/** Nach einem POST immer weiterleiten, damit Neuladen nichts doppelt ausführt. */
function redirect(string $file): never
{
    header('Location: ' . admin_url($file));
    exit;
}
