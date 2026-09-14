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
 * Geprüft wird die ganze Kette: Key aus dem Keyvault, Aufbau des Requests
 * durch das SDK (Bildblock, Schema, Modell), Auswertung der Antwort und der
 * Eintrag im Kostenprotokoll.
 */

// Diese Datei gehört nicht ins Web - sie läuft ausschließlich auf der
// Kommandozeile.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/ai.php';
require_once __DIR__ . '/../lib/access.php';

/*
 * Die Lerneinheiten dieser Suite entstehen per SQL und muessen deshalb selbst
 * sagen, wie weit sie freigegeben sind - die Spalte faengt bei null an, und
 * fuer nicht Freigegebenes werden bewusst keine Saetze erzeugt. Hier geht es
 * um die Mechanik der Satzerzeugung, also steht alles offen. Was beim
 * Einlesen tatsaechlich eingetragen wird, entscheidet
 * initial_released_position(); das prueft tests/e2e.php.
 */

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

// Testkonto, damit das Kostenprotokoll eine gültige Zuordnung bekommt.
q("DELETE FROM users WHERE username = 'ai_test'");
q(
    'INSERT INTO users (username, display_name, password_hash, color) VALUES (?, ?, ?, ?)',
    ['ai_test', 'AI-Test', password_hash('x', PASSWORD_DEFAULT), '#4f7cff'],
);
$user   = q1("SELECT * FROM users WHERE username = 'ai_test'");
$before = (int) qv('SELECT COALESCE(MAX(id), 0) FROM ai_requests');

section('Aufruf');

$result = analyze_vocab_images($images, 'Englisch', $user);

ok('Titel wird übernommen', $result['title'] === 'Unit 4 - In the kitchen', (string) $result['title']);
ok('Fünf vollständige Vokabeln', count($result['entries']) === 5, (string) count($result['entries']));
ok('Unvollständige Zeile wird verworfen',
   !in_array('leer', array_column($result['entries'], 'native'), true));
ok('Vokabelpaar stimmt',
   $result['entries'][0]['foreign'] === 'the spoon' && $result['entries'][0]['native'] === 'der Löffel');
ok('Hinweis wird übernommen', $result['entries'][1]['note'] === 'flach');
ok('Fehlender Hinweis wird zu null', $result['entries'][0]['note'] === null);
ok('Kategorie wird übernommen', $result['entries'][0]['word_type'] === 'substantiv',
   (string) $result['entries'][0]['word_type']);
ok('Verb wird als Verb erkannt', $result['entries'][2]['word_type'] === 'verb');
ok('Grußformel wird als Aussage eingeordnet', $result['entries'][3]['word_type'] === 'aussage');
ok('Frage wird als Frage eingeordnet', $result['entries'][4]['word_type'] === 'frage');

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

ok('output_config trägt das JSON-Schema',
   ($body['output_config']['format']['type'] ?? '') === 'json_schema',
   json_encode($body['output_config'] ?? []));
ok('Schema verlangt foreign, native, note und word_type',
   ($body['output_config']['format']['schema']['properties']['entries']['items']['required'] ?? [])
   === ['foreign', 'native', 'note', 'word_type']);
ok('Schema gibt die dreizehn Kategorien als feste Auswahl vor',
   ($body['output_config']['format']['schema']['properties']['entries']['items']
        ['properties']['word_type']['enum'] ?? []) === word_type_keys());
ok('Aufwandsstufe aus den Einstellungen',
   ($body['output_config']['effort'] ?? '') === setting('vision_effort'));
ok('max_tokens ist gesetzt', ($body['max_tokens'] ?? 0) >= 4096, (string) ($body['max_tokens'] ?? 0));

section('Kostenprotokoll');

$log = q1('SELECT * FROM ai_requests WHERE id > ? ORDER BY id DESC LIMIT 1', [$before]);
ok('Eintrag wurde geschrieben', $log !== null);
ok('Status ok', ($log['status'] ?? '') === 'ok');
ok('Token werden übernommen',
   (int) $log['input_tokens'] === 2400 && (int) $log['output_tokens'] === 180);
ok('Anzahl Fotos stimmt', (int) $log['image_count'] === 2);
ok('Anzahl erkannter Vokabeln stimmt', (int) $log['entry_count'] === 5);
ok('Kind ist zugeordnet', (int) $log['user_id'] === (int) $user['id']);

