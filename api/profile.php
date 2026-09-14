<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/colors.php';

/*
 * Das eigene Konto - Name, Farbe, Passwort.
 *
 * Bisher konnte das nur der Betreiber im Admin. Für eine Familie ging das:
 * Papa sass daneben. In einer Schule nicht - ein Kind, das sein
 * Anfangspasswort "müder Gepard" behalten muss, weil niemand es ändern
 * kann, ist ein Kind mit einem Passwort, das auf einem Zettel steht.
 */

require_api_request();
$user = require_user();
$uid  = (int) $user['id'];

switch (action()) {
    case 'get':
        json_out([
            'ok'      => true,
            'profile' => [
                'name'     => $user['display_name'],
                'username' => $user['username'],
                'color'    => $user['color'],
                // Steht das Anfangspasswort noch? Dann hat das Kind seines
                // noch nicht geändert, und genau das soll die Seite sagen.
                'initial'  => ($user['initial_password'] ?? null) !== null
                              && $user['initial_password'] !== '',
            ],
            'palette' => color_palette(),
        ]);
        // no break - json_out beendet den Request

    case 'save':
        require_post();
        $b = json_body();

        $name  = body_str($b, 'name', 64);
        $color = body_str($b, 'color', 16);

        if ($name === '') {
            json_fail('Bitte einen Namen angeben.');
        }

        q(
            'UPDATE users SET display_name = ?, color = ? WHERE id = ?',
            [$name, valid_color($color), $uid],
        );

        $frisch = q1('SELECT * FROM users WHERE id = ?', [$uid]);
        json_out([
            'ok'   => true,
            'user' => [
                'id'        => $uid,
                'name'      => $frisch['display_name'],
                'color'     => $frisch['color'],
                'appName'   => app_name_for($frisch),
                'canImport' => user_can($frisch, CAP_IMPORT),
                'isTeacher' => user_is_teacher($frisch),
            ],
        ]);

    case 'password':
        require_post();
        $b = json_body();

        $alt = (string) ($b['current'] ?? '');
        $neu = (string) ($b['password'] ?? '');

        /*
         * Das alte Passwort wird verlangt, obwohl die Sitzung angemeldet
         * ist. Der Grund ist ein durchgereichtes Handy: Die App bleibt
         * angemeldet, damit ein Kind nicht täglich tippen muss - dann darf
         * aber nicht jeder, der sie in die Hand bekommt, das Passwort
         * ändern und das Kind aussperren.
         */
        if (!password_verify($alt, (string) $user['password_hash'])) {
            usleep(random_int(200_000, 500_000));
            json_fail('Das bisherige Passwort stimmt nicht.', 403);
        }

        if (mb_strlen($neu) < 6) {
            json_fail('Das neue Passwort braucht mindestens 6 Zeichen.');
        }
        if ($neu === $alt) {
            json_fail('Das ist das bisherige Passwort.');
        }

        /*
         * Und hier verschwindet das Anfangspasswort aus der Datenbank.
         *
         * Es steht dort im Klartext, damit das Anschreiben nachdruckbar
         * bleibt - eine bewusste Abwägung. Sobald ein Kind sein eigenes
         * gewählt hat, ist der gespeicherte Wert wertlos, und der Bestand
         * offener Passwörter schrumpft mit der Zeit, statt zu wachsen.
         */
        q(
            'UPDATE users SET password_hash = ?, initial_password = NULL WHERE id = ?',
            [password_hash($neu, PASSWORD_DEFAULT), $uid],
        );

        json_out(['ok' => true]);

    default:
        json_fail('Unbekannte Aktion.', 404);
}
