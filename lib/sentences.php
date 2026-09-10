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

/** Zusätzlich ohne Zeichen und Apostrophe - die tolerante Stufe. */
function answer_simplify(string $text): string
{
    $text = strtr(answer_normalize($text), DIACRITICS);
    return str_replace(["'", '-', ' '], '', $text);
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

    // Die Lösung darf nicht daneben stehen - sonst ist die Übung sinnlos.
    $rest = answer_simplify(str_replace(SENTENCE_PLACEHOLDER, ' ', $foreign));
    if ($rest !== '' && str_contains($rest, answer_simplify($answer))) {
        return null;
    }

    return [
        'vocab_id' => $vocabId,
        'native'   => mb_substr($native, 0, 255),
        'foreign'  => mb_substr($foreign, 0, 255),
        'answer'   => mb_substr($answer, 0, 128),
    ];
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
 * Erzeugt fehlende Lückensätze für eine Lerneinheit und speichert sie.
 *
 * Ein Aufruf je Lerneinheit: Anweisung und Wortschatz sind für jeden Satz
 * dieselben - einzeln abgefragt bezahlt man sie hundertfach.
 *
 * @return array{created:int, skipped:int, without:int}
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

    $lang = q1('SELECT name, id FROM languages WHERE id = ?', [(int) $unit['language_id']]);
    $all  = sentence_candidates($unitId);

    // Nur Vokabeln ohne Sätze - der Knopf im Admin trägt so gezielt nach.
    $rows = array_values(array_filter($all, static function (array $r): bool {
        return (int) qv('SELECT COUNT(*) FROM sentences WHERE vocab_id = ?', [(int) $r['id']]) === 0;
    }));

    if ($rows === []) {
        return ['created' => 0, 'skipped' => 0, 'without' => 0];
    }

    $known  = known_vocabulary((int) $unit['language_id'], $unitId);
    $prompt = sentence_prompt((string) $lang['name'], $perVocab, $rows, $known);

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
            maxTokens: 32000,
            messages: [['role' => 'user', 'content' => $prompt]],
            outputConfig: OutputConfig::with(
                effort: 'medium',
                format: JSONOutputFormat::with(schema: sentence_schema()),
            ),
        );
    } catch (Throwable $e) {
        ai_log($logBase + [
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'status'      => 'error',
            'error'       => substr(scrub_secrets($e->getMessage()), 0, 2000),
        ]);
        throw $e;
    }

    $durationMs = (int) ((microtime(true) - $started) * 1000);

    if ($message->stopReason === 'refusal') {
        ai_log($logBase + ['duration_ms' => $durationMs, 'status' => 'refusal']);
        throw new RuntimeException('Die Sätze konnten nicht erzeugt werden.');
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
    $created = 0;
    $skipped = 0;

    $st = db()->prepare(
        'INSERT INTO sentences (vocab_id, native_text, foreign_text, answer) VALUES (?, ?, ?, ?)'
    );

    foreach ($data['sentences'] ?? [] as $row) {
        if (!is_array($row)) {
            $skipped++;
            continue;
        }
        $clean = sentence_clean($row, $allowed);
        if ($clean === null) {
            $skipped++;
            continue;
        }
        $st->execute([$clean['vocab_id'], $clean['native'], $clean['foreign'], $clean['answer']]);
        $created++;
    }

    ai_log($logBase + [
        'input_tokens'       => $message->usage->inputTokens,
        'output_tokens'      => $message->usage->outputTokens,
        'cache_read_tokens'  => $message->usage->cacheReadInputTokens ?? 0,
        'cache_write_tokens' => $message->usage->cacheCreationInputTokens ?? 0,
        'entry_count'        => $created,
        'duration_ms'        => $durationMs,
        'status'             => 'ok',
    ]);

    return [
        'created' => $created,
        'skipped' => $skipped,
        'without' => vocab_without_sentences($unitId),
    ];
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
