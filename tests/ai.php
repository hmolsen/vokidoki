<?php
declare(strict_types=1);

/**
 * Test des kompletten Bilderkennungs-Pfads, ohne die echte API zu belasten.
 *
 *   php -S 127.0.0.1:8124 tests/fake-keyvault.php &
 *   php -S 127.0.0.1:8125 tests/fake-anthropic.php &
 *   php tests/ai.php
 *
 * Setzt in config.php voraus:
 *   'keyvault_url'       => 'http://127.0.0.1:8124/'
 *   'keyvault_token'     => 'test-token'
 *   'anthropic_base_url' => 'http://127.0.0.1:8125'
 *
 * Geprueft wird die ganze Kette: Key aus dem Keyvault, Aufbau des Requests
 * durch das SDK (Bildblock, Schema, Modell), Auswertung der Antwort und der
 * Eintrag im Kostenprotokoll.
 */

require_once __DIR__ . '/../lib/ai.php';

$passed = 0;
$failed = 0;

function ok(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  \u{2713} $label\n";
    } else {
        $failed++;
        echo "  \u{2717} $label" . ($detail !== '' ? " - $detail" : '') . "\n";
    }
}

function section(string $name): void
{
    echo "\n$name\n";
}

echo "Bilderkennung gegen " . cfg('anthropic_base_url') . "\n";

// Zwei kleine, echte JPEGs als Foto-Attrappen.
$images = [];
foreach ([0, 1] as $i) {
    $im = imagecreatetruecolor(60, 40);
    imagefilledrectangle($im, 0, 0, 60, 40, imagecolorallocate($im, 250, 250, 250));
    ob_start();
    imagejpeg($im, null, 90);
    $images[] = ['data' => base64_encode((string) ob_get_clean()), 'media_type' => 'image/jpeg'];
    imagedestroy($im);
}

// Testkonto, damit das Kostenprotokoll eine gueltige Zuordnung bekommt.
q("DELETE FROM users WHERE username = 'ai_test'");
q(
    'INSERT INTO users (username, display_name, password_hash, color) VALUES (?, ?, ?, ?)',
    ['ai_test', 'AI-Test', password_hash('x', PASSWORD_DEFAULT), '#4f7cff'],
);
$user   = q1("SELECT * FROM users WHERE username = 'ai_test'");
$before = (int) qv('SELECT COALESCE(MAX(id), 0) FROM ai_requests');

section('Aufruf');

$result = analyze_vocab_images($images, 'Englisch', $user);

ok('Titel wird uebernommen', $result['title'] === 'Unit 4 - In the kitchen', (string) $result['title']);
ok('Drei vollstaendige Vokabeln', count($result['entries']) === 3, (string) count($result['entries']));
ok('Unvollstaendige Zeile wird verworfen',
   !in_array('leer', array_column($result['entries'], 'native'), true));
ok('Vokabelpaar stimmt',
   $result['entries'][0]['foreign'] === 'the spoon' && $result['entries'][0]['native'] === 'der Loeffel');
ok('Hinweis wird uebernommen', $result['entries'][1]['note'] === 'flach');
ok('Fehlender Hinweis wird zu null', $result['entries'][0]['note'] === null);

section('Was beim Modell ankommt');

$record  = json_decode((string) file_get_contents(sys_get_temp_dir() . '/vt-fake-anthropic-last.json'), true);
$body    = $record['body'];
$headers = $record['headers'];

ok('Key aus dem Keyvault landet im Header',
   ($headers['x-api-key'] ?? '') === 'sk-ant-api03-FAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKE');
ok('Modell aus den Einstellungen', $body['model'] === setting('vision_model'), (string) $body['model']);

$content = $body['messages'][0]['content'];
ok('Beide Bilder sind dabei',
   count(array_filter($content, static fn ($b) => $b['type'] === 'image')) === 2);
ok('Bildblock hat media_type in Schreibweise der API',
   ($content[0]['source']['media_type'] ?? null) === 'image/jpeg', json_encode($content[0]['source'] ?? []));
ok('Bilddaten sind base64', ($content[0]['source']['type'] ?? '') === 'base64');
ok('Anweisung steht nach den Bildern', ($content[2]['type'] ?? '') === 'text');
ok('Sprachname steht in der Anweisung', str_contains($content[2]['text'] ?? '', 'Englisch'));

ok('output_config traegt das JSON-Schema',
   ($body['output_config']['format']['type'] ?? '') === 'json_schema',
   json_encode($body['output_config'] ?? []));
ok('Schema verlangt foreign, native und note',
   ($body['output_config']['format']['schema']['properties']['entries']['items']['required'] ?? [])
   === ['foreign', 'native', 'note']);
ok('Aufwandsstufe aus den Einstellungen',
   ($body['output_config']['effort'] ?? '') === setting('vision_effort'));
ok('max_tokens ist gesetzt', ($body['max_tokens'] ?? 0) >= 4096, (string) ($body['max_tokens'] ?? 0));

section('Kostenprotokoll');

$log = q1('SELECT * FROM ai_requests WHERE id > ? ORDER BY id DESC LIMIT 1', [$before]);
ok('Eintrag wurde geschrieben', $log !== null);
ok('Status ok', ($log['status'] ?? '') === 'ok');
ok('Token werden uebernommen',
   (int) $log['input_tokens'] === 2400 && (int) $log['output_tokens'] === 180);
ok('Anzahl Fotos stimmt', (int) $log['image_count'] === 2);
ok('Anzahl erkannter Vokabeln stimmt', (int) $log['entry_count'] === 3);
ok('Kind ist zugeordnet', (int) $log['user_id'] === (int) $user['id']);

// 2400 Eingabe- und 180 Ausgabe-Token zum hinterlegten Preis.
$expected = cost_for((string) $log['model'], 2400, 180);
ok('Kosten korrekt berechnet',
   abs((float) $log['cost_usd'] - $expected) < 0.0000005,
   sprintf('erwartet %.6f, gespeichert %.6f', $expected, (float) $log['cost_usd']));
ok('Dauer wurde gemessen', (int) $log['duration_ms'] > 0);

section('Fehlerfall: die API antwortet mit einem Fehler');

$countBefore = (int) qv('SELECT COUNT(*) FROM ai_requests');
$threw       = false;
try {
    // Der Simulator antwortet bei diesem Sprachnamen mit HTTP 500.
    analyze_vocab_images($images, 'Fehlerfall', $user);
} catch (Throwable $e) {
    $threw = true;
}
ok('Fehler wird nicht verschluckt', $threw);

$errLog = q1('SELECT * FROM ai_requests ORDER BY id DESC LIMIT 1');
ok('Fehlversuch wird protokolliert',
   (int) qv('SELECT COUNT(*) FROM ai_requests') > $countBefore);
ok('Status ist error', ($errLog['status'] ?? '') === 'error', (string) ($errLog['status'] ?? ''));
ok('Fehlversuch bucht keine Kosten', (float) $errLog['cost_usd'] === 0.0);
ok('Fehlertext wurde festgehalten', trim((string) $errLog['error']) !== '');
ok('Kein Schluesselmaterial im Protokoll',
   !str_contains((string) $errLog['error'], 'sk-ant-api03-FAKE'),
   (string) $errLog['error']);

q('DELETE FROM users WHERE id = ?', [(int) $user['id']]);

echo "\n" . str_repeat('-', 52) . "\n";
printf("%d bestanden, %d fehlgeschlagen\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
