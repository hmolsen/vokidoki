<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/punctuation.php';
// Fuer RELEASED_ALL: Die Marke "alles" ist keine Anzahl und darf beim
// Loeschen nicht wie eine behandelt werden.
require_once __DIR__ . '/access.php';

/**
 * Vokabeln anlegen, ändern, löschen - und `position` heil halten.
 *
 * `vocab.position` ist zweierlei zugleich, und daran hängt mehr, als es
 * aussieht:
 *
 *   1. die Reihenfolge der Vokabeln in der Lerneinheit,
 *   2. der **Freigabezeiger**. Elf Abfragen vergleichen
 *      `v.position < u.released_position` - die Vokabelliste des Kindes, der
 *      Ablenkerpool im Quiz, der Lückentext, die Zählungen auf jeder
 *      Übersicht.
 *
 * Das trägt nur, solange die Positionen einer Einheit lückenlos `0..n-1`
 * sind. Genau das war bisher **nicht** garantiert: `admin/vocab.php` löschte
 * eine Vokabel und liess ein Loch. Danach liefert `MAX(position)+1` einen
 * Wert grösser als `COUNT(*)`, und „Alles freigeben" - das
 * `released_position = COUNT(*)` setzt - erreicht die letzte Vokabel nicht
 * mehr. Sie bleibt für immer unsichtbar, ohne Fehlermeldung.
 *
 * Deshalb geht ab hier **jede** Änderung an Vokabeln durch diese Datei, und
 * jede stellt die Lückenlosigkeit hinterher wieder her. Der eindeutige
 * Schlüssel `uq_vocab_pos` macht aus einem Fehler hier einen lauten statt
 * eines stillen.
 *
 * Seiteneffektfrei ladbar wie lib/access.php: keine Sitzung, keine Ausgabe,
 * keine Rechteprüfung. Wer darf, entscheidet die aufrufende Schicht.
 */

/**
 * Sind die Positionen dieser Einheit lückenlos 0..n-1?
 *
 * Drei Zahlen genügen: Anzahl, kleinste, grösste. Sind es n Zeilen, fängt es
 * bei 0 an und endet bei n-1, dann kann nichts fehlen und nichts doppelt
 * sein - Positionen sind ganzzahlig und nicht negativ.
 */
function vocab_positions_dense(int $unitId): bool
{
    $r = q1(
        'SELECT COUNT(*) AS n, MIN(position) AS kleinste, MAX(position) AS groesste
           FROM vocab WHERE unit_id = ?',
        [$unitId],
    );

    $n = (int) ($r['n'] ?? 0);
    if ($n === 0) {
        return true;
    }

    return (int) $r['kleinste'] === 0 && (int) $r['groesste'] === $n - 1;
}

/**
 * Positionen neu durchnumerieren: 0..n-1, in der bisherigen Reihenfolge.
 *
 * Zweistufig, und das ist kein Umstand, sondern Notwendigkeit: Mit dem
 * eindeutigen Schlüssel auf (unit_id, position) würde ein Durchgang
 * mittendrin auf eine Position stossen, die es noch gibt. Deshalb erst alle
 * weit nach oben schieben, dann auf die endgültigen Werte.
 *
 * @return int wie viele Zeilen sich bewegt haben
 */
function vocab_compact_positions(int $unitId): int
{
    $zeilen = qa(
        'SELECT id, position FROM vocab WHERE unit_id = ? ORDER BY position, id',
        [$unitId],
    );
    if ($zeilen === []) {
        return 0;
    }

    $bewegt = 0;
    foreach ($zeilen as $i => $z) {
        if ((int) $z['position'] !== $i) {
            $bewegt++;
        }
    }
    if ($bewegt === 0) {
        return 0;
    }

    // Erster Durchgang: aus dem Weg. Der Abstand ist so gewählt, dass er
    // auch bei einer sehr grossen Einheit nicht mit den Zielwerten kollidiert.
    q('UPDATE vocab SET position = position + 1000000 WHERE unit_id = ?', [$unitId]);

    $st = db()->prepare('UPDATE vocab SET position = ? WHERE id = ?');
    foreach ($zeilen as $i => $z) {
        $st->execute([$i, (int) $z['id']]);
    }

    return $bewegt;
}

