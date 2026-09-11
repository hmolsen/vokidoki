<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/progress.php';
require_once __DIR__ . '/../lib/sentences.php';

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

        // Lernstand beider Übungsarten nebeneinander. Bisher stand hier nur
        // Multiple Choice - dadurch sah eine Vokabel "geschafft" aus, obwohl
        // sie im Lückentext noch offen war.
        $vocab = qa(
            "SELECT v.id, v.term_foreign, v.term_native, v.note, v.word_type,
                    COALESCE(pm.streak, 0)        AS mc_streak,
                    COALESCE(pm.correct_count, 0) AS mc_correct,
                    COALESCE(pm.wrong_count, 0)   AS mc_wrong,
                    pm.known_at                   AS mc_known,
                    COALESCE(pc.streak, 0)        AS cl_streak,
                    COALESCE(pc.correct_count, 0) AS cl_correct,
                    COALESCE(pc.wrong_count, 0)   AS cl_wrong,
                    pc.known_at                   AS cl_known,
                    EXISTS (SELECT 1 FROM sentences s WHERE s.vocab_id = v.id) AS has_sentences
               FROM vocab v
               LEFT JOIN progress pm ON pm.vocab_id = v.id AND pm.mode = ?
               LEFT JOIN progress pc ON pc.vocab_id = v.id AND pc.mode = ?
              WHERE v.unit_id = ?
              ORDER BY v.position, v.id",
            [MODE_CHOICE, MODE_CLOZE, (int) $unit['id']],
        );

        $liste = [];
        foreach ($vocab as $v) {
            $liste[] = [
                'id'           => (int) $v['id'],
                'term_foreign' => $v['term_foreign'],
                'term_native'  => $v['term_native'],
                'note'         => $v['note'],
                'word_type'    => $v['word_type'],
                'modes'        => [
                    'mc' => [
                        'streak'   => (int) $v['mc_streak'],
                        'correct'  => (int) $v['mc_correct'],
                        'wrong'    => (int) $v['mc_wrong'],
                        'known'    => $v['mc_known'] !== null,
                        'possible' => true,
                    ],
                    'cloze' => [
                        'streak'   => (int) $v['cl_streak'],
                        'correct'  => (int) $v['cl_correct'],
                        'wrong'    => (int) $v['cl_wrong'],
                        'known'    => $v['cl_known'] !== null,
                        // Ohne Satz lässt sich diese Vokabel hier nicht üben.
                        'possible' => (int) $v['has_sentences'] === 1,
                    ],
                ],
            ];
        }
        $vocab = $liste;

        // Fortschritt je Übungsart. Der Lückentext zählt nur Vokabeln, zu denen
        // es einen Satz gibt - er wird beim ersten Start erst erzeugt.
        [$mcKnown, $mcTotal] = unit_progress((int) $unit['id'], MODE_CHOICE);
        $cloze = sentence_status((int) $unit['id']);

        json_out(['ok' => true, 'unit' => [
            'id'          => (int) $unit['id'],
            'title'       => $unit['title'],
            'language_id' => (int) $unit['language_id'],
        ], 'vocab' => $vocab, 'modes' => [
            'mc'    => ['known' => $mcKnown, 'total' => $mcTotal],
            'cloze' => $cloze,
        ]]);

    case 'sentence_status':
        // Schlank gehalten: Die Oberfläche fragt das im Sekundentakt ab,
        // solange die Sätze im Hintergrund entstehen.
        $unit = own_unit($uid, (int) ($_GET['id'] ?? 0));
        json_out(['ok' => true, 'cloze' => sentence_status((int) $unit['id'])]);

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

        // Ohne Angabe wird alles zurückgesetzt; mit 'mode' nur eine Übungsart.
        $mode = isset($b['mode']) ? body_str($b, 'mode', 16) : '';
        if ($mode !== '' && !in_array($mode, [MODE_CHOICE, MODE_CLOZE], true)) {
            json_fail('Unbekannte Übungsart.');
        }

        reset_unit_progress((int) $unit['id'], $uid, $mode === '' ? null : $mode);
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
