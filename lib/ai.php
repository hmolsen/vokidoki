<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/cost.php';
require_once __DIR__ . '/keyvault.php';

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
            'Anthropic-SDK fehlt. Auf dem Server ausfuehren: composer install'
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
    // die Anfragen ueber ein Gateway laufen sollen - und von den Tests.
    $baseUrl = (string) cfg('anthropic_base_url', '');

    return new Client(
        apiKey: keyvault_anthropic_key(),
        baseUrl: $baseUrl !== '' ? $baseUrl : null,
    );
}

/** JSON-Schema fuer das Extraktionsergebnis. Erzwingt sauberes JSON statt Freitext-Parsing. */
function vocab_schema(): array
{
    return [
        'type'       => 'object',
        'properties' => [
            'title' => [
                'type'        => ['string', 'null'],
                'description' => 'Ueberschrift der Lerneinheit, z. B. "Unit 1" oder '
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
                    ],
                    'required'             => ['foreign', 'native', 'note'],
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
        'Du liest Vokabelseiten aus einem Schulbuch fuer ein deutsches Schulkind aus.',
        'Die Fremdsprache ist: ' . $languageName . '. Die Muttersprache ist Deutsch.',
        '',
        'Aufgabe:',
        '- Erfasse jedes Vokabelpaar von allen Bildern in der Reihenfolge, in der es auf den',
        '  Seiten steht. Mehrere Bilder gehoeren zu einer einzigen Lerneinheit.',
        '- Ordne jeden Begriff korrekt zu: "foreign" ist ' . $languageName . ', "native" ist Deutsch.',
        '  Buchseiten sind oft zweispaltig - lies spaltenweise, nicht zeilenweise quer ueber die Seite.',
        '- Uebernimm die Schreibweise exakt, inklusive Akzenten und Sonderzeichen.',
        '- Behalte Artikel ("das Haus", "la maison"), Pluralformen und Verbpartikel bei.',
        '- Steht zu einem Eintrag ein Beispielsatz, eine Lautschrift oder ein Hinweis, kommt er',
        '  nach "note", nicht in die Begriffsfelder.',
        '- Trenne mehrere Bedeutungen desselben Begriffs mit Komma innerhalb eines Feldes,',
        '  statt zwei Eintraege anzulegen.',
        '- Ignoriere Seitenzahlen, Kopf- und Fusszeilen, Grammatikkaesten, Uebungsaufgaben',
        '  und alles, was kein Vokabelpaar ist.',
        '- Suche eine Ueberschrift der Lerneinheit ("Unit 1", "Lektion 3", "Vocabulary 2A")',
        '  und gib sie in "title" zurueck. Findest du keine, setze "title" auf null - rate nicht.',
        '- Ist ein Wort schwer lesbar, gib deine beste Lesart an, statt den Eintrag wegzulassen.',
        '',
        'Gib ausschliesslich das geforderte JSON zurueck.',
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

    // Sicherheitsklassifikatoren koennen einen Request ablehnen - das kommt als
    // HTTP 200 zurueck, also vor dem Lesen von content immer stopReason pruefen.
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
                'foreign' => mb_substr($foreign, 0, 255),
                'native'  => mb_substr($native, 0, 255),
                'note'    => $note === '' ? null : mb_substr($note, 0, 255),
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
