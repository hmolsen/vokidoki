<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/colors.php';
// Die Regeln selbst stehen in lib/profile.php - der
// Lehrkraft-Bereich aendert dasselbe ueber ein Formular.
require_once __DIR__ . '/../lib/profile.php';
require_once __DIR__ . '/../lib/geraete.php';

/*
 * Das eigene Konto - Name, Farbe, Passwort.
 *
 * Ändern kann es das Kind selbst, nicht nur der Betreiber im Admin. Ein
 * Kind, das sein Anfangspasswort "müder Gepard" behalten muss, weil niemand
 * es ändern kann, ist ein Kind mit einem Passwort, das auf einem Zettel
 * steht - und Zettel gehen in einer Klasse herum.
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
                'initial'  => profile_has_initial_password($user),
            ],
            'geraete' => geraete_liste($uid),
            'geraeteText' => GERAETE_ERKLAERUNG,
            'palette' => color_palette(),
        ]);
        // no break - json_out beendet den Request

    case 'save':
        require_post();
        $b = json_body();

        $fehler = profile_save($uid, body_str($b, 'name', 64), body_str($b, 'color', 16));
        if ($fehler !== null) {
            json_fail($fehler);
        }

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

        $fehler = profile_change_password(
            $user,
            (string) ($b['current'] ?? ''),
            (string) ($b['password'] ?? ''),
        );
        if ($fehler !== null) {
            // 403 nur für das falsche bisherige Passwort - die anderen
            // beiden sind Eingabefehler und keine Abweisung.
            json_fail($fehler,
                      str_contains($fehler, 'bisherige Passwort stimmt') ? 403 : 400);
        }

        json_out(['ok' => true]);

    /*
     * Die App läuft als Symbol vom Home-Bildschirm (installieren.js). Erst
     * damit steht das Gerät unter "Deine Geräte" - siehe lib/geraete.php.
     */
    case 'installiert':
        require_post();
        geraet_installiert($uid, (bool) (json_body()['beruehrbar'] ?? false));
        json_out(['ok' => true]);

    case 'geraet_weg':
        require_post();
        if (!geraet_widerrufen($uid, (int) (json_body()['id'] ?? 0))) {
            json_fail('Dieses Gerät gibt es nicht.', 404);
        }
        json_out(['ok' => true, 'geraete' => geraete_liste($uid)]);

    default:
        json_fail('Unbekannte Aktion.', 404);
}
