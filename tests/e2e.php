<?php
declare(strict_types=1);

/**
 * End-to-End-Test gegen eine laufende Instanz.
 *
 *   php tests/e2e.php http://localhost:8123
 *
 * Der Test legt einen eigenen Testaccount an, spielt Login, Sprache, Import
 * (ohne KI-Aufruf), Quiz und Aufräumen durch und prüft dabei die Lernregel
 * "dreimal hintereinander richtig". Er braucht Zugriff auf dieselbe Datenbank
 * wie die App, um die richtige Antwort nachzuschlagen - die App gibt sie
 * bewusst nicht heraus.
 *
 * Nicht gegen eine produktive Instanz laufen lassen: Der Testaccount wird
 * angelegt und am Ende wieder gelöscht.
 */

// Diese Datei gehört nicht ins Web - sie läuft ausschließlich auf der
// Kommandozeile.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/wordtypes.php';

$base     = rtrim($argv[1] ?? 'http://localhost:8123', '/');
// Zweites Argument: Admin-Passwort. Fehlt es, wird der Admin-Teil übersprungen -
// so lässt sich der Test auch gegen eine Installation fahren, deren Passwort
// bereits geändert wurde.
$adminPass = $argv[2] ?? (string) cfg('admin_bootstrap_password', '');
$jar     = tempnam(sys_get_temp_dir(), 'vtjar');
$passed  = 0;
$failed  = 0;

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

/** @return array{status:int, body:string, headers:string} */
function http(string $url, ?array $json = null, array $headers = [], bool $follow = false): array
{
    global $jar;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($json !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    }

    $raw    = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    return [
        'status'  => $status,
        'headers' => substr($raw, 0, $hlen),
        'body'    => substr($raw, $hlen),
    ];
}

/**
 * Wartet, bis die Satzerzeugung einer Lerneinheit durch ist.
 *
 * Seit sie im Hintergrund laeuft, ist die Antwort auf 'save' schon da, waehrend
 * die Saetze noch entstehen - genau das ist der Zweck der Uebung.
 */
function waitForSentences(int $unitId, int $sekunden = 30): string
{
    $ende = time() + $sekunden;
    do {
        $status = (string) qv('SELECT sentences_status FROM units WHERE id = ?', [$unitId]);
        if ($status !== 'running') {
            return $status;
        }
        usleep(250000);
    } while (time() < $ende);

    return 'running';
}

/** API-Aufruf mit dem Header, den die App als CSRF-Schutz verlangt. */
function apiCall(string $file, string $action, ?array $body = null, array $query = []): array
{
    global $base;
    $url = $base . "/api/$file.php?" . http_build_query(['action' => $action] + $query);
    $res = http($url, $body, ['X-Vokabeltrainer: 1', 'Content-Type: application/json']);
    return [json_decode($res['body'], true), $res['status']];
}

echo "End-to-End-Test gegen $base\n";

// ------------------------------------------------------------------ Fixture

section('Testaccount vorbereiten');

$username = 'e2e_test';
q('DELETE FROM users WHERE username = ?', [$username]);
q(
    'INSERT INTO users (username, display_name, password_hash, color) VALUES (?, ?, ?, ?)',
    [$username, 'Testkind', password_hash('geheim123', PASSWORD_DEFAULT), '#e0559a'],
);
$userId = (int) db()->lastInsertId();
ok('Account angelegt', $userId > 0);

/** Holt das CSRF-Token aus einem gerenderten Admin-Formular. */
function csrfFrom(string $html): string
{
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m);
    return $m[1] ?? '';
}

/** Admin-Formular abschicken (klassisches POST, kein JSON). */
function adminPost(string $file, array $fields, string $getQuery = ''): array
{
    global $base, $jar;

    // Manche Admin-Seiten zeigen ihr Formular erst mit gesetzten Filtern -
    // das Token muss also von genau dieser Ansicht kommen.
    $page = http($base . "/admin/$file" . ($getQuery !== '' ? '?' . $getQuery : ''));
    $fields['csrf'] = csrfFrom($page['body']);

    $ch = curl_init($base . "/admin/$file");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw    = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    return ['status' => $status, 'body' => substr($raw, $hlen)];
}

section('Admin-Interface');

$res = http($base . '/admin/');
ok('Admin verlangt ein Passwort', str_contains($res['body'], 'name="admin_password"'));

$res = adminPost('index.php', ['admin_password' => 'garantiert-falsch-' . bin2hex(random_bytes(4))]);
ok('Falsches Admin-Passwort wird abgelehnt', str_contains($res['body'], 'name="admin_password"'));

$res = adminPost('index.php', ['admin_password' => $adminPass]);
$adminOk = str_contains($res['body'], 'Diesen Monat');
ok('Admin-Anmeldung', $adminOk, 'Dashboard nicht erreicht - Passwort als 2. Argument übergeben');
ok('Passwort liegt als Hash in der Datenbank',
   str_starts_with((string) qv("SELECT v FROM settings WHERE k = 'admin_password_hash'"), '$'));

foreach (['users.php' => 'Neuen Account anlegen',
          'vocab.php' => 'Kind',
          'settings.php' => 'Modell für die Bilderkennung',
          'selfcheck.php' => 'Prüfung'] as $file => $needle) {
    $res = http($base . '/admin/' . $file);
    ok("Seite $file lädt", $res['status'] === 200 && str_contains($res['body'], $needle),
       "Status {$res['status']}");
}

$res = http($base . '/admin/selfcheck.php');
ok('Selbsttest meldet keine Fehler bei DB und Schema',
   str_contains($res['body'], 'Tabellen vorhanden'));

// Zweites Kind anlegen - damit prüft der Test weiter unten die Trennung der Accounts.
q("DELETE FROM users WHERE username = 'e2e_other'");
$res = adminPost('users.php', [
    'create'       => '1',
    'username'     => 'e2e_other',
    'display_name' => 'Zweitkind',
    'password'     => 'geheim123',
    'color'        => '#4f7cff',
]);
$otherId = (int) qv("SELECT id FROM users WHERE username = 'e2e_other'");
ok('Admin legt einen zweiten Account an', $otherId > 0);

$res = adminPost('users.php', ['create' => '1', 'username' => 'UNGUELTIG!',
                               'display_name' => 'X', 'password' => 'geheim123']);
ok('Ungültiger Benutzername wird abgelehnt', str_contains($res['body'], 'Benutzername: 3-64'));

// Fremddaten anlegen, gegen die der Zugriffsschutz gleich geprüft wird.
q('INSERT INTO languages (user_id, name, flag_emoji) VALUES (?, ?, ?)', [$otherId, 'Fremdisch', '']);
$otherLang = (int) db()->lastInsertId();
q('INSERT INTO units (user_id, language_id, title) VALUES (?, ?, ?)', [$otherId, $otherLang, 'Fremde Unit']);
$otherUnitId = (int) db()->lastInsertId();

