<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/progress.php';
require_once __DIR__ . '/../lib/sentences.php';

require_api_request();
$user = require_user();
$uid  = (int) $user['id'];

switch (action()) {
    case 'prepare':
        require_post();
        $b    = json_body();
        $unit = own_unit($uid, body_int($b, 'unit_id'));

        $offen = vocab_without_sentences((int) $unit['id']);
        if ($offen === 0) {
            json_out(['ok' => true, 'created' => 0, 'ready' => true]);
        }

        // Wie bei der Bilderkennung: Budget zuerst, dann erst die API.
        $blocked = budget_block_reason($uid);
        if ($blocked !== null) {
            json_fail($blocked, 429);
        }

        // Für eine ganze Lerneinheit reicht die Standardlaufzeit nicht.
        set_time_limit(300);

        try {
            $result = generate_sentences($unit, $user);
        } catch (KeyvaultException $e) {
            error_log('[vokabeltrainer] Keyvault: ' . scrub_secrets($e->getMessage()));
            json_fail(
                'Der Schlüsseldienst ist gerade nicht erreichbar. '
                . 'Bitte später noch einmal versuchen.',
                503,
            );
        } catch (Throwable $e) {
            error_log('[vokabeltrainer] Sätze: ' . scrub_secrets($e->getMessage()));
            json_fail(
                'Die Sätze konnten nicht erzeugt werden. Bitte noch einmal versuchen.',
                502,
            );
        }

        [$known, $total] = cloze_progress((int) $unit['id']);
        if ($total === 0) {
            json_fail(
                'Für diese Lerneinheit ließen sich keine Lückensätze erzeugen. '
                . 'Papa kann im Admin-Bereich nachsehen.',
                422,
            );
        }

        json_out([
            'ok'      => true,
            'ready'   => true,
            'created' => $result['created'],
        ]);

    case 'next':
        $unit = own_unit($uid, (int) ($_GET['unit_id'] ?? 0));
        [$known, $total] = cloze_progress((int) $unit['id']);

        if ($total === 0) {
            // Noch keine Sätze - der Client ruft daraufhin 'prepare' auf.
            json_out(['ok' => true, 'needs_preparation' => true]);
        }

        $lang = q1(
            'SELECT name, code FROM languages WHERE id = ?',
            [(int) $unit['language_id']],
        );

        // Eine noch offene Vokabel mit Satz ziehen, davon einen zufälligen Satz.
        $card = q1(
            'SELECT v.id, COALESCE(p.streak, 0) AS streak
               FROM vocab v
               LEFT JOIN progress p ON p.vocab_id = v.id AND p.mode = ?
              WHERE v.unit_id = ? AND p.known_at IS NULL
                AND EXISTS (SELECT 1 FROM sentences s WHERE s.vocab_id = v.id)
              ORDER BY RAND()
              LIMIT 1',
            [MODE_CLOZE, (int) $unit['id']],
        );

        if ($card === null) {
            json_out([
                'ok'    => true,
                'done'  => true,
                'known' => $known,
                'total' => $total,
            ]);
        }

        $sentence = q1(
            'SELECT id, native_text, foreign_text, answer
               FROM sentences WHERE vocab_id = ? ORDER BY RAND() LIMIT 1',
            [(int) $card['id']],
        );

        // Wie beim Multiple Choice: Die Lösung verlässt den Server nicht.
        session_boot();
        $nonce = bin2hex(random_bytes(16));
        if (!isset($_SESSION['cloze']) || !is_array($_SESSION['cloze'])) {
            $_SESSION['cloze'] = [];
        }
        $_SESSION['cloze'][$nonce] = [
            'vocab_id' => (int) $card['id'],
            'unit_id'  => (int) $unit['id'],
            'answer'   => (string) $sentence['answer'],
        ];
        if (count($_SESSION['cloze']) > 20) {
            $_SESSION['cloze'] = array_slice($_SESSION['cloze'], -20, null, true);
        }

        json_out([
            'ok'       => true,
            'done'     => false,
            'nonce'    => $nonce,
            'native'   => $sentence['native_text'],
            'foreign'  => $sentence['foreign_text'],
            'language' => $lang['name'],
            'lang'     => $lang['code'],
            'streak'   => (int) $card['streak'],
            'known'    => $known,
            'total'    => $total,
        ]);

    case 'answer':
        require_post();
        $b     = json_body();
        $nonce = body_str($b, 'nonce', 64);
        $typed = body_str($b, 'text', 128);

        session_boot();
        $pending = $_SESSION['cloze'][$nonce] ?? null;
        if (!is_array($pending)) {
            json_fail('Diese Aufgabe ist nicht mehr gültig. Bitte neu laden.', 409);
        }
        unset($_SESSION['cloze'][$nonce]);

        $unit    = own_unit($uid, (int) $pending['unit_id']);
        $vocabId = (int) $pending['vocab_id'];
        $expected = (string) $pending['answer'];

        $check = answer_check($typed, $expected);
        $stand = record_answer($uid, $vocabId, MODE_CLOZE, $check['correct']);

        [$known, $total] = cloze_progress((int) $unit['id']);

        json_out([
            'ok'           => true,
            'correct'      => $check['correct'],
            // exact=false bei richtiger Antwort heißt: Schreibweise zeigen.
            'exact'        => $check['exact'],
            'answer'       => $expected,
            'streak'       => $stand['streak'],
            'just_learned' => $stand['just_learned'],
            'known'        => $known,
            'total'        => $total,
            'done'         => $total > 0 && $known >= $total,
        ]);

    default:
        json_fail('Unbekannte Aktion.', 404);
}
