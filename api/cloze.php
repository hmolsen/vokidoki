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
        $unit = edit_unit($user, body_int($b, 'unit_id'));

        $offen = vocab_without_sentences((int) $unit['id']);
        if ($offen === 0) {
            json_out(['ok' => true, 'created' => 0, 'ready' => true]);
        }

        // Wie bei der Bilderkennung: Budget zuerst, dann erst die API.
        $blocked = budget_block_reason($uid);
        if ($blocked !== null) {
            json_fail($blocked, 429);
        }

        /*
         * Nur eine Anfrage darf erzeugen. Sitzt eine Klasse gleichzeitig
         * davor, sehen alle "noch keine Sätze" - ohne diesen Riegel würden
         * daraus 28 bezahlte Läufe für ein Ergebnis.
         */
        if (!sentence_claim((int) $unit['id'])) {
            json_out(['ok' => true, 'preparing' => true, 'created' => 0]);
        }

        // Für eine ganze Lerneinheit reicht die Standardlaufzeit nicht.
        set_time_limit(300);

        try {
            $result = generate_sentences($unit, $user);
        } catch (KeyvaultException $e) {
            db_ensure();
            sentence_status_set((int) $unit['id'], SENTENCE_FAILED,
                'Der Schlüsseldienst war nicht erreichbar.');
            error_log('[vokabeltrainer] Keyvault: ' . scrub_secrets($e->getMessage()));
            json_fail(
                'Der Schlüsseldienst ist gerade nicht erreichbar. '
                . 'Bitte später noch einmal versuchen.',
                503,
            );
        } catch (Throwable $e) {
            db_ensure();
            sentence_status_set((int) $unit['id'], SENTENCE_FAILED,
                'Die Sätze konnten nicht erzeugt werden.');
            error_log('[vokabeltrainer] Sätze: ' . scrub_secrets($e->getMessage()));
            json_fail(
                'Die Sätze konnten nicht erzeugt werden. Bitte noch einmal versuchen.',
                502,
            );
        }

        [$known, $total] = cloze_progress((int) $unit['id'], $uid);

        // Hat ein Block gehalten, kann das Kind loslegen - der Rest lässt sich
        // später nachtragen. Nur wenn gar nichts entstand, ist Schluss.
        if ($total === 0) {
            sentence_status_set((int) $unit['id'], SENTENCE_FAILED,
                'Es entstand kein brauchbarer Satz.');
            error_log('[vokabeltrainer] Sätze: nichts brauchbar erzeugt'
                . ($result['failed'] !== null ? ' - ' . scrub_secrets($result['failed']) : ''));
            json_fail(
                'Für diese Lerneinheit ließen sich keine Lückensätze erzeugen. '
                . 'Papa kann im Admin-Bereich nachsehen.',
                422,
            );
        }

        sentence_status_set((int) $unit['id'], SENTENCE_DONE,
            $result['failed'] !== null ? 'Teilweise: ' . $result['failed'] : null);

        if ($result['failed'] !== null) {
            error_log('[vokabeltrainer] Sätze nur teilweise erzeugt: '
                . scrub_secrets($result['failed']));
        }

        json_out([
            'ok'      => true,
            'ready'   => true,
            'created' => $result['created'],
        ]);

    case 'next':
        $unit   = view_unit($user, (int) ($_GET['unit_id'] ?? 0));
        $stand  = sentence_status((int) $unit['id'], $uid);
        $known  = $stand['known'];
        $total  = $stand['total'];

        // Der Hintergrundauftrag vom Einlesen ist noch unterwegs.
        if ($total === 0 && $stand['status'] === SENTENCE_RUNNING) {
            json_out(['ok' => true, 'preparing' => true]);
        }

        if ($total === 0) {
            // Nichts da und nichts unterwegs - der Client stösst 'prepare' an.
            // Das betrifft Lerneinheiten von vor dem Hintergrundlauf.
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
               LEFT JOIN progress p
                      ON p.vocab_id = v.id AND p.mode = ? AND p.user_id = ?
              WHERE v.unit_id = ? AND v.position < ? AND p.known_at IS NULL
                AND EXISTS (SELECT 1 FROM sentences s WHERE s.vocab_id = v.id)
              ORDER BY RAND()
              LIMIT 1',
            [MODE_CLOZE, $uid, (int) $unit['id'], visible_position($user, $unit)],
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
            'vocab_id'    => (int) $card['id'],
            'unit_id'     => (int) $unit['id'],
            'sentence_id' => (int) $sentence['id'],
            'answer'      => (string) $sentence['answer'],
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

        $unit    = view_unit($user, (int) $pending['unit_id']);
        $vocabId = (int) $pending['vocab_id'];
        $expected = (string) $pending['answer'];

        $check = answer_check($typed, $expected);
        $stand = record_answer($uid, $vocabId, MODE_CLOZE, $check['correct']);

        [$known, $total] = cloze_progress((int) $unit['id'], $uid);

        json_out([
            'ok'           => true,
            // Für das Melden eines schiefen Satzes. Wer sie missbraucht,
            // kommt trotzdem nur an eigene Sätze - 'flag' prüft das.
            'sentence_id'  => (int) $pending['sentence_id'],
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

    case 'flag':
        require_post();
        $b     = json_body();
        $satzId = body_int($b, 'sentence_id');
        $typed  = body_str($b, 'text', 128);

        // Der Satz muss zu einer Lerneinheit gehören, die dieses Kind sehen darf.
        view_sentence($user, $satzId);

        // Zweimal melden ändert nichts - der eindeutige Schlüssel fängt das
        // ab, und das Kind bekommt trotzdem seine Bestätigung.
        q(
            'INSERT INTO sentence_flags (sentence_id, user_id, typed)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE typed = VALUES(typed), created_at = NOW()',
            [$satzId, $uid, $typed === '' ? null : $typed],
        );

        json_out(['ok' => true]);

    default:
        json_fail('Unbekannte Aktion.', 404);
}