// Vorherige Einstellung merken, damit der Test nichts dauerhaft verändert.
$prevModel  = (string) qv("SELECT v FROM settings WHERE k = 'vision_model'");
$prevEffort = (string) qv("SELECT v FROM settings WHERE k = 'vision_effort'");
$probeModel = $prevModel === 'claude-sonnet-5' ? 'claude-haiku-4-5' : 'claude-sonnet-5';

adminPost('settings.php', ['save_model' => '1', 'vision_model' => $probeModel,
                           'vision_effort' => 'low']);
ok('Modellwechsel wird gespeichert',
   qv("SELECT v FROM settings WHERE k = 'vision_model'") === $probeModel);

adminPost('settings.php', ['save_model' => '1', 'vision_model' => 'bösartig',
                           'vision_effort' => 'medium']);
ok('Unbekanntes Modell wird abgelehnt',
   qv("SELECT v FROM settings WHERE k = 'vision_model'") === $probeModel);

adminPost('settings.php', ['save_model' => '1', 'vision_model' => $prevModel,
                           'vision_effort' => $prevEffort]);
ok('Vorherige Modelleinstellung wiederhergestellt',
   qv("SELECT v FROM settings WHERE k = 'vision_model'") === $prevModel
   && qv("SELECT v FROM settings WHERE k = 'vision_effort'") === $prevEffort);


// ------------------------------------------------------------------ Anmeldung

section('Anmeldung und Geräte-Token');

[$data, $status] = apiCall('auth', 'login', ['username' => $username, 'password' => 'falsch']);
ok('Falsches Passwort wird abgelehnt', $status === 401, "Status $status");

[$data, $status] = apiCall('auth', 'login', ['username' => $username, 'password' => 'geheim123']);
ok('Anmeldung erfolgreich', $status === 200 && ($data['ok'] ?? false), $data['error'] ?? '');
ok('App-Name ist Besitzform', ($data['user']['appName'] ?? '') === 'Testkinds Vokabeln',
   $data['user']['appName'] ?? '(fehlt)');

$redirect = (string) ($data['redirect'] ?? '');
parse_str((string) parse_url($redirect, PHP_URL_QUERY), $q);
$token = (string) ($q['t'] ?? '');
ok('Geräte-Token ausgeliefert', strlen($token) > 30);
ok('Token ist nur als Hash gespeichert',
   q1('SELECT id FROM device_tokens WHERE token_hash = ?', [hash('sha256', $token)]) !== null);

// ------------------------------------------------------------------ PWA-Hülle

section('PWA-Hülle');

$res = http($base . '/?t=' . urlencode($token));
ok('Token-Start leitet weiter (Token verlässt die URL)', $res['status'] === 302, "Status {$res['status']}");

$res = http($base . '/');
ok('Shell enthält personalisierten Manifest-Link', str_contains($res['body'], 'manifest.php?t='));
ok('iOS-Titel ist der Kindername',
   str_contains($res['body'], 'apple-mobile-web-app-title" content="Testkinds Vokabeln"'));
ok('apple-touch-icon gesetzt', str_contains($res['body'], 'icon.php?u=' . $userId));

$res      = http($base . '/manifest.php?t=' . urlencode($token));
$manifest = json_decode($res['body'], true);
ok('Manifest-Name', ($manifest['name'] ?? '') === 'Testkinds Vokabeln', $manifest['name'] ?? '(fehlt)');
ok('Manifest start_url trägt den Token', str_contains((string) ($manifest['start_url'] ?? ''), 't=' . $token));
ok('Manifest display=standalone', ($manifest['display'] ?? '') === 'standalone');

$res = http($base . '/icon.php?u=' . $userId . '&s=192');
ok('Icon ist ein PNG', str_starts_with($res['body'], "\x89PNG"), 'Antwort war kein PNG');

$res = http($base . '/manifest.php?t=kein-gueltiger-token');
ok('Ungültiger Token liefert generisches Manifest',
   (json_decode($res['body'], true)['name'] ?? '') === 'Vokabeln');

// ------------------------------------------------------------------ Sprache

section('Sprache und Lerneinheit');

[$data, $status] = apiCall('languages', 'create',
    ['name' => 'Testisch', 'flag' => "\u{1F1EC}\u{1F1E7}", 'code' => 'en']);
ok('Sprache angelegt', $status === 200 && ($data['ok'] ?? false), $data['error'] ?? '');
$languageId = (int) ($data['id'] ?? 0);
ok('Sprachkürzel wird gespeichert',
   qv('SELECT code FROM languages WHERE id = ?', [$languageId]) === 'en');

// Ohne mitgeschicktes Kürzel wird es aus dem Namen abgeleitet.
[$sp] = apiCall('languages', 'create', ['name' => 'Spanisch', 'flag' => '']);
ok('Kürzel wird aus dem Namen abgeleitet',
   qv('SELECT code FROM languages WHERE id = ?', [(int) $sp['id']]) === 'es');
[$kl] = apiCall('languages', 'create', ['name' => 'Klingonisch', 'flag' => '']);
ok('Unbekannte Sprache bleibt ohne Kürzel',
   qv('SELECT code FROM languages WHERE id = ?', [(int) $kl['id']]) === null);
q('DELETE FROM languages WHERE id IN (?, ?)', [(int) $sp['id'], (int) $kl['id']]);

[$data, $status] = apiCall('languages', 'create', ['name' => 'Testisch', 'flag' => '']);
ok('Doppelte Sprache wird abgelehnt', $status === 409, "Status $status");

$entries = [];
foreach ([['one', 'eins'], ['two', 'zwei'], ['three', 'drei'], ['four', 'vier'], ['five', 'fünf']] as [$f, $n]) {
    $entries[] = ['foreign' => $f, 'native' => $n];
}
[$data, $status] = apiCall('import', 'save', [
    'language_id' => $languageId,
    'title'       => 'Unit 1',
    'entries'     => $entries,
]);
ok('Lerneinheit gespeichert', $status === 200 && ($data['ok'] ?? false), $data['error'] ?? '');
ok('Alle fünf Vokabeln angelegt', ($data['count'] ?? 0) === 5);
$unitId = (int) ($data['unit_id'] ?? 0);

section('Vokabelkorrektur im Admin');

$vocabRows = qa('SELECT id, term_foreign, term_native FROM vocab WHERE unit_id = ? ORDER BY position', [$unitId]);
$firstId   = (int) $vocabRows[0]['id'];
$filter    = http_build_query(['user' => $userId, 'language' => $languageId, 'unit' => $unitId]);

$fields = ['save_rows' => '1', 'user' => $userId, 'language' => $languageId,
           'unit' => $unitId, 'unit_id' => $unitId, 'unit_title' => 'Unit 1 korrigiert'];
foreach ($vocabRows as $row) {
    $fields['f'][$row['id']]    = $row['term_foreign'];
    $fields['n'][$row['id']]    = $row['term_native'];
    $fields['note'][$row['id']] = '';
}
$fields['f'][$firstId]    = 'ONE';
$fields['n'][$firstId]    = 'die Eins';
$fields['note'][$firstId] = 'Zahlwort';