// 2400 Eingabe- und 180 Ausgabe-Token zum hinterlegten Preis.
$expected = cost_for((string) $log['model'], 2400, 180);
ok('Kosten korrekt berechnet',
   abs((float) $log['cost_usd'] - $expected) < 0.0000005,
   sprintf('erwartet %.6f, gespeichert %.6f', $expected, (float) $log['cost_usd']));
ok('Dauer wurde gemessen', (int) $log['duration_ms'] > 0);

section('Kategorien nachtragen');

// Zwei Vokabeln ohne Wortart anlegen, wie sie vor dieser Funktion entstanden.
q('INSERT INTO languages (name, flag_emoji) VALUES (?, ?)',
  ['Testisch-AI', '']);
$wtLang = (int) db()->lastInsertId();
q('INSERT INTO units (language_id, title, released_position) VALUES (?, ?, ?)',
  [$wtLang, 'Alt', RELEASED_ALL]);
$wtUnit = (int) db()->lastInsertId();
$altbestand = [
    ['to run', 'rennen'],
    ['the house', 'das Haus'],
    ['Bonne nuit !', 'Gute Nacht!'],
    ['Comment tu t\'appelles ?', 'Wie heißt du?'],
];
foreach ($altbestand as $i => [$f, $n]) {
    q('INSERT INTO vocab (unit_id, term_foreign, term_native, position) VALUES (?, ?, ?, ?)',
      [$wtUnit, $f, $n, $i]);
}
$alt = qa('SELECT id, term_foreign, term_native FROM vocab WHERE unit_id = ? ORDER BY position',
          [$wtUnit]);

