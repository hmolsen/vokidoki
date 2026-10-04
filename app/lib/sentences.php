<?php
declare(strict_types=1);

require_once __DIR__ . '/punctuation.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/wordtypes.php';
require_once __DIR__ . '/progress.php';
require_once __DIR__ . '/courses.php';
require_once __DIR__ . '/tts.php';

// use gilt je Datei - die Anweisungen aus lib/ai.php reichen hier nicht.
use Anthropic\Messages\JSONOutputFormat;
use Anthropic\Messages\OutputConfig;

/**
 * Lückensätze: erzeugen, prüfen, Antworten vergleichen.
 *
 * Ein Satz besteht aus dem deutschen Satz, dem fremdsprachigen Satz mit genau
 * einem Platzhalter {} und der dort erwarteten Antwort. Die Antwort ist nicht
 * zwingend die Vokabel selbst: Aus "s'appeler" wird im Satz "Je m'appelle" -
 * deshalb muss das Modell sie mitliefern, wir können sie nicht ableiten.
 */

const SENTENCE_PLACEHOLDER = '{}';

/** So viele Wörter aus früheren Lerneinheiten gehen als bekannt in den Prompt. */
const KNOWN_VOCAB_LIMIT = 300;

/**
 * So viele Vokabeln je KI-Aufruf.
 *
 * 20 Vokabeln zu je drei Sätzen sind rund 7.000 Ausgabe-Token und knapp eine
 * halbe Minute - kurz genug, dass die Datenbankverbindung nicht wegläuft, und
 * weit unter dem Ausgabelimit.
 */
const SENTENCE_BATCH = 20;

// ---------------------------------------------------------------- Antwortvergleich

/**
 * Buchstaben mit Zeichen, wie sie in den Schulsprachen vorkommen.
 *
 * Ausdrücklich als Karte, nicht über iconv('ASCII//TRANSLIT'): dessen Ergebnis
 * hängt von der Locale des Servers ab und liefert je nach System "'e" oder "?"
 * statt "e".
 */
const DIACRITICS = [
    'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae',
    'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
    'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
    'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'œ' => 'oe',
    'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
    'ç' => 'c', 'ñ' => 'n', 'ß' => 'ss', 'ý' => 'y', 'ÿ' => 'y',
];

/** Vereinheitlicht Leerzeichen, Apostrophe und Groß-/Kleinschreibung. */
function answer_normalize(string $text): string
{
    // Typografische Apostrophe und Anführungszeichen auf das schlichte ' bringen -
    // welches davon eine Handytastatur liefert, ist nicht vorhersagbar.
    $text = str_replace(["\u{2019}", "\u{02BC}", "\u{2018}", '`', "\u{00B4}"], "'", $text);
    $text = preg_replace('/[\s\x{00A0}\x{202F}\x{2009}]+/u', ' ', $text) ?? $text;

    // Der Abstand vor einem Satzzeichen zählt nicht mit. Ältere französische
    // Sätze haben dort noch einen ("Salut !", siehe punctuation_fix()), und
    // manches Kind tippt ihn - gemeint ist beides dasselbe, also darf es
    // nicht den Unterschied zwischen richtig und falsch ausmachen.
    $text = preg_replace('/ +([.,;:!?])/u', '$1', $text) ?? $text;

    return mb_strtolower(trim($text));
}

/** Normalisiert und entfernt Akzente - Wortgrenzen bleiben erhalten. */
function answer_fold(string $text): string
{
    return strtr(answer_normalize($text), DIACRITICS);
}

/**
 * Zusätzlich ohne Apostrophe, Bindestriche, Leerzeichen und Satzzeichen -
 * die tolerante Stufe.
 *
 * Die Satzzeichen kamen dazu, als "Comment ça va" ohne Fragezeichen als
 * falsch zählte: Die Lücke fragt die Vokabel ab, nicht das Satzende. Wer es
 * weglässt, bekommt "Fast!" und sieht die Schreibweise mit Zeichen.
 */
function answer_simplify(string $text): string
{
    return preg_replace("/['\- .,;:!?¿¡]/u", '', answer_fold($text)) ?? '';
}

/**
 * Vergleicht die Eingabe des Kindes mit der erwarteten Antwort.
 *
 * @return array{correct:bool, exact:bool}
 *         correct: zählt als richtig. exact: auch in der Schreibweise richtig.
 *         correct && !exact => richtig, aber die Schreibweise zeigen.
 */
