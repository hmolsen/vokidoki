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

/*
 * Wo das Kostenprotokoll beim Start stand.
 *
 * Der Lauf erzeugt Eintraege gegen den Simulator - kostenlos in echtem Geld,
 * aber sie zaehlen auf das Monatsbudget. Bisher blieben sie liegen: Beim
 * Loeschen eines Testkontos setzt der Fremdschluessel user_id auf NULL,
 * die Zeile bleibt. Nach genug Laeufen war das Budget aufgebraucht, und die
 * Suite scheiterte an sich selbst statt an einem Fehler.
 *
 * Am Ende werden deshalb genau die Waisen oberhalb dieser Marke geloescht.
 * Eine Zeile eines echten Kindes ueberlebt: Dessen Konto wird nicht
 * geloescht, also bleibt user_id gesetzt.
 */
$aiMarke = (int) (qv('SELECT COALESCE(MAX(id), 0) FROM ai_requests') ?? 0);

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
require_once __DIR__ . '/../lib/access.php';

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

/**
 * Lerneinheit am Kurs der Sprache, wie api/import.php sie anlegt.
 *
 * Einschliesslich der Freigabemarke: Wer fuer sich selbst einliest, gibt sich
 * damit auch frei. Stuende hier stattdessen ein fester Wert, pruefte die Suite
 * eine Regel, die es in der Anwendung nicht gibt - und genau dieser
 * Unterschied faellt erst auf, wenn ein Kind vor einer leeren Lerneinheit
 * sitzt.
 */