ok('Altbestand hat noch keine Kategorie',
   (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ? AND word_type IS NULL', [$wtUnit]) === 4);

$types = classify_word_types($alt, 'Englisch', $user);

ok('Für jede Vokabel kommt eine Kategorie zurück', count($types) === 4, (string) count($types));
ok('Verb erkannt', ($types[(int) $alt[0]['id']] ?? '') === 'verb');
ok('Substantiv erkannt', ($types[(int) $alt[1]['id']] ?? '') === 'substantiv');
ok('Grußformel als Aussage erkannt', ($types[(int) $alt[2]['id']] ?? '') === 'aussage');
ok('Frage als Frage erkannt', ($types[(int) $alt[3]['id']] ?? '') === 'frage');

// Das Modell darf keine fremden Zeilen anfassen.
$fremd = (int) qv('SELECT id FROM vocab WHERE unit_id <> ? LIMIT 1', [$wtUnit]);
ok('Nur angefragte Nummern werden übernommen', !array_key_exists($fremd, $types));

$logTypes = q1("SELECT * FROM ai_requests WHERE purpose = 'word_types' ORDER BY id DESC LIMIT 1");
ok('Eigener Verwendungszweck im Kostenprotokoll', $logTypes !== null);
ok('Kosten werden auch dafür berechnet', (float) $logTypes['cost_usd'] > 0);

q('DELETE FROM languages WHERE id = ?', [$wtLang]);

section('Lückensätze in Blöcken');

require_once __DIR__ . '/../lib/sentences.php';

// 25 Vokabeln: mehr als SENTENCE_BATCH, also zwei Aufrufe. Genau daran ist es
// auf dem Server gescheitert - ein einziger Aufruf über eine grosse Einheit
// lief so lange, dass die Datenbankverbindung dazwischen wegfiel.
q('INSERT INTO languages (name, flag_emoji, code) VALUES (?, ?, ?)',
  ['Blockisch', '', 'fr']);
$bLang = (int) db()->lastInsertId();
q('INSERT INTO units (language_id, title, released_position) VALUES (?, ?, ?)',
  [$bLang, 'Grosse Einheit', RELEASED_ALL]);
$bUnit = (int) db()->lastInsertId();
for ($i = 0; $i < 25; $i++) {
    q('INSERT INTO vocab (unit_id, term_foreign, term_native, word_type, position)
       VALUES (?, ?, ?, ?, ?)',
      [$bUnit, 'mot' . $i, 'Wort' . $i, 'substantiv', $i]);
}

ok('Mehr Vokabeln als ein Block fasst', 25 > SENTENCE_BATCH, 'Blockgrösse ' . SENTENCE_BATCH);

$vorher = (int) qv("SELECT COUNT(*) FROM ai_requests WHERE purpose = 'sentences'");
$res    = generate_sentences(
    q1('SELECT * FROM units WHERE id = ?', [$bUnit]), $user,
);

$aufrufe = (int) qv("SELECT COUNT(*) FROM ai_requests WHERE purpose = 'sentences'") - $vorher;
ok('Es wurden zwei Aufrufe daraus', $aufrufe === 2, "$aufrufe Aufrufe");
ok('Kein Block ist gescheitert', $res['failed'] === null, (string) $res['failed']);
ok('Alle 25 Vokabeln haben Sätze',
   vocab_without_sentences($bUnit) === 0, vocab_without_sentences($bUnit) . ' offen');
ok('Drei Sätze je Vokabel', $res['created'] === 75, (string) $res['created']);

// Ein zweiter Durchlauf darf nichts mehr tun.
$res2 = generate_sentences(q1('SELECT * FROM units WHERE id = ?', [$bUnit]), $user);
ok('Nichts mehr nachzutragen', $res2['created'] === 0);
ok('Und kein weiterer Aufruf',
   (int) qv("SELECT COUNT(*) FROM ai_requests WHERE purpose = 'sentences'") - $vorher === 2);

q('DELETE FROM languages WHERE id = ?', [$bLang]);
ok('Sätze verschwinden mit der Sprache',
   (int) qv('SELECT COUNT(*) FROM sentences s JOIN vocab v ON v.id = s.vocab_id
              WHERE v.unit_id = ?', [$bUnit]) === 0);

section('Fehlerfall: abgeschnittene Antwort');

// Wird die Antwort am Ausgabelimit gekappt, ist das JSON unlesbar. Ohne die
// Prüfung von stop_reason stünde im Protokoll "ok" mit null Einträgen - der
// bezahlte Aufruf sähe aus wie ein Erfolg.
q('INSERT INTO languages (name, flag_emoji) VALUES (?, ?)',
  ['Abgeschnitten', '']);
$cLang = (int) db()->lastInsertId();
q('INSERT INTO units (language_id, title, released_position) VALUES (?, ?, ?)',
  [$cLang, 'Zu lang', RELEASED_ALL]);
$cUnit = (int) db()->lastInsertId();
q('INSERT INTO vocab (unit_id, term_foreign, term_native, word_type, position)
   VALUES (?, ?, ?, ?, 0)', [$cUnit, 'mot', 'Wort', 'substantiv']);

$res = generate_sentences(q1('SELECT * FROM units WHERE id = ?', [$cUnit]), $user);
ok('Der Block meldet den Abbruch', $res['failed'] !== null, 'kein Fehler gemeldet');
ok('Nichts wurde gespeichert', $res['created'] === 0);

$cut = q1("SELECT * FROM ai_requests WHERE purpose = 'sentences' ORDER BY id DESC LIMIT 1");
ok('Im Protokoll steht error, nicht ok', ($cut['status'] ?? '') === 'error',
   (string) ($cut['status'] ?? ''));
ok('Und der Grund ist benannt',
   str_contains((string) $cut['error'], 'max_tokens'), (string) $cut['error']);
ok('Die verbrauchten Token sind trotzdem verbucht',
   (int) $cut['output_tokens'] === 16000 && (float) $cut['cost_usd'] > 0);

q('DELETE FROM languages WHERE id = ?', [$cLang]);

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
ok('Kein Schlüsselmaterial im Protokoll',
   !str_contains((string) $errLog['error'], 'sk-ant-api03-FAKE'),
   (string) $errLog['error']);

/*
 * Erst die Kostenzeilen, dann das Konto.
 *
 * In dieser Reihenfolge, weil der Fremdschluessel beim Loeschen des Kontos
 * user_id auf NULL setzt statt die Zeile mitzunehmen - danach waere nicht
 * mehr zu erkennen, welche Zeilen aus diesem Lauf stammen. Sie blieben
 * liegen und zaehlten weiter auf das Monatsbudget, bis die Suite an ihrem
 * eigenen Muell scheitert.
 */
q('DELETE FROM ai_requests WHERE user_id = ?', [(int) $user['id']]);
q('DELETE FROM users WHERE id = ?', [(int) $user['id']]);

ok('Der Lauf hinterlaesst keine Kostenzeilen',
   (int) qv('SELECT COUNT(*) FROM ai_requests WHERE user_label = ?',
            [(string) $user['display_name']]) === 0);

echo "\n" . str_repeat('-', 52) . "\n";
printf("%d bestanden, %d fehlgeschlagen\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
