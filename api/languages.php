<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

/**
 * Sprachkürzel aus dem Namen ableiten, wenn die App keines mitschickt.
 * Es dient nur als Tastaturhinweis - findet sich nichts, bleibt es leer und
 * die Tastatur verhält sich wie bisher.
 */
const LANGUAGE_CODES = [
    'englisch' => 'en', 'französisch' => 'fr', 'franzoesisch' => 'fr',
    'latein' => 'la', 'dänisch' => 'da', 'daenisch' => 'da',
    'spanisch' => 'es', 'italienisch' => 'it', 'niederländisch' => 'nl',
    'niederlaendisch' => 'nl', 'schwedisch' => 'sv', 'norwegisch' => 'no',
    'türkisch' => 'tr', 'tuerkisch' => 'tr', 'russisch' => 'ru',
    'polnisch' => 'pl', 'portugiesisch' => 'pt', 'griechisch' => 'el',
];

function language_code(string $sent, string $name): ?string
{
    $code = strtolower(trim($sent));
    if (preg_match('/^[a-z]{2,3}$/', $code) === 1) {
        return $code;
    }
    return LANGUAGE_CODES[mb_strtolower(trim($name))] ?? null;
}

require_api_request();
$user = require_user();
$uid  = (int) $user['id'];

switch (action()) {
    case 'list':
        $rows = qa(
            'SELECT l.id, l.name, l.flag_emoji, l.code,
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
        $code = language_code(body_str($b, 'code', 8), $name);
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
            'INSERT INTO languages (user_id, name, flag_emoji, code) VALUES (?, ?, ?, ?)',
            [$uid, $name, $flag, $code],
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
