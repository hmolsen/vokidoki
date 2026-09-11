<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Abstände vor Satzzeichen zurechtrücken.
 *
 * Der Anlass war "Salut !" - das sah nach einem Fehler des Modells aus, ist
 * aber korrektes Französisch: Vor den "doppelten" Satzzeichen ! ? : ; und
 * innerhalb der Guillemets steht dort ein Leerzeichen. Im Deutschen,
 * Englischen, Dänischen und Lateinischen steht dort keines.
 *
 * Deshalb wird hier nicht pauschal geputzt, sondern je Sprache richtig
 * gesetzt: Was im Französischen fehlt, kommt hinzu; was in den übrigen
 * Sprachen zu viel ist, fällt weg. Vor Punkt und Komma steht nirgends eines,
 * auch im Französischen nicht.
 */

/** Sprachen, die vor den doppelten Satzzeichen ein Leerzeichen setzen. */
const SPACED_PUNCTUATION_LANGS = ['fr'];

/**
 * Vereinheitlicht die Abstände um Satzzeichen.
 *
 * $lang ist das Sprachkürzel des Textes ('fr', 'de', 'da' ...). Ohne Kürzel
 * gilt die enge Schreibweise - sie trifft auf alle Sprachen zu ausser dem
 * Französischen.
 */
function punctuation_fix(string $text, ?string $lang): string
{
    // Geschützte und schmale Leerzeichen mitnehmen: Das Modell liefert
    // gelegentlich U+00A0 oder U+202F, und die sieht man hinterher nicht.
    $text = preg_replace('/[\s\x{00A0}\x{202F}\x{2009}]+/u', ' ', $text) ?? $text;
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    // Erst alles eng ziehen - danach wird nur dort wieder gelockert, wo es
    // hingehört. Das räumt doppelte Abstände gleich mit weg.
    $text = preg_replace('/\s+([.,;:!?\x{00BB}])/u', '$1', $text) ?? $text;
    $text = preg_replace('/(\x{00AB})\s+/u', '$1', $text) ?? $text;

    if (!in_array((string) $lang, SPACED_PUNCTUATION_LANGS, true)) {
        return trim($text);
    }

    // Französisch: vor die Folge der doppelten Satzzeichen ein Leerzeichen.
    $text = preg_replace_callback(
        '/([^\s!?;:])([!?;:]+)/u',
        static function (array $m): string {
            // Zwischen Ziffern bleibt der Doppelpunkt eng - das ist eine
            // Uhrzeit ("10:30"), kein Satzzeichen.
            if ($m[2] === ':' && preg_match('/\d/u', $m[1]) === 1) {
                return $m[0];
            }
            return $m[1] . ' ' . $m[2];
        },
        $text,
    ) ?? $text;

    // Und innerhalb der Guillemets.
    $text = preg_replace('/(?<=\S)(\x{00BB})/u', ' $1', $text) ?? $text;
    $text = preg_replace('/(\x{00AB})(?=\S)/u', '$1 ', $text) ?? $text;

    return trim(preg_replace('/ {2,}/u', ' ', $text) ?? $text);
}

/**
 * Geht Vokabeln und Lückensätze durch und rückt die Abstände zurecht.
 *
 * Mit $apply = false wird nur gezählt, was sich ändern würde - so zeigt der
 * Admin den Knopf nur, wenn es etwas zu tun gibt.
 *
 * @return array{vocab:int, sentences:int}
 */
function punctuation_repair(bool $apply): array
{
    $n = ['vocab' => 0, 'sentences' => 0];

    // Die Fremdsprache folgt ihrer eigenen Regel, die deutsche Seite immer
    // der deutschen - deshalb kommt das Kürzel je Zeile mit.
    $vokabeln = qa(
        'SELECT v.id, v.term_foreign, v.term_native, l.code
           FROM vocab v
           JOIN units t ON t.id = v.unit_id
           JOIN languages l ON l.id = t.language_id'
    );

    foreach ($vokabeln as $v) {
        $f = punctuation_fix((string) $v['term_foreign'], $v['code']);
        $d = punctuation_fix((string) $v['term_native'], 'de');

        if ($f === (string) $v['term_foreign'] && $d === (string) $v['term_native']) {
            continue;
        }
        $n['vocab']++;
        if ($apply) {
            q('UPDATE vocab SET term_foreign = ?, term_native = ? WHERE id = ?',
              [$f, $d, (int) $v['id']]);
        }
    }

    $saetze = qa(
        'SELECT s.id, s.native_text, s.foreign_text, s.answer, l.code
           FROM sentences s
           JOIN vocab v ON v.id = s.vocab_id
           JOIN units t ON t.id = v.unit_id
           JOIN languages l ON l.id = t.language_id'
    );

    foreach ($saetze as $s) {
        $d = punctuation_fix((string) $s['native_text'], 'de');
        $f = punctuation_fix((string) $s['foreign_text'], $s['code']);
        $a = punctuation_fix((string) $s['answer'], $s['code']);

        if ($d === (string) $s['native_text']
            && $f === (string) $s['foreign_text']
            && $a === (string) $s['answer']) {
            continue;
        }
        $n['sentences']++;
        if ($apply) {
            q('UPDATE sentences SET native_text = ?, foreign_text = ?, answer = ? WHERE id = ?',
              [$d, $f, $a, (int) $s['id']]);
        }
    }

    return $n;
}
