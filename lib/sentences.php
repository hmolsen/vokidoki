<?php
declare(strict_types=1);

require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/wordtypes.php';
require_once __DIR__ . '/progress.php';

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

/** Kategorien, für die ein Lückensatz keinen Sinn ergibt. */
const SENTENCE_SKIP_TYPES = ['aussage', 'frage', 'interjektion'];

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
    'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o',
    'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
    'ç' => 'c', 'ñ' => 'n', 'ß' => 'ss', 'ý' => 'y', 'ÿ' => 'y',
];

/** Vereinheitlicht Leerzeichen, Apostrophe und Groß-/Kleinschreibung. */
function answer_normalize(string $text): string
{
    // Typografische Apostrophe und Anführungszeichen auf das schlichte ' bringen -
    // welches davon eine Handytastatur liefert, ist nicht vorhersagbar.
    $text = str_replace(["\u{2019}", "\u{02BC}", "\u{2018}", '`', "\u{00B4}"], "'", $text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    return mb_strtolower(trim($text));
}

/** Normalisiert und entfernt Akzente - Wortgrenzen bleiben erhalten. */
function answer_fold(string $text): string
{
    return strtr(answer_normalize($text), DIACRITICS);
}

/** Zusätzlich ohne Apostrophe, Bindestriche und Leerzeichen - die tolerante Stufe. */
function answer_simplify(string $text): string
{
    return str_replace(["'", '-', ' '], '', answer_fold($text));
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

    // Fehlende Akzente und Apostrophe verzeihen - auf einer Handytastatur sind
    // sie mühsam, und der Sinn der Übung ist die Vokabel, nicht die Tipparbeit.
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
function sentence_clean(array $row, array $allowedVocab): ?array
{
    $vocabId = (int) ($row['vocab_id'] ?? 0);
    if (!in_array($vocabId, $allowedVocab, true)) {
        return null;   // gehört nicht zur Anfrage
    }

    $native  = trim((string) ($row['native'] ?? ''));
    $foreign = trim((string) ($row['foreign'] ?? ''));
    $answer  = trim((string) ($row['answer'] ?? ''));

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

/** Vokabeln einer Lerneinheit, für die ein Lückensatz sinnvoll ist. */
function sentence_candidates(int $unitId): array
{
    $rows = qa(
        'SELECT id, term_foreign, term_native, word_type
           FROM vocab WHERE unit_id = ? ORDER BY position, id',
        [$unitId],
    );

    return array_values(array_filter(
        $rows,
        static fn (array $r): bool => !in_array((string) ($r['word_type'] ?? ''), SENTENCE_SKIP_TYPES, true),
    ));
}

/** Wortschatz derselben Sprache aus anderen Lerneinheiten - gilt als bekannt. */
function known_vocabulary(int $languageId, int $exceptUnitId): array
{
    return qa(
        'SELECT v.term_foreign, v.term_native
           FROM vocab v
           JOIN units t ON t.id = v.unit_id
          WHERE t.language_id = ? AND t.id <> ?
          ORDER BY t.created_at DESC, v.position
          LIMIT ' . KNOWN_VOCAB_LIMIT,
        [$languageId, $exceptUnitId],
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
    $model    = setting('sentence_model', 'claude-sonnet-5');
    if (!array_key_exists($model, VISION_MODELS)) {
        $model = 'claude-sonnet-5';
    }

    $lang = q1('SELECT name FROM languages WHERE id = ?', [(int) $unit['language_id']]);

    // Nur Vokabeln ohne Sätze - so trägt der Knopf im Admin gezielt nach.
    $offen = qa(
        'SELECT v.id, v.term_foreign, v.term_native, v.word_type
           FROM vocab v
          WHERE v.unit_id = ?
            AND NOT EXISTS (SELECT 1 FROM sentences s WHERE s.vocab_id = v.id)
          ORDER BY v.position, v.id',
        [$unitId],
    );
    $rows = array_values(array_filter(
        $offen,
        static fn (array $r): bool => !in_array((string) ($r['word_type'] ?? ''), SENTENCE_SKIP_TYPES, true),
    ));

    if ($rows === []) {
        return ['created' => 0, 'skipped' => 0, 'without' => 0, 'failed' => null];
    }

    $known   = known_vocabulary((int) $unit['language_id'], $unitId);
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
                $batch, $known, (string) $lang['name'], $perVocab, $model, $user,
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
        $ok = is_array($row) ? sentence_clean($row, $allowed) : null;
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
    $n = 0;
    foreach (sentence_candidates($unitId) as $r) {
        if ((int) qv('SELECT COUNT(*) FROM sentences WHERE vocab_id = ?', [(int) $r['id']]) === 0) {
            $n++;
        }
    }
    return $n;
}

/**
 * Fortschritt im Lückentext.
 *
 * Zählt nur Vokabeln, die auch einen Satz haben - sonst wäre die Einheit nie
 * zu schaffen, wenn zu einer Vokabel kein brauchbarer Satz entstanden ist.
 */
function cloze_progress(int $unitId): array
{
    $row = q1(
        'SELECT COUNT(v.id) AS total,
                SUM(CASE WHEN p.known_at IS NOT NULL THEN 1 ELSE 0 END) AS known
           FROM vocab v
           LEFT JOIN progress p ON p.vocab_id = v.id AND p.mode = ?
          WHERE v.unit_id = ?
            AND EXISTS (SELECT 1 FROM sentences s WHERE s.vocab_id = v.id)',
        [MODE_CLOZE, $unitId],
    );
    return [(int) ($row['known'] ?? 0), (int) ($row['total'] ?? 0)];
}