function answer_check(string $typed, string $expected): array
{
    if (trim($typed) === '') {
        return ['correct' => false, 'exact' => false];
    }

    if (answer_normalize($typed) === answer_normalize($expected)) {
        return ['correct' => true, 'exact' => true];
    }

    // Fehlende Akzente, Apostrophe und Satzzeichen verzeihen - auf einer
    // Handytastatur sind sie mühsam, und der Sinn der Übung ist die Vokabel,
    // nicht die Tipparbeit.
    if (answer_simplify($typed) === answer_simplify($expected)) {
        return ['correct' => true, 'exact' => false];
    }

    return ['correct' => false, 'exact' => false];
}

// ---------------------------------------------------------------- Prüfung

/**
 * Prüft einen vom Modell gelieferten Satz.
 * Liefert den bereinigten Satz oder null, wenn er unbrauchbar ist.
 *
 * @param array<int,int> $allowedVocab Nummern, die angefragt wurden
 */
function sentence_clean(array $row, array $allowedVocab, ?string $lang = null): ?array
{
    $vocabId = (int) ($row['vocab_id'] ?? 0);
    if (!in_array($vocabId, $allowedVocab, true)) {
        return null;   // gehört nicht zur Anfrage
    }

    // Abstände vor Satzzeichen wegnehmen, bevor geprüft und gespeichert
    // wird (punctuation_fix()). Die Lücke {} bleibt davon unberührt.
    $native  = punctuation_fix((string) ($row['native'] ?? ''), 'de');
    $foreign = punctuation_fix((string) ($row['foreign'] ?? ''), $lang);
    $answer  = punctuation_fix((string) ($row['answer'] ?? ''), $lang);

    if ($native === '' || $foreign === '' || $answer === '') {
        return null;
    }

    // Genau eine Lücke, und zwar im Fremdsatz.
    if (substr_count($foreign, SENTENCE_PLACEHOLDER) !== 1) {
        return null;
    }
    if (str_contains($native, SENTENCE_PLACEHOLDER)) {
        return null;
    }

    if (answer_is_given_away($foreign, $answer)) {
        return null;
    }

    return [
        'vocab_id' => $vocabId,
        'native'   => mb_substr($native, 0, 255),
        'foreign'  => mb_substr($foreign, 0, 255),
        'answer'   => mb_substr($answer, 0, 128),
    ];
}

/**
 * Einen Satz von Hand ändern - mit denselben Prüfungen wie einen erzeugten.
 *
 * Steht hier und nicht im Admin, seit auch die Lehrkraft Sätze ändert: bei
 * einer Meldung aus dem Lückentext. Zwei Fassungen hätten früher oder
 * später zwei verschiedene Vorstellungen davon, was ein gültiger Satz ist.
 *
 * @return int|null Geänderte Zeilen, oder null, wenn die Form nicht stimmt -
 *                  dann bleibt der Satz, wie er war.
 */
function sentence_update(int $sentenceId, string $native, string $foreign,
                         string $answer, ?string $lang): ?int
{
    $row = sentence_clean([
        // Die Zugehörigkeit steht in der Datenbank; hier zählt nur die Form.
        'vocab_id' => 1,
        'native'   => $native,
        'foreign'  => $foreign,
        'answer'   => $answer,
    ], [1], $lang);

    if ($row === null) {
        return null;   // lieber nichts ändern als kaputt speichern
    }
    return q(
        'UPDATE sentences SET native_text = ?, foreign_text = ?, answer = ? WHERE id = ?',
        [$row['native'], $row['foreign'], $row['answer'], $sentenceId],
    )->rowCount();
}

/** Die Form, die sentence_update() verlangt - für die Meldung, wenn sie fehlt. */
const SENTENCE_FORM_HINT = 'genau eine Lücke {} im fremdsprachigen Satz, Lösung nicht leer '
                         . 'und nicht daneben im Satz';

/**
 * Steht die Lösung schon im Satz? Dann wäre die Übung sinnlos.
 *
 * Bewusst wortweise und erst ab vier Zeichen. Ein früherer Versuch verglich
 * die Zeichenketten ohne Leerzeichen - dabei fand sich "le" in nahezu jedem
 * französischen Satz, und gültige Sätze wurden reihenweise verworfen. Kurze
 * Funktionswörter tauchen nun einmal überall auf und verraten nichts; ein
 * Inhaltswort dagegen schon.
 */
