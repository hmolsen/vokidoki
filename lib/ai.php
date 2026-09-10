<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/cost.php';
require_once __DIR__ . '/keyvault.php';
require_once __DIR__ . '/wordtypes.php';

use Anthropic\Client;
use Anthropic\Messages\Base64ImageSource;
use Anthropic\Messages\ImageBlockParam;
use Anthropic\Messages\JSONOutputFormat;
use Anthropic\Messages\OutputConfig;

/**
 * Registriert den Composer-Autoloader.
 * Muss laufen, bevor irgendeine SDK-Klasse angefasst wird - auch bevor die
 * Nachricht zusammengebaut wird, nicht erst beim Absenden.
 */
function anthropic_autoload(): void
{
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException(
            'Anthropic-SDK fehlt. Auf dem Server ausführen: composer install'
        );
    }
    require_once $autoload;
}

/**
 * Erzeugt einen Client mit einem frisch aus dem Keyvault geholten Key.
 * Der Key wird nicht zwischengespeichert - so kann er jederzeit rotiert werden.
 */
function anthropic_client(): Client
{
    anthropic_autoload();

    // anthropic_base_url bleibt im Normalfall leer; gesetzt wird sie nur, wenn
    // die Anfragen über ein Gateway laufen sollen - und von den Tests.
    $baseUrl = (string) cfg('anthropic_base_url', '');

    return new Client(
        apiKey: keyvault_anthropic_key(),
        baseUrl: $baseUrl !== '' ? $baseUrl : null,
    );
}

