<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Ein Jahr - das Homescreen-Symbol soll dauerhaft angemeldet bleiben. */
const SESSION_LIFETIME = 60 * 60 * 24 * 365;

/**
 * Startet die Session.
 *
 * Wichtig für iOS: Jede Homescreen-Web-App hat einen eigenen, von Safari
 * getrennten Cookie-Container. Dieselbe Session-Konfiguration gilt in beiden
 * Welten, die Cookies sind aber voneinander unabhängig - genau darauf baut
 * der Geräte-Token-Login auf.
 */
function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    // Eigenes Verzeichnis für die Sitzungsdateien. Der Standardpfad des
    // Servers existiert bei geteiltem Hosting nicht immer - dann scheitert
    // session_start() und die Anmeldung funktioniert nicht. Außerdem liegen
    // die Sitzungen so nicht im selben Topf wie die anderer Anwendungen.
    $dir = storage_path('sessions');
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
        // Passend zur Lebensdauer des Cookies: Das Homescreen-Symbol soll
        // angemeldet bleiben, ohne dass die Sitzungsdatei vorher weggeräumt wird.
        ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '1000');
    }

    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => base_path() . '/',
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Lax',
    ]);
    session_name('vtsess');
    session_start();
}

function current_user(): ?array
{
    session_boot();
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) {
        return null;
    }

    static $user = null;
    if ($user === null || (int) $user['id'] !== (int) $id) {
        $user = q1('SELECT * FROM users WHERE id = ? AND active = 1', [(int) $id]);
        if ($user === null) {
            unset($_SESSION['user_id']);
            return null;
        }
    }
    return $user;
}

/** Beendet den Request mit 401, wenn niemand eingeloggt ist. */
function require_user(): array
{
    $u = current_user();
    if ($u === null) {
        require_once __DIR__ . '/json.php';
        json_fail('Nicht angemeldet.', 401, ['auth' => false]);
    }
    return $u;
}

function login_user(int $userId): void
{
    session_boot();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
}

function logout_user(): void
{
    session_boot();
    $_SESSION = [];
    session_destroy();
}

// ---------------------------------------------------------------- Geräte-Token

/**
 * Erzeugt einen neuen Geräte-Token (ein Homescreen-Icon).
 * Gibt den Klartext zurück - gespeichert wird ausschließlich der SHA-256-Hash.
 */
function device_token_create(int $userId, string $label = ''): string
{
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    q(
        'INSERT INTO device_tokens (user_id, token_hash, label) VALUES (?, ?, ?)',
        [$userId, hash('sha256', $token), $label !== '' ? mb_substr($label, 0, 128) : null],
    );
    return $token;
}

/** Löst einen Token in einen aktiven Nutzer auf; null bei unbekannt/widerrufen/inaktiv. */
function device_token_user(string $token): ?array
{
    if ($token === '' || strlen($token) > 128) {
        return null;
    }

    $row = q1(
        'SELECT dt.id AS token_id, u.*
           FROM device_tokens dt
           JOIN users u ON u.id = dt.user_id
          WHERE dt.token_hash = ? AND dt.revoked_at IS NULL AND u.active = 1',
        [hash('sha256', $token)],
    );
    if ($row === null) {
        return null;
    }

    q('UPDATE device_tokens SET last_used_at = NOW() WHERE id = ?', [$row['token_id']]);
    unset($row['token_id']);
    return $row;
}

/**
 * Besitzform des Anzeigenamens: "Lilli" -> "Lillis", "Max" -> "Max'".
 *
 * Ein Name, der schon auf einen Zischlaut endet, bekommt KEIN zweites s -
 * "Hannes" wird nicht zu "Hanness", sondern zu "Hannes'". So steht es im
 * Duden, und auf dem Home-Bildschirm steht der Name eines Kindes.
 */
function possessive(string $name): string
{
    $last = mb_strtolower(mb_substr($name, -1));
    return in_array($last, ['s', 'x', 'z', 'ß'], true) ? $name . "'" : $name . 's';
}

/** App-Name für Manifest und iOS-Homescreen, z. B. "Lillis Vokidoki". */
function app_name_for(array $user): string
{
    return possessive($user['display_name']) . ' Vokidoki';
}
