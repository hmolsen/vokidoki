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
        $lang = view_language($user, (int) ($_GET['language_id'] ?? 0));
        /*
         * Beide Übungsarten, nicht nur Multiple Choice.
         *
         * Hier stand dieselbe Verkürzung wie früher in 'get': Es zählte nur
         * mode='mc', und eine Lerneinheit sah fertig aus, während im
         * Lückentext noch alles offen war.
         *
         * Gezählt wird in Schritten, nicht in Vokabeln: Jede Vokabel bringt
         * einen Schritt fürs Auswählen mit, und einen zweiten fürs Einsetzen,
         * sofern sie einen Lückensatz hat. Das gibt Teilerfolge wieder,
         * statt eine halb gelernte Vokabel als gar nicht gelernt zu führen.
         */
        /*
         * Die Freigabemarke als Textbaustein statt als Parameter.
         *
         * Hier wird je Lerneinheit gezaehlt, und die Marke steht in der Zeile
         * der Einheit selbst - ein gebundener Wert koennte das nicht
         * ausdruecken, es braucht den Spaltenvergleich auf die aeussere
         * Zeile.
         *
         * Ohne Ausnahme fuer Lehrkraefte: In der App sollen sie genau das
         * sehen, was ihre Klasse sieht. Alles zu sehen ist Sache des
         * Lehrkraft-Bereichs.
         */
        $frei = ' AND v.position < u.released_position';

        /*
         * Und der Kurs statt u.user_id.
         *
         * Bis hierher stand hier noch "die Einheit gehoert einem Konto".
         * Solange jede Sprache genau einem Kind gehoerte, fiel das nicht
         * auf - in einer Klasse waere die Liste leer, obwohl die Einheit da
         * ist.
         */
        $rows = qa(
            "SELECT u.id, u.title, u.created_at,
                    (SELECT COUNT(*) FROM vocab v
                      WHERE v.unit_id = u.id{$frei}) AS total,
                    (SELECT COUNT(*) FROM vocab v
                       JOIN progress p ON p.vocab_id = v.id
                                      AND p.mode = 'mc' AND p.user_id = ?
                      WHERE v.unit_id = u.id AND p.known_at IS NOT NULL{$frei}) AS known,
                    (SELECT COUNT(*) FROM vocab v
                      WHERE v.unit_id = u.id{$frei}
                        AND EXISTS (SELECT 1 FROM sentences s WHERE s.vocab_id = v.id)
                    ) AS cloze_total,
                    (SELECT COUNT(*) FROM vocab v
                       JOIN progress p ON p.vocab_id = v.id
                                      AND p.mode = 'cloze' AND p.user_id = ?
                      WHERE v.unit_id = u.id AND p.known_at IS NOT NULL{$frei}) AS cloze_known,
                    (SELECT COALESCE(SUM(p.correct_count), 0) FROM vocab v
                       JOIN progress p ON p.vocab_id = v.id AND p.user_id = ?
                      WHERE v.unit_id = u.id{$frei}) AS correct,
                    (SELECT COALESCE(SUM(p.wrong_count), 0) FROM vocab v
                       JOIN progress p ON p.vocab_id = v.id AND p.user_id = ?
                      WHERE v.unit_id = u.id{$frei}) AS wrong
               FROM units u
               JOIN course_members m ON m.course_id = u.course_id AND m.user_id = ?
              WHERE u.language_id = ?
              ORDER BY u.position, u.id",
            [$uid, $uid, $uid, $uid, $uid, (int) $lang['id']],
        );
        foreach ($rows as &$r) {
            $r['id']          = (int) $r['id'];
            $r['total']       = (int) $r['total'];
            $r['known']       = (int) $r['known'];
            $r['cloze_total'] = (int) $r['cloze_total'];
            $r['cloze_known'] = (int) $r['cloze_known'];
            $r['correct']     = (int) $r['correct'];
            $r['wrong']       = (int) $r['wrong'];

            $r['steps_total'] = $r['total'] + $r['cloze_total'];
            $r['steps_done']  = $r['known'] + $r['cloze_known'];
            $r['percent']     = $r['steps_total'] > 0
                ? (int) round($r['steps_done'] / $r['steps_total'] * 100)
                : 0;
            $r['done'] = $r['steps_total'] > 0 && $r['steps_done'] >= $r['steps_total'];
        }
        unset($r);
        /*
         * Der Name des Kurses reist mit.
         *
         * Gebraucht beim Einlesen: Wer ueber einen Link oder einen QR-Code
         * direkt dort landet, sieht sonst nur "Vokabeln einlesen" und weiss
         * nicht, in welchen Kurs das faellt: "Englisch - 5B".
         */
        $kursZeile = q1(
            'SELECT co.id, co.name FROM courses co
               JOIN course_members m ON m.course_id = co.id
              WHERE co.language_id = ? AND m.user_id = ?
              LIMIT 1',
            [(int) $lang['id'], $uid],
        );
        $kurs = $kursZeile === null ? null : (string) $kursZeile['name'];

        /*
         * Wie auf der Kachel: Der Kursname steht nur da, wenn er etwas
         * unterscheidet. Wer Englisch zweimal hat, braucht "Englisch - 5B";
         * wer es einmal hat, liest lieber "Englisch" als seinen eigenen
         * Namen. Die Regel muss dieselbe sein wie in api/languages.php -
         * sonst heisst die Kachel anders als die Seite dahinter.
         */
        $gleichnamig = (int) qv(
            'SELECT COUNT(*) FROM courses co
               JOIN languages l2      ON l2.id = co.language_id
               JOIN course_members m  ON m.course_id = co.id
              WHERE m.user_id = ? AND l2.name = ?',
            [$uid, (string) $lang['name']],
        );
        $anzeige = $gleichnamig > 1 && $kurs !== null ? (string) $kurs : (string) $lang['name'];

        /*
         * Die Kennung des Kurses - nur fuer eine Lehrkraft.
         *
         * Sie braucht sie fuer den Weg zurueck: Der Hinweis "So sieht deine
         * Klasse das" traegt einen Knopf in die Verwaltung, und der soll auf
         * genau diesen Kurs zeigen und nicht auf die Startseite. Einem Kind
         * sagt die Zahl nichts - der Lehrkraft-Bereich laesst es ohnehin
         * nicht hinein -, also steht sie dort auch nicht.
         */
        json_out(['ok' => true, 'language' => [
            'id'       => (int) $lang['id'],
            'name'     => $lang['name'],
            'flag'     => $lang['flag_emoji'],
            'course'   => $kurs,
            'courseId' => $kursZeile !== null && user_is_teacher($user)
                ? (int) $kursZeile['id'] : null,
            // Was als Ueberschrift dastehen soll - Sprache oder Kurs.
            'label'    => $anzeige,
        ], 'units' => $rows]);

    case 'get':
        $unit  = view_unit($user, (int) ($_GET['id'] ?? 0));

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
               LEFT JOIN progress pm
                      ON pm.vocab_id = v.id AND pm.mode = ? AND pm.user_id = ?
               LEFT JOIN progress pc
                      ON pc.vocab_id = v.id AND pc.mode = ? AND pc.user_id = ?
              WHERE v.unit_id = ? AND v.position < ?
              ORDER BY v.position, v.id",
            [MODE_CHOICE, $uid, MODE_CLOZE, $uid, (int) $unit['id'],
             visible_position($user, $unit)],
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
        [$mcKnown, $mcTotal] = unit_progress((int) $unit['id'], $uid, MODE_CHOICE);
        $cloze = sentence_status((int) $unit['id'], $uid);

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
        $unit = view_unit($user, (int) ($_GET['id'] ?? 0));
        json_out(['ok' => true, 'cloze' => sentence_status((int) $unit['id'], $uid)]);

    case 'rename':
        require_post();
        $b     = json_body();
        $unit  = edit_unit($user, body_int($b, 'id'));
        $title = body_str($b, 'title', 128);
        if ($title === '') {
            json_fail('Bitte einen Titel angeben.');
        }
        // Freigegeben hat edit_unit(); die Zeile ist damit die richtige.
        q('UPDATE units SET title = ? WHERE id = ?', [$title, (int) $unit['id']]);
        json_out(['ok' => true]);

    case 'reset':
        require_post();
        $b    = json_body();
        $unit = view_unit($user, body_int($b, 'id'));

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
        $unit = edit_unit($user, body_int($b, 'id'));
        q('DELETE FROM units WHERE id = ?', [(int) $unit['id']]);
        json_out(['ok' => true]);

    default:
        json_fail('Unbekannte Aktion.', 404);
}
