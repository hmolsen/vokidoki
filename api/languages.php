<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/courses.php';
require_once __DIR__ . '/../lib/languages.php';

require_api_request();
$user = require_user();
$uid  = (int) $user['id'];

switch (action()) {
    case 'list':
        $rows = qa(
            /*
             * Die Sprachen, in deren Kurs dieses Konto ist - nicht die, die
             * es angelegt hat. Fuer eine Familie ist das dasselbe, in einer
             * Klasse hat die Lehrkraft angelegt und die Kinder lernen.
             *
             * Gezaehlt wird nur Freigegebenes. Sonst stuende hier "20
             * Vokabeln", waehrend die Lerneinheit drei zeigt.
             */
            'SELECT l.id, l.name, l.flag_emoji, l.code,
                    (SELECT COUNT(*) FROM units u
                      WHERE u.course_id = co.id) AS unit_count,
                    (SELECT COUNT(*) FROM vocab v
                       JOIN units u2 ON u2.id = v.unit_id
                      WHERE u2.course_id = co.id
                        AND (? = 1 OR v.position < u2.released_position)) AS vocab_count
               FROM languages l
               JOIN courses co        ON co.language_id = l.id
               JOIN course_members m  ON m.course_id = co.id
              WHERE m.user_id = ?
              ORDER BY l.name',
            [user_is_teacher($user) ? 1 : 0, $uid],
        );
        foreach ($rows as &$r) {
            $r['id']          = (int) $r['id'];
            $r['unit_count']  = (int) $r['unit_count'];
            $r['vocab_count'] = (int) $r['vocab_count'];
        }
        json_out(['ok' => true, 'languages' => $rows]);

    case 'create':
        require_post();
        $b    = json_body();
        $name = body_str($b, 'name', 64);
        $flag = body_str($b, 'flag', 16);
        $code = language_code(body_str($b, 'code', 8), $name);
        if ($name === '') {
            json_fail('Bitte einen Namen für die Sprache angeben.');
        }

        /*
         * Gibt es diese Sprache fuer dieses Konto schon?
         *
         * Gefragt wird ueber die Kurse, nicht ueber languages.user_id. Zwei
         * Klassen derselben Schule duerfen beide "Englisch" haben - verboten
         * ist nur, dass dasselbe Kind zweimal dieselbe Sprache anlegt und
         * danach nicht mehr weiss, in welcher seiner beiden es war.
         */
        $exists = q1(
            'SELECT l.id
               FROM languages l
               JOIN courses co       ON co.language_id = l.id
               JOIN course_members m ON m.course_id = co.id
              WHERE m.user_id = ? AND l.name = ?
              LIMIT 1',
            [$uid, $name],
        );
        if ($exists !== null) {
            json_fail('Diese Sprache gibt es schon.', 409);
        }

        q(
            'INSERT INTO languages (school_id, name, flag_emoji, code)
             VALUES (?, ?, ?, ?)',
            [$user['school_id'] ?? null, $name, $flag, $code],
        );
        $languageId = (int) db()->lastInsertId();

        /*
         * Zu jeder Sprache gehoert ein Kurs - daran haengen die Lerneinheiten
         * und die Zugriffsregeln. Entstuende er nicht, waere die Sprache
         * angelegt, aber fuer ihren eigenen Urheber unsichtbar.
         */
        $kurs = course_create_for_language(
            ['id' => $languageId, 'name' => $name],
            $user,
        );
        if ($kurs === null) {
            q('DELETE FROM languages WHERE id = ?', [$languageId]);
            json_fail('Dieses Konto gehoert zu keiner Schule.', 409);
        }

        json_out(['ok' => true, 'id' => $languageId]);

    case 'delete':
        require_post();
        $b   = json_body();
        $lang = edit_language($user, body_int($b, 'id'));
        // $lang kommt aus edit_language() und ist damit bereits freigegeben.
        // Eine zweite Besitzpruefung hier waere eine zweite Wahrheit - und
        // genau die, die beim Umstieg auf Kurse falsch wuerde.
        q('DELETE FROM languages WHERE id = ?', [(int) $lang['id']]);
        json_out(['ok' => true]);

    default:
        json_fail('Unbekannte Aktion.', 404);
}
