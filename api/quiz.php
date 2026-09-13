<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/progress.php';

require_api_request();
$user = require_user();
$uid  = (int) $user['id'];

const QUIZ_MODE    = MODE_CHOICE;
const OPTION_COUNT = 4;

switch (action()) {
    case 'next':
        $unit = own_unit($uid, (int) ($_GET['unit_id'] ?? 0));
        [$known, $total] = unit_progress((int) $unit['id'], $uid, QUIZ_MODE);
        $langName = (string) qv(
            'SELECT name FROM languages WHERE id = ?',
            [(int) $unit['language_id']],
        );

        if ($total === 0) {
            json_fail('Diese Lerneinheit enthält noch keine Vokabeln.', 422);
        }

        // Noch offene Vokabel ziehen. ORDER BY RAND() ist hier unkritisch -
        // eine Lerneinheit hat höchstens ein paar hundert Zeilen.
        $card = q1(
            "SELECT v.id, v.term_foreign, v.term_native, COALESCE(p.streak, 0) AS streak
               FROM vocab v
               LEFT JOIN progress p
                      ON p.vocab_id = v.id AND p.mode = ? AND p.user_id = ?
              WHERE v.unit_id = ? AND p.known_at IS NULL
              ORDER BY RAND()
              LIMIT 1",
            [QUIZ_MODE, $uid, (int) $unit['id']],
        );

        if ($card === null) {
            json_out([
                'ok'    => true,
                'done'  => true,
                'known' => $known,
                'total' => $total,
            ]);
        }

        // Richtung zufällig: mal Fremdsprache -> Deutsch, mal umgekehrt.
        $foreignToNative = random_int(0, 1) === 1;
        $question = $foreignToNative ? $card['term_foreign'] : $card['term_native'];
        $answer   = $foreignToNative ? $card['term_native']  : $card['term_foreign'];
        $col      = $foreignToNative ? 'term_native' : 'term_foreign';

        // Ablenker zuerst aus derselben Einheit; reicht sie nicht aus, aus der
        // gesamten Sprache nachlegen, damit immer vier Optionen zusammenkommen.
        $need    = OPTION_COUNT - 1;
        $options = [$answer];

        $pools = [
            ["SELECT DISTINCT {$col} AS t FROM vocab
               WHERE unit_id = ? AND {$col} <> ? ORDER BY RAND() LIMIT {$need}",
             [(int) $unit['id'], $answer]],
            ["SELECT DISTINCT v.{$col} AS t FROM vocab v
                JOIN units u ON u.id = v.unit_id
               WHERE u.language_id = ? AND u.user_id = ? AND v.{$col} <> ?
               ORDER BY RAND() LIMIT {$need}",
             [(int) $unit['language_id'], $uid, $answer]],
        ];

        foreach ($pools as [$sql, $params]) {
            if (count($options) >= OPTION_COUNT) {
                break;
            }
            foreach (qa($sql, $params) as $row) {
                if (count($options) >= OPTION_COUNT) {
                    break;
                }
                if (!in_array($row['t'], $options, true)) {
                    $options[] = $row['t'];
                }
            }
        }

        shuffle($options);
        $correctIndex = (int) array_search($answer, $options, true);

        // Die richtige Antwort verlässt den Server nicht - sonst wäre sie im
        // Netzwerk-Tab ablesbar und die Auswertung fälschbar.
        session_boot();
        $nonce = bin2hex(random_bytes(16));
        if (!isset($_SESSION['quiz']) || !is_array($_SESSION['quiz'])) {
            $_SESSION['quiz'] = [];
        }
        $_SESSION['quiz'][$nonce] = [
            'vocab_id' => (int) $card['id'],
            'unit_id'  => (int) $unit['id'],
            'correct'  => $correctIndex,
        ];
        if (count($_SESSION['quiz']) > 20) {
            $_SESSION['quiz'] = array_slice($_SESSION['quiz'], -20, null, true);
        }

        json_out([
            'ok'        => true,
            'done'      => false,
            'language'  => $langName,
            'nonce'     => $nonce,
            'question'  => $question,
            'direction' => $foreignToNative ? 'foreign_to_native' : 'native_to_foreign',
            'options'   => $options,
            'streak'    => (int) $card['streak'],
            'known'     => $known,
            'total'     => $total,
        ]);

    case 'answer':
        require_post();
        $b     = json_body();
        $nonce = body_str($b, 'nonce', 64);
        $index = body_int($b, 'index');

        session_boot();
        $pending = $_SESSION['quiz'][$nonce] ?? null;
        if (!is_array($pending)) {
            json_fail('Diese Frage ist nicht mehr gültig. Bitte neu laden.', 409);
        }
        // Einmalig verwendbar - verhindert mehrfaches Einreichen derselben Frage.
        unset($_SESSION['quiz'][$nonce]);

        $unit     = own_unit($uid, (int) $pending['unit_id']);
        $vocabId  = (int) $pending['vocab_id'];
        $isRight  = $index === (int) $pending['correct'];

        $stand = record_answer($uid, $vocabId, QUIZ_MODE, $isRight);

        [$known, $total] = unit_progress((int) $unit['id'], $uid, QUIZ_MODE);

        json_out([
            'ok'            => true,
            'correct'       => $isRight,
            'correct_index' => (int) $pending['correct'],
            'streak'        => $stand['streak'],
            'just_learned'  => $stand['just_learned'],
            'known'         => $known,
            'total'         => $total,
            'done'          => $total > 0 && $known >= $total,
        ]);

    case 'stats':
        $unit = own_unit($uid, (int) ($_GET['unit_id'] ?? 0));
        $row  = q1(
            "SELECT COUNT(v.id) AS total,
                    SUM(CASE WHEN p.known_at IS NOT NULL THEN 1 ELSE 0 END) AS known,
                    COALESCE(SUM(p.correct_count), 0) AS correct,
                    COALESCE(SUM(p.wrong_count), 0)   AS wrong
               FROM vocab v
               LEFT JOIN progress p
                      ON p.vocab_id = v.id AND p.mode = ? AND p.user_id = ?
              WHERE v.unit_id = ?",
            [QUIZ_MODE, $uid, (int) $unit['id']],
        );
        json_out(['ok' => true, 'stats' => [
            'total'   => (int) $row['total'],
            'known'   => (int) $row['known'],
            'correct' => (int) $row['correct'],
            'wrong'   => (int) $row['wrong'],
        ]]);

    default:
        json_fail('Unbekannte Aktion.', 404);
}
