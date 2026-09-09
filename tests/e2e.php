<?php
declare(strict_types=1);

/**
 * End-to-End-Test gegen eine laufende Instanz.
 *
 *   php tests/e2e.php http://localhost:8123
 *
 * Der Test legt einen eigenen Testaccount an, spielt Login, Sprache, Import
 * (ohne KI-Aufruf), Quiz und Aufraeumen durch und prueft dabei die Lernregel
 * "dreimal hintereinander richtig". Er braucht Zugriff auf dieselbe Datenbank
 * wie die App, um die richtige Antwort nachzuschlagen - die App gibt sie
 * bewusst nicht heraus.
 *
 * Nicht gegen eine produktive Instanz laufen lassen: Der Testaccount wird
 * angelegt und am Ende wieder geloescht.
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/settings.php';

$base     = rtrim($argv[1] ?? 'http://localhost:8123', '/');
// Zweites Argument: Admin-Passwort. Fehlt es, wird der Admin-Teil uebersprungen -
// so laesst sich der Test auch gegen eine Installation fahren, deren Passwort
// bereits geaendert wurde.
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
    curl_close($ch);

    return [
        'status'  => $status,
        'headers' => substr($raw, 0, $hlen),
        'body'    => substr($raw, $hlen),
    ];
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
    curl_close($ch);

    return ['status' => $status, 'body' => substr($raw, $hlen)];
}

section('Admin-Interface');

$res = http($base . '/admin/');
ok('Admin verlangt ein Passwort', str_contains($res['body'], 'name="admin_password"'));

$res = adminPost('index.php', ['admin_password' => 'garantiert-falsch-' . bin2hex(random_bytes(4))]);
ok('Falsches Admin-Passwort wird abgelehnt', str_contains($res['body'], 'name="admin_password"'));

$res = adminPost('index.php', ['admin_password' => $adminPass]);
$adminOk = str_contains($res['body'], 'Diesen Monat');
ok('Admin-Anmeldung', $adminOk, 'Dashboard nicht erreicht - Passwort als 2. Argument uebergeben');
ok('Passwort liegt als Hash in der Datenbank',
   str_starts_with((string) qv("SELECT v FROM settings WHERE k = 'admin_password_hash'"), '$'));

foreach (['users.php' => 'Neuen Account anlegen',
          'vocab.php' => 'Kind',
          'settings.php' => 'Modell fuer die Bilderkennung',
          'selfcheck.php' => 'Pruefung'] as $file => $needle) {
    $res = http($base . '/admin/' . $file);
    ok("Seite $file laedt", $res['status'] === 200 && str_contains($res['body'], $needle),
       "Status {$res['status']}");
}

$res = http($base . '/admin/selfcheck.php');
ok('Selbsttest meldet keine Fehler bei DB und Schema',
   str_contains($res['body'], 'Tabellen vorhanden'));

// Zweites Kind anlegen - damit prueft der Test weiter unten die Trennung der Accounts.
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
ok('Ungueltiger Benutzername wird abgelehnt', str_contains($res['body'], 'Benutzername: 3-64'));

// Fremddaten anlegen, gegen die der Zugriffsschutz gleich geprueft wird.
q('INSERT INTO languages (user_id, name, flag_emoji) VALUES (?, ?, ?)', [$otherId, 'Fremdisch', '']);
$otherLang = (int) db()->lastInsertId();
q('INSERT INTO units (user_id, language_id, title) VALUES (?, ?, ?)', [$otherId, $otherLang, 'Fremde Unit']);
$otherUnitId = (int) db()->lastInsertId();

// Vorherige Einstellung merken, damit der Test nichts dauerhaft veraendert.
$prevModel  = (string) qv("SELECT v FROM settings WHERE k = 'vision_model'");
$prevEffort = (string) qv("SELECT v FROM settings WHERE k = 'vision_effort'");
$probeModel = $prevModel === 'claude-sonnet-5' ? 'claude-haiku-4-5' : 'claude-sonnet-5';

adminPost('settings.php', ['save_model' => '1', 'vision_model' => $probeModel,
                           'vision_effort' => 'low']);
ok('Modellwechsel wird gespeichert',
   qv("SELECT v FROM settings WHERE k = 'vision_model'") === $probeModel);

adminPost('settings.php', ['save_model' => '1', 'vision_model' => 'boesartig',
                           'vision_effort' => 'medium']);
ok('Unbekanntes Modell wird abgelehnt',
   qv("SELECT v FROM settings WHERE k = 'vision_model'") === $probeModel);

adminPost('settings.php', ['save_model' => '1', 'vision_model' => $prevModel,
                           'vision_effort' => $prevEffort]);
ok('Vorherige Modelleinstellung wiederhergestellt',
   qv("SELECT v FROM settings WHERE k = 'vision_model'") === $prevModel
   && qv("SELECT v FROM settings WHERE k = 'vision_effort'") === $prevEffort);


// ------------------------------------------------------------------ Anmeldung

section('Anmeldung und Geraete-Token');

[$data, $status] = apiCall('auth', 'login', ['username' => $username, 'password' => 'falsch']);
ok('Falsches Passwort wird abgelehnt', $status === 401, "Status $status");

[$data, $status] = apiCall('auth', 'login', ['username' => $username, 'password' => 'geheim123']);
ok('Anmeldung erfolgreich', $status === 200 && ($data['ok'] ?? false), $data['error'] ?? '');
ok('App-Name ist Besitzform', ($data['user']['appName'] ?? '') === 'Testkinds Vokabeln',
   $data['user']['appName'] ?? '(fehlt)');

$redirect = (string) ($data['redirect'] ?? '');
parse_str((string) parse_url($redirect, PHP_URL_QUERY), $q);
$token = (string) ($q['t'] ?? '');
ok('Geraete-Token ausgeliefert', strlen($token) > 30);
ok('Token ist nur als Hash gespeichert',
   q1('SELECT id FROM device_tokens WHERE token_hash = ?', [hash('sha256', $token)]) !== null);

// ------------------------------------------------------------------ PWA-Huelle

section('PWA-Huelle');

$res = http($base . '/?t=' . urlencode($token));
ok('Token-Start leitet weiter (Token verlaesst die URL)', $res['status'] === 302, "Status {$res['status']}");

$res = http($base . '/');
ok('Shell enthaelt personalisierten Manifest-Link', str_contains($res['body'], 'manifest.php?t='));
ok('iOS-Titel ist der Kindername',
   str_contains($res['body'], 'apple-mobile-web-app-title" content="Testkinds Vokabeln"'));
ok('apple-touch-icon gesetzt', str_contains($res['body'], 'icon.php?u=' . $userId));

$res      = http($base . '/manifest.php?t=' . urlencode($token));
$manifest = json_decode($res['body'], true);
ok('Manifest-Name', ($manifest['name'] ?? '') === 'Testkinds Vokabeln', $manifest['name'] ?? '(fehlt)');
ok('Manifest start_url traegt den Token', str_contains((string) ($manifest['start_url'] ?? ''), 't=' . $token));
ok('Manifest display=standalone', ($manifest['display'] ?? '') === 'standalone');

$res = http($base . '/icon.php?u=' . $userId . '&s=192');
ok('Icon ist ein PNG', str_starts_with($res['body'], "\x89PNG"), 'Antwort war kein PNG');

$res = http($base . '/manifest.php?t=ungueltig');
ok('Ungueltiger Token liefert generisches Manifest',
   (json_decode($res['body'], true)['name'] ?? '') === 'Vokabeln');

// ------------------------------------------------------------------ Sprache

section('Sprache und Lerneinheit');

[$data, $status] = apiCall('languages', 'create', ['name' => 'Testisch', 'flag' => "\u{1F1EC}\u{1F1E7}"]);
ok('Sprache angelegt', $status === 200 && ($data['ok'] ?? false), $data['error'] ?? '');
$languageId = (int) ($data['id'] ?? 0);

[$data, $status] = apiCall('languages', 'create', ['name' => 'Testisch', 'flag' => '']);
ok('Doppelte Sprache wird abgelehnt', $status === 409, "Status $status");

$entries = [];
foreach ([['one', 'eins'], ['two', 'zwei'], ['three', 'drei'], ['four', 'vier'], ['five', 'fuenf']] as [$f, $n]) {
    $entries[] = ['foreign' => $f, 'native' => $n];
}
[$data, $status] = apiCall('import', 'save', [
    'language_id' => $languageId,
    'title'       => 'Unit 1',
    'entries'     => $entries,
]);
ok('Lerneinheit gespeichert', $status === 200 && ($data['ok'] ?? false), $data['error'] ?? '');
ok('Alle fuenf Vokabeln angelegt', ($data['count'] ?? 0) === 5);
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
ok('Hinweisfeld wird uebernommen', $fixed['note'] === 'Zahlwort');
ok('Titel der Lerneinheit wird umbenannt',
   qv('SELECT title FROM units WHERE id = ?', [$unitId]) === 'Unit 1 korrigiert');

// Leere Felder duerfen bestehende Daten nicht zerstoeren.
$fields['f'][$firstId] = '';
$fields['n'][$firstId] = '';
adminPost('vocab.php', $fields, $filter);
ok('Leere Eingabe loescht keine Vokabel',
   qv('SELECT term_foreign FROM vocab WHERE id = ?', [$firstId]) === 'ONE');

adminPost('vocab.php', ['add_vocab' => $unitId, 'user' => $userId, 'language' => $languageId,
                        'unit' => $unitId, 'new_f' => 'six', 'new_n' => 'sechs'], $filter);
ok('Admin ergaenzt eine Vokabel',
   (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ?', [$unitId]) === 6);

adminPost('vocab.php', ['delete_vocab' => $firstId, 'user' => $userId,
                        'language' => $languageId, 'unit' => $unitId], $filter);
ok('Admin loescht eine Vokabel',
   q1('SELECT id FROM vocab WHERE id = ?', [$firstId]) === null);

// Eine ergaenzt, eine geloescht - der Quiz-Abschnitt findet wieder fuenf Vokabeln vor.
ok('Lerneinheit steht wieder bei fuenf Vokabeln',
   (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ?', [$unitId]) === 5);

section('Bilderkennung ueber die API');

// Laeuft nur gegen den Simulator (tests/fake-anthropic.php). Zeigt
// anthropic_base_url woanders hin, wird uebersprungen - dieser Test darf
// niemals die echte, kostenpflichtige API treffen.
$aiBase = (string) cfg('anthropic_base_url', '');
$isFake = $aiBase !== '' && preg_match('#^https?://(127\.0\.0\.1|localhost)[:/]#', $aiBase) === 1;

if (!$isFake) {
    echo "  - uebersprungen (anthropic_base_url zeigt nicht auf den Simulator)
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
    ok('Nur vollstaendige Paare werden zurueckgegeben', count($data['entries'] ?? []) === 3);

    [$saved, $status] = apiCall('import', 'save', [
        'language_id' => $languageId,
        'title'       => $data['title'],
        'entries'     => $data['entries'],
    ]);
    ok('Erkannte Vokabeln lassen sich speichern', $status === 200 && ($saved['count'] ?? 0) === 3);

    $newUnit = (int) ($saved['unit_id'] ?? 0);
    ok('Lerneinheit traegt den erkannten Titel',
       qv('SELECT title FROM units WHERE id = ?', [$newUnit]) === 'Unit 4 - In the kitchen');

    // Nicht im Quiz-Abschnitt mitzaehlen lassen.
    q('DELETE FROM units WHERE id = ?', [$newUnit]);

    [$data, $status] = apiCall('import', 'analyze', ['language_id' => $languageId, 'images' => []]);
    ok('Ohne Foto wird abgelehnt', $status === 400, "Status $status");

    [$data, $status] = apiCall('import', 'analyze', [
        'language_id' => $languageId,
        'images'      => [['data' => base64_encode('kein bild'), 'media_type' => 'image/jpeg']],
    ]);
    ok('Ungueltiges Bild wird abgelehnt', $status === 400, "Status $status");
}

// ------------------------------------------------------------------ Fremdzugriff

section('Fremdzugriff');

// Gegen die im Admin-Abschnitt gezielt angelegten Daten des zweiten Kindes.
[$data, $status] = apiCall('units', 'get', null, ['id' => $otherUnitId]);
ok('Fremde Lerneinheit ist nicht lesbar', $status === 404, "Status $status");

[$data, $status] = apiCall('quiz', 'next', null, ['unit_id' => $otherUnitId]);
ok('Quiz zu fremder Lerneinheit wird verweigert', $status === 404, "Status $status");

[$data, $status] = apiCall('units', 'delete', ['id' => $otherUnitId]);
ok('Loeschen einer fremden Lerneinheit wird verweigert', $status === 404, "Status $status");

[$data, $status] = apiCall('import', 'save', [
    'language_id' => $otherLang,
    'title'       => 'Eingeschleust',
    'entries'     => [['foreign' => 'a', 'native' => 'b']],
]);
ok('Speichern in fremde Sprache wird verweigert', $status === 404, "Status $status");
ok('Fremde Lerneinheit existiert unveraendert weiter',
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
ok('Genau vier Antwortmoeglichkeiten', count($card['options'] ?? []) === 4);
ok('Antwortmoeglichkeiten sind verschieden',
   count(array_unique($card['options'] ?? [])) === 4);
ok('Richtige Antwort wird nicht mitgeschickt',
   !array_key_exists('correct_index', $card) && !array_key_exists('answer', $card));
ok('Sprachname fuer die Richtungsanzeige dabei', ($card['language'] ?? '') === 'Testisch');

// Dieselbe Frage zweimal beantworten - der Nonce darf nur einmal gelten.
$index = correctIndexFor($card, $unitId);
[$r1, $s1] = apiCall('quiz', 'answer', ['nonce' => $card['nonce'], 'index' => $index]);
ok('Richtige Antwort wird als richtig gewertet', $r1['correct'] ?? false);
ok('Serie steht bei 1', ($r1['streak'] ?? -1) === 1);

[$r2, $s2] = apiCall('quiz', 'answer', ['nonce' => $card['nonce'], 'index' => $index]);
ok('Nonce ist nur einmal gueltig', $s2 === 409, "Status $s2");

// Falsche Antwort setzt die Serie zurueck.
[$card] = apiCall('quiz', 'next', null, ['unit_id' => $unitId]);
$right = correctIndexFor($card, $unitId);
$wrong = ($right + 1) % 4;
[$r3] = apiCall('quiz', 'answer', ['nonce' => $card['nonce'], 'index' => $wrong]);
ok('Falsche Antwort wird als falsch gewertet', ($r3['correct'] ?? true) === false);
ok('Serie faellt auf 0 zurueck', ($r3['streak'] ?? -1) === 0);
ok('Richtige Loesung wird zurueckgemeldet', ($r3['correct_index'] ?? -1) === $right);

// Ganze Einheit durchspielen - jede Vokabel braucht drei Treffer am Stueck.
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

// ------------------------------------------------------------------ Zuruecksetzen

section('Zuruecksetzen');

[$data, $status] = apiCall('units', 'reset', ['id' => $unitId]);
ok('Fortschritt zurueckgesetzt', $status === 200);
ok('Lernstand ist geloescht',
   (int) qv('SELECT COUNT(*) FROM progress p JOIN vocab v ON v.id = p.vocab_id WHERE v.unit_id = ?', [$unitId]) === 0);

[$card] = apiCall('quiz', 'next', null, ['unit_id' => $unitId]);
ok('Nach dem Zuruecksetzen kommen wieder Fragen', !($card['done'] ?? true));

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

// ------------------------------------------------------------------ Aufraeumen

q('DELETE FROM users WHERE id = ?', [$userId]);
q("DELETE FROM users WHERE username IN ('e2e_other')");
@unlink($jar);

echo "\n" . str_repeat('-', 52) . "\n";
printf("%d bestanden, %d fehlgeschlagen\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
