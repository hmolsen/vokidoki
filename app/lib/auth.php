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

/**
 * Beendet den Request mit 401, wenn niemand eingeloggt ist - und mit 403,
 * solange das Konto die Hinweise der ersten Anmeldung nicht bestätigt hat.
 *
 * Die zweite Schranke steht hier und nicht in der App: Die App zeigt die
 * Seite zum Bestätigen, aber wer die API direkt anspricht, soll ohne
 * Bestätigung genauso wenig an Vokabeln und Lernstand kommen. Die beiden
 * Aufrufe, die davor gehen müssen - "me" und das Bestätigen selbst - stehen
 * in api/auth.php und fragen current_user() statt dieser Funktion.
 */
function require_user(): array
{
    $u = current_user();
    if ($u === null) {
        require_once __DIR__ . '/json.php';
        json_fail('Nicht angemeldet.', 401, ['auth' => false]);
    }

    require_once __DIR__ . '/einwilligung.php';
    if (einwilligung_noetig($u)) {
        require_once __DIR__ . '/json.php';
        json_fail('Bitte bestätige zuerst die Hinweise.', 403, ['einwilligung' => true]);
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
 * Start aus einem Symbol auf dem Home-Bildschirm: den Token der start_url
 * gegen eine Sitzung in *diesem* Container tauschen.
 *
 * iOS führt eine installierte Web-App mit eigenen Cookies - die Anmeldung
 * aus Safari kommt nicht mit. Deshalb trägt die start_url den Token, und
 * wer damit ankommt, ist angemeldet. Gebraucht von der App (index.php) und
 * vom Lehrkraft-Bereich (teacher/_boot.php), die je ein eigenes Symbol
 * haben. Danach leitet der Aufrufer auf eine Adresse ohne Token weiter,
 * damit er nicht in Verlauf oder Screenshots landet.
 */
function device_token_einloesen(string $token): ?array
{
    $user = device_token_user($token);
    if ($user !== null) {
        login_user((int) $user['id']);
        $_SESSION['device_token'] = $token;
    }
    return $user;
}

/**
 * Der Token für den Manifest-Link: der dieser Installation, sonst ein frisch
 * erzeugter für den nächsten "Zum Home-Bildschirm"-Vorgang.
 *
 * Einer je Sitzung, für beide Symbole: Legt eine Lehrkraft Lernansicht und
 * Verwaltung auf denselben Bildschirm, tragen beide denselben Token - es ist
 * dasselbe Gerät, und im Profil soll es einmal stehen, nicht zweimal.
 */
function install_token(array $user): string
{
    session_boot();
    $known = $_SESSION['device_token'] ?? null;
    if (is_string($known)) {
        $besitzer = device_token_user($known);
        if ($besitzer !== null && (int) $besitzer['id'] === (int) $user['id']) {
            return $known;
        }
    }
    $token = device_token_create(
        (int) $user['id'],
        substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unbekanntes Gerät'), 0, 128),
    );
    $_SESSION['device_token'] = $token;
    return $token;
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

/**
 * Das Konto, wie die App es als VT.user bekommt.
 *
 * An drei Stellen gebraucht - api/auth.php bei "me" und bei der Anmeldung,
 * index.php in der Hülle -, und dreimal stand dieselbe Liste da. Mit den
 * Hinweisen bei der ersten Anmeldung kam ein Feld dazu, und drei Abschriften
 * hätten es früher oder später an einer Stelle vergessen.
 */
function app_user_data(array $user): array
{
    require_once __DIR__ . '/access.php';
    require_once __DIR__ . '/einwilligung.php';

    return [
        'id'        => (int) $user['id'],
        'name'      => $user['display_name'],
        'color'     => $user['color'],
        'appName'   => app_name_for($user),
        // Was das Konto darf - damit die Oberflaeche nichts anbietet, was
        // die API hinterher ablehnt.
        'canImport' => user_can($user, CAP_IMPORT),
        'isTeacher' => user_is_teacher($user),
        // null, oder die Punkte, die vor dem ersten Üben zu bestätigen sind.
        'einwilligung' => einwilligung_fuer_app($user),
    ];
}
