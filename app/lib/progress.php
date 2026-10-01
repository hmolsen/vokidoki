<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/streak.php';

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
const MODE_PICK   = 'pick';    // Einsetzen: Lückensatz, das Wort aus drei wählen
const MODE_CLOZE  = 'cloze';   // Lückentext

/*
 * Alle Übungsarten, die einen Lernstand führen - an einer Stelle.
 *
 * Die Liste stand dreimal da, in api/bundle.php zweimal und in
 * api/units.php, jedesmal als [MODE_CHOICE, MODE_CLOZE]. Eine dritte
 * Übungsart hätte an jeder davon nachgetragen werden müssen; wo es
 * vergessen worden wäre, hätte der Server ihre Antworten still verworfen.
 */
const MODES = [MODE_CHOICE, MODE_PICK, MODE_CLOZE];

/**
 * Fortschritt einer Lerneinheit für ein Kind als [gekonnt, gesamt].
 *
 * "Gesamt" heisst: was für dieses Kind freigegeben ist. Sonst stünde bei
 * einer Klasse, die drei von zwanzig Vokabeln aufhat, dauerhaft "0 von 20" -
 * und ein Balken, der sich nicht bewegen kann, entmutigt.
 */
function unit_progress(int $unitId, int $userId, string $mode): array
{
    $row = q1(
        'SELECT COUNT(v.id) AS total,
                SUM(CASE WHEN p.known_at IS NOT NULL THEN 1 ELSE 0 END) AS known
           FROM vocab v
           LEFT JOIN progress p
                  ON p.vocab_id = v.id AND p.mode = ? AND p.user_id = ?
          WHERE v.unit_id = ? AND v.position < ?',
        [$mode, $userId, $unitId, visible_position_for($unitId, $userId)],
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
 * Und hier wird auch der Tag verbucht, an dem geübt wurde - die Serie hängt
 * daran. Ausdrücklich an dieser Stelle und nicht bei den drei Aufrufern:
 * Üben geht über das Bündel, über das Quiz und über den Lückentext, und der
 * vierte Weg, der das eines Tages vergisst, kommt bestimmt. $tag ist der
 * Tag des GERÄTS, weil offline geübt und später geschickt wird; fehlt er,
 * gilt heute.
 *
 * @return array{streak:int, just_learned:bool, newly_learned:bool}
 */
function record_answer(
    int $userId,
    int $vocabId,
    string $mode,
    bool $correct,
    ?string $tag = null,
): array {
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

    /*
     * Und jetzt zum ersten Mal gekonnt - das ist nicht dasselbe wie
     * $nowKnown.
     *
     * $nowKnown steht ab der dritten richtigen Antwort bei JEDER weiteren
     * wieder da: Die Serie bleibt ja über der Schwelle. Für die Rückmeldung
     * „Sitzt!" war das nie ein Problem, weil das Quiz nur Vokabeln vorlegt,
     * die noch nicht gekonnt sind. Für die Serie wäre es eines gewesen -
     * der Lückentext legt dieselbe Vokabel später wieder vor, und dann
     * hätte ein einziges Wort jeden Tag aufs Neue „neu gelernt" gemeldet.
     * Gemeint ist der Übergang, und der steht in known_at.
     */
    $neuGekonnt = $nowKnown && ($cur === null || $cur['known_at'] === null);

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

    streak_verbuchen($userId, streak_tag_pruefen($tag), $correct, $neuGekonnt);

    return [
        'streak'        => $streak,
        'just_learned'  => $nowKnown,
        'newly_learned' => $neuGekonnt,
    ];
}

/**
 * Setzt den Lernstand einer Lerneinheit zurück - eine Übungsart oder alle.
 *
 * Ausdrücklich ohne Rücksicht auf die Freigabe, anders als alles darüber.
 * Hier wird gelöscht, nicht gezeigt: Zurücknehmen einer Freigabe darf keine
 * Lernstände zurücklassen, die beim nächsten Freigeben wieder auftauchen und
 * einem Kind eine Serie gutschreiben, die es nicht mehr hat.
 */
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
