<?php
declare(strict_types=1);

/**
 * Anthropic-Simulator für die Tests.
 *
 *   php -S 127.0.0.1:8125 tests/fake-anthropic.php
 *
 * Nimmt einen Messages-API-Aufruf entgegen, legt Header und Rumpf zur
 * Auswertung ab und antwortet im Format der echten API. So lässt sich der
 * gesamte Weg - Keyvault, SDK, Bildblock, Schema, Antwortauswertung,
 * Kostenprotokoll - prüfen, ohne die echte API zu belasten.
 */

// Wird bewusst per "php -S" ausgeliefert, gehört aber auf keinen echten
// Webserver.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

const RECORD_FILE = 'vt-fake-anthropic-last.json';
const EXPECTED_KEY = 'sk-ant-api03-FAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKE';

$raw     = file_get_contents('php://input') ?: '';
$request = json_decode($raw, true);

$headers = [];
foreach ($_SERVER as $name => $value) {
    if (str_starts_with($name, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
    }
}

file_put_contents(
    sys_get_temp_dir() . '/' . RECORD_FILE,
    json_encode(['headers' => $headers, 'body' => $request], JSON_UNESCAPED_UNICODE),
);

header('Content-Type: application/json');

if (($headers['x-api-key'] ?? '') !== EXPECTED_KEY) {
    http_response_code(401);
    echo json_encode(['type' => 'error', 'error' => [
        'type'    => 'authentication_error',
        'message' => 'invalid x-api-key',
    ]]);
    exit;
}

// Die Tests lösen einen Serverfehler aus, indem sie als Sprachnamen
// "Fehlerfall" übergeben - der steht dann in der Anweisung.
// Der Inhalt ist mal eine Liste von Blöcken (Bilder + Text), mal schlichter
// Text - beides kommt in der App vor.
$content     = $request['messages'][0]['content'] ?? '';
$instruction = '';
if (is_string($content)) {
    $instruction = $content;
} elseif (is_array($content)) {
    foreach ($content as $block) {
        if (($block['type'] ?? '') === 'text') {
            $instruction = (string) ($block['text'] ?? '');
        }
    }
}
if (str_contains($instruction, 'Fehlerfall')) {
    http_response_code(500);
    echo json_encode(['type' => 'error', 'error' => [
        'type'    => 'api_error',
        'message' => 'simulierter Serverfehler',
    ]]);
    exit;
}

// Zweite Betriebsart: Wortarten für bereits gespeicherte Vokabeln nachtragen.
// Erkennbar am Prompt; die Nummern kommen aus den übergebenen Zeilen zurück.
if (str_contains($instruction, 'Bestimme zu jeder Vokabel die Kategorie')) {
    $types = [];
    foreach (explode("\n", $instruction) as $line) {
        if (preg_match('/^(\d+)\t(.+?)\t/', $line, $m) === 1) {
            $begriff = $m[2];
            // Grobe Nachbildung des Modells: Satzzeichen entscheiden über
            // Frage und Aussage, sonst "to ..." Verb und "the ..." Substantiv.
            if (str_contains($begriff, '?')) {
                $type = 'frage';
            } elseif (str_contains($begriff, '!')) {
                $type = 'aussage';
            } elseif (str_starts_with($begriff, 'to ')) {
                $type = 'verb';
            } elseif (str_starts_with($begriff, 'the ')) {
                $type = 'substantiv';
            } else {
                $type = 'sonstiges';
            }
            $types[] = ['id' => (int) $m[1], 'word_type' => $type];
        }
    }
    echo json_encode([
        'id'            => 'msg_test_types',
        'type'          => 'message',
        'role'          => 'assistant',
        'model'         => $request['model'] ?? 'unbekannt',
        'content'       => [['type' => 'text', 'text' => json_encode(['types' => $types])]],
        'stop_reason'   => 'end_turn',
        'stop_sequence' => null,
        'usage'         => ['input_tokens' => 900, 'output_tokens' => 120],
    ]);
    exit;
}

// Antwort im Format, das das JSON-Schema der App verlangt.
$payload = [
    'title'   => 'Unit 4 - In the kitchen',
    'entries' => [
        ['foreign' => 'the spoon', 'native' => 'der Löffel', 'note' => null,    'word_type' => 'substantiv'],
        ['foreign' => 'the plate', 'native' => 'der Teller', 'note' => 'flach', 'word_type' => 'substantiv'],
        ['foreign' => 'to cook',   'native' => 'kochen',     'note' => null,    'word_type' => 'verb'],
        ['foreign' => 'Good night!', 'native' => 'Gute Nacht!', 'note' => null, 'word_type' => 'aussage'],
        ['foreign' => 'How are you?', 'native' => 'Wie geht es dir?', 'note' => null, 'word_type' => 'frage'],
        // Unvollständige Zeile: muss von der App verworfen werden.
        ['foreign' => '',          'native' => 'leer',       'note' => null,    'word_type' => 'sonstiges'],
    ],
];

echo json_encode([
    'id'            => 'msg_test_0001',
    'type'          => 'message',
    'role'          => 'assistant',
    'model'         => $request['model'] ?? 'unbekannt',
    'content'       => [['type' => 'text', 'text' => json_encode($payload, JSON_UNESCAPED_UNICODE)]],
    'stop_reason'   => 'end_turn',
    'stop_sequence' => null,
    'usage'         => [
        'input_tokens'  => 2400,
        'output_tokens' => 180,
    ],
]);