/**
 * Vokabeln hinten anfügen. Liefert, wie viele wirklich dazugekommen sind.
 *
 * `$paare` ist eine Liste von `['foreign' => …, 'native' => …, 'note' => …,
 * 'word_type' => …]`; die letzten beiden dürfen fehlen.
 *
 * Wichtig, und der Grund für die Transaktion: Die nächste freie Position
 * wird gelesen und gleich darauf geschrieben. Ohne Sperre könnten zwei
 * gleichzeitige Anfügungen dieselbe Position bekommen - und dann teilen sich
 * zwei Vokabeln einen Freigabeschritt.
 *
 * `released_position` wird **nicht** angefasst. Neue Wörter sind damit für
 * die Klasse zunächst unsichtbar, genau wie eine frisch eingelesene Einheit
 * einer Lehrkraft. Bei einer Einheit, die ein Kind für sich selbst
 * eingelesen hat, steht die Marke auf RELEASED_ALL - dort sind sie sofort
 * da, und das ist richtig: Es gibt niemanden, der freigäbe.
 */
function vocab_append(int $unitId, array $paare, ?string $sprachcode = null): int
{
    if ($paare === []) {
        return 0;
    }

    $eigene = !db()->inTransaction();
    if ($eigene) {
        db()->beginTransaction();
    }

    try {
        // Die Zeile der Einheit sperren - sie ist der Ankerpunkt, an dem
        // sich zwei gleichzeitige Anfügungen aufreihen.
        q1('SELECT id FROM units WHERE id = ? FOR UPDATE', [$unitId]);

        vocab_compact_positions($unitId);
        $naechste = (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ?', [$unitId]);

        /*
         * Was schon drinsteht, kommt nicht noch einmal hinein.
         *
         * Zweimal dieselbe Buchseite fotografiert, ein Doppelklick auf
         * "Hinzufuegen", eine Antwort, die unterwegs verlorenging und
         * wiederholt wurde - die Wege zu einem Duplikat sind viele, und
         * keiner davon ist eine Absicht.
         *
         * Verglichen wird das PAAR, nicht das fremde Wort allein: "bank"
         * heisst Bank und Ufer, und beide gehoeren in dieselbe Einheit.
         * Und verglichen wird, was wirklich gespeichert wuerde - also nach
         * punctuation_fix(), sonst schluepft "apple." an "apple" vorbei.
         */
        $schon = [];
        foreach (qa('SELECT term_foreign, term_native FROM vocab WHERE unit_id = ?',
                    [$unitId]) as $v) {
            $schon[vocab_paarschluessel((string) $v['term_foreign'],
                                        (string) $v['term_native'])] = true;
        }

        $st = db()->prepare(
            'INSERT INTO vocab (unit_id, term_foreign, term_native, note, word_type,
                                check_note, position)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
        );

        $dazu = 0;
        foreach ($paare as $p) {
            // Abstände vor Satzzeichen gleich hier richtigstellen - dasselbe
            // wie beim Einlesen. Bisher tat das nur der Importweg, und von
            // Hand ergänzte Vokabeln tauchten hinterher in der Liste
            // "Abstände" im Admin auf.
            $f = punctuation_fix((string) ($p['foreign'] ?? ''), $sprachcode);
            $n = punctuation_fix((string) ($p['native'] ?? ''), 'de');
            if ($f === '' || $n === '') {
                continue;
            }

            $schluessel = vocab_paarschluessel($f, $n);
            if (isset($schon[$schluessel])) {
                continue;
            }
            $schon[$schluessel] = true;

            $notiz = trim((string) ($p['note'] ?? ''));
            // Was die KI beim Einlesen berichtigt hat - die Zeile bleibt
            // markiert, bis die Lehrkraft sie geprüft hat.
            $pruefen = trim((string) ($p['correction'] ?? ''));
            $st->execute([
                $unitId,
                mb_substr($f, 0, 255),
                mb_substr($n, 0, 255),
                $notiz === '' ? null : mb_substr($notiz, 0, 255),
                ($p['word_type'] ?? null) !== null ? mb_substr((string) $p['word_type'], 0, 16) : null,
                $pruefen === '' ? null : mb_substr($pruefen, 0, 255),
                $naechste + $dazu,
            ]);
            $dazu++;
        }

        if ($eigene) {
            db()->commit();
        }
        return $dazu;
    } catch (Throwable $e) {
        if ($eigene && db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
}

/**
 * Der Schluessel, an dem zwei Vokabeln als dieselbe gelten.
 *
 * Gross- und Kleinschreibung zaehlt nicht, Leerraum am Rand auch nicht -
 * wer "Apple" statt "apple" tippt, meint dasselbe Wort. Mehr nicht: Ein
 * Vergleich, der Umlaute faltet oder Satzzeichen wegwirft, wuerde
 * Vokabeln zusammenziehen, die sich genau darin unterscheiden.
 */
function vocab_paarschluessel(string $fremd, string $deutsch): string
{
    return mb_strtolower(trim($fremd)) . "\0" . mb_strtolower(trim($deutsch));
}

/** Eine Vokabel ändern. Positionen bleiben, wie sie sind. */
function vocab_update(int $vocabId, string $foreign, string $native,
                      ?string $sprachcode = null): bool
{
    $f = punctuation_fix($foreign, $sprachcode);
    $n = punctuation_fix($native, 'de');
    if ($f === '' || $n === '') {
        return false;
    }

    // Wer die Vokabel ändert, hat sie angesehen - die Markierung "von der
    // KI berichtigt" hat damit ihren Zweck erfüllt.
    q(
        'UPDATE vocab SET term_foreign = ?, term_native = ?, check_note = NULL WHERE id = ?',
        [mb_substr($f, 0, 255), mb_substr($n, 0, 255), $vocabId],
    );
    return true;
}

/** Die Markierung "von der KI berichtigt" wegnehmen: Die Lehrkraft sagt "passt". */
function vocab_check_done(int $vocabId): void
{
    q('UPDATE vocab SET check_note = NULL WHERE id = ?', [$vocabId]);
}

/**
 * Eine Vokabel löschen - und die Freigabe dabei richtig halten.
 *
 * Der Kern: Lag die gelöschte Vokabel **unterhalb** der Freigabemarke, muss
 * die Marke um eins sinken. Sonst rückt stillschweigend das nächste, bisher
 * gesperrte Wort nach und ist plötzlich für die Klasse da - niemand hat es
 * freigegeben, und niemand sieht, dass es passiert ist.
 *
 * Danach wird verdichtet, damit `position` wieder lückenlos ist.
 *
 * Der Lernstand der Kinder zu dieser Vokabel verschwindet mit ihr
 * (ON DELETE CASCADE auf progress). Das ist nicht zu vermeiden - die Vokabel
 * ist weg -, aber es gehört in die Rückfrage, die davor steht.
 */
function vocab_delete(int $vocabId): bool
{
    $zeile = q1(
        'SELECT v.id, v.unit_id, v.position, u.released_position
           FROM vocab v JOIN units u ON u.id = v.unit_id
          WHERE v.id = ?',
        [$vocabId],
    );
    if ($zeile === null) {
        return false;
    }

    $unitId   = (int) $zeile['unit_id'];
    $position = (int) $zeile['position'];
    $frei     = (int) $zeile['released_position'];

    $eigene = !db()->inTransaction();
    if ($eigene) {
        db()->beginTransaction();
    }

    try {
        q1('SELECT id FROM units WHERE id = ? FOR UPDATE', [$unitId]);
        q('DELETE FROM vocab WHERE id = ?', [$vocabId]);

        /*
         * Nur wenn die Marke wirklich darüber stand. Und nicht bei
         * RELEASED_ALL: Dort heisst die Marke "alles", nicht "so viele" -
         * sie um eins zu senken machte aus "alles" plötzlich eine Zahl.
         */
        if ($position < $frei && $frei !== RELEASED_ALL) {
            q('UPDATE units SET released_position = ? WHERE id = ?',
              [max(0, $frei - 1), $unitId]);
        }

        vocab_compact_positions($unitId);

        if ($eigene) {
            db()->commit();
        }
        return true;
    } catch (Throwable $e) {
        if ($eigene && db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
}

/**
 * Alle Einheiten, deren Positionen Löcher haben.
 *
 * Für den Selbsttest im Admin und für die Überführung. Eine Abfrage statt
 * einer Schleife über alle Einheiten: Bei tausend Lerneinheiten wäre das
 * sonst tausend Abfragen.
 */
function vocab_units_with_gaps(): array
{
    return qa(
        'SELECT u.id, u.title, COUNT(v.id) AS n,
                MIN(v.position) AS kleinste, MAX(v.position) AS groesste
           FROM units u JOIN vocab v ON v.unit_id = u.id
          GROUP BY u.id, u.title
         HAVING MIN(v.position) <> 0 OR MAX(v.position) <> COUNT(v.id) - 1
          ORDER BY u.id',
    );
}