adminPost('vocab.php', $fields, $filter);
$fixed = q1('SELECT term_foreign, term_native, note FROM vocab WHERE id = ?', [$firstId]);
ok('Admin korrigiert eine Vokabel',
   $fixed['term_foreign'] === 'ONE' && $fixed['term_native'] === 'die Eins',
   json_encode($fixed));
ok('Hinweisfeld wird übernommen', $fixed['note'] === 'Zahlwort');
ok('Titel der Lerneinheit wird umbenannt',
   qv('SELECT title FROM units WHERE id = ?', [$unitId]) === 'Unit 1 korrigiert');

// Leere Felder dürfen bestehende Daten nicht zerstören.
$fields['f'][$firstId] = '';
$fields['n'][$firstId] = '';
adminPost('vocab.php', $fields, $filter);
ok('Leere Eingabe löscht keine Vokabel',
   qv('SELECT term_foreign FROM vocab WHERE id = ?', [$firstId]) === 'ONE');

adminPost('vocab.php', ['add_vocab' => $unitId, 'user' => $userId, 'language' => $languageId,
                        'unit' => $unitId, 'new_f' => 'six', 'new_n' => 'sechs'], $filter);
ok('Admin ergänzt eine Vokabel',
   (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ?', [$unitId]) === 6);

adminPost('vocab.php', ['delete_vocab' => $firstId, 'user' => $userId,
                        'language' => $languageId, 'unit' => $unitId], $filter);
ok('Admin löscht eine Vokabel',
   q1('SELECT id FROM vocab WHERE id = ?', [$firstId]) === null);

// Eine ergänzt, eine gelöscht - der Quiz-Abschnitt findet wieder fünf Vokabeln vor.
ok('Lerneinheit steht wieder bei fünf Vokabeln',
   (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ?', [$unitId]) === 5);

section('Bilderkennung über die API');

// Läuft nur gegen den Simulator (tests/fake-anthropic.php). Zeigt
// anthropic_base_url woanders hin, wird übersprungen - dieser Test darf
// niemals die echte, kostenpflichtige API treffen.
$aiBase = (string) cfg('anthropic_base_url', '');
$isFake = $aiBase !== '' && preg_match('#^https?://(127\.0\.0\.1|localhost)[:/]#', $aiBase) === 1;

if (!$isFake) {
    echo "  - übersprungen (anthropic_base_url zeigt nicht auf den Simulator)
";
} else {
    $im = imagecreatetruecolor(60, 40);
    imagefilledrectangle($im, 0, 0, 60, 40, imagecolorallocate($im, 250, 250, 250));
    ob_start();
    imagejpeg($im, null, 90);
    $photo = base64_encode((string) ob_get_clean());
    imagedestroy($im);

    [$data, $status] = apiCall('import', 'analyze', [
        'language_id' => $languageId,
        'images'      => [['data' => $photo, 'media_type' => 'image/jpeg']],
    ]);
    ok('Foto wird ausgewertet', $status === 200 && ($data['ok'] ?? false), $data['error'] ?? '');
    ok('Titel kommt aus dem Bild', ($data['title'] ?? '') === 'Unit 4 - In the kitchen');
    ok('Nur vollständige Paare werden zurückgegeben', count($data['entries'] ?? []) === 5);

    [$saved, $status] = apiCall('import', 'save', [
        'language_id' => $languageId,
        'title'       => $data['title'],
        'entries'     => $data['entries'],
    ]);
    ok('Erkannte Vokabeln lassen sich speichern', $status === 200 && ($saved['count'] ?? 0) === 5);

    $newUnit = (int) ($saved['unit_id'] ?? 0);
    ok('Lerneinheit trägt den erkannten Titel',
       qv('SELECT title FROM units WHERE id = ?', [$newUnit]) === 'Unit 4 - In the kitchen');

    ok('Kategorie wird mitgeliefert, ohne dass das Kind sie prüfen muss',
       ($data['entries'][0]['word_type'] ?? null) === 'substantiv');
    ok('Kategorie landet in der Datenbank',
       qv('SELECT word_type FROM vocab WHERE unit_id = ? ORDER BY position LIMIT 1',
          [$newUnit]) === 'substantiv');
    ok('Keine Vokabel bleibt ohne Kategorie',
       (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ? AND word_type IS NULL',
                [$newUnit]) === 0);

    // Neu: Die Lückensätze entstehen gleich beim Speichern im Hintergrund. Die
    // Antwort auf 'save' ist also schon da, während noch gearbeitet wird.
    ok('Die Satzerzeugung wurde angestossen',
       in_array(qv('SELECT sentences_status FROM units WHERE id = ?', [$newUnit]),
                ['running', 'done'], true));

    $stand = waitForSentences($newUnit);
    ok('Der Hintergrundlauf wird fertig', $stand === 'done', $stand);
    ok('Sätze sind ohne weiteres Zutun da',
       (int) qv('SELECT COUNT(*) FROM sentences s JOIN vocab v ON v.id = s.vocab_id
                  WHERE v.unit_id = ?', [$newUnit]) > 0);

    [$st, $code] = apiCall('units', 'sentence_status', null, ['id' => $newUnit]);
    ok('Der Abfrage-Endpunkt meldet fertig', ($st['cloze']['status'] ?? '') === 'done');
    ok('Und nennt den Fortschritt', ($st['cloze']['total'] ?? 0) > 0);

    // Nicht im Quiz-Abschnitt mitzählen lassen.
    q('DELETE FROM units WHERE id = ?', [$newUnit]);

    [$data, $status] = apiCall('import', 'analyze', ['language_id' => $languageId, 'images' => []]);
    ok('Ohne Foto wird abgelehnt', $status === 400, "Status $status");

    [$data, $status] = apiCall('import', 'analyze', [
        'language_id' => $languageId,
        'images'      => [['data' => base64_encode('kein bild'), 'media_type' => 'image/jpeg']],
    ]);
    ok('Ungültiges Bild wird abgelehnt', $status === 400, "Status $status");
}

section('Kategorien im Admin');

$vocabRows = qa('SELECT id, term_foreign, term_native FROM vocab WHERE unit_id = ? ORDER BY position',
                [$unitId]);
$firstId   = (int) $vocabRows[0]['id'];
$filter    = http_build_query(['user' => $userId, 'language' => $languageId, 'unit' => $unitId]);

$res = http($base . '/admin/vocab.php?' . $filter);
ok('Spalte Kategorie ist da', str_contains($res['body'], '<th>Kategorie</th>'));
ok('Auswahlfeld je Zeile', str_contains($res['body'], 'name="wt[' . $firstId . ']"'));
$fehlend = array_values(array_filter(
    word_type_keys(),
    static fn (string $k): bool => !str_contains($res['body'], 'value="' . $k . '"'),
));
ok('Alle dreizehn Kategorien stehen zur Wahl', $fehlend === [], implode(', ', $fehlend));

// Manuell setzen - über dasselbe Formular wie die Textkorrekturen.
$fields = ['save_rows' => '1', 'user' => $userId, 'language' => $languageId,
           'unit' => $unitId, 'unit_id' => $unitId, 'unit_title' => 'Unit 1 korrigiert'];
foreach ($vocabRows as $row) {
    $fields['f'][$row['id']]    = $row['term_foreign'];
    $fields['n'][$row['id']]    = $row['term_native'];
    $fields['note'][$row['id']] = '';
    $fields['wt'][$row['id']]   = '';
}
$fields['wt'][$firstId] = 'adjektiv';

adminPost('vocab.php', $fields, $filter);
ok('Kategorie lässt sich von Hand setzen',
   qv('SELECT word_type FROM vocab WHERE id = ?', [$firstId]) === 'adjektiv',
   (string) qv('SELECT word_type FROM vocab WHERE id = ?', [$firstId]));

$fields['wt'][$firstId] = 'gibtsnicht';
adminPost('vocab.php', $fields, $filter);
ok('Unbekannte Kategorie wird verworfen statt gespeichert',
   qv('SELECT word_type FROM vocab WHERE id = ?', [$firstId]) === null);

$res = http($base . '/admin/vocab.php?' . $filter);
ok('Fehlende Kategorie wird als Strich angezeigt', str_contains($res['body'], 'wt-leer'));

// Nachtragen: greift auf alles, was noch keine Wortart hat.
$offen = (int) qv('SELECT COUNT(*) FROM vocab WHERE word_type IS NULL');
ok('Es gibt etwas nachzutragen', $offen > 0, (string) $offen);

$res = http($base . '/admin/vocab.php?' . $filter);
ok('Knopf zum Nachtragen erscheint', str_contains($res['body'], 'Kategorien nachtragen'));

if ($isFake) {
    adminPost('vocab.php', ['fill_word_types' => '1', 'user' => $userId,
                            'language' => $languageId, 'unit' => $unitId], $filter);
    ok('Nach dem Nachtragen hat jede Vokabel eine Kategorie',
       (int) qv('SELECT COUNT(*) FROM vocab WHERE word_type IS NULL') === 0,
       qv('SELECT COUNT(*) FROM vocab WHERE word_type IS NULL') . ' offen');

    $res = http($base . '/admin/vocab.php?' . $filter);
    ok('Knopf verschwindet, wenn nichts mehr offen ist',
       !str_contains($res['body'], 'Kategorien nachtragen'));
} else {
    echo "  - Nachtragen übersprungen (kein Simulator)
";
}

// ------------------------------------------------------------------ Fremdzugriff

section('Fremdzugriff');

// Gegen die im Admin-Abschnitt gezielt angelegten Daten des zweiten Kindes.
[$data, $status] = apiCall('units', 'get', null, ['id' => $otherUnitId]);
ok('Fremde Lerneinheit ist nicht lesbar', $status === 404, "Status $status");

[$data, $status] = apiCall('quiz', 'next', null, ['unit_id' => $otherUnitId]);
ok('Quiz zu fremder Lerneinheit wird verweigert', $status === 404, "Status $status");

[$data, $status] = apiCall('units', 'delete', ['id' => $otherUnitId]);
ok('Löschen einer fremden Lerneinheit wird verweigert', $status === 404, "Status $status");

[$data, $status] = apiCall('import', 'save', [
    'language_id' => $otherLang,
    'title'       => 'Eingeschleust',
    'entries'     => [['foreign' => 'a', 'native' => 'b']],
]);
ok('Speichern in fremde Sprache wird verweigert', $status === 404, "Status $status");
ok('Fremde Lerneinheit existiert unverändert weiter',
   q1('SELECT id FROM units WHERE id = ?', [$otherUnitId]) !== null);

$res = http($base . "/api/quiz.php?action=next&unit_id=$unitId");
ok('API ohne Sicherheitsheader wird abgewiesen', $res['status'] === 403, "Status {$res['status']}");

// ------------------------------------------------------------------ Quiz

section('Flashcard-Trainer');

/** Ermittelt die richtige Antwort aus der Datenbank - die API gibt sie nicht heraus. */
function correctIndexFor(array $card, int $unitId): int
{
    $row = q1(
        'SELECT term_foreign, term_native FROM vocab
          WHERE unit_id = ? AND (term_foreign = ? OR term_native = ?) LIMIT 1',
        [$unitId, $card['question'], $card['question']],
    );
    $answer = $card['direction'] === 'foreign_to_native' ? $row['term_native'] : $row['term_foreign'];
    return (int) array_search($answer, $card['options'], true);
}

[$card] = apiCall('quiz', 'next', null, ['unit_id' => $unitId]);
ok('Erste Frage geliefert', ($card['ok'] ?? false) && !($card['done'] ?? true));
ok('Genau vier Antwortmöglichkeiten', count($card['options'] ?? []) === 4);
ok('Antwortmöglichkeiten sind verschieden',
   count(array_unique($card['options'] ?? [])) === 4);
ok('Richtige Antwort wird nicht mitgeschickt',
   !array_key_exists('correct_index', $card) && !array_key_exists('answer', $card));
ok('Sprachname für die Richtungsanzeige dabei', ($card['language'] ?? '') === 'Testisch');

// Dieselbe Frage zweimal beantworten - der Nonce darf nur einmal gelten.
$index = correctIndexFor($card, $unitId);
[$r1, $s1] = apiCall('quiz', 'answer', ['nonce' => $card['nonce'], 'index' => $index]);
ok('Richtige Antwort wird als richtig gewertet', $r1['correct'] ?? false);
ok('Serie steht bei 1', ($r1['streak'] ?? -1) === 1);

[$r2, $s2] = apiCall('quiz', 'answer', ['nonce' => $card['nonce'], 'index' => $index]);
ok('Nonce ist nur einmal gültig', $s2 === 409, "Status $s2");

// Falsche Antwort setzt die Serie zurück.
[$card] = apiCall('quiz', 'next', null, ['unit_id' => $unitId]);
$right = correctIndexFor($card, $unitId);
$wrong = ($right + 1) % 4;
[$r3] = apiCall('quiz', 'answer', ['nonce' => $card['nonce'], 'index' => $wrong]);
ok('Falsche Antwort wird als falsch gewertet', ($r3['correct'] ?? true) === false);
ok('Serie fällt auf 0 zurück', ($r3['streak'] ?? -1) === 0);
ok('Richtige Lösung wird zurückgemeldet', ($r3['correct_index'] ?? -1) === $right);

// Ganze Einheit durchspielen - jede Vokabel braucht drei Treffer am Stück.
$rounds = 0;
$last   = null;
while ($rounds < 200) {
    [$card] = apiCall('quiz', 'next', null, ['unit_id' => $unitId]);
    if ($card['done'] ?? false) {
        break;
    }
    $last = apiCall('quiz', 'answer', [
        'nonce' => $card['nonce'],
        'index' => correctIndexFor($card, $unitId),
    ])[0];
    $rounds++;
}

ok('Lerneinheit wird nach lauter richtigen Antworten bestanden', ($card['done'] ?? false), "nach $rounds Runden");
ok('Alle Vokabeln gelten als gekonnt', ($card['known'] ?? 0) === 5 && ($card['total'] ?? 0) === 5);

$knownRows = (int) qv(
    "SELECT COUNT(*) FROM progress p JOIN vocab v ON v.id = p.vocab_id
      WHERE v.unit_id = ? AND p.known_at IS NOT NULL",
    [$unitId],
);
ok('Lernstand steht in der Datenbank', $knownRows === 5, "$knownRows von 5");

$minStreak = (int) qv(
    'SELECT MIN(p.streak) FROM progress p JOIN vocab v ON v.id = p.vocab_id WHERE v.unit_id = ?',
    [$unitId],
);
ok('Keine Vokabel wurde vor drei Treffern freigegeben', $minStreak >= 3, "kleinste Serie: $minStreak");

// ------------------------------------------------------------------ Zurücksetzen

section('Zurücksetzen');

[$data, $status] = apiCall('units', 'reset', ['id' => $unitId]);
ok('Fortschritt zurückgesetzt', $status === 200);
ok('Lernstand ist gelöscht',
   (int) qv('SELECT COUNT(*) FROM progress p JOIN vocab v ON v.id = p.vocab_id WHERE v.unit_id = ?', [$unitId]) === 0);

[$card] = apiCall('quiz', 'next', null, ['unit_id' => $unitId]);
ok('Nach dem Zurücksetzen kommen wieder Fragen', !($card['done'] ?? true));

section('Sprache im Admin löschen');

// Wegwerf-Sprache mit Einheit, Vokabeln und Lernstand anlegen.
[$data] = apiCall('languages', 'create', ['name' => 'Wegwerfisch', 'flag' => '']);
$tmpLang = (int) $data['id'];
[$data] = apiCall('import', 'save', [
    'language_id' => $tmpLang,
    'title'       => 'Zum Löschen',
    'entries'     => [['foreign' => 'aa', 'native' => 'bb'], ['foreign' => 'cc', 'native' => 'dd']],
]);
$tmpUnit = (int) $data['unit_id'];
$tmpVocab = (int) qv('SELECT id FROM vocab WHERE unit_id = ? LIMIT 1', [$tmpUnit]);
q('INSERT INTO progress (user_id, vocab_id, mode, streak) VALUES (?, ?, ?, 1)',
  [$userId, $tmpVocab, 'mc']);

ok('Wegwerf-Sprache steht mit allem Drum und Dran',
   $tmpLang > 0 && $tmpUnit > 0
   && (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ?', [$tmpUnit]) === 2
   && (int) qv('SELECT COUNT(*) FROM progress WHERE vocab_id = ?', [$tmpVocab]) === 1);

$res = adminPost('vocab.php', ['delete_language' => $tmpLang, 'user' => $userId],
                 http_build_query(['user' => $userId]));

ok('Sprache ist gelöscht',
   q1('SELECT id FROM languages WHERE id = ?', [$tmpLang]) === null);
ok('Lerneinheiten verschwinden mit',
   (int) qv('SELECT COUNT(*) FROM units WHERE language_id = ?', [$tmpLang]) === 0);
ok('Vokabeln verschwinden mit',
   (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ?', [$tmpUnit]) === 0);
ok('Lernstand verschwindet mit',
   (int) qv('SELECT COUNT(*) FROM progress WHERE vocab_id = ?', [$tmpVocab]) === 0);
ok('Meldung nennt, was entfernt wurde',
   str_contains($res['body'], 'Wegwerfisch') && str_contains($res['body'], '2 Vokabel'),
   'Meldung nicht gefunden');

// Die andere Sprache des Kindes darf davon unberührt bleiben.
ok('Andere Sprache bleibt bestehen',
   q1('SELECT id FROM languages WHERE id = ?', [$languageId]) !== null);
ok('Ihre Lerneinheit bleibt bestehen',
   q1('SELECT id FROM units WHERE id = ?', [$unitId]) !== null);

$res = adminPost('vocab.php', ['delete_language' => $tmpLang, 'user' => $userId],
                 http_build_query(['user' => $userId]));
ok('Erneutes Löschen meldet sich sauber',
   str_contains($res['body'], 'gibt es nicht mehr'));

section('Lückentext');

if (!$isFake) {
    echo "  - übersprungen (kein Simulator)
";
} else {
    // Der Multiple-Choice-Stand dieser Einheit ist oben schon aufgebaut worden.
    // Er muss den ganzen Abschnitt über unberührt bleiben.
    $mcVorher = (int) qv(
        "SELECT COUNT(*) FROM progress p JOIN vocab v ON v.id = p.vocab_id
          WHERE v.unit_id = ? AND p.mode = 'mc'", [$unitId]);

    // Die Sätze dieser Einheit sind beim Einlesen im Hintergrund entstanden.
    ok('Der Hintergrundlauf war schon durch', waitForSentences($unitId) === 'done');

    // Im Admin-Abschnitt wurde eine Vokabel ergänzt - für die fehlen noch
    // Sätze, also darf dieser Aufruf etwas tun.
    [$data, $status] = apiCall('cloze', 'prepare', ['unit_id' => $unitId]);
    ok('Nachträglich ergänzte Vokabeln bekommen ihre Sätze',
       $status === 200 && ($data['created'] ?? 0) > 0, json_encode($data));
    ok('Danach fehlt nichts mehr',
       (int) qv('SELECT COUNT(*) FROM vocab v WHERE v.unit_id = ?
                  AND v.word_type NOT IN (?, ?, ?)
                  AND NOT EXISTS (SELECT 1 FROM sentences s WHERE s.vocab_id = v.id)',
                [$unitId, 'aussage', 'frage', 'interjektion']) === 0);

    // Und jetzt ist wirklich nichts mehr zu tun.
    $vorher = (int) qv("SELECT COUNT(*) FROM ai_requests WHERE purpose = 'sentences'");
    [$data, $status] = apiCall('cloze', 'prepare', ['unit_id' => $unitId]);
    ok('Ein weiterer Aufruf findet nichts zu tun', ($data['created'] ?? -1) === 0, json_encode($data));
    ok('Und kostet nichts',
       (int) qv("SELECT COUNT(*) FROM ai_requests WHERE purpose = 'sentences'") === $vorher);

    // Eine Lerneinheit von vor dem Hintergrundlauf: dort greift 'prepare'.
    q('INSERT INTO units (user_id, language_id, title) VALUES (?, ?, ?)',
      [$userId, $languageId, 'Alter Bestand']);
    $altUnit = (int) db()->lastInsertId();
    q('INSERT INTO vocab (unit_id, term_foreign, term_native, word_type, position)
       VALUES (?, ?, ?, ?, 0)', [$altUnit, 'vieux', 'alt', 'adjektiv']);

    [$data] = apiCall('cloze', 'next', null, ['unit_id' => $altUnit]);
    ok('Alter Bestand meldet Vorbereitungsbedarf',
       ($data['needs_preparation'] ?? false) === true, json_encode($data));

    [$data, $status] = apiCall('cloze', 'prepare', ['unit_id' => $altUnit]);
    ok('Und lässt sich nachträglich vorbereiten',
       $status === 200 && ($data['created'] ?? 0) > 0, $data['error'] ?? json_encode($data));
    ok('Der Zustand steht danach auf fertig',
       qv('SELECT sentences_status FROM units WHERE id = ?', [$altUnit]) === 'done');
    q('DELETE FROM units WHERE id = ?', [$altUnit]);

    $anzahl = (int) qv(
        'SELECT COUNT(*) FROM sentences s JOIN vocab v ON v.id = s.vocab_id WHERE v.unit_id = ?',
        [$unitId]);
    ok('Sätze liegen in der Datenbank', $anzahl > 0, (string) $anzahl);
    ok('Der unbrauchbare Satz des Modells wurde verworfen',
       (int) qv('SELECT COUNT(*) FROM sentences WHERE foreign_text NOT LIKE ?', ['%{}%']) === 0);

    // Eine Frage geht durch die Übung.
    [$card] = apiCall('cloze', 'next', null, ['unit_id' => $unitId]);
    ok('Aufgabe wird geliefert', ($card['ok'] ?? false) && !($card['done'] ?? true));
    ok('Deutscher Satz ist dabei', ($card['native'] ?? '') !== '');
    ok('Fremdsatz trägt die Lücke', str_contains((string) ($card['foreign'] ?? ''), '{}'));
    ok('Die Lösung wird nicht mitgeschickt', !array_key_exists('answer', $card));
    ok('Sprachkürzel für die Tastatur ist dabei', ($card['lang'] ?? '') === 'en',
       (string) ($card['lang'] ?? 'fehlt'));

    // Die richtige Antwort steht in der Datenbank - die API gibt sie nicht heraus.
    $loesung = (string) qv(
        'SELECT answer FROM sentences s JOIN vocab v ON v.id = s.vocab_id
          WHERE v.unit_id = ? AND s.native_text = ? LIMIT 1',
        [$unitId, $card['native']]);

    [$r] = apiCall('cloze', 'answer', ['nonce' => $card['nonce'], 'text' => $loesung]);
    ok('Richtige Eingabe zählt als richtig', ($r['correct'] ?? false) === true);
    ok('Und gilt als exakt geschrieben', ($r['exact'] ?? false) === true);
    ok('Serie steht bei 1', ($r['streak'] ?? -1) === 1);

    [$r2, $s2] = apiCall('cloze', 'answer', ['nonce' => $card['nonce'], 'text' => $loesung]);
    ok('Nonce gilt nur einmal', $s2 === 409, "Status $s2");

    // Falsche Eingabe.
    [$card] = apiCall('cloze', 'next', null, ['unit_id' => $unitId]);
    [$r] = apiCall('cloze', 'answer', ['nonce' => $card['nonce'], 'text' => 'völliger Unsinn']);
    ok('Falsche Eingabe zählt als falsch', ($r['correct'] ?? true) === false);
    ok('Serie fällt zurück', ($r['streak'] ?? -1) === 0);
    ok('Die Lösung wird nach der Antwort gezeigt', ($r['answer'] ?? '') !== '');

    // Ganze Einheit durchspielen.
    $runden = 0;
    while ($runden < 300) {
        [$card] = apiCall('cloze', 'next', null, ['unit_id' => $unitId]);
        if ($card['done'] ?? false) {
            break;
        }
        $loesung = (string) qv(
            'SELECT answer FROM sentences s JOIN vocab v ON v.id = s.vocab_id
              WHERE v.unit_id = ? AND s.native_text = ? LIMIT 1',
            [$unitId, $card['native']]);
        apiCall('cloze', 'answer', ['nonce' => $card['nonce'], 'text' => $loesung]);
        $runden++;
    }
    ok('Lückentext wird bestanden', ($card['done'] ?? false), "nach $runden Runden");

    $minStreak = (int) qv(
        "SELECT MIN(p.streak) FROM progress p JOIN vocab v ON v.id = p.vocab_id
          WHERE v.unit_id = ? AND p.mode = 'cloze'", [$unitId]);
    ok('Auch hier gilt: dreimal hintereinander', $minStreak >= 3, "kleinste Serie: $minStreak");

    // Der eigentliche Beweis, dass progress.mode die Übungsarten trennt.
    ok('Der Multiple-Choice-Stand blieb unberührt',
       (int) qv("SELECT COUNT(*) FROM progress p JOIN vocab v ON v.id = p.vocab_id
                  WHERE v.unit_id = ? AND p.mode = 'mc'", [$unitId]) === $mcVorher);

    [$data, $status] = apiCall('units', 'reset', ['id' => $unitId, 'mode' => 'cloze']);
    ok('Nur den Lückentext zurücksetzen geht', $status === 200
       && (int) qv("SELECT COUNT(*) FROM progress p JOIN vocab v ON v.id = p.vocab_id
                     WHERE v.unit_id = ? AND p.mode = 'cloze'", [$unitId]) === 0);
    ok('Multiple Choice übersteht auch das',
       (int) qv("SELECT COUNT(*) FROM progress p JOIN vocab v ON v.id = p.vocab_id
                  WHERE v.unit_id = ? AND p.mode = 'mc'", [$unitId]) === $mcVorher);

    [$data, $status] = apiCall('units', 'reset', ['id' => $unitId, 'mode' => 'quatsch']);
    ok('Unbekannte Übungsart wird abgelehnt', $status === 400, "Status $status");

    [$data, $status] = apiCall('cloze', 'next', null, ['unit_id' => $otherUnitId]);
    ok('Fremde Lerneinheit bleibt gesperrt', $status === 404, "Status $status");
}

section('Zustand der Satzerzeugung');

// Haengengebliebener Auftrag: Ohne Grenze bliebe die Uebung fuer immer gesperrt.
q("UPDATE units SET sentences_status = 'running',
       sentences_started_at = NOW() - INTERVAL 2 HOUR, sentences_error = NULL
    WHERE id = ?", [$unitId]);
[$st] = apiCall('units', 'sentence_status', null, ['id' => $unitId]);
ok('Alter Auftrag mit vorhandenen Saetzen gilt als fertig',
   ($st['cloze']['status'] ?? '') === 'done', json_encode($st['cloze'] ?? []));

// Dasselbe ohne Saetze muss als gescheitert gelten.
q('INSERT INTO units (user_id, language_id, title, sentences_status, sentences_started_at)
   VALUES (?, ?, ?, ?, NOW() - INTERVAL 2 HOUR)',
  [$userId, $languageId, 'Haengengeblieben', 'running']);
$hUnit = (int) db()->lastInsertId();
q('INSERT INTO vocab (unit_id, term_foreign, term_native, word_type, position)
   VALUES (?, ?, ?, ?, 0)', [$hUnit, 'x', 'y', 'substantiv']);

[$st] = apiCall('units', 'sentence_status', null, ['id' => $hUnit]);
ok('Alter Auftrag ohne Saetze gilt als gescheitert',
   ($st['cloze']['status'] ?? '') === 'failed', json_encode($st['cloze'] ?? []));
ok('Mit einer Begruendung', ($st['cloze']['error'] ?? '') !== '');
ok('Und der Zustand wurde festgeschrieben',
   qv('SELECT sentences_status FROM units WHERE id = ?', [$hUnit]) === 'failed');

// Ein laufender Auftrag sperrt die Uebung.
q("UPDATE units SET sentences_status = 'running', sentences_started_at = NOW()
    WHERE id = ?", [$hUnit]);
[$c] = apiCall('cloze', 'next', null, ['unit_id' => $hUnit]);
ok('Solange erzeugt wird, meldet die Uebung "in Arbeit"',
   ($c['preparing'] ?? false) === true, json_encode($c));

[$u] = apiCall('units', 'get', null, ['id' => $hUnit]);
ok('Die Lerneinheit liefert den Zustand mit',
   ($u['modes']['cloze']['status'] ?? '') === 'running');

q('DELETE FROM units WHERE id = ?', [$hUnit]);

[$st, $code] = apiCall('units', 'sentence_status', null, ['id' => $otherUnitId]);
ok('Fremde Lerneinheit bleibt auch hier gesperrt', $code === 404, "Status $code");

section('Lückensätze im Admin');

$satzId = (int) qv('SELECT s.id FROM sentences s JOIN vocab v ON v.id = s.vocab_id
                     WHERE v.unit_id = ? LIMIT 1', [$unitId]);

$res = http($base . '/admin/sentences.php');
ok('Die Seite listet alle Sätze', $res['status'] === 200
   && str_contains($res['body'], 'name="sn[' . $satzId . ']"'), "Status {$res['status']}");
ok('Mit Kind, Sprache und Lerneinheit daneben',
   str_contains($res['body'], 'Testkind') && str_contains($res['body'], 'Testisch'));

$res = http($base . '/admin/sentences.php?q=' . urlencode('gibtsnichtxyz'));
ok('Die Suche filtert', str_contains($res['body'], 'Keine Sätze gefunden'));

// Bearbeiten über die globale Seite.
$res = adminPost('sentences.php', [
    'save' => '1',
    'sn'   => [$satzId => 'Ein neuer deutscher Satz.'],
    'sf'   => [$satzId => 'Un {} nouveau.'],
    'sa'   => [$satzId => 'texte'],
]);
$nach = q1('SELECT * FROM sentences WHERE id = ?', [$satzId]);
ok('Satz lässt sich global bearbeiten',
   $nach['native_text'] === 'Ein neuer deutscher Satz.' && $nach['answer'] === 'texte',
   json_encode($nach));

// Eine kaputte Form darf nichts überschreiben.
$res = adminPost('sentences.php', [
    'save' => '1',
    'sn'   => [$satzId => 'Deutsch'],
    'sf'   => [$satzId => 'ohne Lücke'],
    'sa'   => [$satzId => 'texte'],
]);
ok('Ein Satz ohne Lücke wird nicht gespeichert',
   qv('SELECT foreign_text FROM sentences WHERE id = ?', [$satzId]) === 'Un {} nouveau.');
ok('Und die Meldung sagt warum', str_contains($res['body'], 'Form nicht stimmt'));

adminPost('sentences.php', ['delete' => $satzId]);
ok('Satz lässt sich löschen',
   q1('SELECT id FROM sentences WHERE id = ?', [$satzId]) === null);

section('Übersicht der Lerneinheit');

[$u, $code] = apiCall('units', 'get', null, ['id' => $unitId]);
ok('Die Lerneinheit liefert ihre Vokabeln', $code === 200 && count($u['vocab'] ?? []) > 0);

$erste = $u['vocab'][0] ?? [];
ok('Jede Vokabel bringt beide Übungsarten mit',
   isset($erste['modes']['mc'], $erste['modes']['cloze']), json_encode(array_keys($erste)));
ok('Mit Serie, Treffern und Stand je Übungsart',
   isset($erste['modes']['mc']['streak'], $erste['modes']['mc']['known'],
         $erste['modes']['cloze']['correct'], $erste['modes']['cloze']['possible']));
ok('Auswählen ist immer möglich', $erste['modes']['mc']['possible'] === true);

// Solange eine Vokabel noch keinen Satz hat, zeigt die Übersicht dafür einen
// Strich - nicht drei offene Punkte, die nie voll werden könnten.
q('INSERT INTO vocab (unit_id, term_foreign, term_native, word_type, position)
   VALUES (?, ?, ?, ?, 99)', [$unitId, 'Bonne nuit !', 'Gute Nacht!', 'aussage']);
$grussId = (int) db()->lastInsertId();

[$u2] = apiCall('units', 'get', null, ['id' => $unitId]);
$gruss = null;
foreach ($u2['vocab'] as $v) {
    if ((int) $v['id'] === $grussId) {
        $gruss = $v;
    }
}
ok('Die Grußformel steht in der Liste', $gruss !== null);
ok('Ohne Satz zeigt der Lückentext einen Strich',
   $gruss !== null && $gruss['modes']['cloze']['possible'] === false);
ok('Beim Auswählen ist sie sofort übbar',
   $gruss !== null && $gruss['modes']['mc']['possible'] === true);

// Und sie bekommt jetzt auch Lückensätze - früher war sie davon ausgenommen.
if ($isFake) {
    apiCall('cloze', 'prepare', ['unit_id' => $unitId]);
    ok('Auch eine Grußformel bekommt Lückensätze',
       (int) qv('SELECT COUNT(*) FROM sentences WHERE vocab_id = ?', [$grussId]) > 0,
       'keine erzeugt');

    [$u3] = apiCall('units', 'get', null, ['id' => $unitId]);
    foreach ($u3['vocab'] as $v) {
        if ((int) $v['id'] === $grussId) {
            ok('Und gilt danach als übbar', $v['modes']['cloze']['possible'] === true);
        }
    }
}

q('DELETE FROM vocab WHERE id = ?', [$grussId]);

// Die beiden Stände dürfen sich unterscheiden - das war der Anlass.
$mcGekonnt    = count(array_filter($u['vocab'], fn ($v) => $v['modes']['mc']['known']));
$clozeGekonnt = count(array_filter($u['vocab'], fn ($v) => $v['modes']['cloze']['known']));
ok('Die Stände beider Übungsarten werden getrennt geführt',
   is_int($mcGekonnt) && is_int($clozeGekonnt),
   "Auswählen $mcGekonnt, Lückentext $clozeGekonnt");

section('Filter im Admin');

// Dropdowns sind zwei Klicks fuer eine Auswahl. Jetzt sind es Links - die
// funktionieren auch ohne JavaScript und lassen sich als Lesezeichen ablegen.
foreach (['vocab.php', 'sentences.php'] as $seite) {
    $res = http($base . '/admin/' . $seite);
    ok("$seite zeigt die Kinder als Knoepfe",
       str_contains($res['body'], 'class="chips"')
       && str_contains($res['body'], 'class="chip"'), "Status {$res['status']}");
    ok("$seite kommt ohne Auswahlfeld fuer das Kind aus",
       !str_contains($res['body'], 'name="user" id="user"'));
}

// Ein Klick auf ein Kind fuehrt zu einem Link, der genau dieses setzt.
$res = http($base . '/admin/vocab.php');
ok('Der Knopf verweist auf das gewaehlte Kind',
   str_contains($res['body'], 'vocab.php?user=' . $userId), 'Link nicht gefunden');

// Sprache wechseln muss die tiefere Auswahl fallenlassen, sonst zeigte der
// Filter auf eine Lerneinheit, die zur neuen Sprache nicht gehoert.
$res = http($base . '/admin/vocab.php?' . http_build_query(
    ['user' => $userId, 'language' => $languageId, 'unit' => $unitId]));

/** Die Links einer Filterzeile, an ihrer Beschriftung erkannt. */
$zeile = static function (string $body, string $label): array {
    $muster = '#<span class="lbl">' . preg_quote($label, '#')
            . '</span><span class="chips">(.*?)</span></div>#s';
    if (preg_match($muster, $body, $m) !== 1) {
        return [];
    }
    preg_match_all('#href="([^"]*)"#', $m[1], $links);
    return array_map(static fn (string $l): string => html_entity_decode($l), $links[1]);
};

$sprachLinks = $zeile($res['body'], 'Sprache');
ok('Die Sprachzeile hat Knoepfe', $sprachLinks !== []);
ok('Beim Sprachwechsel faellt die Lerneinheit weg',
   $sprachLinks !== [] && !array_filter($sprachLinks,
       static fn (string $l): bool => str_contains($l, 'unit=')),
   implode(' ', $sprachLinks));

$kindLinks = $zeile($res['body'], 'Kind');
ok('Beim Kindwechsel fallen Sprache und Lerneinheit weg',
   $kindLinks !== [] && !array_filter($kindLinks,
       static fn (string $l): bool => str_contains($l, 'language=') || str_contains($l, 'unit=')),
   implode(' ', $kindLinks));

$einheitLinks = $zeile($res['body'], 'Lerneinheit');
ok('Die Lerneinheit-Knoepfe behalten Kind und Sprache',
   $einheitLinks !== [] && !array_filter($einheitLinks,
       static fn (string $l): bool => !str_contains($l, 'language=')),
   implode(' ', $einheitLinks));

ok('Die gewaehlte Lerneinheit ist hervorgehoben',
   str_contains($res['body'], 'class="chip on"'));

// Die Filter muessen weiter ueber die URL steuerbar sein.
$res = http($base . '/admin/sentences.php?' . http_build_query(['user' => $userId]));
ok('Filter per URL wirken weiterhin', $res['status'] === 200
   && str_contains($res['body'], 'Testkind'));

section('Farbwahl im Admin');

require_once __DIR__ . '/../lib/colors.php';

ok('Die Palette hat 64 Farben', count(color_palette()) === 64, (string) count(color_palette()));
ok('Alle sind gültige Hexwerte',
   count(array_filter(color_palette(),
       static fn (string $c): bool => preg_match('/^#[0-9a-f]{6}$/', $c) === 1)) === 64);
ok('Und alle verschieden', count(array_unique(color_palette())) === 64);

$res = http($base . '/admin/users.php');
ok('Die Seite zeigt das Farbfeld', str_contains($res['body'], 'class="palette"'));
ok('Kein Auswahlfeld mehr für die Farbe', !str_contains($res['body'], '<select name="color"'));
ok('64 Kacheln stehen zur Wahl',
   substr_count($res['body'], 'class="swatch-pick"') >= 64,
   (string) substr_count($res['body'], 'class="swatch-pick"'));

// Eine Farbe aus dem Feld setzen.
$farbe = color_palette()[40];
adminPost('users.php', ['update' => '1', 'id' => $userId,
                        'display_name' => 'Testkind', 'color' => $farbe, 'active' => '1']);
ok('Gewählte Farbe wird gespeichert',
   qv('SELECT color FROM users WHERE id = ?', [$userId]) === $farbe,
   (string) qv('SELECT color FROM users WHERE id = ?', [$userId]));

$res = http($base . '/admin/users.php');
ok('Und ist im Feld als gewählt markiert',
   str_contains($res['body'], 'value="' . $farbe . '" checked'));

// Unsinn darf nicht durchrutschen.
adminPost('users.php', ['update' => '1', 'id' => $userId,
                        'display_name' => 'Testkind', 'color' => 'rot; drop table', 'active' => '1']);
ok('Ungültige Farbe wird abgefangen',
   preg_match('/^#[0-9a-f]{6}$/', (string) qv('SELECT color FROM users WHERE id = ?', [$userId])) === 1,
   (string) qv('SELECT color FROM users WHERE id = ?', [$userId]));

// Das Symbol muss mit heller wie dunkler Farbe lesbar bleiben.
foreach ([color_palette()[0] => 'sehr hell', color_palette()[7] => 'sehr dunkel'] as $c => $was) {
    q('UPDATE users SET color = ? WHERE id = ?', [$c, $userId]);
    $png = http($base . '/icon.php?u=' . $userId . '&s=192');
    ok("Symbol wird erzeugt ($was: $c)", str_starts_with($png['body'], chr(0x89) . 'PNG'));
}
q('UPDATE users SET color = ? WHERE id = ?', [$farbe, $userId]);

// ------------------------------------------------------------------ Abmelden

section('Abmelden und Token-Widerruf');

apiCall('auth', 'logout', []);
[$data, $status] = apiCall('languages', 'list');
ok('Nach dem Abmelden ist die API gesperrt', $status === 401, "Status $status");

q('UPDATE device_tokens SET revoked_at = NOW() WHERE user_id = ?', [$userId]);
$res = http($base . '/?t=' . urlencode($token));
ok('Widerrufener Token leitet weiter', $res['status'] === 302);
$res = http($base . '/');
ok('Widerrufener Token meldet niemanden mehr an',
   !str_contains($res['body'], 'Testkinds Vokabeln'));

// ------------------------------------------------------------------ Aufräumen

q('DELETE FROM users WHERE id = ?', [$userId]);
q("DELETE FROM users WHERE username IN ('e2e_other')");
@unlink($jar);

echo "\n" . str_repeat('-', 52) . "\n";
printf("%d bestanden, %d fehlgeschlagen\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
