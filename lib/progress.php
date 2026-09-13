<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Lernlogik, gemeinsam für alle Übungsarten.
 *
 * Die Regel steht bewusst nur an einer Stelle: Dreimal hintereinander richtig
 * macht eine Vokabel zu "gekonnt", ein Fehler setzt die Serie zurück. Jede
 * Übungsart führt ihre eigene Serie - progress.mode trennt sie.
 *
 * Der Lernstand gehört dem Kind, nicht der Vokabel. Das klingt
 * selbstverständlich, war es hier aber lange nicht: Der eindeutige Schlüssel
 * lautete (vocab_id, mode) ohne user_id, und gelesen wurde ohne
 * Benutzerfilter. Solange jede Vokabel genau einem Kind gehörte, trug das.
 * Sobald eine Klasse denselben Vokabelsatz übt, teilen sich alle Kinder eine
 * Serie. Deshalb steht user_id jetzt in jeder Abfrage.
 */

/** Dreimal hintereinander richtig => gilt als gekonnt. */
const KNOWN_THRESHOLD = 3;

const MODE_CHOICE = 'mc';      // Multiple Choice
const MODE_CLOZE  = 'cloze';   // Lückentext

/** Fortschritt einer Lerneinheit für ein Kind als [gekonnt, gesamt]. */
function unit_progress(int $unitId, int $userId, string $mode): array
{
    $row = q1(
        'SELECT COUNT(v.id) AS total,
                SUM(CASE WHEN p.known_at IS NOT NULL THEN 1 ELSE 0 END) AS known
           FROM vocab v
           LEFT JOIN progress p
                  ON p.vocab_id = v.id AND p.mode = ? AND p.user_id = ?
          WHERE v.unit_id = ?',
        [$mode, $userId, $unitId],
    );
    return [(int) ($row['known'] ?? 0), (int) ($row['total'] ?? 0)];
}

/**
 * Verbucht eine Antwort und liefert den neuen Stand der Vokabel.
 *
 * Bewusst ohne ON DUPLICATE KEY: Die Funktion soll unter der alten wie unter
 * der neuen Indexlage richtig arbeiten. Der Code geht per FTP sofort live, die
 * Schemaänderung läuft aber erst beim nächsten Aufruf des Admin-Bereichs -
 * dazwischen liegt ein Fenster, in dem ein Upsert gegen den alten Schlüssel
 * den Lernstand eines anderen Kindes überschrieben hätte. Ein SELECT auf die
 * eigene Zeile und danach UPDATE über den Primärschlüssel hängt an gar keinem
 * eindeutigen Index und ist in beiden Zuständen richtig.
 *
 * @return array{streak:int, just_learned:bool}
 */
function record_answer(int $userId, int $vocabId, string $mode, bool $correct): array
{
    $cur = q1(
        'SELECT id, streak, correct_count, wrong_count, known_at
           FROM progress
          WHERE user_id = ? AND vocab_id = ? AND mode = ?',
        [$userId, $vocabId, $mode],
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

    if ($cur === null) {
        q(
            'INSERT INTO progress
                (user_id, vocab_id, mode, streak, correct_count, wrong_count,
                 known_at, last_seen_at)
             VALUES (?, ?, ?, ?, ?, ?, ' . ($nowKnown ? 'NOW()' : 'NULL') . ', NOW())',
            [$userId, $vocabId, $mode, $streak, $correctN, $wrongN],
        );
    } else {
        // Einmal gekonnt bleibt gekonnt, solange die Serie hält.
        $known = $nowKnown ? 'COALESCE(known_at, NOW())' : 'NULL';

        q(
            'UPDATE progress
                SET streak        = ?,
                    correct_count = ?,
                    wrong_count   = ?,
                    known_at      = ' . $known . ',
                    last_seen_at  = NOW()
              WHERE id = ?',
            [$streak, $correctN, $wrongN, (int) $cur['id']],
        );
    }

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
