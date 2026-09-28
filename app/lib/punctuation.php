<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Abstände vor Satzzeichen zurechtrücken.
 *
 * Vor Satzzeichen steht kein Leerzeichen - in keiner Sprache.
 *
 * Das war einmal anders: Im Französischen gehört typografisch vor ! ? : ;
 * und in die Guillemets ein Abstand ("Salut !"), und eine Zeit lang wurde
 * er hier eigens gesetzt. Im Schulgebrauch sah das aber nach einem Fehler
 * aus - die Lehrkraft las "Comment ça va ?" als verrutschtes Fragezeichen,
 * und ein Kind tippt den Abstand auf dem Handy ohnehin nicht. Also gilt
 * jetzt überall die enge Schreibweise, und eine Regel statt zweier.
 *
 * $lang bleibt als Parameter stehen, weil jede Stelle, die Text anlegt,
 * die Sprache mitgibt - gibt es wieder eine Sprache mit eigener Regel,
 * gehört sie hierher und nirgends sonst.
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

    $text = preg_replace('/\s+([.,;:!?\x{00BB}])/u', '$1', $text) ?? $text;
    $text = preg_replace('/(\x{00AB})\s+/u', '$1', $text) ?? $text;

    return trim($text);
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
