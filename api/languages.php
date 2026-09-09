<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

require_api_request();
$user = require_user();
$uid  = (int) $user['id'];

switch (action()) {
    case 'list':
        $rows = qa(
            'SELECT l.id, l.name, l.flag_emoji,
                    (SELECT COUNT(*) FROM units u WHERE u.language_id = l.id) AS unit_count,
                    (SELECT COUNT(*) FROM vocab v
                       JOIN units u2 ON u2.id = v.unit_id
                      WHERE u2.language_id = l.id) AS vocab_count
               FROM languages l
              WHERE l.user_id = ?
              ORDER BY l.name',
            [$uid],
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
        if ($name === '') {
            json_fail('Bitte einen Namen für die Sprache angeben.');
        }

        $exists = q1(
            'SELECT id FROM languages WHERE user_id = ? AND name = ?',
            [$uid, $name],
        );
        if ($exists !== null) {
            json_fail('Diese Sprache gibt es schon.', 409);
        }

        q(
            'INSERT INTO languages (user_id, name, flag_emoji) VALUES (?, ?, ?)',
            [$uid, $name, $flag],
        );
        json_out(['ok' => true, 'id' => (int) db()->lastInsertId()]);

    case 'delete':
        require_post();
        $b   = json_body();
        $lang = own_language($uid, body_int($b, 'id'));
        q('DELETE FROM languages WHERE id = ? AND user_id = ?', [(int) $lang['id'], $uid]);
        json_out(['ok' => true]);

    default:
        json_fail('Unbekannte Aktion.', 404);
}
