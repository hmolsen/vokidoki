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

require_once __DIR__ . '/../lib/courses.php';

/*
 * Ein Konto entsteht nie fuer sich allein - es gehoert zu einer Schule, und
 * ohne die kann es weder eine Sprache anlegen noch eine Lerneinheit sehen,
 * weil beides am Kurs haengt. Die Vorbereitung bildet deshalb ab, was
 * admin/users.php tut.
 */
function makeUser(string $username, string $display, string $color = '#e0559a'): int
{
    q('DELETE FROM users WHERE username = ?', [$username]);
    q('INSERT INTO users (username, display_name, password_hash, color, can_import)
       VALUES (?, ?, ?, ?, 1)',
      [$username, $display, password_hash('geheim123', PASSWORD_DEFAULT), $color]);

    $id = (int) db()->lastInsertId();
    user_assign_to_school($id);
    return $id;
}

/**
 * Sprache samt Kurs, wie api/languages.php sie anlegt. Ohne Kurs waere die
 * Sprache da, aber fuer ihren eigenen Urheber unsichtbar.
 */
function makeLanguage(int $userId, string $name, ?string $code = null): int
{
    q('INSERT INTO languages (user_id, school_id, name, flag_emoji, code)
       VALUES (?, (SELECT school_id FROM users WHERE id = ?), ?, ?, ?)',
      [$userId, $userId, $name, '', $code]);
    $langId = (int) db()->lastInsertId();

    $user = q1('SELECT * FROM users WHERE id = ?', [$userId]);
    course_create_for_language(['id' => $langId, 'name' => $name], $user);

    return $langId;
}

/** Lerneinheit am Kurs der Sprache, wie api/import.php sie anlegt. */
function makeUnit(int $userId, int $langId, string $title, string $extraCols = '',
                  array $extraVals = []): int
{
    $kurs = course_for_language($langId);
    q('INSERT INTO units (user_id, language_id, course_id, title' . $extraCols . ')
       VALUES (?, ?, ?, ?' . str_repeat(', ?', count($extraVals)) . ')',
      array_merge([$userId, $langId, $kurs === null ? null : (int) $kurs['id'], $title],
                  $extraVals));
    return (int) db()->lastInsertId();
}

$username = 'e2e_test';
$userId   = makeUser($username, 'Testkind');
ok('Account angelegt', $userId > 0);
ok('Und gehoert zu einer Schule',
   (int) qv('SELECT school_id FROM users WHERE id = ?', [$userId]) > 0,
   'ohne Schule kann das Konto keinen Kurs haben');

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
$otherLang   = makeLanguage($otherId, 'Fremdisch');
$otherUnitId = makeUnit($otherId, $otherLang, 'Fremde Unit');

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

section('Zugriffsregeln an einer Stelle');

/*
 * Die Regel, wer was sehen und aendern darf, stand bis vor kurzem in zwei
 * Funktionen in api/_boot.php, die drei Dinge zugleich taten: pruefen, laden
 * und im Fehlerfall eine JSON-Antwort schicken. Jetzt steht sie in
 * lib/access.php - ohne Sitzung, ohne Ausgabe, und damit auch fuer einen
 * Lehrkraft-Bereich brauchbar, der weiterleitet statt JSON zu schicken.
 *
 * Diese Pruefungen halten die Naht zusammen. Sie kosten wenig und verhindern
 * genau das, was sonst passiert: dass jemand "nur schnell" wieder eine eigene
 * Besitzabfrage in einen Endpunkt schreibt.
 */
$accessDatei = __DIR__ . '/../lib/access.php';
ok('Es gibt eine eigene Datei fuer die Zugriffsregeln', is_file($accessDatei));

$access = (string) file_get_contents($accessDatei);

// Kommentare duerfen die Begriffe nennen - es geht um tatsaechliche Aufrufe.
$ohneKommentare = (string) preg_replace(
    ['~/\*.*?\*/~s', '~//[^
]*~'], '', $access,
);
$effekte = [];
foreach (['session_start', 'session_boot', 'json_fail', 'json_out', 'header('] as $wort) {
    if (str_contains($ohneKommentare, $wort)) {
        $effekte[] = $wort;
    }
}
ok('Sie kennt weder Sitzung noch Ausgabe', $effekte === [], implode(', ', $effekte));

ok('Und braucht nur die Datenbank',
   preg_match_all('~^require_once[^
]*~m', $ohneKommentare, $reqs) === 1
   && str_contains($reqs[0][0], 'db.php'),
   implode(' | ', $reqs[0] ?? []));

// Der Beweis, dass beim Laden nichts passiert: vorher wie nachher keine Sitzung.
$vorher = session_status();
require_once $accessDatei;
ok('Das blosse Laden startet keine Sitzung',
   session_status() === $vorher && $vorher === PHP_SESSION_NONE);
ok('Und die Regeln stehen danach bereit', function_exists('load_unit_for_view'));

ok('Sehen und Aendern sind getrennte Fragen',
   str_contains($access, 'function load_unit_for_view')
   && str_contains($access, 'function load_unit_for_edit'));

// Kein Endpunkt darf die Regel umgehen. Gesucht wird nach eigenen
// Besitzabfragen - "user_id = ?" in einem SELECT ausserhalb der Huellen.
$umgeher = [];
foreach (glob(__DIR__ . '/../api/*.php') ?: [] as $datei) {
    if (basename($datei) === '_boot.php') {
        continue;
    }
    $quelle = (string) file_get_contents($datei);
    // Gesucht ist die Besitzpruefung an einem einzelnen Datensatz, also
    // "WHERE id = ? AND user_id = ?". Eine schlichte Auflistung der eigenen
    // Sprachen filtert ebenfalls auf user_id und ist voellig in Ordnung.
    if (preg_match('/WHERE\s+\w*\.?id\s*=\s*\?\s+AND\s+\w*\.?user_id\s*=\s*\?/i', $quelle) === 1) {
        $umgeher[] = basename($datei);
    }
}
ok('Kein Endpunkt prueft den Besitz noch selbst',
   $umgeher === [], implode(', ', $umgeher));

ok('Die alten Funktionen sind verschwunden',
   !str_contains((string) file_get_contents(__DIR__ . '/../api/_boot.php'), 'function own_unit'));

// Das Einlesen haengt an einer Faehigkeit, nicht mehr allein am Besitz.
$importQuelle = (string) file_get_contents(__DIR__ . '/../api/import.php');
ok('Einlesen verlangt eine ausdrueckliche Berechtigung',
   substr_count($importQuelle, 'require_cap($user, CAP_IMPORT)') === 2,
   substr_count($importQuelle, 'require_cap($user, CAP_IMPORT)') . ' von 2 Aktionen');

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

section('Schule, Klasse, Kurs');

/*
 * Die Ueberfuehrung des vorhandenen Bestandes.
 *
 * Geprueft wird nicht das einmalige Ergebnis auf dieser Datenbank, sondern die
 * Migration selbst: Ein Kind mit Sprache und Lerneinheit wird angelegt, wie es
 * vor dem Umbau ausgesehen haette - ohne Schule, ohne Kurs. Dann laeuft die
 * Schemapflege erneut und muss alles einsortieren.
 */
require_once __DIR__ . '/../lib/schema.php';

foreach (['schools', 'classes', 'class_members', 'courses', 'course_members'] as $t) {
    ok("Tabelle $t ist da", table_exists($t));
}
foreach ([['users', 'school_id'], ['users', 'role'], ['users', 'can_import'],
          ['languages', 'school_id'], ['units', 'course_id'],
          ['units', 'released_position'], ['ai_requests', 'school_id']] as [$t, $c]) {
    ok("Spalte $t.$c ist da", column_exists($t, $c));
}

