<?php
declare(strict_types=1);

/**
 * Anthropic-Simulator fuer die Tests.
 *
 *   php -S 127.0.0.1:8125 tests/fake-anthropic.php
 *
 * Nimmt einen Messages-API-Aufruf entgegen, legt Header und Rumpf zur
 * Auswertung ab und antwortet im Format der echten API. So laesst sich der
 * gesamte Weg - Keyvault, SDK, Bildblock, Schema, Antwortauswertung,
 * Kostenprotokoll - pruefen, ohne die echte API zu belasten.
 */

// Wird bewusst per "php -S" ausgeliefert, gehoert aber auf keinen echten
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

// Die Tests loesen einen Serverfehler aus, indem sie als Sprachnamen
// "Fehlerfall" uebergeben - der steht dann in der Anweisung.
$instruction = '';
foreach ($request['messages'][0]['content'] ?? [] as $block) {
    if (($block['type'] ?? '') === 'text') {
        $instruction = (string) ($block['text'] ?? '');
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

// Antwort im Format, das das JSON-Schema der App verlangt.
$payload = [
    'title'   => 'Unit 4 - In the kitchen',
    'entries' => [
        ['foreign' => 'the spoon', 'native' => 'der Loeffel', 'note' => null],
        ['foreign' => 'the plate', 'native' => 'der Teller',  'note' => 'flach'],
        ['foreign' => 'to cook',   'native' => 'kochen',      'note' => null],
        // Unvollstaendige Zeile: muss von der App verworfen werden.
        ['foreign' => '',          'native' => 'leer',        'note' => null],
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
