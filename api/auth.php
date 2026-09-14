<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

require_api_request();

switch (action()) {
    case 'me':
        $u = current_user();
        json_out([
            'ok'   => true,
            'user' => $u === null ? null : [
                'id'        => (int) $u['id'],
                'name'      => $u['display_name'],
                'color'     => $u['color'],
                'appName'   => app_name_for($u),
                'canImport' => user_can($u, CAP_IMPORT),
                'isTeacher' => user_is_teacher($u),
            ],
        ]);
        // no break - json_out beendet den Request

    case 'login':
        require_post();
        $b        = json_body();
        $username = body_str($b, 'username', 64);
        $password = (string) ($b['password'] ?? '');

        // Erst bremsen, dann prüfen. Die Bremse zählt auch unbekannte
        // Benutzernamen mit - sonst liesse sich an ihr ablesen, welche
        // Konten es gibt.
        $ip    = login_client_ip();
        $sperr = login_guard($username, $ip);
        if ($sperr !== null) {
            json_fail($sperr, 429);
        }

        $user = q1('SELECT * FROM users WHERE username = ? AND active = 1', [$username]);

        // password_verify auch bei unbekanntem Nutzer aufrufen, damit die
        // Antwortzeit keine Rückschlüsse auf existierende Konten zulässt.
        $hash = $user['password_hash'] ?? '$2y$12$' . str_repeat('.', 53);
        if ($user === null || !password_verify($password, $hash)) {
            login_attempt_record($username, $ip);
            usleep(random_int(150_000, 400_000));
            json_fail('Benutzername oder Passwort stimmt nicht.', 401);
        }

        // Wer sein Passwort kennt, soll nicht an den Fehlversuchen von
        // vorhin hängenbleiben.
        login_attempts_reset($username);

        login_user((int) $user['id']);

        // Frischer Geräte-Token für genau diese Installation. index.php
        // rendert daraus den Manifest-Link für "Zum Home-Bildschirm".
        $token = device_token_create(
            (int) $user['id'],
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unbekanntes Gerät'), 0, 128),
        );
        $_SESSION['device_token'] = $token;

        json_out([
            'ok'       => true,
            'redirect' => url('/?t=' . urlencode($token)),
            'user'     => [
                'id'        => (int) $user['id'],
                'name'      => $user['display_name'],
                'color'     => $user['color'],
                'appName'   => app_name_for($user),
                'canImport' => user_can($user, CAP_IMPORT),
                'isTeacher' => user_is_teacher($user),
            ],
        ]);

    case 'logout':
        require_post();
        logout_user();
        json_out(['ok' => true]);

    default:
        json_fail('Unbekannte Aktion.', 404);
}
