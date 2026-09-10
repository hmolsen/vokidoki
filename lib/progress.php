<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Lernlogik, gemeinsam für alle Übungsarten.
 *
 * Die Regel steht bewusst nur an einer Stelle: Dreimal hintereinander richtig
 * macht eine Vokabel zu "gekonnt", ein Fehler setzt die Serie zurück. Jede
 * Übungsart führt ihre eigene Serie - progress.mode trennt sie, der eindeutige
 * Schlüssel (vocab_id, mode) sorgt dafür von selbst.
 */

/** Dreimal hintereinander richtig => gilt als gekonnt. */
const KNOWN_THRESHOLD = 3;

const MODE_CHOICE = 'mc';      // Multiple Choice
const MODE_CLOZE  = 'cloze';   // Lückentext

/** Fortschritt einer Lerneinheit als [gekonnt, gesamt]. */
function unit_progress(int $unitId, string $mode): array
{
    $row = q1(
        'SELECT COUNT(v.id) AS total,
                SUM(CASE WHEN p.known_at IS NOT NULL THEN 1 ELSE 0 END) AS known
           FROM vocab v
           LEFT JOIN progress p ON p.vocab_id = v.id AND p.mode = ?
          WHERE v.unit_id = ?',
        [$mode, $unitId],
    );
    return [(int) ($row['known'] ?? 0), (int) ($row['total'] ?? 0)];
}

/**
 * Verbucht eine Antwort und liefert den neuen Stand der Vokabel.
 *
 * @return array{streak:int, just_learned:bool}
 */
function record_answer(int $userId, int $vocabId, string $mode, bool $correct): array
{
    $cur = q1(
        'SELECT streak, correct_count, wrong_count FROM progress WHERE vocab_id = ? AND mode = ?',
        [$vocabId, $mode],
    );
    $streak   = (int) ($cur['streak'] ?? 0);
    $correctN = (int) ($cur['correct_count'] ?? 0);
    $wrongN   = (int) ($cur['wrong_count'] ?? 0);

    if ($correct) {
        $streak++;
        $correctN++;
    } else {
        $streak = 0;   // "dreimal hintereinander" - ein Fehler setzt zurück
        $wrongN++;
    }
    $nowKnown = $streak >= KNOWN_THRESHOLD;

    $knownInsert = $nowKnown ? 'NOW()' : 'NULL';
    $knownUpdate = $nowKnown ? 'COALESCE(known_at, NOW())' : 'NULL';

    q(
        'INSERT INTO progress
            (user_id, vocab_id, mode, streak, correct_count, wrong_count, known_at, last_seen_at)
         VALUES (?, ?, ?, ?, ?, ?, ' . $knownInsert . ', NOW())
         ON DUPLICATE KEY UPDATE
            streak        = VALUES(streak),
            correct_count = VALUES(correct_count),
            wrong_count   = VALUES(wrong_count),
            known_at      = ' . $knownUpdate . ',
            last_seen_at  = NOW()',
        [$userId, $vocabId, $mode, $streak, $correctN, $wrongN],
    );

    return ['streak' => $streak, 'just_learned' => $nowKnown];
}

/** Setzt den Lernstand einer Lerneinheit zurück - eine Übungsart oder alle. */
function reset_unit_progress(int $unitId, int $userId, ?string $mode = null): void
{
    if ($mode === null) {
        q(
            'DELETE p FROM progress p
               JOIN vocab v ON v.id = p.vocab_id
              WHERE v.unit_id = ? AND p.user_id = ?',
            [$unitId, $userId],
        );
        return;
    }

    q(
        'DELETE p FROM progress p
           JOIN vocab v ON v.id = p.vocab_id
          WHERE v.unit_id = ? AND p.user_id = ? AND p.mode = ?',
        [$unitId, $userId, $mode],
    );
}
