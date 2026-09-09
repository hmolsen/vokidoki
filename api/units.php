<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

require_api_request();
$user = require_user();
$uid  = (int) $user['id'];

switch (action()) {
    case 'list':
        $lang = own_language($uid, (int) ($_GET['language_id'] ?? 0));
        $rows = qa(
            "SELECT u.id, u.title, u.created_at,
                    COUNT(v.id) AS total,
                    SUM(CASE WHEN p.known_at IS NOT NULL THEN 1 ELSE 0 END) AS known,
                    COALESCE(SUM(p.correct_count), 0) AS correct,
                    COALESCE(SUM(p.wrong_count), 0)   AS wrong
               FROM units u
               LEFT JOIN vocab v ON v.unit_id = u.id
               LEFT JOIN progress p ON p.vocab_id = v.id AND p.mode = 'mc'
              WHERE u.language_id = ? AND u.user_id = ?
              GROUP BY u.id, u.title, u.created_at
              ORDER BY u.created_at DESC",
            [(int) $lang['id'], $uid],
        );
        foreach ($rows as &$r) {
            $r['id']      = (int) $r['id'];
            $r['total']   = (int) $r['total'];
            $r['known']   = (int) $r['known'];
            $r['correct'] = (int) $r['correct'];
            $r['wrong']   = (int) $r['wrong'];
            $r['done']    = $r['total'] > 0 && $r['known'] >= $r['total'];
        }
        json_out(['ok' => true, 'language' => [
            'id'   => (int) $lang['id'],
            'name' => $lang['name'],
            'flag' => $lang['flag_emoji'],
        ], 'units' => $rows]);

    case 'get':
        $unit  = own_unit($uid, (int) ($_GET['id'] ?? 0));
        $vocab = qa(
            "SELECT v.id, v.term_foreign, v.term_native, v.note,
                    COALESCE(p.correct_count, 0) AS correct,
                    COALESCE(p.wrong_count, 0)   AS wrong,
                    COALESCE(p.streak, 0)        AS streak,
                    p.known_at
               FROM vocab v
               LEFT JOIN progress p ON p.vocab_id = v.id AND p.mode = 'mc'
              WHERE v.unit_id = ?
              ORDER BY v.position, v.id",
            [(int) $unit['id']],
        );
        foreach ($vocab as &$v) {
            $v['id']      = (int) $v['id'];
            $v['correct'] = (int) $v['correct'];
            $v['wrong']   = (int) $v['wrong'];
            $v['streak']  = (int) $v['streak'];
            $v['known']   = $v['known_at'] !== null;
            unset($v['known_at']);
        }
        json_out(['ok' => true, 'unit' => [
            'id'          => (int) $unit['id'],
            'title'       => $unit['title'],
            'language_id' => (int) $unit['language_id'],
        ], 'vocab' => $vocab]);

    case 'rename':
        require_post();
        $b     = json_body();
        $unit  = own_unit($uid, body_int($b, 'id'));
        $title = body_str($b, 'title', 128);
        if ($title === '') {
            json_fail('Bitte einen Titel angeben.');
        }
        q('UPDATE units SET title = ? WHERE id = ? AND user_id = ?', [$title, (int) $unit['id'], $uid]);
        json_out(['ok' => true]);

    case 'reset':
        require_post();
        $b    = json_body();
        $unit = own_unit($uid, body_int($b, 'id'));
        q(
            'DELETE p FROM progress p
               JOIN vocab v ON v.id = p.vocab_id
              WHERE v.unit_id = ? AND p.user_id = ?',
            [(int) $unit['id'], $uid],
        );
        json_out(['ok' => true]);

    case 'delete':
        require_post();
        $b    = json_body();
        $unit = own_unit($uid, body_int($b, 'id'));
        q('DELETE FROM units WHERE id = ? AND user_id = ?', [(int) $unit['id'], $uid]);
        json_out(['ok' => true]);

    default:
        json_fail('Unbekannte Aktion.', 404);
}