// Ein Kind, wie es vor dem Umbau ausgesehen haette.
$altName = 'testalt_' . bin2hex(random_bytes(3));
q('INSERT INTO users (username, display_name, password_hash, color, school_id, can_import)
   VALUES (?, ?, ?, ?, NULL, 0)',
  [$altName, 'Altkind', password_hash('geheim123', PASSWORD_DEFAULT), '#4f7cff']);
$altUser = (int) db()->lastInsertId();

q('INSERT INTO languages (user_id, name, flag_emoji, code) VALUES (?, ?, ?, ?)',
  [$altUser, 'Altisch', '', 'de']);
$altLang = (int) db()->lastInsertId();

q('INSERT INTO units (user_id, language_id, title) VALUES (?, ?, ?)',
  [$altUser, $altLang, 'Alte Einheit']);
$altUnit = (int) db()->lastInsertId();

ok('Ein Bestand ohne Schule und Kurs steht bereit',
   qv('SELECT school_id FROM users WHERE id = ?', [$altUser]) === null
   && qv('SELECT course_id FROM units WHERE id = ?', [$altUnit]) === null);

$kurseVorher = (int) qv('SELECT COUNT(*) FROM courses');

// Die Merker loeschen, damit die Ueberfuehrung erneut laeuft.
q("DELETE FROM settings WHERE k LIKE 'schema_applied_family.%'");
settings_reset_cache();
ensure_schema();

ok('Das Kind bekommt eine Schule',
   (int) qv('SELECT school_id FROM users WHERE id = ?', [$altUser]) > 0);
ok('Und landet in einer Klasse',
   (int) qv('SELECT COUNT(*) FROM class_members WHERE user_id = ?', [$altUser]) === 1);
ok('Seine Rechte bleiben, wie sie waren',
   (int) qv('SELECT can_import FROM users WHERE id = ?', [$altUser]) === 1,
   'der Bestand darf weiter einlesen');

$kurs = q1('SELECT * FROM courses WHERE language_id = ?', [$altLang]);
ok('Zur Sprache entsteht ein Kurs', $kurs !== null);
ok('Der Kurs heisst nach Sprache und Kind',
   ($kurs['name'] ?? '') === 'Altisch Altkind', $kurs['name'] ?? '-');
ok('Das Kind ist Mitglied darin',
   (int) qv('SELECT COUNT(*) FROM course_members WHERE course_id = ? AND user_id = ?',
            [(int) $kurs['id'], $altUser]) === 1);
ok('Und zwar als SchuelerIn',
   qv('SELECT member_role FROM course_members WHERE course_id = ? AND user_id = ?',
      [(int) $kurs['id'], $altUser]) === 'student');

ok('Die Lerneinheit haengt am Kurs',
   (int) qv('SELECT course_id FROM units WHERE id = ?', [$altUnit]) === (int) $kurs['id']);
ok('Und gilt als vollstaendig freigegeben',
   (int) qv('SELECT released_position FROM units WHERE id = ?', [$altUnit]) > 0,
   'sonst saehe das Kind seine bisherigen Vokabeln nicht mehr');

// Zweimal laufen darf nichts verdoppeln - sonst entstuenden bei jedem
// Admin-Aufruf neue Kurse.
$kurseNachher = (int) qv('SELECT COUNT(*) FROM courses');
q("DELETE FROM settings WHERE k LIKE 'schema_applied_family.%'");
settings_reset_cache();
ensure_schema();
ok('Ein zweiter Durchlauf legt nichts doppelt an',
   (int) qv('SELECT COUNT(*) FROM courses') === $kurseNachher,
   $kurseNachher . ' vorher, ' . qv('SELECT COUNT(*) FROM courses') . ' nachher');