function answer_is_given_away(string $foreign, string $answer): bool
{
    // Ohne Akzente vergleichen: "Eleve" neben der Lösung "élève" verrät genauso.
    $needle = answer_fold($answer);
    if (mb_strlen($needle) < 4) {
        return false;
    }

    $rest = answer_fold(str_replace(SENTENCE_PLACEHOLDER, ' ', $foreign));
    if ($rest === '') {
        return false;
    }

    // Wortgrenzen statt roher Teilzeichenkette: "ans" darf in "dans" stecken.
    $pattern = '/(?<!\pL)' . preg_quote($needle, '/') . '(?!\pL)/u';

    return preg_match($pattern, $rest) === 1;
}

// ---------------------------------------------------------------- Erzeugung

function sentence_schema(): array
{
    return [
        'type'       => 'object',
        'properties' => [
            'sentences' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'vocab_id' => ['type' => 'integer', 'description' => 'Die mitgelieferte Nummer'],
                        'native'   => ['type' => 'string', 'description' => 'Der deutsche Satz, ohne Lücke'],
                        'foreign'  => ['type' => 'string', 'description' => 'Derselbe Satz in der Fremdsprache, mit {} als Lücke'],
                        'answer'   => ['type' => 'string', 'description' => 'Was genau in die Lücke gehört'],
                    ],
                    'required'             => ['vocab_id', 'native', 'foreign', 'answer'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required'             => ['sentences'],
        'additionalProperties' => false,
    ];
}

/**
 * Vokabeln einer Lerneinheit.
 *
 * Frueher waren Aussagen, Fragen und Interjektionen ausgenommen - in der
 * Annahme, ein Lueckentext ergaebe dafuer keinen Sinn. Das war falsch: Gerade
 * bei ganzen Aeusserungen ist er die wertvollste Uebung, weil das Kind sie
 * produzieren muss statt sie wiederzuerkennen. Die Luecke deckt dann einen
 * kennzeichnenden Teil ab: "Wie heisst du?" / "{} comment ?".
 */
function sentence_candidates(int $unitId): array
{
    /*
     * Nur die freigegebenen Vokabeln.
     *
     * Ein Lueckensatz wird gebraucht, wenn ein Kind ihn ueben soll - und
     * ueben kann es nur, was aufgemacht ist. Eine Vokabel, die gerade von
     * Hand dazugekommen ist, steht hinter der Marke und wartet; ihr Satz
     * wartet mit.
     *
     * Das war eine Weile anders - es entstand alles gleich beim Einlesen,
     * damit niemand nach dem Freigeben warten muss. Der Grund ist
     * weggefallen: Die Freigabe antwortet inzwischen zuerst und arbeitet
     * danach weiter, die Seite sagt "entsteht gerade" und laedt sich von
     * selbst nach. Gewartet wird also so oder so nicht - und so entsteht
     * nichts fuer Woerter, die vielleicht nie drankommen.
     */
    return qa(
        'SELECT v.id, v.term_foreign, v.term_native, v.word_type
           FROM vocab v
           JOIN units u ON u.id = v.unit_id
          WHERE v.unit_id = ? AND v.position < u.released_position
          ORDER BY v.position, v.id',
        [$unitId],
    );
}

/**
 * Wortschatz aus den anderen Lerneinheiten desselben Kurses - gilt als bekannt.
 *
 * Zweck ist ein besserer Prompt: Das Modell soll Saetze aus Woertern bauen,
 * die das Kind schon kennt. Frueher zaehlte dafuer die ganze Sprache. In
 * einem einzelnen Kurs ist das dasselbe, an einer Schule mit mehreren nicht -
 * dann wanderte der Wortschatz fremder Klassen in die Anfrage. Das bricht nichts, macht den
 * Prompt aber teurer und die Saetze schlechter, weil "bekannt" dann Woerter
 * meint, die dieses Kind nie gesehen hat.
 *
 * Ebenfalls nur Freigegebenes: Ein Satz soll nicht aus einem Wort bestehen,
 * das erst naechste Woche drankommt.
 */
function known_vocabulary(?int $courseId, int $exceptUnitId): array
{
    if ($courseId === null) {
        return [];
    }

    return qa(
        'SELECT v.term_foreign, v.term_native
           FROM vocab v
           JOIN units t ON t.id = v.unit_id
          WHERE t.course_id = ? AND t.id <> ?
          ORDER BY t.position DESC, v.position
          LIMIT ' . KNOWN_VOCAB_LIMIT,
        [$courseId, $exceptUnitId],
    );
}

function sentence_prompt(string $languageName, int $perVocab, array $rows, array $known): string
{
    $lines = [];
    foreach ($rows as $r) {
        $lines[] = sprintf("%d\t%s\t%s", (int) $r['id'], $r['term_foreign'], $r['term_native']);
    }

    $knownLine = [];
    foreach ($known as $k) {
        $knownLine[] = $k['term_foreign'];
    }

    $text = [
        'Schreibe Lückensätze zum Üben von Vokabeln. Die Fremdsprache ist: '
            . $languageName . '. Die Muttersprache ist Deutsch.',
        '',
        'Zu jeder Vokabel ' . $perVocab . ' verschiedene Sätze. Je Satz:',
        '- "native": ein kurzer, natürlicher deutscher Satz, der die Vokabel benutzt.',
        '- "foreign": derselbe Satz in der Fremdsprache, mit {} an der Stelle der Vokabel.',
        '- "answer": genau das, was in die Lücke gehört - in der Form, die der Satz',
        '  verlangt. Aus "s\'appeler" wird "Je m\'appelle", aus "grand" wird "grande",',
        '  wenn das Substantiv weiblich ist. Nicht einfach die Vokabel abschreiben.',
        '',
        'Regeln:',
        '- Kurz und einfach. Die Sätze sind für ein Schulkind im Anfangsunterricht.',
        '- Ist der Eintrag selbst schon eine ganze Äußerung - eine Frage, eine',
        '  Grußformel, eine Wendung -, dann baue keinen Satz darum herum. Der',
        '  deutsche Satz ist dann die Äußerung auf Deutsch, der fremdsprachige',
        '  dieselbe Äußerung mit der Lücke an einer kennzeichnenden Stelle:',
        '    "Wie heißt du?" / "{} comment ?" mit der Lösung "Tu t\'appelles"',
        '    "Wie heißt du?" / "Tu {} comment ?" mit der Lösung "t\'appelles"',
        '  Bei sehr kurzen Äußerungen darf die Lücke alles ersetzen:',
        '    "Gute Nacht!" / "{}" mit der Lösung "Bonne nuit !"',
        '  Setze die Lücke bei mehreren Sätzen an verschiedene Stellen, damit das',
        '  Kind die Wendung nach und nach ganz beherrscht.',
        '- Benutze ausser der geübten Vokabel nur Wörter, die das Kind kennt: die',
        '  Vokabeln dieser Lerneinheit, die weiter unten aufgelisteten bekannten',
        '  Wörter, sowie Artikel, Zahlwörter, Personalpronomen, Frageworte und die',
        '  Formen von "sein" und "haben". Diese Grundwörter darfst du voraussetzen.',
        '- Die Lücke steht genau einmal im Satz, geschrieben als {}.',
        '- Der deutsche Satz enthält keine Lücke.',
        '- Die Lösung darf im Satz nicht noch einmal auftauchen.',
        '- Die ' . $perVocab . ' Sätze zu einer Vokabel sollen sich unterscheiden,',
        '  nicht nur ein Wort austauschen.',
        '- Achte auf die Zeichensetzung der Fremdsprache.',
        '',
        'Vokabeln (Nummer, Fremdsprache, Deutsch):',
        implode("\n", $lines),
    ];

    if ($knownLine !== []) {
        $text[] = '';
        $text[] = 'Diese Wörter kennt das Kind schon und darfst du verwenden:';
        $text[] = implode(', ', $knownLine);
    }

    return implode("\n", $text);
}

/**
/**
 * Erzeugt fehlende Lückensätze für eine Lerneinheit und speichert sie.
 *
 * In Blöcken zu SENTENCE_BATCH Vokabeln. Ein einzelner Aufruf über eine ganze
 * grosse Lerneinheit lief minutenlang - lange genug, dass MySQL die untätige
 * Verbindung schloss und das Speichern danach mit "server has gone away"
 * scheiterte, nachdem die Anfrage bereits bezahlt war. Kleinere Blöcke sind
 * kürzer unterwegs, und ein misslungener Block kostet nicht die ganze Einheit.
 *
 * Der Wortschatz-Vorspann wird dadurch mehrfach geschickt; das sind bei vier
 * Blöcken ein bis zwei Cent - der Preis für Verlässlichkeit.
 *
 * @return array{created:int, skipped:int, without:int, failed:?string}
 */
function generate_sentences(array $unit, array $user): array
{
    anthropic_autoload();

    $unitId   = (int) $unit['id'];
    $perVocab = max(1, min(5, (int) setting('sentences_per_vocab', '3')));
    $model    = setting_model('sentence_model');

    $lang = q1('SELECT name, code FROM languages WHERE id = ?', [(int) $unit['language_id']]);

    /*
     * Alle Vokabeln ohne Sätze - der Knopf im Admin trägt damit gezielt
     * nach, was fehlt, und ein zweiter Lauf erzeugt nichts doppelt.
     */
    $rows = qa(
        'SELECT v.id, v.term_foreign, v.term_native, v.word_type
           FROM vocab v
          WHERE v.unit_id = ?
            AND NOT EXISTS (SELECT 1 FROM sentences s WHERE s.vocab_id = v.id)
          ORDER BY v.position, v.id',
        [$unitId],
    );

    if ($rows === []) {
        return ['created' => 0, 'skipped' => 0, 'without' => 0, 'failed' => null];
    }

    $known = known_vocabulary(
        $unit['course_id'] === null ? null : (int) $unit['course_id'],
        $unitId,
    );
    $created = 0;
    $skipped = 0;
    $failed  = null;

    foreach (array_chunk($rows, SENTENCE_BATCH) as $batch) {
        // Vor jedem Block prüfen: Ein Abbruch soll nicht erst beim Budget enden.
        $blocked = budget_block_reason((int) $user['id']);
        if ($blocked !== null) {
            $failed = $blocked;
            break;
        }

        try {
            [$c, $s] = generate_sentence_batch(
                $batch, $known, (string) $lang['name'], $lang['code'],
                $perVocab, $model, $user,
            );
            $created += $c;
            $skipped += $s;
        } catch (Throwable $e) {
            // Was frühere Blöcke erzeugt haben, bleibt erhalten.
            $failed = $e->getMessage();
            break;
        }
    }

    return [
        'created' => $created,
        'skipped' => $skipped,
        'without' => vocab_without_sentences($unitId),
        'failed'  => $failed,
    ];
}

/**
 * Ein Block: ein API-Aufruf, dann protokollieren, dann speichern.
 *
 * @return array{0:int, 1:int} erzeugt, verworfen
 */
function generate_sentence_batch(
    array $rows,
    array $known,
    string $languageName,
    ?string $languageCode,
    int $perVocab,
    string $model,
    array $user,
): array {
    $prompt  = sentence_prompt($languageName, $perVocab, $rows, $known);
    $started = microtime(true);
    $logBase = [
        'user_id'    => (int) $user['id'],
        'user_label' => (string) $user['display_name'],
        'model'      => $model,
        'purpose'    => 'sentences',
    ];

    try {
        $message = anthropic_client()->messages->create(
            model: $model,
            maxTokens: 16000,
            messages: [['role' => 'user', 'content' => $prompt]],
            outputConfig: OutputConfig::with(
                effort: 'medium',
                format: JSONOutputFormat::with(schema: sentence_schema()),
            ),
        );
    } catch (Throwable $e) {
        db_ensure();
        ai_log($logBase + [
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'status'      => 'error',
            'error'       => substr(scrub_secrets($e->getMessage()), 0, 2000),
        ]);
        throw $e;
    }

    $durationMs = (int) ((microtime(true) - $started) * 1000);

    // Der Aufruf lief minutenlang ohne Datenbankverkehr - die Verbindung kann
    // in der Zwischenzeit geschlossen worden sein.
    db_ensure();

    $usage = [
        'input_tokens'       => $message->usage->inputTokens,
        'output_tokens'      => $message->usage->outputTokens,
        'cache_read_tokens'  => $message->usage->cacheReadInputTokens ?? 0,
        'cache_write_tokens' => $message->usage->cacheCreationInputTokens ?? 0,
        'duration_ms'        => $durationMs,
    ];

    if ($message->stopReason === 'refusal') {
        ai_log($logBase + $usage + ['status' => 'refusal']);
        throw new RuntimeException('Die Sätze konnten nicht erzeugt werden.');
    }

    // Abgeschnittene Antwort: Das JSON ist unvollständig und nicht lesbar.
    // Ohne diese Prüfung stünde im Protokoll "ok" mit null Einträgen.
    if ($message->stopReason === 'max_tokens') {
        ai_log($logBase + $usage + [
            'status' => 'error',
            'error'  => 'Antwort war zu lang und wurde abgeschnitten (max_tokens). '
                      . 'Weniger Sätze je Vokabel einstellen oder kleinere Blöcke.',
        ]);
        throw new RuntimeException('Die Antwort des Modells war zu lang.');
    }

    $json = '';
    foreach ($message->content as $block) {
        if ($block->type === 'text') {
            $json = $block->text;
            break;
        }
    }

    $allowed = array_map(static fn (array $r): int => (int) $r['id'], $rows);
    $data    = json_decode($json, true);
    $clean   = [];
    $skipped = 0;

    foreach ($data['sentences'] ?? [] as $row) {
        $ok = is_array($row) ? sentence_clean($row, $allowed, $languageCode) : null;
        if ($ok === null) {
            $skipped++;
            continue;
        }
        $clean[] = $ok;
    }

    // Erst protokollieren, dann speichern: Geht das Speichern schief, ist der
    // bezahlte Aufruf trotzdem verbucht.
    ai_log($logBase + $usage + [
        'entry_count' => count($clean),
        'status'      => 'ok',
    ]);

    $st = db()->prepare(
        'INSERT INTO sentences (vocab_id, native_text, foreign_text, answer) VALUES (?, ?, ?, ?)'
    );
    foreach ($clean as $c) {
        $st->execute([$c['vocab_id'], $c['native'], $c['foreign'], $c['answer']]);
    }

    return [count($clean), $skipped];
}

/** Wie viele geeignete Vokabeln der Einheit noch keinen Satz haben. */
function vocab_without_sentences(int $unitId): int
{
    /*
     * Eine Abfrage statt einer je Vokabel: Die Kursseite fragt das alle zwei
     * Sekunden für jede Lerneinheit ab, solange etwas entsteht
     * (erzeugung_stand()). Die Auswahl ist dieselbe wie in
     * sentence_candidates() - freigegeben heisst: vor der Marke.
     */
    return (int) qv(
        'SELECT COUNT(*)
           FROM vocab v
           JOIN units u ON u.id = v.unit_id
          WHERE v.unit_id = ? AND v.position < u.released_position
            AND NOT EXISTS (SELECT 1 FROM sentences s WHERE s.vocab_id = v.id)',
        [$unitId],
    );
}

/**
 * Wie viele Vokabeln der Einheit haben ueberhaupt einen Satz?
 *
 * Eine Frage an den Inhalt, nicht an ein Kind - deshalb ohne Benutzer. Der
 * Hintergrundlauf braucht genau das, um zu entscheiden, ob etwas Brauchbares
 * entstanden ist.
 */
function cloze_sentence_count(int $unitId): int
{
    return (int) qv(
        'SELECT COUNT(*) FROM vocab v
          WHERE v.unit_id = ?
            AND EXISTS (SELECT 1 FROM sentences s WHERE s.vocab_id = v.id)',
        [$unitId],
    );
}

/**
 * Fortschritt eines Kindes im Lückentext.
 *
 * Zählt nur Vokabeln, die auch einen Satz haben - sonst wäre die Einheit nie
 * zu schaffen, wenn zu einer Vokabel kein brauchbarer Satz entstanden ist.
 *
 * Der Benutzerfilter ist nicht schmueckend: Ohne ihn zaehlt die Abfrage die
 * Treffer aller Kinder zusammen, sobald sich mehrere einen Vokabelsatz teilen.
 */
function cloze_progress(int $unitId, int $userId): array
{
    $row = q1(
        'SELECT COUNT(v.id) AS total,
                SUM(CASE WHEN p.known_at IS NOT NULL THEN 1 ELSE 0 END) AS known
           FROM vocab v
           LEFT JOIN progress p
                  ON p.vocab_id = v.id AND p.mode = ? AND p.user_id = ?
          WHERE v.unit_id = ? AND v.position < ?
            AND EXISTS (SELECT 1 FROM sentences s WHERE s.vocab_id = v.id)',
        [MODE_CLOZE, $userId, $unitId, visible_position_for($unitId, $userId)],
    );
    return [(int) ($row['known'] ?? 0), (int) ($row['total'] ?? 0)];
}

// ---------------------------------------------------------------- Hintergrundlauf

const SENTENCE_PENDING = 'pending';
const SENTENCE_RUNNING = 'running';
const SENTENCE_DONE    = 'done';
const SENTENCE_FAILED  = 'failed';

/**
 * Ein Lauf, der laenger dauert, ist abgestuerzt.
 *
 * Ohne diese Grenze bliebe eine Lerneinheit fuer immer auf "running" stehen,
 * wenn der Vorgang abgebrochen wurde - und der Knopf waere nie wieder
 * anklickbar.
 */
const SENTENCE_STALE_AFTER = 900;   // Sekunden

/**
 * Den Lauf für sich beanspruchen. Gibt false zurück, wenn schon einer läuft.
 *
 * Bisher stand hier ein Prüfen und danach ein Setzen, mit einer Lücke
 * dazwischen. Bei einem einzelnen Kind feuert das nie: Es stösst die
 * Satzerzeugung an, fertig. Bei einer Klasse sitzen 28 Kinder in derselben
 * Minute davor, alle sehen "noch keine Sätze", und alle starten denselben
 * Lauf - achtundzwanzig bezahlte Anfragen für ein Ergebnis.
 *
 * Ein einziges UPDATE mit der Bedingung im WHERE entscheidet die Sache in
 * der Datenbank statt in PHP. Genau eine Anfrage bekommt die Zeile.
 *
 * Ein hängengebliebener Lauf blockiert dabei nicht für immer: Ist der
 * Startzeitpunkt alt genug, gilt die Zeile wieder als frei. Ohne diese
 * Bedingung wäre eine abgestürzte Erzeugung ein Riegel, den niemand mehr
 * aufbekommt.
 *
 * Der Startzeitpunkt wird in jedem Fall neu geschrieben. Das ist kein
 * Beiwerk: MySQL zählt bei einem UPDATE nur die tatsächlich geänderten
 * Zeilen, und wäre der Status der einzige Wert, meldete ein Übergang von
 * "running" (abgestanden) nach "running" null geänderte Zeilen - der
 * Anspruch ginge verloren, obwohl er berechtigt war.
 */
function sentence_claim(int $unitId): bool
{
    $stale = (int) SENTENCE_STALE_AFTER;

    $st = q(
        "UPDATE units
            SET sentences_status     = '" . SENTENCE_RUNNING . "',
                sentences_error      = NULL,
                sentences_started_at = NOW()
          WHERE id = ?
            AND (sentences_status IS NULL
                 OR sentences_status <> '" . SENTENCE_RUNNING . "'
                 OR sentences_started_at IS NULL
                 OR sentences_started_at < NOW() - INTERVAL $stale SECOND)",
        [$unitId],
    );

    return $st->rowCount() === 1;
}

function sentence_status_set(int $unitId, string $status, ?string $error = null): void
{
    q(
        'UPDATE units
            SET sentences_status = ?,
                sentences_error = ?,
                sentences_started_at = CASE WHEN ? = ? THEN NOW() ELSE sentences_started_at END
          WHERE id = ?',
        [$status, $error !== null ? mb_substr($error, 0, 255) : null,
         $status, SENTENCE_RUNNING, $unitId],
    );
}

/**
 * Zustand der Satzerzeugung, wie ihn die Oberflaeche braucht.
 *
 * @return array{status:string, error:?string, known:int, total:int}
 */
function sentence_status(int $unitId, int $userId): array
{
    $unit = q1(
        'SELECT sentences_status, sentences_error, sentences_started_at
           FROM units WHERE id = ?',
        [$unitId],
    );
    [$known, $total] = cloze_progress($unitId, $userId);

    $status = (string) ($unit['sentences_status'] ?? '');
    $error  = $unit['sentences_error'] ?? null;

    // Haengengeblieben: Der Vorgang lebt nicht mehr, aber niemand hat es
    // vermerkt. Gibt es trotzdem Saetze, ist es gut genug.
    if ($status === SENTENCE_RUNNING && $unit['sentences_started_at'] !== null) {
        $alter = time() - strtotime((string) $unit['sentences_started_at']);
        if ($alter > SENTENCE_STALE_AFTER) {
            $status = $total > 0 ? SENTENCE_DONE : SENTENCE_FAILED;
            $error  = $total > 0 ? null : 'Der Vorgang wurde unterbrochen.';
            sentence_status_set($unitId, $status, $error);
        }
    }

    if ($status === '') {
        // Lerneinheiten aus der Zeit vor dem Hintergrundlauf.
        $status = $total > 0 ? SENTENCE_DONE : SENTENCE_PENDING;
    }

    return [
        'status' => $status,
        'error'  => $error,
        'known'  => $known,
        'total'  => $total,
    ];
}

/**
 * Erzeugt die Saetze einer Lerneinheit und fuehrt dabei den Zustand mit.
 *
 * Gedacht fuer den Lauf im Hintergrund: Der Aufrufer hat die Antwort an das
 * Kind schon geschickt, hier darf nichts mehr ausgegeben werden. Fehler landen
 * im Protokoll und am Zustand der Lerneinheit, nicht in einer Antwort.
 */
function generate_sentences_tracked(int $unitId): void
{
    $unit = q1('SELECT * FROM units WHERE id = ?', [$unitId]);
    if ($unit === null) {
        return;
    }
    // Wer die Anfrage zu verantworten hat - die Lehrkraft des Kurses, sonst
    // das einzige Mitglied. Frueher stand hier units.user_id.
    $user = course_billing_user(
        $unit['course_id'] === null ? null : (int) $unit['course_id'],
    );
    if ($user === null) {
        error_log('[vokabeltrainer] Saetze: Lerneinheit ' . $unitId
                  . ' hat keinen Kurs mit Mitgliedern - kein Lauf.');
        sentence_status_set($unitId, SENTENCE_FAILED,
            'Diese Lerneinheit gehoert zu keinem Kurs.');
        return;
    }

    /*
     * Gibt es ueberhaupt etwas zu tun?
     *
     * "Nichts zu tun" ist kein Fehlschlag, und genau das stand hier: Eine
     * Lehrkraft liest eine Unit ein, freigegeben ist noch nichts, also gibt
     * es auch keine Vokabel, fuer die ein Satz entstehen koennte - und die
     * Einheit trug danach "Es entstand kein brauchbarer Satz". Das war
     * gelogen und liess eine frisch eingelesene Lektion kaputt aussehen.
     *
     * Der Zustand richtet sich danach, ob schon Saetze da sind: Sind welche
     * da, ist die Einheit fertig; sonst wartet sie auf die Freigabe.
     */
    if (vocab_without_sentences($unitId) === 0) {
        sentence_status_set(
            $unitId,
            cloze_sentence_count($unitId) > 0 ? SENTENCE_DONE : SENTENCE_PENDING,
        );
        // Die Sätze sind da - fehlen noch Aufnahmen, kommen sie jetzt.
        sentence_audio_nachtragen($unitId, $user);
        return;
    }

    $blocked = budget_block_reason((int) $user['id']);
    if ($blocked !== null) {
        sentence_status_set($unitId, SENTENCE_FAILED, $blocked);
        return;
    }

    sentence_status_set($unitId, SENTENCE_RUNNING);

    try {
        $res = generate_sentences($unit, $user);
    } catch (Throwable $e) {
        error_log('[vokabeltrainer] Saetze (Hintergrund): ' . scrub_secrets($e->getMessage()));
        db_ensure();
        sentence_status_set($unitId, SENTENCE_FAILED,
            'Die Saetze konnten nicht erzeugt werden.');
        return;
    }

    db_ensure();
    $total = cloze_sentence_count($unitId);

    if ($total === 0) {
        sentence_status_set($unitId, SENTENCE_FAILED,
            $res['failed'] ?? 'Es entstand kein brauchbarer Satz.');
        return;
    }

    // Teilerfolg zaehlt als fertig: Das Kind kann ueben, der Rest laesst sich
    // im Admin nachtragen.
    sentence_status_set($unitId, SENTENCE_DONE,
        $res['failed'] !== null ? 'Teilweise: ' . $res['failed'] : null);

    sentence_audio_nachtragen($unitId, $user);
}

/**
 * Die Aufnahmen für "Hören" - im selben Lauf, gleich nach den Sätzen.
 *
 * Erst nachdem der Zustand der Sätze steht: Die Klasse kann den Lückentext
 * schon üben, während gesprochen wird. Ein Fehler hier kostet nur die
 * Aufnahmen, nie die Sätze - deshalb abgefangen und nur protokolliert.
 */
function sentence_audio_nachtragen(int $unitId, array $user): void
{
    try {
        $res = tts_nachtragen($unitId, $user);
        if ($res['fehler'] !== null) {
            error_log('[vokabeltrainer] Aufnahmen, Lerneinheit ' . $unitId . ': ' . $res['fehler']);
        }
    } catch (Throwable $e) {
        error_log('[vokabeltrainer] Aufnahmen: ' . scrub_secrets($e->getMessage()));
    }
}