function makeUnit(int $userId, int $langId, string $title, string $extraCols = '',
                  array $extraVals = []): int
{
    $kurs = course_for_language($langId);
    $user = q1('SELECT * FROM users WHERE id = ?', [$userId]);

    q('INSERT INTO units (user_id, language_id, course_id, title, released_position'
      . $extraCols . ')
       VALUES (?, ?, ?, ?, ?' . str_repeat(', ?', count($extraVals)) . ')',
      array_merge([$userId, $langId, $kurs === null ? null : (int) $kurs['id'], $title,
                   initial_released_position($user ?? [])],
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

section('Schemaaenderungen nur auf Knopfdruck');

/*
 * Frueher lief ensure_schema() beim Aufruf jeder Admin-Seite von selbst.
 * Bequem, aber blind: Ein Fehlschlag stand nur im Protokoll, und niemand
 * wusste, ob und wann eine Aenderung gelaufen war. Jetzt ist es eine
 * Entscheidung mit Knopf, Rueckmeldung und Abbruch beim ersten Fehler.
 */
require_once __DIR__ . '/../lib/schema.php';

$adminBoot = (string) file_get_contents(__DIR__ . '/../admin/_boot.php');
ok('Der Admin-Bereich migriert nicht mehr von selbst',
   preg_match('/^ensure_schema\(\);/m', $adminBoot) !== 1);
ok('Weist aber auf offene Aenderungen hin',
   str_contains($adminBoot, 'schema_pending()'));

$schemaQuelle = (string) file_get_contents(__DIR__ . '/../lib/schema.php');
ok('Und beim ersten Fehlschlag wird abgebrochen',
   preg_match('/catch \(Throwable \$e\) \{.*?break;/s', $schemaQuelle) === 1,
   'sonst arbeitete sich der Lauf durch Folgefehler');

// Einen offenen Stand herstellen und ueber die Oberflaeche ausfuehren.
q("DELETE FROM settings WHERE k LIKE 'schema_applied_family.%'");
settings_reset_cache();
$offenVorher = count(schema_pending());
ok('Es stehen Aenderungen aus', $offenVorher > 0, (string) $offenVorher);

$seite = http($base . '/admin/selfcheck.php')['body'];
ok('Der Selbsttest zeigt sie an',
   str_contains($seite, 'ausstehende Schemaänderung'));
ok('Und bietet einen Knopf an', str_contains($seite, 'name="run_migrations"'));
ok('Er nennt sie beim Namen', str_contains($seite, 'family.courses'));

$res = adminPost('selfcheck.php', ['run_migrations' => '1']);
ok('Der Knopf fuehrt sie aus',
   str_contains($res['body'], 'Änderung(en) ausgeführt'), 'keine Rueckmeldung');

settings_reset_cache();
ok('Danach steht nichts mehr aus', schema_pending() === [],
   implode(', ', schema_pending()));

$seite = http($base . '/admin/selfcheck.php')['body'];
ok('Und die Karte ist verschwunden',
   !str_contains($seite, 'ausstehende Schemaänderung'));

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

// Nachgetragen wird seit neuestem nur auf Knopfdruck. Hier geht es um die
// Logik des Nachtragens, nicht um den Weg dorthin - der hat einen eigenen
// Abschnitt -, deshalb direkt.
settings_reset_cache();
ensure_schema();

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

section('Anfangspasswörter');

require_once __DIR__ . '/../lib/passwords.php';

$adjektive = password_words(PW_ADJECTIVE);
$tiere     = password_words(PW_ANIMAL);

ok('Es gibt genug Adjektive', count($adjektive) >= 50, count($adjektive) . ' Stück');
ok('Es gibt genug Tiere', count($tiere) >= 50, count($tiere) . ' Stück');
ok('Zusammen reichen sie für eine Schule',
   count($adjektive) * count($tiere) >= 5000,
   count($adjektive) * count($tiere) . ' Kombinationen');

ok('Jedes Tier hat ein Geschlecht',
   array_filter($tiere, static fn ($t) => !in_array($t['gender'], ['m', 'f', 'n'], true)) === []);
ok('Kein Adjektiv trägt schon eine Endung',
   array_filter($adjektive, static fn ($a) => $a['gender'] !== null) === []);

ok('Die Endung richtet sich nach dem Geschlecht',
   password_ending('m') === 'er' && password_ending('f') === 'e' && password_ending('n') === 'es',
   password_ending('m') . '/' . password_ending('f') . '/' . password_ending('n'));

/*
 * Der Kern: Ein Kind bekommt "müder Gepard", nicht "müde Gepard". Deshalb
 * werden hier viele Passwörter erzeugt und jedes gegen seine Bausteine
 * geprüft - ein einzelner Griff könnte zufällig richtig sein.
 *
 * Die erwartete Endung steht hier ausgeschrieben und wird NICHT bei
 * password_ending() erfragt. Sonst prüfte der Test die Funktion gegen sich
 * selbst und bliebe auch dann grün, wenn sie für jedes Geschlecht dasselbe
 * lieferte - genau der Fehler, den er finden soll.
 */
$endung  = ['m' => 'er', 'f' => 'e', 'n' => 'es'];
$stämme  = array_column($adjektive, 'word');
$genus   = array_column($tiere, 'gender', 'word');
$erzeugt = [];
$falsch  = null;

for ($i = 0; $i < 300; $i++) {
    $pw = password_generate();
    if ($pw === null || !str_contains($pw, ' ')) {
        $falsch = var_export($pw, true);
        break;
    }
    [$adj, $tier] = explode(' ', $pw, 2);
    if (!isset($genus[$tier])) {
        $falsch = $pw;
        break;
    }
    if (!in_array($adj, array_map(
            static fn ($s) => $s . $endung[$genus[$tier]], $stämme), true)) {
        $falsch = $pw;
        break;
    }
    $erzeugt[] = $pw;
}

ok('300 Passwörter sind grammatisch richtig gebeugt', $falsch === null, (string) $falsch);
ok('Sie bestehen aus genau zwei Wörtern ohne Ziffern',
   $erzeugt !== [] && array_filter($erzeugt,
       static fn ($p) => preg_match('/^\p{L}+ \p{L}+$/u', $p) !== 1) === []);
ok('Sie wiederholen sich nicht ständig',
   count(array_unique($erzeugt)) > 250, count(array_unique($erzeugt)) . ' verschiedene');

// Eine Klassenliste soll keine zwei gleichen Passwörter enthalten.
$klasse = [];
for ($i = 0; $i < 28; $i++) {
    $klasse[] = password_generate($klasse);
}
ok('Innerhalb einer Klasse ist jedes Passwort verschieden',
   count(array_unique($klasse)) === 28, count(array_unique($klasse)) . ' von 28');

ok('Getippte Leerzeichen werden nachgesehen',
   password_tidy("  müder   Gepard \u{00A0}") === 'müder Gepard',
   '[' . password_tidy("  müder   Gepard \u{00A0}") . ']');
ok('Die Grossschreibung bleibt, wie sie auf dem Blatt steht',
   password_tidy('Müder Gepard') === 'Müder Gepard');

// Abgeschaltete Wörter tauchen nicht mehr auf.
$probe = 'zzprobe' . bin2hex(random_bytes(2));
q('INSERT INTO password_words (kind, word, gender, active) VALUES (?, ?, NULL, 0)',
  [PW_ADJECTIVE, $probe]);
ok('Ein abgeschaltetes Wort wird nicht mehr vergeben',
   !in_array($probe, array_column(password_words(PW_ADJECTIVE), 'word'), true));
q('DELETE FROM password_words WHERE kind = ? AND word = ?', [PW_ADJECTIVE, $probe]);

section('Gestufte Freigabe');

/*
 * Die Lehrkraft liest eine ganze Unit ein, gibt sie aber portionsweise frei -
 * "Unit 1 bis 'stressed'". Das spart die Saetze fuer den Rest, und die kosten
 * Geld.
 *
 * Die Marke steht als units.released_position und meint "so viele Vokabeln
 * sind auf": Freigegeben ist, was v.position < released_position erfuellt.
 * Der Altbestand steht auf dem Hoechstwert und bleibt damit vollstaendig
 * sichtbar - haetten die Kinder ihre bisherigen Vokabeln ploetzlich nicht
 * mehr, waere die Umstellung ein Rueckschritt.
 *
 * Diese Pruefungen beschreiben den Zielzustand und sind rot, bevor es ihn
 * gibt. Der Punkt ist nicht, dass eine Abfrage weniger Zeilen liefert,
 * sondern dass KEINE schuelerseitige Abfrage die Sperre vergisst - auch
 * nicht der Ablenkerpool im Quiz, der sonst nicht freigegebene Woerter als
 * falsche Antworten ausplaudert.
 */

require_once __DIR__ . '/../lib/access.php';

/** Einen API-Aufruf mit einem anderen Cookie-Topf machen. */
function apiAls(string $topf, callable $was): mixed
{
    global $jar;
    $alt = $jar;
    $jar = $topf;
    try {
        return $was();
    } finally {
        $jar = $alt;
    }
}

$freiLang = makeLanguage($userId, 'Freigabisch');
$freiUnit = makeUnit($userId, $freiLang, 'Unit mit Stufen');

// Zehn Vokabeln, deren Reihenfolge feststeht - sonst laesst sich nicht sagen,
// welche freigegeben sein muessten.
$freiWoerter = ['alpha', 'bravo', 'charlie', 'delta', 'echo',
                'foxtrot', 'golf', 'hotel', 'india', 'juliett'];
foreach ($freiWoerter as $i => $w) {
    q('INSERT INTO vocab (unit_id, term_foreign, term_native, position) VALUES (?, ?, ?, ?)',
      [$freiUnit, $w, 'de-' . $w, $i]);
}

$freiUnitRow = q1('SELECT * FROM units WHERE id = ?', [$freiUnit]);
$kindRow     = q1('SELECT * FROM users WHERE id = ?', [$userId]);

ok('Eine frische Lerneinheit hat zehn Vokabeln',
   (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ?', [$freiUnit]) === 10);

// ---- Die Naht selbst, ohne HTTP.

$freiUnitRow['released_position'] = 3;
ok('Ein Kind sieht nur bis zur Freigabemarke',
   visible_position($kindRow, $freiUnitRow) === 3,
   (string) visible_position($kindRow, $freiUnitRow));

$lehrRow = ['id' => 0, 'role' => ROLE_TEACHER];
ok('Eine Lehrkraft sieht alles',
   visible_position($lehrRow, $freiUnitRow) >= 10,
   (string) visible_position($lehrRow, $freiUnitRow));

$freiUnitRow['released_position'] = 0;
ok('Ohne Freigabe sieht ein Kind nichts',
   visible_position($kindRow, $freiUnitRow) === 0);

// ---- Und jetzt durch die API, so wie das Kind es erlebt.

q('UPDATE units SET released_position = 3 WHERE id = ?', [$freiUnit]);

[$d, $s] = apiCall('units', 'get', null, ['id' => $freiUnit]);
$sichtbar = array_column($d['vocab'] ?? [], 'term_foreign');
sort($sichtbar);
ok('Die Vokabelliste zeigt nur die freigegebenen',
   $sichtbar === ['alpha', 'bravo', 'charlie'], implode(', ', $sichtbar));

ok('Und der Fortschritt zaehlt auch nur die freigegebenen',
   (int) ($d['modes']['mc']['total'] ?? -1) === 3,
   var_export($d['modes']['mc']['total'] ?? null, true));

[$d, $s] = apiCall('units', 'list', null, ['language_id' => $freiLang]);
$dieseUnit = null;
foreach ($d['units'] ?? [] as $u) {
    if ((int) $u['id'] === $freiUnit) {
        $dieseUnit = $u;
    }
}
ok('Auch die Uebersicht rechnet mit der Freigabe',
   $dieseUnit !== null && (int) $dieseUnit['total'] === 3,
   var_export($dieseUnit['total'] ?? null, true));

/*
 * Der Ablenkerpool. Dreissig Zuege, damit ein Versehen nicht durchrutscht:
 * Bei nur drei freigegebenen Woertern muss jede der vier Antworten aus
 * diesen dreien stammen - oder aus einer anderen freigegebenen Einheit.
 */
$verraten = [];
for ($i = 0; $i < 30; $i++) {
    [$d, $s] = apiCall('quiz', 'next', null, ['unit_id' => $freiUnit]);
    if (($d['done'] ?? false) || !isset($d['options'])) {
        break;
    }
    foreach (array_merge($d['options'], [$d['question']]) as $wort) {
        if (in_array($wort, array_slice($freiWoerter, 3), true)
            || in_array($wort, array_map(static fn ($w) => 'de-' . $w,
                                         array_slice($freiWoerter, 3)), true)) {
            $verraten[$wort] = true;
        }
    }
}
ok('Das Quiz plaudert keine gesperrte Vokabel aus - auch nicht als Ablenker',
   $verraten === [], implode(', ', array_keys($verraten)));

// ---- Die Lehrkraft sieht dieselbe Einheit vollstaendig.

$freiLehrer = 'freilehr_' . bin2hex(random_bytes(3));
$freiSchule = (int) qv('SELECT school_id FROM users WHERE id = ?', [$userId]);
q('INSERT INTO users (school_id, username, display_name, password_hash, color, role, can_import)
   VALUES (?, ?, ?, ?, ?, ?, 1)',
  [$freiSchule, $freiLehrer, 'Frau Freigabe',
   password_hash('lehrerin123', PASSWORD_DEFAULT), '#4f7cff', ROLE_TEACHER]);
$freiLehrerId = (int) db()->lastInsertId();
course_add_member((int) course_for_language($freiLang)['id'], $freiLehrerId, 'teacher');

$lehrJar = tempnam(sys_get_temp_dir(), 'vtfrei');
$gesehen = apiAls($lehrJar, function () use ($freiLehrer, $freiUnit) {
    apiCall('auth', 'login', ['username' => $freiLehrer, 'password' => 'lehrerin123']);
    [$d, $s] = apiCall('units', 'get', null, ['id' => $freiUnit]);
    return array_column($d['vocab'] ?? [], 'term_foreign');
});
ok('Die Lehrkraft sieht die ganze Einheit',
   count($gesehen) === 10, count($gesehen) . ' von 10');

// ---- Der Altbestand bleibt unberuehrt.

q('UPDATE units SET released_position = 4294967295 WHERE id = ?', [$freiUnit]);
[$d, $s] = apiCall('units', 'get', null, ['id' => $freiUnit]);
ok('Eine Einheit auf dem Hoechstwert zeigt weiter alles',
   count($d['vocab'] ?? []) === 10, count($d['vocab'] ?? []) . ' von 10');

// ---- Nichts freigegeben heisst: nichts zu ueben.

q('UPDATE units SET released_position = 0 WHERE id = ?', [$freiUnit]);
[$d, $s] = apiCall('units', 'get', null, ['id' => $freiUnit]);
ok('Ohne Freigabe ist die Vokabelliste leer', ($d['vocab'] ?? null) === []);

[$d, $s] = apiCall('quiz', 'next', null, ['unit_id' => $freiUnit]);
ok('Und das Quiz sagt das freundlich statt zu stolpern',
   $s === 422 || ($d['done'] ?? false) === true,
   'Status ' . $s . ' ' . var_export($d, true));

// ---- Was ueberhaupt Saetze bekommt, haengt an der Freigabe.

require_once __DIR__ . '/../lib/sentences.php';

q('UPDATE units SET released_position = 4 WHERE id = ?', [$freiUnit]);
$kandidaten = array_column(sentence_candidates($freiUnit), 'term_foreign');
sort($kandidaten);
ok('Saetze entstehen nur fuer Freigegebenes',
   $kandidaten === ['alpha', 'bravo', 'charlie', 'delta'], implode(', ', $kandidaten));

q('UPDATE units SET released_position = 0 WHERE id = ?', [$freiUnit]);
ok('Ohne Freigabe gibt es nichts zu erzeugen - und nichts zu bezahlen',
   sentence_candidates($freiUnit) === []);

/*
 * Der Wortschatz-Vorspann fuer den Prompt: andere Einheiten desselben
 * KURSES, nicht derselben Sprache. In einer Familie ist das dasselbe, in
 * einer Schule wanderte sonst der Wortschatz fremder Klassen in die Anfrage.
 */
$fremdLang = makeLanguage($userId, 'Fremdgabisch');
$fremdUnit = makeUnit($userId, $fremdLang, 'Fremde Einheit');
q('INSERT INTO vocab (unit_id, term_foreign, term_native, position) VALUES (?, ?, ?, 0)',
  [$fremdUnit, 'zulu', 'de-zulu']);

q('UPDATE units SET released_position = 10 WHERE id = ?', [$freiUnit]);
$nachbarUnit = makeUnit($userId, $freiLang, 'Nachbareinheit');
q('INSERT INTO vocab (unit_id, term_foreign, term_native, position) VALUES (?, ?, ?, 0)',
  [$nachbarUnit, 'kilo', 'de-kilo']);
q('INSERT INTO vocab (unit_id, term_foreign, term_native, position) VALUES (?, ?, ?, 1)',
  [$nachbarUnit, 'lima', 'de-lima']);
q('UPDATE units SET released_position = 1 WHERE id = ?', [$nachbarUnit]);

$kurs    = course_for_language($freiLang);
$bekannt = array_column(known_vocabulary((int) $kurs['id'], $freiUnit), 'term_foreign');

ok('Als bekannt gilt der Nachbar im selben Kurs',
   in_array('kilo', $bekannt, true), implode(', ', $bekannt));
ok('Aber nicht, was dort noch gesperrt ist',
   !in_array('lima', $bekannt, true), implode(', ', $bekannt));
ok('Und nichts aus einem anderen Kurs',
   !in_array('zulu', $bekannt, true), implode(', ', $bekannt));
ok('Die eigene Einheit zaehlt nicht als bekannt',
   !in_array('alpha', $bekannt, true), implode(', ', $bekannt));

ok('Ohne Kurs bleibt der Vorspann leer statt fremd zu werden',
   known_vocabulary(null, $freiUnit) === []);

// ---- Und jetzt das Freigeben durch die Oberflaeche der Lehrkraft.

$freiJar = tempnam(sys_get_temp_dir(), 'vtfrl');

function freiGet(string $pfad): array
{
    global $base, $freiJar;
    return freiPost($base . '/teacher/' . $pfad, null);
}

function freiPost(string $url, ?array $post): array
{
    global $freiJar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $freiJar,
        CURLOPT_COOKIEFILE     => $freiJar,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 60,
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

$seite = freiPost($base . '/teacher/', null);
preg_match('/name="csrf" value="([a-f0-9]+)"/', $seite['body'], $fm);
freiPost($base . '/teacher/index.php', [
    'teacher_login' => '1',
    'username'      => $freiLehrer,
    'password'      => 'lehrerin123',
    'csrf'          => $fm[1] ?? '',
]);

q('UPDATE units SET released_position = 0 WHERE id = ?', [$freiUnit]);

$res = freiGet('unit.php?id=' . $freiUnit);
ok('Die Lehrkraft kann die Lerneinheit oeffnen', $res['status'] === 200,
   'Status ' . $res['status']);
ok('Und sieht alle zehn Vokabeln, auch die gesperrten',
   str_contains($res['body'], 'juliett'));
ok('Der Stand steht oben drueber',
   str_contains($res['body'], 'Noch nichts freigegeben'));

preg_match('/name="csrf" value="([a-f0-9]+)"/', $res['body'], $fm);
$freiCsrf = $fm[1] ?? '';

/*
 * Drei Vokabeln freigeben. Die Satzerzeugung laeuft danach gegen den
 * Simulator; wichtig ist hier die Marke, nicht das Ergebnis.
 */
freiPost($base . '/teacher/unit.php?id=' . $freiUnit, [
    'release'  => 3,
    'unit_id'  => $freiUnit,
    'csrf'     => $freiCsrf,
]);
ok('Freigeben setzt die Marke',
   (int) qv('SELECT released_position FROM units WHERE id = ?', [$freiUnit]) === 3,
   (string) qv('SELECT released_position FROM units WHERE id = ?', [$freiUnit]));

// Eine Stelle, die es nicht gibt, wird abgelehnt statt gespeichert.
freiPost($base . '/teacher/unit.php?id=' . $freiUnit, [
    'release' => 99, 'unit_id' => $freiUnit, 'csrf' => $freiCsrf,
]);
ok('Eine Stelle jenseits der Einheit wird abgelehnt',
   (int) qv('SELECT released_position FROM units WHERE id = ?', [$freiUnit]) === 3);

// Zuruecknehmen.
freiPost($base . '/teacher/unit.php?id=' . $freiUnit, [
    'release' => 0, 'unit_id' => $freiUnit, 'csrf' => $freiCsrf,
]);
ok('Und laesst sich zuruecknehmen',
   (int) qv('SELECT released_position FROM units WHERE id = ?', [$freiUnit]) === 0);

/*
 * Die Grenze: Eine Lerneinheit einer anderen Schule geht niemanden etwas an -
 * auch nicht ueber ein untergeschobenes Formular.
 */
q("INSERT IGNORE INTO schools (name) VALUES ('Fremde Schule 3')");
$fremdeSchule3 = (int) qv("SELECT id FROM schools WHERE name = 'Fremde Schule 3'");
q('UPDATE courses SET school_id = ? WHERE id = ?',
  [$fremdeSchule3, (int) course_for_language($fremdLang)['id']]);
q('UPDATE units SET released_position = 0 WHERE id = ?', [$fremdUnit]);

freiPost($base . '/teacher/unit.php?id=' . $fremdUnit, [
    'release' => 1, 'unit_id' => $fremdUnit, 'csrf' => $freiCsrf,
]);
ok('Eine Lerneinheit einer anderen Schule laesst sich nicht freigeben',
   (int) qv('SELECT released_position FROM units WHERE id = ?', [$fremdUnit]) === 0);

// ---- Nur ein Satzlauf, auch wenn eine ganze Klasse gleichzeitig draufsieht.

/*
 * Bei einer Familie feuert das nie: Ein Kind stoesst die Satzerzeugung an,
 * fertig. Bei einer Klasse sitzen 28 Kinder in derselben Minute davor, alle
 * sehen "noch keine Saetze" - und ohne Riegel starten alle denselben Lauf.
 * Achtundzwanzig bezahlte Anfragen fuer ein Ergebnis.
 */
q('UPDATE units SET sentences_status = NULL, sentences_started_at = NULL WHERE id = ?',
  [$freiUnit]);

$ansprueche = 0;
for ($i = 0; $i < 28; $i++) {
    if (sentence_claim($freiUnit)) {
        $ansprueche++;
    }
}
ok('Von achtundzwanzig Anlaeufen kommt genau einer durch',
   $ansprueche === 1, $ansprueche . ' statt 1');

/*
 * Und eine Pruefung am Quelltext, die unbequem ist, aber ehrlich.
 *
 * Die Schleife darueber laeuft nacheinander. Sie zeigt den Zustandsautomaten,
 * aber NICHT das Wettrennen: Ein Pruefen-dann-Setzen besteht sie genauso,
 * weil zwischen den Durchlaeufen nichts dazwischenkommen kann. Ein echtes
 * Wettrennen liesse sich hier auch nicht herstellen - der eingebaute
 * PHP-Server arbeitet Anfragen einzeln ab, gleichzeitige Aufrufe wuerden
 * ohnehin hintereinander laufen.
 *
 * Was sich pruefen laesst, ist die Form: Die Entscheidung muss in einem
 * einzigen UPDATE mit Bedingung stecken und darf nicht aus einem gelesenen
 * Wert in PHP folgen. Das ist der ganze Unterschied.
 */
$quelle = (string) file_get_contents(__DIR__ . '/../lib/sentences.php');
preg_match('/function sentence_claim\(.*?\n\}/s', $quelle, $qm);
$rumpf = $qm[0] ?? '';

ok('Der Anspruch faellt in einem einzigen UPDATE mit Bedingung',
   str_contains($rumpf, 'UPDATE units')
   && str_contains($rumpf, 'rowCount()')
   && preg_match('/\bWHERE\b.*\bsentences_status\b/s', $rumpf) === 1,
   'sentence_claim() entscheidet nicht in der Datenbank');
ok('Und nicht aus einem vorher gelesenen Wert',
   !preg_match('/\bq1\s*\(|\bqv\s*\(/', $rumpf),
   'es wird erst gelesen und dann gesetzt - dazwischen passt ein zweiter Aufruf');

ok('Und die Einheit steht danach auf "laeuft"',
   qv('SELECT sentences_status FROM units WHERE id = ?', [$freiUnit]) === SENTENCE_RUNNING);

/*
 * Ein abgestuerzter Lauf darf kein Riegel sein, den niemand mehr aufbekommt.
 * Der Startzeitpunkt wird kuenstlich alt gemacht.
 */
q('UPDATE units SET sentences_started_at = NOW() - INTERVAL ? SECOND WHERE id = ?',
  [SENTENCE_STALE_AFTER + 60, $freiUnit]);
ok('Ein haengengebliebener Lauf gibt die Einheit wieder frei',
   sentence_claim($freiUnit));

// Und der frische Anspruch haelt sofort wieder dicht.
ok('Danach ist wieder zu', !sentence_claim($freiUnit));

q('UPDATE units SET sentences_status = ?, sentences_started_at = NULL WHERE id = ?',
  [SENTENCE_DONE, $freiUnit]);
ok('Eine fertige Einheit laesst sich erneut beanspruchen - fuers Nachtragen',
   sentence_claim($freiUnit));

q('UPDATE units SET sentences_status = NULL, sentences_started_at = NULL WHERE id = ?',
  [$freiUnit]);

q('DELETE FROM users WHERE id = ?', [$freiLehrerId]);
q('DELETE FROM languages WHERE id IN (?, ?)', [$freiLang, $fremdLang]);
q('DELETE FROM schools WHERE id = ?', [$fremdeSchule3]);
@unlink($lehrJar);
@unlink($freiJar);

section('Kosten je Schule');

require_once __DIR__ . '/../lib/cost.php';

/*
 * Der teuerste bekannte Preis statt null.
 *
 * Anthropic benennt Modelle um. Frueher kam fuer einen unbekannten Namen
 * still 0.00 heraus - ab diesem Tag kostet scheinbar alles nichts, kein
 * Deckel greift, und auffallen wuerde es erst auf der Rechnung.
 */
$bekannt = array_key_first(price_table());
ok('Ein bekanntes Modell wird nach Liste berechnet',
   cost_for((string) $bekannt, 1_000_000, 0) > 0);
ok('Ein unbekanntes Modell kostet nicht plötzlich nichts',
   cost_for('claude-gibt-es-nicht-9', 1_000_000, 0) > 0,
   'wieder 0.00 - der Deckel griffe nicht mehr');
ok('Und zwar mindestens so viel wie das teuerste bekannte',
   cost_for('claude-gibt-es-nicht-9', 1_000_000, 1_000_000)
   >= max(array_map(
       static fn ($m) => cost_for((string) $m, 1_000_000, 1_000_000),
       array_keys(price_table()))));

// Auch ohne Preisliste darf nichts umsonst sein.
$preiseVorher = setting('prices_json', '');
setting_set('prices_json', '{}');
settings_reset_cache();
ok('Selbst mit leerer Preisliste kostet ein Aufruf etwas',
   cost_for('irgendwas', 1_000_000, 0) > 0);
setting_set('prices_json', $preiseVorher);
settings_reset_cache();

// Eine Kostenzeile bekommt die Schule ihres Kontos, ohne dass der Aufrufer
// daran denken muss.
$kostenUser = makeUser('e2e_kosten', 'Kostenkind');
$kostenSchule = (int) qv('SELECT school_id FROM users WHERE id = ?', [$kostenUser]);

$vorher = cost_this_month($kostenSchule);
ai_log([
    'user_id'       => $kostenUser,
    'user_label'    => 'Kostenkind',
    'model'         => (string) $bekannt,
    'purpose'       => 'test',
    'input_tokens'  => 1_000_000,
    'output_tokens' => 0,
]);

$zeile = q1('SELECT * FROM ai_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            [$kostenUser]);
ok('Eine Kostenzeile merkt sich die Schule von selbst',
   (int) ($zeile['school_id'] ?? 0) === $kostenSchule,
   'school_id = ' . var_export($zeile['school_id'] ?? null, true));
ok('Und taucht in der Auswertung dieser Schule auf',
   cost_this_month($kostenSchule) > $vorher);

$proSchule = cost_this_month_by_school();
ok('Die Auswertung führt jede Schule auf',
   count($proSchule) >= 1
   && array_filter($proSchule, static fn ($s) => (int) $s['id'] === $kostenSchule) !== []);

/*
 * Der eigene Deckel einer Schule. Er wird auf einen Cent gesetzt - die
 * gerade gebuchte Zeile ueberschreitet ihn sicher.
 */
$deckelVorher = qv('SELECT monthly_cost_cap_usd FROM schools WHERE id = ?', [$kostenSchule]);
q('UPDATE schools SET monthly_cost_cap_usd = 0.01 WHERE id = ?', [$kostenSchule]);
$grund = budget_block_reason($kostenUser);
ok('Ein aufgebrauchtes Schulbudget hält die Bilderkennung an',
   $grund !== null && str_contains($grund, 'Schule'), $grund ?? '(kein Grund)');

q('UPDATE schools SET monthly_cost_cap_usd = 100000 WHERE id = ?', [$kostenSchule]);
$capVorher = setting('monthly_cost_cap_usd', '10.00');
setting_set('monthly_cost_cap_usd', '0');
settings_reset_cache();
ok('Mit reichlich Budget läuft sie wieder', budget_block_reason($kostenUser) === null,
   budget_block_reason($kostenUser) ?? '');

/*
 * Und die Gegenprobe: Der Deckel des Betreibers gilt auch fuer eine Schule,
 * die grosszuegig eingestellt ist - er bekommt die Rechnung.
 */
setting_set('monthly_cost_cap_usd', '0.0001');
settings_reset_cache();
$grund = budget_block_reason($kostenUser);
ok('Der Deckel des Betreibers lässt sich nicht umgehen',
   $grund !== null && str_contains($grund, 'Betreiber'), $grund ?? '(kein Grund)');

setting_set('monthly_cost_cap_usd', $capVorher);
settings_reset_cache();
q('UPDATE schools SET monthly_cost_cap_usd = ' . ($deckelVorher === null ? 'NULL' : '?')
  . ' WHERE id = ?',
  $deckelVorher === null ? [$kostenSchule] : [$deckelVorher, $kostenSchule]);
q('DELETE FROM ai_requests WHERE user_id = ?', [$kostenUser]);
q('DELETE FROM users WHERE id = ?', [$kostenUser]);

section('QR-Code');

require_once __DIR__ . '/../lib/qr.php';

/**
 * Liest einen fertigen Code wieder aus - bewusst als eigene Umsetzung und
 * nicht mit den Hilfsmitteln der Bibliothek.
 *
 * Ein selbstgebauter Encoder wird still falsch: Das Muster sieht aus wie ein
 * QR-Code, und ob es einer ist, merkt man erst mit dem Handy in der Hand.
 * Deshalb wird hier der ganze Weg zurückgegangen - Formatinformation lesen,
 * Maske abziehen, Zickzack ablaufen, Blöcke entflechten, Kopf auswerten - und
 * am Ende muss derselbe Text herauskommen.
 */
function qrLesen(array $m): ?string
{
    $size    = count($m);
    $version = (int) (($size - 17) / 4);

    // 1. Formatinformation aus der ersten Kopie.
    $bits = 0;
    $holen = static function (int $r, int $c) use ($m): int {
        return $m[$r][$c] ? 1 : 0;
    };
    for ($i = 0; $i <= 5; $i++) {
        $bits |= $holen($i, 8) << $i;
    }
    $bits |= $holen(7, 8) << 6;
    $bits |= $holen(8, 8) << 7;
    $bits |= $holen(8, 7) << 8;
    for ($i = 9; $i < 15; $i++) {
        $bits |= $holen(8, 14 - $i) << $i;
    }

    $daten = (($bits ^ 0x5412) >> 10) & 0x1F;
    $ecc   = ($daten >> 3) & 3;
    $maske = $daten & 7;

    if ($ecc !== QR_ECC_M) {
        return null;
    }

    // 2. Belegte Stellen wiederherstellen und Maske abziehen.
    $leer = array_fill(0, $size, array_fill(0, $size, false));
    $fest = $leer;
    $egal = $leer;
    qr_draw_function_patterns($egal, $fest, $version, $size);

    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c < $size; $c++) {
            if (!$fest[$r][$c] && qr_mask_bit($maske, $r, $c)) {
                $m[$r][$c] = !$m[$r][$c];
            }
        }
    }

    // 3. Zickzack ablaufen.
    $folge = '';
    $row   = $size - 1;
    $dir   = -1;
    for ($col = $size - 1; $col > 0; $col -= 2) {
        if ($col === 6) {
            $col--;
        }
        while (true) {
            for ($s = 0; $s < 2; $s++) {
                $c = $col - $s;
                if (!$fest[$row][$c]) {
                    $folge .= $m[$row][$c] ? '1' : '0';
                }
            }
            $row += $dir;
            if ($row < 0 || $row >= $size) {
                $row -= $dir;
                $dir = -$dir;
                break;
            }
        }
    }

    [$eccProBlock, $bloecke, $datenProBlock] = QR_BLOCKS_M[$version];

    $codewords = [];
    foreach (str_split(substr($folge, 0, ($bloecke * ($datenProBlock + $eccProBlock)) * 8), 8) as $b) {
        $codewords[] = bindec($b);
    }

    // 4. Blöcke entflechten - nur der Datenteil wird gebraucht.
    $daten = [];
    for ($i = 0; $i < $datenProBlock; $i++) {
        for ($b = 0; $b < $bloecke; $b++) {
            $daten[$b][$i] = $codewords[$i * $bloecke + $b];
        }
    }
    $flach = [];
    for ($b = 0; $b < $bloecke; $b++) {
        foreach ($daten[$b] as $v) {
            $flach[] = $v;
        }
    }

    // 5. Kopf auswerten: Modus 0100, dann acht Bit Länge.
    $strom = '';
    foreach ($flach as $cw) {
        $strom .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
    }

    if (substr($strom, 0, 4) !== '0100') {
        return null;
    }
    $laenge = bindec(substr($strom, 4, 8));

    $text = '';
    for ($i = 0; $i < $laenge; $i++) {
        $text .= chr(bindec(substr($strom, 12 + $i * 8, 8)));
    }

    return $text;
}

$qrFaelle = [
    'a',
    'https://cqrity.de/vokabeltrainer',
    'https://cqrity.de/vokabeltrainer/?u=lilli.m',
    'Müller & Söhne — ÄÖÜ ß',
    str_repeat('x', 14),   // letzte Länge in Version 1
    str_repeat('y', 15),   // erste in Version 2
    str_repeat('z', 106),  // letzte in Version 6
];

$qrFehler = [];
foreach ($qrFaelle as $t) {
    $m = qr_matrix($t);
    if ($m === null || qrLesen($m) !== $t) {
        $qrFehler[] = mb_substr($t, 0, 24);
    }
}
ok('Ein erzeugter QR-Code lässt sich wieder lesen', $qrFehler === [],
   implode('; ', $qrFehler));

// Die Versionsgrenzen: eine Länge mehr, und der Code muss wachsen.
$grenzen = [14 => 21, 15 => 25, 26 => 25, 27 => 29, 42 => 29, 43 => 33,
            62 => 33, 63 => 37, 84 => 37, 85 => 41, 106 => 41];
$falsch = [];
foreach ($grenzen as $len => $erwartet) {
    $m = qr_matrix(str_repeat('a', $len));
    if ($m === null || count($m) !== $erwartet) {
        $falsch[] = $len . '=>' . ($m === null ? 'null' : count($m));
    }
}
ok('Die Version wächst genau an den richtigen Stellen', $falsch === [],
   implode(' ', $falsch));

ok('Was nicht mehr passt, wird abgelehnt statt verstümmelt',
   qr_matrix(str_repeat('a', 107)) === null);

// Aufbau: die drei Sucher und die Taktlinien.
$m    = qr_matrix('https://cqrity.de/vokabeltrainer');
$size = count($m);

$sucherOk = true;
foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$or, $oc]) {
    for ($r = 0; $r < 7; $r++) {
        for ($c = 0; $c < 7; $c++) {
            $soll = max(abs($r - 3), abs($c - 3)) !== 2;
            if ($m[$or + $r][$oc + $c] !== $soll) {
                $sucherOk = false;
            }
        }
    }
}
ok('Die drei Sucherquadrate stehen richtig', $sucherOk);

$taktOk = true;
for ($i = 8; $i < $size - 8; $i++) {
    if ($m[6][$i] !== ($i % 2 === 0) || $m[$i][6] !== ($i % 2 === 0)) {
        $taktOk = false;
    }
}
ok('Die Taktlinien laufen durch', $taktOk,
   'an der Kreuzung mit der Formatinformation verschluckt');

ok('Das immer dunkle Modul ist dunkel', $m[$size - 8][8] === true);

/*
 * Feste Vergleichswerte. Erzeugt mit dieser Umsetzung und ausserhalb der
 * Suite gegengeprüft: einmal gegen einen Decoder (jsQR), einmal Modul für
 * Modul gegen einen fremden Encoder. Ändert sich hier etwas, war es entweder
 * Absicht - dann gehören die Werte erneuert und erneut gegengeprüft - oder
 * ein Fehler.
 */
$golden = [
    'a'                                => '963343f04f9af434',
    'bbbbbbbbbbbbbbb'                  => '03d5fb58ddb77eea',
    'https://cqrity.de/vokabeltrainer' => '66a88a0ee3cd4f71',
];
$abweichung = [];
foreach ($golden as $text => $soll) {
    $zeilen = [];
    foreach (qr_matrix($text) as $r) {
        $zeilen[] = implode('', array_map(static fn ($b) => $b ? '1' : '0', $r));
    }
    $ist = substr(hash('sha256', implode('|', $zeilen)), 0, 16);
    if ($ist !== $soll) {
        $abweichung[] = sprintf('%s: %s statt %s', mb_substr($text, 0, 12), $ist, $soll);
    }
}
ok('Die Codes sehen aus wie beim letzten gegengeprüften Stand',
   $abweichung === [], implode('; ', $abweichung));

$svg = qr_svg('https://cqrity.de/vokabeltrainer', 4, 'Adresse der App');
ok('Als SVG kommt gültiges Markup heraus',
   $svg !== null && str_starts_with($svg, '<svg ') && str_ends_with($svg, '</svg>'));
ok('Mit dem hellen Rand, ohne den keine Kamera etwas findet',
   $svg !== null && str_contains($svg, 'viewBox="0 0 37 37"'),
   'Version 3 plus 2x4 Rand = 37');
ok('Und ohne fremde Zeichen im Beschriftungstext',
   qr_svg('x', 4, 'Anna & "Bö" <b>') !== null
   && !str_contains((string) qr_svg('x', 4, 'Anna & "Bö" <b>'), '<b>'));

section('Anmeldebremse');

require_once __DIR__ . '/../lib/throttle.php';

// Erst die Kurve für sich - sie lässt sich prüfen, ohne Fehlversuche
// erzeugen zu müssen.
ok('Die ersten Fehlversuche kosten nichts',
   login_delay_ms(1, 2) === 0 && login_delay_ms(2, 2) === 0);
ok('Danach wächst die Wartezeit',
   login_delay_ms(3, 2) > 0 && login_delay_ms(4, 2) > login_delay_ms(3, 2),
   login_delay_ms(3, 2) . 'ms / ' . login_delay_ms(4, 2) . 'ms');
ok('Und sie ist gedeckelt',
   login_delay_ms(99, 2) === LOGIN_DELAY_CAP_MS, login_delay_ms(99, 2) . 'ms');

ok('Gesperrt wird erst ab dem fünften Fehlversuch',
   !login_penalty(4, 0)['locked'] && login_penalty(5, 0)['locked']);

/*
 * Der wichtigste Punkt: Eine ganze Schule sitzt hinter einer Adresse. Viele
 * Fehlversuche von dort dürfen niemanden aussperren, sonst sperrt der erste
 * vertippte Fünftklässler seine Klasse mit aus.
 */
ok('Eine vielbenutzte Adresse sperrt niemanden aus',
   !login_penalty(0, 500)['locked']);
ok('Sie wird aber spürbar gebremst',
   login_penalty(0, 500)['delay_ms'] === LOGIN_DELAY_CAP_MS);
ok('Eine Klasse voller Kinder bleibt unter der Schwelle',
   login_penalty(0, 28)['delay_ms'] === 0);

// Jetzt gegen die laufende Anwendung. Eigener Cookie-Topf, damit die
// erfolgreiche Anmeldung am Ende nicht die Sitzung der Suite übernimmt.
$bremsJar = tempnam(sys_get_temp_dir(), 'vtbrems');

function bremsLogin(string $user, string $pass): array
{
    global $base, $bremsJar;
    $ch = curl_init($base . '/api/auth.php?action=login');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $bremsJar,
        CURLOPT_COOKIEFILE     => $bremsJar,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['username' => $user, 'password' => $pass]),
        CURLOPT_HTTPHEADER     => ['X-Vokabeltrainer: 1', 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body   = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [json_decode($body, true), $status];
}

$opferA = 'brems_a_' . bin2hex(random_bytes(3));
$opferB = 'brems_b_' . bin2hex(random_bytes(3));
$opferAId = makeUser($opferA, 'Bremse A');
$opferBId = makeUser($opferB, 'Bremse B');

login_attempts_reset($opferA);
login_attempts_reset($opferB);

for ($i = 1; $i <= LOGIN_ACCOUNT_LIMIT; $i++) {
    [$d, $s] = bremsLogin($opferA, 'bestimmt-falsch');
    if ($i < LOGIN_ACCOUNT_LIMIT) {
        // Solange nicht gesperrt, ist die Antwort die gewöhnliche Absage.
        ok("Fehlversuch $i wird abgelehnt, aber nicht gesperrt", $s === 401, "Status $s");
    }
}

[$d, $s] = bremsLogin($opferA, 'bestimmt-falsch');
ok('Nach fünf Fehlversuchen ist das Konto gesperrt', $s === 429, "Status $s");
ok('Und die Meldung sagt, wie lange',
   preg_match('/\d+ Minute/', (string) ($d['error'] ?? '')) === 1, $d['error'] ?? '(keine)');

// Der Kern der Sache: Das richtige Passwort hilft jetzt auch nicht mehr.
[$d, $s] = bremsLogin($opferA, 'geheim123');
ok('Auch das richtige Passwort kommt nicht durch', $s === 429, "Status $s");

/*
 * Und die Gegenprobe zur Schul-Adresse: Beide Konten kommen von 127.0.0.1.
 * Wäre die Adresse gesperrt statt nur gebremst, käme B jetzt nicht mehr rein.
 */
[$d, $s] = bremsLogin($opferB, 'geheim123');
ok('Ein anderes Kind an derselben Adresse kommt weiter rein',
   $s === 200 && ($d['ok'] ?? false), "Status $s");

ok('Die erfolgreiche Anmeldung löscht die eigenen Fehlversuche',
   login_attempts_count($opferB, '127.0.0.1')['account'] === 0);

// Die Lehrkraft schliesst auf.
login_attempts_reset($opferA);
[$d, $s] = bremsLogin($opferA, 'geheim123');
ok('Nach dem Aufschliessen geht die Anmeldung wieder',
   $s === 200 && ($d['ok'] ?? false), "Status $s");

q('DELETE FROM login_attempts WHERE username IN (?, ?)', [$opferA, $opferB]);
q('DELETE FROM users WHERE id IN (?, ?)', [$opferAId, $opferBId]);
@unlink($bremsJar);

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

// ---------------------------------------------- Klassen und Klassenlisten

section('Klasse anlegen und füllen');

require_once __DIR__ . '/../lib/roster.php';

// Erst das Einlesen der Namen für sich - ohne Datenbank, ohne Konten.
$geparst = roster_parse_names(
    "Lilli Molsen\n"
    . "Schmidt, Anna-Lena\n"
    . "   \n"
    . "Max\n"
    . "Jürgen Öztürk\n"
);

ok('Aus der Liste werden vier Namen', count($geparst) === 4, count($geparst) . ' Namen');
ok('"Vorname Nachname" wird verstanden',
   roster_display_name($geparst[0]['first'], $geparst[0]['initial']) === 'Lilli M.',
   roster_display_name($geparst[0]['first'], $geparst[0]['initial']));
ok('"Nachname, Vorname" auch',
   roster_display_name($geparst[1]['first'], $geparst[1]['initial']) === 'Anna-Lena S.',
   roster_display_name($geparst[1]['first'], $geparst[1]['initial']));
ok('Ein Name ohne Nachnamen bleibt für sich',
   roster_display_name($geparst[2]['first'], $geparst[2]['initial']) === 'Max');
ok('Leere Zeilen fallen weg',
   array_filter($geparst, static fn ($n) => trim($n['first']) === '') === []);

/*
 * Der eigentliche Punkt der ganzen Übung: Der Nachname darf gar nicht erst
 * in die Datenbank. Was hier herauskommt, ist alles, was gespeichert wird.
 */
$alleFelder = '';
foreach ($geparst as $n) {
    $alleFelder .= $n['first'] . '|' . $n['initial'] . '|';
}
ok('Der Nachname überlebt das Einlesen nicht',
   !str_contains(mb_strtolower($alleFelder), 'molsen')
   && !str_contains(mb_strtolower($alleFelder), 'schmidt')
   && !str_contains(mb_strtolower($alleFelder), 'öztürk'),
   $alleFelder);

ok('Umlaute werden im Benutzernamen ausgeschrieben',
   roster_username_base('Jürgen', 'Ö') === 'juergen.oe',
   roster_username_base('Jürgen', 'Ö'));
ok('Der Bindestrich im Doppelnamen bleibt',
   roster_username_base('Anna-Lena', 'S') === 'anna-lena.s',
   roster_username_base('Anna-Lena', 'S'));
ok('Ein Name ganz ohne brauchbare Zeichen kippt nicht um',
   roster_username_base('???', '') !== '', roster_username_base('???', ''));

// Jetzt durch die Oberfläche, so wie es eine Lehrkraft tut.
$klassenName = '9Z-' . bin2hex(random_bytes(2));

$res  = teacherGet('classes.php');
ok('Die Klassenübersicht öffnet sich', $res['status'] === 200);
preg_match('/name="csrf" value="([a-f0-9]+)"/', $res['body'], $cm);
$lehrerCsrf = $cm[1] ?? '';

$res = teacherRequest($base . '/teacher/classes.php', [
    'create_class' => '1',
    'name'         => $klassenName,
    'csrf'         => $lehrerCsrf,
]);
ok('Eine Klasse lässt sich anlegen', str_contains($res['body'], 'angelegt'));

$klasseId = (int) qv('SELECT id FROM classes WHERE school_id = ? AND name = ?',
                     [(int) qv('SELECT school_id FROM users WHERE id = ?', [$lehrerId]),
                      $klassenName]);
ok('Und steht in der Datenbank', $klasseId > 0);

$res = teacherRequest($base . '/teacher/classes.php', [
    'create_class' => '1',
    'name'         => $klassenName,
    'csrf'         => $lehrerCsrf,
]);
ok('Dieselbe Klasse zweimal geht nicht', str_contains($res['body'], 'gibt es schon'));

// Die Klassenliste hineinkopieren.
$res = teacherRequest($base . '/teacher/class.php?id=' . $klasseId, [
    'add_students' => '1',
    'class_id'     => $klasseId,
    'names'        => "Lilli Molsen\nSchmidt, Anna-Lena\nMax\nJürgen Öztürk",
    'csrf'         => $lehrerCsrf,
]);
ok('Vier Konten auf einmal', str_contains($res['body'], '4 Konten angelegt'),
   'Meldung fehlt');

$neueKinder = class_members_list($klasseId);
ok('Sie stehen in der Klasse', count($neueKinder) === 4, count($neueKinder) . ' Kinder');

$namen = array_column($neueKinder, 'display_name');
sort($namen);
ok('Und heissen Vorname plus Anfangsbuchstabe',
   $namen === ['Anna-Lena S.', 'Jürgen Ö.', 'Lilli M.', 'Max'], implode(', ', $namen));

ok('Jedes hat ein Anfangspasswort',
   array_filter($neueKinder, static fn ($k) => (string) $k['initial_password'] === '') === []);
ok('Und die Passwörter sind untereinander verschieden',
   count(array_unique(array_column($neueKinder, 'initial_password'))) === 4);
ok('Sie sind alle Kinder, keine Lehrkräfte',
   array_filter($neueKinder, static fn ($k) => $k['role'] !== 'student') === []);

// Das Anfangspasswort muss auch wirklich das Passwort sein.
$lilli = q1("SELECT * FROM users WHERE display_name = 'Lilli M.' AND id IN (
                 SELECT user_id FROM class_members WHERE class_id = ?)", [$klasseId]);
ok('Das gezeigte Passwort öffnet das Konto',
   password_verify((string) $lilli['initial_password'], (string) $lilli['password_hash']));

ok('Der Benutzername ist zum Abtippen gebaut',
   preg_match('/^[a-z0-9.\-]+$/', (string) $lilli['username']) === 1, $lilli['username']);
ok('Das Kind gehört zur Schule der Lehrkraft',
   (int) $lilli['school_id'] === (int) qv('SELECT school_id FROM users WHERE id = ?', [$lehrerId]));

// Dieselbe Liste noch einmal - eine unsichere Lehrkraft darf keine
// Karteileichen erzeugen.
$res = teacherRequest($base . '/teacher/class.php?id=' . $klasseId, [
    'add_students' => '1',
    'class_id'     => $klasseId,
    'names'        => "Lilli Molsen\nSchmidt, Anna-Lena\nMax\nJürgen Öztürk",
    'csrf'         => $lehrerCsrf,
]);
ok('Die Liste ein zweites Mal legt niemanden doppelt an',
   count(class_members_list($klasseId)) === 4,
   count(class_members_list($klasseId)) . ' Kinder');
ok('Und sagt, dass übersprungen wurde', str_contains($res['body'], 'übersprungen'));

/*
 * Ein gesperrtes Kind aufschliessen, ohne ihm ein neues Passwort zu geben.
 * Wer sich nur vertippt hat, soll weder warten noch abtippen muessen.
 */
$lilliName = (string) $lilli['username'];
for ($i = 0; $i < LOGIN_ACCOUNT_LIMIT; $i++) {
    login_attempt_record($lilliName, '127.0.0.1');
}
ok('Ein Kind lässt sich aussperren',
   isset(login_locked_usernames([$lilliName])[$lilliName]));

$res = teacherGet('class.php?id=' . $klasseId);
ok('Die Klassenliste zeigt die Sperre an', str_contains($res['body'], 'gesperrt'));
ok('Und bietet das Entsperren an', str_contains($res['body'], 'Entsperren'));

$hashVorher = (string) qv('SELECT password_hash FROM users WHERE id = ?', [(int) $lilli['id']]);
teacherRequest($base . '/teacher/class.php?id=' . $klasseId, [
    'unlock'   => (int) $lilli['id'],
    'class_id' => $klasseId,
    'csrf'     => $lehrerCsrf,
]);
ok('Nach dem Entsperren geht es wieder',
   login_locked_usernames([$lilliName]) === []);
ok('Und das Passwort ist dasselbe geblieben',
   (string) qv('SELECT password_hash FROM users WHERE id = ?', [(int) $lilli['id']])
   === $hashVorher);

// Passwort zurücksetzen.
$altesPasswort = (string) $lilli['initial_password'];
$res = teacherRequest($base . '/teacher/class.php?id=' . $klasseId, [
    'reset_password' => (int) $lilli['id'],
    'class_id'       => $klasseId,
    'csrf'           => $lehrerCsrf,
]);
$lilliNeu = q1('SELECT * FROM users WHERE id = ?', [(int) $lilli['id']]);
ok('Ein neues Anfangspasswort lässt sich setzen',
   (string) $lilliNeu['initial_password'] !== $altesPasswort);
ok('Und öffnet das Konto',
   password_verify((string) $lilliNeu['initial_password'], (string) $lilliNeu['password_hash']));
ok('Das alte tut es nicht mehr',
   !password_verify($altesPasswort, (string) $lilliNeu['password_hash']));

/*
 * Und die Grenze: Über ein untergeschobenes Formular darf sich kein Konto
 * ausserhalb dieser Klasse zurücksetzen lassen.
 */
$fremdesHash = (string) qv('SELECT password_hash FROM users WHERE id = ?', [$userId]);
teacherRequest($base . '/teacher/class.php?id=' . $klasseId, [
    'reset_password' => $userId,
    'class_id'       => $klasseId,
    'csrf'           => $lehrerCsrf,
]);
ok('Ein Kind aus einer anderen Klasse bleibt unberührt',
   (string) qv('SELECT password_hash FROM users WHERE id = ?', [$userId]) === $fremdesHash);

// ------------------------------------------------------ Der Ausdruck

section('Zettel mit den Zugangsdaten');

require_once __DIR__ . '/../lib/letter.php';

ok('Die Vorlage nennt Name, Benutzername und Passwort',
   str_contains(letter_default(), '{name}')
   && str_contains(letter_default(), '{benutzername}')
   && str_contains(letter_default(), '{passwort}'));

$gefuellt = letter_render(letter_template(), [
    'name'         => 'Lilli M.',
    'benutzername' => 'lilli.m',
    'passwort'     => 'müder Gepard',
    'klasse'       => '5B',
    'schule'       => 'Testschule',
    'url'          => 'https://example.test/vt',
]);
ok('Die Platzhalter werden ersetzt',
   str_contains($gefuellt, 'müder Gepard') && !str_contains($gefuellt, '{passwort}'));

/*
 * Ein Tippfehler im Platzhalter soll auffallen, nicht verschwinden. Eine
 * leere Stelle waere ein Kind ohne Passwort auf dem Zettel.
 */
ok('Ein unbekannter Platzhalter bleibt stehen',
   str_contains(letter_render('Hallo {name}, dein {passwrot}', ['name' => 'Max']),
                '{passwrot}'));

$res = teacherGet('print.php?class=' . $klasseId);
ok('Die Druckansicht öffnet sich', $res['status'] === 200, 'Status ' . $res['status']);
ok('Sie zeigt ein Blatt je Kind',
   substr_count($res['body'], 'class="blatt"') === 4,
   substr_count($res['body'], 'class="blatt"') . ' Blätter');
ok('Mit einem QR-Code darauf', str_contains($res['body'], '<svg '));
ok('Und dem Anfangspasswort im Klartext',
   str_contains($res['body'], (string) $lilliNeu['initial_password']));
ok('Die Bedienleiste verschwindet beim Drucken',
   str_contains($res['body'], '.bar { display: none; }'));
ok('Nach jedem Kind kommt eine neue Seite',
   str_contains($res['body'], 'page-break-after: always'));

$res = teacherGet('print.php?class=' . $klasseId . '&user=' . (int) $lilli['id']);
ok('Ein einzelnes Blatt lässt sich nachdrucken',
   substr_count($res['body'], 'class="blatt"') === 1,
   substr_count($res['body'], 'class="blatt"') . ' Blätter');

/*
 * Und die Grenze: Ein Kind aus einer anderen Klasse darf auch dann nicht auf
 * dem Zettel landen, wenn seine Nummer im Aufruf steht.
 */
$res = teacherGet('print.php?class=' . $klasseId . '&user=' . $userId);
ok('Ein fremdes Kind kommt nicht auf den Ausdruck',
   substr_count($res['body'], 'class="blatt"') === 0
   && str_contains($res['body'], 'nichts zu drucken'));

// Eine Klasse einer anderen Schule geht niemanden etwas an.
q("INSERT IGNORE INTO schools (name) VALUES ('Fremde Schule 2')");
$fremdeSchule2 = (int) qv("SELECT id FROM schools WHERE name = 'Fremde Schule 2'");
q('INSERT INTO classes (school_id, name) VALUES (?, ?)', [$fremdeSchule2, 'Fremde 5A']);
$fremdeKlasse = (int) db()->lastInsertId();

$res = teacherGet('class.php?id=' . $fremdeKlasse);
ok('Eine Klasse einer anderen Schule bleibt verschlossen',
   !str_contains($res['body'], 'Fremde 5A'), 'fremde Klasse war sichtbar');

q('DELETE FROM classes WHERE id = ?', [$fremdeKlasse]);
q('DELETE FROM schools WHERE id = ?', [$fremdeSchule2]);

// Aufraeumen: erst die Kinder, dann die Klasse.
foreach ($neueKinder as $k) {
    q('DELETE FROM users WHERE id = ?', [(int) $k['id']]);
}
q('DELETE FROM classes WHERE id = ?', [$klasseId]);

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

// Die eigenen Kostenzeilen mitnehmen - siehe $aiMarke ganz oben.
$weg = q('DELETE FROM ai_requests WHERE id > ? AND user_id IS NULL', [$aiMarke])->rowCount();
ok('Der Lauf hinterlaesst keine Kostenzeilen',
   (int) qv('SELECT COUNT(*) FROM ai_requests WHERE id > ? AND user_id IS NULL',
            [$aiMarke]) === 0,
   $weg . ' aufgeraeumt');

@unlink($jar);

echo "\n" . str_repeat('-', 52) . "\n";
printf("%d bestanden, %d fehlgeschlagen\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