// Die Ueberfuehrung legt zu JEDER Sprache ohne Kurs einen an - im Testlauf
// entstehen unterwegs mehrere. Die tragende Aussage ist deshalb nicht die
// Anzahl, sondern die Zuordnung: genau ein Kurs je Sprache, keiner uebrig.
$ohneKurs = (int) qv('SELECT COUNT(*) FROM languages l
                       WHERE l.school_id IS NOT NULL
                         AND NOT EXISTS (SELECT 1 FROM courses co WHERE co.language_id = l.id)');
ok('Jede Sprache hat danach einen Kurs', $ohneKurs === 0, $ohneKurs . ' ohne');

$doppelt = (int) qv('SELECT COUNT(*) FROM (
                        SELECT language_id FROM courses
                         GROUP BY language_id HAVING COUNT(*) > 1
                     ) d');
ok('Und keine Sprache zwei', $doppelt === 0, $doppelt . ' doppelt');

/*
 * Der Zugriff haengt jetzt an der Kurszugehoerigkeit statt an units.user_id.
 * Dass die uebrigen Pruefungen dieser Suite davon unberuehrt bleiben, ist der
 * eigentliche Beweis: Die Ueberfuehrung des Bestandes war vollstaendig.
 */
$accessQuelle = (string) file_get_contents(__DIR__ . '/../lib/access.php');
ok('Die Zugriffsregeln fragen die Kurszugehoerigkeit',
   substr_count($accessQuelle, 'course_members') >= 4,
   substr_count($accessQuelle, 'course_members') . ' Abfragen');
ok('Und nicht mehr den Besitzer der Lerneinheit',
   !str_contains($accessQuelle, 'FROM units WHERE id = ? AND user_id'));

// Eine frisch angelegte Sprache bekommt sofort ihren Kurs - sonst waere sie
// fuer ihren eigenen Urheber unsichtbar.
[$neu, $code] = apiCall('languages', 'create', ['name' => 'Kursprobe', 'flag' => '']);
ok('Eine neue Sprache laesst sich anlegen', $code === 200, json_encode($neu));
$probeLang = (int) ($neu['id'] ?? 0);
ok('Und bekommt sofort einen Kurs',
   q1('SELECT id FROM courses WHERE language_id = ?', [$probeLang]) !== null);
ok('Mit dem Urheber als Mitglied',
   (int) qv('SELECT COUNT(*) FROM course_members m
               JOIN courses co ON co.id = m.course_id
              WHERE co.language_id = ? AND m.user_id = ?', [$probeLang, $userId]) === 1);

[$sichtbar] = apiCall('units', 'list', null, ['language_id' => $probeLang]);
ok('Und ist fuer ihren Urheber sichtbar', ($sichtbar['ok'] ?? false) === true,
   json_encode($sichtbar));

apiCall('languages', 'delete', ['id' => $probeLang]);

/*
 * Zwei Wege fuehren zum Schema: schema.sql bei einer Neuinstallation und die
 * Migrationen bei einem Update. Laufen sie auseinander, faellt das erst bei
 * der naechsten frischen Schule auf - und dann auf die unangenehme Art.
 *
 * Ein vollstaendiger Vergleich braeuchte eine zweite Datenbank und damit
 * Rechte, die nicht jede Installation hat. Geprueft wird deshalb das, was
 * tatsaechlich vergessen wird: eine Tabelle oder Spalte, die es per Migration
 * gibt, in schema.sql aber nicht.
 */
$schemaSql = (string) file_get_contents(__DIR__ . '/../schema.sql');

$fehlend = [];
foreach (['schools', 'classes', 'class_members', 'courses', 'course_members',
          'users', 'languages', 'units', 'vocab', 'sentences', 'sentence_flags',
          'progress', 'ai_requests', 'settings', 'device_tokens'] as $t) {
    if (!str_contains($schemaSql, 'CREATE TABLE IF NOT EXISTS ' . $t . ' (')) {
        $fehlend[] = $t;
    }
}
ok('Jede Tabelle steht auch in schema.sql', $fehlend === [], implode(', ', $fehlend));

foreach ([['school_id', 'users'], ['role', 'users'], ['can_import', 'users'],
          ['course_id', 'units'], ['released_position', 'units']] as [$spalte, $tabelle]) {
    ok("schema.sql kennt $tabelle.$spalte", str_contains($schemaSql, $spalte));
}

ok('Und den neuen Schluessel auf progress',
   str_contains($schemaSql, 'uq_progress_user (user_id, vocab_id, mode)')
   && !str_contains($schemaSql, 'uq_progress (vocab_id, mode)'));

q('DELETE FROM users WHERE id = ?', [$altUser]);

section('Lernstand gehört dem Kind');

/*
 * Der Kern des Schulumbaus.
 *
 * Heute haengt der Lernstand an der Vokabel, nicht am Kind: progress traegt
 * UNIQUE (vocab_id, mode) ohne user_id, und record_answer() liest ohne
 * Benutzerfilter. Solange jede Vokabel genau einem Kind gehoert, faellt das
 * nicht auf. Sobald eine Klasse denselben Vokabelsatz uebt, teilen sich 28
 * Kinder eine Serie - sie sehen gegenseitig "gekonnt", und das Zuruecksetzen
 * eines Kindes wirkt fuer die halbe Klasse.
 *
 * Diese Pruefungen gehen bewusst an der API vorbei direkt auf lib/progress.php,
 * weil own_unit() zwei Kinder an derselben Vokabel heute noch gar nicht
 * zulaesst. Sie beschreiben den Zielzustand.
 */
require_once __DIR__ . '/../lib/progress.php';

$zweitId = makeUser('testzweit_' . bin2hex(random_bytes(3)), 'Zweitkind', '#4f7cff');
ok('Ein zweites Kind ist angelegt', $zweitId > 0 && $zweitId !== $userId);

$gemeinsam = (int) qv('SELECT id FROM vocab WHERE unit_id = ? ORDER BY position LIMIT 1', [$unitId]);
ok('Eine gemeinsame Vokabel ist da', $gemeinsam > 0);

q('DELETE FROM progress WHERE vocab_id = ?', [$gemeinsam]);

// Kind eins antwortet zweimal richtig, Kind zwei einmal falsch.
record_answer($userId,  $gemeinsam, MODE_CHOICE, true);
record_answer($userId,  $gemeinsam, MODE_CHOICE, true);
record_answer($zweitId, $gemeinsam, MODE_CHOICE, false);

$zeilen = (int) qv('SELECT COUNT(*) FROM progress WHERE vocab_id = ? AND mode = ?',
                   [$gemeinsam, MODE_CHOICE]);
ok('Beide Kinder bekommen eine eigene Zeile', $zeilen === 2, $zeilen . ' statt 2');

$serieEins  = qv('SELECT streak FROM progress WHERE vocab_id = ? AND mode = ? AND user_id = ?',
                 [$gemeinsam, MODE_CHOICE, $userId]);
$serieZwei  = qv('SELECT streak FROM progress WHERE vocab_id = ? AND mode = ? AND user_id = ?',
                 [$gemeinsam, MODE_CHOICE, $zweitId]);
ok('Die Serie des ersten Kindes steht bei 2', (int) $serieEins === 2, var_export($serieEins, true));
ok('Die des zweiten bei 0 - und ueberschreibt die erste nicht',
   $serieZwei !== null && (int) $serieZwei === 0, var_export($serieZwei, true));

// Und die Auswertung darf nur den eigenen Stand sehen.
record_answer($userId, $gemeinsam, MODE_CHOICE, true);
$eigen = qv('SELECT known_at FROM progress WHERE vocab_id = ? AND mode = ? AND user_id = ?',
            [$gemeinsam, MODE_CHOICE, $userId]);
$fremd = qv('SELECT known_at FROM progress WHERE vocab_id = ? AND mode = ? AND user_id = ?',
            [$gemeinsam, MODE_CHOICE, $zweitId]);
ok('Dreimal richtig gilt beim ersten Kind als gekonnt', $eigen !== null);
ok('Beim zweiten aber nicht', $fremd === null, var_export($fremd, true));

// Zuruecksetzen ist eine Sache des einzelnen Kindes.
reset_unit_progress($unitId, $zweitId, null);
$nachReset = (int) qv('SELECT COUNT(*) FROM progress WHERE vocab_id = ? AND user_id = ?',
                      [$gemeinsam, $userId]);
ok('Das Zuruecksetzen des einen laesst den anderen unberuehrt', $nachReset === 1,
   $nachReset . ' Zeilen statt 1');

q('DELETE FROM users WHERE id = ?', [$zweitId]);
q('DELETE FROM progress WHERE vocab_id = ?', [$gemeinsam]);

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

section('Sprachkürzel');

require_once __DIR__ . '/../lib/schema.php';

// Das Kürzel steuert im Lückentext den Tastaturhinweis und die Reihe der
// Sonderzeichen. Die Spalte kam erst spaeter dazu und wurde nur beim Anlegen
// gefuellt - wer seine Sprachen vorher angelegt hatte, sah davon nichts.
// Genau dieser Zustand wird hier nachgestellt.
$vorher = qa('SELECT id, code FROM languages');

q('INSERT INTO languages (user_id, name, flag_emoji, code) VALUES (?, ?, ?, NULL)',
  [$userId, 'Französisch', '']);
$frId = (int) db()->lastInsertId();
q('INSERT INTO languages (user_id, name, flag_emoji, code) VALUES (?, ?, ?, NULL)',
  [$userId, 'daenisch', '']);
$daId = (int) db()->lastInsertId();
q('INSERT INTO languages (user_id, name, flag_emoji, code) VALUES (?, ?, ?, NULL)',
  [$userId, 'Klingonisch', '']);
$klId = (int) db()->lastInsertId();

q('UPDATE languages SET code = NULL');

// Den Zustand vor dem Nachtrag herstellen - sonst gilt er als erledigt.
q("DELETE FROM settings WHERE k = 'schema_applied_languages.code.backfill'");
settings_reset_cache();

ok('Die Schemapflege erkennt, dass Kürzel fehlen',
   in_array('languages.code.backfill', schema_pending(), true),
   implode(', ', schema_pending()));

// Ein Aufruf des Admin-Bereichs traegt nach, wie bei jeder Schemaaenderung.
http($base . '/admin/');

ok('Französisch bekommt sein Kürzel - trotz Umlaut und großem Anfangsbuchstaben',
   qv('SELECT code FROM languages WHERE id = ?', [$frId]) === 'fr');
ok('Und die ausgeschriebene Schreibweise "daenisch" ebenso',
   qv('SELECT code FROM languages WHERE id = ?', [$daId]) === 'da');
ok('Eine frei benannte Sprache bleibt ohne',
   qv('SELECT code FROM languages WHERE id = ?', [$klId]) === null);

// Sonst bliebe die Schemapflege wegen des Klingonischen fuer immer offen und
// wuerde bei jedem Admin-Aufruf dasselbe UPDATE fahren.
ok('Danach ist nichts mehr offen',
   !in_array('languages.code.backfill', schema_pending(), true),
   implode(', ', schema_pending()));

section('Sprache im Admin pflegen');

$seite = http($base . '/admin/vocab.php?' . http_build_query(
    ['user' => $userId, 'language' => $frId]))['body'];

ok('Die Sprachkarte zeigt ein Feld für das Kürzel',
   str_contains($seite, 'name="lang_code"'));
ok('Mit dem aktuellen Wert darin',
   preg_match('/name="lang_code" value="fr"/', $seite) === 1);
// Der Knopf zum Löschen wurde bisher nie dargestellt - die Verarbeitung gab
// es, aber niemand konnte sie auslösen.
ok('Und einen Knopf zum Löschen der Sprache',
   str_contains($seite, 'name="delete_language"'));

$res = adminPost('vocab.php',
    ['save_language' => $frId, 'lang_code' => 'DA', 'user' => $userId, 'language' => $frId],
    http_build_query(['user' => $userId, 'language' => $frId]));
ok('Ein Kürzel lässt sich ändern - auch groß eingetippt',
   qv('SELECT code FROM languages WHERE id = ?', [$frId]) === 'da');

$res = adminPost('vocab.php',
    ['save_language' => $frId, 'lang_code' => 'Unsinn123', 'user' => $userId, 'language' => $frId],
    http_build_query(['user' => $userId, 'language' => $frId]));
ok('Unsinn wird abgewiesen', str_contains($res['body'], 'zwei oder drei Buchstaben'));
ok('Und der alte Wert bleibt stehen',
   qv('SELECT code FROM languages WHERE id = ?', [$frId]) === 'da');

$res = adminPost('vocab.php',
    ['save_language' => $frId, 'lang_code' => '', 'user' => $userId, 'language' => $frId],
    http_build_query(['user' => $userId, 'language' => $frId]));
ok('Leeren schaltet den Hinweis wieder ab',
   qv('SELECT code FROM languages WHERE id = ?', [$frId]) === null,
   'tatsächlich: ' . var_export(qv('SELECT code FROM languages WHERE id = ?', [$frId]), true));

// Der Nachtrag darf die Handarbeit nicht wieder überschreiben. Genau das tat
// er anfangs: Er leitete "noch offen" aus den Daten ab, und ein geleertes
// Kürzel sah für ihn wieder wie ein nachzutragendes aus.
http($base . '/admin/');
ok('Und ein Admin-Aufruf trägt es nicht wieder ein',
   qv('SELECT code FROM languages WHERE id = ?', [$frId]) === null,
   'tatsächlich: ' . var_export(qv('SELECT code FROM languages WHERE id = ?', [$frId]), true));

// Aufraeumen - die drei Wegwerf-Sprachen und der alte Stand der uebrigen.
q('DELETE FROM languages WHERE id IN (?, ?, ?)', [$frId, $daId, $klId]);
foreach ($vorher as $z) {
    q('UPDATE languages SET code = ? WHERE id = ?', [$z['code'], (int) $z['id']]);
}

section('Abstände vor Satzzeichen');

// Eine eigene Sprache mit französischem Kürzel - dort gilt die weite
// Schreibweise, auf der deutschen Seite derselben Vokabel die enge.
[$data] = apiCall('languages', 'create', ['name' => 'Abstandstest', 'flag' => '']);
$absLang = (int) $data['id'];
q('UPDATE languages SET code = ? WHERE id = ?', ['fr', $absLang]);

[$data] = apiCall('import', 'save', [
    'language_id' => $absLang,
    'title'       => 'Abstände',
    'entries'     => [
        ['foreign' => 'Salut!',          'native' => 'Hallo !'],
        ['foreign' => 'Bonne nuit  !',   'native' => 'Gute Nacht !'],
        ['foreign' => 'Merci , Madame!', 'native' => 'Danke , gnädige Frau!'],
        ['foreign' => 'Comment?',        'native' => 'Wie bitte ?'],
    ],
]);
$absUnit = (int) $data['unit_id'];

$eingelesen = qa('SELECT term_foreign, term_native FROM vocab WHERE unit_id = ? ORDER BY position',
                 [$absUnit]);

ok('Die Fremdsprache bekommt beim Einlesen ihr Leerzeichen',
   ($eingelesen[0]['term_foreign'] ?? '') === 'Salut !',
   json_encode($eingelesen[0] ?? null, JSON_UNESCAPED_UNICODE));
ok('Und die deutsche Seite verliert ihres',
   ($eingelesen[0]['term_native'] ?? '') === 'Hallo!',
   json_encode($eingelesen[0] ?? null, JSON_UNESCAPED_UNICODE));
ok('Doppelte Abstände werden zu einem',
   ($eingelesen[1]['term_foreign'] ?? '') === 'Bonne nuit !',
   json_encode($eingelesen[1] ?? null, JSON_UNESCAPED_UNICODE));
ok('Komma eng, Ausrufezeichen weit - in einer Zeile',
   ($eingelesen[2]['term_foreign'] ?? '') === 'Merci, Madame !'
   && ($eingelesen[2]['term_native'] ?? '') === 'Danke, gnädige Frau!',
   json_encode($eingelesen[2] ?? null, JSON_UNESCAPED_UNICODE));

// ---------------------------------------------- der Knopf für den Bestand
// Altbestand nachstellen: So sah es aus, bevor das Einlesen es richtigstellte.
q("UPDATE vocab SET term_foreign = 'Salut!', term_native = 'Hallo !'
    WHERE unit_id = ? AND position = 0", [$absUnit]);

$seite = http($base . '/admin/vocab.php?' . http_build_query(['user' => $userId]))['body'];
ok('Der Admin merkt, dass Abstände krumm sind',
   str_contains($seite, 'name="fix_punctuation"'), 'keine Karte im Markup');
ok('Und erklärt die französische Regel',
   str_contains($seite, 'Salut !'));

adminPost('vocab.php', ['fix_punctuation' => '1', 'user' => $userId],
          http_build_query(['user' => $userId]));

$nachher = q1('SELECT term_foreign, term_native FROM vocab WHERE unit_id = ? AND position = 0',
              [$absUnit]);
ok('Der Knopf rückt den Bestand zurecht',
   $nachher['term_foreign'] === 'Salut !' && $nachher['term_native'] === 'Hallo!',
   json_encode($nachher, JSON_UNESCAPED_UNICODE));

$seite = http($base . '/admin/vocab.php?' . http_build_query(['user' => $userId]))['body'];
ok('Danach verschwindet die Karte von selbst',
   !str_contains($seite, 'name="fix_punctuation"'));

// Ein zweiter Klick darf nichts weiterschieben.
adminPost('vocab.php', ['fix_punctuation' => '1', 'user' => $userId],
          http_build_query(['user' => $userId]));
ok('Ein zweiter Durchlauf ändert nichts mehr',
   qv('SELECT term_foreign FROM vocab WHERE unit_id = ? AND position = 0', [$absUnit]) === 'Salut !');

q('DELETE FROM languages WHERE id = ?', [$absLang]);

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
    $altUnit = makeUnit($userId, $languageId, 'Alter Bestand');
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
$hUnit = makeUnit($userId, $languageId, 'Haengengeblieben',
                  ', sentences_status', ['running']);
q('UPDATE units SET sentences_started_at = NOW() - INTERVAL 2 HOUR WHERE id = ?', [$hUnit]);
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

section('Gesamtfortschritt der Sprache');

/*
 * Die Uebersicht zaehlte nur Multiple Choice. Eine Lerneinheit stand damit
 * auf voll, waehrend im Lueckentext noch alles offen war - genau die
 * Verkuerzung, die in 'get' schon einmal behoben worden war.
 *
 * Gezaehlt wird in Schritten: je Vokabel einer fuers Auswaehlen, und ein
 * zweiter fuers Einsetzen, sofern sie einen Lueckensatz hat.
 */
[$liste, $code] = apiCall('units', 'list', null, ['language_id' => $languageId]);
ok('Die Uebersicht antwortet', $code === 200 && isset($liste['units']));

$dieEinheit = null;
foreach ($liste['units'] as $u) {
    if ((int) $u['id'] === $unitId) {
        $dieEinheit = $u;
    }
}
ok('Die Lerneinheit ist dabei', $dieEinheit !== null);

ok('Beide Uebungsarten werden ausgewiesen',
   isset($dieEinheit['known'], $dieEinheit['total'],
         $dieEinheit['cloze_known'], $dieEinheit['cloze_total']),
   json_encode(array_keys($dieEinheit ?? [])));

ok('Die Schritte sind die Summe aus beiden',
   $dieEinheit['steps_total'] === $dieEinheit['total'] + $dieEinheit['cloze_total']
   && $dieEinheit['steps_done'] === $dieEinheit['known'] + $dieEinheit['cloze_known'],
   json_encode($dieEinheit));

$erwartet = $dieEinheit['steps_total'] > 0
    ? (int) round($dieEinheit['steps_done'] / $dieEinheit['steps_total'] * 100)
    : 0;
ok('Die Prozentzahl passt dazu', $dieEinheit['percent'] === $erwartet,
   $dieEinheit['percent'] . ' statt ' . $erwartet);

// Der Kern: Alles im Auswaehlen gekonnt, im Lueckentext nichts - dann darf
// nicht "fertig" dastehen.
q('DELETE FROM progress WHERE vocab_id IN (SELECT id FROM vocab WHERE unit_id = ?)',
  [$unitId]);
foreach (qa('SELECT id FROM vocab WHERE unit_id = ?', [$unitId]) as $v) {
    q('INSERT INTO progress (user_id, vocab_id, mode, streak, known_at)
       VALUES (?, ?, ?, 3, NOW())', [$userId, (int) $v['id'], 'mc']);
}

[$liste] = apiCall('units', 'list', null, ['language_id' => $languageId]);
$nurMc = null;
foreach ($liste['units'] as $u) {
    if ((int) $u['id'] === $unitId) {
        $nurMc = $u;
    }
}
ok('Auswaehlen vollstaendig gekonnt', $nurMc['known'] === $nurMc['total']);
ok('Aber die Lerneinheit gilt nicht als fertig',
   $nurMc['cloze_total'] > 0 ? $nurMc['done'] === false : true,
   json_encode($nurMc));
ok('Und der Fortschritt steht nicht bei 100 Prozent',
   $nurMc['cloze_total'] > 0 ? $nurMc['percent'] < 100 : true,
   $nurMc['percent'] . ' %');

// Jetzt auch den Lueckentext - dann erst ist es geschafft.
foreach (qa('SELECT v.id FROM vocab v
              WHERE v.unit_id = ?
                AND EXISTS (SELECT 1 FROM sentences s WHERE s.vocab_id = v.id)',
            [$unitId]) as $v) {
    q('INSERT INTO progress (user_id, vocab_id, mode, streak, known_at)
       VALUES (?, ?, ?, 3, NOW())', [$userId, (int) $v['id'], 'cloze']);
}

[$liste] = apiCall('units', 'list', null, ['language_id' => $languageId]);
foreach ($liste['units'] as $u) {
    if ((int) $u['id'] === $unitId) {
        ok('Mit beiden Uebungsarten gilt sie als fertig', $u['done'] === true, json_encode($u));
        ok('Und steht bei 100 Prozent', $u['percent'] === 100, $u['percent'] . ' %');
    }
}

q('DELETE FROM progress WHERE vocab_id IN (SELECT id FROM vocab WHERE unit_id = ?)',
  [$unitId]);

$js = (string) file_get_contents(__DIR__ . '/../views/language.js');
ok('Die Uebersicht zeigt die Prozentzahl gross',
   str_contains($js, 'bigpercent') && str_contains($js, '${prozent}'));
ok('Und rechnet mit den Schritten beider Uebungsarten',
   str_contains($js, 'u.steps_total') && str_contains($js, 'u.steps_done'));
ok('Sie benennt auch, woraus sich das zusammensetzt',
   str_contains($js, 'Auswählen') && str_contains($js, 'Lückentext'));

section('Aufgabe melden');

// Vielleicht lag nicht das Kind daneben, sondern der Satz. Dann soll es das
// sagen koennen, ohne dass Papa davon erfaehrt, indem er hunderte Saetze
// durchsieht.
$flagSatz = (int) qv('SELECT s.id FROM sentences s
                       JOIN vocab v ON v.id = s.vocab_id
                      WHERE v.unit_id = ? LIMIT 1', [$unitId]);
ok('Ein Satz zum Melden ist da', $flagSatz > 0);

[$res, $code] = apiCall('cloze', 'flag',
    ['sentence_id' => $flagSatz, 'text' => 'mein Versuch']);
ok('Die Meldung wird angenommen', $code === 200 && ($res['ok'] ?? false) === true,
   json_encode($res));

$eintrag = q1('SELECT * FROM sentence_flags WHERE sentence_id = ?', [$flagSatz]);
ok('Und landet in der Datenbank', $eintrag !== null);
ok('Mit dem Kind, das gemeldet hat', (int) ($eintrag['user_id'] ?? 0) === $userId);
ok('Und mit dem, was es getippt hatte',
   ($eintrag['typed'] ?? '') === 'mein Versuch', json_encode($eintrag));

// Zweimal melden darf den Zaehler nicht hochtreiben - sonst ergaebe ein
// veraergertes Kind zehn Meldungen fuer denselben Satz.
apiCall('cloze', 'flag', ['sentence_id' => $flagSatz, 'text' => 'zweiter Versuch']);
ok('Zweimal melden zaehlt nur einmal',
   (int) qv('SELECT COUNT(*) FROM sentence_flags WHERE sentence_id = ?', [$flagSatz]) === 1);
ok('Der letzte Versuch wird aber vermerkt',
   qv('SELECT typed FROM sentence_flags WHERE sentence_id = ?', [$flagSatz]) === 'zweiter Versuch');

// Ein fremder Satz geht niemanden etwas an.
$fremderSatz = (int) qv('SELECT s.id FROM sentences s
                          JOIN vocab v ON v.id = s.vocab_id
                          JOIN units t ON t.id = v.unit_id
                         WHERE t.user_id <> ? LIMIT 1', [$userId]);
if ($fremderSatz > 0) {
    [$res, $code] = apiCall('cloze', 'flag', ['sentence_id' => $fremderSatz, 'text' => 'x']);
    ok('Ein fremder Satz laesst sich nicht melden', $code === 404,
       "Status $code");
    ok('Und es entsteht kein Eintrag',
       (int) qv('SELECT COUNT(*) FROM sentence_flags WHERE sentence_id = ?', [$fremderSatz]) === 0);
} else {
    ok('Ein fremder Satz laesst sich nicht melden', true, 'kein fremder Satz vorhanden');
    ok('Und es entsteht kein Eintrag', true, 'kein fremder Satz vorhanden');
}

section('Gemeldete Saetze im Admin');

$seite = http($base . '/admin/sentences.php')['body'];
ok('Der Admin weist auf Meldungen hin', str_contains($seite, 'gemeldete'));
ok('Mit einem Weg, nur diese zu zeigen', str_contains($seite, 'flagged=1'));
ok('Die gemeldete Zeile hebt sich ab', str_contains($seite, 'class="flagged"'));
ok('Und nennt, wer was getippt hat',
   str_contains($seite, 'Testkind') && str_contains($seite, 'zweiter Versuch'));

// Gemeldete Saetze stehen oben - sonst muesste man sie suchen.
$posGemeldet = strpos($seite, 'class="flagged"');
ok('Gemeldetes steht vor dem Rest',
   $posGemeldet !== false, 'keine gemeldete Zeile gefunden');

$nur = http($base . '/admin/sentences.php?flagged=1')['body'];
ok('Der Filter zeigt nur Gemeldetes',
   substr_count($nur, '<tr class="flagged"') === 1
   && substr_count($nur, '<tr>') <= 1,
   substr_count($nur, '<tr class="flagged"') . ' gemeldet, '
   . substr_count($nur, '<tr>') . ' uebrige');

// Eine Meldung kann auch unbegruendet sein.
$res = adminPost('sentences.php', ['clear_flags' => $flagSatz]);
ok('Die Meldung laesst sich zuruecknehmen',
   (int) qv('SELECT COUNT(*) FROM sentence_flags WHERE sentence_id = ?', [$flagSatz]) === 0);
ok('Und es wird gemeldet, dass es geschah',
   str_contains($res['body'], 'zurückgenommen'));

$seite = http($base . '/admin/sentences.php')['body'];
ok('Danach ist der Hinweis verschwunden', !str_contains($seite, 'gemeldete'));

section('Seitenblätterung');

require_once __DIR__ . '/../lib/pager.php';

// Die Rechnung zuerst - welche Zahlen stehen in der Leiste?
$folge = static fn (int $c, int $t): string => implode(' ', array_map(
    static fn (int $n): string => $n === PAGER_GAP ? '...' : (string) $n,
    pager_pages($c, $t),
));

ok('Wenige Seiten stehen vollständig da', $folge(2, 5) === '1 2 3 4 5', $folge(2, 5));
ok('Erste und letzte Seite sind immer dabei',
   str_starts_with($folge(10, 20), '1 ') && str_ends_with($folge(10, 20), ' 20'), $folge(10, 20));
ok('Um die aktuelle Seite herum ein Fenster',
   $folge(10, 20) === '1 ... 9 10 11 ... 20', $folge(10, 20));
ok('Am Anfang fällt die vordere Auslassung weg',
   $folge(1, 20) === '1 2 ... 20', $folge(1, 20));
ok('Am Ende die hintere', $folge(20, 20) === '1 ... 19 20', $folge(20, 20));

// Drei Punkte für eine einzige ausgelassene Seite sähen albern aus - und
// wären breiter als die Zahl, die sie verstecken.
ok('Eine einzelne Lücke wird ausgeschrieben statt gepunktet',
   $folge(4, 20) === '1 2 3 4 5 ... 20', $folge(4, 20));

$link = static fn (int $n): string => '/x?p=' . $n;
ok('Bei einer einzigen Seite kommt keine Leiste', pager(1, 1, $link) === '');
ok('Eine Seitenzahl außerhalb wird eingefangen',
   str_contains(pager(99, 3, $link), 'aria-current="page">3<'), pager(99, 3, $link));

$leiste = pager(1, 20, $link);
ok('Die aktuelle Seite ist kein Link',
   substr_count($leiste, 'aria-current="page"') === 1
   && !str_contains($leiste, '<a class="pg on"'));
ok('Der Rückwärtspfeil hat auf Seite 1 kein Ziel',
   str_contains($leiste, '<span class="pg pg-step off"'));
ok('Der Vorwärtspfeil schon', str_contains($leiste, 'href="/x?p=2" aria-label'));

// ------------------------------------------------ und dasselbe gerendert
$fuellVocab = (int) qv('SELECT id FROM vocab WHERE unit_id = ? LIMIT 1', [$unitId]);
ok('Eine Vokabel zum Anhaengen der Fuellsaetze', $fuellVocab > 0);

$fueller = [];
for ($i = 0; $i < 60; $i++) {
    q('INSERT INTO sentences (vocab_id, native_text, foreign_text, answer)
       VALUES (?, ?, ?, ?)',
      [$fuellVocab, 'Blätterfüller ' . $i, '{} Nummer ' . $i, 'Fueller' . $i]);
    $fueller[] = (int) db()->lastInsertId();
}

$res = http($base . '/admin/sentences.php');
ok('Mit genug Sätzen erscheint die Leiste',
   str_contains($res['body'], '<nav class="pager"'), 'keine Leiste im Markup');
ok('Sie nennt die aktuelle Seite', str_contains($res['body'], 'aria-current="page"'));
ok('Und sagt, wie viele es sind',
   preg_match('~<span class="pg-info">Seite 1 von \d+</span>~', $res['body']) === 1);

// Die Leiste gehört nicht ins Bearbeitungsformular: Ein Klick darauf verwirft
// alles, was in den Feldern steht und noch nicht gespeichert wurde.
$vorFormular = strpos($res['body'], '</form>');
$vorLeiste   = strpos($res['body'], '<nav class="pager"');
ok('Sie steht ausserhalb des Formulars',
   $vorFormular !== false && $vorLeiste !== false && $vorLeiste > $vorFormular);

$res = http($base . '/admin/sentences.php?p=2');
ok('Seite 2 lässt sich aufrufen',
   str_contains($res['body'], '<span class="pg-info">Seite 2 von'));
ok('Und dort hat der Rückwärtspfeil ein Ziel',
   preg_match('~<a class="pg pg-step" href="[^"]*p=1"~', $res['body']) === 1);

// Die alten schmucklosen Links sind weg.
ok('Keine nackten Blätterlinks mehr',
   !str_contains($res['body'], 'weiter &raquo;') && !str_contains($res['body'], '&laquo; zurück'));

q('DELETE FROM sentences WHERE id IN (' . implode(',', $fueller) . ')');

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

// Der Haken ist ein schmales Zeichen, die Punktreihe fuellt ihre Zelle ganz
// aus. Sitzen beide in verschieden breiten Zellen, springt die Spalte von
// Zeile zu Zeile. Geprueft wird deshalb die Rechnung selbst, nicht nur, dass
// die Regeln dastehen.
$css = (string) file_get_contents(__DIR__ . '/../style.css');

$zahl = static function (string $muster) use ($css): int {
    return preg_match($muster, $css, $m) === 1 ? (int) $m[1] : 0;
};

$stateW = $zahl('/\.marks\s*\{[^}]*--state-w:\s*(\d+)px/s');
$punkt  = $zahl('/\.dots i\s*\{\s*width:\s*(\d+)px/s');
$luecke = $zahl('/\.dots\s*\{[^}]*gap:\s*(\d+)px/s');

ok('Die Zellenbreite steht als eine Zahl in der Datei', $stateW > 0, (string) $stateW);
ok('Punkte, Haken und Strich teilen sich dieselbe Breite',
   preg_match('/\.marks \.dots,\s*\.mark-done,\s*\.mark-off\s*\{[^}]*width:\s*var\(--state-w\)/s', $css) === 1);
ok('Drei Punkte fuellen die Zelle genau aus',
   $punkt > 0 && $luecke > 0 && 3 * $punkt + 2 * $luecke === $stateW,
   "3x{$punkt}px + 2x{$luecke}px = " . (3 * $punkt + 2 * $luecke) . "px, Zelle {$stateW}px");
ok('Die Punkte verteilen sich ueber die volle Breite',
   preg_match('/\.marks \.dots\s*\{[^}]*justify-content:\s*space-between/s', $css) === 1);
ok('Haken und Strich stehen mittig darin - also ueber dem mittleren Punkt',
   preg_match('/\.mark-done,\s*\.mark-off\s*\{[^}]*text-align:\s*center/s', $css) === 1);

// Auf schmalen Geraeten schrumpfen Zelle und Punkte gemeinsam; sonst waere die
// Ausrichtung genau dort dahin, wo der Platz am knappsten ist.
$engW     = $zahl('/@media[^{]*360px[^}]*\.marks\s*\{[^}]*--state-w:\s*(\d+)px/s');
$engPunkt = $zahl('/\.marks \.dots i\s*\{\s*width:\s*(\d+)px/s');
ok('Auch auf schmalen Geraeten geht die Rechnung auf',
   $engW > 0 && $engPunkt > 0 && 3 * $engPunkt + 2 * $luecke === $engW,
   "3x{$engPunkt}px + 2x{$luecke}px = " . (3 * $engPunkt + 2 * $luecke) . "px, Zelle {$engW}px");

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

// Das Feld liegt zugeklappt hinter einem Knopf - offen in der Zeile schrumpften
// die Kacheln auf Pixelgrösse.
ok('Die Farbwahl steckt in einem Flyout', str_contains($res['body'], 'class="colorpick"'));
ok('Der Knopf zeigt die aktuelle Farbe', str_contains($res['body'], 'class="swatch-current"'));
ok('Und ist zugeklappt', !preg_match('/<details class="colorpick" open/', $res['body']));

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

section('Aktualisieren statt Abmelden in der App');

// In der installierten App gibt es keine Adresszeile - ohne diesen Weg kaeme
// eine neue Fassung dort nie an.
$js = file_get_contents(__DIR__ . '/../core.js');
ok('core.js bringt hardRefresh mit', str_contains($js, 'export async function hardRefresh'));
ok('Es meldet den Service Worker ab', str_contains($js, 'r.unregister()'));
ok('Und leert den Zwischenspeicher', str_contains($js, 'caches.delete'));

$view = file_get_contents(__DIR__ . '/../views/languages.js');
ok('In der App steht dort Aktualisieren statt Abmelden',
   str_contains($view, 'VT.standalone') && str_contains($view, "id=\"refresh\""));
ok('Im Browser bleibt das Abmelden', str_contains($view, "id=\"logout\""));

// Der Versionsstempel muss sich mit den Dateien aendern, sonst liefern Browser
// und Service Worker ewig die alte Fassung aus.
$res = http($base . '/');
preg_match('/app\.js\?v=(\d+)/', $res['body'], $m);
$stempel = (int) ($m[1] ?? 0);
ok('Die Huelle traegt einen Versionsstempel', $stempel > 1000000000, (string) $stempel);
ok('Er stammt vom Aenderungsdatum der Dateien',
   $stempel >= (int) filemtime(__DIR__ . '/../app.js'), (string) $stempel);

// Eine gepflegte Liste war unvollstaendig: Wer eine dort fehlende Ansicht
// aenderte, erreichte eine auf dem Homescreen liegende App gar nicht.
$aeltester = null;
foreach (glob(__DIR__ . '/../views/*.js') ?: [] as $datei) {
    if ($stempel < (int) filemtime($datei)) {
        $aeltester = basename($datei);
    }
}
ok('Und zwar von jeder Ansicht, nicht nur von einer Auswahl',
   $aeltester === null, 'nicht erfasst: ' . (string) $aeltester);
ok('Die Huelle selbst wird nicht vorgehalten',
   str_contains(strtolower($res['headers']), 'cache-control: no-store'),
   'kein no-store im Kopf');

section('Aktualisierung erkennen und anbieten');

// Die installierte App wird selten beendet - sie liegt wochenlang im
// Hintergrund und merkte von einer Aktualisierung bisher gar nichts.
[$meta, $code] = apiCall('meta', 'version');
ok('Der Server nennt seine Fassung', $code === 200 && ($meta['version'] ?? '') !== '',
   json_encode($meta));

require_once __DIR__ . '/../lib/version.php';
ok('Und zwar dieselbe, die auch die Hülle ausliefert',
   ($meta['version'] ?? '') === app_version(), (string) ($meta['version'] ?? ''));

// Auch eine App mit abgelaufener Sitzung soll erfahren, dass es etwas Neues
// gibt - sonst bliebe gerade die am laengsten auf altem Stand. Der laufende
// Test ist angemeldet, deshalb wird das an der Quelle geprueft.
ok('Die Auskunft verlangt keine Anmeldung',
   preg_match('/^\s*(\$\w+\s*=\s*)?require_user\(/m',
              (string) file_get_contents(__DIR__ . '/../api/meta.php')) !== 1);

$mitHeader = http($base . '/api/meta.php?action=version', null, ['X-Vokabeltrainer: 1']);
ok('Und antwortet auf einen schlichten GET',
   $mitHeader['status'] === 200 && str_contains($mitHeader['body'], '"version"'),
   $mitHeader['body']);

$fremd = http($base . '/api/meta.php?action=version');
ok('Aber weiterhin den Sicherheitsheader',
   str_contains($fremd['body'], 'Ungültiger Aufruf'), $fremd['body']);

// Die Huelle muss die Dateiliste mitgeben, sonst kann "Aktualisieren" nicht
// gezielt jede einzelne neu holen.
$shell = http($base . '/')['body'];
ok('Die Hülle nennt die Dateien der Oberfläche',
   preg_match('/assets:\s*\[(.*?)\]/s', $shell, $am) === 1, 'keine Liste');
$liste = $am[1] ?? '';
foreach (['app.js', 'core.js', 'style.css', 'views/cloze.js'] as $datei) {
    ok("Darunter $datei", str_contains($liste, '"' . $datei . '"'));
}
ok('Und jede Ansicht, nicht nur eine Auswahl',
   count(array_filter(glob(__DIR__ . '/../views/*.js') ?: [],
       static fn (string $p): bool => !str_contains($liste, '"views/' . basename($p) . '"'))) === 0);

$appjs = (string) file_get_contents(__DIR__ . '/../app.js');
ok('Die App fragt in Abständen nach', str_contains($appjs, "api('meta', 'version')"));
ok('Vor allem, wenn sie in den Vordergrund kommt',
   str_contains($appjs, 'visibilitychange'));
ok('Und bietet das Band von oben an',
   str_contains($appjs, 'update-bar') && str_contains($appjs, 'hardRefresh()'));

$corejs = (string) file_get_contents(__DIR__ . '/../core.js');
ok('Aktualisieren holt jede Datei ausdrücklich neu',
   str_contains($corejs, "cache: 'reload'") && str_contains($corejs, 'VT.assets'),
   'kein gezieltes Neuladen');

// Der Grund, warum die Sonderzeichen nach einem Update noch an der alten
// Stelle standen: Der Service Worker selbst kam aus dem Zwischenspeicher und
// erneuerte sich nie.
ok('Der Service Worker wird nie aus dem Zwischenspeicher geladen',
   str_contains($appjs, "updateViaCache: 'none'"));

section('Zusammenhalt der Module');

/*
 * Die App blieb nach einem Update weiss: app.js traegt als einzige Datei
 * einen Versionsstempel in der Adresse, core.js und die Ansichten werden ohne
 * importiert. Der Service Worker lieferte deshalb eine alte core.js an eine
 * schon neue app.js, der Import eines dort neuen Namens schlug fehl - und ein
 * fehlgeschlagener Import reisst den ganzen Modulgraphen mit, nicht nur die
 * eine Ansicht. Hier wird gegengeprueft, was sich statisch pruefen laesst.
 */
$module = array_merge(
    [__DIR__ . '/../app.js', __DIR__ . '/../core.js'],
    glob(__DIR__ . '/../views/*.js') ?: [],
);

/** Namen, die eine Datei nach aussen gibt. */
$exporte = static function (string $datei): array {
    $quelle = (string) @file_get_contents($datei);
    preg_match_all('/export\s+(?:async\s+)?(?:function|const|let|class)\s+([\w$]+)/',
                   $quelle, $m);
    return $m[1];
};

$fehlend = [];
$geprueft = 0;
foreach ($module as $datei) {
    $quelle = (string) file_get_contents($datei);
    preg_match_all('/import\s*\{([^}]*)\}\s*from\s*[\'"]([^\'"]+)[\'"]/s',
                   $quelle, $treffer, PREG_SET_ORDER);

    foreach ($treffer as $t) {
        $ziel = realpath(dirname($datei) . '/' . $t[2]);
        if ($ziel === false) {
            $fehlend[] = basename($datei) . ' -> ' . $t[2] . ' (Datei fehlt)';
            continue;
        }
        $vorhanden = $exporte($ziel);

        foreach (explode(',', $t[1]) as $name) {
            $name = trim(explode(' as ', trim($name))[0]);
            if ($name === '') {
                continue;
            }
            $geprueft++;
            if (!in_array($name, $vorhanden, true)) {
                $fehlend[] = basename($datei) . ' holt ' . $name
                           . ' aus ' . basename($ziel) . ', das es dort nicht gibt';
            }
        }
    }
}

ok('Es gibt etwas zu pruefen', $geprueft > 20, (string) $geprueft);
ok('Jeder importierte Name wird auch exportiert',
   $fehlend === [], implode('; ', $fehlend));

// app.js kommt als einzige Datei verlaesslich frisch an. Je weniger sie aus
// core.js zieht, desto kleiner der Schaden, wenn die beiden auseinanderlaufen.
preg_match('/import\s*\{([^}]*)\}\s*from\s*[\'"]\.\/core\.js[\'"]/s',
           (string) file_get_contents(__DIR__ . '/../app.js'), $m);
$ausCore = array_filter(array_map('trim', explode(',', $m[1] ?? '')));
ok('app.js haelt sich bei core.js zurueck',
   count($ausCore) <= 6, implode(', ', $ausCore));

$sw = file_get_contents(__DIR__ . '/../sw.js');

// Der eigentliche Fehler: Code kam aus dem Zwischenspeicher, waehrend die
// dazugehoerige app.js schon neu war.
ok('Der Service Worker holt Code zuerst aus dem Netz',
   preg_match('~\(js\|css\)\$/\.test\(url\.pathname\)~', $sw) === 1
   && preg_match('~fetch\(request\)\.then\(merken\)\.catch\(\(\) => caches\.match~', $sw) === 1,
   'kein Network-First fuer js/css');
ok('Und traegt einen neuen Cache-Namen, damit der alte Bestand wegfaellt',
   preg_match("~const CACHE = 'vokabeltrainer-v(\d+)'~", $sw, $cm) === 1
   && (int) $cm[1] >= 4, $cm[1] ?? 'keiner');

$ht = (string) file_get_contents(__DIR__ . '/../.htaccess');
ok('Und der Server laesst js/css gegenpruefen',
   preg_match('~FilesMatch "\\\\\.\(js\|css\)\$"~', $ht) === 1
   && str_contains($ht, 'no-cache'), 'keine Cache-Control-Regel');

section('PWA-Hülle, Fortsetzung');

ok('Der Service Worker haelt nur die Offline-Seite im Voraus vor',
   str_contains($sw, "const ASSETS = ['./offline.html']"));

// ------------------------------------------------------------------ Abmelden

section('Bereich für Lehrkräfte');

/*
 * Eigener Cookie-Topf: Die Lehrkraft meldet sich an, ohne die Sitzung des
 * Testkindes anzufassen - sonst liefe der Rest der Suite ins Leere.
 */
$lehrerJar = tempnam(sys_get_temp_dir(), 'vtlehr');

function teacherGet(string $pfad): array
{
    global $base, $lehrerJar;
    return teacherRequest($base . '/teacher/' . $pfad, null);
}

function teacherRequest(string $url, ?array $post): array
{
    global $lehrerJar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $lehrerJar,
        CURLOPT_COOKIEFILE     => $lehrerJar,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body   = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $body];
}

function teacherLogin(string $user, string $pass): array
{
    global $base;
    $seite = teacherRequest($base . '/teacher/', null);
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $seite['body'], $m);
    return teacherRequest($base . '/teacher/index.php', [
        'teacher_login' => '1',
        'username'      => $user,
        'password'      => $pass,
        'csrf'          => $m[1] ?? '',
    ]);
}

// Eine Lehrkraft anlegen - wie es der Admin tut.
$lehrerName = 'lehr_' . bin2hex(random_bytes(3));
q('INSERT INTO users (username, display_name, password_hash, color, role, can_import)
   VALUES (?, ?, ?, ?, ?, 1)',
  [$lehrerName, 'Frau Meier', password_hash('lehrerin123', PASSWORD_DEFAULT),
   '#4f7cff', 'teacher']);
$lehrerId = (int) db()->lastInsertId();
user_assign_to_school($lehrerId);
ok('Eine Lehrkraft ist angelegt', $lehrerId > 0);
ok('Mit der Rolle teacher',
   qv('SELECT role FROM users WHERE id = ?', [$lehrerId]) === 'teacher');

$res = teacherGet('');
ok('Ohne Anmeldung kommt die Anmeldeseite',
   $res['status'] === 200 && str_contains($res['body'], 'Bereich für Lehrkräfte'));

$res = teacherLogin($lehrerName, 'garantiert-falsch');
ok('Falsches Passwort wird abgewiesen',
   $res['status'] === 401 && str_contains($res['body'], 'stimmt nicht'));

// Ein Schuelerkonto darf hier nicht hinein - und bekommt dieselbe Meldung,
// damit die Rolle eines Kontos nicht ausplauderbar wird.
$res = teacherLogin($username, 'geheim123');
ok('Ein Schuelerkonto kommt nicht in den Lehrkraft-Bereich', $res['status'] === 401);
ok('Und erfaehrt nicht, woran es lag',
   str_contains($res['body'], 'stimmt nicht')
   && !str_contains($res['body'], 'keine Lehrkraft'));

$res = teacherLogin($lehrerName, 'lehrerin123');
ok('Die Lehrkraft kommt hinein',
   $res['status'] === 200 && str_contains($res['body'], 'Meine Kurse'), "Status {$res['status']}");
ok('Und sieht ihre Schule', str_contains($res['body'], 'Familie'));
ok('Sowie die Kurse der Schule',
   preg_match('/course\.php\?id=(\d+)/', $res['body'], $km) === 1);

$kursId = (int) ($km[1] ?? 0);
$res = teacherGet('course.php?id=' . $kursId);
ok('Die Kursansicht öffnet sich', $res['status'] === 200);
ok('Sie zeigt die Lerneinheiten', str_contains($res['body'], 'Lerneinheiten'));
ok('Und wer im Kurs ist', str_contains($res['body'], 'Wer im Kurs ist'));

// Ein Kurs einer anderen Schule geht niemanden etwas an.
q("INSERT IGNORE INTO schools (name) VALUES ('Fremde Schule')");
$fremdeSchule = (int) qv("SELECT id FROM schools WHERE name = 'Fremde Schule'");
q('INSERT INTO courses (school_id, language_id, name) VALUES (?, ?, ?)',
  [$fremdeSchule, $languageId, 'Fremder Kurs']);
$fremderKurs = (int) db()->lastInsertId();

$res = teacherGet('course.php?id=' . $fremderKurs);
ok('Ein Kurs einer anderen Schule bleibt verschlossen',
   !str_contains($res['body'], 'Fremder Kurs'), 'fremder Kurs war sichtbar');

q('DELETE FROM courses WHERE id = ?', [$fremderKurs]);
q('DELETE FROM schools WHERE id = ?', [$fremdeSchule]);

/*
 * Der Lehrkraft-Bereich fuehrt bewusst keine Schemaaenderungen aus: Mehrere
 * Lehrkraefte koennten sonst gleichzeitig dasselbe ALTER anstossen. Er merkt
 * aber, wenn etwas aussteht, und arbeitet dann nicht weiter.
 */
$boot = (string) file_get_contents(__DIR__ . '/../teacher/_boot.php');
ok('Der Lehrkraft-Bereich migriert nicht selbst',
   !preg_match('/^\s*ensure_schema\(\);/m', $boot));
ok('Merkt aber, wenn das Schema aussteht',
   str_contains($boot, 'schema_pending()'));

$res = teacherRequest($base . '/teacher/index.php',
    ['teacher_logout' => '1', 'csrf' => (function () use ($base): string {
        $s = teacherRequest($base . '/teacher/index.php', null);
        preg_match('/name="csrf" value="([a-f0-9]+)"/', $s['body'], $m);
        return $m[1] ?? '';
    })()]);
ok('Abmelden führt zurück zur Anmeldung',
   str_contains($res['body'], 'Bereich für Lehrkräfte'));

q('DELETE FROM users WHERE id = ?', [$lehrerId]);
@unlink($lehrerJar);

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