/** JSON-Schema für das Extraktionsergebnis. Erzwingt sauberes JSON statt Freitext-Parsing. */
function vocab_schema(): array
{
    return [
        'type'       => 'object',
        'properties' => [
            'title' => [
                'type'        => ['string', 'null'],
                'description' => 'Überschrift der Lerneinheit, z. B. "Unit 1" oder '
                               . '"Lektion 3 - Im Restaurant". null, wenn auf den Bildern keine steht.',
            ],
            'entries' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'foreign' => ['type' => 'string', 'description' => 'Begriff in der Fremdsprache'],
                        'native'  => ['type' => 'string', 'description' => 'Deutsche Entsprechung'],
                        'note'    => [
                            'type'        => ['string', 'null'],
                            'description' => 'Optionaler Zusatz wie Beispielsatz oder Hinweis; sonst null.',
                        ],
                        'word_type' => [
                            'type'        => 'string',
                            'enum'        => word_type_keys(),
                            'description' => 'Kategorie des fremdsprachigen Eintrags: eine der '
                                           . 'zehn Wortarten, oder "frage" bzw. "aussage" für '
                                           . 'ganze Äußerungen, oder "sonstiges".',
                        ],
                    ],
                    'required'             => ['foreign', 'native', 'note', 'word_type'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required'             => ['title', 'entries'],
        'additionalProperties' => false,
    ];
}

function vocab_prompt(string $languageName): string
{
    $lines = [
        'Du liest Vokabelseiten aus einem Schulbuch für ein deutsches Schulkind aus.',
        'Die Fremdsprache ist: ' . $languageName . '. Die Muttersprache ist Deutsch.',
        '',
        'Aufgabe:',
        '- Erfasse jedes Vokabelpaar von allen Bildern in der Reihenfolge, in der es auf den',
        '  Seiten steht. Mehrere Bilder gehören zu einer einzigen Lerneinheit.',
        '- Ordne jeden Begriff korrekt zu: "foreign" ist ' . $languageName . ', "native" ist Deutsch.',
        '  Buchseiten sind oft zweispaltig - lies spaltenweise, nicht zeilenweise quer über die Seite.',
        '- Übernimm die Schreibweise exakt, inklusive Akzenten und Sonderzeichen.',
        '- Behalte Artikel ("das Haus", "la maison"), Pluralformen und Verbpartikel bei.',
        '- Steht zu einem Eintrag ein Beispielsatz, eine Lautschrift oder ein Hinweis, kommt er',
        '  nach "note", nicht in die Begriffsfelder.',
        '- Trenne mehrere Bedeutungen desselben Begriffs mit Komma innerhalb eines Feldes,',
        '  statt zwei Einträge anzulegen.',
        '- Ignoriere Seitenzahlen, Kopf- und Fußzeilen, Grammatikkästen, Übungsaufgaben',
        '  und alles, was kein Vokabelpaar ist.',
        '- Suche eine Überschrift der Lerneinheit ("Unit 1", "Lektion 3", "Vocabulary 2A")',
        '  und gib sie in "title" zurück. Findest du keine, setze "title" auf null - rate nicht.',
        '- Ist ein Wort schwer lesbar, gib deine beste Lesart an, statt den Eintrag wegzulassen.',
        '- Bestimme zu jedem Eintrag die Kategorie in "word_type".',
        '  Richte dich nach dem fremdsprachigen Eintrag, nicht nach der Übersetzung.',
        '  Einzelne Wörter bekommen ihre Wortart. Steht ein Artikel dabei',
        '  ("la maison", "das Haus"), zählt das Substantiv.',
        '  Ganze Äußerungen bekommen "frage", wenn sie eine Frage sind',
        '  ("Comment tu t\'appelles ?", "How are you?"), sonst "aussage"',
        '  ("Bonne nuit !", "Merci, Madame !", "Ich heiße Lilli.").',
        '  Das gilt auch ohne Satzzeichen - entscheidend ist, ob gefragt wird.',
        '  "sonstiges" nur, wenn wirklich nichts davon passt.',
        '',
        'Gib ausschließlich das geforderte JSON zurück.',
    ];

    return implode("\n", $lines);
}

/**
 * Schickt die Fotos an die Claude-API und liefert Titel + Vokabelpaare.
 *
 * @param array<int,array{data:string,media_type:string}> $images
 * @return array{title:?string, entries:array<int,array{foreign:string,native:string,note:?string}>, cost:float, model:string}
 */
function analyze_vocab_images(array $images, string $languageName, array $user): array
{
    anthropic_autoload();

    $model  = setting('vision_model', 'claude-opus-5');
    $effort = setting('vision_effort', 'medium');
    if (!in_array($effort, EFFORT_LEVELS, true)) {
        $effort = 'medium';
    }

    $content = [];
    foreach ($images as $img) {
        $content[] = ImageBlockParam::with(
            source: Base64ImageSource::with(
                data: $img['data'],
                mediaType: $img['media_type'],
            ),
        );
    }
    $content[] = ['type' => 'text', 'text' => vocab_prompt($languageName)];

    $started = microtime(true);
    $logBase = [
        'user_id'     => (int) $user['id'],
        'user_label'  => (string) $user['display_name'],
        'model'       => $model,
        'purpose'     => 'vocab_ocr',
        'image_count' => count($images),
    ];

    try {
        $message = anthropic_client()->messages->create(
            model: $model,
            maxTokens: 16000,
            messages: [['role' => 'user', 'content' => $content]],
            outputConfig: OutputConfig::with(
                effort: $effort,
                format: JSONOutputFormat::with(schema: vocab_schema()),
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

    // Sicherheitsklassifikatoren können einen Request ablehnen - das kommt als
    // HTTP 200 zurück, also vor dem Lesen von content immer stopReason prüfen.
    if ($message->stopReason === 'refusal') {
        ai_log($logBase + [
            'input_tokens'  => $message->usage->inputTokens,
            'output_tokens' => $message->usage->outputTokens,
            'duration_ms'   => $durationMs,
            'status'        => 'refusal',
            'error'         => scrub_secrets((string) $message->stopDetails?->explanation),
        ]);
        throw new RuntimeException('Die Bilder konnten nicht ausgewertet werden.');
    }

    $json = '';
    foreach ($message->content as $block) {
        if ($block->type === 'text') {
            $json = $block->text;
            break;
        }
    }

    $data    = json_decode($json, true);
    $entries = [];
    if (is_array($data) && isset($data['entries']) && is_array($data['entries'])) {
        foreach ($data['entries'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $foreign = trim((string) ($row['foreign'] ?? ''));
            $native  = trim((string) ($row['native'] ?? ''));
            if ($foreign === '' || $native === '') {
                continue;
            }
            $note      = isset($row['note']) && is_string($row['note']) ? trim($row['note']) : '';
            $entries[] = [
                'foreign'   => mb_substr($foreign, 0, 255),
                'native'    => mb_substr($native, 0, 255),
                'note'      => $note === '' ? null : mb_substr($note, 0, 255),
                'word_type' => word_type_clean($row['word_type'] ?? null),
            ];
        }
    }

    $title = null;
    if (is_array($data) && isset($data['title']) && is_string($data['title'])) {
        $t     = trim($data['title']);
        $title = $t === '' ? null : mb_substr($t, 0, 128);
    }

    $cost = ai_log($logBase + [
        'input_tokens'       => $message->usage->inputTokens,
        'output_tokens'      => $message->usage->outputTokens,
        'cache_read_tokens'  => $message->usage->cacheReadInputTokens ?? 0,
        'cache_write_tokens' => $message->usage->cacheCreationInputTokens ?? 0,
        'entry_count'        => count($entries),
        'duration_ms'        => $durationMs,
        'status'             => 'ok',
    ]);

    return ['title' => $title, 'entries' => $entries, 'cost' => $cost, 'model' => $model];
}

/** JSON-Schema für das Nachtragen der Kategorien. */
function word_type_schema(): array
{
    return [
        'type'       => 'object',
        'properties' => [
            'types' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'        => ['type' => 'integer', 'description' => 'Die mitgelieferte Nummer'],
                        'word_type' => ['type' => 'string', 'enum' => word_type_keys()],
                    ],
                    'required'             => ['id', 'word_type'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required'             => ['types'],
        'additionalProperties' => false,
    ];
}

/**
 * Bestimmt die Kategorien einer Liste bereits gespeicherter Vokabeln.
 *
 * @param array<int,array{id:int,term_foreign:string,term_native:string}> $rows
 * @return array<int,string> Kategorie je Vokabel-ID
 */
function classify_word_types(array $rows, string $languageName, array $user): array
{
    anthropic_autoload();

    if ($rows === []) {
        return [];
    }

    $model  = setting('vision_model', 'claude-opus-5');
    $effort = setting('vision_effort', 'medium');
    if (!in_array($effort, EFFORT_LEVELS, true)) {
        $effort = 'medium';
    }

    $lines = [];
    foreach ($rows as $row) {
        $lines[] = sprintf("%d\t%s\t%s", (int) $row['id'], $row['term_foreign'], $row['term_native']);
    }

    $prompt = implode("\n", [
        'Bestimme zu jeder Vokabel die Kategorie des fremdsprachigen Eintrags.',
        'Die Fremdsprache ist: ' . $languageName . '. Die Muttersprache ist Deutsch.',
        '',
        'Richte dich nach dem fremdsprachigen Eintrag, nicht nach der Übersetzung.',
        'Einzelne Wörter bekommen ihre Wortart. Steht ein Artikel dabei',
        '("la maison", "das Haus"), zählt das Substantiv.',
        'Ganze Äußerungen bekommen "frage", wenn sie eine Frage sind',
        '("Comment tu t\'appelles ?", "How are you?"), sonst "aussage"',
        '("Bonne nuit !", "Merci, Madame !").',
        'Das gilt auch ohne Satzzeichen - entscheidend ist, ob gefragt wird.',
        '"sonstiges" nur, wenn wirklich nichts davon passt.',
        '',
        'Gib zu jeder Nummer genau einen Eintrag zurück, für alle ' . count($rows) . ' Zeilen.',
        '',
        'Nummer, Fremdsprache und deutsche Bedeutung, durch Tabulator getrennt:',
        implode("\n", $lines),
    ]);

    $started = microtime(true);
    $logBase = [
        'user_id'    => (int) $user['id'],
        'user_label' => (string) $user['display_name'],
        'model'      => $model,
        'purpose'    => 'word_types',
    ];

    try {
        $message = anthropic_client()->messages->create(
            model: $model,
            maxTokens: 16000,
            messages: [['role' => 'user', 'content' => $prompt]],
            outputConfig: OutputConfig::with(
                effort: $effort,
                format: JSONOutputFormat::with(schema: word_type_schema()),
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
        throw new RuntimeException('Die Kategorien konnten nicht bestimmt werden.');
    }

    $json = '';
    foreach ($message->content as $block) {
        if ($block->type === 'text') {
            $json = $block->text;
            break;
        }
    }

    // Nur Nummern übernehmen, die auch angefragt wurden - das Modell soll
    // keine fremden Zeilen verändern können.
    $wanted = array_column($rows, 'id');
    $data   = json_decode($json, true);
    $result = [];
    foreach ($data['types'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id   = (int) ($row['id'] ?? 0);
        $type = word_type_clean($row['word_type'] ?? null);
        if ($type !== null && in_array($id, $wanted, true)) {
            $result[$id] = $type;
        }
    }

    ai_log($logBase + [
        'input_tokens'       => $message->usage->inputTokens,
        'output_tokens'      => $message->usage->outputTokens,
        'cache_read_tokens'  => $message->usage->cacheReadInputTokens ?? 0,
        'cache_write_tokens' => $message->usage->cacheCreationInputTokens ?? 0,
        'entry_count'        => count($result),
        'duration_ms'        => $durationMs,
        'status'             => 'ok',
    ]);

    return $result;
}
