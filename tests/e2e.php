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
require_once __DIR__ . '/../lib/vocab.php';

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
    $schoolId = qv('SELECT school_id FROM users WHERE id = ?', [$userId]);
    // Das Kuerzel wie in der Anwendung herleiten, wenn keines vorgegeben ist -
    // course_create() und api/languages.php machen es genauso. Ein Helfer, der
    // hier abweicht, prueft eine Lage, die es nicht gibt.
    q('INSERT INTO languages (school_id, name, flag_emoji, code) VALUES (?, ?, ?, ?)',
      [$schoolId, $name, '', $code ?? language_code('', $name)]);
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

    q('INSERT INTO units (language_id, course_id, title, released_position'
      . $extraCols . ')
       VALUES (?, ?, ?, ?' . str_repeat(', ?', count($extraVals)) . ')',
      array_merge([$langId, $kurs === null ? null : (int) $kurs['id'], $title,
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
          'vocab.php' => 'Schule',
          'settings.php' => 'Modell für die Bilderkennung',
          'selfcheck.php' => 'Prüfung'] as $file => $needle) {
    $res = http($base . '/admin/' . $file);
    ok("Seite $file lädt", $res['status'] === 200 && str_contains($res['body'], $needle),
       "Status {$res['status']}");
}

$res = http($base . '/admin/selfcheck.php');
ok('Selbsttest meldet keine Fehler bei DB und Schema',
   str_contains($res['body'], 'Tabellen vorhanden'));

/*
 * Zweites Kind anlegen - damit prüft der Test weiter unten die Trennung der
 * Accounts. Die Schule ist beim Anlegen Pflicht: Ohne sie sieht das Konto
 * nichts, weil Sichtbarkeit über die Kursmitgliedschaft läuft.
 */
q("DELETE FROM users WHERE username = 'e2e_other'");
$testSchule = (int) qv('SELECT school_id FROM users WHERE id = ?', [$userId]);
$res = adminPost('users.php', [
    'create'       => '1',
    'username'     => 'e2e_other',
    'display_name' => 'Zweitkind',
    'password'     => 'geheim123',
    'color'        => '#4f7cff',
    'school_id'    => $testSchule,
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
ok('App-Name ist Besitzform', ($data['user']['appName'] ?? '') === 'Testkinds Vokidoki',
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
   str_contains($res['body'], 'apple-mobile-web-app-title" content="Testkinds Vokidoki"'));
ok('apple-touch-icon gesetzt', str_contains($res['body'], 'icon.php?u=' . $userId));

$res      = http($base . '/manifest.php?t=' . urlencode($token));
$manifest = json_decode($res['body'], true);
ok('Manifest-Name', ($manifest['name'] ?? '') === 'Testkinds Vokidoki', $manifest['name'] ?? '(fehlt)');
ok('Manifest start_url trägt den Token', str_contains((string) ($manifest['start_url'] ?? ''), 't=' . $token));
ok('Manifest display=standalone', ($manifest['display'] ?? '') === 'standalone');

$res = http($base . '/icon.php?u=' . $userId . '&s=192');
ok('Icon ist ein PNG', str_starts_with($res['body'], "\x89PNG"), 'Antwort war kein PNG');

/*
 * Auf dem Symbol steht Voki, nicht mehr der Anfangsbuchstabe - auf der Farbe
 * des Kontos, mit weissem Rand um ihn und jeden Stern. Nachgezaehlt wird an
 * den Pixeln: Ein PNG kommt auch dann, wenn die Vorlage fehlt, und dann
 * stuende nur ein Punkt darauf.
 */
$pixel = static function (string $png): array {
    $bild = imagecreatefromstring($png);
    $zaehl = ['weiss' => 0, 'gruen' => 0, 'n' => imagesx($bild)];
    for ($y = 0; $y < imagesy($bild); $y += 2) {
        for ($x = 0; $x < imagesx($bild); $x += 2) {
            $c = imagecolorat($bild, $x, $y);
            [$r, $g, $b] = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
            if ($r > 240 && $g > 240 && $b > 240) $zaehl['weiss']++;
            // Vokis Gruen: #AFD535.
            if (abs($r - 0xAF) < 18 && abs($g - 0xD5) < 18 && abs($b - 0x35) < 30) $zaehl['gruen']++;
        }
    }
    $zaehl['bild'] = $bild;
    return $zaehl;
};
$farbeTest = (string) qv('SELECT color FROM users WHERE id = ?', [$userId]);
$sym = $pixel($res['body']);
$ecke = imagecolorat($sym['bild'], 0, 0);
ok('Das Symbol hat die Farbe des Kontos',
   sprintf('#%06x', $ecke & 0xFFFFFF) === strtolower($farbeTest),
   sprintf('#%06x statt %s', $ecke & 0xFFFFFF, $farbeTest));
ok('Darauf steht Voki in seinem Gruen', $sym['gruen'] > 1000, $sym['gruen'] . ' gruene Pixel');
/*
 * Der Rand: Von links in die Zeile hinein ist das Erste, worauf man trifft,
 * weiss - nicht Gruen und nicht das Gelb eines Sterns. Weisse Pixel nur zu
 * zaehlen, reichte nicht: Vokis Augen sind auch weiss.
 */
$randZeilen = 0;
foreach ([0.3, 0.45, 0.6, 0.75] as $anteil) {
    $y     = (int) ($sym['n'] * $anteil);
    $grund = imagecolorat($sym['bild'], 0, $y);
    for ($x = 1; $x < $sym['n']; $x++) {
        $c = imagecolorat($sym['bild'], $x, $y);
        $abstand = abs((($c >> 16) & 255) - (($grund >> 16) & 255))
                 + abs((($c >> 8) & 255) - (($grund >> 8) & 255))
                 + abs(($c & 255) - ($grund & 255));
        if ($abstand < 60) continue;
        // Kantenglaettung: ein, zwei Pixel Mischfarbe, dann Weiss.
        foreach (range($x, min($x + 3, $sym['n'] - 1)) as $xx) {
            $w = imagecolorat($sym['bild'], $xx, $y);
            if ((($w >> 16) & 255) > 230 && (($w >> 8) & 255) > 230 && ($w & 255) > 230) {
                $randZeilen++;
                break;
            }
        }
        break;
    }
}
ok('Mit weissem Rand um Voki und die Sterne', $randZeilen === 4, "$randZeilen von 4 Zeilen");

// Maskable: Android schneidet auf einen Kreis von 80 % - am Rand darf nichts
// von Voki stehen, sonst fehlen ihm die Sterne.
$mask = imagecreatefromstring(http($base . '/icon.php?u=' . $userId . '&s=512&p=1')['body']);
$randFrei = true;
foreach (range(0, 511, 7) as $i) {
    foreach ([[$i, 40], [$i, 471], [40, $i], [471, $i]] as [$x, $y]) {
        $c = imagecolorat($mask, $x, $y);
        if ((($c >> 16) & 255) > 240 && (($c >> 8) & 255) > 240 && ($c & 255) > 240) {
            $randFrei = false;
        }
    }
}
ok('Das maskierbare Symbol laesst den Rand frei', $randFrei);

// Das Favicon: nur Voki, ohne Flaeche - im Tab soll kein Quadrat stehen.
$fav = http($base . '/icon.php?f=1&s=32');
$favBild = imagecreatefromstring($fav['body']);
ok('Das Favicon ist durchsichtig, wo Voki nicht ist',
   ((imagecolorat($favBild, 0, 0) >> 24) & 0x7F) === 127);
ok('Und hat Voki darin',
   ((imagecolorat($favBild, 16, 18) >> 24) & 0x7F) < 64, 'Mitte ist leer');

foreach (['/' => 'App', '/admin/' => 'Admin', '/teacher/' => 'Lehrkraft-Bereich'] as $pfad => $wo) {
    $kopf = http($base . $pfad)['body'];
    ok("$wo hat Voki als Favicon",
       str_contains($kopf, 'rel="icon" type="image/svg+xml"') && str_contains($kopf, 'voki-icon.svg')
       && str_contains($kopf, 'icon.php?f=1'));
}
ok('Auch die Seite ohne Netz',
   str_contains((string) file_get_contents(__DIR__ . '/../offline.html'), 'voki-icon.svg'));

// Eine neue Zeichnung muss ankommen, auch wenn storage/icons/ voll ist.
ok('Der Zwischenspeicher der Symbole kennt die Vorlage',
   str_contains((string) file_get_contents(__DIR__ . '/../icon.php'), 'filemtime($vorlage)'));

$res = http($base . '/manifest.php?t=kein-gueltiger-token');
ok('Ungültiger Token liefert generisches Manifest',
   (json_decode($res['body'], true)['name'] ?? '') === 'Vokidoki');

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

/*
 * Ausgewaehlt wird ueber Schule, Kurs, Lerneinheit. Frueher ueber Kind und
 * Sprache - aus der Zeit, als ein Kind seine Vokabeln besass.
 */
$adminKurs  = (int) course_for_language($languageId)['id'];
$adminWahl  = ['school' => $testSchule, 'course' => $adminKurs, 'unit' => $unitId];
$filter     = http_build_query($adminWahl);
$kursFilter = http_build_query(['school' => $testSchule, 'course' => $adminKurs]);

$fields = ['save_rows' => '1', 'unit_title' => 'Unit 1 korrigiert'] + $adminWahl;
foreach ($vocabRows as $row) {
    $fields['f'][$row['id']]    = $row['term_foreign'];
    $fields['n'][$row['id']]    = $row['term_native'];
    $fields['note'][$row['id']] = '';
}

/*
 * Eine Lerneinheit zaehlt nur im eigenen Kurs. Ein alter Link, der eine
 * Einheit unter einem fremden Kurs nennt, zeigt sie nicht - und speichert
 * nicht hinein. Frueher ging "unit_id" aus dem Formular direkt ins UPDATE.
 */
[$data] = apiCall('languages', 'create', ['name' => 'Nebenkurs', 'flag' => '']);
$nebenSprache = (int) ($data['id'] ?? 0);
$nebenKurs    = (int) course_for_language($nebenSprache)['id'];
$falsch       = ['school' => $testSchule, 'course' => $nebenKurs, 'unit' => $unitId];

$seite = http($base . '/admin/vocab.php?' . http_build_query($falsch))['body'];
ok('Eine Lerneinheit erscheint nicht unter einem fremden Kurs',
   !str_contains($seite, 'name="f[' . $firstId . ']"'));
$titelVorher = (string) qv('SELECT title FROM units WHERE id = ?', [$unitId]);
adminPost('vocab.php', ['unit_title' => 'Untergeschoben'] + $falsch + $fields,
          http_build_query($falsch));
ok('Und wird darunter auch nicht umbenannt',
   qv('SELECT title FROM units WHERE id = ?', [$unitId]) === $titelVorher,
   (string) qv('SELECT title FROM units WHERE id = ?', [$unitId]));

// Wer nur die Lerneinheit nennt, landet trotzdem richtig.
$seite = http($base . '/admin/vocab.php?unit=' . $unitId)['body'];
ok('Ein Link mit nur der Lerneinheit findet Kurs und Schule selbst',
   str_contains($seite, 'name="f[' . $firstId . ']"'));
// Nimmt den Kurs ueber den Fremdschluessel mit.
q('DELETE FROM languages WHERE id = ?', [$nebenSprache]);

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

adminPost('vocab.php', ['add_vocab' => '1', 'new_f' => 'six', 'new_n' => 'sechs'] + $adminWahl,
          $filter);
ok('Admin ergänzt eine Vokabel',
   (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ?', [$unitId]) === 6);

adminPost('vocab.php', ['delete_vocab' => $firstId] + $adminWahl, $filter);
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

$res = http($base . '/admin/vocab.php?' . $filter);
ok('Spalte Kategorie ist da', str_contains($res['body'], '<th>Kategorie</th>'));
ok('Auswahlfeld je Zeile', str_contains($res['body'], 'name="wt[' . $firstId . ']"'));
$fehlend = array_values(array_filter(
    word_type_keys(),
    static fn (string $k): bool => !str_contains($res['body'], 'value="' . $k . '"'),
));
ok('Alle dreizehn Kategorien stehen zur Wahl', $fehlend === [], implode(', ', $fehlend));

// Manuell setzen - über dasselbe Formular wie die Textkorrekturen.
$fields = ['save_rows' => '1', 'unit_title' => 'Unit 1 korrigiert'] + $adminWahl;
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

// Die Karten fuer den ganzen Bestand stehen ueber der Auswahl, nicht ueber
// einer offenen Lerneinheit - dort will man deren Vokabeln sehen.
$res = http($base . '/admin/vocab.php?' . $kursFilter);
ok('Knopf zum Nachtragen erscheint', str_contains($res['body'], 'Kategorien nachtragen'));
ok('Aber nicht ueber einer offenen Lerneinheit',
   !str_contains(http($base . '/admin/vocab.php?' . $filter)['body'], 'Kategorien nachtragen'));

if ($isFake) {
    adminPost('vocab.php', ['fill_word_types' => '1'] + $adminWahl, $kursFilter);
    ok('Nach dem Nachtragen hat jede Vokabel eine Kategorie',
       (int) qv('SELECT COUNT(*) FROM vocab WHERE word_type IS NULL') === 0,
       qv('SELECT COUNT(*) FROM vocab WHERE word_type IS NULL') . ' offen');

    $res = http($base . '/admin/vocab.php?' . $kursFilter);
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

/*
 * Die Liste der Schemaaenderungen ist leer - und das ist der richtige Zustand.
 *
 * schema.sql legt das fertige Schema an; eine frische Installation hat nichts
 * nachzutragen. Hier standen fuenfundvierzig Aenderungen, und jede einzelne
 * war der Weg von einem aelteren Stand auf den heutigen - bis hin zu zehn
 * family.*-Schritten, die den Bestand der alten Familien-App ueberfuehrten.
 * Es gibt keine aeltere Datenbank mehr, die ueberfuehrt werden muesste.
 *
 * Der Weg selbst bleibt: Die naechste Aenderung kommt in dieselbe Liste.
 */
ok('Es steht nichts aus', schema_pending() === [], implode(', ', schema_pending()));
ok('Weil die Liste leer ist', schema_migrations() === [],
   count(schema_migrations()) . ' Eintraege');
ok('Und kein Rettungsweg fuer Altbestand mehr darin steht',
   !function_exists('schema_has_legacy_data')
   && preg_match("/'family\.[a-z_]+' => \[/", $schemaQuelle) !== 1,
   'die alte Familien-App wird nicht mehr ueberfuehrt - der Kommentar, '
   . 'der das erklaert, darf bleiben');

// Der Mechanismus muss trotzdem stehen - die naechste Aenderung braucht ihn.
foreach (['schema_migrations', 'schema_pending', 'ensure_schema',
          'schema_was_applied', 'table_exists', 'column_exists',
          'index_exists'] as $fn) {
    ok("Der Weg fuer die naechste Aenderung steht: $fn()", function_exists($fn));
}
ok('ensure_schema() laeuft auch ohne Aenderungen sauber durch',
   ensure_schema() === []);

$seite = http($base . '/admin/selfcheck.php')['body'];
ok('Der Selbsttest meldet nichts Offenes',
   !str_contains($seite, 'ausstehende Schemaänderung'));
ok('Und laedt trotzdem', str_contains($seite, 'Prüfung'));

/*
 * Die Woerter fuer die Anfangspasswoerter kommen jetzt aus schema.sql.
 *
 * Sie wurden einmal von einer Schemaaenderung gesaet. Faellt das weg, ohne
 * dass schema.sql sie mitbringt, bleiben die Listen leer - und
 * password_generate() liefert dann bewusst gar kein Passwort. Eine
 * Neuinstallation haette also Konten ohne Anfangspasswort.
 */
$schemaSql = (string) file_get_contents(__DIR__ . '/../schema.sql');
ok('schema.sql bringt die Passwortwoerter mit',
   str_contains($schemaSql, 'INSERT IGNORE INTO password_words'));
ok('Und zwar beide Sorten',
   substr_count($schemaSql, "('adjective',") >= 20
   && substr_count($schemaSql, "('animal',") >= 20,
   substr_count($schemaSql, "('adjective',") . ' Adjektive, '
   . substr_count($schemaSql, "('animal',") . ' Tiere');
ok('In dieser Installation sind sie da',
   (int) qv('SELECT COUNT(*) FROM password_words') >= 40,
   (string) qv('SELECT COUNT(*) FROM password_words'));
require_once __DIR__ . '/../lib/passwords.php';
ok('Und es entsteht ein Anfangspasswort', password_generate() !== null);

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

/*
 * Hier stand die Nachstellung des Bestandes VOR dem Umbau auf Kurse: ein
 * Kind ohne Schule, eine Sprache ohne Kurs, eine Lerneinheit mit
 * units.user_id - und danach die Ueberfuehrung durch die family.*-Schritte.
 *
 * Beides gibt es nicht mehr. Die Spalten user_id sind aus dem Schema
 * verschwunden, die Ueberfuehrung aus der Aenderungsliste. Geprueft wird
 * jetzt das Gegenteil: dass von der alten Gestalt nichts uebrig ist.
 */
foreach ([['units', 'user_id'], ['languages', 'user_id']] as [$t, $c]) {
    ok("Die alte Besitzspalte $t.$c ist weg", !column_exists($t, $c),
       'der Besitzer einer Lerneinheit war das Modell VOR den Kursen');
}
ok('Jede Sprache gehoert zu einem Kurs',
   (int) qv('SELECT COUNT(*) FROM languages l
              WHERE NOT EXISTS (SELECT 1 FROM courses co
                                 WHERE co.language_id = l.id)') === 0,
   'eine Sprache ohne Kurs war das Kennzeichen des Altbestands');
ok('Und jede Lerneinheit zu einem Kurs',
   (int) qv('SELECT COUNT(*) FROM units WHERE course_id IS NULL') === 0,
   'ohne Kurs saehe sie niemand - auch die Lehrkraft nicht');

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
          'users', 'languages', 'units', 'vocab', 'sentences', 'vocab_flags',
          'progress', 'ai_requests', 'settings', 'device_tokens',
          'login_attempts', 'login_handoffs', 'password_words'] as $t) {
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

section('Kurs im Admin löschen');

/*
 * Hier hiess es "Sprache loeschen" - und loeschte ueber den Fremdschluessel
 * den ganzen Kurs mit, waehrend die Rueckfrage nur Vokabeln nannte. Jetzt
 * heisst es Kurs, und die Rueckfrage nennt, was daran haengt.
 */

// Wegwerf-Kurs mit Einheit, Vokabeln und Lernstand anlegen.
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

$tmpKurs  = (int) course_for_language($tmpLang)['id'];
$tmpWahl  = ['school' => $testSchule, 'course' => $tmpKurs];

$seite = http($base . '/admin/vocab.php?' . http_build_query($tmpWahl))['body'];
ok('Der Kurs hat einen Knopf zum Löschen', str_contains($seite, 'name="delete_course"'));
ok('Die Rückfrage nennt, was daran hängt',
   str_contains($seite, '1 Lerneinheit(en), 2 Vokabel(n)'), 'Zahlen nicht in der Rückfrage');
ok('Den alten Knopf "Sprache löschen" gibt es nicht mehr',
   !str_contains($seite, 'name="delete_language"'));

$res = adminPost('vocab.php', ['delete_course' => $tmpKurs] + $tmpWahl, http_build_query($tmpWahl));

ok('Der Kurs ist gelöscht', q1('SELECT id FROM courses WHERE id = ?', [$tmpKurs]) === null);
ok('Seine Sprache mit ihm',
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
ok('Und die Auswahl steht danach auf der Schule, nicht im Leeren',
   preg_match('/course=' . $tmpKurs . '(?!\d)/', $res['body']) !== 1);

// Die andere Sprache des Kindes darf davon unberührt bleiben.
ok('Andere Sprache bleibt bestehen',
   q1('SELECT id FROM languages WHERE id = ?', [$languageId]) !== null);
ok('Ihre Lerneinheit bleibt bestehen',
   q1('SELECT id FROM units WHERE id = ?', [$unitId]) !== null);

$res = adminPost('vocab.php', ['delete_course' => $tmpKurs] + $tmpWahl,
                 http_build_query(['school' => $testSchule]));
ok('Erneutes Löschen meldet sich sauber',
   str_contains($res['body'], 'gibt es nicht mehr'));

section('Sprachkürzel');

require_once __DIR__ . '/../lib/languages.php';

/*
 * Das Kürzel steuert im Lückentext den Tastaturhinweis und die Reihe der
 * Sonderzeichen. Gesetzt wird es beim Anlegen, von language_code().
 *
 * Hier stand einmal die Pruefung eines Nachtrags: Die Spalte kam spaeter
 * dazu, und wer seine Sprachen vorher angelegt hatte, stand ohne da. Den
 * Nachtrag gibt es nicht mehr - es gibt keine Sprachen von vorher. Geprueft
 * wird jetzt die Zuordnung selbst, und die ist dieselbe geblieben.
 */
ok('Französisch bekommt sein Kürzel - trotz Umlaut und großem Anfangsbuchstaben',
   language_code('', 'Französisch') === 'fr');
ok('Und die ausgeschriebene Schreibweise "daenisch" ebenso',
   language_code('', 'daenisch') === 'da');
ok('Eine frei benannte Sprache bleibt ohne',
   language_code('', 'Klingonisch') === null);

// Und beim Anlegen kommt es auch wirklich in die Zeile.
$frId = makeLanguage($userId, 'Französisch');
$daId = makeLanguage($userId, 'daenisch');
$klId = makeLanguage($userId, 'Klingonisch');

ok('Eine neu angelegte Sprache traegt ihr Kürzel',
   qv('SELECT code FROM languages WHERE id = ?', [$frId]) === 'fr');
ok('Auch die ausgeschriebene Schreibweise',
   qv('SELECT code FROM languages WHERE id = ?', [$daId]) === 'da');
ok('Und die frei benannte bleibt leer',
   in_array(qv('SELECT code FROM languages WHERE id = ?', [$klId]), [null, ''], true));

section('Sprache im Admin pflegen');

// Das Kuerzel steht beim Kurs - jeder Kurs hat seine eigene Sprachzeile.
$frWahl = ['school' => $testSchule, 'course' => (int) course_for_language($frId)['id']];

$seite = http($base . '/admin/vocab.php?' . http_build_query($frWahl))['body'];

ok('Die Kurskarte zeigt ein Feld für das Kürzel',
   str_contains($seite, 'name="lang_code"'));
ok('Mit dem aktuellen Wert darin',
   preg_match('/name="lang_code" value="fr"/', $seite) === 1);
ok('Und einen Knopf zum Löschen des Kurses',
   str_contains($seite, 'name="delete_course"'));

$res = adminPost('vocab.php',
    ['save_language' => '1', 'lang_code' => 'DA'] + $frWahl, http_build_query($frWahl));
ok('Ein Kürzel lässt sich ändern - auch groß eingetippt',
   qv('SELECT code FROM languages WHERE id = ?', [$frId]) === 'da');

$res = adminPost('vocab.php',
    ['save_language' => '1', 'lang_code' => 'Unsinn123'] + $frWahl, http_build_query($frWahl));
ok('Unsinn wird abgewiesen', str_contains($res['body'], 'zwei oder drei Buchstaben'));
ok('Und der alte Wert bleibt stehen',
   qv('SELECT code FROM languages WHERE id = ?', [$frId]) === 'da');

$res = adminPost('vocab.php',
    ['save_language' => '1', 'lang_code' => ''] + $frWahl, http_build_query($frWahl));
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

$seite = http($base . '/admin/vocab.php?' . http_build_query(['school' => $testSchule]))['body'];
ok('Der Admin merkt, dass Abstände krumm sind',
   str_contains($seite, 'name="fix_punctuation"'), 'keine Karte im Markup');
ok('Und erklärt die französische Regel',
   str_contains($seite, 'Salut !'));

adminPost('vocab.php', ['fix_punctuation' => '1', 'school' => $testSchule],
          http_build_query(['school' => $testSchule]));

$nachher = q1('SELECT term_foreign, term_native FROM vocab WHERE unit_id = ? AND position = 0',
              [$absUnit]);
ok('Der Knopf rückt den Bestand zurecht',
   $nachher['term_foreign'] === 'Salut !' && $nachher['term_native'] === 'Hallo!',
   json_encode($nachher, JSON_UNESCAPED_UNICODE));

$seite = http($base . '/admin/vocab.php?' . http_build_query(['school' => $testSchule]))['body'];
ok('Danach verschwindet die Karte von selbst',
   !str_contains($seite, 'name="fix_punctuation"'));

// Ein zweiter Klick darf nichts weiterschieben.
adminPost('vocab.php', ['fix_punctuation' => '1', 'school' => $testSchule],
          http_build_query(['school' => $testSchule]));
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
// Der Kurs heisst "Testisch Testkind" - Sprache und Anlegerin im Namen.
ok('Mit Kurs und Lerneinheit daneben',
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

/*
 * Dieselben Saetze unter ihren Vokabeln, in den Unterlagen - mit derselben
 * Pruefung. Dort stand einmal ein eigener Weg ueber sentence_clean(), den
 * kein Formular ausloeste.
 */
$seite = http($base . '/admin/vocab.php?' . $filter)['body'];
ok('Die Lerneinheit zeigt ihre Saetze gleich mit',
   str_contains($seite, 'name="sn[' . $satzId . ']"') && str_contains($seite, 'name="save_sentence_rows"'));

adminPost('vocab.php', ['save_sentence_rows' => '1',
    'sn' => [$satzId => 'Noch ein Satz.'], 'sf' => [$satzId => 'Encore {} texte.'],
    'sa' => [$satzId => 'un']] + $adminWahl, $filter);
ok('Und laesst sie dort bearbeiten',
   qv('SELECT foreign_text FROM sentences WHERE id = ?', [$satzId]) === 'Encore {} texte.');

$res = adminPost('vocab.php', ['save_sentence_rows' => '1',
    'sn' => [$satzId => 'Deutsch'], 'sf' => [$satzId => 'ohne Lücke'],
    'sa' => [$satzId => 'un']] + $adminWahl, $filter);
ok('Mit derselben Pruefung wie auf der Satzliste',
   qv('SELECT foreign_text FROM sentences WHERE id = ?', [$satzId]) === 'Encore {} texte.'
   && str_contains($res['body'], 'Form nicht stimmt'));

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

section('Vokabel melden');

require_once __DIR__ . '/../lib/meldungen.php';

/*
 * Vielleicht lag nicht das Kind daneben, sondern die Vokabel oder der Satz.
 * Dann soll es das sagen koennen - aus jeder Uebung, und im selben Strom
 * wie die Antworten: Geuebt wird auch ohne Netz, und eine Meldung aus dem
 * Zug soll ankommen, sobald wieder eines da ist.
 */
$mSatz = q1('SELECT s.id, s.vocab_id FROM sentences s
               JOIN vocab v ON v.id = s.vocab_id
              WHERE v.unit_id = ? LIMIT 1', [$unitId]);
ok('Ein Satz zum Melden ist da', $mSatz !== null);
$mVokabel = (int) ($mSatz['vocab_id'] ?? 0);
$mSatzId  = (int) ($mSatz['id'] ?? 0);
$mMarke   = bin2hex(random_bytes(6));
$mEreignis = static fn (string $n, int $v, int $s, string $t): array =>
    ['e' => $mMarke . '-' . $n, 'k' => 'melden', 'v' => $v, 's' => $s, 't' => $t];

[$res, $code] = apiCall('bundle', 'push', ['ereignisse' => [
    $mEreignis('1', $mVokabel, $mSatzId, 'mein Versuch'),
    $mEreignis('2', $mVokabel, 0, ''),
]]);
ok('Beide Meldungen werden angenommen',
   $code === 200 && ($res['genommen'] ?? 0) === 2, (string) json_encode($res));

$eintrag = q1('SELECT * FROM vocab_flags WHERE vocab_id = ? AND sentence_id = ?',
              [$mVokabel, $mSatzId]);
ok('Die aus dem Lueckentext landet mit ihrem Satz', $eintrag !== null);
ok('Mit dem Kind, das gemeldet hat', (int) ($eintrag['user_id'] ?? 0) === $userId);
ok('Und mit dem, was es getippt hatte',
   ($eintrag['typed'] ?? '') === 'mein Versuch', (string) json_encode($eintrag));
ok('Die aus dem Auswaehlen meint das Wortpaar - ohne Satz',
   (int) qv('SELECT COUNT(*) FROM vocab_flags WHERE vocab_id = ? AND sentence_id = 0',
            [$mVokabel]) === 1);

// Zweimal melden darf den Zaehler nicht hochtreiben - sonst ergaebe ein
// veraergertes Kind zehn Meldungen fuer denselben Satz. Eine neue Kennung,
// weil es ein neuer Druck ist und kein wiederholter Stapel.
apiCall('bundle', 'push', ['ereignisse' => [
    $mEreignis('3', $mVokabel, $mSatzId, 'zweiter Versuch'),
]]);
ok('Zweimal melden zaehlt nur einmal',
   (int) qv('SELECT COUNT(*) FROM vocab_flags WHERE vocab_id = ? AND sentence_id = ?',
            [$mVokabel, $mSatzId]) === 1);
ok('Der letzte Versuch wird aber vermerkt',
   qv('SELECT typed FROM vocab_flags WHERE vocab_id = ? AND sentence_id = ?',
      [$mVokabel, $mSatzId]) === 'zweiter Versuch');

$mStand = meldung_laden($mVokabel, null);
ok('Zusammen ist das EINE gemeldete Vokabel von einem Kind',
   $mStand !== null && $mStand['kinder'] === 1
   && count($mStand['wahl']) === 1 && count($mStand['saetze']) === 1,
   (string) json_encode($mStand));

// Ein Satz einer anderen Vokabel laesst sich nicht unterschieben - sonst
// kaeme mit einer erlaubten Vokabel jeder Satz auf die Liste.
$mAndererSatz = (int) qv('SELECT id FROM sentences WHERE vocab_id <> ? LIMIT 1', [$mVokabel]);
[$res] = apiCall('bundle', 'push', ['ereignisse' => [
    $mEreignis('4', $mVokabel, $mAndererSatz, 'x'),
]]);
ok('Ein fremder Satz an einer erlaubten Vokabel wird abgewiesen',
   ($res['fremd'] ?? 0) === 1
   && (int) qv('SELECT COUNT(*) FROM vocab_flags WHERE sentence_id = ?', [$mAndererSatz]) === 0,
   (string) json_encode($res));

// Und eine Vokabel aus einem Kurs, in dem dieses Kind nicht ist, erst recht.
$mFremdeVokabel = (int) qv('SELECT v.id FROM vocab v
                              JOIN units t ON t.id = v.unit_id
                             WHERE NOT EXISTS (SELECT 1 FROM course_members m
                                                WHERE m.course_id = t.course_id
                                                  AND m.user_id = ?)
                             LIMIT 1', [$userId]);
if ($mFremdeVokabel > 0) {
    [$res] = apiCall('bundle', 'push', ['ereignisse' => [
        $mEreignis('5', $mFremdeVokabel, 0, ''),
    ]]);
    ok('Eine fremde Vokabel laesst sich nicht melden', ($res['fremd'] ?? 0) === 1,
       (string) json_encode($res));
    ok('Und es entsteht kein Eintrag',
       (int) qv('SELECT COUNT(*) FROM vocab_flags WHERE vocab_id = ?', [$mFremdeVokabel]) === 0);
} else {
    ok('Eine fremde Vokabel laesst sich nicht melden', true, 'keine fremde Vokabel vorhanden');
    ok('Und es entsteht kein Eintrag', true, 'keine fremde Vokabel vorhanden');
}

// Der Weg vom Knopf in den Strom - in jeder der drei Uebungen.
$mJs = (string) file_get_contents(__DIR__ . '/../melden.js');
ok('Der Knopf fragt nach, bevor er meldet',
   str_contains($mJs, "'Diese Vokabel deiner Lehrkraft melden?'")
   && preg_match('/if \(!\(await nachfragen\(\)\)\) return;\s*vokabelMelden\(/', $mJs) === 1);
ok('Und zwar in der Seite, nicht mit einem Kaestchen des Browsers',
   // Ein Aufruf hat ein Argument - das "confirm()" im Kommentar nicht.
   preg_match('/confirm\(\s*[^)\s]/', $mJs) !== 1 && str_contains($mJs, 'showModal()'));
ok('Und meldet ueber die Warteschlange, nicht mit eigenem Abruf',
   !str_contains($mJs, 'api(')
   && str_contains((string) file_get_contents(__DIR__ . '/../vorrat.js'), "k: 'melden'"));
foreach (['quiz', 'cloze', 'frei'] as $mAnsicht) {
    $mQuelle = (string) file_get_contents(__DIR__ . "/../views/$mAnsicht.js");
    ok("Die Uebung $mAnsicht hat den Knopf",
       str_contains($mQuelle, 'meldeKnopf(') && str_contains($mQuelle, 'meldenVerdrahten('));
}
ok('Den alten Weg ueber die Lueckentext-API gibt es nicht mehr',
   !str_contains((string) file_get_contents(__DIR__ . '/../api/cloze.php'), "case 'flag'"));

section('Meldungen im Admin');

$seite = http($base . '/admin/meldungen.php')['body'];
$mWort = (string) qv('SELECT term_foreign FROM vocab WHERE id = ?', [$mVokabel]);
ok('Der Admin zeigt die gemeldete Vokabel', str_contains($seite, h($mWort)), $mWort);
ok('Und nennt, wer was getippt hat',
   str_contains($seite, 'Testkind') && str_contains($seite, 'zweiter Versuch'));
ok('Mit dem Satz zum Aendern und dem Wortpaar dazu',
   str_contains($seite, 'name="s[' . $mSatzId . '][f]"') && str_contains($seite, 'name="f"'));
ok('Die Leiste traegt die Zahl in Rot', str_contains($seite, 'class="zaehler"'));
ok('Die Lueckensaetze haben keine eigene Meldeverwaltung mehr',
   !str_contains(http($base . '/admin/sentences.php')['body'], 'clear_flags'));

$res = adminPost('meldungen.php', ['meldung' => $mVokabel, 'stimmt' => 1]);
ok('"Stimmt so" erledigt alle Meldungen der Vokabel',
   (int) qv('SELECT COUNT(*) FROM vocab_flags WHERE vocab_id = ?', [$mVokabel]) === 0);
ok('Und laesst die Vokabel, wie sie war',
   qv('SELECT term_foreign FROM vocab WHERE id = ?', [$mVokabel]) === $mWort);
ok('Und die naechste rueckt nach, statt dass diese stehen bleibt',
   !str_contains($res['body'], 'name="meldung" value="' . $mVokabel . '"'));

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
    ok("$seite zeigt die Schulen als Knoepfe",
       str_contains($res['body'], 'class="chips"')
       && str_contains($res['body'], 'class="chip'), "Status {$res['status']}");
    /*
     * Die Auswahl beginnt bei der Schule, nicht beim Kind. Das Kind war der
     * Einstieg, solange es seine Vokabeln besass; seit sie einem Kurs
     * gehoeren, fuehrte es zu 28 gleichen Antworten.
     */
    ok("$seite waehlt nicht mehr ueber das Kind",
       !str_contains($res['body'], '<span class="lbl">Kind</span>'));
}

// Ein Klick auf eine Schule fuehrt zu einem Link, der genau diese setzt.
$res = http($base . '/admin/vocab.php');
ok('Der Knopf verweist auf die Schule',
   str_contains($res['body'], 'vocab.php?school=' . $testSchule), 'Link nicht gefunden');

// Oben wechseln muss die tiefere Auswahl fallenlassen, sonst zeigte der
// Filter auf eine Lerneinheit, die zum neuen Kurs nicht gehoert.
$res = http($base . '/admin/vocab.php?' . http_build_query($adminWahl));

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

$schulLinks = $zeile($res['body'], 'Schule');
ok('Beim Schulwechsel fallen Kurs und Lerneinheit weg',
   $schulLinks !== [] && !array_filter($schulLinks,
       static fn (string $l): bool => str_contains($l, 'course=') || str_contains($l, 'unit=')),
   implode(' ', $schulLinks));

/*
 * Die Zeile "Kurs" traegt jetzt wirklich den Kurs. Frueher trug sie die
 * Sprache (?language=) und hiess nur Kurs.
 */
$kursLinks = $zeile($res['body'], 'Kurs');
ok('Die Kurszeile hat Knoepfe', $kursLinks !== []);
ok('Sie setzt den Kurs, nicht die Sprache',
   $kursLinks !== [] && !array_filter($kursLinks,
       static fn (string $l): bool => str_contains($l, 'language=') || !str_contains($l, 'course=')),
   implode(' ', $kursLinks));
ok('Beim Kurswechsel faellt die Lerneinheit weg',
   $kursLinks !== [] && !array_filter($kursLinks,
       static fn (string $l): bool => str_contains($l, 'unit=')),
   implode(' ', $kursLinks));

$einheitLinks = $zeile($res['body'], 'Lerneinheit');
ok('Die Lerneinheit-Knoepfe behalten Schule und Kurs',
   $einheitLinks !== [] && !array_filter($einheitLinks,
       static fn (string $l): bool => !str_contains($l, 'course=') || !str_contains($l, 'school=')),
   implode(' ', $einheitLinks));

ok('Die gewaehlte Lerneinheit ist hervorgehoben',
   str_contains($res['body'], 'class="chip on"'));

// Die Filter muessen weiter ueber die URL steuerbar sein.
$res = http($base . '/admin/sentences.php?' . http_build_query(['course' => $adminKurs]));
ok('Filter per URL wirken weiterhin', $res['status'] === 200
   && str_contains($res['body'], 'Testkind'));
ok('Und die Einheit fuehrt zu ihren Vokabeln',
   str_contains($res['body'], 'unit=' . $unitId));

section('Accounts nach Schule und Klasse');

/*
 * Eine Karte je Konto mit allen Formularen offen - bei drei Kindern ging
 * das, bei einer Schule mit dreihundert nicht mehr. Jetzt: Filter, eine
 * Zeile je Konto, die Formulare aufklappbar.
 */
$res = http($base . '/admin/users.php?school=' . $testSchule);
ok('Die Kontenliste laesst sich nach Schule filtern',
   str_contains($res['body'], 'class="chip on"') && str_contains($res['body'], 'Testkind'));
ok('Mit einer Zeile je Konto, zum Aufklappen',
   str_contains($res['body'], '<details class="konto'));
ok('Sie nennt Kurse statt Sprachen',
   str_contains($res['body'], 'Kurs') && !preg_match('/\d+ Sprachen,/', $res['body']));
ok('Und die Sofortsuche findet auch die Zeilen dort',
   str_contains($res['body'], 'data-filter-ziel="kontenliste"'));

$andereSchule = (int) (qv('SELECT id FROM schools WHERE id <> ? ORDER BY id LIMIT 1', [$testSchule]) ?? 0);
if ($andereSchule > 0) {
    $res = http($base . '/admin/users.php?school=' . $andereSchule);
    ok('In einer anderen Schule steht das Testkind nicht',
       !str_contains($res['body'], '>Testkind<'));
}

$res = http($base . '/admin/users.php?' . http_build_query(['school' => $testSchule, 'rolle' => 1]));
ok('Der Filter Lehrkraefte laesst Kinder weg', !str_contains($res['body'], '>Testkind<'));

$res = adminPost('users.php', ['set_password' => '1', 'id' => $userId, 'password' => 'geheim123',
                               'school' => $testSchule],
                 'school=' . $testSchule);
ok('Nach dem Speichern bleibt der Filter stehen',
   str_contains($res['body'], 'Passwort gesetzt')
   && preg_match('#users\.php\?school=' . $testSchule . '&amp;rolle=#', $res['body']) === 1,
   'die Rollen-Knoepfe tragen die Schule weiter');

$res = http($base . '/admin/users.php');
ok('Die Rueckfrage beim Loeschen verspricht nicht mehr "alle Vokabeln"',
   !str_contains($res['body'], 'mit allen Sprachen und Vokabeln'));


section('Farbwahl im Admin');

require_once __DIR__ . '/../lib/colors.php';

ok('Die Palette hat 49 Farben - sieben mal sieben', count(color_palette()) === 49,
   (string) count(color_palette()));
ok('Alle sind gültige Hexwerte',
   count(array_filter(color_palette(),
       static fn (string $c): bool => preg_match('/^#[0-9a-f]{6}$/', $c) === 1)) === 49);
ok('Und alle verschieden', count(array_unique(color_palette())) === 49);
// Der Rueckfall heisst Blau und muss es auch sein - als Index [27] war er
// nach dem Wechsel auf 7x7 still ein Gruen geworden.
[$rb, $gb, $bb] = sscanf(color_default(), '#%02x%02x%02x');
ok('Die Standardfarbe ist ein Blau', $bb > $rb && $bb > $gb, color_default());

$res = http($base . '/admin/users.php');
ok('Die Seite zeigt das Farbfeld', str_contains($res['body'], 'class="palette"'));
ok('Kein Auswahlfeld mehr für die Farbe', !str_contains($res['body'], '<select name="color"'));
ok('49 Kacheln stehen zur Wahl',
   substr_count($res['body'], 'class="swatch-pick"') >= 49,
   (string) substr_count($res['body'], 'class="swatch-pick"'));

// Das Feld liegt zugeklappt hinter einem Knopf - offen in der Zeile schrumpften
// die Kacheln auf Pixelgrösse.
ok('Die Farbwahl steckt in einem Flyout', str_contains($res['body'], 'class="colorpick"'));
ok('Der Knopf zeigt die aktuelle Farbe', str_contains($res['body'], 'class="swatch-current"'));
ok('Und ist zugeklappt', !preg_match('/<details class="colorpick" open/', $res['body']));

// Eine Farbe aus dem Feld setzen.
$farbe = color_palette()[40];
adminPost('users.php', ['update' => '1', 'id' => $userId, 'school_id' => $testSchule,
                        'display_name' => 'Testkind', 'color' => $farbe, 'active' => '1']);
ok('Gewählte Farbe wird gespeichert',
   qv('SELECT color FROM users WHERE id = ?', [$userId]) === $farbe,
   (string) qv('SELECT color FROM users WHERE id = ?', [$userId]));

$res = http($base . '/admin/users.php');
ok('Und ist im Feld als gewählt markiert',
   str_contains($res['body'], 'value="' . $farbe . '" checked'));

// Unsinn darf nicht durchrutschen.
adminPost('users.php', ['update' => '1', 'id' => $userId, 'school_id' => $testSchule,
                        'display_name' => 'Testkind', 'color' => 'rot; drop table', 'active' => '1']);
ok('Ungültige Farbe wird abgefangen',
   preg_match('/^#[0-9a-f]{6}$/', (string) qv('SELECT color FROM users WHERE id = ?', [$userId])) === 1,
   (string) qv('SELECT color FROM users WHERE id = ?', [$userId]));

// Das Symbol muss mit heller wie dunkler Farbe lesbar bleiben.
foreach ([color_palette()[0] => 'sehr hell', color_palette()[6] => 'sehr dunkel'] as $c => $was) {
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

/*
 * Beides steht jetzt im Einstellungsmenue, nicht mehr als Symbol in der
 * Ecke. Es stand dort nur auf der Startseite - wer mitten im Ueben eine
 * neue Fassung holen wollte, musste erst zurueck.
 */
$view = file_get_contents(__DIR__ . '/../core.js');
ok('In der App steht dort Aktualisieren statt Abmelden',
   str_contains($view, 'VT.standalone') && str_contains($view, 'data-nav-refresh'));
ok('Im Browser bleibt das Abmelden', str_contains($view, 'data-nav-logout'));

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

/*
 * vorrat.js und menue.js gehoerten lange nicht dazu.
 *
 * Damit bewegte sich der Versionsstempel nicht, wenn sich der Vorrat
 * aenderte - eine auf dem Homescreen liegende App erfuhr von der Aenderung
 * also gar nichts und uebte wochenlang mit der alten Fassung weiter. Die
 * Liste entsteht jetzt aus glob() statt aus vier Namen von Hand.
 */
foreach (['vorrat.js', 'menue.js'] as $datei) {
    ok("Auch $datei steht in der Liste", str_contains($liste, '"' . $datei . '"'),
       'sonst merkt eine installierte App nichts von einer Aenderung daran');
}
ok('Und keine Datei steht doppelt darin',
   count(app_assets()) === count(array_unique(app_assets())));


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
/*
 * Drei mehr als frueher: navQuelle, navAbmelden und serieQuelle. Sie sind
 * der Grund, warum core.js die Menues und das Abzeichen bauen kann, ohne den
 * Vorrat zu kennen - vorrat.js holt sich von dort VT und api(), ein Import
 * in die andere Richtung waere ein Ring. app.js reicht die Faeden herein,
 * und nur deshalb bleibt die Richtung eindeutig.
 *
 * Die Zahl ist ein Budget, kein Naturgesetz: Sie darf steigen, wenn ein
 * weiterer Faden dieselbe Richtung eindeutig haelt - aber nicht, weil es
 * gerade bequem war, noch einen Baustein aus core.js zu holen.
 */
ok('app.js haelt sich bei core.js zurueck',
   count($ausCore) <= 9, implode(', ', $ausCore));

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

/*
 * Bindestrich statt Leerzeichen.
 *
 * Ein Leerzeichen im Passwort ist auf einer Tablet-Tastatur die grosse
 * Taste unten, und wer sie zweimal trifft, kommt nicht hinein. Der
 * Bindestrich laesst sich nicht unsichtbar verdoppeln, und ein Doppelklick
 * markiert das ganze Wort statt nur der Haelfte.
 *
 * Nur fuer neue: Was vergeben ist, bleibt. Weiter unten steht die Probe
 * dafuer.
 */
for ($i = 0; $i < 300; $i++) {
    $pw = password_generate();
    if ($pw === null || !str_contains($pw, '-')) {
        $falsch = var_export($pw, true);
        break;
    }
    [$adj, $tier] = explode('-', $pw, 2);
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
ok('Sie bestehen aus zwei Wörtern mit Bindestrich, ohne Ziffern',
   $erzeugt !== [] && array_filter($erzeugt,
       static fn ($p) => preg_match('/^\p{L}+-\p{L}+$/u', $p) !== 1) === []);
ok('Und keines trägt noch ein Leerzeichen',
   array_filter($erzeugt, static fn ($p) => str_contains($p, ' ')) === [],
   'auf einer Tablet-Tastatur die grosse Taste unten - zweimal getroffen, und das Kind kommt nicht hinein');
ok('Sie wiederholen sich nicht ständig',
   count(array_unique($erzeugt)) > 250, count(array_unique($erzeugt)) . ' verschiedene');

/*
 * Was vergeben ist, bleibt. password_tidy() raeumt weiterhin Leerzeichen
 * auf - ein Kind, dessen Zettel "mueder Gepard" nennt, muss sich damit
 * weiter anmelden koennen, auch wenn neue Passwörter anders aussehen.
 */
ok('Ein altes Passwort mit Leerzeichen bleibt tippbar',
   password_tidy('  müder   Gepard ') === 'müder Gepard',
   '[' . password_tidy('  müder   Gepard ') . ']');

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

/*
 * Auch fuer die Lehrkraft. In der App soll sie genau das sehen, was ihre
 * Klasse sieht - vorher sah sie beim Auswaehlen alle Vokabeln und im
 * Lueckentext nur die mit Saetzen, also zwei Zahlen fuer dieselbe Einheit
 * und keine davon die der Klasse. Alles zu sehen ist Sache des
 * Lehrkraft-Bereichs, und der fragt ohne diese Grenze ab.
 */
$lehrRow = ['id' => 0, 'role' => ROLE_TEACHER];
ok('Auch eine Lehrkraft sieht nur bis zur Marke',
   visible_position($lehrRow, $freiUnitRow) === 3,
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
ok('Die Lehrkraft sieht in der App dasselbe wie ihre Klasse',
   count($gesehen) === 3, count($gesehen) . ' statt 3');

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
   $s === 422, 'Status ' . $s . ' ' . var_export($d, true));

/*
 * Und es sagt die Wahrheit. "Enthaelt keine Vokabeln" waere hier gelogen -
 * die Einheit hat zehn, sie sind nur noch nicht dran. Ein Kind, dem man das
 * falsch erklaert, sucht den Fehler bei sich.
 */
ok('Und nennt den richtigen Grund - nicht freigegeben, nicht leer',
   str_contains((string) ($d['error'] ?? ''), 'freigegeben'),
   (string) ($d['error'] ?? ''));

$leereUnit = makeUnit($userId, $freiLang, 'Wirklich leer');
[$d, $s] = apiCall('quiz', 'next', null, ['unit_id' => $leereUnit]);
ok('Eine wirklich leere Einheit bekommt weiter ihre eigene Meldung',
   str_contains((string) ($d['error'] ?? ''), 'keine Vokabeln'),
   (string) ($d['error'] ?? ''));
q('DELETE FROM units WHERE id = ?', [$leereUnit]);

// ---- Was ueberhaupt Saetze bekommt, haengt an der Freigabe.

require_once __DIR__ . '/../lib/sentences.php';

/*
 * Saetze entstehen fuer das, was freigegeben ist - und nur dafuer.
 *
 * Ein Lueckensatz wird gebraucht, wenn ein Kind ihn ueben soll, und ueben
 * kann es nur, was aufgemacht ist. Eine Vokabel, die gerade von Hand
 * dazugekommen ist, steht hinter der Marke und wartet; ihr Satz wartet mit.
 *
 * Das war eine Weile andersherum - es entstand alles gleich beim Einlesen,
 * damit niemand nach dem Freigeben warten muss. Der Grund ist weggefallen:
 * Die Freigabe antwortet inzwischen zuerst und arbeitet danach weiter, die
 * Seite sagt "entsteht gerade" und laedt sich von selbst nach.
 */
q('UPDATE units SET released_position = 4 WHERE id = ?', [$freiUnit]);
ok('Saetze entstehen nur fuer Freigegebenes',
   count(sentence_candidates($freiUnit)) === 4,
   count(sentence_candidates($freiUnit)) . ' von 4');

q('UPDATE units SET released_position = 0 WHERE id = ?', [$freiUnit]);
ok('Ohne Freigabe gibt es nichts zu erzeugen',
   count(sentence_candidates($freiUnit)) === 0,
   count(sentence_candidates($freiUnit)) . ' - was zu ist, wird nicht geuebt');

q('UPDATE units SET released_position = 10 WHERE id = ?', [$freiUnit]);
ok('Und ist alles auf, sind es alle',
   count(sentence_candidates($freiUnit)) === 10,
   count(sentence_candidates($freiUnit)) . ' von 10');

/*
 * Der Wortschatz-Vorspann fuer den Prompt: andere Einheiten desselben
 * KURSES, nicht derselben Sprache. Bei einem einzelnen Kurs ist das
 * dasselbe, an einer Schule mit mehreren wanderte sonst der Wortschatz
 * fremder Klassen in die Anfrage.
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
ok('Und zwar auch das dort noch Gesperrte - Saetze entstehen fuer alles',
   in_array('lima', $bekannt, true), implode(', ', $bekannt));
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

function freiPost(string $url, ?array $post, array $header = [],
                 bool $mitKopf = false): array
{
    global $freiJar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $freiJar,
        CURLOPT_COOKIEFILE     => $freiJar,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HEADER         => $mitKopf,
    ]);
    if ($header !== []) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    }
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $roh    = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $laenge = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if (!$mitKopf) {
        return ['status' => $status, 'body' => $roh, 'header' => ''];
    }
    return ['status' => $status,
            'header' => substr($roh, 0, $laenge),
            'body'   => substr($roh, $laenge)];
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
 * Der Balken selbst laesst sich hier nicht ziehen - dazu braucht es einen
 * Browser, der wirklich rechnet. Was diese Suite pruefen kann, ist die
 * Form: dass der Balken nicht wieder in die Tabelle wandert.
 *
 * Denn genau daran lag es. Als eigene Tabellenzeile liess er sich um eine
 * Vokabel verschieben und dann nicht mehr: insertBefore haengt das Element
 * um, und ein umgehaengtes Element verliert seine Bindung an den Zeiger.
 * Von dreissig Bewegungen kamen danach drei an. Nachgemessen wurde das mit
 * einem echten Chrome; hier steht der Riegel, der den Rueckweg zumacht.
 */
// Fuer den Blick auf die Tabelle kurz etwas freigeben - danach wieder zu.
freiPost($base . '/teacher/unit.php?id=' . $freiUnit, [
    'release' => 3, 'unit_id' => $freiUnit, 'csrf' => $freiCsrf,
]);
$res = freiPost($base . '/teacher/unit.php?id=' . $freiUnit, null);
ok('Die Tabelle trennt freigegebene und gesperrte Zeilen',
   str_contains($res['body'], 'class="released"')
   && str_contains($res['body'], 'class="locked"'));
ok('Und jede Zeile sagt, die wievielte sie ist',
   preg_match('/<tr class="(?:released|locked)" data-pos="1">/', $res['body']) === 1);
ok('Die Marke steht an der Tabelle, damit das Skript sie findet',
   str_contains($res['body'], 'data-released="'));
ok('Ohne JavaScript bleibt je Zeile ein Knopf',
   str_contains($res['body'], 'js-hide')
   && str_contains($res['body'], 'form="releaseform"'),
   'sonst ist die Freigabe ohne Skript nicht bedienbar');

freiPost($base . '/teacher/unit.php?id=' . $freiUnit, [
    'release' => 0, 'unit_id' => $freiUnit, 'csrf' => $freiCsrf,
]);

$skriptB = http($base . '/teacher/teacher.js');
ok('Das Skript legt den Balken ueber die Tabelle, nicht hinein',
   str_contains($skriptB['body'], "className = 'releasewrap'")
   && !preg_match("/createElement\('tr'\)[^;]*;\s*\N*releasebar/", $skriptB['body']),
   'als <tr> verliert er beim Verschieben die Zeigerbindung');
ok('Er bindet den Zeiger an sich',
   str_contains($skriptB['body'], 'setPointerCapture'));
ok('Und folgt ihm frei, statt von Grenze zu Grenze zu springen',
   str_contains($skriptB['body'], 'const folgen =')
   && str_contains($skriptB['body'], "balken.style.top"),
   'sonst ruckelt er zeilenweise statt sich ziehen zu lassen');
ok('Gespeichert wird beim Loslassen, nicht schon beim Ziehen',
   preg_match('/const loslassen[^}]*speichern\(\)/s', $skriptB['body']) === 1);
ok('Die Zahl haengt in einer eigenen Blase',
   str_contains($skriptB['body'], "class=\"bubble\"")
   && str_contains($skriptB['body'], 'blase.textContent'),
   'im Balken selbst war sie nicht zu lesen');
ok('Am Fensterrand rollt die Seite mit',
   str_contains($skriptB['body'], 'window.scrollBy'),
   'sonst endet das Ziehen am unteren Bildrand');

$cssB = (string) file_get_contents(__DIR__ . '/../admin/admin.css');
ok('Die Huelle ist der Bezugspunkt fuer den Balken',
   preg_match('/\.releasewrap\s*\{[^}]*position:\s*relative/s', $cssB) === 1);
ok('Der Balken liegt darin absolut',
   preg_match('/\.releasebar\s*\{[^}]*position:\s*absolute/s', $cssB) === 1);
ok('Und faengt den Zeiger auch auf dem Handy ab',
   preg_match('/\.releasebar\s*\{[^}]*touch-action:\s*none/s', $cssB) === 1,
   'sonst scrollt das Handy statt zu ziehen');


// ---- Die Tabelle selbst: drei Spalten, mehr passt auf ein Telefon nicht.

/*
 * Vorher waren es fuenf: die laufende Nummer, das fremde Wort, das
 * deutsche, die Zahl der Lueckensaetze und die Handgriffe. Am Telefon
 * blieben fuer die Woerter damit keine sechzig Pixel - und keine der
 * beiden Zahlen sagte etwas, das nicht anderswo steht: wie viel
 * freigegeben ist, sagt die Blase am Balken, und wie viele Saetze fehlen,
 * der Knopf "Saetze nachtragen".
 */
$res = freiPost($base . '/teacher/unit.php?id=' . $freiUnit, null);

preg_match('/<thead>.*?<\/thead>/s', $res['body'], $km);
$kopf = $km[0] ?? '';
ok('Der Tabellenkopf steht in einem <thead>', $kopf !== '',
   'ohne ihn kann er beim Rollen nicht stehenbleiben');
$spalten = preg_match_all('/<th[\s>]/', $kopf);
ok('Und traegt genau drei Spalten', $spalten === 3, $spalten . ' Spalten');
/*
 * Im Kopf steht die Sprache, nicht das Wort "Fremdsprache" - und vor beiden
 * Spalten ihre Fahne. Die Spalte sagt damit, was in ihr steht, statt was
 * sie ist.
 */
$kopfSprache = (string) qv('SELECT l.name FROM units t
    JOIN languages l ON l.id = t.language_id WHERE t.id = ?', [$freiUnit]);
ok('Im Kopf steht die Sprache des Kurses',
   str_contains($kopf, '>' . h($kopfSprache) . '</th>'), $kopfSprache);
ok('Und daneben Deutsch', str_contains($kopf, '>Deutsch</th>'));
ok('Das Wort "Fremdsprache" steht nicht mehr da', !str_contains($kopf, 'Fremdsprache'));
ok('Vor beiden steht eine Fahne', substr_count($kopf, 'kopfflagge') === 2,
   substr_count($kopf, 'kopfflagge') . ' statt 2');
ok('Und die deutsche ist die deutsche', str_contains($kopf, '1f1e9-1f1ea.svg'),
   'die deutsche Seite heisst immer Deutsch');
/*
 * Die beiden Fragen gelten der Spaltenzeile, nicht dem ganzen <thead>: Dort
 * steht seit neuestem auch "Nichts freigeben", und dessen Schloss ist eine
 * Zeichenentitaet - mit einem # darin.
 */
preg_match('/<tr>\s*<th.*?<\/tr>/s', $kopf, $kzm);
$kopfzeile = $kzm[0] ?? '';
ok('Die Spaltenzeile liess sich herausloesen', $kopfzeile !== '');
ok('Die laufende Nummer ist weg', !str_contains($kopfzeile, '#'));
ok('Und die Satzzahl auch', !str_contains($kopfzeile, 'Sätze'));

preg_match('/<tr class="(?:released|locked)" data-pos="1">.*?<\/tr>/s', $res['body'], $zm);
$zeile = $zm[0] ?? '';
ok('Eine Vokabelzeile hat ebenso drei Zellen', substr_count($zeile, '<td') === 3,
   substr_count($zeile, '<td') . ' Zellen');

/*
 * Und die Spaltenueberschrift steht im Kopf, nicht noch einmal in jeder
 * Zelle. data-label ist das, woraus die Karten am Telefon ihre
 * Beschriftung ziehen - in einer Tabelle, die eine Tabelle bleibt, stand
 * dadurch vor jedem Wort noch einmal "FREMDSPRACHE".
 */
ok('Keine Zelle traegt die Spaltenueberschrift noch einmal',
   !str_contains($zeile, 'data-label'),
   'sie steht schon im Kopf');

// ---- Aendern und Loeschen sind Sinnbilder, keine Saetze.

/*
 * Beide als Emoji, also mit der Variantenwahl dahinter: Ohne sie waehlt der
 * Browser die Textform, und dann steht neben einem farbigen Muelleimer ein
 * blasser Strich, der ein Stift sein soll.
 */
ok('Aendern ist ein Stift', str_contains($zeile, 'iconaction quiet nurbild')
   && str_contains($zeile, '&#9999;&#65039;'));
ok('Loeschen ist ein Muelleimer', str_contains($zeile, 'iconaction danger nurbild')
   && str_contains($zeile, '&#128465;&#65039;'));
ok('Und jeder Knopf hat trotzdem einen Namen',
   substr_count($zeile, 'class="nurvorlesen"') === 4,
   substr_count($zeile, 'class="nurvorlesen"')
   . ' von 4 - ein Knopf aus bloss einem Zeichen heisst sonst "Schaltflaeche"');
ok('Der reine Sinnbildknopf hat eine eigene Regel',
   preg_match('/\.iconaction\.nurbild\s*\{/', $cssB) === 1);
ok('Und der vorgelesene Name wird wirklich versteckt',
   preg_match('/\.nurvorlesen\s*\{[^}]*clip-path:/s', $cssB) === 1,
   'display: none nimmt ihn auch dem Vorleseprogramm');

// ---- Der Kopf bleibt beim Rollen stehen.

ok('Der Tabellenkopf klebt',
   preg_match('/table\.release thead th\s*\{[^}]*position:\s*sticky/s', $cssB) === 1);
ok('Und zwar unter der Leiste, nicht hinter ihr',
   preg_match('/table\.release thead th\s*\{[^}]*top:\s*var\(--barhoehe/s', $cssB) === 1);
ok('Deren Hoehe misst das Skript',
   str_contains($skriptB['body'], "'--barhoehe'")
   && str_contains($skriptB['body'], 'function initBarHoehe'),
   'am Telefon bricht die Leiste um und ist doppelt so hoch');
/*
 * Und die Tabelle darf kein Rollbehaelter sein: table.data traegt
 * overflow: hidden fuer die runden Ecken, und damit wird SIE der Bezug des
 * Klebens statt des Fensters. Der Kopf stand dann um die Leistenhoehe
 * versetzt zwischen der ersten und der zweiten Zeile.
 */
ok('Die Tabelle ist dafuer kein Rollbehaelter',
   preg_match('/table\.release\s*\{\s*overflow:\s*clip/s', $cssB) === 1,
   'mit overflow: hidden klebt der Kopf an der Tabelle statt am Fenster');
/*
 * Der Rahmen unter dem Zeiger nur da, wo es einen Zeiger gibt.
 *
 * Auf einem Telefon bleibt :hover nach einer Beruehrung haengen und wandert
 * beim Rollen unter dem Finger von Zeile zu Zeile mit - ein Kaestchen um
 * jede Zelle, das beim Scrollen springt. Gemeint war es als Vorschau fuer
 * den Klick.
 *
 * Geprueft am Quelltext und nicht im Browser: Die Geraeteemulation von
 * Chrome meldet weiterhin (hover: hover), der Fall laesst sich dort also
 * gar nicht herstellen.
 */
ok('Der Zeilenrahmen haengt an einem Zeiger',
   preg_match('/@media \(hover: hover\)\s*\{\s*table\.release\.draggable '
              . 'tr\[data-pos\]:hover td/s', $cssB) === 1,
   'sonst klebt er am Telefon nach der Beruehrung fest');
ok('Und der Zeilenhintergrund der anderen Tabellen auch',
   preg_match('/@media \(hover: hover\)\s*\{\s*table\.rowlink '
              . 'tr\[data-href\]:hover td/s', $cssB) === 1);

ok('Der Balken liegt unter dem Kopf, nicht darueber',
   preg_match('/\.releasebar\s*\{[^}]*z-index:\s*3/s', $cssB) === 1,
   'sonst schiebt er sich beim Rollen ueber die Spaltennamen');

// ---- Am Telefon bleibt sie eine Tabelle, und zwar eine passende.

ok('Die Telefonregeln nehmen die Freigabetabelle aus',
   preg_match('/table\.data\.release tr:has\(> th\)\s*\{\s*display:\s*table-row/s', $cssB) === 1,
   'sonst wird auch hier jede Zeile zur Karte - und der Balken braucht Zeilen');
ok('Und verteilen die Breite fest',
   preg_match('/table\.data\.release\s*\{\s*table-layout:\s*fixed/s', $cssB) === 1,
   'sonst rutschen die Spalten, sobald eine Zeile zum Formular wird');


// ---- Die drei Wege, eine Lerneinheit zu erweitern.

/*
 * Die Anlegezeile stand bis hierher als letzte Zeile IN der Freigabetabelle
 * - am falschen Ort: Die Tabelle zeigt, was freigegeben ist, der Balken
 * laeuft durch sie hindurch, und eine Zeile mit zwei leeren Feldern
 * mittendrin sieht aus wie eine Vokabel ohne Wort. Jetzt stehen darunter
 * drei gleichwertige Wege nebeneinander.
 */
$res = freiPost($base . '/teacher/unit.php?id=' . $freiUnit, null);

/*
 * Die Anlegezeile steht wieder IN der Tabelle - aber als Zusatzzeile unter
 * den Vokabeln, nicht als Teil der Freigabe: kein data-pos, keine
 * released/locked-Klasse, damit der Balken sie nicht mitzaehlt.
 *
 * Dazwischen lag sie eine Weile am Knopf darunter. Dort war sie weit weg
 * von dem, was entsteht - man tippt eine Vokabel und sieht sie zwei
 * Bildschirme hoeher erscheinen.
 */
preg_match('/<table class="data release".*?<\/table>/s', $res['body'], $tm);
ok('Die Anlegezeile steht am Fuss der Tabelle',
   str_contains($tm[0] ?? '', 'id="handzeile"')
   && str_contains($tm[0] ?? '', 'name="new_f"'),
   'dort, wo die neue Vokabel gleich stehen wird');
ok('Und zaehlt fuer den Balken nicht mit',
   preg_match('/<tr class="newrow" id="handzeile"[^>]*>/', $tm[0] ?? '') === 1
   && !preg_match('/id="handzeile"[^>]*data-pos/', $tm[0] ?? ''));
ok('Zugeklappt, bis jemand sie will',
   preg_match('/id="handzeile"[^>]*hidden/', $tm[0] ?? '') === 1);

/*
 * Die Ueberschrift heisst nach dem, was darunter steht - und das stimmt
 * auch fuer eine leere Lerneinheit: "erweitern" kann man nur, was es
 * schon gibt.
 */
ok('Darunter steht "Vokabeln zur Lerneinheit hinzufuegen"',
   str_contains($res['body'], '<h2>Vokabeln zur Lerneinheit hinzufügen</h2>'));
$teacherJs = (string) file_get_contents(__DIR__ . '/../teacher/teacher.js');

ok('Mit vier Karten - drei Wege, der letzte in zwei Fassungen',
   substr_count($res['body'], 'class="card erweiternkarte"') === 4,
   substr_count($res['body'], 'class="card erweiternkarte"') . ' statt 4');

ok('Von Hand: ein Link auf dieselbe Seite',
   preg_match('/<a class="card erweiternkarte" id="vonHand"\s+href="[^"]*vonhand=1/s',
              $res['body']) === 1,
   'ohne JavaScript laedt sie neu und die Zeile steht da');
ok('Aus Dateien: ohne Skript weiterhin der Weg in die Einleseansicht',
   preg_match('/<a class="card erweiternkarte" id="ausDateien" href="[^"]*#\/lang\/\d+\/import"/',
              $res['body']) === 1,
   'mit Skript faengt teacher.js den Klick ab und oeffnet den Dateidialog');

/*
 * Der dritte Weg steht zweimal da, weil er zwei ist.
 *
 * Am Rechner ist die Kamera woanders - dort fuehrt ein QR-Code das Telefon
 * hierher. Am Telefon waere derselbe Code Unsinn; dort oeffnet der Knopf
 * die Kamera. Welches Geraet davorsitzt, weiss nur der Browser: Beide
 * Karten stehen im HTML, das Skript blendet die falsche aus. Ohne Skript
 * bleibt der QR-Code stehen - das ist der Weg, der auch ohne Kamera
 * weiterhilft.
 */
ok('Am Rechner: der QR-Code',
   preg_match('/<button class="card erweiternkarte" type="button" data-handoff id="perQr">/',
              $res['body']) === 1);
ok('Am Telefon: die Kamera - vorerst ausgeblendet',
   preg_match('/<button class="card erweiternkarte" type="button" id="perKamera" hidden>/',
              $res['body']) === 1,
   'sichtbar macht sie das Skript, wenn der Zeiger grob und die Finger mehrere sind');
ok('Und das Skript entscheidet danach, nicht nach dem Kennzeichen der Anfrage',
   str_contains($teacherJs, "matchMedia('(pointer: coarse)')")
   && str_contains($teacherJs, 'navigator.maxTouchPoints > 1')
   && !str_contains($teacherJs, 'userAgent'),
   'ein iPad meldet sich seit Jahren als Mac');

ok('Und das Fenster mit dem Code steht auch hier',
   str_contains($res['body'], 'id="handoff"')
   && str_contains($res['body'], 'data-unit="' . $freiUnit . '"'),
   'und es zeigt auf die Lerneinheit, nicht mehr auf den Kurs');
ok('Der Kurs steht nicht mehr darin',
   !str_contains($res['body'], 'data-course="'),
   'das Ziel ist genau diese Seite');

// ---- Einlesen ohne Umweg: Dateidialog, Ablage, Erkennen.

/*
 * Das Einlesen war eine eigene Ansicht in der App: Knopf druecken, Seite
 * wechselt, Dateien waehlen, Titel eintippen, zurueckfinden. Wer an einer
 * Lerneinheit arbeitet, hat die Lerneinheit schon - der Umweg fuehrte an
 * einen Ort, der von ihr nichts weiss.
 */
ok('Zwei Dateifelder liegen versteckt daneben',
   str_contains($res['body'], '<input type="file" id="bildwahl" accept="image/*" multiple hidden')
   && str_contains($res['body'], 'id="kamerawahl" accept="image/*" capture="environment"'),
   'eines fuer den Dateidialog, eines fuer die Kamera');

ok('Die Ablage steht leer im HTML',
   preg_match('/<section class="stapel" id="stapel" hidden/', $res['body']) === 1);
ok('Und weiss, wohin sie gehoert',
   str_contains($res['body'], 'data-unit="' . $freiUnit . '"')
   && preg_match('/data-language="\d+"/', $res['body']) === 1
   && str_contains($res['body'], 'data-api="')
   && str_contains($res['body'], 'data-bilder="'),
   'Sprache fuer das Erkennen, Einheit fuer das Speichern');
ok('Die Lupe ist ein Fenster, kein neuer Tab',
   str_contains($res['body'], '<dialog id="lupe" class="lupe">'),
   'das Bild liegt im Browser - es gibt keine Adresse, die sich oeffnen liesse');
ok('Und die Ablage sagt, dass die Maschine sich verlesen kann',
   preg_match('/Was dabei herauskommt, kann Fehler enthalten/', $res['body']) === 1);

ok('Die Bildverkleinerung steht nur noch einmal im Quelltext',
   file_exists(__DIR__ . '/../views/bilder.js')
   && str_contains((string) file_get_contents(__DIR__ . '/../views/import.js'),
                   "from './bilder.js'")
   && str_contains($teacherJs, 'stapel.dataset.bilder'),
   'zwei Abschriften waeren bald zwei verschiedene Bildgroessen');

// ---- Erkannte Vokabeln anhaengen.

$vorherStand = (int) qv('SELECT released_position FROM units WHERE id = ?', [$freiUnit]);
$vorherZahl  = (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ?', [$freiUnit]);

$scan = freiPost($base . '/teacher/unit.php?id=' . $freiUnit, [
    'add_scanned' => '1', 'unit_id' => $freiUnit, 'csrf' => $freiCsrf,
    'entries' => json_encode([
        ['foreign' => 'gescannt-eins', 'native' => 'Eins', 'word_type' => 'noun'],
        ['foreign' => 'gescannt-zwei', 'native' => 'Zwei'],
        ['foreign' => '', 'native' => 'ohne Wort'],
    ]),
], ['X-Requested-With: fetch']);
$scanDaten = json_decode($scan['body'], true);

ok('Erkannte Vokabeln haengen sich an die Lerneinheit an',
   ($scanDaten['ok'] ?? false) === true && ($scanDaten['dazu'] ?? 0) === 2,
   $scan['body']);
ok('Halbe Paare fallen dabei weg',
   (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ?', [$freiUnit])
   === $vorherZahl + 2);
ok('Sie stehen hinten, nicht vorne',
   (string) qv('SELECT term_foreign FROM vocab WHERE unit_id = ? ORDER BY position DESC LIMIT 1',
               [$freiUnit]) === 'gescannt-zwei');
ok('Und die Freigabe ruehrt das nicht an',
   (int) qv('SELECT released_position FROM units WHERE id = ?', [$freiUnit]) === $vorherStand,
   'frisch Eingelesenes ist fuer die Klasse zunaechst unsichtbar - das ist der Sinn dieser Seite');

$nochmal = freiPost($base . '/teacher/unit.php?id=' . $freiUnit, [
    'add_scanned' => '1', 'unit_id' => $freiUnit, 'csrf' => $freiCsrf,
    'entries' => json_encode([
        ['foreign' => 'gescannt-eins', 'native' => 'Eins'],
        ['foreign' => 'gescannt-drei', 'native' => 'Drei'],
    ]),
], ['X-Requested-With: fetch']);
$nochmalDaten = json_decode($nochmal['body'], true);
ok('Dieselbe Buchseite zweimal fotografiert gibt keine Duplikate',
   ($nochmalDaten['dazu'] ?? 0) === 1 && ($nochmalDaten['doppelt'] ?? 0) === 1,
   $nochmal['body']);
ok('Und "gescannt-eins" steht weiterhin genau einmal drin',
   (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ? AND term_foreign = ?',
            [$freiUnit, 'gescannt-eins']) === 1);

$ohneMarke = freiPost($base . '/teacher/unit.php?id=' . $freiUnit, [
    'add_scanned' => '1', 'unit_id' => $freiUnit, 'csrf' => 'falsch',
    'entries' => json_encode([['foreign' => 'heimlich', 'native' => 'Heimlich']]),
], ['X-Requested-With: fetch']);
ok('Ohne gueltiges CSRF-Feld kommt nichts an',
   (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ? AND term_foreign = ?',
            [$freiUnit, 'heimlich']) === 0,
   'Status ' . $ohneMarke['status']);

/*
 * Und die frisch angehaengten Zeilen stehen gruen da. Nach dem Erkennen
 * laedt die Seite neu - anders als beim Tippen: Es aendern sich zu viele
 * Dinge auf einmal, und eine Seite, die sie alle richtig hat, ist
 * ehrlicher als eine, die sie an sechs Stellen nachtraegt.
 */
$frischRes = freiPost($base . '/teacher/unit.php?id=' . $freiUnit . '&neu=3', null);
ok('Nach dem Einlesen stehen die neuen Zeilen gruen da',
   substr_count($frischRes['body'], 'class="locked frisch"')
   + substr_count($frischRes['body'], 'class="released frisch"') === 3,
   'dasselbe Zeichen wie beim Tippen');
ok('Und ein Anker fuehrt direkt zu ihnen', str_contains($frischRes['body'], 'id="frisch"'));
ok('Daneben ein Hinweis, dass die Maschine sich verlesen kann',
   str_contains($frischRes['body'], '3 Vokabeln sind dazugekommen')
   && str_contains($frischRes['body'], 'bevor</em> du freigibst'));
/*
 * Die Zahl steht in der Adresse, also darf sie erfunden sein. Ohne
 * Deckelung meldete "&neu=9999" neuntausend dazugekommene Vokabeln und
 * faerbte jede Zeile der Einheit gruen.
 */
$luege = freiPost($base . '/teacher/unit.php?id=' . $freiUnit . '&neu=9999', null)['body'];
$wirklich = (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ?', [$freiUnit]);
ok('Eine erfundene Zahl wird auf die Einheit gedeckelt',
   !str_contains($luege, '9999 Vokabeln sind dazugekommen')
   && str_contains($luege, $wirklich . ' Vokabeln sind dazugekommen'));
ok('Und eine negative macht gar keine frischen Zeilen',
   !str_contains(freiPost($base . '/teacher/unit.php?id=' . $freiUnit . '&neu=-4', null)['body'],
                 'frisch'));

q('DELETE FROM vocab WHERE unit_id = ? AND term_foreign LIKE ?', [$freiUnit, 'gescannt-%']);

ok('Das Feld heisst nach der Sprache, nicht "Fremdsprache"',
   str_contains($res['body'], 'aria-label="' . h($kopfSprache) . '"'),
   $kopfSprache);

/*
 * Und ohne Seitenneuladen: Wer zehn Woerter abtippt, will nicht zehnmal
 * die Tabelle von oben sehen. Das Formular antwortet auf ein fetch mit
 * der frischen Zeile.
 */
/*
 * Die beiden Helfer stehen jetzt in _boot.php, nicht mehr in unit.php:
 * Seit die Kursseite ihre Reihenfolge per fetch sichert, brauchen zwei
 * Seiten sie - und zwei Abschriften waeren bald zwei verschiedene
 * Antworten.
 */
ok('Das Anlegen antwortet auch mit einer Zeile statt einer Seite',
   str_contains((string) file_get_contents(__DIR__ . '/../teacher/_boot.php'),
                'function unit_will_json'));
$jsonAntwort = freiPost($base . '/teacher/unit.php?id=' . $freiUnit, [
    'add_vocab' => '1', 'unit_id' => $freiUnit,
    'new_f' => 'zulu', 'new_n' => 'de-zulu', 'csrf' => $freiCsrf,
], ['X-Requested-With: fetch']);
$jsonDaten = json_decode($jsonAntwort['body'], true);
ok('Und die Zeile kommt als JSON zurueck',
   ($jsonDaten['ok'] ?? false) === true
   && ($jsonDaten['vokabel']['term_foreign'] ?? '') === 'zulu',
   $jsonAntwort['body']);
ok('Die Vokabel steht danach wirklich drin',
   (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ? AND term_foreign = ?',
            [$freiUnit, 'zulu']) === 1);

/*
 * Die Antwort traegt ihre Laenge.
 *
 * Ohne Content-Length weiss der Browser nicht, wo sie aufhoert - und weil
 * der Vorgang danach noch weiterarbeitet (die Lueckensaetze), bleibt die
 * Verbindung offen. Er wartet, meldet am Ende "keine Verbindung", und wer
 * das sieht, drueckt noch einmal: Die Vokabel steht dann zweimal drin.
 */
$kopfAntwort = freiPost($base . '/teacher/unit.php?id=' . $freiUnit, [
    'add_vocab' => '1', 'unit_id' => $freiUnit,
    'new_f' => 'yankee', 'new_n' => 'de-yankee', 'csrf' => $freiCsrf,
], ['X-Requested-With: fetch'], true);
ok('Die JSON-Antwort nennt ihre Laenge',
   preg_match('/^Content-Length:\s*(\d+)/mi', $kopfAntwort['header'], $lm) === 1
   && (int) $lm[1] === strlen($kopfAntwort['body']),
   'sonst wartet der Browser auf das Ende und meldet "keine Verbindung"');

// ---- Und dasselbe Paar kommt kein zweites Mal hinein.

/*
 * Ein Doppelklick, eine verlorene Antwort, dieselbe Buchseite zweimal
 * fotografiert - die Wege zu einem Duplikat sind viele, und keiner davon
 * ist eine Absicht.
 */
$nochmal = freiPost($base . '/teacher/unit.php?id=' . $freiUnit, [
    'add_vocab' => '1', 'unit_id' => $freiUnit,
    'new_f' => 'zulu', 'new_n' => 'de-zulu', 'csrf' => $freiCsrf,
], ['X-Requested-With: fetch']);
$nochmalDaten = json_decode($nochmal['body'], true);
ok('Dieselbe Vokabel kommt kein zweites Mal hinein',
   (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ? AND term_foreign = ?',
            [$freiUnit, 'zulu']) === 1);
ok('Und die Antwort sagt, warum',
   ($nochmalDaten['ok'] ?? true) === false
   && ($nochmalDaten['doppelt'] ?? false) === true
   && str_contains((string) ($nochmalDaten['error'] ?? ''), 'steht schon'),
   $nochmal['body']);

/*
 * Das PAAR entscheidet, nicht das fremde Wort allein: "bank" heisst Bank
 * und Ufer, und beide gehoeren in dieselbe Einheit.
 */
freiPost($base . '/teacher/unit.php?id=' . $freiUnit, [
    'add_vocab' => '1', 'unit_id' => $freiUnit,
    'new_f' => 'zulu', 'new_n' => 'de-zulu-zwei', 'csrf' => $freiCsrf,
], ['X-Requested-With: fetch']);
ok('Dasselbe Wort mit anderer Bedeutung darf zweimal vorkommen',
   (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ? AND term_foreign = ?',
            [$freiUnit, 'zulu']) === 2,
   '"bank" heisst Bank und Ufer');

// Und auf demselben Weg beim Einlesen: vocab_append ueberspringt es.
$dopUnit = makeUnit($freiLehrerId ?? $lehrerId, (int) qv(
    'SELECT language_id FROM units WHERE id = ?', [$freiUnit]), 'Doppelt-Unit');
vocab_append($dopUnit, [['foreign' => 'alpha', 'native' => 'de-alpha']]);
$zweiterLauf = vocab_append($dopUnit, [
    ['foreign' => 'Alpha ', 'native' => 'de-alpha'],
    ['foreign' => 'beta',   'native' => 'de-beta'],
]);
ok('Auch beim Einlesen kommt nichts doppelt hinein', $zweiterLauf === 1,
   (string) $zweiterLauf);
ok('Gross- und Kleinschreibung zaehlt dabei nicht',
   (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ?', [$dopUnit]) === 2,
   'wer "Apple" statt "apple" tippt, meint dasselbe Wort');
ok('Und die Positionen bleiben lueckenlos', vocab_positions_dense($dopUnit));
q('DELETE FROM units WHERE id = ?', [$dopUnit]);

q('DELETE FROM vocab WHERE unit_id = ? AND term_foreign IN (?, ?)',
  [$freiUnit, 'zulu', 'yankee']);
ok('Das Skript haengt sie ein, ohne zu laden',
   str_contains($skriptB['body'], 'function initVocabAdd')
   && str_contains($skriptB['body'], "'X-Requested-With': 'fetch'"));
ok('Und markiert sie als frisch',
   str_contains($skriptB['body'], "'locked frisch'")
   && preg_match('/table\.release tr\.frisch td\s*\{[^}]*animation:\s*frischWeg/s', $cssB) === 1,
   'gruen auftauchen und verblassen - eine Bestaetigung, die man nicht wegklickt');

// ---- Und nach dem Einlesen geht es in die Freigabe, nicht in die App.

/*
 * Fuer ein Kind ist die Lerneinheit in der App das Ziel - es hat gerade
 * seine eigenen Vokabeln eingelesen und will ueben. Fuer eine Lehrkraft ist
 * es die Freigabe: Eingelesen ist noch nicht aufgemacht, und in der
 * Schueleransicht saehe sie eine leere Liste.
 */
$einleseQuelle = (string) file_get_contents(__DIR__ . '/../views/import.js');
ok('Nach dem Einlesen landet eine Lehrkraft in der Freigabe',
   preg_match('/if \(VT\.user\?\.isTeacher\) \{.{0,200}?teacher\/unit\.php\?id=/s',
              $einleseQuelle) === 1,
   'nicht in der Schueleransicht - dort waere die Liste leer');
ok('Und ein Kind weiterhin in der App',
   str_contains($einleseQuelle, 'go(`/unit/${data.unit_id}`);'));

// ---- Der Kopf der Seite: Pfad, Knopfreihe, Meldung an der richtigen Stelle.

/*
 * Vorher stand ueber der Tabelle eine Fliesstextzeile - "Englisch - Klasse
 * 7b - zurueck zum Kurs" -, in der nur das letzte Stueck ein Link war. Der
 * Weg eine Ebene hoeher ist auf dieser Seite aber die haeufigste Handlung
 * nach dem Lesen. Jetzt steht dort ein Pfad aus Knoepfen.
 */
$res = freiPost($base . '/teacher/unit.php?id=' . $freiUnit, null);
ok('Ueber dem Titel steht kein Pfad mehr',
   !str_contains($res['body'], '<nav class="crumbs"'));
ok('Sondern ein Burger links',
   str_contains($res['body'], '<details class="menue" id="menuLinks">'));
ok('Und das eigene Konto rechts',
   str_contains($res['body'], '<details class="menue rechts" id="menuRechts">'));
ok('Dazwischen steht der eigene Name',
   str_contains($res['body'], '<span class="barname">'));
ok('Und "zurueck zum Kurs" als Fliesstext ist weg',
   !str_contains($res['body'], 'zurück zum Kurs'));

/*
 * Die beiden Mengen-Knoepfe haengen an den Enden der Tabelle.
 *
 * Sie standen einmal als Reihe darueber. Dort sagten sie nichts darueber,
 * wohin sie greifen; an den Enden sind sie die beiden Endstellungen des
 * Balkens, den man dazwischen von Hand zieht - oben zu, unten auf.
 */
ok('"Nichts freigeben" ist die erste Zeile der Tabelle',
   preg_match('#<table[^>]*id="freigabe".*?<thead>\s*<tr class="mengen">.*?'
              . 'Nichts freigeben#s', $res['body']) === 1,
   'die Tabelle faengt mit dem Knopf an');
ok('Und steht ueber der Kopfzeile mit den Sprachen',
   preg_match('#Nichts freigeben.*?</tr>.*?<th[^>]*>.*?Deutsch#s', $res['body']) === 1);

ok('"Alles freigeben" steht im Fuss der Tabelle',
   preg_match('#<tfoot>\s*<tr class="mengen">.*?Alles freigeben.*?</tfoot>#s',
              $res['body']) === 1);
ok('Und damit unterhalb der Anlegezeile',
   preg_match('#id="handzeile".*?<tfoot>.*?Alles freigeben#s', $res['body']) === 1,
   'wer gerade von Hand angefuegt hat, will sie mitfreigeben');

ok('Beide spannen die ganze Tabellenbreite',
   substr_count($res['body'], '<tr class="mengen">') === 2
   && substr_count($res['body'], '<td colspan="3">') >= 2);
ok('Beide haengen am Formular unter der Tabelle',
   substr_count($res['body'], 'class="mengenknopf') === 2
   && substr_count($res['body'], 'form="releaseform"') >= 2,
   'ein <form> kann nicht um Tabellenzeilen herumstehen');
ok('Beide bleiben stehen, auch wenn einer gerade nichts bewirkt',
   substr_count($res['body'], 'name="release"') >= 2
   && str_contains($res['body'], 'disabled title='),
   'sonst spraenge die Tabelle bei jedem Freigeben um eine Zeile');

$cssMengen = (string) file_get_contents(__DIR__ . '/../admin/admin.css');
ok('Die Knoepfe tragen die Farbe, die sie bewirken',
   preg_match('/\.mengenknopf\.zu\s*\{[^}]*--surface-2/s', $cssMengen) === 1
   && preg_match('/\.mengenknopf\.auf\s*\{[^}]*--good-bg/s', $cssMengen) === 1,
   'oben das Grau der gesperrten Zeilen, unten das Gruen der freigegebenen');
ok('Und die Zelle darum traegt kein Polster',
   preg_match('/table\.data\.release tr\.mengen td \{ padding: 0/s', $cssMengen) === 1,
   'sonst steht der Knopf am Telefon eingerueckt statt buendig');

/*
 * Die Meldung gehoert unter den Titel und nicht zwischen Titel und
 * Zwischenzeile - dort zerschnitt sie den Kopf der Seite.
 */
$mit = freiPost($base . '/teacher/unit.php?id=' . $freiUnit, [
    'release' => 2, 'unit_id' => $freiUnit, 'csrf' => $freiCsrf,
]);
$posPfad = strpos($mit['body'], 'class="adminbar"');
$posH1   = strpos($mit['body'], '<h1>');
$posNote = strpos($mit['body'], '<div class="notice');
$posH2   = strpos($mit['body'], '<h2>Freigabe</h2>');
ok('Die Meldung steht zwischen Titel und Freigabe',
   $posPfad !== false && $posH1 !== false && $posNote !== false && $posH2 !== false
   && $posPfad < $posH1 && $posH1 < $posNote && $posNote < $posH2,
   "Pfad $posPfad, h1 $posH1, Meldung $posNote, h2 $posH2");

freiPost($base . '/teacher/unit.php?id=' . $freiUnit, [
    'release' => 0, 'unit_id' => $freiUnit, 'csrf' => $freiCsrf,
]);

/*
 * Die Schubladen stehen in style.css, nicht in admin.css: Seit die
 * Kinderansicht dieselben zwei Knoepfe hat, brauchen beide Bereiche sie,
 * und admin.css laedt die App gar nicht. Eine Fassung fuer beide - zwei
 * waeren bald zwei verschiedene Menues.
 */
$cssK = (string) file_get_contents(__DIR__ . '/../style.css');
ok('Die Schublade schiebt sich herein',
   preg_match('/\.menue > \.schublade\s*\{[^}]*animation:\s*schubladeLinks/s', $cssK) === 1,
   'als Animation, nicht als Uebergang - <details> blendet seinen Inhalt aus');
ok('Und legt einen Schleier ueber die Seite',
   preg_match('/\.menue > \.schleier\s*\{[^}]*position:\s*fixed/s', $cssK) === 1);
/*
 * Als App auf dem Home-Bildschirm zeichnet iOS bis unter die Dynamic Island.
 * Die Leiste rechnete das mit, die Schubladen nicht - ihr erster Eintrag lag
 * unter der Insel.
 */
ok('Und faengt unter der Dynamic Island an',
   preg_match('/\.menue > \.schublade\s*\{[^}]*padding:\s*calc\(env\(safe-area-inset-top\)/s',
              $cssK) === 1);
ok('Und endet ueber dem Balken zum Wischen',
   preg_match('/\.menue > \.schublade\s*\{[^}]*safe-area-inset-bottom/s', $cssK) === 1);
ok('Und sie steht dort, wo beide Bereiche sie finden',
   !str_contains($cssB, '.menue > .schublade'),
   'in admin.css waere sie fuer die App unerreichbar');
ok('Und die Knopfreihe bricht um statt zu quetschen',
   preg_match('/\.buttonrow\s*\{[^}]*flex-wrap:\s*wrap/s', $cssB) === 1);

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
 * Bei einem einzelnen Kind feuert das nie: Es stoesst die Satzerzeugung an,
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

section('Schulen im Admin');

/*
 * Die Schule ist der erste Schritt einer Installation, nicht der letzte.
 * Ohne sie sieht ein Konto nichts: Lerneinheiten haengen an Kursen, Kurse an
 * Schulen.
 */

$schulQuelle = (string) file_get_contents(__DIR__ . '/../admin/_boot.php');
ok('Die Schulen stehen im Admin-Menue',
   str_contains($schulQuelle, "'schools.php'   => 'Schulen'"));

/*
 * Eine Schule entsteht nur, wo jemand sie anlegt.
 *
 * Hier stand einmal die Pruefung einer Schule namens "Familie": Die
 * Schemapflege legte sie an, um den Bestand der alten Familien-App zu
 * retten. Beides ist weg - es gibt keinen Bestand zu retten, und eine still
 * erzeugte Schule waere nur ein Posten, den jemand wieder wegraeumen muss.
 */
$schulenQuelle = (string) file_get_contents(__DIR__ . '/../admin/schools.php');
ok('Keine Schule entsteht von selbst',
   !str_contains($schulenQuelle, "'Familie'")
   && (int) qv("SELECT COUNT(*) FROM schools WHERE name = 'Familie'") === 0,
   'angelegt wird im Admin, und nur dort');

$seite = http($base . '/admin/schools.php')['body'];
ok('Die Seite laedt', str_contains($seite, 'Schule anlegen'));

$schulName = 'Testschule ' . bin2hex(random_bytes(3));
$res = adminPost('schools.php', ['create' => '1', 'name' => $schulName]);
ok('Eine Schule laesst sich anlegen', str_contains($res['body'], 'angelegt'));

$neueSchule = (int) qv('SELECT id FROM schools WHERE name = ?', [$schulName]);
ok('Und steht in der Datenbank', $neueSchule > 0);

$res = adminPost('schools.php', ['create' => '1', 'name' => $schulName]);
ok('Zweimal derselbe Name geht nicht', str_contains($res['body'], 'gibt es schon'));

// Umbenennen und ein eigenes Monatslimit setzen.
$res = adminPost('schools.php', [
    'update' => '1', 'id' => $neueSchule,
    'name'   => $schulName . ' II', 'cap' => '3,50', 'active' => '1',
]);
ok('Der Name laesst sich aendern',
   qv('SELECT name FROM schools WHERE id = ?', [$neueSchule]) === $schulName . ' II');
ok('Und ein eigenes Monatslimit setzen',
   abs((float) qv('SELECT monthly_cost_cap_usd FROM schools WHERE id = ?', [$neueSchule]) - 3.5)
   < 0.001,
   (string) qv('SELECT monthly_cost_cap_usd FROM schools WHERE id = ?', [$neueSchule]));

/*
 * Leeres Feld heisst "kein eigenes Limit" und nicht "null Dollar". Der
 * Unterschied ist erheblich: NULL laesst nur das Budget des Betreibers
 * greifen, 0.00 sperrte die Schule sofort aus.
 */
adminPost('schools.php', [
    'update' => '1', 'id' => $neueSchule,
    'name'   => $schulName . ' II', 'cap' => '', 'active' => '1',
]);
ok('Ein leeres Limit bedeutet "keines", nicht "null"',
   qv('SELECT monthly_cost_cap_usd FROM schools WHERE id = ?', [$neueSchule]) === null);

// Ein Konto haengt dran - dann wird nicht geloescht.
$schulKind = makeUser('e2e_schulkind', 'Schulkind');
q('UPDATE users SET school_id = ? WHERE id = ?', [$neueSchule, $schulKind]);

$res = adminPost('schools.php', ['delete' => $neueSchule]);
ok('Eine Schule mit Konten wird nicht geloescht',
   q1('SELECT id FROM schools WHERE id = ?', [$neueSchule]) !== null
   && str_contains($res['body'], 'Nicht gelöscht'));

q('DELETE FROM users WHERE id = ?', [$schulKind]);
$res = adminPost('schools.php', ['delete' => $neueSchule]);
ok('Eine leere Schule dagegen schon',
   q1('SELECT id FROM schools WHERE id = ?', [$neueSchule]) === null);

// ---- Konten werden beim Anlegen einer Schule zugeordnet.

$zuordSchule = 'Zuordnung ' . bin2hex(random_bytes(3));
adminPost('schools.php', ['create' => '1', 'name' => $zuordSchule]);
$zuordId = (int) qv('SELECT id FROM schools WHERE name = ?', [$zuordSchule]);

$lehrName = 'e2e_lehr_' . bin2hex(random_bytes(3));
$res = adminPost('users.php', [
    'create'       => '1',
    'username'     => $lehrName,
    'display_name' => 'Frau Zuordnung',
    'password'     => 'start12345',
    'color'        => '#4f7cff',
    'school_id'    => $zuordId,
    'role'         => 'teacher',
]);
$neuerLehrer = q1('SELECT * FROM users WHERE username = ?', [$lehrName]);
ok('Eine Lehrkraft laesst sich mit Schule anlegen',
   $neuerLehrer !== null && (int) $neuerLehrer['school_id'] === $zuordId,
   var_export($neuerLehrer['school_id'] ?? null, true));
ok('Und zwar gleich als Lehrkraft', ($neuerLehrer['role'] ?? '') === 'teacher');
ok('Lehrkraefte duerfen einlesen, ohne dass jemand daran denkt',
   (int) ($neuerLehrer['can_import'] ?? 0) === 1);

// Ohne Schule wird abgelehnt statt ein Konto anzulegen, das nichts sieht.
$ohneName = 'e2e_ohne_' . bin2hex(random_bytes(3));
$res = adminPost('users.php', [
    'create'       => '1',
    'username'     => $ohneName,
    'display_name' => 'Ohne Schule',
    'password'     => 'start12345',
    'color'        => '#4f7cff',
    'school_id'    => '0',
]);
ok('Ein Konto ohne Schule wird abgelehnt',
   q1('SELECT id FROM users WHERE username = ?', [$ohneName]) === null
   && str_contains($res['body'], 'Schule'));

/*
 * Und die Abschottung: Eine Lehrkraft sieht ausschliesslich ihre Schule.
 */
$fremdKurs = (int) course_for_language($languageId)['id'];
$fremdSchuleId = (int) qv('SELECT school_id FROM courses WHERE id = ?', [$fremdKurs]);
ok('Der Testkurs gehoert einer anderen Schule', $fremdSchuleId !== $zuordId);

$absJar = tempnam(sys_get_temp_dir(), 'vtabs');
$absBody = (function () use ($base, $absJar, $lehrName, $fremdKurs): string {
    $ch = curl_init($base . '/teacher/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $absJar,
                            CURLOPT_COOKIEFILE => $absJar, CURLOPT_TIMEOUT => 30]);
    $seite = (string) curl_exec($ch);
    curl_close($ch);
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $seite, $m);

    foreach ([['teacher_login' => '1', 'username' => $lehrName,
               'password' => 'start12345', 'csrf' => $m[1] ?? ''], null] as $post) {
        $ch = curl_init($base . ($post === null
            ? '/teacher/course.php?id=' . $fremdKurs : '/teacher/index.php'));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $absJar,
                                CURLOPT_COOKIEFILE => $absJar, CURLOPT_FOLLOWLOCATION => true,
                                CURLOPT_TIMEOUT => 30]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $antwort = (string) curl_exec($ch);
        curl_close($ch);
    }
    return $antwort;
})();

ok('Ein Kurs einer fremden Schule bleibt der Lehrkraft verschlossen',
   !str_contains($absBody, 'Wer im Kurs ist'),
   'die Kursansicht war sichtbar');

q('DELETE FROM users WHERE id = ?', [(int) $neuerLehrer['id']]);
q('DELETE FROM schools WHERE id = ?', [$zuordId]);
@unlink($absJar);

section('Die Unterlagen gehoeren dem Kurs, nicht einem Konto');

/*
 * Der Sinn davon, units.user_id und languages.user_id loszuwerden.
 *
 * Solange eine Sprache einem Konto gehoerte, war der Fall "die Lehrkraft
 * legt an, die Klasse lernt" gar nicht ausdrueckbar - und am
 * Fremdschluessel hing ON DELETE CASCADE: Ein Kind aus der Schule nehmen
 * haette die Unterlagen von 27 anderen mitgenommen.
 */

$besSchule = (int) qv('SELECT school_id FROM users WHERE id = ?', [$userId]);

$besLehrer = 'beslehr_' . bin2hex(random_bytes(3));
q('INSERT INTO users (school_id, username, display_name, password_hash, color, role, can_import)
   VALUES (?, ?, ?, ?, ?, ?, 1)',
  [$besSchule, $besLehrer, 'Herr Besitz',
   password_hash('lehrerin123', PASSWORD_DEFAULT), '#4f7cff', ROLE_TEACHER]);
$besLehrerId = (int) db()->lastInsertId();

// Die Lehrkraft legt an, das Kind lernt.
$besLang = makeLanguage($besLehrerId, 'Besitzisch');
$besUnit = makeUnit($besLehrerId, $besLang, 'Lehrer-Einheit');
q('INSERT INTO vocab (unit_id, term_foreign, term_native, position) VALUES (?, ?, ?, 0)',
  [$besUnit, 'mine', 'meins']);
q('UPDATE units SET released_position = 1 WHERE id = ?', [$besUnit]);

$besKurs = (int) course_for_language($besLang)['id'];

$besKind = makeUser('e2e_besitz', 'Besitzkind');
course_add_member($besKurs, $besKind, 'student');

// Das Kind meldet sich mit eigenem Topf an und sieht die fremde Sprache.
$besJar = tempnam(sys_get_temp_dir(), 'vtbes');
$gesehen = apiAls($besJar, function () {
    apiCall('auth', 'login', ['username' => 'e2e_besitz', 'password' => 'geheim123']);
    [$d, $s] = apiCall('languages', 'list');
    return array_column($d['languages'] ?? [], 'name');
});
ok('Ein Kind sieht die Sprache, die seine Lehrkraft angelegt hat',
   in_array('Besitzisch', $gesehen, true), implode(', ', $gesehen));

/*
 * Und der Kern: Das Kind verlaesst die Schule. Frueher nahm der
 * Fremdschluessel die Sprache samt allem mit.
 */
q('DELETE FROM users WHERE id = ?', [$besKind]);

ok('Sein Weggang laesst die Sprache stehen',
   q1('SELECT id FROM languages WHERE id = ?', [$besLang]) !== null);
ok('Und die Lerneinheit',
   q1('SELECT id FROM units WHERE id = ?', [$besUnit]) !== null);
ok('Und die Vokabeln darin',
   (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ?', [$besUnit]) === 1);
ok('Seine Kursmitgliedschaft ist dagegen weg',
   (int) qv('SELECT COUNT(*) FROM course_members WHERE user_id = ?', [$besKind]) === 0);

// Dasselbe fuer die Lehrkraft, die alles angelegt hat.
q('DELETE FROM users WHERE id = ?', [$besLehrerId]);
ok('Auch der Weggang der Lehrkraft nimmt die Unterlagen nicht mit',
   q1('SELECT id FROM units WHERE id = ?', [$besUnit]) !== null
   && (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ?', [$besUnit]) === 1);

q('DELETE FROM languages WHERE id = ?', [$besLang]);
ok('Die Sprache zu loeschen raeumt dann aber wirklich auf',
   (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ?', [$besUnit]) === 0,
   'vocab blieb stehen - fk_units_lang oder fk_vocab_unit fehlt');

@unlink($besJar);

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
    $roh    = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Ohne CURLOPT_HEADER: Diese Fassung liefert nur den Rumpf. Hier stand
    // eine Abfrage auf ein $mitKopf, das es in dieser Funktion nie gab -
    // sie war immer wahr und erzeugte bei jedem Aufruf eine Warnung.
    return ['status' => $status, 'body' => $roh, 'header' => ''];
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
   $res['status'] === 200 && str_contains($res['body'], 'Klassen'), "Status {$res['status']}");

/*
 * Der Name der Schule steht im Pfad oben links und fuehrt zu den Klassen.
 * Frueher stand hier ein fester Name im Test - der galt genau in einer
 * Datenbank und fiel in jeder anderen um.
 */
/*
 * Der erste Krumen ist ein Haeuschen.
 *
 * Er trug einmal den Namen der Schule, und das war richtig, solange die
 * Wurzel die Schule war. Inzwischen fuehrt er auf die eigenen Kurse - und
 * ein Knopf mit dem Namen der Schule sagt nicht, dass er dorthin fuehrt.
 * Der Name steht noch im Titel, fuer den Zeiger und das Vorleseprogramm.
 */
$schulName = (string) qv('SELECT s.name FROM schools s
                            JOIN users u ON u.school_id = s.id WHERE u.id = ?', [$lehrerId]);
ok('Das Menue links fuehrt auf die eigenen Kurse',
   str_contains($res['body'], '<a class="mitem haupt" href='));
ok('Und zeigt ein Haeuschen dazu', str_contains($res['body'], '&#127968;'));
ok('Darunter stehen alle Kurse der Schule',
   str_contains($res['body'], 'Alle Kurse der Schule'));
ok('Und der Weg in die Klassenverwaltung',
   str_contains($res['body'], 'Klassen und Kinder'));
ok('Der Name der Schule steht nicht mehr in der Leiste',
   !str_contains($res['body'], '<span>' . h($schulName) . '</span>'), $schulName);

$res = teacherGet('classes.php');
ok('Sowie die Klassen der Schule',
   preg_match('/class\.php\?id=(\d+)/', $res['body']) === 1);
$res = teacherGet('class.php?id=' . (int) qv(
    'SELECT c.id FROM classes c WHERE c.school_id = ? ORDER BY c.id LIMIT 1',
    [(int) qv('SELECT school_id FROM users WHERE id = ?', [$lehrerId])]));
ok('Und darin die Kurse',
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

section('Meldungen für die Lehrkraft');

/*
 * Die Meldung gehoert zu der, deren Unterlagen es sind - nicht nur zum
 * Admin, der die Klasse nicht kennt. Ein eigener Kurs, damit die Zahlen
 * hier nur von dem abhaengen, was dieser Abschnitt selbst anlegt.
 */
$lmLang = makeLanguage($userId, 'Meldisch' . bin2hex(random_bytes(2)));
$lmUnit = makeUnit($userId, $lmLang, 'Meldeeinheit');
$lmKurs = (int) course_for_language($lmLang)['id'];
course_add_member($lmKurs, $lehrerId, COURSE_ROLE_TEACHER);

$lmIds = [];
foreach (['alpha', 'bravo'] as $i => $w) {
    q('INSERT INTO vocab (unit_id, term_foreign, term_native, position) VALUES (?, ?, ?, ?)',
      [$lmUnit, $w, 'de-' . $w, $i]);
    $lmIds[$w] = (int) db()->lastInsertId();
    q('INSERT INTO sentences (vocab_id, native_text, foreign_text, answer) VALUES (?, ?, ?, ?)',
      [$lmIds[$w], 'Satz zu ' . $w . '.', 'Here is {} now.', $w]);
}
$lmSatz = static fn (string $w): int =>
    (int) qv('SELECT id FROM sentences WHERE vocab_id = ?', [$lmIds[$w]]);

/*
 * "alpha" von zwei Kindern, einmal beim Auswaehlen und einmal im
 * Lueckentext; "bravo" von einem, im Lueckentext. Gemeldet ueber
 * meldung_aufnehmen() - der Weg vom Geraet dorthin ist oben geprueft.
 */
meldung_aufnehmen($userId, $lmIds['alpha'], 0, '');
meldung_aufnehmen($otherId, $lmIds['alpha'], $lmSatz('alpha'), 'alfa');
meldung_aufnehmen($userId, $lmIds['bravo'], $lmSatz('bravo'), 'brafo');

$res = teacherGet('index.php');
ok('Am Zahnrad steht rot, wie viele Vokabeln gemeldet sind',
   preg_match('/<summary class="burger"[^>]*>.*?<span class="zaehler"[^>]*>2<\/span>/s',
              $res['body']) === 1);
ok('Gezaehlt werden Vokabeln, nicht Meldungen',
   str_contains($res['body'], '2 gemeldete Vokabeln'));
ok('Im Menue fuehrt ein Eintrag zu ihnen',
   str_contains($res['body'], 'class="mitem meldungen"')
   && str_contains($res['body'], 'meldungen.php'));

$res = teacherGet('meldungen.php');
ok('Die meistgemeldete Vokabel steht vorn',
   str_contains($res['body'], 'name="meldung" value="' . $lmIds['alpha'] . '"')
   && !str_contains($res['body'], 'name="meldung" value="' . $lmIds['bravo'] . '"'));
ok('Mit der Zahl der Kinder', str_contains($res['body'], '2 Kinder haben'));
ok('Das Wortpaar zum Aendern, weil beim Auswaehlen gemeldet wurde',
   str_contains($res['body'], 'name="f" value="alpha"'));
ok('Und der Satz zum Aendern, samt dem Getippten',
   str_contains($res['body'], 'name="s[' . $lmSatz('alpha') . '][f]"')
   && str_contains($res['body'], 'tippte &bdquo;alfa&ldquo;'));

preg_match('/name="csrf" value="([a-f0-9]+)"/', $res['body'], $lmM);
$lmCsrf = $lmM[1] ?? '';

// Aendern: Wortpaar und Satz in einem Zug, und danach ist die Meldung weg.
$res = teacherRequest($base . '/teacher/meldungen.php', [
    'csrf' => $lmCsrf, 'meldung' => $lmIds['alpha'], 'sichern' => 1,
    'f' => 'alpha!', 'n' => 'de-alpha neu',
    's' => [$lmSatz('alpha') => ['n' => 'Neuer Satz.', 'f' => 'Here {} is.', 'a' => 'alpha']],
]);
$lmV = q1('SELECT term_foreign, term_native FROM vocab WHERE id = ?', [$lmIds['alpha']]);
ok('"Ändern" speichert das Wortpaar',
   ($lmV['term_native'] ?? '') === 'de-alpha neu', (string) json_encode($lmV));
ok('Und den Satz',
   qv('SELECT foreign_text FROM sentences WHERE id = ?', [$lmSatz('alpha')]) === 'Here {} is.');
ok('Und erledigt die Meldung',
   (int) qv('SELECT COUNT(*) FROM vocab_flags WHERE vocab_id = ?', [$lmIds['alpha']]) === 0);
ok('Danach steht die naechste da',
   str_contains($res['body'], 'name="meldung" value="' . $lmIds['bravo'] . '"'));
ok('Und das Zahnrad zaehlt eine weniger',
   preg_match('/<span class="zaehler"[^>]*>1<\/span>/', $res['body']) === 1);

// Ein Satz ohne Luecke faellt durch - und dann bleibt alles, wie es war.
$res = teacherRequest($base . '/teacher/meldungen.php', [
    'csrf' => $lmCsrf, 'meldung' => $lmIds['bravo'], 'sichern' => 1,
    's' => [$lmSatz('bravo') => ['n' => 'Satz.', 'f' => 'Keine Luecke.', 'a' => 'bravo']],
]);
ok('Ein kaputter Satz wird nicht gespeichert',
   qv('SELECT foreign_text FROM sentences WHERE id = ?', [$lmSatz('bravo')]) === 'Here is {} now.');
ok('Die Meldung bleibt dann offen',
   (int) qv('SELECT COUNT(*) FROM vocab_flags WHERE vocab_id = ?', [$lmIds['bravo']]) === 1);
ok('Und es steht da, was fehlte', str_contains($res['body'], 'genau eine Lücke'));

// Ein Feld fuer einen Satz, der nicht gemeldet ist, wird nicht gelesen.
$lmFremd = (int) qv('SELECT id FROM sentences WHERE vocab_id <> ? AND vocab_id <> ? LIMIT 1',
                    [$lmIds['alpha'], $lmIds['bravo']]);
$lmVorher = (string) qv('SELECT foreign_text FROM sentences WHERE id = ?', [$lmFremd]);
teacherRequest($base . '/teacher/meldungen.php', [
    'csrf' => $lmCsrf, 'meldung' => $lmIds['bravo'], 'sichern' => 1,
    's' => [$lmSatz('bravo') => ['n' => 'Satz zu bravo.', 'f' => 'Here is {} now.', 'a' => 'bravo'],
            $lmFremd         => ['n' => 'Gekapert.', 'f' => 'Ge {} kapert.', 'a' => 'x']],
]);
ok('Ein untergeschobener fremder Satz bleibt unberuehrt',
   qv('SELECT foreign_text FROM sentences WHERE id = ?', [$lmFremd]) === $lmVorher);

$res = teacherGet('meldungen.php');
ok('Ist alles erledigt, sagt die Seite das', str_contains($res['body'], 'Keine offenen Meldungen'));
ok('Und das Zahnrad ist wieder ohne Zahl', !str_contains($res['body'], 'class="zaehler"'));

// Eine Lehrkraft sieht nur die Meldungen ihrer eigenen Kurse.
meldung_aufnehmen($userId, $lmIds['bravo'], 0, '');
ok('Wer nicht Lehrkraft des Kurses ist, sieht die Meldung nicht',
   meldung_laden($lmIds['bravo'], $otherId) === null
   && meldung_laden($lmIds['bravo'], $lehrerId) !== null);
$res = teacherRequest($base . '/teacher/meldungen.php', [
    'csrf' => $lmCsrf, 'meldung' => $lmIds['bravo'], 'stimmt' => 1,
]);
ok('"Stimmt so" erledigt sie auch hier',
   (int) qv('SELECT COUNT(*) FROM vocab_flags WHERE vocab_id = ?', [$lmIds['bravo']]) === 0);

q('DELETE FROM languages WHERE id = ?', [$lmLang]);

section('Was die Oberflaeche anbietet');

require_once __DIR__ . '/../lib/worldlanguages.php';

/*
 * Ein Kind ohne Einlese-Recht bekam "Vokabeln einlesen" weiterhin angeboten
 * und erst beim Tippen ein "Dafuer fehlt dir die Berechtigung". Eine
 * Schaltflaeche, die nur dazu da ist, eine Absage zu holen, ist keine
 * Schaltflaeche.
 */
$uiQuelle    = (string) file_get_contents(__DIR__ . '/../views/language.js');
$listeQuelle = (string) file_get_contents(__DIR__ . '/../views/languages.js');

ok('Einlesen erscheint nur mit Berechtigung',
   preg_match('/selbstEinlesen\(\) \?\s*`\s*<button class="row" data-go="\/lang\/\$\{language\.id\}\/import"/s',
              $uiQuelle) === 1,
   'der Knopf haengt an keiner Bedingung');
/*
 * Und fuer eine Lehrkraft erscheint er gar nicht.
 *
 * Diese Seite ist die Ansicht ihrer Klasse - "So sieht es die Klasse" -,
 * und die soll genau das sein. Ein Knopf, den kein Kind dort hat, macht aus
 * der Probe eine Seite, die es so nie gibt. Eingelesen wird aus dem
 * Lehrkraft-Bereich heraus.
 */
ok('Und fuer eine Lehrkraft gar nicht',
   preg_match('/function selbstEinlesen\(\)\s*\{\s*return VT\.user\.canImport && !VT\.user\.isTeacher;/s',
              $uiQuelle) === 1,
   'dieselbe Regel wie beim Anlegen einer Sprache in der Kachelliste');
/*
 * Und das Anlegen einer Sprache: nur mit Einlese-Recht UND nicht als
 * Lehrkraft. Fuer sie heisst das Ding Kurs und entsteht in der Verwaltung -
 * zwei Wege zum selben Ergebnis, von denen einer schlechter ist, sind einer
 * zu viel.
 */
ok('Und das Anlegen einer Sprache ebenso',
   str_contains($listeQuelle, 'darfAnlegen()')
   && preg_match('/canImport && !VT\.user\.isTeacher/', $listeQuelle) === 1,
   'die Bedingung fehlt');
/*
 * Die Zeile "Verwaltung" ueber den Kacheln ist weg. Der Schalter im Zahnrad
 * kann dasselbe und mehr: Er fuehrt auf die Entsprechung DIESER Seite und
 * steht auf jeder Seite an derselben Stelle. Eine zweite Tuer daneben nahm
 * den Platz ueber genau dem weg, weswegen man hergekommen ist.
 */
ok('Ueber den Kacheln steht keine zweite Tuer in die Verwaltung',
   !str_contains($listeQuelle, 'Klassen, Kurse, Zugangsdaten und Freigaben'),
   'der Weg dorthin steht im Zahnrad');

// Was die App ueberhaupt erfaehrt.
[$d, $s] = apiCall('auth', 'me');
ok('Die App erfaehrt, was das Konto darf',
   array_key_exists('canImport', $d['user'] ?? []) && array_key_exists('isTeacher', $d['user'] ?? []),
   json_encode($d['user'] ?? null));

$startseite = http($base . '/')['body'];
ok('Und zwar schon beim Ausliefern der Huelle',
   str_contains($startseite, '"canImport"') && str_contains($startseite, '"isTeacher"'));

/*
 * Und die Absage bleibt trotzdem, wo sie hingehoert: in der API. Die
 * Oberflaeche versteckt, sie schuetzt nicht.
 */
$ohneRecht = makeUser('e2e_ohnerecht', 'Ohne Recht');
q('UPDATE users SET can_import = 0 WHERE id = ?', [$ohneRecht]);

$rechtJar = tempnam(sys_get_temp_dir(), 'vtrecht');
[$abgelehnt, $status] = apiAls($rechtJar, function () {
    apiCall('auth', 'login', ['username' => 'e2e_ohnerecht', 'password' => 'geheim123']);
    return apiCall('languages', 'create', ['name' => 'Heimlich', 'flag' => '']);
});
ok('Ohne Recht laesst sich auch per API keine Sprache anlegen',
   $status === 403, 'Status ' . $status . ' ' . json_encode($abgelehnt));

$dasDarf = apiAls($rechtJar, fn () => apiCall('auth', 'me')[0]['user']['canImport'] ?? null);
ok('Und die App bekommt das auch gesagt', $dasDarf === false, var_export($dasDarf, true));

q('DELETE FROM users WHERE id = ?', [$ohneRecht]);
@unlink($rechtJar);

/*
 * Aendern ist nicht Ueben - das Berechtigungskonzept.
 *
 * Mitglied in einem Kurs zu sein hiess bisher auch, seine Lerneinheiten
 * aendern und loeschen zu duerfen. Ein Kind im Kurs konnte damit die
 * Lerneinheit seiner Klasse loeschen - mit allen Vokabeln, Saetzen und den
 * Lernstaenden aller anderen -, und sogar die ganze Sprache. Das war ein
 * echtes Loch, und es sass an der Naht, die genau dafuer gebaut wurde.
 *
 * Die Regel lautet jetzt: Wer Inhalte anlegen darf, darf sie auch aendern.
 * Das ist dieselbe Befugnis und heisst CAP_IMPORT - normalerweise hat sie
 * die Lehrkraft; ein Kind bekommt sie nur, wenn es selbst einlesen soll.
 */
$schuelerKonto = makeUser('e2e_darfnicht', 'Darf Nicht');
q('UPDATE users SET can_import = 0 WHERE id = ?', [$schuelerKonto]);
course_add_member((int) course_for_language($languageId)['id'], $schuelerKonto, 'student');

$schuelerRow = q1('SELECT * FROM users WHERE id = ?', [$schuelerKonto]);
$lehrRow2    = q1('SELECT * FROM users WHERE id = ?', [$lehrerId]);

ok('Ein Kind ohne Recht darf die Lerneinheit ansehen',
   load_unit_for_view($schuelerRow, $unitId) !== null);
ok('Aber nicht aendern',
   load_unit_for_edit($schuelerRow, $unitId) === null,
   'es koennte sie loeschen');
ok('Und die Sprache auch nicht',
   load_language_for_edit($schuelerRow, $languageId) === null);

ok('Eine Lehrkraft darf beides',
   load_unit_for_view($lehrRow2, $unitId) !== null
   || load_unit_for_edit($lehrRow2, $unitId) === null);

// Und durch die API, nicht nur an der Naht vorbei.
$darfJar = tempnam(sys_get_temp_dir(), 'vtdarf');
$vorherUnits = (int) qv('SELECT COUNT(*) FROM units WHERE id = ?', [$unitId]);

$ergebnis = apiAls($darfJar, function () use ($unitId, $languageId) {
    apiCall('auth', 'login', ['username' => 'e2e_darfnicht', 'password' => 'geheim123']);
    return [
        'loeschen'  => apiCall('units', 'delete', ['id' => $unitId]),
        'umbenennen' => apiCall('units', 'rename', ['id' => $unitId, 'title' => 'Gekapert']),
        'sprache'   => apiCall('languages', 'delete', ['id' => $languageId]),
        'einlesen'  => apiCall('import', 'analyze', ['language_id' => $languageId, 'images' => []]),
    ];
});

ok('Die API laesst ein Kind die Lerneinheit nicht loeschen',
   $ergebnis['loeschen'][1] === 404, 'Status ' . $ergebnis['loeschen'][1]);
ok('Und sie steht auch wirklich noch da',
   (int) qv('SELECT COUNT(*) FROM units WHERE id = ?', [$unitId]) === $vorherUnits);
ok('Umbenennen geht ebenso wenig',
   $ergebnis['umbenennen'][1] === 404
   && qv('SELECT title FROM units WHERE id = ?', [$unitId]) !== 'Gekapert',
   'Status ' . $ergebnis['umbenennen'][1]);
ok('Die Sprache zu loeschen auch nicht',
   $ergebnis['sprache'][1] === 404
   && q1('SELECT id FROM languages WHERE id = ?', [$languageId]) !== null,
   'Status ' . $ergebnis['sprache'][1]);
ok('Und einlesen erst recht nicht',
   $ergebnis['einlesen'][1] === 403, 'Status ' . $ergebnis['einlesen'][1]);

/*
 * Was ein Kind sehr wohl darf: ueben und seinen eigenen Lernstand
 * zuruecksetzen. Das ist seine Sache und niemandes sonst.
 */
$eigenes = apiAls($darfJar, fn () => apiCall('units', 'reset', ['id' => $unitId]));
ok('Seinen eigenen Lernstand darf es zuruecksetzen', $eigenes[1] === 200,
   'Status ' . $eigenes[1]);

q('DELETE FROM users WHERE id = ?', [$schuelerKonto]);
@unlink($darfJar);

/*
 * Und die Gegenprobe an der Quelle: Beide Ladefunktionen fragen wirklich
 * nach der Befugnis, statt sich auf die Aufrufer zu verlassen.
 */
$zugriffQuelle = (string) file_get_contents(__DIR__ . '/../lib/access.php');
ok('Beide Aenderungswege pruefen die Befugnis',
   substr_count($zugriffQuelle, 'if (!user_can($user, CAP_IMPORT)) {') === 2,
   substr_count($zugriffQuelle, 'if (!user_can($user, CAP_IMPORT)) {') . ' von 2');

/*
 * Und die Oberflaeche bietet nichts an, was die API ablehnt.
 */
$unitUi  = (string) file_get_contents(__DIR__ . '/../views/unit.js');
$clozeUi = (string) file_get_contents(__DIR__ . '/../views/cloze.js');

ok('Loeschen und Umbenennen erscheinen nur mit Befugnis',
   substr_count($unitUi, 'selbstVerwalten()') >= 2,
   'sonst holt ein Kind sich dort nur Absagen');
ok('Und einer Lehrkraft gar nicht',
   preg_match('/function selbstVerwalten\(\)\s*\{\s*return VT\.user\.canImport && !VT\.user\.isTeacher;/s',
              $unitUi) === 1,
   'sie verwaltet in ihrem Bereich, nicht in der Ansicht ihrer Klasse');
ok('Das Zuruecksetzen bleibt fuer alle',
   preg_match('/selbstVerwalten\(\)[^
]*id="reset"/', $unitUi) !== 1,
   'der Lernstand gehoert dem Kind');
ok('Und ohne Befugnis erzeugt niemand Saetze auf Knopfdruck',
   str_contains($clozeUi, 'if (VT.user.canImport) {'),
   'sonst loest ein Kind einen bezahlten Aufruf aus');

// ---- Die Sprachliste fuer das Auswahlfeld.

$auswahl = language_choices();
ok('Es gibt reichlich Sprachen zur Auswahl', count($auswahl) > 80, (string) count($auswahl));

$oben = array_values(array_filter($auswahl, static fn ($x) => $x['top']));
ok('Die fuenf Schulsprachen stehen oben',
   array_column($oben, 'name') === ['Englisch', 'Französisch', 'Latein', 'Spanisch', 'Dänisch'],
   implode(', ', array_column($oben, 'name')));
ok('Und stehen am Anfang der Liste',
   array_slice(array_column($auswahl, 'top'), 0, 5) === [true, true, true, true, true]);
ok('Danach kommt keine mehr doppelt',
   count(array_unique(array_column($auswahl, 'name'))) === count($auswahl));
ok('Jede traegt ein Sinnbild',
   array_filter($auswahl, static fn ($x) => $x['flag'] === '') === []);

/*
 * Die Kuerzel steuern im Lueckentext die Sonderzeichenreihe. Sie kommen aus
 * derselben Liste - sonst haette eine Sprache im Auswahlfeld gestanden und
 * im Lueckentext keine Tastaturhilfe gehabt.
 */
ok('Das Kuerzel kommt aus derselben Liste',
   language_code('', 'Schwedisch') === 'sv' && language_code('', 'Latein') === 'la');
ok('Auch ohne Umlaute geschrieben',
   language_code('', 'Franzoesisch') === 'fr' && language_code('', 'Daenisch') === 'da');

section('Mein Konto');

/*
 * Name, Farbe und vor allem das Passwort aendert das Kind selbst, nicht nur
 * der Betreiber. Ein Kind, das sein Anfangspasswort behalten muss, weil
 * niemand es aendern kann, hat ein Passwort, das auf einem Zettel steht -
 * und Zettel gehen in einer Klasse herum.
 */
$kontoKind = makeUser('e2e_konto', 'Kontokind');
q('UPDATE users SET initial_password = ? WHERE id = ?', ['müder Gepard', $kontoKind]);

$kontoJar = tempnam(sys_get_temp_dir(), 'vtkonto');

[$d, $s] = apiAls($kontoJar, function () {
    apiCall('auth', 'login', ['username' => 'e2e_konto', 'password' => 'geheim123']);
    return apiCall('profile', 'get');
});
ok('Das eigene Konto laesst sich abrufen', $s === 200 && ($d['ok'] ?? false));
ok('Mit Name, Benutzername und Farbe',
   ($d['profile']['name'] ?? '') === 'Kontokind'
   && ($d['profile']['username'] ?? '') === 'e2e_konto'
   && preg_match('/^#[0-9a-f]{6}$/', (string) ($d['profile']['color'] ?? '')) === 1,
   json_encode($d['profile'] ?? null));
ok('Und der Auskunft, dass noch das Anfangspasswort gilt',
   ($d['profile']['initial'] ?? null) === true);
ok('Dazu die Farbpalette zur Auswahl', count($d['palette'] ?? []) === 49);

// Name und Farbe aendern.
[$d, $s] = apiAls($kontoJar, fn () => apiCall('profile', 'save',
    ['name' => 'Kontokind Neu', 'color' => '#123456']));
ok('Name und Farbe lassen sich aendern', $s === 200);
$frisch = q1('SELECT display_name, color FROM users WHERE id = ?', [$kontoKind]);
ok('Und stehen in der Datenbank',
   $frisch['display_name'] === 'Kontokind Neu' && $frisch['color'] === '#123456',
   json_encode($frisch));
ok('Die Antwort traegt den neuen App-Namen',
   ($d['user']['appName'] ?? '') === 'Kontokind Neus Vokidoki',
   (string) ($d['user']['appName'] ?? ''));

// Unsinn als Farbe darf nicht durchrutschen.
apiAls($kontoJar, fn () => apiCall('profile', 'save',
    ['name' => 'Kontokind Neu', 'color' => 'rot; drop table']));
ok('Eine unsinnige Farbe wird abgefangen',
   preg_match('/^#[0-9a-f]{6}$/',
              (string) qv('SELECT color FROM users WHERE id = ?', [$kontoKind])) === 1);

// Ein leerer Name wird abgelehnt statt gespeichert.
apiAls($kontoJar, fn () => apiCall('profile', 'save', ['name' => '  ', 'color' => '#123456']));
ok('Ein leerer Name wird abgelehnt',
   qv('SELECT display_name FROM users WHERE id = ?', [$kontoKind]) === 'Kontokind Neu');

// ---- Das Passwort.

[$d, $s] = apiAls($kontoJar, fn () => apiCall('profile', 'password',
    ['current' => 'falsch', 'password' => 'neuespasswort']));
ok('Ohne das bisherige Passwort geht nichts', $s === 403, 'Status ' . $s);

[$d, $s] = apiAls($kontoJar, fn () => apiCall('profile', 'password',
    ['current' => 'geheim123', 'password' => 'kurz']));
ok('Ein zu kurzes neues Passwort wird abgelehnt', $s === 400, 'Status ' . $s);

[$d, $s] = apiAls($kontoJar, fn () => apiCall('profile', 'password',
    ['current' => 'geheim123', 'password' => 'geheim123']));
ok('Dasselbe noch einmal auch', $s === 400, 'Status ' . $s);

[$d, $s] = apiAls($kontoJar, fn () => apiCall('profile', 'password',
    ['current' => 'geheim123', 'password' => 'ganzneuespasswort']));
ok('Mit dem richtigen bisherigen geht es', $s === 200, 'Status ' . $s);

$nachher = q1('SELECT password_hash, initial_password FROM users WHERE id = ?', [$kontoKind]);
ok('Das neue Passwort gilt', password_verify('ganzneuespasswort', $nachher['password_hash']));
ok('Das alte nicht mehr', !password_verify('geheim123', $nachher['password_hash']));

/*
 * Und der Punkt, auf den es ankommt: Das Anfangspasswort verschwindet aus
 * der Datenbank. Es steht dort im Klartext, damit das Anschreiben
 * nachdruckbar bleibt - sobald das Kind sein eigenes gewaehlt hat, ist der
 * Wert wertlos, und der Bestand offener Passwoerter schrumpft mit der Zeit
 * statt zu wachsen.
 */
ok('Und das Anfangspasswort ist aus der Datenbank verschwunden',
   $nachher['initial_password'] === null,
   var_export($nachher['initial_password'], true));

/*
 * Und der Hinweis darauf verschwindet sofort - nicht erst beim naechsten
 * Laden. Er fordert zu etwas auf, das gerade erledigt wurde; bliebe er
 * stehen, fragte man sich, ob es geklappt hat.
 */
$profilQuelle = (string) file_get_contents(__DIR__ . '/../views/profile.js');
ok('Der Hinweis auf das Anfangspasswort ist ansprechbar',
   str_contains($profilQuelle, 'id="initialhint"'));
ok('Und wird nach dem Aendern sofort entfernt',
   preg_match('/hinweis\.remove\(\)/', $profilQuelle) === 1
   && str_contains($profilQuelle, 'profile.initial = false'),
   'sonst bleibt er bis zum naechsten Laden stehen');

// Und die API sagt beim naechsten Abruf dasselbe.
$nochmal = apiAls($kontoJar, fn () => apiCall('profile', 'get')[0]);
ok('Auch die API meldet das Anfangspasswort nicht mehr',
   ($nochmal['profile']['initial'] ?? null) === false,
   var_export($nochmal['profile']['initial'] ?? null, true));

// Ohne Anmeldung geht gar nichts.
$fremdJar = tempnam(sys_get_temp_dir(), 'vtfremd');
[$d, $s] = apiAls($fremdJar, fn () => apiCall('profile', 'get'));
ok('Ohne Anmeldung bleibt das Konto verschlossen', $s === 401, 'Status ' . $s);

q('DELETE FROM users WHERE id = ?', [$kontoKind]);
@unlink($kontoJar);
@unlink($fremdJar);

// ---- Der Weg dorthin in der Oberflaeche.

$listeQuelle2 = (string) file_get_contents(__DIR__ . '/../views/languages.js');
ok('Die App fuehrt zum eigenen Konto',
   str_contains((string) file_get_contents(__DIR__ . '/../core.js'), 'href="#/konto"'),
   'im Einstellungsmenue, von jeder Ansicht aus - nicht nur von der Startseite');
ok('Und die Verwaltung steht ueber den Sprachen',
   strpos($listeQuelle2, '${teacherLink()}') < strpos($listeQuelle2, '<div class="grid">'),
   'der Link steht noch darunter');

$routen = (string) file_get_contents(__DIR__ . '/../app.js');
ok('Die Route dorthin gibt es', str_contains($routen, 'profileView'));

section('Kurs anlegen');

/*
 * Der Kurs war lange der blinde Fleck: Er entstand nur als Nebenwirkung,
 * wenn jemand in der App eine Sprache anlegte - mit dem Namen des Kontos
 * statt der Klasse, der Lehrkraft als Schuelerin und einer geratenen Klasse.
 * Eine Lehrkraft hatte gar keinen Weg, einen anzulegen.
 */

require_once __DIR__ . '/../lib/roster.php';
require_once __DIR__ . '/../lib/worldlanguages.php';

$res = teacherGet('classes.php');
preg_match('/name="csrf" value="([a-f0-9]+)"/', $res['body'], $km);
$lehrerCsrf = $km[1] ?? '';
ok('Die Lehrkraft hat ein Formular-Token', $lehrerCsrf !== '');

$kursKlasse = 'Kurs7' . bin2hex(random_bytes(2));
$res = teacherRequest($base . '/teacher/classes.php', [
    'create_class' => '1', 'name' => $kursKlasse, 'csrf' => $lehrerCsrf,
]);
$kursKlasseId = (int) qv('SELECT id FROM classes WHERE school_id = ? AND name = ?',
    [(int) qv('SELECT school_id FROM users WHERE id = ?', [$lehrerId]), $kursKlasse]);
ok('Eine Klasse fuer den Kurs steht bereit', $kursKlasseId > 0);

teacherRequest($base . '/teacher/class.php?id=' . $kursKlasseId, [
    'add_students' => '1', 'class_id' => $kursKlasseId,
    'names'        => "Ida Berger\nTom Fischer",
    'csrf'         => $lehrerCsrf,
]);
ok('Mit zwei Kindern darin',
   count(class_members_list($kursKlasseId)) === 2);

$res = teacherGet('class.php?id=' . $kursKlasseId);
ok('Die Klasse fuehrt zum Assistenten',
   str_contains($res['body'], 'neu.php?klasse=' . $kursKlasseId),
   'der Kurs entsteht dort, nicht mehr in einer Anlegezeile hier');
ok('Und legt selbst keinen Kurs mehr an',
   !str_contains($res['body'], 'name="create_course"'));

// ---- Schritt 1: Fuer welche Klasse?

$res = teacherGet('neu.php');
ok('Der Assistent fragt zuerst nach der Klasse',
   str_contains($res['body'], '<h1>Für welche Klasse?</h1>'));
ok('Und sagt, wo man steht', str_contains($res['body'], 'Schritt 1 von 2'));
ok('Die Klassen der Schule stehen zur Wahl',
   str_contains($res['body'], 'neu.php?klasse=' . $kursKlasseId)
   && str_contains($res['body'], h($kursKlasse)));
ok('Und auf der Kachel steht, was die Wahl bedeutet',
   str_contains($res['body'], 'kommen mit in den Kurs'),
   'sonst muss man raten, was die Klasse mit dem Kurs zu tun hat');
ok('"Kurs ohne Klasse" steht daneben, nicht darunter',
   str_contains($res['body'], 'neu.php?klasse=0')
   && str_contains($res['body'], 'Kurs ohne Klasse'));
ok('Eine Klasse laesst sich hier anlegen',
   str_contains($res['body'], 'name="neue_klasse"'),
   'sonst ist der erste Schritt fuer eine neue Lehrkraft eine Sackgasse');
ok('Auf Schritt 1 ist noch keine Sprache zu sehen',
   !str_contains($res['body'], 'data-picker'),
   'eine Frage je Seite');

// Eine Klasse von hier aus: Danach geht es gleich weiter zur Sprache.
$ausAssistent = 'Assi' . bin2hex(random_bytes(2));
$res = teacherRequest($base . '/teacher/neu.php', [
    'neue_klasse' => '1', 'klassenname' => $ausAssistent, 'csrf' => $lehrerCsrf,
]);
$assiKlasseId = (int) qv('SELECT id FROM classes WHERE school_id = ? AND name = ?',
    [(int) qv('SELECT school_id FROM users WHERE id = ?', [$lehrerId]), $ausAssistent]);
ok('Eine Klasse entsteht im Assistenten', $assiKlasseId > 0);
ok('Und der Assistent steht danach bei der Sprache',
   str_contains($res['body'], '<h1>Für welche Sprache?</h1>'),
   'wer beim Kursanlegen eine Klasse anlegt, will sie auch nehmen');

// ---- Schritt 2: Fuer welche Sprache?

$res = teacherGet('neu.php?klasse=' . $kursKlasseId);
ok('Schritt 2 fragt nach der Sprache',
   str_contains($res['body'], '<h1>Für welche Sprache?</h1>'));
ok('Und sagt, wo man steht', str_contains($res['body'], 'Schritt 2 von 2'));
ok('Die Klasse steht auf den Kacheln',
   str_contains($res['body'], h($kursKlasse)),
   'sonst weiss man auf Schritt 2 nicht mehr, fuer wen');

/*
 * Die fuenf Schulsprachen als Kacheln. Jede ist ein Absendeknopf, der
 * ihren Namen traegt; abgeschickt wird nur der
 * gedrueckte. Das kann HTML von sich aus.
 */
ok('Die fuenf Schulsprachen stehen als Kacheln da',
   substr_count($res['body'], 'class="card wahlkarte" name="sprache"') === 5,
   substr_count($res['body'], 'class="card wahlkarte" name="sprache"') . ' statt 5');
ok('Englisch ist eine davon',
   str_contains($res['body'], 'name="sprache" value="Englisch"'));
ok('Auf der Kachel steht, wie der Kurs heissen wird',
   str_contains($res['body'], 'Englisch - ' . h($kursKlasse)),
   'sonst ist der Name eine Ueberraschung');

/*
 * Und darunter alle uebrigen. Was das Skript daraus macht - ein
 * durchsuchbares Feld - laesst sich von hier aus nicht ausfuehren; geprueft
 * wird, dass die Bausteine da sind. Ohne sie faellt es auf ein gewoehnliches
 * Auswahlfeld zurueck, und auch das muss bedienbar bleiben.
 */
ok('Alle uebrigen Sprachen stehen als Auswahlfeld im HTML',
   substr_count($res['body'], 'data-flag=') > 80,
   substr_count($res['body'], 'data-flag=') . ' Eintraege');
ok('Die fuenf Schulsprachen sind darin als solche gekennzeichnet',
   substr_count($res['body'], 'data-top="1"') === 5,
   substr_count($res['body'], 'data-top="1"') . ' statt 5');
ok('Es gibt kein Feld fuer die Flagge',
   !str_contains($res['body'], 'name="flag"'),
   'eine Flagge ist eine Eigenschaft der Sprache, keine Entscheidung');
ok('Und im Regelfall auch keines fuer den Namen',
   !str_contains($res['body'], 'name="name"'),
   'der Name ergibt sich aus Sprache und Klasse');
ok('Das Skript wird geladen', str_contains($res['body'], 'teacher.js?v='));

$skript = http($base . '/teacher/teacher.js');
ok('Und ist abrufbar', $skript['status'] === 200, 'Status ' . $skript['status']);
ok('Es kennt beide Schreibweisen ohne Umlaute',
   str_contains($skript['body'], 'ohnePunkte') && str_contains($skript['body'], 'ausgeschrieben'),
   'sonst findet "danisch" kein Daenisch');

/*
 * Die aufgeklappte Liste darf die Tabelle nicht abschneiden.
 *
 * table.data traegt overflow: hidden fuer die runden Ecken - ein Kind mit
 * position: absolute wird dort gekappt. Mit position: fixed haengt das
 * Panel an keinem Vorfahren mehr; dafuer muss seine Lage von Hand gesetzt
 * werden.
 */
$pickerCss = (string) file_get_contents(__DIR__ . '/../admin/admin.css');
ok('Die aufgeklappte Liste haengt nicht in der Tabelle',
   preg_match('/\.pickpanel\s*\{[^}]*position:\s*fixed/s', $pickerCss) === 1,
   'mit position: absolute schneidet die Tabelle sie ab');
ok('Und das Skript setzt ihre Lage',
   str_contains($skript['body'], 'getBoundingClientRect')
   && str_contains($skript['body'], "addEventListener('scroll'"),
   'ein festes Panel muss beim Scrollen mitgefuehrt werden');

// ---- Und dann steht der Kurs.

$res = teacherRequest($base . '/teacher/neu.php', [
    'klasse' => $kursKlasseId, 'sprache' => 'Englisch', 'csrf' => $lehrerCsrf,
]);
$neuerKurs = q1('SELECT * FROM courses WHERE name = ?', ['Englisch - ' . $kursKlasse]);
ok('Der Kurs heisst "Sprache - Klasse"', $neuerKurs !== null,
   'Englisch - ' . $kursKlasse . ' nicht gefunden');

$neuerKursId = (int) ($neuerKurs['id'] ?? 0);
ok('Er haengt an der Klasse',
   (int) ($neuerKurs['class_id'] ?? 0) === $kursKlasseId);
ok('Und an der Schule der Lehrkraft',
   (int) ($neuerKurs['school_id'] ?? 0)
   === (int) qv('SELECT school_id FROM users WHERE id = ?', [$lehrerId]));
ok('Danach steht man auf der Kursseite',
   str_contains($res['body'], 'class="ansichtwahl"')
   && str_contains($res['body'], 'Lerneinheiten'),
   'der Assistent endet dort, wo man hinwollte');

$eigeneSprache = q1('SELECT * FROM languages WHERE id = ?',
                    [(int) ($neuerKurs['language_id'] ?? 0)]);
ok('Zum Kurs gehoert eine eigene Sprache',
   ($eigeneSprache['name'] ?? '') === 'Englisch');
ok('Flagge und Kuerzel kommen aus der Sprachliste',
   ($eigeneSprache['flag_emoji'] ?? '') === language_flag('Englisch')
   && ($eigeneSprache['code'] ?? '') === 'en',
   ($eigeneSprache['flag_emoji'] ?? '-') . ' / ' . ($eigeneSprache['code'] ?? '-'));

/*
 * Die Besetzung: Lehrkraft als Lehrkraft, die Kinder der Klasse als
 * SchuelerInnen. Frueher stand die Lehrkraft im eigenen Kurs als Schuelerin.
 */
$besetzung = course_members_list($neuerKursId);
$rollen    = [];
foreach ($besetzung as $m) {
    $rollen[$m['display_name']] = $m['member_role'];
}
ok('Die Lehrkraft ist als Lehrkraft drin',
   ($rollen['Frau Meier'] ?? '') === 'teacher', json_encode($rollen));
ok('Und die Kinder der Klasse als SchuelerInnen',
   ($rollen['Ida B.'] ?? '') === 'student' && ($rollen['Tom F.'] ?? '') === 'student',
   json_encode($rollen));

// ---- Zweimal derselbe Kurs: abgelehnt, aber nicht als Sackgasse.

$vorher = (int) qv('SELECT COUNT(*) FROM courses');
$res = teacherRequest($base . '/teacher/neu.php', [
    'klasse' => $kursKlasseId, 'sprache' => 'Englisch', 'csrf' => $lehrerCsrf,
]);
ok('Denselben Kurs zweimal anzulegen wird abgelehnt',
   (int) qv('SELECT COUNT(*) FROM courses') === $vorher
   && str_contains($res['body'], 'gibt es an dieser Schule schon'));
ok('Und zwar auf Schritt 2, nicht zurueck auf Schritt 1',
   str_contains($res['body'], '<h1>Für welche Sprache?</h1>'),
   'die Klasse steht ja schon fest');
ok('Erst jetzt fragt der Assistent nach einem Namen',
   str_contains($res['body'], 'name="name"'),
   'keine Zusatzfrage, sondern die Antwort auf ein Problem');

$res = teacherRequest($base . '/teacher/neu.php', [
    'klasse' => $kursKlasseId, 'sprache' => 'Englisch',
    'name'   => 'Englisch - ' . $kursKlasse . ' (zweite Gruppe)', 'csrf' => $lehrerCsrf,
]);
$zweiteGruppe = q1('SELECT * FROM courses WHERE name = ?',
                   ['Englisch - ' . $kursKlasse . ' (zweite Gruppe)']);
ok('Mit eigenem Namen geht es dann doch', $zweiteGruppe !== null);
q('DELETE FROM languages WHERE id = ?', [(int) ($zweiteGruppe['language_id'] ?? 0)]);
q('DELETE FROM courses   WHERE id = ?', [(int) ($zweiteGruppe['id'] ?? 0)]);

/*
 * Eine zweite Klasse darf dieselbe Sprache haben - mit eigenen Unterlagen.
 */
$zweiteKlasse = 'Kurs8' . bin2hex(random_bytes(2));
$zweiteKlasseId = (int) (class_create(
    (int) qv('SELECT school_id FROM users WHERE id = ?', [$lehrerId]), $zweiteKlasse,
)['id'] ?? 0);
$res = teacherRequest($base . '/teacher/neu.php', [
    'klasse' => $zweiteKlasseId, 'sprache' => 'Englisch', 'csrf' => $lehrerCsrf,
]);
$zweiter = q1('SELECT * FROM courses WHERE name = ?', ['Englisch - ' . $zweiteKlasse]);
ok('Eine zweite Gruppe darf dieselbe Sprache lernen', $zweiter !== null,
   'Englisch - ' . $zweiteKlasse . ' nicht gefunden');
ok('Und bekommt dafuer eine eigene Sprachzeile',
   $zweiter !== null
   && (int) $zweiter['language_id'] !== (int) ($neuerKurs['language_id'] ?? 0));

// ---- Und ein Kurs ganz ohne Klasse.

/*
 * Ueber die Oberflaeche gab es ihn eine Zeit lang nicht mehr, obwohl das
 * Datenmodell ihn kann: Kurse entstanden nur noch in einer Klasse. Fuer
 * eine Arbeitsgemeinschaft quer durch die Jahrgaenge ist das die falsche
 * Form - jetzt ist es wieder eine Kachel wie jede andere.
 */
$ohneName = 'Ohnisch' . bin2hex(random_bytes(2));
$res = teacherRequest($base . '/teacher/neu.php', [
    'klasse' => '0', 'sprache' => $ohneName, 'csrf' => $lehrerCsrf,
]);
$ohneKurs = q1('SELECT * FROM courses WHERE name = ?', [$ohneName]);
ok('Ein Kurs ohne Klasse laesst sich anlegen', $ohneKurs !== null, $ohneName);
ok('Er haengt an keiner Klasse',
   $ohneKurs !== null && $ohneKurs['class_id'] === null);
ok('Und heisst nur nach der Sprache', ($ohneKurs['name'] ?? '') === $ohneName);
ok('Drin ist erst einmal nur die Lehrkraft',
   count(course_members_list((int) ($ohneKurs['id'] ?? 0))) === 1,
   'Kinder nimmt man einzeln auf');
ok('Eine freie Sprache bekommt die Weltkugel',
   (string) qv('SELECT flag_emoji FROM languages WHERE id = ?',
               [(int) ($ohneKurs['language_id'] ?? 0)]) === "\u{1F310}");

$res = teacherGet('course.php?id=' . (int) ($ohneKurs['id'] ?? 0));
ok('Auch er fuehrt in die Klassenverwaltung',
   str_contains($res['body'], 'classes.php'),
   'von der Hauptansicht aus muss alles erreichbar sein');
ok('Und er bietet an, Kinder der Schule einzeln aufzunehmen',
   str_contains($res['body'], 'name="add_member_by_name"')
   && str_contains($res['body'], 'list="kandidaten"'),
   'sonst bleibt ein Kurs ohne Klasse fuer immer leer');
ok('Die Vorschlagsliste nennt Kinder der Schule',
   str_contains($res['body'], '<option value="Ida B."'),
   'wer schon im Kurs ist, steht nicht darin - Ida ist es nicht');

q('DELETE FROM languages WHERE id = ?', [(int) ($ohneKurs['language_id'] ?? 0)]);
q('DELETE FROM courses   WHERE id = ?', [(int) ($ohneKurs['id'] ?? 0)]);

// ---- Die Grenze: eine Klasse einer anderen Schule.

q("INSERT IGNORE INTO schools (name) VALUES ('Fremde Schule 9')");
$fremdeSchule9 = (int) qv("SELECT id FROM schools WHERE name = 'Fremde Schule 9'");
$fremdeKlasse9 = class_create($fremdeSchule9, 'Fremdklasse9');
$fremdeKlasse9Id = (int) ($fremdeKlasse9['id'] ?? 0);

$kurseVorher = (int) qv('SELECT COUNT(*) FROM courses');
$res = teacherRequest($base . '/teacher/neu.php', [
    'klasse' => $fremdeKlasse9Id, 'sprache' => 'Englisch', 'csrf' => $lehrerCsrf,
]);
ok('Fuer eine Klasse einer anderen Schule entsteht kein Kurs',
   (int) qv('SELECT COUNT(*) FROM courses') === $kurseVorher);
ok('Und es heisst nur, dass es sie nicht gibt',
   str_contains($res['body'], 'Diese Klasse gibt es nicht'),
   'wer sie nicht sehen darf, soll nicht erfahren, dass es sie gibt');

q('DELETE FROM classes WHERE id = ?', [$fremdeKlasse9Id]);
q('DELETE FROM schools WHERE id = ?', [$fremdeSchule9]);
q('DELETE FROM classes WHERE id = ?', [$assiKlasseId]);

// ---- Mitglieder im Kurs pflegen.

/*
 * Ida ueber die Klasse holen, nicht ueber den Anzeigenamen: Den kann es
 * mehrfach geben, und dann prueft der Abschnitt an einem fremden Konto
 * herum - die erste Zusicherung waere sogar gruen, weil ein Unbeteiligter
 * erst recht nicht im Kurs ist.
 */
$ida = (int) qv(
    "SELECT u.id FROM class_members m
       JOIN users u ON u.id = m.user_id
      WHERE m.class_id = ? AND u.display_name = 'Ida B.'",
    [$kursKlasseId],
);
ok('Ida ist eindeutig bestimmt', $ida > 0);
teacherRequest($base . '/teacher/course.php?id=' . $neuerKursId, [
    'remove_member' => $ida, 'course_id' => $neuerKursId, 'csrf' => $lehrerCsrf,
]);
ok('Jemand laesst sich aus dem Kurs nehmen',
   course_role($ida, $neuerKursId) === null);

/*
 * Und der Knopf sagt, was er tun wuerde.
 *
 * "Klasse 5B nachtragen" liess offen, ob dabei etwas passiert - und meistens
 * passierte nichts: Die Kinder kommen beim Anlegen des Kurses mit hinein.
 * Wer draufdrueckte, bekam "Es war niemand nachzutragen", also eine Auskunft
 * auf eine Frage, die er nicht gestellt hatte.
 */
ok('Genau eines fehlt jetzt', course_class_missing($neuerKursId) === 1,
   (string) course_class_missing($neuerKursId));

$res = teacherGet('course.php?id=' . $neuerKursId);
ok('Der Knopf nennt die Zahl der Fehlenden',
   preg_match('/1\s+fehlendes Kind\s+aus Klasse ' . preg_quote($kursKlasse, '/')
              . '\s+eintragen/s', $res['body']) === 1,
   'sonst weiss man erst nach dem Druecken, ob er etwas tut');
ok('Und steht dabei nicht abgeblendet',
   preg_match('/name="sync_class" value="1"\s*>/s', $res['body']) === 1,
   'es gibt ja etwas zu tun');

teacherRequest($base . '/teacher/course.php?id=' . $neuerKursId, [
    'sync_class' => '1', 'course_id' => $neuerKursId, 'csrf' => $lehrerCsrf,
]);
ok('Und die Klasse laesst sich nachtragen',
   course_role($ida, $neuerKursId) === 'student');

ok('Danach fehlt niemand mehr', course_class_missing($neuerKursId) === 0);

$res = teacherGet('course.php?id=' . $neuerKursId);
ok('Dann steht der Knopf abgeblendet da',
   preg_match('/name="sync_class" value="1"\s+disabled/s', $res['body']) === 1,
   'dastehen soll er trotzdem - sonst sucht man ihn beim naechsten Mal');
ok('Und sagt, dass alle drin sind',
   preg_match('/Alle Kinder aus Klasse ' . preg_quote($kursKlasse, '/')
              . ' sind im Kurs/', $res['body']) === 1);
ok('Und nennt im Titel den Grund',
   str_contains($res['body'], 'Alle Kinder der Klasse sind schon im Kurs.'));

/*
 * Ein Kurs ohne Klasse hat nichts nachzutragen - dort steht der Knopf gar
 * nicht erst, und die Abfrage darf ihn auch nicht zaehlen.
 */
$zaehlKurs = course_create(
    q1('SELECT * FROM users WHERE id = ?', [$lehrerId]),
    'Zaehlisch' . bin2hex(random_bytes(2)), "\u{1F310}", null, '',
);
if (!is_string($zaehlKurs)) {
    ok('Ein Kurs ohne Klasse zaehlt keine Fehlenden',
       course_class_missing((int) $zaehlKurs['id']) === 0);
    $zaehlSeite = teacherGet('course.php?id=' . (int) $zaehlKurs['id']);
    ok('Und bietet das Nachtragen gar nicht erst an',
       !str_contains($zaehlSeite['body'], 'name="sync_class"'));
    q('DELETE FROM languages WHERE id = ?', [(int) $zaehlKurs['language_id']]);
    q('DELETE FROM courses   WHERE id = ?', [(int) $zaehlKurs['id']]);
}

// ---- Das Suchfeld fuer die Aufnahme.

/*
 * Eine Schule hat dreihundert Kinder, und der Kurs braucht eines davon.
 * Was das Skript daraus macht - Filtern bei jedem Zeichen, eine Liste
 * darunter, die graue Ergaenzung bei genau einem Treffer - laesst sich von
 * hier aus nicht ausfuehren; geprueft wird, dass die Bausteine da sind.
 * Ohne sie bleibt ein Textfeld mit <datalist>, und auch das muss bedienbar
 * sein.
 */
$res = teacherGet('course.php?id=' . $neuerKursId);
ok('Das Feld ist als Suchfeld ausgezeichnet',
   str_contains($res['body'], 'data-suche')
   && str_contains($res['body'], 'name="member_name"'));
ok('Die graue Ergaenzung hat ihren Platz',
   str_contains($res['body'], 'class="geist"'),
   'ein Eingabefeld kann nicht zwei Farben zugleich');
ok('Und die Vorschlagsliste auch',
   str_contains($res['body'], 'class="vorschlaege"'));
ok('Die Namen stehen weiterhin als <datalist> im HTML',
   str_contains($res['body'], '<datalist id="kandidaten">'),
   'das ist der Weg ohne JavaScript - und die Quelle fuer das Skript');
ok('Das Skript liest sie von dort',
   str_contains($skript['body'], 'function initMemberSearch')
   && str_contains($skript['body'], "feld.removeAttribute('list')"),
   'zwei Vorschlagslisten uebereinander waeren eine zu viel');
ok('Und es sucht ohne Umlaute wie das Sprachfeld',
   preg_match('/^const passt = /m', $skript['body']) === 1,
   'dieselbe Faltung, an einer Stelle');

/*
 * Die Grenze: Ein Konto einer anderen Schule kommt nicht in den Kurs, auch
 * nicht ueber ein untergeschobenes Formular.
 */
q("INSERT IGNORE INTO schools (name) VALUES ('Fremde Schule 4')");
$fremdeSchule4 = (int) qv("SELECT id FROM schools WHERE name = 'Fremde Schule 4'");
$fremdesKind   = makeUser('e2e_fremdkind', 'Fremdkind');
q('UPDATE users SET school_id = ? WHERE id = ?', [$fremdeSchule4, $fremdesKind]);

teacherRequest($base . '/teacher/course.php?id=' . $neuerKursId, [
    'add_member' => $fremdesKind, 'course_id' => $neuerKursId, 'csrf' => $lehrerCsrf,
]);
ok('Ein Kind einer anderen Schule kommt nicht in den Kurs',
   course_role($fremdesKind, $neuerKursId) === null);

q('DELETE FROM users WHERE id = ?', [$fremdesKind]);
q('DELETE FROM schools WHERE id = ?', [$fremdeSchule4]);

// ---- Einen Kurs loeschen.

/*
 * Der Kurs bekommt erst etwas zu verlieren: eine Lerneinheit mit Vokabeln,
 * einen Lueckensatz und einen Lernstand. Sonst prueft der Abschnitt das
 * Loeschen einer leeren Huelle, und genau die gefaehrlichen Faelle - haengen
 * die Lernstaende mit dran, bleiben Waisen liegen? - blieben ungeprueft.
 */
$loeschUnit = makeUnit($freiLehrerId ?? $lehrerId, (int) $neuerKurs['language_id'], 'Zu löschen');
q('UPDATE units SET course_id = ? WHERE id = ?', [$neuerKursId, $loeschUnit]);
foreach (['un', 'deux'] as $i => $w) {
    q('INSERT INTO vocab (unit_id, term_foreign, term_native, position) VALUES (?, ?, ?, ?)',
      [$loeschUnit, $w, 'de-' . $w, $i]);
}
$loeschVokabel = (int) qv('SELECT id FROM vocab WHERE unit_id = ? ORDER BY position LIMIT 1',
                          [$loeschUnit]);
q('INSERT INTO sentences (vocab_id, native_text, foreign_text, answer)
   VALUES (?, ?, ?, ?)', [$loeschVokabel, 'Eins.', '{} .', 'un']);
record_answer($ida, $loeschVokabel, MODE_CHOICE, true);

$vorschau = course_delete_preview($neuerKursId);
ok('Die Vorschau zaehlt, was verloren ginge',
   $vorschau['units'] === 1 && $vorschau['vocab'] === 2
   && $vorschau['sentences'] === 1 && $vorschau['progress'] === 1
   && $vorschau['students'] >= 1,
   json_encode($vorschau));

$res = teacherGet('course.php?id=' . $neuerKursId);
ok('Die Seite bietet das Loeschen an', str_contains($res['body'], 'name="delete_course"'));
ok('Und nennt die Folgen in Zahlen',
   str_contains($res['body'], 'Gespeicherte Lernstände')
   && str_contains($res['body'], 'Kinder verlieren den Zugang'));
ok('Sie verlangt das eigene Passwort',
   str_contains($res['body'], 'name="password"'));

// Ohne Passwort passiert nichts.
teacherRequest($base . '/teacher/course.php?id=' . $neuerKursId, [
    'delete_course' => '1', 'course_id' => $neuerKursId, 'csrf' => $lehrerCsrf,
]);
ok('Ohne Passwort wird nicht geloescht',
   q1('SELECT id FROM courses WHERE id = ?', [$neuerKursId]) !== null);

// Mit falschem auch nicht.
$res = teacherRequest($base . '/teacher/course.php?id=' . $neuerKursId, [
    'delete_course' => '1', 'course_id' => $neuerKursId,
    'password'      => 'bestimmt-falsch', 'csrf' => $lehrerCsrf,
]);
ok('Mit falschem Passwort auch nicht',
   q1('SELECT id FROM courses WHERE id = ?', [$neuerKursId]) !== null
   && str_contains($res['body'], 'Passwort stimmt nicht'));

// Und mit dem richtigen.
$res = teacherRequest($base . '/teacher/course.php?id=' . $neuerKursId, [
    'delete_course' => '1', 'course_id' => $neuerKursId,
    'password'      => 'lehrerin123', 'csrf' => $lehrerCsrf,
]);
ok('Mit dem richtigen Passwort ist der Kurs weg',
   q1('SELECT id FROM courses WHERE id = ?', [$neuerKursId]) === null);
ok('Die Meldung sagt, was mitgegangen ist',
   str_contains($res['body'], 'geloescht') || str_contains($res['body'], 'gelöscht'));

/*
 * Der Kern: Es darf nichts liegenbleiben. units.course_id traegt keinen
 * Fremdschluessel - ohne ausdrueckliches Loeschen blieben die Einheiten als
 * Waisen zurueck, unsichtbar, aber weiter in jeder Kostenrechnung.
 */
ok('Die Lerneinheit ist mitgegangen',
   q1('SELECT id FROM units WHERE id = ?', [$loeschUnit]) === null);
ok('Die Vokabeln auch',
   (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ?', [$loeschUnit]) === 0);
ok('Die Lueckensaetze auch',
   (int) qv('SELECT COUNT(*) FROM sentences WHERE vocab_id = ?', [$loeschVokabel]) === 0);
ok('Und die Lernstaende der Kinder',
   (int) qv('SELECT COUNT(*) FROM progress WHERE vocab_id = ?', [$loeschVokabel]) === 0);
ok('Die Kursmitgliedschaften sind aufgeloest',
   (int) qv('SELECT COUNT(*) FROM course_members WHERE course_id = ?', [$neuerKursId]) === 0);
ok('Die Sprache des Kurses ist mitgegangen',
   q1('SELECT id FROM languages WHERE id = ?', [(int) $neuerKurs['language_id']]) === null);

$waisen = (int) qv('SELECT COUNT(*) FROM units t
                     WHERE t.course_id IS NOT NULL
                       AND NOT EXISTS (SELECT 1 FROM courses co WHERE co.id = t.course_id)');
ok('Und nirgends bleibt eine Lerneinheit ohne Kurs zurueck',
   $waisen === 0, $waisen . ' Waisen');

// Die Kinder selbst bleiben natuerlich.
ok('Die Kinder gibt es weiterhin',
   q1('SELECT id FROM users WHERE id = ?', [$ida]) !== null);

/*
 * Der Fall, auf den es beim ausdruecklichen Loeschen der Einheiten ankommt:
 * zwei Kurse an einer Sprache.
 *
 * Im Regelfall gibt es den nicht - course_create() legt je Kurs eine eigene
 * Sprache an, und ihr Loeschen nimmt die Einheiten ohnehin mit. Deshalb
 * faellt ein Entfernen der Zeile "DELETE FROM units" in den Pruefungen
 * darueber gar nicht auf. Hier wird die Ausnahme absichtlich hergestellt:
 * Bleibt die Sprache stehen, weil ein zweiter Kurs an ihr haengt, muessen
 * die Einheiten des geloeschten trotzdem verschwinden.
 */
$geteilteSprache = makeLanguage($lehrerId, 'Geteiltisch');
$kursA = (int) course_for_language($geteilteSprache)['id'];
q('INSERT INTO courses (school_id, language_id, name, created_by) VALUES (?, ?, ?, ?)',
  [(int) qv('SELECT school_id FROM users WHERE id = ?', [$lehrerId]),
   $geteilteSprache, 'Zweiter an derselben Sprache', $lehrerId]);
$kursB = (int) db()->lastInsertId();

$unitA = makeUnit($lehrerId, $geteilteSprache, 'Einheit A');
q('UPDATE units SET course_id = ? WHERE id = ?', [$kursA, $unitA]);
$unitB = makeUnit($lehrerId, $geteilteSprache, 'Einheit B');
q('UPDATE units SET course_id = ? WHERE id = ?', [$kursB, $unitB]);

course_delete($kursA);

ok('Bei geteilter Sprache geht die Einheit des geloeschten Kurses mit',
   q1('SELECT id FROM units WHERE id = ?', [$unitA]) === null,
   'sie waere als Waise liegengeblieben');
ok('Die des anderen Kurses bleibt',
   q1('SELECT id FROM units WHERE id = ?', [$unitB]) !== null);
ok('Und die geteilte Sprache bleibt auch',
   q1('SELECT id FROM languages WHERE id = ?', [$geteilteSprache]) !== null);

course_delete($kursB);
ok('Erst der letzte Kurs nimmt die Sprache mit',
   q1('SELECT id FROM languages WHERE id = ?', [$geteilteSprache]) === null);

/*
 * Und die Grenze: Ein Kurs einer ANDEREN Schule laesst sich auch mit
 * richtigem Passwort nicht loeschen.
 *
 * Innerhalb der eigenen Schule darf jede Lehrkraft loeschen, auch fremde
 * Kurse - eine Schule ist eine Vertrauensgemeinschaft, und wer vertritt,
 * muss aufraeumen koennen. Ueber die Schulgrenze hinweg nicht.
 */
q("INSERT IGNORE INTO schools (name) VALUES ('Fremde Schule 5')");
$fremdeSchule5 = (int) qv("SELECT id FROM schools WHERE name = 'Fremde Schule 5'");
q('INSERT INTO languages (school_id, name, flag_emoji, code) VALUES (?, ?, ?, ?)',
  [$fremdeSchule5, 'Fremdsprachisch', '', 'xx']);
$fremdLangId = (int) db()->lastInsertId();
q('INSERT INTO courses (school_id, language_id, name) VALUES (?, ?, ?)',
  [$fremdeSchule5, $fremdLangId, 'Fremder Kurs 5']);
$fremdKursId = (int) db()->lastInsertId();

$res = teacherRequest($base . '/teacher/course.php?id=' . $fremdKursId, [
    'delete_course' => '1', 'course_id' => $fremdKursId,
    'password'      => 'lehrerin123', 'csrf' => $lehrerCsrf,
]);
ok('Ein Kurs einer anderen Schule bleibt unberuehrt',
   q1('SELECT id FROM courses WHERE id = ?', [$fremdKursId]) !== null);
ok('Und seine Sprache auch',
   q1('SELECT id FROM languages WHERE id = ?', [$fremdLangId]) !== null);

q('DELETE FROM languages WHERE id = ?', [$fremdLangId]);
q('DELETE FROM schools WHERE id = ?', [$fremdeSchule5]);

// Aufraeumen: Kurse, Sprachen, Kinder, Klasse.
foreach (class_members_list($kursKlasseId) as $m) {
    q('DELETE FROM users WHERE id = ?', [(int) $m['id']]);
}
q('DELETE FROM languages WHERE id IN (?, ?)',
  [(int) ($neuerKurs['language_id'] ?? 0), (int) ($zweiter['language_id'] ?? 0)]);
q('DELETE FROM classes WHERE id = ?', [$kursKlasseId]);

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
/*
 * Kein Knopf "Öffnen" mehr: Die Zeile selbst oeffnet. Eine Spalte, die in
 * jeder Zeile dasselbe sagt, war auf dem Telefon die breiteste.
 */
ok('Es gibt keinen Oeffnen-Knopf mehr',
   !preg_match('/>\s*Öffnen\s*</u', $res['body']));
ok('Die Zeile traegt stattdessen ihr Ziel',
   preg_match('/<tr[^>]*data-href="[^"]*class\.php\?id=\d+"/', $res['body']) === 1);
ok('Und der Name darin ist ein echter Link',
   preg_match('/<a class="rowmain" href="[^"]*class\.php\?id=\d+"/', $res['body']) === 1,
   'sonst kommt niemand ohne Zeiger dorthin');

/*
 * Dasselbe Muster wie bei den Kursen: eine Tabelle, und die letzte Zeile
 * ist die neue. Ein Formular kann sich in HTML nicht ueber mehrere Zellen
 * spannen, deshalb liegt es daneben und die Felder verweisen darauf.
 */
ok('Das Anlegen steckt in der Klassentabelle',
   preg_match('/<tr class="newrow">/', $res['body']) === 1);
ok('Das Namensfeld gehoert ueber form= dazu',
   str_contains($res['body'], 'form="newclass"'));
ok('Es gibt keine eigene Karte mehr dafuer',
   !preg_match('/<h2>Neue Klasse<\/h2>/', $res['body']));
ok('Die Tabelle zeigt auch, wie viele Kurse an der Klasse haengen',
   preg_match('/<th class="num">Kurse<\/th>/', $res['body']) === 1);

/*
 * Die Navigation ist der Pfad. Zwei feste Reiter darueber gab es einmal -
 * sie sagten dasselbe ein zweites Mal, und der Reiter "Kurse" fuehrte auf
 * eine Liste, die es nicht mehr gibt.
 */
$navQuelle = (string) file_get_contents(__DIR__ . '/../teacher/_boot.php');
ok('Es gibt keine festen Reiter mehr',
   !str_contains($navQuelle, "'index.php'   => 'Kurse'"));
ok('Die Leiste traegt die beiden Menues',
   preg_match('/adminbar.*?menuLinks.*?menuRechts/s', $navQuelle) === 1);
ok('Und keinen Pfad mehr',
   !str_contains($navQuelle, 'teacher_crumbs')
   && !str_contains($navQuelle, 'teacher_school_crumb'),
   'drei Knoepfe mit Kursnamen darin brauchen am Telefon zwei Zeilen');

$res2 = teacherGet('index.php');
ok('Die alte Kursliste leitet auf die Klassen',
   str_contains($res2['body'], 'Klassen') && !str_contains($res2['body'], 'name="create_course"'),
   'ein Lesezeichen darauf soll nicht ins Leere fuehren');
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
 * Einzeln nachtragen - der Weg fuer alles nach dem ersten Mal.
 *
 * Mit JavaScript wird daraus ein Zug: Namen tippen, Enter, naechster Name.
 * Dafuer antwortet der Endpunkt mit der frischen Zeile statt mit einer
 * ganzen Seite. Ohne JavaScript schickt dasselbe Formular gewoehnlich ab;
 * beide Wege werden hier gefahren.
 */
function kindAnlegen(int $klasseId, string $name, bool $alsJson): array
{
    global $base, $lehrerJar, $lehrerCsrf;

    $ch = curl_init($base . '/teacher/class.php?id=' . $klasseId);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $lehrerJar,
        CURLOPT_COOKIEFILE     => $lehrerJar,
        CURLOPT_FOLLOWLOCATION => !$alsJson,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'add_student' => '1',
            'class_id'    => $klasseId,
            'student'     => $name,
            'csrf'        => $lehrerCsrf,
        ]),
    ]);
    if ($alsJson) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Requested-With: fetch']);
    }
    $body   = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true)];
}

$vorher = count(class_members_list($klasseId));
$res = kindAnlegen($klasseId, 'Fritz Brinkmann', true);

ok('Ein einzelnes Kind laesst sich nachtragen', $res['status'] === 200, 'Status ' . $res['status']);
ok('Die Antwort ist die frische Zeile, keine ganze Seite',
   ($res['json']['ok'] ?? false) === true && isset($res['json']['kind']),
   substr($res['body'], 0, 120));
/*
 * Der Benutzername darf eine Ziffer tragen: Er ist ueber alle Schulen
 * hinweg eindeutig, und ein zweiter "fritz.b" wird durchgezaehlt. Geprueft
 * wird die Form, nicht ein fester Wert.
 */
ok('Mit Name, Benutzername und Anfangspasswort',
   ($res['json']['kind']['name'] ?? '') === 'Fritz B.'
   && preg_match('/^fritz\.b\d*$/', (string) ($res['json']['kind']['username'] ?? '')) === 1
   && preg_match('/^\p{L}+-\p{L}+$/u', (string) ($res['json']['kind']['password'] ?? '')) === 1,
   json_encode($res['json']['kind'] ?? null));
ok('Und das Kind ist wirklich in der Klasse',
   count(class_members_list($klasseId)) === $vorher + 1);

// Derselbe Name ein zweites Mal legt niemanden doppelt an.
$res = kindAnlegen($klasseId, 'Fritz Brinkmann', true);
ok('Derselbe Name zweimal wird abgelehnt',
   $res['status'] === 409 && ($res['json']['ok'] ?? true) === false,
   'Status ' . $res['status']);
ok('Und die Meldung sagt, warum',
   str_contains((string) ($res['json']['error'] ?? ''), 'schon in der Klasse'));

$res = kindAnlegen($klasseId, '   ', true);
ok('Eine leere Eingabe wird abgelehnt', $res['status'] === 422, 'Status ' . $res['status']);

/*
 * Und ohne JavaScript: dasselbe Formular, gewoehnlich abgeschickt. Das ist
 * kein Beiwerk - faellt das Skript aus, muss die Seite bedienbar bleiben.
 */
$vorher = count(class_members_list($klasseId));
$res = kindAnlegen($klasseId, 'Greta Sommerfeld', false);
ok('Ohne JavaScript geht derselbe Weg',
   count(class_members_list($klasseId)) === $vorher + 1
   && str_contains($res['body'], 'Greta S.'),
   'Status ' . $res['status']);

// Der Nachname darf auch hier nirgends ankommen.
$spuren = (int) qv(
    "SELECT COUNT(*) FROM users WHERE display_name LIKE '%Brinkmann%'
                                   OR username LIKE '%brinkmann%'
                                   OR display_name LIKE '%Sommerfeld%'"
);
ok('Die Nachnamen stehen nirgends in der Datenbank', $spuren === 0, $spuren . ' Spuren');

// ---- Die Seite selbst.

$res = teacherGet('class.php?id=' . $klasseId);
ok('Die Kindertabelle hat eine Zeile zum Hinzufuegen',
   str_contains($res['body'], 'id="neuesKind"'));
ok('Das Feld gehoert ueber form= zum Formular',
   str_contains($res['body'], 'form="newstudent"'));
ok('Unter der Tabelle steht, dass Nachnamen nicht gespeichert werden',
   str_contains($res['body'], 'Nachnamen werden nicht gespeichert'));
ok('Die Klasse zeigt auch ihre Kurse',
   str_contains($res['body'], 'Kurse dieser Klasse'));

/*
 * Das Textfeld fuer die ganze Liste gibt es nur, solange die Klasse leer
 * ist. Danach waere ein Feld mit 28 Namen darin nur noch im Weg.
 */
ok('Bei gefuellter Klasse ist das grosse Textfeld weg',
   !str_contains($res['body'], 'name="names"'));

$leereKlasse = class_create((int) qv('SELECT school_id FROM users WHERE id = ?', [$lehrerId]),
                            'Leer' . bin2hex(random_bytes(2)));
$res = teacherGet('class.php?id=' . (int) $leereKlasse['id']);
ok('Bei leerer Klasse ist es da', str_contains($res['body'], 'name="names"'));
q('DELETE FROM classes WHERE id = ?', [(int) $leereKlasse['id']]);

$skriptK = http($base . '/teacher/teacher.js');
ok('Das Skript kennt den Enter-Weg',
   str_contains($skriptK['body'], 'initStudentAdd')
   && str_contains($skriptK['body'], "e.key === 'Enter'"));
ok('Und setzt die Werte als Text, nicht als Markup',
   str_contains($skriptK['body'], 'textContent = kind.name'),
   'sonst zerlegt ein Name mit spitzer Klammer die Tabelle');
ok('Die frische Zeile traegt gleich ihre Knoepfe',
   str_contains($skriptK['body'], 'reset_password')
   && str_contains($skriptK['body'], 'printUser'),
   'sonst fehlen Passwort und Zettel bis zum naechsten Laden');

$res = teacherGet('class.php?id=' . $klasseId);
ok('Und das Formular traegt die Adresse dafuer',
   str_contains($res['body'], 'data-print-user="'));

// ---- Ein Lauf ohne Arbeit ist kein Fehlschlag.

/*
 * Der Fall, an dem es gehakt hat: Eine Lehrkraft liest eine Unit ein,
 * freigegeben ist noch nichts - also gibt es keine Vokabel, fuer die ein
 * Satz entstehen koennte. Die Einheit trug danach "Es entstand kein
 * brauchbarer Satz". Das war gelogen und liess eine frisch eingelesene
 * Lektion kaputt aussehen.
 */
$leerLang = makeLanguage($lehrerId, 'Leerlaufisch');
$leerUnit = makeUnit($lehrerId, $leerLang, 'Nichts freigegeben');
q('UPDATE units SET released_position = 0 WHERE id = ?', [$leerUnit]);
foreach (['alpha', 'beta'] as $i => $w) {
    q('INSERT INTO vocab (unit_id, term_foreign, term_native, position) VALUES (?, ?, ?, ?)',
      [$leerUnit, $w, 'de-' . $w, $i]);
}

/*
 * Ohne Freigabe gibt es nichts zu erzeugen: Ein Lueckensatz wird
 * gebraucht, wenn ein Kind ihn ueben soll, und ueben kann es nur, was
 * aufgemacht ist.
 */
ok('Ohne Freigabe gibt es nichts zu erzeugen',
   vocab_without_sentences($leerUnit) === 0,
   vocab_without_sentences($leerUnit) . ' - was zu ist, wird nicht geuebt');

/*
 * Und mit Freigabe schon. Dass ein Lauf ohne Freigabe frueher "Es entstand
 * kein brauchbarer Satz" hinterliess, ist damit erledigt: Er startet gar
 * nicht erst.
 */
q('UPDATE units SET released_position = 2 WHERE id = ?', [$leerUnit]);
ok('Nach der Freigabe schon',
   vocab_without_sentences($leerUnit) === 2,
   vocab_without_sentences($leerUnit) . ' von 2');

sentence_claim($leerUnit);
generate_sentences_tracked($leerUnit);

$stand  = q1('SELECT sentences_status, sentences_error FROM units WHERE id = ?', [$leerUnit]);
$anzahl = (int) qv('SELECT COUNT(*) FROM sentences s
                      JOIN vocab v ON v.id = s.vocab_id
                     WHERE v.unit_id = ?', [$leerUnit]);
ok('Und danach sind sie da', $anzahl > 0, $anzahl . ' Saetze');
ok('Die Einheit gilt als fertig',
   $stand['sentences_status'] === SENTENCE_DONE, (string) $stand['sentences_status']);
ok('Ohne Fehlermeldung', $stand['sentences_error'] === null,
   var_export($stand['sentences_error'], true));

// Wieder zumachen: Die Pruefungen darunter beschreiben eine Einheit ohne
// Freigabe, und die Saetze von eben bleiben dabei liegen.
q('UPDATE units SET released_position = 0 WHERE id = ?', [$leerUnit]);

/*
 * Und der Fall, an dem es gehakt hatte: ein Lauf, fuer den es nichts mehr
 * zu tun gibt. "Nichts zu tun" ist kein Fehlschlag - frueher stand danach
 * "Es entstand kein brauchbarer Satz" an einer Einheit, die vollstaendig
 * war.
 */
sentence_claim($leerUnit);
generate_sentences_tracked($leerUnit);
$stand = q1('SELECT sentences_status, sentences_error FROM units WHERE id = ?', [$leerUnit]);
ok('Ein zweiter Lauf ohne Arbeit meldet keinen Fehlschlag',
   $stand['sentences_status'] !== SENTENCE_FAILED,
   (string) $stand['sentences_status'] . ' / ' . var_export($stand['sentences_error'], true));
ok('Und hinterlaesst keine Fehlermeldung',
   $stand['sentences_error'] === null, var_export($stand['sentences_error'], true));

// Die Kinder sehen davon trotzdem nur das Freigegebene.
$kindRow2 = q1('SELECT * FROM users WHERE id = ?', [$userId]);
$unitRow2 = q1('SELECT * FROM units WHERE id = ?', [$leerUnit]);
ok('Gesehen wird davon nur das Freigegebene',
   visible_position($kindRow2, $unitRow2) === 0,
   (string) visible_position($kindRow2, $unitRow2));
[$clozeKnown, $clozeTotal] = cloze_progress($leerUnit, $userId);
ok('Und im Lueckentext kommt ohne Freigabe nichts an',
   $clozeTotal === 0, $clozeTotal . ' Saetze sichtbar');

q('UPDATE units SET released_position = 2 WHERE id = ?', [$leerUnit]);
[$clozeKnown, $clozeTotal] = cloze_progress($leerUnit, $userId);
ok('Nach der Freigabe schon', $clozeTotal === 2, $clozeTotal . ' von 2');

/*
 * Die Oberflaeche muss den laufenden Zustand zeigen koennen: Spinner statt
 * Symbol, Zeile nicht anklickbar, und erst danach wieder klickbar.
 */
$unitQuelle = (string) file_get_contents(__DIR__ . '/../views/unit.js');
ok('Waehrend der Erzeugung dreht sich ein Spinner',
   str_contains($unitQuelle, "info.status === 'running'")
   && str_contains($unitQuelle, 'spinner inline'));
ok('Und die Zeile ist solange nicht anklickbar',
   preg_match('/const zu\s*=\s*wartet \|\| fertig;/', $unitQuelle) === 1
   && preg_match('/\$\{zu \? .disabled./', $unitQuelle) === 1);
// Eine geschaffte Uebung ebenso - aber gruen statt grau, und mit ihrem Symbol.
ok('Eine geschaffte Uebung ist ebenfalls nicht anklickbar',
   str_contains($unitQuelle, "fertig ? ' geschafft' : ''"));
ok('Und setzt beim Druck nicht mehr still den Lernstand zurueck',
   !str_contains($unitQuelle, 'zuruecksetzen(unitId, mode)'));
/*
 * Nachgefragt wird jetzt ueber den Vorrat, nicht ueber einen eigenen
 * Endpunkt: Ein Aufruf holt beides auf einmal - ob die Saetze fertig sind
 * UND die Saetze selbst. Vorher sagte sentence_status nur Bescheid, und das
 * Kind wartete danach noch einmal auf die naechste Runde.
 */
ok('Danach fragt die App nach, bis es fertig ist',
   str_contains($unitQuelle, 'vorratAuffrischen()') && str_contains($unitQuelle, 'setTimeout(tick'));

q('DELETE FROM languages WHERE id = ?', [$leerLang]);
q('DELETE FROM ai_requests WHERE user_id IS NULL');

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

/*
 * Die Adresse auf dem Zettel muss eine Adresse sein.
 *
 * Hier stand url('/') - und das liefert einen Pfad, "/vokabeltrainer/".
 * Auf dem Papier ist das wertlos: Ein QR-Code mit einem Pfad darin ist kein
 * Link, sondern eine Zeichenkette, und ein Kind, das "/vokabeltrainer/" in
 * die Adresszeile tippt, landet nirgends. Aufgefallen ist es nur, weil ich
 * den erzeugten Code einmal wirklich ausgelesen habe.
 */
require_once __DIR__ . '/../lib/qr.php';

ok('Die oeffentliche Adresse traegt Schema und Host',
   preg_match('~^https?://[^/]+~', public_url('/')) === 1, public_url('/'));

$res = teacherGet('print.php?class=' . $klasseId);
ok('Die Druckansicht öffnet sich', $res['status'] === 200, 'Status ' . $res['status']);

// Den QR-Code aus der Seite holen und zurueckrechnen - steht dort wirklich
// eine aufrufbare Adresse?
preg_match('~<path d="([^"]+)"~', $res['body'], $pm);
preg_match('~viewBox="0 0 (\d+) ~', $res['body'], $vm);
$qrGelesen = null;
if (($pm[1] ?? '') !== '' && ($vm[1] ?? '') !== '') {
    $seite  = (int) $vm[1] - 8;          // ohne den hellen Rand von je 4
    $felder = array_fill(0, $seite, array_fill(0, $seite, false));
    preg_match_all('~M(\d+) (\d+)h~', $pm[1], $mm, PREG_SET_ORDER);
    foreach ($mm as $t) {
        $c = (int) $t[1] - 4;
        $r = (int) $t[2] - 4;
        if ($r >= 0 && $r < $seite && $c >= 0 && $c < $seite) {
            $felder[$r][$c] = true;
        }
    }
    $qrGelesen = qrLesen($felder);
}
ok('Im QR-Code auf dem Zettel steht eine aufrufbare Adresse',
   $qrGelesen !== null && preg_match('~^https?://~', $qrGelesen) === 1,
   var_export($qrGelesen, true));
/*
 * Verglichen wird mit der Adresse, unter der dieser Test laeuft - nicht mit
 * public_url() aus diesem Prozess. Hier auf der Kommandozeile gibt es keine
 * Anfrage, aus der sich ein Host ableiten liesse; der Server kennt ihn.
 */
$erwarteteAdresse = $base . '/';
ok('Und zwar die der App',
   $qrGelesen === $erwarteteAdresse,
   var_export($qrGelesen, true) . ' statt ' . $erwarteteAdresse);
ok('Dieselbe Adresse steht auch zum Abtippen darauf',
   str_contains($res['body'], h($erwarteteAdresse)));
// Gezaehlt wird gegen die Klasse, nicht gegen eine feste Zahl - wer hier
// ein Kind ergaenzt, soll nicht diesen Abschnitt rot faerben.
$erwarteteBlaetter = count(class_members_list($klasseId));
ok('Sie zeigt ein Blatt je Kind',
   substr_count($res['body'], 'class="blatt"') === $erwarteteBlaetter,
   substr_count($res['body'], 'class="blatt"') . ' statt ' . $erwarteteBlaetter);
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

/*
 * Aufraeumen: erst die Kinder, dann die Klasse.
 *
 * Gefragt wird nach dem tatsaechlichen Stand, nicht nach der Liste von
 * vorhin - wer weiter oben ein Kind ergaenzt, haette es sonst als Waise
 * hinterlassen, und der naechste Lauf faende einen belegten Benutzernamen
 * vor.
 */
foreach (class_members_list($klasseId) as $k) {
    q('DELETE FROM users WHERE id = ?', [(int) $k['id']]);
}
q('DELETE FROM classes WHERE id = ?', [$klasseId]);

section('Weitere Seiten in dieselbe Lerneinheit');

/*
 * Bis hierher legte jedes Einlesen eine NEUE Lerneinheit an - die Route
 * trug nur eine language_id, die Nutzlast kein unit_id. Wer eine zweite
 * Buchseite derselben Lektion fotografierte, bekam "Unit 4" und "Unit 4
 * (2)" und musste beide einzeln freigeben.
 */

/*
 * Der Abschnitt steht spaet in der Suite - bis hierher hat sie sich mehrfach
 * an- und abgemeldet, und ein frueherer Abschnitt hat dem Testkind das
 * Einlese-Recht abgenommen, um zu pruefen, dass es dann nicht mehr geht.
 * Also beides wiederherstellen, statt sich auf einen Zustand zu verlassen,
 * den ein anderer Abschnitt hinterlassen hat.
 */
q('UPDATE users SET can_import = 1 WHERE id = ?', [$userId]);
apiCall('auth', 'login', ['username' => $username, 'password' => 'geheim123']);

$anKlasse = class_create(
    (int) qv('SELECT school_id FROM users WHERE id = ?', [$userId]),
    'Anhang' . bin2hex(random_bytes(2)),
);
$anLang = makeLanguage($userId, 'Anhaengisch' . bin2hex(random_bytes(2)));

// Eine Einheit mit drei Vokabeln, zwei davon freigegeben.
[$d, $st] = apiCall('import', 'save', [
    'language_id' => $anLang,
    'title'       => 'Anhang-Unit',
    'entries'     => [
        ['foreign' => 'red',   'native' => 'rot'],
        ['foreign' => 'blue',  'native' => 'blau'],
        ['foreign' => 'green', 'native' => 'gruen'],
    ],
]);
ok('Eine Lerneinheit entsteht wie bisher', ($d['ok'] ?? false) === true,
   $d['error'] ?? "Status $st");
$anUnit = (int) ($d['unit_id'] ?? 0);
q('UPDATE units SET released_position = 2 WHERE id = ?', [$anUnit]);

$woerter = static fn (int $u): array => array_column(qa(
    'SELECT term_foreign FROM vocab WHERE unit_id = ? ORDER BY position', [$u],
), 'term_foreign');

ok('Mit drei Vokabeln', count($woerter($anUnit)) === 3, implode(',', $woerter($anUnit)));

// ---- Anhaengen statt neu anlegen.

$unitsVorher = (int) qv('SELECT COUNT(*) FROM units WHERE language_id = ?', [$anLang]);

[$d, $st] = apiCall('import', 'save', [
    'language_id' => $anLang,
    'unit_id'     => $anUnit,
    'entries'     => [
        ['foreign' => 'yellow', 'native' => 'gelb'],
        ['foreign' => 'black',  'native' => 'schwarz'],
    ],
]);
ok('Mit unit_id wird angehaengt', ($d['ok'] ?? false) === true, $d['error'] ?? "Status $st");
ok('Und zwar in dieselbe Einheit', (int) ($d['unit_id'] ?? 0) === $anUnit);
ok('Es entsteht keine zweite Lerneinheit',
   (int) qv('SELECT COUNT(*) FROM units WHERE language_id = ?', [$anLang]) === $unitsVorher,
   'genau das war der Grund fuer "Unit 4" und "Unit 4 (2)"');
ok('Die neuen Vokabeln stehen hinten dran',
   $woerter($anUnit) === ['red', 'blue', 'green', 'yellow', 'black'],
   implode(',', $woerter($anUnit)));
ok('Ohne Titel geht es dabei auch', true);

// ---- Und die Freigabe bleibt, wo sie war.

ok('Die Freigabemarke ruehrt sich nicht',
   (int) qv('SELECT released_position FROM units WHERE id = ?', [$anUnit]) === 2,
   (string) qv('SELECT released_position FROM units WHERE id = ?', [$anUnit]));
ok('Die Klasse sieht die angehaengten Woerter also noch nicht',
   array_column(qa('SELECT v.term_foreign FROM vocab v JOIN units u ON u.id = v.unit_id
                     WHERE v.unit_id = ? AND v.position < u.released_position
                     ORDER BY v.position', [$anUnit]), 'term_foreign')
   === ['red', 'blue']);
ok('Die Positionen sind lueckenlos', vocab_positions_dense($anUnit));

// ---- Und die Lueckensaetze warten, bis die Woerter aufgemacht werden.

/*
 * Ein Lueckensatz wird gebraucht, wenn ein Kind ihn ueben soll - und ueben
 * kann es nur, was freigegeben ist. Die angehaengten Woerter stehen hinter
 * der Marke; ihr Satz wartet mit. Das spart nicht nur einen Aufruf: Es
 * entsteht auch nichts fuer Woerter, die vielleicht nie drankommen.
 */
$satzZu = static fn (string $wort): int => (int) qv(
    'SELECT COUNT(*) FROM sentences s JOIN vocab v ON v.id = s.vocab_id
      WHERE v.unit_id = ? AND v.term_foreign = ?', [$anUnit, $wort]);

if ($isFake) {
    $anStand = waitForSentences($anUnit);
    ok('Der Satzlauf fuer die angehaengten Vokabeln wird fertig',
       $anStand === 'done', $anStand);
    ok('Solange sie zu sind, entsteht kein Satz', $satzZu('yellow') === 0,
       $satzZu('yellow') . ' - was nicht geuebt wird, braucht keinen');

    // Und jetzt aufmachen.
    q('UPDATE units SET released_position = 5 WHERE id = ?', [$anUnit]);
    sentence_claim($anUnit);
    generate_sentences_tracked($anUnit);

    ok('Nach dem Freigeben gibt es ihn',
       $satzZu('yellow') > 0,
       'sonst bleibt die Vokabel im Lueckentext stumm');
    ok('Und die vorher schon vorhandenen behalten ihre',
       (int) qv('SELECT COUNT(*) FROM sentences s JOIN vocab v ON v.id = s.vocab_id
                  WHERE v.unit_id = ? AND v.term_foreign = ?', [$anUnit, 'red']) > 0);
}

// ---- Ohne Titel und ohne unit_id geht nichts.

[$d, $st] = apiCall('import', 'save', [
    'language_id' => $anLang,
    'entries'     => [['foreign' => 'grey', 'native' => 'grau']],
]);
ok('Eine neue Einheit ohne Titel wird abgelehnt', ($d['ok'] ?? true) === false,
   "Status $st");

// ---- Die Grenze: eine fremde Lerneinheit.

$fremdeLang = makeLanguage($otherId, 'Fremdanhang' . bin2hex(random_bytes(2)));
$fremdeUnit = makeUnit($otherId, $fremdeLang, 'Fremde Anhang-Unit');
$vorherFremd = (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ?', [$fremdeUnit]);

[$d, $st] = apiCall('import', 'save', [
    'language_id' => $anLang,
    'unit_id'     => $fremdeUnit,
    'entries'     => [['foreign' => 'stolen', 'native' => 'geklaut']],
]);
ok('In eine fremde Lerneinheit laesst sich nichts schreiben',
   ($d['ok'] ?? true) === false, "Status $st");
ok('Und es kommt dort auch nichts an',
   (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ?', [$fremdeUnit]) === $vorherFremd);

/*
 * Auch die eigene Einheit laesst sich nicht unter falscher Sprache fuellen.
 * Sonst reichte eine Sprache, an der man Rechte hat, um in jede andere
 * eigene Einheit zu schreiben - und die Sprache bestimmt, wie
 * punctuation_fix() arbeitet.
 */
$zweiteLang = makeLanguage($userId, 'Zweitanhang' . bin2hex(random_bytes(2)));
[$d, $st] = apiCall('import', 'save', [
    'language_id' => $zweiteLang,
    'unit_id'     => $anUnit,
    'entries'     => [['foreign' => 'wrong', 'native' => 'falsch']],
]);
ok('Eine Einheit einer anderen Sprache wird abgelehnt',
   ($d['ok'] ?? true) === false, "Status $st");
ok('Und bleibt unveraendert', count($woerter($anUnit)) === 5,
   implode(',', $woerter($anUnit)));

// ---- Die Oberflaeche bietet die Wahl an.

$skriptA = http($base . '/views/import.js');
ok('Die Einleseansicht laesst die Wahl',
   str_contains($skriptA['body'], 'function zielWahl')
   && str_contains($skriptA['body'], "id=\"ziel\""));
ok('Vorbelegt ist "Neue Lerneinheit"',
   str_contains($skriptA['body'], '<option value="">Neue Lerneinheit</option>'));
ok('Und sie schickt unit_id mit',
   str_contains($skriptA['body'], 'body.unit_id = anId'));
ok('Beim Anhaengen verschwindet das Titelfeld',
   str_contains($skriptA['body'], "feld.hidden = anhaengen"),
   'sonst tippt jemand einen Titel, der nirgends landet');

q('DELETE FROM languages WHERE id IN (?, ?, ?)', [$anLang, $fremdeLang, $zweiteLang]);
q('DELETE FROM classes   WHERE id = ?', [(int) $anKlasse['id']]);

section('Reihenfolge und Freigabe der Vokabeln');

/*
 * vocab.position ist zweierlei zugleich: Reihenfolge UND Freigabezeiger.
 * Elf Abfragen vergleichen v.position < u.released_position, und „Alles
 * freigeben" setzt die Marke auf COUNT(*). Das traegt nur, solange die
 * Positionen luecklos 0..n-1 sind - und genau das war vor diesem Abschnitt
 * nicht garantiert.
 */
$posKlasse = class_create(
    (int) qv('SELECT school_id FROM users WHERE id = ?', [$lehrerId]),
    'Pos' . bin2hex(random_bytes(2)),
);
$posKurs = course_create(
    q1('SELECT * FROM users WHERE id = ?', [$lehrerId]),
    'Positionisch' . bin2hex(random_bytes(2)), "\u{1F310}", (int) $posKlasse['id'], '',
);
$posKursId  = is_string($posKurs) ? 0 : (int) $posKurs['id'];
$posSprache = is_string($posKurs) ? 0 : (int) $posKurs['language_id'];
ok('Ein Kurs fuer die Positionen', $posKursId > 0);

$posUnit = makeUnit($lehrerId, $posSprache, 'Positions-Unit');
foreach (['alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot'] as $i => $w) {
    q('INSERT INTO vocab (unit_id, position, term_foreign, term_native)
       VALUES (?, ?, ?, ?)', [$posUnit, $i, $w, 'de-' . $w]);
}
q('UPDATE units SET released_position = 3 WHERE id = ?', [$posUnit]);

/** Die Woerter, die ein Kind dieser Einheit gerade sieht. */
$sichtbar = static function (int $unitId): array {
    return array_column(qa(
        'SELECT v.term_foreign FROM vocab v JOIN units u ON u.id = v.unit_id
          WHERE v.unit_id = ? AND v.position < u.released_position
          ORDER BY v.position', [$unitId],
    ), 'term_foreign');
};

ok('Zu Beginn sind drei Woerter frei',
   $sichtbar($posUnit) === ['alpha', 'bravo', 'charlie'],
   implode(',', $sichtbar($posUnit)));

// ---- Anfuegen aendert nicht, was freigegeben ist.

$vorher = (int) qv('SELECT released_position FROM units WHERE id = ?', [$posUnit]);
$dazu   = vocab_append($posUnit, [
    ['foreign' => 'golf',  'native' => 'de-golf'],
    ['foreign' => 'hotel', 'native' => 'de-hotel'],
]);
ok('Zwei Vokabeln kommen dazu', $dazu === 2, (string) $dazu);
ok('Die Freigabemarke bleibt, wo sie war',
   (int) qv('SELECT released_position FROM units WHERE id = ?', [$posUnit]) === $vorher);
ok('Und die Klasse sieht weiterhin genau dieselben Woerter',
   $sichtbar($posUnit) === ['alpha', 'bravo', 'charlie'],
   implode(',', $sichtbar($posUnit)));
ok('Die neuen stehen hinten dran',
   (int) qv('SELECT position FROM vocab WHERE unit_id = ? AND term_foreign = ?',
            [$posUnit, 'hotel']) === 7);
ok('Die Positionen sind lueckenlos', vocab_positions_dense($posUnit));

// ---- Loeschen unterhalb der Marke laesst dieselben Woerter frei.

$bravo = (int) qv('SELECT id FROM vocab WHERE unit_id = ? AND term_foreign = ?',
                  [$posUnit, 'bravo']);
vocab_delete($bravo);

ok('Nach dem Loeschen sinkt die Marke mit',
   (int) qv('SELECT released_position FROM units WHERE id = ?', [$posUnit]) === 2,
   (string) qv('SELECT released_position FROM units WHERE id = ?', [$posUnit]));
ok('Die Klasse sieht dieselben Woerter wie vorher, nur ohne das geloeschte',
   $sichtbar($posUnit) === ['alpha', 'charlie'],
   implode(',', $sichtbar($posUnit)));
ok('Und die Positionen sind wieder lueckenlos', vocab_positions_dense($posUnit));

/*
 * Der Kern der Sache: Ohne die Markenkorrektur waere jetzt "delta"
 * freigegeben - ein Wort, das niemand freigegeben hat.
 */
ok('Kein gesperrtes Wort ist nachgerueckt',
   !in_array('delta', $sichtbar($posUnit), true), implode(',', $sichtbar($posUnit)));

// ---- Loeschen oberhalb der Marke laesst sie in Ruhe.

$golf = (int) qv('SELECT id FROM vocab WHERE unit_id = ? AND term_foreign = ?',
                 [$posUnit, 'golf']);
$vorMarke = (int) qv('SELECT released_position FROM units WHERE id = ?', [$posUnit]);
vocab_delete($golf);
ok('Ein gesperrtes Wort zu loeschen ruehrt die Marke nicht an',
   (int) qv('SELECT released_position FROM units WHERE id = ?', [$posUnit]) === $vorMarke);

// ---- "Alles freigeben" erreicht auch das zuletzt Angefuegte.

vocab_append($posUnit, [['foreign' => 'india', 'native' => 'de-india']]);
$gesamt = (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ?', [$posUnit]);
q('UPDATE units SET released_position = ? WHERE id = ?', [$gesamt, $posUnit]);
ok('"Alles freigeben" erreicht wirklich alle - auch die angefuegten',
   count($sichtbar($posUnit)) === $gesamt,
   count($sichtbar($posUnit)) . ' von ' . $gesamt);
ok('Einschliesslich der zuletzt angefuegten',
   in_array('india', $sichtbar($posUnit), true));

// ---- Die Verdichtung raeumt Loecher weg.

q('DELETE FROM vocab WHERE unit_id = ? AND term_foreign = ?', [$posUnit, 'charlie']);
ok('Ein blankes DELETE hinterlaesst ein Loch', !vocab_positions_dense($posUnit),
   'sonst prueft der naechste Schritt nichts');
$bewegt = vocab_compact_positions($posUnit);
ok('Die Verdichtung raeumt es weg', vocab_positions_dense($posUnit));
ok('Und sagt, wie viele Zeilen sich bewegt haben', $bewegt > 0, (string) $bewegt);
ok('Die Reihenfolge bleibt dabei erhalten',
   array_column(qa('SELECT term_foreign FROM vocab WHERE unit_id = ? ORDER BY position',
                   [$posUnit]), 'term_foreign')
   === ['alpha', 'delta', 'echo', 'foxtrot', 'hotel', 'india'],
   implode(',', array_column(qa('SELECT term_foreign FROM vocab WHERE unit_id = ? ORDER BY position',
                                [$posUnit]), 'term_foreign')));

// ---- Der Riegel in der Datenbank.

ok('Es gibt einen eindeutigen Schluessel auf (unit_id, position)',
   index_exists('vocab', 'uq_vocab_pos'));

$dublette = false;
try {
    $erste = (int) qv('SELECT id FROM vocab WHERE unit_id = ? ORDER BY position LIMIT 1',
                      [$posUnit]);
    $zweite = (int) qv('SELECT id FROM vocab WHERE unit_id = ? ORDER BY position LIMIT 1 OFFSET 1',
                       [$posUnit]);
    q('UPDATE vocab SET position = 0 WHERE id = ?', [$zweite]);
    $dublette = true;
} catch (Throwable $e) {
    // genau so soll es sein
}
ok('Zwei Vokabeln koennen sich keine Position teilen', !$dublette,
   'sonst teilen sie sich einen Freigabeschritt');

// ---- Und dass das ueberhaupt auffaellt.

ok('Der Selbsttest kennt die Frage',
   str_contains((string) file_get_contents(__DIR__ . '/../admin/selfcheck.php'),
                'Reihenfolge der Vokabeln'));
/*
 * Hier stand die Reihenfolge zweier Schemaaenderungen: erst die Positionen
 * geradeziehen, dann den Schluessel darauf festnageln. Beide sind weg -
 * schema.sql bringt den Schluessel von Anfang an mit, und es gibt keine
 * Datenbank mehr, deren Positionen Luecken haetten.
 */
ok('schema.sql kennt den Schluessel von Anfang an',
   str_contains((string) file_get_contents(__DIR__ . '/../schema.sql'), 'uq_vocab_pos'),
   'ohne ihn koennten sich zwei Vokabeln eine Position teilen');
ok('Und er steht auch wirklich auf der Tabelle',
   index_exists('vocab', 'uq_vocab_pos'));

// ---- punctuation_fix greift auch von Hand.

$posUnit2 = makeUnit($lehrerId, $posSprache, 'Abstands-Unit');
vocab_append($posUnit2, [['foreign' => 'Ça va ?', 'native' => 'Wie geht es ?']], 'fr');
$paar = q1('SELECT term_foreign, term_native FROM vocab WHERE unit_id = ?', [$posUnit2]);
ok('Im Deutschen faellt der Abstand vor dem Fragezeichen weg',
   ($paar['term_native'] ?? '') === 'Wie geht es?', (string) ($paar['term_native'] ?? ''));
ok('Im Franzoesischen bleibt er',
   str_contains((string) ($paar['term_foreign'] ?? ''), ' ?')
   || str_contains((string) ($paar['term_foreign'] ?? ''), "\u{202F}?"),
   (string) ($paar['term_foreign'] ?? ''));

/*
 * Vollstaendig wegraeumen, nicht nur die Klasse.
 *
 * Eine Klasse zu loeschen nimmt den Kurs nicht mit, und am Kurs haengen
 * Sprache, Lerneinheiten und Vokabeln. Der Abschnitt "Kategorien
 * nachtragen" zaehlt spaeter ALLE Vokabeln ohne Wortart - liegen hier
 * welche herum, faellt er um, und zwar an einer Stelle, die mit diesem
 * Abschnitt nichts zu tun hat.
 */
q('DELETE FROM languages WHERE id = ?', [$posSprache]);
q('DELETE FROM courses   WHERE id = ?', [$posKursId]);
q('DELETE FROM classes   WHERE id = ?', [(int) $posKlasse['id']]);

section('Meine Kurse als Startseite');

/*
 * Die Startseite war die Klassenliste. Eine Klasse legt man einmal im
 * Schuljahr an, eine Lerneinheit jede Woche - und bis zur Freigabe waren es
 * drei Klicks und vier Seiten. Jetzt liegt der Alltag vorn.
 */

$startSchule = (int) qv('SELECT school_id FROM users WHERE id = ?', [$lehrerId]);

// Eine zweite Lehrkraft derselben Schule - fuer die Gegenprobe, dass jede
// nur ihre eigenen Kurse als "meine" sieht.
$zweiteName = 'lehr2_' . bin2hex(random_bytes(3));
q('INSERT INTO users (school_id, username, display_name, password_hash, color, role, can_import)
   VALUES (?, ?, ?, ?, ?, ?, 1)',
  [$startSchule, $zweiteName, 'Herr Zweit',
   password_hash('lehrerin123', PASSWORD_DEFAULT), '#4f7cff', ROLE_TEACHER]);
$zweiteId = (int) db()->lastInsertId();

$startKlasse = class_create($startSchule, 'Start' . bin2hex(random_bytes(2)));
$startKurs   = course_create(
    q1('SELECT * FROM users WHERE id = ?', [$lehrerId]),
    'Startisch' . bin2hex(random_bytes(2)), "\u{1F310}", (int) $startKlasse['id'], '',
);
$startKursId = is_string($startKurs) ? 0 : (int) $startKurs['id'];
ok('Ein Kurs fuer die Startseite', $startKursId > 0,
   is_string($startKurs) ? $startKurs : '');

$fremdeKlasse = class_create($startSchule, 'Fremd' . bin2hex(random_bytes(2)));
$fremderKurs  = course_create(
    q1('SELECT * FROM users WHERE id = ?', [$zweiteId]),
    'Fremdisch' . bin2hex(random_bytes(2)), "\u{1F310}", (int) $fremdeKlasse['id'], '',
);
$fremderKursId = is_string($fremderKurs) ? 0 : (int) $fremderKurs['id'];

// ---- Die Abfrage, die es vorher nicht gab.

$meine = courses_for_teacher($lehrerId, $startSchule);
$namen = array_column($meine, 'name');
ok('courses_for_teacher liefert den eigenen Kurs',
   in_array($startKurs['name'], $namen, true), implode(', ', $namen));
ok('Und nicht den der Kollegin',
   !in_array($fremderKurs['name'], $namen, true), implode(', ', $namen));

$seine = array_column(courses_for_teacher($zweiteId, $startSchule), 'name');
ok('Umgekehrt genauso',
   in_array($fremderKurs['name'], $seine, true)
   && !in_array($startKurs['name'], $seine, true), implode(', ', $seine));

/*
 * Die Rolle wird wirklich geprueft, nicht nur die Mitgliedschaft.
 *
 * Heute faellt das nicht auf: Eine Lehrkraft ist ueberall, wo sie Mitglied
 * ist, auch als Lehrkraft eingetragen - course_add_member() traegt die
 * Rolle des Kontos ein. Die Mutationsprobe blieb deshalb gruen, als der
 * Rollenfilter entfernt wurde. Eine Zeile mit der Rolle "student" laesst
 * sich aber jederzeit anders erzeugen, etwa durch eine Ueberfuehrung, und
 * dann stuende ein fremder Kurs unter "Meine Kurse". Also: von Hand
 * eintragen und nachsehen.
 */
q("INSERT INTO course_members (course_id, user_id, member_role) VALUES (?, ?, 'student')",
  [$fremderKursId, $lehrerId]);
$mitSchuelerzeile = array_column(courses_for_teacher($lehrerId, $startSchule), 'name');
ok('Eine Mitgliedschaft als SchuelerIn macht den Kurs nicht zu meinem',
   !in_array($fremderKurs['name'], $mitSchuelerzeile, true),
   implode(', ', $mitSchuelerzeile));
q('DELETE FROM course_members WHERE course_id = ? AND user_id = ?',
  [$fremderKursId, $lehrerId]);

/*
 * Die neueste Lerneinheit reist mit - sie ist das Ziel des Freigeben-Knopfes
 * auf der Karte. Ohne sie muesste die Karte je Kurs nachfragen.
 */
$eigene = null;
foreach ($meine as $k) {
    if ((int) $k['id'] === $startKursId) { $eigene = $k; }
}
ok('Ohne Lerneinheit ist die neueste leer',
   $eigene !== null && $eigene['latest_unit'] === null);

$startUnit = makeUnit($lehrerId, (int) $startKurs['language_id'], 'Start-Unit');
$meine = courses_for_teacher($lehrerId, $startSchule);
foreach ($meine as $k) {
    if ((int) $k['id'] === $startKursId) { $eigene = $k; }
}
ok('Mit einer ist sie die neueste',
   (int) ($eigene['latest_unit'] ?? 0) === $startUnit,
   (string) ($eigene['latest_unit'] ?? 'null'));

// ---- Die Seite.

$res = teacherGet('index.php');
ok('Die Startseite ist keine Weiterleitung mehr', $res['status'] === 200);
ok('Sie heisst "Meine Kurse"', str_contains($res['body'], '<h1>Meine Kurse</h1>'));
ok('Und zeigt Karten statt einer Tabelle',
   str_contains($res['body'], 'class="kurskarten"')
   && substr_count($res['body'], 'kurskarte') >= 1);
ok('Der eigene Kurs steht darauf', str_contains($res['body'], h($startKurs['name'])));

ok('Die Karte fuehrt mit einem Klick in die Freigabe',
   str_contains($res['body'], 'unit.php?id=' . $startUnit),
   'vorher waren es drei Klicks und vier Seiten');
/*
 * "+ Lerneinheit" fuehrt nicht mehr in die App.
 *
 * Hier stand ein Link in die Einleseansicht - in einem neuen Tab, weil man
 * von dort nicht zurueckfand. Seit auf der Lerneinheitsseite alle drei
 * Wege stehen, legt der Knopf eine leere Einheit an und fuehrt auf ihre
 * Seite: derselbe Weg wie in der Lerneinheitentabelle des Kurses.
 */
ok('Und "+ Lerneinheit" legt eine an, statt in die App zu fuehren',
   preg_match('/<form method="post" action="[^"]*course\.php">.*?'
              . 'name="course_id" value="' . (int) $startKurs['id'] . '".*?'
              . 'name="add_unit"/s', $res['body']) === 1,
   'vorher ein Link auf #/lang/N/import');
ok('Und oeffnet dafuer keinen zweiten Tab',
   !str_contains($res['body'], 'target="_blank"'),
   'ein Knopf, der die Seite verlaesst, ist kein Knopf');

$vorherEinheiten = (int) qv('SELECT COUNT(*) FROM units WHERE course_id = ?',
                            [(int) $startKurs['id']]);
$angelegt = teacherRequest($base . '/teacher/course.php', [
    'add_unit' => '1', 'course_id' => (int) $startKurs['id'], 'csrf' => $lehrerCsrf,
]);
ok('Der Knopf legt wirklich eine an',
   (int) qv('SELECT COUNT(*) FROM units WHERE course_id = ?',
            [(int) $startKurs['id']]) === $vorherEinheiten + 1);
ok('Und man steht danach auf ihrer Seite',
   str_contains($angelegt['body'], 'Unbenannte Lerneinheit')
   && str_contains($angelegt['body'], 'Vokabeln zur Lerneinheit hinzufügen'),
   'dort stehen die drei Wege, sie zu fuellen');
q('DELETE FROM units WHERE course_id = ? AND title = ?',
  [(int) $startKurs['id'], 'Unbenannte Lerneinheit']);

ok('Die Kurse der Kolleginnen stehen zugeklappt darunter',
   str_contains($res['body'], 'class="kursealle"')
   && str_contains($res['body'], h($fremderKurs['name'])),
   'eine Vertretung muss an die Unterlagen kommen');
ok('Die Verwaltung steht am Fuss',
   str_contains($res['body'], 'class="card verwaltung"')
   && str_contains($res['body'], 'classes.php'));
ok('Und als Karte mit einem Knopf, nicht als Fussnote',
   preg_match('/<div class="card verwaltung">.*?<a class="btn small secondary" '
              . 'href="[^"]*classes\.php"/s', $res['body']) === 1,
   'ein unterstrichenes Wort mitten in einem grauen Satz ist kein Weg');

/*
 * Ohne Lerneinheit ist "Freigeben" abgeblendet statt abwesend - eine Karte,
 * die je nach Datenlage anders aussieht, laesst einen suchen.
 */
$leererKurs = course_create(
    q1('SELECT * FROM users WHERE id = ?', [$lehrerId]),
    'Leerisch' . bin2hex(random_bytes(2)), "\u{1F310}", (int) $startKlasse['id'], '',
);
$res = teacherGet('index.php');
ok('Ohne Lerneinheit ist der Freigeben-Knopf abgeblendet',
   preg_match('/<span class="btn small secondary aus"[^>]*>Freigeben<\/span>/', $res['body']) === 1,
   'er soll dastehen, nicht fehlen');

// ---- Der Kurswechsler steht im Menue links.

/*
 * Wer Englisch in der 5a und Franzoesisch in der 7b gibt, wechselt
 * dauernd. Das konnte der Kurskrumen im Pfad; seit der Pfad weg ist,
 * stehen die eigenen Kurse eingerueckt unter "Meine Kurse" - von jeder
 * Seite aus derselbe Griff.
 */
$res = teacherGet('course.php?id=' . $startKursId);
ok('Das Menue nennt die eigenen Kurse',
   str_contains($res['body'], h((string) $leererKurs['name']))
   && str_contains($res['body'], h((string) $startKurs['name'])),
   'sonst fuehrt der Wechsel wieder ueber die Startseite');
ok('Und den Weg zu allen Kursen der Schule',
   str_contains($res['body'], 'Alle Kurse der Schule'));
ok('Der aktuelle Kurs ist darin markiert',
   str_contains($res['body'], 'class="mitem on"')
   && str_contains($res['body'], 'aria-current="page"'));

$res = teacherGet('unit.php?id=' . $startUnit);
ok('In der Lerneinheit steht dasselbe Menue',
   str_contains($res['body'], 'id="menuLinks"')
   && str_contains($res['body'], h((string) $leererKurs['name'])));

/*
 * Ein Menue mit einem Eintrag ist ein Menue zu viel: Wer nur einen Kurs
 * hat, bekommt einen gewoehnlichen Krumen.
 */
$einzelName = 'lehr3_' . bin2hex(random_bytes(3));
q('INSERT INTO users (school_id, username, display_name, password_hash, color, role, can_import)
   VALUES (?, ?, ?, ?, ?, ?, 1)',
  [$startSchule, $einzelName, 'Frau Einzel',
   password_hash('lehrerin123', PASSWORD_DEFAULT), '#4f7cff', ROLE_TEACHER]);
$einzelId   = (int) db()->lastInsertId();
$einzelKurs = course_create(
    q1('SELECT * FROM users WHERE id = ?', [$einzelId]),
    'Einzelisch' . bin2hex(random_bytes(2)), "\u{1F310}", (int) $startKlasse['id'], '',
);

$einzelJar = tempnam(sys_get_temp_dir(), 'vteinzel');
$einzelSeite = (function () use ($base, $einzelJar, $einzelName, $einzelKurs): string {
    $hole = static function (string $url, ?array $post) use ($einzelJar): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $einzelJar,
            CURLOPT_COOKIEFILE     => $einzelJar,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $body = (string) curl_exec($ch);
        curl_close($ch);
        return $body;
    };
    $seite = $hole($base . '/teacher/', null);
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $seite, $m);
    $hole($base . '/teacher/index.php', [
        'teacher_login' => '1', 'username' => $einzelName,
        'password' => 'lehrerin123', 'csrf' => $m[1] ?? '',
    ]);
    return $hole($base . '/teacher/course.php?id=' . (int) $einzelKurs['id'], null);
})();

ok('Mit nur einem eigenen Kurs gibt es kein Menue',
   !str_contains($einzelSeite, 'crumbmenu'),
   'ein Menue mit einem Eintrag ist eines zu viel');
@unlink($einzelJar);

// Ebenso hier: erst die Sprachen (sie ziehen Kurse, Einheiten und Vokabeln
// mit), dann die Konten und Klassen.
foreach ([$startKurs, $fremderKurs, $leererKurs, $einzelKurs] as $k) {
    if (!is_string($k)) {
        q('DELETE FROM languages WHERE id = ?', [(int) $k['language_id']]);
        q('DELETE FROM courses   WHERE id = ?', [(int) $k['id']]);
    }
}
q('DELETE FROM users   WHERE id IN (?, ?)', [$zweiteId, $einzelId]);
q('DELETE FROM classes WHERE id IN (?, ?)',
  [(int) $startKlasse['id'], (int) $fremdeKlasse['id']]);

section('Nach der Anmeldung: die Lehrkraft in die Verwaltung');

/*
 * Die Anmeldung der App kannte nur ein Ziel: die Kachelansicht. Eine
 * Lehrkraft landete damit in der Ansicht ihrer Klasse und musste sich von
 * dort erst in die Verwaltung durchklicken - obwohl sie genau dafuer kommt.
 *
 * Geprueft wird beides, denn beides haengt an derselben Antwort: dass die
 * Lehrkraft in den Lehrkraft-Bereich geschickt wird und dass das Kind
 * weiterhin in die App geht - mit Geraete-Token, sonst gibt es kein "Zum
 * Home-Bildschirm".
 */

$naJar    = tempnam(sys_get_temp_dir(), 'vtna');
$naVorher = (int) qv('SELECT COUNT(*) FROM device_tokens WHERE user_id = ?', [$lehrerId]);

$naLehrer = apiAls($naJar, static function () use ($lehrerName): array {
    [$d] = apiCall('auth', 'login',
                   ['username' => $lehrerName, 'password' => 'lehrerin123']);
    return $d ?? [];
});

ok('Die Lehrkraft meldet sich in der App an',
   ($naLehrer['ok'] ?? false) === true, $naLehrer['error'] ?? '');
ok('Und wird als Lehrkraft erkannt',
   ($naLehrer['user']['isTeacher'] ?? false) === true);
ok('Das Ziel ist die Verwaltung',
   (string) ($naLehrer['redirect'] ?? '') === url('/teacher/'),
   (string) ($naLehrer['redirect'] ?? '(fehlt)'));

/*
 * Ein Geraete-Token entsteht dabei keiner. Er ist der Schluessel fuer die
 * installierte App; wer in die Verwaltung geht, braucht ihn nicht, und
 * ungenutzte Tokens sammeln sich sonst bei jeder Anmeldung an.
 */
ok('Und es entsteht kein Geraete-Token dafuer',
   (int) qv('SELECT COUNT(*) FROM device_tokens WHERE user_id = ?', [$lehrerId]) === $naVorher,
   (string) qv('SELECT COUNT(*) FROM device_tokens WHERE user_id = ?', [$lehrerId])
   . ' statt ' . $naVorher);

// Und das Ziel traegt wirklich die Verwaltung, nicht nur den Pfad dorthin.
$naSeite = apiAls($naJar, static function (): array {
    global $base;
    return http($base . '/teacher/');
});
ok('Dort steht die Startseite der Verwaltung',
   str_contains($naSeite['body'], '<h1>Meine Kurse</h1>'),
   'Status ' . $naSeite['status']);

/*
 * Die Kinderansicht bleibt der Lehrkraft offen - sie ist der Weg zu "So
 * sieht es die Klasse". Weitergeleitet wird nach der Anmeldung, nicht bei
 * jedem Aufruf der App.
 */
$naApp = apiAls($naJar, static function (): array {
    global $base;
    return http($base . '/');
});
ok('Die App selbst bleibt fuer sie erreichbar',
   $naApp['status'] === 200 && str_contains($naApp['body'], 'window.VT'),
   'Status ' . $naApp['status']);

// ---- Die Gegenprobe: ein Kind geht weiterhin in die App.

$naKind = apiAls($naJar, static function () use ($username): array {
    [$d] = apiCall('auth', 'login',
                   ['username' => $username, 'password' => 'geheim123']);
    return $d ?? [];
});
ok('Ein Kind landet weiterhin in der App',
   str_contains((string) ($naKind['redirect'] ?? ''), '/?t='),
   (string) ($naKind['redirect'] ?? '(fehlt)'));
ok('Und bekommt seinen Geraete-Token',
   (int) qv('SELECT COUNT(*) FROM device_tokens WHERE user_id = ?', [$userId]) > 0);

@unlink($naJar);

section('Klasse als Mittelpunkt');

/*
 * Der Umbau: Die Kursliste als eigene Seite ist weg, alles steht in der
 * Klasse. Geprueft wird die Form der Seiten - was das Skript daraus macht
 * (Zeilenklick, Sofort-Zettel, QR-Fenster), laesst sich von hier aus nicht
 * ausfuehren; dafuer gibt es die Zusicherungen am Quelltext weiter unten.
 */
$umbauKlasse = class_create(
    (int) qv('SELECT school_id FROM users WHERE id = ?', [$lehrerId]),
    'Umbau' . bin2hex(random_bytes(2)),
);
$umbauKlasseId = (int) ($umbauKlasse['id'] ?? 0);
ok('Eine Klasse fuer den Umbau steht bereit', $umbauKlasseId > 0);

$res = teacherGet('class.php?id=' . $umbauKlasseId);

// ---- Die Kurse stehen oben, die Kinder darunter.

$posKurse  = strpos($res['body'], 'Kurse dieser Klasse');
$posKinder = strpos($res['body'], 'Kinder dieser Klasse');
ok('Die Kurse stehen ueber den Kindern',
   $posKurse !== false && $posKinder !== false && $posKurse < $posKinder,
   "Kurse $posKurse, Kinder $posKinder");

ok('Die Kurstabelle hat eine Anlegezeile',
   preg_match('/<tr class="newrow">.*?neu\.php\?klasse=' . $umbauKlasseId . '/s',
              $res['body']) === 1,
   'sie legt nicht mehr selbst an, sondern fuehrt in den Assistenten');
ok('Und die Kindertabelle auch',
   str_contains($res['body'], 'id="neuesKind"'));

// ---- Der Zettel steht immer da, abgeblendet solange die Klasse leer ist.

ok('Der Klassenzettel steht auch bei leerer Klasse schon da',
   str_contains($res['body'], 'id="zettelAlle"'),
   'er tauchte frueher erst nach dem Neuladen auf');
ok('Bei leerer Klasse abgeblendet',
   preg_match('/id="zettelAlle"[^>]*class="[^"]*\baus\b/', $res['body']) === 1
   || preg_match('/class="[^"]*\baus\b[^"]*"[^>]*id="zettelAlle"/', $res['body']) === 1,
   'sonst fuehrt er auf ein leeres Blatt');

teacherRequest($base . '/teacher/class.php?id=' . $umbauKlasseId, [
    'add_student' => '1', 'class_id' => $umbauKlasseId,
    'student'     => 'Mira Talberg', 'csrf' => $lehrerCsrf,
]);
$res = teacherGet('class.php?id=' . $umbauKlasseId);
ok('Mit einem Kind darin ist er frei',
   str_contains($res['body'], 'id="zettelAlle"')
   && !str_contains($res['body'], 'secondary aus')
   && !str_contains($res['body'], 'aria-disabled'),
   'die Sperre haengt noch dran');

$skriptU = http($base . '/teacher/teacher.js');
ok('Und das Skript nimmt die Sperre schon beim ersten Kind weg',
   str_contains($skriptU['body'], 'zettelFreigeben')
   && str_contains($skriptU['body'], "getElementById('zettelAlle')"),
   'sonst erscheint er erst beim naechsten Laden');

// ---- Zeilen oeffnen, statt einen Knopf dafuer zu tragen.

ok('Das Skript macht Zeilen anklickbar',
   str_contains($skriptU['body'], "closest('tr[data-href]')"));
ok('Laesst aber Knoepfe und Felder in Ruhe',
   str_contains($skriptU['body'], "closest('a, button, input, select, textarea, label')"),
   'sonst oeffnet ein Klick auf "Entfernen" die Zeile');
ok('Und markierten Text ebenso',
   str_contains($skriptU['body'], 'getSelection'),
   'wer etwas markiert, liest - er klickt nicht');

// ---- Die Kursseite.

$umbauKurs = course_create(
    q1('SELECT * FROM users WHERE id = ?', [$lehrerId]),
    'Umbauisch' . bin2hex(random_bytes(2)), "\u{1F310}", $umbauKlasseId, '',
);
$umbauKursId = is_string($umbauKurs) ? 0 : (int) $umbauKurs['id'];
ok('Ein Kurs in dieser Klasse', $umbauKursId > 0,
   is_string($umbauKurs) ? $umbauKurs : '');

$res = teacherGet('course.php?id=' . $umbauKursId);

/*
 * Der Pfad ist kurz: Schule, Kurs. Die Klasse stand einmal dazwischen -
 * eine Ebene, die man nur durchquerte. Jetzt ist sie ein Ziel und steht
 * dort, wo es um ihre Kinder geht.
 */
ok('Es gibt keinen Pfad mehr, ueber den man die Klasse durchquert',
   !str_contains($res['body'], 'class="crumbs"'),
   'Schule > Klasse > Kurs war eine Ebene zu viel - und am Telefon zwei Zeilen');
ok('Die Klasse ist von hier aus trotzdem erreichbar',
   str_contains($res['body'], 'class.php?id=' . $umbauKlasseId . '&amp;kurs=' . $umbauKursId),
   'und nimmt den Kurs mit, damit der Weg zurueck steht');

/*
 * Der QR-Code im leeren Kurs fuehrt in die Einleseansicht dieses Kurses -
 * frueher nur auf die Startseite, und dort stand man dann und wusste nicht
 * weiter.
 */
$umbauSprache = (int) qv('SELECT language_id FROM courses WHERE id = ?', [$umbauKursId]);
/*
 * Im leeren Kurs steht kein QR-Code mehr. Er fuehrte dorthin, wo man sich
 * erst noch anmelden muss - der Weg, der das ueberspringt, heisst "Am
 * Smartphone einlesen", und zwei Codes nebeneinander waren einer zu viel.
 */
ok('Der leere Kurs zeigt keinen Code mehr',
   !str_contains($res['body'], 'importqr') && !str_contains($res['body'], '<svg'));
ok('Und auch keine zwei Einleseknoepfe mehr',
   !str_contains($res['body'], 'data-handoff'),
   'die Frage an dieser Stelle lautet: Ich brauche eine neue Lerneinheit');
ok('Sondern einen Knopf, der eine anlegt',
   str_contains($res['body'], 'name="add_unit"'));

// Eine Lerneinheit anlegen - dann weicht die Karte der Tabelle mit Anlegezeile.
$umbauUnit = makeUnit($lehrerId, $umbauSprache, 'Umbau-Unit');
$res = teacherGet('course.php?id=' . $umbauKursId);
ok('Mit Lerneinheiten ist die Karte weg', !str_contains($res['body'], 'importcard'));
ok('Dafuer steht eine Anlegezeile in der Tabelle',
   preg_match('/<tr class="newrow">.*?name="add_unit"/s', $res['body']) === 1,
   'ein Knopf, eine Frage: Ich brauche eine neue Lerneinheit');
ok('Die Lerneinheit oeffnet sich per Zeilenklick',
   preg_match('/<tr data-href="[^"]*unit\.php\?id=' . $umbauUnit . '"/', $res['body']) === 1);

// ---- Aufnehmen ueber ein Feld mit Vorschlaegen.

ok('Es gibt kein Auswahlfeld mehr zum Aufnehmen',
   !str_contains($res['body'], 'Einzeln aufnehmen'));
ok('Sondern eine Zeile mit Vorschlagsliste',
   str_contains($res['body'], 'list="kandidaten"')
   && str_contains($res['body'], '<datalist id="kandidaten"'));

$fremder = makeUser('e2e_vorschlag', 'Vorschlag K.');
q('UPDATE users SET school_id = ? WHERE id = ?',
  [(int) qv('SELECT school_id FROM users WHERE id = ?', [$lehrerId]), $fremder]);

$res = teacherGet('course.php?id=' . $umbauKursId);
ok('Die Liste nennt, wer noch nicht im Kurs ist',
   str_contains($res['body'], 'Vorschlag K.'));

teacherRequest($base . '/teacher/course.php?id=' . $umbauKursId, [
    'add_member_by_name' => '1', 'course_id' => $umbauKursId,
    'member_name' => 'Vorschlag K.', 'csrf' => $lehrerCsrf,
]);
ok('Ein Name aus der Liste nimmt auf',
   course_role($fremder, $umbauKursId) === 'student');

$res = teacherGet('course.php?id=' . $umbauKursId);
ok('Danach steht der Name nicht mehr in der Liste',
   preg_match('/<datalist id="kandidaten">(.*?)<\/datalist>/s', $res['body'], $dm) !== 1
   || !str_contains($dm[1], 'Vorschlag K.'));

$vorher = (int) qv('SELECT COUNT(*) FROM course_members WHERE course_id = ?', [$umbauKursId]);
teacherRequest($base . '/teacher/course.php?id=' . $umbauKursId, [
    'add_member_by_name' => '1', 'course_id' => $umbauKursId,
    'member_name' => 'Gibt Es Nicht', 'csrf' => $lehrerCsrf,
]);
ok('Ein erfundener Name nimmt niemanden auf',
   (int) qv('SELECT COUNT(*) FROM course_members WHERE course_id = ?', [$umbauKursId]) === $vorher);

q('DELETE FROM users WHERE id = ?', [$fremder]);

// ---- Tabellen am Telefon.

$cssU = (string) file_get_contents(__DIR__ . '/../admin/admin.css');
ok('Es gibt einen Umbruchpunkt fuer kleine Bildschirme',
   preg_match('/@media \(max-width: 720px\)/', $cssU) === 1);
ok('Darunter wird aus jeder Zeile eine Karte',
   preg_match('/@media \(max-width: 720px\).*?table\.data tr \{[^}]*display: block/s', $cssU) === 1);
ok('Und die Spaltenueberschrift steht vor dem Wert',
   str_contains($cssU, 'content: attr(data-label)'),
   'ohne Kopfzeile waere eine Karte eine Reihe unbeschrifteter Werte');

$res = teacherGet('class.php?id=' . $umbauKlasseId);
ok('Die Zellen tragen ihre Beschriftung mit',
   substr_count($res['body'], 'data-label="') >= 6,
   substr_count($res['body'], 'data-label="') . ' Zellen');

q('DELETE FROM classes WHERE id = ?', [$umbauKlasseId]);

section('Feinschliff im Lehrkraft-Bereich');

// ---- Die Leiste: Zahnrad und ein Knopf zum Abmelden.

$res = teacherGet('classes.php');
ok('Abmelden ist ein Knopf, kein unterstrichenes Wort',
   preg_match('/<button class="mitem" name="teacher_logout"/', $res['body']) === 1
   && !preg_match('/class="linkbtn" name="teacher_logout"/', $res['body']),
   'es tut etwas, statt woandershin zu fuehren');
ok('Es steht im Menue rechts, zusammen mit dem eigenen Konto',
   preg_match('/id="menuRechts".*?name="teacher_logout"/s', $res['body']) === 1);
/*
 * Das eigene Konto bleibt im Lehrkraft-Bereich.
 *
 * Der Menueeintrag fuehrte einmal in die App - Name, Farbe und Passwort
 * sind dieselben, egal von welcher Seite man kommt, und eine zweite
 * Fassung schien zwei Orte fuer eine Sache. In der Bedienung war es das
 * Gegenteil: Wer hier drueckte, stand in einer anderen Anwendung, und der
 * Zurueck-Knopf fuehrte an den Anfang der Kinderansicht.
 */
ok('Darin auch der Weg zum Profil',
   preg_match('/<a class="mitem" href="[^"]*\/teacher\/konto\.php">/', $res['body']) === 1);
ok('Und einer geradewegs zum Passwort',
   preg_match('/<a class="mitem" href="[^"]*\/teacher\/konto\.php#passwort">/',
              $res['body']) === 1,
   'erst suchen und dann tippen ist kein Weg, den man zweimal geht');
ok('Und keiner mehr in die Kinderansicht',
   !str_contains($res['body'], '#/konto'),
   'von dort fuehrte der Zurueck-Knopf an den Anfang der App');

// ---- Die Seite selbst: dieselbe Leiste, dieselben Regeln.

$res = teacherGet('konto.php');
ok('Die Kontoseite liegt im Lehrkraft-Bereich', $res['status'] === 200);
ok('Und traegt dessen Leiste',
   str_contains($res['body'], 'class="adminbar"')
   && str_contains($res['body'], 'id="menuLinks"'),
   'nahtlos heisst: dieselbe Navigation wie jede andere Seite hier');
ok('Sie zeigt Name, Farbe und Passwort',
   str_contains($res['body'], 'name="name"')
   && str_contains($res['body'], 'class="colorpick"')
   && str_contains($res['body'], 'name="change_password"'));
ok('Und den eigenen Benutzernamen, der sich nicht aendern laesst',
   str_contains($res['body'], '<code>' . h($lehrerName) . '</code>'),
   $lehrerName);
ok('Ohne JavaScript bedienbar: zwei gewoehnliche Formulare',
   substr_count($res['body'], '<form method="post" class="card kontoform">') === 2);

$altName = (string) qv('SELECT display_name FROM users WHERE id = ?', [$lehrerId]);
$res = teacherRequest($base . '/teacher/konto.php', [
    'save_profile' => '1', 'name' => 'Frau Umbenannt', 'color' => '#4f7cff',
    'csrf' => $lehrerCsrf,
]);
ok('Der Name laesst sich hier aendern',
   (string) qv('SELECT display_name FROM users WHERE id = ?', [$lehrerId])
   === 'Frau Umbenannt');
ok('Und steht danach gleich in der Leiste',
   str_contains($res['body'], 'Frau Umbenannt'),
   'sie traegt den Namen - sonst bliebe der alte stehen');

$res = teacherRequest($base . '/teacher/konto.php', [
    'save_profile' => '1', 'name' => '   ', 'color' => '#4f7cff', 'csrf' => $lehrerCsrf,
]);
ok('Ein leerer Name wird abgewiesen',
   (string) qv('SELECT display_name FROM users WHERE id = ?', [$lehrerId])
   === 'Frau Umbenannt'
   && str_contains($res['body'], 'Bitte einen Namen angeben.'));

$vorherHash = (string) qv('SELECT password_hash FROM users WHERE id = ?', [$lehrerId]);
$res = teacherRequest($base . '/teacher/konto.php', [
    'change_password' => '1', 'current' => 'falsch-falsch',
    'password' => 'neuesgeheim', 'password2' => 'neuesgeheim', 'csrf' => $lehrerCsrf,
]);
ok('Ohne das bisherige Passwort geht nichts',
   (string) qv('SELECT password_hash FROM users WHERE id = ?', [$lehrerId]) === $vorherHash
   && str_contains($res['body'], 'Das bisherige Passwort stimmt nicht.'),
   'ein liegengelassener Rechner im Lehrerzimmer ist kein Freibrief');

$res = teacherRequest($base . '/teacher/konto.php', [
    'change_password' => '1', 'current' => 'lehrerin123',
    'password' => 'kurz', 'password2' => 'kurz', 'csrf' => $lehrerCsrf,
]);
ok('Und ein zu kurzes neues auch nicht',
   (string) qv('SELECT password_hash FROM users WHERE id = ?', [$lehrerId]) === $vorherHash
   && str_contains($res['body'], 'mindestens 6 Zeichen'));

$res = teacherRequest($base . '/teacher/konto.php', [
    'change_password' => '1', 'current' => 'lehrerin123',
    'password' => 'einesneues1', 'password2' => 'einanderes2', 'csrf' => $lehrerCsrf,
]);
ok('Zwei verschiedene Eingaben ebenso wenig',
   (string) qv('SELECT password_hash FROM users WHERE id = ?', [$lehrerId]) === $vorherHash
   && str_contains($res['body'], 'nicht gleich'));

/*
 * Und die Regeln stehen nur einmal da - die App ruft dieselben auf. Sonst
 * waere die Mindestlaenge hier sechs und dort irgendwann acht.
 */
ok('Beide Wege benutzen dieselben Regeln',
   str_contains((string) file_get_contents(__DIR__ . '/../teacher/konto.php'),
                'profile_change_password(')
   && str_contains((string) file_get_contents(__DIR__ . '/../api/profile.php'),
                   'profile_change_password('),
   'zwei Fassungen derselben Sache waeren bald zwei verschiedene');

q('UPDATE users SET display_name = ? WHERE id = ?', [$altName, $lehrerId]);

// ---- Der Weg in die Schueleransicht steht in der Zeile der Ueberschrift.

$fsKlasse = class_create(
    (int) qv('SELECT school_id FROM users WHERE id = ?', [$lehrerId]),
    'Fein' . bin2hex(random_bytes(2)),
);
$fsKlasseId = (int) ($fsKlasse['id'] ?? 0);
$fsKurs = course_create(
    q1('SELECT * FROM users WHERE id = ?', [$lehrerId]),
    'Feinisch' . bin2hex(random_bytes(2)), "\u{1F310}", $fsKlasseId, '',
);
$fsKursId    = is_string($fsKurs) ? 0 : (int) $fsKurs['id'];
$fsSprache   = (int) qv('SELECT language_id FROM courses WHERE id = ?', [$fsKursId]);
ok('Ein Kurs fuer den Feinschliff', $fsKursId > 0);

$res = teacherGet('course.php?id=' . $fsKursId);
ok('Die Ueberschrift hat eine eigene Zeile',
   preg_match('/<div class="titelzeile">\s*<h1>/', $res['body']) === 1);

/*
 * Neben der Ueberschrift steht kein Knopf mehr.
 *
 * "So sieht es die Klasse" stand auf zwei von sieben Seiten und fuehrte nur
 * in eine Richtung; zurueck ging es ueber einen Hinweis in der App, den es
 * auch nicht ueberall gab. Zwei halbe Wege fuer eine Bewegung. Jetzt ist es
 * ein Schalter mit zwei Stellungen im Zahnrad - an derselben Stelle auf
 * jeder Seite, in beide Richtungen, und er zeigt nebenbei, wo man steht.
 */
$fsUnit = makeUnit($lehrerId, $fsSprache, 'Feinschliff-Unit');
foreach ([['course.php?id=' . $fsKursId, 'Kurs',        '#/lang/' . $fsSprache],
          ['unit.php?id=' . $fsUnit,     'Lerneinheit', '#/unit/' . $fsUnit],
          ['classes.php',                'Klassen',     null]] as [$wo, $wie, $ziel]) {
    $sicht = teacherGet($wo);

    ok("Kein Knopf neben der Ueberschrift mehr ($wie)",
       !str_contains($sicht['body'], 'So sieht es die Klasse'));
    ok("Der Schalter steht im Zahnrad ($wie)",
       preg_match('/<p class="mkopf klein">Ansicht<\/p>\s*<div class="ansichtwahl"/',
                  $sicht['body']) === 1);
    ok("Und zwar unter \"Passwort aendern\" und ueber den Farben ($wie)",
       preg_match('/Passwort ändern.*?class="ansichtwahl".*?class="themawahl"/s',
                  $sicht['body']) === 1);
    ok("\"Verwaltung\" ist hier die Stellung, in der man steht ($wie)",
       preg_match('/<span class="ansichtknopf on" aria-current="page">/', $sicht['body']) === 1,
       'und darum kein Verweis: ein Knopf dorthin, wo man ist, ist keiner');

    if ($ziel === null) {
        // Klassenlisten gibt es in der Schueleransicht nicht - von dort
        // fuehrt der Wechsel auf die Startseite, nicht ins Leere.
        ok('Ohne Entsprechung fuehrt der Wechsel auf die Startseite',
           preg_match('/<a class="ansichtknopf" href="([^"]*)"/', $sicht['body'], $zm) === 1
           && !str_contains($zm[1], '#'), $zm[1] ?? '(keiner)');
    } else {
        ok("Der Wechsel fuehrt auf die Entsprechung DIESER Seite ($wie)",
           preg_match('/<a class="ansichtknopf" href="([^"]*)"/', $sicht['body'], $zm) === 1
           && str_ends_with($zm[1], $ziel),
           ($zm[1] ?? '(keiner)') . ' statt ... ' . $ziel);
    }
}

/*
 * Eine Kennung aus der Adresse, die dieser Lehrkraft nicht gehoert, fuehrt
 * nicht auf einen fremden Kurs: Gesucht wird in IHRER Kursliste.
 */
$fremdKurs = (int) qv('SELECT co.id FROM courses co
                        WHERE NOT EXISTS (SELECT 1 FROM course_members m
                                           WHERE m.course_id = co.id AND m.user_id = ?)
                        ORDER BY co.id LIMIT 1', [$lehrerId]);
if ($fremdKurs > 0) {
    $fremd = teacherGet('course.php?id=' . $fremdKurs);
    ok('Ein fremder Kurs fuehrt nicht in dessen Schueleransicht',
       preg_match('/<a class="ansichtknopf" href="([^"]*)"/', $fremd['body'], $fm) !== 1
       || !str_contains($fm[1], '#/lang/'),
       $fm[1] ?? '(keiner)');
}

$kern = http($base . '/core.js');
ok('Die Lernansicht sagt, was sie ist',
   str_contains($kern['body'], 'export function lernansicht'));
ok('Und nur ihr',
   str_contains($kern['body'], 'VT.user?.isTeacher'),
   'ein Kind muss nicht erklaert bekommen, dass es seine eigene App sieht');

/*
 * Und zwar ganz oben, vor der Leiste - auf allen drei Seiten, die eine
 * Entsprechung in der Verwaltung haben. Im Quiz und im Lueckentext steht er
 * nicht: Die binden sich an die sichtbare Hoehe (.app.fitted ist fixiert und
 * genau so hoch wie das Fenster), und ein Streifen darueber schoebe die
 * Eingabezeile aus dem Bild.
 */
foreach ([['views/languages.js', 'Meine Kurse'],
          ['views/language.js',  'Der Kurs'],
          ['views/unit.js',      'Die Lerneinheit']] as [$datei, $wie]) {
    $q = http($base . '/' . $datei)['body'];
    ok("$wie zeigt den Streifen", str_contains($q, 'lernansicht()'));
    ok("Und zwar vor der Leiste ($wie)",
       preg_match('/\$\{lernansicht\(\)\}\s*\$\{topbar\(/', $q) === 1,
       'ganz oben heisst ganz oben');
}
foreach (['views/quiz.js', 'views/cloze.js'] as $datei) {
    ok("Beim Ueben steht er nicht ($datei)",
       !str_contains(http($base . '/' . $datei)['body'], 'lernansicht('),
       '.app.fitted ist genau so hoch wie das Fenster');
}
ok('Der Weg zurueck steht nicht mehr im Hinweis',
   !str_contains($kern['body'], "teacherBack('Zurück zur Verwaltung'"),
   'er steht im Zahnrad, auf jeder Seite dieselbe Stelle');

/*
 * Auch die App kennt die Entsprechung ihrer Seiten - dieselben drei, nur
 * andersherum. Ueben und Lueckentext gehoeren zu ihrer Lerneinheit.
 */
ok('Die App findet die Entsprechung in der Verwaltung',
   str_contains($kern['body'], 'function verwaltungZiel()'));
foreach ([
    ['/teacher/unit.php?id=${einheit[1]}',        'die Lerneinheit'],
    ['/teacher/course.php?id=${k.course_id}',     'den Kurs'],
    ['`${VT.base}/teacher/`',                     'die Startseite als Rueckfall'],
] as [$stueck, $was]) {
    ok("Sie zeigt auf $was", str_contains($kern['body'], $stueck), $stueck);
}
ok('Ueben und Lueckentext zaehlen zu ihrer Lerneinheit',
   str_contains($kern['body'], '(?:unit|quiz|cloze)'),
   'es ist dieselbe Lerneinheit, nur in Betrieb');

/*
 * Der Eintrag "Zur Verwaltung" im linken Menue ist weg: Der Schalter kann
 * dasselbe und mehr. Zwei Wege fuer eine Bewegung sind einer zu viel - und
 * der schlechtere stand im falschen Menue.
 */
ok('Und das linke Menue fuehrt nicht mehr daneben hinaus',
   !str_contains($kern['body'], '<span>Zur Verwaltung</span>'),
   'der Eintrag ist weg - der Kommentar, der das erklaert, darf bleiben');

/*
 * Beide Fassungen des Schalters - PHP und JavaScript - muessen dasselbe
 * sagen. Dieselbe Sicherung wie bei der Farbwahl darunter.
 */
preg_match('/<p class="mkopf klein">Ansicht<\/p>\s*<div class="ansichtwahl".*?<\/div>/s',
           teacherGet('index.php')['body'], $am);
$phpAnsicht = $am[0] ?? '';
$jsAnsicht  = (string) file_get_contents(__DIR__ . '/../menue.js');
ok('Die Verwaltung liefert den Schalter aus', $phpAnsicht !== '');
foreach (['Verwaltung', 'Lernansicht', 'ansichtwahl', 'ansichtknopf'] as $wort) {
    ok('Beide Fassungen kennen "' . $wort . '"',
       str_contains($phpAnsicht, $wort) && str_contains($jsAnsicht, $wort));
}
ok('Und in beiden ist genau eine Stellung die aktive',
   substr_count($phpAnsicht, 'ansichtknopf on') === 1
   && substr_count($jsAnsicht, 'ansichtknopf on') === 1);
ok('In der Verwaltung ist es "Verwaltung"',
   preg_match('/ansichtknopf on.*?Verwaltung/s', $phpAnsicht) === 1);
ok('Und in der App die Lernansicht',
   preg_match('/ansichtknopf on.*?Lernansicht/s', $jsAnsicht) === 1);
ok('Das Wort "Schueleransicht" steht nirgends mehr im Schalter',
   !str_contains($phpAnsicht, 'Schüleransicht') && !str_contains($jsAnsicht, 'Schüleransicht'));

/*
 * Die Kennung des Kurses kommt aus der API - und nur fuer eine Lehrkraft.
 * Einem Kind sagt sie nichts; der Lehrkraft-Bereich laesst es ohnehin nicht
 * hinein, also steht sie dort auch nicht.
 */
$fsJar = tempnam(sys_get_temp_dir(), 'vtfs');
$fsSicht = apiAls($fsJar, static function () use ($lehrerName, $fsSprache): array {
    apiCall('auth', 'login', ['username' => $lehrerName, 'password' => 'lehrerin123']);
    [$d] = apiCall('units', 'list', null, ['language_id' => $fsSprache]);
    return $d['language'] ?? [];
});
ok('Die Lehrkraft bekommt die Kennung ihres Kurses',
   (int) ($fsSicht['courseId'] ?? 0) === $fsKursId,
   json_encode($fsSicht));
@unlink($fsJar);

// Und die Gegenprobe mit einem Kind desselben Kurses.
$fsKindName = 'e2e_sichtkind';
$fsKind     = makeUser($fsKindName, 'Sichtkind');
user_assign_to_school($fsKind, (int) qv('SELECT school_id FROM users WHERE id = ?', [$lehrerId]));
course_add_member($fsKursId, $fsKind, COURSE_ROLE_STUDENT);

$fsKindJar   = tempnam(sys_get_temp_dir(), 'vtfsk');
$fsKindSicht = apiAls($fsKindJar, static function () use ($fsKindName, $fsSprache): array {
    apiCall('auth', 'login', ['username' => $fsKindName, 'password' => 'geheim123']);
    [$d] = apiCall('units', 'list', null, ['language_id' => $fsSprache]);
    return $d['language'] ?? [];
});
ok('Ein Kind desselben Kurses bekommt sie nicht',
   array_key_exists('courseId', $fsKindSicht) && $fsKindSicht['courseId'] === null,
   json_encode($fsKindSicht));
ok('Den Namen des Kurses sieht es dagegen weiterhin',
   ($fsKindSicht['label'] ?? '') !== '', json_encode($fsKindSicht));
@unlink($fsKindJar);
q('DELETE FROM users WHERE id = ?', [$fsKind]);

/*
 * Aus dem Hinweiskasten ist ein Streifen geworden.
 *
 * Vier Zeilen Erklaerung ueber den Kursen waren richtig, solange sie die
 * einzige Auskunft waren. Inzwischen steht im Zahnrad ein Schalter, der
 * dasselbe sagt und den Weg zurueck kennt - uebrig bleibt die Antwort auf
 * "wo bin ich hier", in einem Wort.
 */
$stilF = (string) file_get_contents(__DIR__ . '/../style.css');
ok('Den Hinweiskasten gibt es nicht mehr',
   !str_contains($stilF, 'notice.pupilview')
   && !str_contains($kern['body'], 'pupilHint'));
ok('Stattdessen ein Streifen',
   str_contains($kern['body'], 'class="lernansicht">Lernansicht'));
ok('Er zieht sich bis an die Kanten',
   preg_match('/\.lernansicht\s*\{[^}]*margin:[^}]*safe-area-inset-right/s', $stilF) === 1,
   'ein eingerueckter Streifen sieht aus wie ein Kasten, der nicht passt');
ok('Und ist klein',
   preg_match('/\.lernansicht\s*\{[^}]*font-size:\s*\.7\d*rem/s', $stilF) === 1);

// ---- Die beiden Wege zum Einlesen stehen nebeneinander.

$res = teacherGet('course.php?id=' . $fsKursId);
ok('Die Anlegezeile stellt beide Wege nebeneinander',
   preg_match('/class="coursetitle addbuttons"/', $res['body']) === 1);
$cssF = (string) file_get_contents(__DIR__ . '/../admin/admin.css');
ok('Und die Reihe ist waagerecht',
   preg_match('/\.coursetitle\.addbuttons\s*\{[^}]*display:\s*flex/s', $cssF) === 1,
   'untereinander lesen sie sich wie Schritt eins und Schritt zwei');

// ---- Das Farbfeld nimmt feste Groessen statt der halben Seite.

$cssS = (string) file_get_contents(__DIR__ . '/../style.css');
/*
 * In der App hat das Farbfeld dieselben Kaestchen wie der Monatskalender
 * darueber. Dass es dabei mit der Karte waechst, ist in Ordnung: Es liegt
 * zugeklappt hinter einem Knopf.
 */
preg_match('/\.monatsgitter\s*\{([^}]*)\}/s', $cssS, $kal);
preg_match('/\.swatches\s*\{([^}]*)\}/s', $cssS, $fel);
$spalten = static fn (string $b): string =>
    preg_match('/grid-template-columns:\s*([^;]+);/', $b, $m) === 1 ? trim($m[1]) : '';
$abstand = static fn (string $b): string =>
    preg_match('/\bgap:\s*([^;]+);/', $b, $m) === 1 ? trim($m[1]) : '';
ok('Das Farbfeld hat die Spalten des Kalenders',
   $spalten($fel[1] ?? '') === 'repeat(7, 1fr)'
   && $spalten($fel[1] ?? '') === $spalten($kal[1] ?? ''),
   $spalten($fel[1] ?? '') . ' / ' . $spalten($kal[1] ?? ''));
ok('Und seinen Abstand', $abstand($fel[1] ?? '') === $abstand($kal[1] ?? ''),
   $abstand($fel[1] ?? '') . ' / ' . $abstand($kal[1] ?? ''));
ok('Die Kaestchen sind quadratisch und gerundet wie die Tage',
   preg_match('/\.swatches \.swatch-pick span\s*\{[^}]*aspect-ratio:\s*1;[^}]*border-radius:\s*7px/s', $cssS) === 1
   && preg_match('/\.monatstag\s*\{[^}]*border-radius:\s*7px/s', $cssS) === 1);

$profilQ = (string) file_get_contents(__DIR__ . '/../views/profile.js');
ok('Das Feld liegt zugeklappt hinter einem Knopf mit der Farbe',
   str_contains($profilQ, '<details class="farbwahl"')
   && !str_contains($profilQ, '<details class="farbwahl" open')
   && str_contains($profilQ, 'class="farbpunkt"'));
ok('Darueber steht das App-Symbol wie auf dem Home-Bildschirm',
   str_contains($profilQ, 'class="homescreen"') && str_contains($profilQ, 'class="appsymbol"'));

/*
 * Die Vorschau rechnet wie icon.php: Verlauf nach unten auf 68 %, und Voki
 * auf 84 % der Kante. Zwei Stellen, eine Regel - laeuft eine davon weg,
 * zeigt das Konto ein anderes Symbol als das Telefon.
 *
 * Hier wurde einmal auch die Schrift des Anfangsbuchstabens verglichen.
 * Den Buchstaben gibt es nicht mehr, und mit ihm ging Roboto.
 */
$iconQ = (string) file_get_contents(__DIR__ . '/../icon.php');
ok('Vorschau und icon.php dunkeln gleich ab',
   str_contains($iconQ, '$c * 0.68') && str_contains($profilQ, 'SYMBOL_DUNKEL = 0.68'));
ok('Und zeigen Voki gleich gross',
   str_contains($iconQ, 'VOKI_ANTEIL          = 0.84')
   && preg_match('/\.appsymbol img\s*\{[^}]*width:\s*84%/', $cssS) === 1);
ok('Und zeigen denselben Voki',
   str_contains($profilQ, 'assets/voki-icon.svg') && !str_contains($profilQ, 'anfangsbuchstabe'));

q('DELETE FROM classes WHERE id = ?', [$fsKlasseId]);

// ---- Zwei Kurse derselben Sprache lassen sich auseinanderhalten.

/*
 * Der gemeldete Fall: zwei Kacheln "Englisch" nebeneinander. Die Kachel
 * traegt dann den Namen des Kurses - aber nur dann. Legt ein Kind selbst
 * eine Sprache an, heisst sein Kurs "Englisch Lilli M.", und der eigene Name
 * auf der eigenen Kachel ist keine Auskunft.
 */
$dsSchule = (int) qv('SELECT school_id FROM users WHERE id = ?', [$lehrerId]);
$dsA = class_create($dsSchule, 'DsA' . bin2hex(random_bytes(2)));
$dsB = class_create($dsSchule, 'DsB' . bin2hex(random_bytes(2)));
$dsLehrer = q1('SELECT * FROM users WHERE id = ?', [$lehrerId]);
$dsSprache = 'Doppelisch' . bin2hex(random_bytes(2));
$dsKursA = course_create($dsLehrer, $dsSprache, "\u{1F310}", (int) $dsA['id'], '');
$dsJar = tempnam(sys_get_temp_dir(), 'vtds');

$einmal = apiAls($dsJar, function () use ($lehrerName) {
    apiCall('auth', 'login', ['username' => $lehrerName, 'password' => 'lehrerin123']);
    [$d] = apiCall('languages', 'list');
    return array_column($d['languages'] ?? [], 'name');
});
ok('Bei einem Kurs steht der Name der Sprache auf der Kachel',
   in_array($dsSprache, $einmal, true), implode(', ', $einmal));

$dsKursB = course_create($dsLehrer, $dsSprache, "\u{1F310}", (int) $dsB['id'], '');
ok('Ein zweiter Kurs derselben Sprache ist moeglich', !is_string($dsKursB),
   is_string($dsKursB) ? $dsKursB : '');

$zweimal = apiAls($dsJar, function () use ($lehrerName) {
    apiCall('auth', 'login', ['username' => $lehrerName, 'password' => 'lehrerin123']);
    [$d] = apiCall('languages', 'list');
    return array_column($d['languages'] ?? [], 'name');
});
ok('Bei zweien stehen die Kursnamen da',
   in_array($dsSprache . ' - ' . $dsA['name'], $zweimal, true)
   && in_array($dsSprache . ' - ' . $dsB['name'], $zweimal, true),
   implode(', ', $zweimal));
ok('Und nicht zweimal dasselbe Wort',
   count(array_keys($zweimal, $dsSprache, true)) === 0);

/*
 * Die Ueberschrift der Sprachseite folgt derselben Regel. Stuende dort etwas
 * anderes als auf der Kachel, waere der Weg dorthin eine Ueberraschung.
 */
$kopfA = apiAls($dsJar, function () use ($lehrerName, $dsKursA) {
    apiCall('auth', 'login', ['username' => $lehrerName, 'password' => 'lehrerin123']);
    [$d] = apiCall('units', 'list', null,
                   ['language_id' => (int) $dsKursA['language_id']]);
    return (string) ($d['language']['label'] ?? '');
});
ok('Die Seite dahinter traegt denselben Namen wie die Kachel',
   $kopfA === $dsSprache . ' - ' . $dsA['name'], $kopfA);

@unlink($dsJar);
q('DELETE FROM classes WHERE id IN (?, ?)', [(int) $dsA['id'], (int) $dsB['id']]);

section('Vokabeln von Hand');

/*
 * Bisher konnte nur der Betreiber im Admin eine Vokabel ergaenzen, aendern
 * oder loeschen. Eine Lehrkraft sah ein falsch erkanntes Wort in ihrer
 * eigenen Lerneinheit und konnte nichts tun - ausser alles neu einzulesen.
 */

$hvKlasse = class_create(
    (int) qv('SELECT school_id FROM users WHERE id = ?', [$lehrerId]),
    'Hand' . bin2hex(random_bytes(2)),
);
$hvKurs = course_create(
    q1('SELECT * FROM users WHERE id = ?', [$lehrerId]),
    'Handisch' . bin2hex(random_bytes(2)), "\u{1F310}", (int) $hvKlasse['id'], '',
);
$hvKursId = is_string($hvKurs) ? 0 : (int) $hvKurs['id'];
$hvUnit   = makeUnit($lehrerId, (int) $hvKurs['language_id'], 'Hand-Unit');
foreach (['alpha', 'bravo', 'charlie'] as $i => $w) {
    q('INSERT INTO vocab (unit_id, position, term_foreign, term_native)
       VALUES (?, ?, ?, ?)', [$hvUnit, $i, $w, 'de-' . $w]);
}
q('UPDATE units SET released_position = 2 WHERE id = ?', [$hvUnit]);
ok('Eine Lerneinheit fuer die Handarbeit', $hvUnit > 0);

$woerter = static fn (): array => array_column(qa(
    'SELECT term_foreign FROM vocab WHERE unit_id = ? ORDER BY position', [$hvUnit],
), 'term_foreign');

// ---- Die Seite bietet es an.

$res = teacherGet('unit.php?id=' . $hvUnit);
ok('Die Vokabeltabelle hat eine Anlegezeile',
   str_contains($res['body'], 'name="new_f"') && str_contains($res['body'], 'name="add_vocab"'));
ok('Je Zeile gibt es Aendern und Loeschen',
   substr_count($res['body'], 'data-edit=') === 3
   && substr_count($res['body'], 'name="delete_vocab"') === 3,
   substr_count($res['body'], 'data-edit=') . ' / '
   . substr_count($res['body'], 'name="delete_vocab"'));
ok('Die Rueckfrage beim Loeschen nennt die Folgen',
   str_contains($res['body'], 'Lernstand aller Kinder daran verschwinden mit'),
   'eine Vokabel zu loeschen nimmt jedem Kind seinen Stand dazu');

// ---- Hinzufuegen.

teacherRequest($base . '/teacher/unit.php?id=' . $hvUnit, [
    'add_vocab' => '1', 'unit_id' => $hvUnit,
    'new_f' => 'delta', 'new_n' => 'de-delta', 'csrf' => $lehrerCsrf,
]);
ok('Eine Vokabel von Hand kommt dazu',
   $woerter() === ['alpha', 'bravo', 'charlie', 'delta'], implode(',', $woerter()));
ok('Die Freigabe bleibt, wo sie war',
   (int) qv('SELECT released_position FROM units WHERE id = ?', [$hvUnit]) === 2);
ok('Die Positionen sind lueckenlos', vocab_positions_dense($hvUnit));

$vorher = count($woerter());
teacherRequest($base . '/teacher/unit.php?id=' . $hvUnit, [
    'add_vocab' => '1', 'unit_id' => $hvUnit,
    'new_f' => '', 'new_n' => 'ohne', 'csrf' => $lehrerCsrf,
]);
ok('Ein halbes Paar wird abgelehnt', count($woerter()) === $vorher);

// ---- Aendern.

$bravoId = (int) qv('SELECT id FROM vocab WHERE unit_id = ? AND term_foreign = ?',
                    [$hvUnit, 'bravo']);
teacherRequest($base . '/teacher/unit.php?id=' . $hvUnit, [
    'save_vocab' => $bravoId, 'unit_id' => $hvUnit,
    'edit_f' => 'bravissimo', 'edit_n' => 'de-bravissimo', 'csrf' => $lehrerCsrf,
]);
ok('Eine Vokabel laesst sich aendern',
   (string) qv('SELECT term_foreign FROM vocab WHERE id = ?', [$bravoId]) === 'bravissimo');
ok('Die Position bleibt dabei dieselbe',
   (int) qv('SELECT position FROM vocab WHERE id = ?', [$bravoId]) === 1);

// ---- Loeschen unterhalb der Marke.

$sichtbar = static fn (): array => array_column(qa(
    'SELECT v.term_foreign FROM vocab v JOIN units u ON u.id = v.unit_id
      WHERE v.unit_id = ? AND v.position < u.released_position
      ORDER BY v.position', [$hvUnit],
), 'term_foreign');

ok('Vor dem Loeschen sieht die Klasse zwei Woerter',
   $sichtbar() === ['alpha', 'bravissimo'], implode(',', $sichtbar()));

teacherRequest($base . '/teacher/unit.php?id=' . $hvUnit, [
    'delete_vocab' => $bravoId, 'unit_id' => $hvUnit, 'csrf' => $lehrerCsrf,
]);
ok('Die Vokabel ist weg', $woerter() === ['alpha', 'charlie', 'delta'],
   implode(',', $woerter()));
ok('Und die Klasse sieht deshalb eines weniger - nicht ein anderes',
   $sichtbar() === ['alpha'], implode(',', $sichtbar()));
ok('Die Positionen sind wieder lueckenlos', vocab_positions_dense($hvUnit));

// ---- Die Grenze: fremde Vokabeln.

$fremdKlasse = class_create(
    (int) qv('SELECT school_id FROM users WHERE id = ?', [$lehrerId]),
    'HandFremd' . bin2hex(random_bytes(2)),
);
$fremdKurs = course_create(
    q1('SELECT * FROM users WHERE id = ?', [$lehrerId]),
    'Handfremd' . bin2hex(random_bytes(2)), "\u{1F310}", (int) $fremdKlasse['id'], '',
);
$fremdUnit = makeUnit($lehrerId, (int) $fremdKurs['language_id'], 'Andere Unit');
q('INSERT INTO vocab (unit_id, position, term_foreign, term_native)
   VALUES (?, 0, ?, ?)', [$fremdUnit, 'unberuehrt', 'de-unberuehrt']);
$fremdVokabel = (int) qv('SELECT id FROM vocab WHERE unit_id = ?', [$fremdUnit]);

teacherRequest($base . '/teacher/unit.php?id=' . $hvUnit, [
    'save_vocab' => $fremdVokabel, 'unit_id' => $hvUnit,
    'edit_f' => 'gekapert', 'edit_n' => 'de-gekapert', 'csrf' => $lehrerCsrf,
]);
ok('Eine Vokabel aus einer anderen Lerneinheit laesst sich nicht aendern',
   (string) qv('SELECT term_foreign FROM vocab WHERE id = ?', [$fremdVokabel]) === 'unberuehrt',
   (string) qv('SELECT term_foreign FROM vocab WHERE id = ?', [$fremdVokabel]));

teacherRequest($base . '/teacher/unit.php?id=' . $hvUnit, [
    'delete_vocab' => $fremdVokabel, 'unit_id' => $hvUnit, 'csrf' => $lehrerCsrf,
]);
ok('Und auch nicht loeschen',
   (int) qv('SELECT COUNT(*) FROM vocab WHERE id = ?', [$fremdVokabel]) === 1);

/*
 * Und die Lerneinheit einer anderen Schule geht niemanden etwas an - die
 * Seite selbst prueft das schon beim Laden, aber gerade deshalb muss es
 * einmal nachgewiesen sein.
 */
q("INSERT IGNORE INTO schools (name) VALUES ('Fremde Schule 7')");
$fremdeSchule7 = (int) qv("SELECT id FROM schools WHERE name = 'Fremde Schule 7'");
q('UPDATE courses SET school_id = ? WHERE id = ?', [$fremdeSchule7, (int) $fremdKurs['id']]);

$res = teacherGet('unit.php?id=' . $fremdUnit);
ok('Eine Lerneinheit einer anderen Schule laesst sich nicht oeffnen',
   !str_contains($res['body'], 'unberuehrt'));

q('UPDATE courses SET school_id = ? WHERE id = ?',
  [(int) qv('SELECT school_id FROM users WHERE id = ?', [$lehrerId]), (int) $fremdKurs['id']]);
q('DELETE FROM schools WHERE id = ?', [$fremdeSchule7]);

// ---- Der Zeilenklick darf die Knoepfe in Ruhe lassen.

$skriptH = http($base . '/teacher/teacher.js');
ok('Das Skript schaltet die Zeile zum Formular um',
   str_contains($skriptH['body'], 'function initVocabEdit'));
ok('Und der Freigabe-Zeilenklick laesst Knoepfe aus',
   preg_match('/zeilen\.forEach\(\(tr, i\) => \{.*?closest\(.a, button, input/s',
              $skriptH['body']) === 1,
   'sonst gibt ein Druck auf "Loeschen" nebenbei Vokabeln frei');

q('DELETE FROM languages WHERE id IN (?, ?)',
  [(int) $hvKurs['language_id'], (int) $fremdKurs['language_id']]);
q('DELETE FROM classes WHERE id IN (?, ?)',
  [(int) $hvKlasse['id'], (int) $fremdKlasse['id']]);

section('Die Lerneinheit verwalten');

/*
 * Umbenennen und Loeschen standen in der Schueleransicht, unter "Verwalten",
 * und waren fuer eine Lehrkraft der einzige Weg dorthin. Nur ist die
 * Schueleransicht das, was die Klasse sieht - wer sie aufmacht, um
 * auszuprobieren, wie eine Lerneinheit ankommt, soll genau das sehen und
 * nicht zwei Knoepfe mehr. Jetzt stehen sie dort, wo verwaltet wird.
 */

$lvKlasse = class_create(
    (int) qv('SELECT school_id FROM users WHERE id = ?', [$lehrerId]),
    'LvK' . bin2hex(random_bytes(2)),
);
$lvKurs = course_create(
    q1('SELECT * FROM users WHERE id = ?', [$lehrerId]),
    'Verwaltisch' . bin2hex(random_bytes(2)), "\u{1F310}", (int) $lvKlasse['id'], '',
);
$lvKursId = is_string($lvKurs) ? 0 : (int) $lvKurs['id'];
$lvUnit   = makeUnit($lehrerId, (int) $lvKurs['language_id'], 'Unit vorher');
ok('Eine Lerneinheit zum Verwalten', $lvUnit > 0);

// ---- Der Weg zurueck auf die Startseite.

/*
 * Die Ueberschrift ist der Weg zurueck: "Englisch - 5B > Unit 4", und der
 * Kurs davor ist ein Knopf. "Meine Kurse" stand hier einmal daneben -
 * dafuer gibt es das Haeuschen im Pfad, und eine Ebene hoeher will man von
 * hier aus oefter als ganz nach oben.
 */
$res = teacherGet('unit.php?id=' . $lvUnit);
ok('Die Ueberschrift traegt den Kurs als Knopf zurueck',
   preg_match('/<h1><a class="kursknopf" href="[^"]*course\.php\?id=' . $lvKursId . '"/',
              $res['body']) === 1,
   'ein unterstrichenes Wort in einer Ueberschrift liest man als Ueberschrift');
ok('Danach kommt der Name der Lerneinheit',
   preg_match('/<span class="einheitname" data-titel>Unit vorher<\/span>/', $res['body']) === 1);
preg_match('/<div class="titelzeile">.*?<\/div>/s', $res['body'], $tz);
ok('Und "Meine Kurse" steht nicht mehr daneben',
   !str_contains($tz[0] ?? '', 'Meine Kurse'),
   'dafuer gibt es das Haeuschen im Pfad - dort steht es weiterhin im Titel');

// ---- Umbenennen, oben in der Ueberschrift.

ok('Der Stift steht in der Ueberschrift',
   preg_match('/<h1>.*?data-rename[ >]/s', $res['body']) === 1);
ok('Mit Haken zum Sichern und Kreuz zum Verwerfen',
   str_contains($res['body'], 'data-rename-save')
   && str_contains($res['body'], 'data-rename-cancel'));
ok('Das Feld gehoert ueber form= zum Formular daneben',
   preg_match('/<input class="einheitfeld"[^>]*form="titelform"/', $res['body']) === 1,
   'ein <form> darf in HTML nicht in einer <h1> stehen');
ok('Die Seite bietet das Umbenennen an',
   str_contains($res['body'], 'name="rename_unit"')
   && str_contains($res['body'], 'name="title"'));
ok('Und unten steht es kein zweites Mal',
   substr_count($res['body'], 'name="rename_unit"') === 1,
   'dieselbe Sache an zwei Stellen, zwei Bildschirme voneinander entfernt');
/*
 * Ohne JavaScript steht alles nebeneinander da: Name, Feld, alle drei
 * Knoepfe. Haesslich, aber bedienbar - und genau in dieser Reihenfolge
 * richtig. Das Skript blendet um, was gerade nicht gebraucht wird.
 */
$skriptT = http($base . '/teacher/teacher.js');
ok('Das Skript blendet den Titel um',
   str_contains($skriptT['body'], 'function initUnitTitle'));
ok('Und Escape verwirft wie das Kreuz',
   preg_match('/function initUnitTitle.*?Escape/s', $skriptT['body']) === 1,
   'wie beim Aendern einer Vokabel');

teacherRequest($base . '/teacher/unit.php?id=' . $lvUnit, [
    'rename_unit' => '1', 'unit_id' => $lvUnit,
    'title' => '  Unit   nachher  ', 'csrf' => $lehrerCsrf,
]);
ok('Der Titel laesst sich aendern',
   (string) qv('SELECT title FROM units WHERE id = ?', [$lvUnit]) === 'Unit nachher',
   (string) qv('SELECT title FROM units WHERE id = ?', [$lvUnit]));

$res = teacherRequest($base . '/teacher/unit.php?id=' . $lvUnit, [
    'rename_unit' => '1', 'unit_id' => $lvUnit, 'title' => '   ', 'csrf' => $lehrerCsrf,
]);
ok('Ein leerer Titel wird abgelehnt',
   (string) qv('SELECT title FROM units WHERE id = ?', [$lvUnit]) === 'Unit nachher'
   && str_contains($res['body'], 'braucht einen Titel'));

// ---- Loeschen, mit allem, was daran haengt.

foreach (['alpha', 'bravo'] as $i => $w) {
    q('INSERT INTO vocab (unit_id, position, term_foreign, term_native)
       VALUES (?, ?, ?, ?)', [$lvUnit, $i, $w, 'de-' . $w]);
}
$lvVokabel = (int) qv('SELECT id FROM vocab WHERE unit_id = ? ORDER BY position LIMIT 1',
                      [$lvUnit]);
q('INSERT INTO sentences (vocab_id, native_text, foreign_text, answer)
   VALUES (?, ?, ?, ?)', [$lvVokabel, 'Eins.', '{} .', 'alpha']);
record_answer($lehrerId, $lvVokabel, MODE_CHOICE, true);

$res = teacherGet('unit.php?id=' . $lvUnit);
ok('Die Seite bietet das Loeschen an', str_contains($res['body'], 'name="delete_unit"'));
ok('Zugeklappt, nicht im Weg',
   preg_match('/<details class="card">\s*<summary[^>]*>\s*Diese Lerneinheit l\xC3\xB6schen/s',
              $res['body']) === 1,
   'Loeschen ist nichts, worueber man stolpert');
ok('Die Rueckfrage nennt die Folgen',
   str_contains($res['body'], 'dem Lernstand aller Kinder daran'));

$res = teacherRequest($base . '/teacher/unit.php?id=' . $lvUnit, [
    'delete_unit' => '1', 'unit_id' => $lvUnit, 'csrf' => $lehrerCsrf,
]);
ok('Die Lerneinheit ist weg',
   q1('SELECT id FROM units WHERE id = ?', [$lvUnit]) === null);
ok('Die Vokabeln auch',
   (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ?', [$lvUnit]) === 0);
ok('Die Lueckensaetze auch',
   (int) qv('SELECT COUNT(*) FROM sentences WHERE vocab_id = ?', [$lvVokabel]) === 0);
ok('Und die Lernstaende',
   (int) qv('SELECT COUNT(*) FROM progress WHERE vocab_id = ?', [$lvVokabel]) === 0);
ok('Die Meldung sagt, was mitgegangen ist',
   str_contains($res['body'], '2 Vokabeln') && str_contains($res['body'], 'Lückensätzen'),
   'sonst steht da nur "geloescht" und man raet, was das hiess');
ok('Und man steht danach im Kurs',
   str_contains($res['body'], 'Lerneinheiten') && str_contains($res['body'], 'Wer im Kurs ist'),
   'nicht auf einer Seite, die es nicht mehr gibt');

// ---- Die Grenze: eine Lerneinheit einer anderen Schule.

/*
 * Ein zweites Konto genuegt dafuer nicht: Es gehoert zur selben Schule, und
 * dort darf die Lehrkraft arbeiten - das ist die Vertretungsregel, und sie
 * ist Absicht. Die Grenze ist die Schule, also muss der Kurs ueber sie
 * hinweg.
 */
$lvFremdLang = makeLanguage($otherId, 'Fremdverwalt' . bin2hex(random_bytes(2)));
$lvFremdUnit = makeUnit($otherId, $lvFremdLang, 'Fremde Unit');

q("INSERT IGNORE INTO schools (name) VALUES ('Fremde Schule 11')");
$lvFremdeSchule = (int) qv("SELECT id FROM schools WHERE name = 'Fremde Schule 11'");
$lvEigeneSchule = (int) qv('SELECT school_id FROM courses WHERE language_id = ?',
                           [$lvFremdLang]);
q('UPDATE courses SET school_id = ? WHERE language_id = ?',
  [$lvFremdeSchule, $lvFremdLang]);

teacherRequest($base . '/teacher/unit.php?id=' . $lvFremdUnit, [
    'rename_unit' => '1', 'unit_id' => $lvFremdUnit,
    'title' => 'gekapert', 'csrf' => $lehrerCsrf,
]);
ok('Eine fremde Lerneinheit laesst sich nicht umbenennen',
   (string) qv('SELECT title FROM units WHERE id = ?', [$lvFremdUnit]) === 'Fremde Unit');

teacherRequest($base . '/teacher/unit.php?id=' . $lvFremdUnit, [
    'delete_unit' => '1', 'unit_id' => $lvFremdUnit, 'csrf' => $lehrerCsrf,
]);
ok('Und nicht loeschen',
   q1('SELECT id FROM units WHERE id = ?', [$lvFremdUnit]) !== null);

q('UPDATE courses SET school_id = ? WHERE language_id = ?',
  [$lvEigeneSchule, $lvFremdLang]);
q('DELETE FROM schools WHERE id = ?', [$lvFremdeSchule]);

q('DELETE FROM languages WHERE id IN (?, ?)',
  [(int) $lvKurs['language_id'], $lvFremdLang]);
q('DELETE FROM classes WHERE id = ?', [(int) $lvKlasse['id']]);

section('Eine Form, ein Wort');

/*
 * Dieser Abschnitt prueft Gleichfoermigkeit, und zwar am Quelltext.
 *
 * Das ist ungewoehnlich, aber hier richtig: Es geht nicht um Verhalten,
 * sondern darum, dass dieselbe Sache ueberall gleich heisst und gleich
 * aussieht. Ein Mensch sieht das beim Lesen nicht - er sieht drei Seiten
 * nie nebeneinander. Eine Pruefung schon.
 */

$lehrerDateien = glob(__DIR__ . '/../teacher/*.php') ?: [];
$lehrerQuelle  = '';
foreach ($lehrerDateien as $datei) {
    $lehrerQuelle .= (string) file_get_contents($datei);
}
ok('Der Lehrkraft-Bereich ist da', $lehrerQuelle !== '');

// ---- Eine Reihenfolge fuer die Knopfklassen.

$ausreisser = [];
foreach (['btn secondary small', 'btn danger small', 'btn small small'] as $falsch) {
    if (str_contains($lehrerQuelle, 'class="' . $falsch . '"')) {
        $ausreisser[] = $falsch;
    }
}
ok('Knopfklassen stehen immer in derselben Reihenfolge',
   $ausreisser === [], implode(', ', $ausreisser)
   . ' - erst btn, dann die Groesse, dann die Spielart');

// ---- Der Hoerer fuer die Rueckfragen steht genau einmal.

ok('Es gibt nur einen data-confirm-Hoerer',
   substr_count($lehrerQuelle, "closest('[data-confirm]')") === 0,
   'er gehoert nach teacher.js, nicht in jede Seite');
ok('Und der steht im Skript',
   str_contains((string) file_get_contents(__DIR__ . '/../teacher/teacher.js'),
                "closest('[data-confirm]')"));

// ---- Keine Klasse ohne Regel.

$cssQuelle = (string) file_get_contents(__DIR__ . '/../admin/admin.css')
           . (string) file_get_contents(__DIR__ . '/../style.css');
ok('Die tote Klasse compactform ist weg',
   !str_contains($lehrerQuelle, 'compactform'),
   'sie hatte nirgends eine Regel');

// ---- Jede Tabelle im selben Muster.

$res = teacherGet('classes.php');
ok('Die letzte Spalte heisst ueberall gleich',
   !str_contains($res['body'], '<th class="chev">'),
   'Kopf und Anlegezeile trugen zwei Namen fuer dieselbe Spalte');

$efKlasse = class_create(
    (int) qv('SELECT school_id FROM users WHERE id = ?', [$lehrerId]),
    'Einheit' . bin2hex(random_bytes(2)),
);
$efKurs = course_create(
    q1('SELECT * FROM users WHERE id = ?', [$lehrerId]),
    'Einheitlich' . bin2hex(random_bytes(2)), "\u{1F310}", (int) $efKlasse['id'], '',
);
$efKursId = is_string($efKurs) ? 0 : (int) $efKurs['id'];

// ---- Leerzustaende sehen gleich aus.

$res = teacherGet('course.php?id=' . $efKursId);
ok('Der leere Kurs benutzt die eine Form',
   str_contains($res['body'], '<div class="leer">'),
   'es gab vier verschiedene dafuer');

$efUnit = makeUnit($lehrerId, (int) $efKurs['language_id'], 'Leere Unit');

// Mit einer Lerneinheit steht die Tabelle da - vorher der Leerzustand.
$res = teacherGet('course.php?id=' . $efKursId);
ok('Auch die Einheitentabelle traegt "courses"',
   str_contains($res['body'], 'class="data courses rowlink kompakt" id="einheiten"'),
   'ohne das ist ihre Anlegezeile als einzige nicht getoent');

// ---- Die Lerneinheiten-Tabelle sagt eine Sache, und zwar immer dieselbe.

/*
 * Vorher standen dort vier Spalten: Titel, Vokabeln, Freigegeben, Angelegt.
 * "Vokabeln" und "Freigegeben" nannten beide die Gesamtzahl - zweimal
 * dieselbe Zahl nebeneinander -, und das Anlegedatum beantwortete keine
 * Frage, die sich beim Unterrichten stellt.
 *
 * Und "Freigegeben" hatte drei Formen: "alle", "noch keine", "3 von 8".
 * Drei Formen fuer eine Auskunft heisst, dass sich zwei Zeilen
 * untereinander nicht vergleichen lassen, ohne jede einzeln zu lesen.
 */
$efZu   = makeUnit($lehrerId, (int) $efKurs['language_id'], 'Noch zu');
$efHalb = makeUnit($lehrerId, (int) $efKurs['language_id'], 'Halb offen');
$efGanz = makeUnit($lehrerId, (int) $efKurs['language_id'], 'Ganz offen');
foreach ([$efZu, $efHalb, $efGanz] as $u) {
    for ($i = 0; $i < 4; $i++) {
        q('INSERT INTO vocab (unit_id, term_foreign, term_native, position)
           VALUES (?, ?, ?, ?)', [$u, 'w' . $i . '-' . $u, 'W' . $i, $i]);
    }
}
q('UPDATE units SET released_position = 0 WHERE id = ?', [$efZu]);
q('UPDATE units SET released_position = 2 WHERE id = ?', [$efHalb]);
q('UPDATE units SET released_position = 4 WHERE id = ?', [$efGanz]);

$res = teacherGet('course.php?id=' . $efKursId);
preg_match('/<table class="data courses rowlink kompakt" id="einheiten">.*?<\/table>/s',
           $res['body'], $etm);
$etab = $etm[0] ?? '';

/*
 * Vier Spalten: Griff, Titel, Freigegeben, Pfeil. Der Griff kam dazu, als
 * die Reihenfolge von Hand legbar wurde - sie ist dieselbe, in der die
 * Klasse die Lerneinheiten sieht.
 */
ok('Vier Spalten, nicht fuenf',
   preg_match_all('/<th[\s>]/', $etab) === 4,
   preg_match_all('/<th[\s>]/', $etab) . ' statt 4');
ok('Die Vokabelzahl steht nicht mehr als eigene Spalte da',
   !str_contains($etab, '>Vokabeln</th>'),
   'sie steht in "n von m" schon drin');
ok('Und das Anlegedatum auch nicht', !str_contains($etab, '>Angelegt</th>'));

ok('Halb offen heisst "2 von 4", und zwar gelb',
   str_contains($etab, '<span class="pill halb">2 von 4</span>'), $etab);
ok('Ganz offen heisst "4 von 4", und zwar gruen',
   str_contains($etab, '<span class="pill good">4 von 4</span>'));
ok('Nichts offen heisst "0 von 4", und zwar rot',
   str_contains($etab, '<span class="pill bad">0 von 4</span>'), $etab);
ok('Und "alle" steht nirgends mehr',
   !str_contains($etab, '>alle<') && !str_contains($etab, 'noch keine</span>'),
   'eine Form fuer eine Auskunft');
ok('Eine Lerneinheit ohne Vokabeln sagt das statt einer Zahl',
   str_contains($etab, '<span class="pill ohne">noch keine Vokabeln</span>'),
   '"0 von 0" waere keine Antwort');
/*
 * Nicht ".pill.leer": .leer gehoert schon dem gestrichelten Kasten fuer
 * Leerzustaende, mit 18 px Polster und einem Rahmen. Die Blase erbte das
 * und war doppelt so hoch wie ihre Nachbarn - im Quelltext sah man es
 * nicht, in der Tabelle sofort.
 */
ok('Und erbt dabei nicht den Leerzustands-Kasten',
   !str_contains($etab, 'pill leer'),
   'zwei Bedeutungen fuer einen Klassennamen');

/*
 * Und der Titel: Die Sprache stand als eigene Zeile unter dem Kursnamen -
 * "Englisch - 6B" und darunter noch einmal "Englisch". Jetzt steht sie als
 * Fahne davor, wo sie einen halben Zentimeter braucht statt einer Zeile.
 */
ok('Die Fahne steht vor dem Kursnamen',
   preg_match('/<h1><img class="kopfflagge"[^>]*>' . preg_quote(h((string) $efKurs['name']), '/')
              . '<\/h1>/', $res['body']) === 1,
   'und der Kursname gleich dahinter');
ok('Die Zeile mit der Sprache darunter ist weg',
   !str_contains($res['body'], '<p class="muted">' . h((string) qv(
       'SELECT l.name FROM languages l JOIN courses co ON co.language_id = l.id
         WHERE co.id = ?', [$efKursId])) . '</p>'),
   'dieselbe Auskunft zweimal');

q('DELETE FROM units WHERE id IN (?, ?, ?)', [$efZu, $efHalb, $efGanz]);
$res = teacherGet('course.php?id=' . $efKursId);
$res = teacherGet('unit.php?id=' . $efUnit);
ok('Die leere Lerneinheit ebenso', str_contains($res['body'], '<div class="leer">'));

/*
 * Und der Grund, warum das mehr als Kosmetik ist: Frueher verschluckte der
 * leere Zweig die ganze Tabelle - seit darin eine Anlegezeile steht, war
 * damit ausgerechnet in einer leeren Lerneinheit der einzige Weg verdeckt,
 * eine Vokabel von Hand einzutragen.
 */
/*
 * Auch eine leere Lerneinheit ist keine Sackgasse: Ohne Vokabeln steht
 * dort keine Tabelle mehr - aber "von Hand" laedt sie mit der
 * Anlegezeile, und dann ist sie der Ort, an dem die erste Vokabel
 * entsteht.
 */
ok('Ohne Vokabeln steht dort kein Tabellengeruest',
   !str_contains($res['body'], 'id="freigabe"'),
   'ein Kopf und nichts darunter ist keine Tabelle');
$leerHand = teacherGet('unit.php?id=' . $efUnit . '&vonhand=1');
ok('Aber "von Hand" bringt sie mit der Anlegezeile',
   str_contains($leerHand['body'], 'id="handzeile"')
   && str_contains($leerHand['body'], 'name="add_vocab"')
   && !preg_match('/id="handzeile"[^>]*hidden/', $leerHand['body']),
   'sonst ist eine leere Lerneinheit eine Sackgasse');

teacherRequest($base . '/teacher/unit.php?id=' . $efUnit, [
    'add_vocab' => '1', 'unit_id' => $efUnit,
    'new_f' => 'erste', 'new_n' => 'de-erste', 'csrf' => $lehrerCsrf,
]);
ok('Und es klappt auch wirklich',
   (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ?', [$efUnit]) === 1);

// ---- Stillgelegt steht dran, nicht nur blass.

q('UPDATE courses SET active = 0 WHERE id = ?', [$efKursId]);
$res = teacherGet('class.php?id=' . (int) $efKlasse['id']);
ok('Ein stillgelegter Kurs sagt, dass er stillgelegt ist',
   str_contains($res['body'], 'stillgelegt'),
   'blass allein ist keine Auskunft');
q('UPDATE courses SET active = 1 WHERE id = ?', [$efKursId]);

// ---- Ein Wort je Sache.

$verboten = [
    'Teilnehmende' => 'Kind',
    'Lektion'      => 'Lerneinheit',
    'Anschreiben'  => 'Zettel',
];
$gefunden = [];
foreach ($verboten as $falsch => $richtig) {
    if (str_contains($lehrerQuelle, $falsch)) {
        $gefunden[] = "$falsch (gemeint ist: $richtig)";
    }
}
ok('Dieselbe Sache heisst ueberall gleich', $gefunden === [], implode('; ', $gefunden));

ok('Das Glossar steht in der Beschreibung',
   str_contains((string) file_get_contents(__DIR__ . '/../README.md'), 'Ein Wort je Sache'));

q('DELETE FROM languages WHERE id = ?', [(int) $efKurs['language_id']]);
q('DELETE FROM classes   WHERE id = ?', [(int) $efKlasse['id']]);

section('Sprung ans Telefon');

/*
 * Ein QR-Code, der die Anmeldung ersetzt, ist ein Passwort in Bildform.
 * Entsprechend wird hier nicht die Schnittstelle geglaubt, sondern das
 * BILD gelesen: Das SVG besteht aus einem Pfad mit einem Rechteck je
 * dunklem Modul; daraus entsteht die Matrix zurueck, und qrLesen() macht
 * daraus wieder Text. Was in diesem Text steht, wird dann wirklich
 * aufgerufen - mit einem leeren Cookie-Glas, so wie ein Telefon daherkommt.
 */
require_once __DIR__ . '/../lib/handoff.php';

/** Das SVG aus lib/qr.php zurueck in eine Matrix lesen. */
function qrAusSvg(string $svg): ?array
{
    if (preg_match('/viewBox="0 0 (\d+) \d+"/', $svg, $v) !== 1) {
        return null;
    }
    $ganz = (int) $v[1];
    $rand = 4;
    $size = $ganz - 2 * $rand;
    if ($size < 21) {
        return null;
    }

    $m = array_fill(0, $size, array_fill(0, $size, false));
    if (preg_match('/<path d="([^"]*)"/', $svg, $p) !== 1) {
        return null;
    }
    if (preg_match_all('/M(\d+) (\d+)h1v1h-1z/', $p[1], $treffer, PREG_SET_ORDER) === 0) {
        return null;
    }
    foreach ($treffer as $t) {
        $c = (int) $t[1] - $rand;
        $r = (int) $t[2] - $rand;
        if ($r >= 0 && $r < $size && $c >= 0 && $c < $size) {
            $m[$r][$c] = true;
        }
    }
    return $m;
}

// ---- Eine Marke entsteht nur auf Druck, und nur fuer eine Lehrkraft.

/*
 * Eigene Klasse, eigener Kurs: Der Abschnitt haengt dann an nichts, was ein
 * anderer Abschnitt vorher aufgeraeumt haben koennte.
 */
$hoSchule = (int) qv('SELECT school_id FROM users WHERE id = ?', [$lehrerId]);
$hoKlasse = class_create($hoSchule, 'Sprung' . bin2hex(random_bytes(2)));
$hoKurs   = 0;
if (!is_string($hoKlasse)) {
    $angelegt = course_create(
        q1('SELECT * FROM users WHERE id = ?', [$lehrerId]),
        'Sprungisch' . bin2hex(random_bytes(2)), "\u{1F310}", (int) $hoKlasse['id'], '',
    );
    if (!is_string($angelegt)) {
        $hoKurs = (int) $angelegt['id'];
    }
}
ok('Die Lehrkraft hat einen Kurs fuer den Versuch', $hoKurs > 0);

/*
 * Der Sprung steht auf der Lerneinheit, nicht auf dem Kurs: Der Code
 * fuehrt ins Einlesen, und eingelesen wird IN eine Lerneinheit.
 */
$hoUnit = makeUnit($lehrerId, (int) $angelegt['language_id'], 'Sprung-Unit');
$res = teacherGet('unit.php?id=' . $hoUnit);
preg_match('/name="csrf" value="([a-f0-9]+)"/', $res['body'], $hm);
$hoCsrf = $hm[1] ?? '';

ok('Die Lerneinheit bietet den Sprung an',
   str_contains($res['body'], 'data-handoff')
   && str_contains($res['body'], 'id="handoff"'));

// Angemeldet, aber per GET: Eine Marke ist eine Handlung, kein Abruf.
$res = teacherRequest($base . '/teacher/handoff.php', null);
ok('Ohne POST gibt es keine Marke', $res['status'] === 405, 'Status ' . $res['status']);

$vorher = (int) qv('SELECT COUNT(*) FROM login_handoffs');
$res = teacherRequest($base . '/teacher/handoff.php', [
    'unit_id' => $hoUnit, 'csrf' => 'falsch',
]);
ok('Ohne gueltiges CSRF-Feld auch nicht',
   (int) qv('SELECT COUNT(*) FROM login_handoffs') === $vorher);

$res  = teacherRequest($base . '/teacher/handoff.php', [
    'unit_id' => $hoUnit, 'csrf' => $hoCsrf,
]);
$json = json_decode($res['body'], true);
ok('Mit POST und CSRF entsteht eine', ($json['ok'] ?? false) === true,
   mb_substr($res['body'], 0, 120));
ok('Und die Antwort traegt ein SVG',
   str_contains((string) ($json['svg'] ?? ''), '<svg'));
ok('Sie nennt auch die Frist', (int) ($json['minuten'] ?? 0) === HANDOFF_TTL);

// ---- Was im Bild steht, ist die Adresse, die zieht.

$matrix = qrAusSvg((string) ($json['svg'] ?? ''));
ok('Das Bild laesst sich zurueck in eine Matrix lesen', $matrix !== null);

$adresse = $matrix === null ? null : qrLesen($matrix);
ok('Und die Matrix wieder in Text', $adresse !== null, (string) $adresse);
ok('Im Code steht eine Adresse mit Marke',
   is_string($adresse) && str_contains($adresse, '?h='), (string) $adresse);

/*
 * Jetzt das Telefon: ein eigenes Cookie-Glas, also keine Sitzung. Wer die
 * Adresse aufruft, muss danach angemeldet sein und auf genau der
 * Lerneinheit stehen, von der der Code kam - nicht in der App, wo er die
 * Einheit in einer Liste wiedersuchen muesste.
 */
$handyJar = tempnam(sys_get_temp_dir(), 'vthandy');
$holen = static function (string $url) use ($handyJar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $handyJar,
        CURLOPT_COOKIEFILE     => $handyJar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $antwort = (string) curl_exec($ch);
    $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ort     = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return ['status' => $status, 'ort' => $ort, 'body' => $antwort];
};

// Die Adresse aus dem Bild zeigt auf public_url(); lokal ist das derselbe
// Host, aber der Test soll gegen $base laufen und nicht gegen die Welt.
$lokal = (string) $adresse;
if (preg_match('/\?h=(.+)$/', $lokal, $hmm) === 1) {
    $lokal = $base . '/?h=' . $hmm[1];
}

$r1 = $holen($lokal);
ok('Die Marke leitet weiter', $r1['status'] === 302, 'Status ' . $r1['status']);
ok('Und zwar auf genau diese Lerneinheit',
   str_contains($r1['ort'], '/teacher/unit.php?id=' . $hoUnit),
   $r1['ort']);

// Angemeldet? Die API antwortet nur einer Sitzung.
$ch = curl_init($base . '/api/languages.php?action=list');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR      => $handyJar,
    CURLOPT_COOKIEFILE     => $handyJar,
    CURLOPT_HTTPHEADER     => ['X-Vokabeltrainer: 1'],
    CURLOPT_TIMEOUT        => 30,
]);
$apiAntwort = json_decode((string) curl_exec($ch), true);
curl_close($ch);
ok('Das Telefon ist danach angemeldet', ($apiAntwort['ok'] ?? false) === true,
   (string) ($apiAntwort['error'] ?? '?'));

// ---- Genau einmal.

/*
 * Die Uhr eine Weile zuruecksetzen, bevor es ein zweites Mal versucht wird.
 *
 * Ohne das prueft der Abschnitt nichts: Beide Versuche fallen in dieselbe
 * Sekunde, das zweite UPDATE schreibt denselben Wert noch einmal, MySQL
 * meldet "null Zeilen geaendert" - und die Marke waere auch dann nicht
 * einloesbar, wenn der Riegel ganz fehlte. Genau so ist es beim ersten
 * Mutationsversuch gewesen: Der Riegel wurde entfernt, und der Test blieb
 * gruen. Mit einer gealterten Marke faellt er um, wie er soll.
 */
q('UPDATE login_handoffs SET used_at = DATE_SUB(NOW(), INTERVAL 5 SECOND)
    WHERE used_at IS NOT NULL AND used_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)');

$zweiterJar = tempnam(sys_get_temp_dir(), 'vtzwei');
$ch = curl_init($lokal);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR      => $zweiterJar,
    CURLOPT_COOKIEFILE     => $zweiterJar,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT        => 30,
]);
curl_exec($ch);
$ortZwei = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
curl_close($ch);
ok('Ein zweites Einloesen fuehrt nicht zur Lerneinheit',
   !str_contains($ortZwei, '/teacher/unit.php'), $ortZwei);

$ch = curl_init($base . '/api/languages.php?action=list');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR      => $zweiterJar,
    CURLOPT_COOKIEFILE     => $zweiterJar,
    CURLOPT_HTTPHEADER     => ['X-Vokabeltrainer: 1'],
    CURLOPT_TIMEOUT        => 30,
]);
$zweiAntwort = json_decode((string) curl_exec($ch), true);
curl_close($ch);
ok('Und meldet niemanden an', ($zweiAntwort['ok'] ?? false) !== true);

@unlink($handyJar);
@unlink($zweiterJar);

// ---- Abgelaufen ist abgelaufen.

$alt = handoff_create($lehrerId, '/lang/1/import');
q('UPDATE login_handoffs SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE)
    WHERE token_hash = ?', [hash('sha256', $alt)]);
ok('Eine abgelaufene Marke zieht nicht', handoff_redeem($alt) === null);

// ---- Fremde Kurse gehen niemanden etwas an.

$fremdeEinheit = (int) qv(
    'SELECT t.id FROM units t JOIN courses co ON co.id = t.course_id
      WHERE co.school_id <> ? LIMIT 1',
    [(int) qv('SELECT school_id FROM users WHERE id = ?', [$lehrerId])],
);
if ($fremdeEinheit > 0) {
    $res  = teacherRequest($base . '/teacher/handoff.php', [
        'unit_id' => $fremdeEinheit, 'csrf' => $hoCsrf,
    ]);
    $json = json_decode($res['body'], true);
    ok('Fuer eine Lerneinheit einer anderen Schule gibt es keine Marke',
       ($json['ok'] ?? false) !== true, mb_substr($res['body'], 0, 80));
}

// ---- Das Ziel kommt aus der Marke, nicht aus der Adresse.

ok('Ein Ziel mit Schema wird abgelehnt',
   !handoff_target_ok('https://boese.example/'));
ok('Ein Ziel mit doppeltem Schraegstrich ebenso',
   !handoff_target_ok('//boese.example/'));
ok('Ein gewoehnlicher Weg der App ist erlaubt',
   handoff_target_ok('/lang/12/import'));

/*
 * Und die Uebersetzung vom Ziel zur Adresse.
 *
 * In der Marke steht eine abstrakte Form - "/teacher/unit/42" -, damit
 * handoff_target_ok() eng bleiben kann: Stuende dort die fertige Adresse
 * mit Fragezeichen und Punkt darin, muesste die Pruefung beides
 * durchlassen, und damit waere sie keine mehr.
 */
ok('Ein Ziel in der App wird zum Hash',
   str_ends_with((string) handoff_target_url('/lang/12/import'), '#/lang/12/import'));
ok('Ein Ziel im Lehrkraft-Bereich zu einer eigenen Adresse',
   str_ends_with((string) handoff_target_url('/teacher/unit/42'),
                 '/teacher/unit.php?id=42'),
   (string) handoff_target_url('/teacher/unit/42'));
ok('Und ein verbogenes Ziel zu gar nichts',
   handoff_target_url('https://boese.example/') === null);

/*
 * Und die Pruefung greift auch wirklich auf dem Weg.
 *
 * Das Ziel steht in der Datenbank und kommt von unserem eigenen Code - ein
 * fremdes koennte dort nur stehen, wenn jemand schon in der Datenbank ist.
 * Trotzdem: Ein Riegel, der nie angefasst wird, ist einer, von dem niemand
 * weiss, ob er haelt. Hier wird eine Marke von Hand verbogen.
 */
$boese = handoff_create($lehrerId, '/lang/1/import');
q("UPDATE login_handoffs SET target = 'https://boese.example/' WHERE token_hash = ?",
  [hash('sha256', $boese)]);

$ch = curl_init($base . '/?h=' . rawurlencode($boese));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT        => 30,
]);
curl_exec($ch);
$ortBoese = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
curl_close($ch);
ok('Ein verbogenes Ziel fuehrt nicht aus der Anwendung heraus',
   !str_contains($ortBoese, 'boese.example'), $ortBoese);

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

section('Das Buendel: alles auf einmal, Antworten zurueck');

/*
 * Bis hierher holte die App jede Frage einzeln - Vokabel ziehen, Ablenker
 * wuerfeln, Antwort einschicken, naechste Frage. Drei bis vier Runden uebers
 * Netz je Wort, auf die ein Kind wartet, und ein Aussetzer mitten darin
 * wurde zu "Bist du online?".
 *
 * Jetzt: einmal alles holen, ohne Netz ueben, Antworten als Strom von
 * Ereignissen zurueck.
 */

$buLang = makeLanguage($userId, 'Buendelisch' . bin2hex(random_bytes(2)));
$buUnit = makeUnit($userId, $buLang, 'Buendel-Unit');
$buWoerter = ['aa', 'bb', 'cc', 'dd', 'ee', 'ff'];
foreach ($buWoerter as $i => $w) {
    q('INSERT INTO vocab (unit_id, term_foreign, term_native, position) VALUES (?, ?, ?, ?)',
      [$buUnit, $w, 'de-' . $w, $i]);
}
// Nur die ersten vier sind auf - die letzten beiden duerfen nirgends auftauchen.
q('UPDATE units SET released_position = 4 WHERE id = ?', [$buUnit]);

$buIds = [];
foreach (qa('SELECT id, term_foreign FROM vocab WHERE unit_id = ? ORDER BY position',
            [$buUnit]) as $v) {
    $buIds[$v['term_foreign']] = (int) $v['id'];
}
// Zu "aa" ein Lueckensatz, damit das Buendel auch Saetze traegt.
q('INSERT INTO sentences (vocab_id, native_text, foreign_text, answer) VALUES (?, ?, ?, ?)',
  [$buIds['aa'], 'Ein deutscher Satz.', 'An ___ sentence.', 'aa']);

[$bu, $status] = apiCall('bundle', 'get');
ok('Das Buendel kommt in einem Stueck', $status === 200 && ($bu['ok'] ?? false) === true,
   'Status ' . $status);

$buVok = array_column($bu['vokabeln'] ?? [], 'f');
ok('Es traegt die freigegebenen Vokabeln',
   in_array('aa', $buVok, true) && in_array('dd', $buVok, true));
ok('Und die gesperrten nicht',
   !in_array('ee', $buVok, true) && !in_array('ff', $buVok, true),
   'sonst stuenden die naechsten Woerter im Geraet, bevor die Klasse sie sehen darf');
ok('Die Lueckensaetze sind dabei',
   in_array('An ___ sentence.', array_column($bu['saetze'] ?? [], 'f'), true));
ok('Und die Sprache, in deren Kurs dieses Konto ist',
   in_array($buLang, array_column($bu['sprachen'] ?? [], 'id'), true));
ok('Dazu die Schwelle, ab der etwas als gekonnt gilt',
   (int) ($bu['schwelle'] ?? 0) === KNOWN_THRESHOLD,
   'das Geraet rechnet mit, und zwar mit derselben Zahl');

/*
 * Und die Rueckrichtung. Der ganze Trick ist, dass es ein EREIGNISSTROM
 * ist: record_answer() ist eine reine Funktion aus (bisheriger Stand,
 * richtig/falsch). Wer dieselben Antworten in derselben Reihenfolge
 * nachspielt, bekommt denselben Stand - es gibt nichts zusammenzufuehren.
 */
$buE = static fn (string $wort, bool $richtig, string $kennung): array => [
    'e' => $kennung, 'v' => $buIds[$wort], 'm' => 'mc', 'r' => $richtig ? 1 : 0,
];

$marke = bin2hex(random_bytes(6));
[$antwort, $status] = apiCall('bundle', 'push', ['ereignisse' => [
    $buE('aa', true,  $marke . '-1'),
    $buE('aa', true,  $marke . '-2'),
    $buE('aa', true,  $marke . '-3'),
    $buE('bb', false, $marke . '-4'),
]]);
ok('Ein Stapel Antworten wird angenommen',
   $status === 200 && ($antwort['genommen'] ?? 0) === 4, (string) json_encode($antwort));

$standAa = q1('SELECT streak, correct_count, known_at FROM progress
                WHERE user_id = ? AND vocab_id = ? AND mode = ?',
              [$userId, $buIds['aa'], 'mc']);
ok('Dreimal richtig ergibt dieselbe Serie wie einzeln geschickt',
   (int) ($standAa['streak'] ?? 0) === 3 && (int) ($standAa['correct_count'] ?? 0) === 3);
ok('Und die Vokabel gilt danach als gekonnt', ($standAa['known_at'] ?? null) !== null,
   'KNOWN_THRESHOLD ist drei - nachgespielt gilt dieselbe Regel');

$standBb = q1('SELECT streak, wrong_count FROM progress
                WHERE user_id = ? AND vocab_id = ? AND mode = ?',
              [$userId, $buIds['bb'], 'mc']);
ok('Eine falsche Antwort zaehlt als falsch',
   (int) ($standBb['wrong_count'] ?? 0) === 1 && (int) ($standBb['streak'] ?? 9) === 0);

/*
 * Derselbe Stapel noch einmal - das passiert wirklich: Die Anfrage kam an,
 * die Antwort ging unterwegs verloren, das Geraet schickt noch einmal.
 * Ohne Gedaechtnis stuende "aa" danach bei sechs statt bei drei.
 */
[$zweimal, $status] = apiCall('bundle', 'push', ['ereignisse' => [
    $buE('aa', true, $marke . '-1'),
    $buE('aa', true, $marke . '-2'),
    $buE('aa', true, $marke . '-3'),
    $buE('bb', false, $marke . '-4'),
]]);
ok('Derselbe Stapel zweimal zaehlt nicht doppelt',
   ($zweimal['genommen'] ?? 9) === 0 && ($zweimal['doppelt'] ?? 0) === 4,
   json_encode($zweimal));
ok('Der Stand bleibt, wie er war',
   (int) qv('SELECT correct_count FROM progress
              WHERE user_id = ? AND vocab_id = ? AND mode = ?',
            [$userId, $buIds['aa'], 'mc']) === 3,
   'sonst gaelte eine Vokabel eine Runde zu frueh als gekonnt');

/*
 * Und die Grenze. Eine untergeschobene Kennung darf keinen Lernstand an
 * einer Vokabel anlegen, die dieses Konto gar nicht sehen darf - weder an
 * einer fremden noch an einer noch nicht freigegebenen.
 */
$buFremdLang = makeLanguage($otherId, 'Fremdbuendel' . bin2hex(random_bytes(2)));
$buFremdUnit = makeUnit($otherId, $buFremdLang, 'Fremde Buendel-Unit');
q('INSERT INTO vocab (unit_id, term_foreign, term_native, position) VALUES (?, ?, ?, 0)',
  [$buFremdUnit, 'geheim', 'geheim']);
q('UPDATE units SET released_position = 1 WHERE id = ?', [$buFremdUnit]);
$buFremdVokabel = (int) qv('SELECT id FROM vocab WHERE unit_id = ?', [$buFremdUnit]);

[$abgewiesen] = apiCall('bundle', 'push', ['ereignisse' => [
    ['e' => $marke . '-fremd', 'v' => $buFremdVokabel, 'm' => 'mc', 'r' => 1],
    ['e' => $marke . '-zu',    'v' => $buIds['ff'],    'm' => 'mc', 'r' => 1],
]]);
ok('Eine fremde Vokabel wird nicht angenommen',
   ($abgewiesen['fremd'] ?? 0) === 2 && ($abgewiesen['genommen'] ?? 9) === 0,
   json_encode($abgewiesen));
ok('Und es entsteht auch kein Lernstand dafuer',
   (int) qv('SELECT COUNT(*) FROM progress WHERE user_id = ? AND vocab_id IN (?, ?)',
            [$userId, $buFremdVokabel, $buIds['ff']]) === 0,
   'eine gesperrte Vokabel ist fuer dieses Konto so fremd wie eine aus einer anderen Schule');

[$mist] = apiCall('bundle', 'push', ['ereignisse' => [
    ['e' => $marke . '-mist', 'v' => $buIds['cc'], 'm' => 'erfunden', 'r' => 1],
]]);
ok('Eine erfundene Uebungsart wird uebergangen', ($mist['genommen'] ?? 9) === 0);

// Und ein anderes Konto bekommt sein eigenes Buendel, nicht dieses.
$buFremdJar = tempnam(sys_get_temp_dir(), 'vtbu');
$buAndere = apiAls($buFremdJar, static function (): array {
    apiCall('auth', 'login', ['username' => 'e2e_other', 'password' => 'geheim123']);
    [$d] = apiCall('bundle', 'get');
    return $d ?? [];
});
ok('Ein anderes Konto bekommt sein eigenes Buendel',
   !in_array('aa', array_column($buAndere['vokabeln'] ?? [], 'f'), true),
   'die Vokabeln haengen am Kurs, nicht am Konto, das fragt');
@unlink($buFremdJar);

q('DELETE FROM answer_receipts WHERE user_id = ?', [$userId]);

section('Saetze entstehen beim Freigeben');

/*
 * Bis hierher entstanden sie beim Einlesen, fuer die ganze Einheit - und
 * wenn hinterher etwas dazukam, stand ein Knopf "Saetze nachtragen (N)" da.
 * Der stellte eine Frage, die sich nicht stellt: Ein Lueckensatz wird
 * gebraucht, wenn ein Kind ihn ueben soll, und ueben kann es nur, was
 * aufgemacht ist.
 */
$sfLehrer = 'sflehr_' . bin2hex(random_bytes(3));
q('INSERT INTO users (school_id, username, display_name, password_hash, color, role, can_import)
   VALUES (?, ?, ?, ?, ?, ?, 1)',
  [(int) qv('SELECT school_id FROM users WHERE id = ?', [$userId]),
   $sfLehrer, 'Frau Satz', password_hash('lehrerin123', PASSWORD_DEFAULT),
   '#4f7cff', ROLE_TEACHER]);
$sfLehrerId = (int) db()->lastInsertId();

$sfKlasse = class_create((int) qv('SELECT school_id FROM users WHERE id = ?', [$userId]),
                         'Satz' . bin2hex(random_bytes(2)));
$sfKurs   = course_create(q1('SELECT * FROM users WHERE id = ?', [$sfLehrerId]),
                          'Satzisch' . bin2hex(random_bytes(2)), "\u{1F310}",
                          (int) $sfKlasse['id'], '');
$sfKursId = (int) $sfKurs['id'];
$sfLang   = (int) $sfKurs['language_id'];

q('INSERT INTO units (language_id, course_id, title, released_position, position)
   VALUES (?, ?, ?, 0, 1)', [$sfLang, $sfKursId, 'Satz-Unit']);
$sfUnit = (int) db()->lastInsertId();
foreach (['aa', 'bb', 'cc'] as $i => $w) {
    q('INSERT INTO vocab (unit_id, term_foreign, term_native, position) VALUES (?, ?, ?, ?)',
      [$sfUnit, $w, 'de-' . $w, $i]);
}

ok('Ohne Freigabe steht nichts an', vocab_without_sentences($sfUnit) === 0,
   vocab_without_sentences($sfUnit) . ' - was zu ist, wird nicht geuebt');

q('UPDATE units SET released_position = 2 WHERE id = ?', [$sfUnit]);
ok('Nach der Freigabe zweier Vokabeln stehen zwei an',
   vocab_without_sentences($sfUnit) === 2,
   (string) vocab_without_sentences($sfUnit));

/*
 * Und der Weg ueber die Oberflaeche: Freigeben stoesst den Lauf an. Die
 * Antwort geht dabei zuerst raus - die Saetze zu zwanzig Vokabeln dauern
 * eine halbe Minute, und solange soll niemand auf eine leere Seite sehen.
 */
q('UPDATE units SET released_position = 0, sentences_status = NULL WHERE id = ?', [$sfUnit]);

q('DELETE FROM login_attempts');
teacherLogin($sfLehrer, 'lehrerin123');
$res = teacherGet('unit.php?id=' . $sfUnit);
preg_match('/name="csrf" value="([a-f0-9]+)"/', $res['body'], $sfm);
$sfCsrf = $sfm[1] ?? '';

ok('Der Knopf "Saetze nachtragen" ist weg',
   !str_contains($res['body'], 'name="catch_up"'),
   'er fragte nach etwas, das von selbst geschieht');

$res = teacherRequest($base . '/teacher/unit.php?id=' . $sfUnit, [
    'release' => 3, 'unit_id' => $sfUnit, 'csrf' => $sfCsrf,
]);

/*
 * Geprueft wird der ANSTOSS, nicht das Ergebnis: Ob wirklich Saetze
 * entstehen, haengt am Modell und steht in tests/ai.php. Hier zaehlt, dass
 * die Freigabe den Lauf beansprucht hat - ohne das bliebe alles liegen.
 */
$sfStand = (string) qv('SELECT sentences_status FROM units WHERE id = ?', [$sfUnit]);
ok('Freigeben stoesst den Satzlauf an', $sfStand !== '', $sfStand ?: '(nichts)');
ok('Und die Meldung sagt es',
   str_contains($res['body'], 'entstehen gerade'),
   'sonst wartet die Lehrkraft auf etwas, von dem sie nichts weiss');

/*
 * Zuruecknehmen erzeugt nichts. Was zu ist, wird nicht geuebt - und ein
 * Lauf, der nichts zu tun hat, faende nichts vor.
 */
q('UPDATE units SET released_position = 3, sentences_status = NULL WHERE id = ?', [$sfUnit]);
$res = teacherRequest($base . '/teacher/unit.php?id=' . $sfUnit, [
    'release' => 0, 'unit_id' => $sfUnit, 'csrf' => $sfCsrf,
]);
ok('Zuruecknehmen stoesst keinen Lauf an',
   qv('SELECT sentences_status FROM units WHERE id = ?', [$sfUnit]) === null,
   (string) qv('SELECT sentences_status FROM units WHERE id = ?', [$sfUnit]));

/*
 * Und der eine Fall, in dem noch von Hand nachgeholt wird: ein Lauf, der
 * abgebrochen ist. Ohne diesen Knopf wuerde erst die naechste Freigabe es
 * wieder versuchen - und wer schon alles aufgemacht hat, haette gar keine
 * mehr.
 */
q('UPDATE units SET released_position = 3, sentences_status = ?, sentences_error = ?
    WHERE id = ?', [SENTENCE_FAILED, 'Probe', $sfUnit]);
$res = teacherGet('unit.php?id=' . $sfUnit);
ok('Nach einem Fehlschlag steht ein Knopf zum Wiederholen da',
   str_contains($res['body'], 'name="catch_up"')
   && str_contains($res['body'], 'Noch einmal versuchen'));

q('DELETE FROM users WHERE id = ?', [$sfLehrerId]);

section('Impressum, Datenschutz, Lizenzen');

require_once __DIR__ . '/../lib/markdown.php';

/*
 * Gewoehnliche Seiten vom Server, kein Teil der App: Sie muessen erreichbar
 * sein, BEVOR jemand angemeldet ist - wer sich anmelden soll, darf vorher
 * wissen, wer dahintersteht und was mit seinen Daten geschieht.
 */
foreach (legal_documents() as $k => $d) {
    ok('Es gibt ' . $d['titel'], is_file(__DIR__ . '/../' . $d['datei']),
       $d['datei'] . ' fehlt');

    $res = http($base . '/rechtliches.php?d=' . $k);
    ok($d['kurz'] . ' laesst sich ohne Anmeldung aufrufen', $res['status'] === 200,
       'Status ' . $res['status']);
    ok('Und traegt seinen Titel', str_contains($res['body'], h($d['titel'])));
    ok('Und die drei stehen nebeneinander',
       substr_count($res['body'], 'class="chip') === 3);
}

/*
 * Die Adresse waehlt aus einer festen Liste, sie benennt keine Datei.
 * Sonst stuende dort ein Weg, sich mit ?d=../config jede Datei des Servers
 * ausgeben zu lassen.
 */
foreach (['../config', '../../etc/passwd', 'config.php', 'README'] as $boese) {
    $res = http($base . '/rechtliches.php?d=' . rawurlencode($boese));

    /*
     * Zweierlei muss stimmen, und das zweite ist das wichtigere: Die Seite
     * sagt 404 UND zeigt das Impressum - sie versucht also gar nicht erst,
     * eine Datei mit diesem Namen zu lesen. Nur zu pruefen, dass nichts
     * Fremdes auslaeuft, pruefte die Wache nicht: Eine Fassung, die den
     * Namen aus der Adresse nimmt und ".md" anhaengt, kaeme damit durch.
     */
    ok('Ein erfundenes Dokument wird abgewiesen: ' . $boese,
       $res['status'] === 404, 'Status ' . $res['status']);
    ok('Und zeigt stattdessen das Impressum',
       str_contains($res['body'], 'Angaben gemäß')
       && !str_contains($res['body'], 'db_pass')
       && !str_contains($res['body'], '<?php'),
       mb_substr(strip_tags($res['body']), 0, 60));
}

/*
 * Und der Wandler macht aus Markdown Text, nicht Markup. Erst maskieren,
 * dann auszeichnen - anders herum liesse sich HTML einschleusen, und eine
 * dieser Dateien wird irgendwann jemand aus dem Netz zusammenkopieren.
 */
$gift = markdown_to_html('<script>alert(1)</script> [x](javascript:alert(2)) '
                       . '<img src=x onerror=alert(3)>');
ok('Der Markdown-Wandler laesst kein HTML durch',
   !str_contains($gift, '<script') && !str_contains($gift, '<img')
   && !str_contains($gift, 'javascript:'),
   $gift);

ok('Er kann Ueberschriften, Listen und Links',
   str_contains(markdown_to_html("## Titel\n\n* eins\n* zwei"), '<h3>Titel</h3>')
   && str_contains(markdown_to_html("* eins"), '<ul><li>')
   && str_contains(markdown_to_html('[VT](https://example.org)'),
                   'href="https://example.org"'));
ok('Und macht aus einer ersten Ueberschrift keine zweite <h1>',
   !str_contains(markdown_to_html('# Impressum'), '<h1>'),
   'die <h1> der Seite ist der Titel des Dokuments');

/*
 * Erreichbar von ueberall: im Einstellungsmenue, als Zeile ganz unten, und
 * auf beiden Anmeldeseiten. Wer noch kein Konto hat, kann kein Menue
 * oeffnen - deshalb gerade dort.
 */
$anmeldung = http($base . '/teacher/')['body'];
ok('Die Anmeldung der Lehrkraft zeigt sie',
   substr_count($anmeldung, 'rechtliches.php') === 3, $anmeldung ? '' : 'leer');

$kern = (string) file_get_contents(__DIR__ . '/../core.js');
ok('Die App hat sie im Einstellungsmenue',
   str_contains($kern, 'rechtsItems()') && str_contains($kern, 'rechtliches.php'));
ok('Und als Zeile ganz unten',
   str_contains($kern, 'export function rechtsZeile'));
ok('Die Anmeldung der App zeigt sie ebenfalls',
   str_contains((string) file_get_contents(__DIR__ . '/../views/login.js'),
                'rechtsZeile()'),
   'wer sich anmelden soll, darf vorher wissen, wer dahintersteht');
ok('Und die Startseite der App auch',
   str_contains((string) file_get_contents(__DIR__ . '/../views/languages.js'),
                'rechtsZeile()'));

q('DELETE FROM login_attempts');
teacherLogin($lehrerName2 ?? '', 'x');   // egal, nur um die Seite zu bekommen
$res = http($base . '/teacher/');
ok('Und jede Seite des Lehrkraft-Bereichs traegt die Zeile',
   str_contains($res['body'], 'class="rechtszeile"'));

/*
 * Die Lizenzen nennen, was wirklich mitgeliefert wird. Eine Liste, die ein
 * Paket vergisst, ist schlimmer als keine: Sie sieht nach Sorgfalt aus.
 */
$lizenzen = (string) file_get_contents(__DIR__ . '/../LIZENZEN.md');
$sperre   = json_decode((string) file_get_contents(__DIR__ . '/../composer.lock'), true);
$fehlend  = [];
foreach ($sperre['packages'] ?? [] as $paket) {
    if (!str_contains($lizenzen, (string) $paket['name'])) {
        $fehlend[] = (string) $paket['name'];
    }
}
ok('Jedes mitgelieferte Paket steht in den Lizenzen', $fehlend === [],
   implode(', ', $fehlend));
ok('Und die Schriften und die Fahnen auch',
   str_contains($lizenzen, 'Fredoka') && str_contains($lizenzen, 'Nunito')
   && str_contains($lizenzen, 'Twemoji') && str_contains($lizenzen, 'CC-BY 4.0'),
   'beide verlangen die Nennung der Herkunft');
// Und umgekehrt: Was nicht mehr mitkommt, steht auch nicht mehr darin.
// Roboto ging mit dem Anfangsbuchstaben auf dem App-Symbol.
ok('Keine Lizenz fuer eine Schrift, die nicht mehr beiliegt',
   str_contains($lizenzen, 'Roboto') === is_file(__DIR__ . '/../assets/Roboto-Bold.ttf'));

/*
 * Seit die Oberflaeche eigene Schriften mitliefert, gehoeren sie dazu - die
 * OFL verlangt, dass ihr Text bei jeder Weitergabe mitkommt, auch bei einer
 * ueber das Netz. Frueher stand hier, es werde gar keine Schrift
 * mitgeliefert; genau solche Saetze veralten still.
 */
ok('Auch die beiden Schriften der Oberflaeche stehen darin',
   str_contains($lizenzen, 'Fredoka') && str_contains($lizenzen, 'Nunito')
   && str_contains($lizenzen, 'SIL Open Font License'),
   'die OFL verlangt die Weitergabe ihres Textes');
ok('Und ihre Lizenztexte liegen wirklich bei',
   is_file(__DIR__ . '/../assets/fonts/OFL-Fredoka.txt')
   && is_file(__DIR__ . '/../assets/fonts/OFL-Nunito.txt'));
ok('Die Zusage, dass keine Schrift von fremden Servern kommt, steht noch da',
   str_contains($lizenzen, 'nicht von Google'),
   'sie ist der Grund, warum die Dateien hier liegen');

section('Die Reihenfolge der Lerneinheiten');

/*
 * Sie sortierten sich nach dem Anlegedatum, neueste zuerst. Das ist die
 * Reihenfolge, in der sie ENTSTANDEN sind, und die hat mit der Reihenfolge,
 * in der sie DRANKOMMEN, nichts zu tun: Wer Unit 7 vor Unit 3 fotografiert,
 * weil die Seite gerade aufgeschlagen war, bekam sie auch so vorgesetzt -
 * und die Klasse sah sie genauso.
 */
$roLehrer = 'rolehr_' . bin2hex(random_bytes(3));
q('INSERT INTO users (school_id, username, display_name, password_hash, color, role, can_import)
   VALUES (?, ?, ?, ?, ?, ?, 1)',
  [(int) qv('SELECT school_id FROM users WHERE id = ?', [$userId]),
   $roLehrer, 'Frau Reihe', password_hash('lehrerin123', PASSWORD_DEFAULT),
   '#4f7cff', ROLE_TEACHER]);
$roLehrerId = (int) db()->lastInsertId();

$roKlasse = class_create((int) qv('SELECT school_id FROM users WHERE id = ?', [$userId]),
                         'Reihe' . bin2hex(random_bytes(2)));
$roKurs = course_create(q1('SELECT * FROM users WHERE id = ?', [$roLehrerId]),
                        'Reihisch' . bin2hex(random_bytes(2)), "\u{1F310}",
                        (int) $roKlasse['id'], '');
$roKursId = (int) $roKurs['id'];
$roLang   = (int) $roKurs['language_id'];

/*
 * Drei Einheiten, absichtlich in der "falschen" Reihenfolge angelegt -
 * created_at zaehlt aufwaerts, die Namen nicht.
 */
$roIds = [];
foreach (['Unit 7', 'Unit 3', 'Unit 5'] as $titel) {
    q('INSERT INTO units (language_id, course_id, title, released_position, position)
       VALUES (?, ?, ?, 0, ?)',
      [$roLang, $roKursId, $titel, unit_next_position($roKursId)]);
    $roIds[$titel] = (int) db()->lastInsertId();
    // Ein paar Sekunden Abstand, damit created_at sich unterscheidet.
    q('UPDATE units SET created_at = DATE_ADD(created_at, INTERVAL ? SECOND) WHERE id = ?',
      [count($roIds), $roIds[$titel]]);
}

$reihe = static fn (): array => array_column(
    course_units_list($roKursId), 'title');

ok('Neue Lerneinheiten stehen hinten',
   $reihe() === ['Unit 7', 'Unit 3', 'Unit 5'],
   implode(', ', $reihe()) . ' - wer eine anlegt, sucht sie am Ende');

ok('Und zwar mit laufender Nummer',
   array_column(course_units_list($roKursId), 'position') === [1, 2, 3],
   implode(',', array_column(course_units_list($roKursId), 'position')));

// ---- Umsortieren.

$neueReihe = [$roIds['Unit 3'], $roIds['Unit 5'], $roIds['Unit 7']];
ok('Drei Einheiten lassen sich neu ordnen',
   course_units_reorder($roKursId, $neueReihe) === 3);
ok('Und stehen danach so da', $reihe() === ['Unit 3', 'Unit 5', 'Unit 7'],
   implode(', ', $reihe()));

/*
 * Was die Liste nicht nennt, geht nicht verloren. Zwei Lehrkraefte
 * sortieren gleichzeitig, eine legt dabei eine neue an - die faellt sonst
 * auf Position null und landet stillschweigend ganz oben.
 */
q('INSERT INTO units (language_id, course_id, title, released_position, position)
   VALUES (?, ?, ?, 0, ?)',
  [$roLang, $roKursId, 'Unit 9', unit_next_position($roKursId)]);
$roIds['Unit 9'] = (int) db()->lastInsertId();

course_units_reorder($roKursId, [$roIds['Unit 5'], $roIds['Unit 3']]);
ok('Was die Liste nicht nennt, haengt hinten an',
   $reihe() === ['Unit 5', 'Unit 3', 'Unit 7', 'Unit 9'],
   implode(', ', $reihe()));

/*
 * Und eine untergeschobene Kennung aendert nichts. Die Liste kommt aus dem
 * Browser, und was von dort kommt, darf nie bestimmen, WELCHE Zeilen
 * angefasst werden - nur, in welcher Reihenfolge die eigenen stehen.
 */
$roFremdUnit = makeUnit($otherId, makeLanguage($otherId, 'Fremdreihe' . bin2hex(random_bytes(2))),
                        'Fremde Reihe');
$vorherFremd = (int) qv('SELECT position FROM units WHERE id = ?', [$roFremdUnit]);
course_units_reorder($roKursId, [$roFremdUnit, $roIds['Unit 3']]);
ok('Eine fremde Lerneinheit wird uebergangen',
   (int) qv('SELECT position FROM units WHERE id = ?', [$roFremdUnit]) === $vorherFremd
   && count($reihe()) === 4,
   implode(', ', $reihe()));

// ---- Und die Kinder sehen dieselbe Reihenfolge.

$roKind = makeUser('rokind_' . bin2hex(random_bytes(3)), 'Reihekind');
course_add_member($roKursId, $roKind, 'student');
foreach ($roIds as $titel => $uid2) {
    q('INSERT INTO vocab (unit_id, term_foreign, term_native, position) VALUES (?, ?, ?, 0)',
      [$uid2, 'w-' . $titel, 'de-' . $titel]);
    q('UPDATE units SET released_position = 1 WHERE id = ?', [$uid2]);
}

$roJar = tempnam(sys_get_temp_dir(), 'vtreihe');
$ausSicht = apiAls($roJar, static function () use ($roKind, $roLang): array {
    apiCall('auth', 'login',
            ['username' => (string) qv('SELECT username FROM users WHERE id = ?', [$roKind]),
             'password' => 'geheim123']);
    [$liste] = apiCall('units', 'list', null, ['language_id' => $roLang]);
    [$buendel] = apiCall('bundle', 'get');
    return [
        'liste'   => array_column($liste['units'] ?? [], 'title'),
        'buendel' => array_column(array_filter($buendel['einheiten'] ?? [],
                        static fn (array $u): bool => $u['l'] === $roLang), 't'),
    ];
});
@unlink($roJar);

ok('Die Klasse sieht dieselbe Reihenfolge wie die Lehrkraft',
   $ausSicht['liste'] === $reihe(),
   implode(', ', $ausSicht['liste']) . ' gegen ' . implode(', ', $reihe()));
ok('Und der Vorrat bringt sie genauso mit',
   $ausSicht['buendel'] === $reihe(),
   implode(', ', $ausSicht['buendel']));

// ---- Der Weg ueber die Oberflaeche.

q('DELETE FROM login_attempts');
teacherLogin($roLehrer, 'lehrerin123');
$res = teacherGet('course.php?id=' . $roKursId);

ok('Jede Zeile traegt einen Anfasser',
   substr_count($res['body'], 'class="anfasser"') === 4,
   substr_count($res['body'], 'class="anfasser"') . ' statt 4');
ok('Und ist ziehbar', substr_count($res['body'], 'draggable="true"') === 4);
ok('Ohne Skript tun es zwei Pfeile je Zeile',
   substr_count($res['body'], 'form="sortierform"') === 8,
   substr_count($res['body'], 'form="sortierform"') . ' statt 8');
ok('Die erste Zeile kann nicht weiter nach oben',
   preg_match('/data-unit="' . $roIds['Unit 5'] . '".*?Nach oben.*?disabled/s',
              str_replace('disabled', 'disabled', $res['body'])) === 1
   || str_contains($res['body'], 'title="Nach oben" disabled'),
   'ein Knopf, der nichts tut, gehoert abgeblendet');
ok('Die Seite sagt, wozu die Reihenfolge gut ist',
   str_contains($res['body'], 'in der deine Klasse die Lerneinheiten'));

$res = teacherRequest($base . '/teacher/course.php?id=' . $roKursId, [
    'reorder_units' => '1', 'course_id' => $roKursId,
    'reihenfolge' => implode(',', [$roIds['Unit 9'], $roIds['Unit 7'],
                                   $roIds['Unit 5'], $roIds['Unit 3']]),
    'csrf' => (function () use ($roKursId): string {
        preg_match('/name="csrf" value="([a-f0-9]+)"/',
                   teacherGet('course.php?id=' . $roKursId)['body'], $m);
        return $m[1] ?? '';
    })(),
]);
ok('Umsortieren geht auch ohne Skript',
   $reihe() === ['Unit 9', 'Unit 7', 'Unit 5', 'Unit 3'],
   implode(', ', $reihe()));

/*
 * Und eine fremde Lehrkraft kann das nicht. Die Kursseite prueft auf die
 * Schule - wer nicht hineindarf, kommt gar nicht bis hierher.
 */
$roFremdeSchule = (int) qv('SELECT id FROM schools WHERE id <> ? LIMIT 1',
                           [(int) qv('SELECT school_id FROM users WHERE id = ?', [$roLehrerId])]);
if ($roFremdeSchule > 0) {
    q('UPDATE users SET school_id = ? WHERE id = ?', [$roFremdeSchule, $roLehrerId]);
    $vorherReihe = $reihe();
    teacherRequest($base . '/teacher/course.php?id=' . $roKursId, [
        'reorder_units' => '1', 'course_id' => $roKursId,
        'reihenfolge' => implode(',', array_values($roIds)), 'csrf' => 'egal',
    ]);
    ok('Eine Lehrkraft einer anderen Schule sortiert nichts um',
       $reihe() === $vorherReihe, implode(', ', $reihe()));
}

q('DELETE FROM users WHERE id IN (?, ?)', [$roLehrerId, $roKind]);

section('Sofort filtern statt abschicken');

/*
 * Die Suche im Admin war ein Formular: tippen, abschicken, warten, Seite
 * neu. Bei zweihundert Saetzen sucht man aber nicht einmal, sondern
 * zehnmal hintereinander - und jedesmal war der Bildschirm kurz weg.
 */
$satzSeite = http($base . '/admin/sentences.php')['body'];
ok('Das Suchfeld filtert im Browser',
   str_contains($satzSeite, 'data-filter-ziel="satzliste"'));
ok('Und die Zeilen sagen, wonach zu suchen ist',
   preg_match('/<tr data-suchtext="[^"]+"/', $satzSeite) === 1,
   'in den Zellen stehen Eingabefelder - deren Inhalt gehoert nicht zum Text der Zeile');
ok('Der Knopf fuehrt weiterhin in den ganzen Bestand',
   str_contains($satzSeite, 'Im ganzen Bestand suchen'),
   'der Sofortfilter sieht nur diese Seite - und ohne Skript ist er der einzige Weg');

$vocabSeite = http($base . '/admin/vocab.php')['body'];
ok('Auch die Vokabeln haben jetzt eine Suche',
   str_contains($vocabSeite, 'data-filter-ziel="vokabelliste"')
   || str_contains(
        (string) file_get_contents(__DIR__ . '/../admin/vocab.php'),
        'data-filter-ziel="vokabelliste"'),
   'bei zweihundert Vokabeln hiess "die eine finden" scrollen und lesen');

$bootJs = (string) file_get_contents(__DIR__ . '/../admin/_boot.php');
ok('Der Filter versteckt Zeilen, statt sie zu loeschen',
   str_contains($bootJs, 'zeile.hidden = !passt'),
   'wer das Suchwort wieder leert, soll alles wiederbekommen');
ok('Und sagt, wie viele von wie vielen zu sehen sind',
   str_contains($bootJs, "' von ' + zeilen.length"),
   'sonst haelt man eine gefilterte Liste fuer die ganze');

section('Keine Rede von Geld');

/*
 * Was die Bilderkennung kostet, geht den Betreiber etwas an - nicht die
 * Lehrkraft und schon gar nicht das Kind. Eine Rueckfrage, die "das kostet"
 * sagt, macht aus einer fachlichen Entscheidung eine finanzielle, und die
 * kann eine Lehrkraft gar nicht treffen.
 */
foreach (['teacher/course.php', 'teacher/unit.php', 'teacher/index.php',
          'teacher/classes.php', 'teacher/class.php', 'teacher/konto.php',
          'teacher/teacher.js', 'core.js'] as $datei) {
    $quelle = (string) file_get_contents(__DIR__ . '/../' . $datei);
    // Nur sichtbarer Text, keine Kommentare - die duerfen erklaeren, warum.
    $ohneKommentar = preg_replace('#/\*.*?\*/|^\s*//.*$#ms', '', $quelle) ?? $quelle;
    ok($datei . ' spricht nicht von Kosten',
       preg_match('/kostet|bezahlt|Monatsbudget|Rechnung|\bEUR\b|USD/i', $ohneKommentar) !== 1,
       'das geht den Betreiber etwas an, nicht die Lehrkraft');
}

ok('Auch die Absage der Bilderkennung nennt keine Zahlen',
   !str_contains((string) file_get_contents(__DIR__ . '/../lib/cost.php'),
                 'Monatsbudget f\u00fcr die Bilderkennung ist'),
   'sie sagt, dass es gerade nicht geht - nicht, warum es Geld kostet');

section('Hell, dunkel, automatisch');

require_once __DIR__ . '/../lib/thema.php';

/*
 * Die Wahl ist eine Einstellung des Geraets, kein Datensatz auf dem Server:
 * Wer die App auf dem Tablet dunkel mag und am Rechner hell, soll das haben
 * koennen, ohne dass eines das andere umstellt.
 */
$cssT = (string) file_get_contents(__DIR__ . '/../style.css');

ok('Ohne Wahl entscheidet das Geraet',
   preg_match('/@media \(prefers-color-scheme: dark\)/', $cssT) === 1);
ok('Eine Wahl schlaegt das Geraet',
   preg_match('/:root\[data-theme="dark"\]\s*\{/', $cssT) === 1
   && preg_match('/:root\[data-theme="light"\]/', $cssT) === 1);
ok('Und "hell" bleibt hell, auch auf einem dunklen Geraet',
   preg_match('/@media \(prefers-color-scheme: dark\)\s*\{\s*(?:\/\*.*?\*\/\s*)?'
              . ':root:not\(\[data-theme="light"\]\)/s', $cssT) === 1,
   'ohne die Wache gewaenne die Medienabfrage');
ok('Der Browser faerbt seine eigenen Teile mit',
   substr_count($cssT, 'color-scheme:') >= 3,
   'sonst steht ein weisser Rollbalken neben einer dunklen Seite');

/*
 * Und die Wahl greift, BEVOR das erste Bild steht. Als Modul ginge das
 * nicht - Module laufen nach dem Aufbau, und dann blitzt eine halbe
 * Sekunde die helle Seite auf, bevor sie dunkel wird.
 */
$skript = thema_kopf_skript();
ok('Das Kopfskript liest die Wahl', str_contains($skript, 'vt-thema')
   && str_contains($skript, 'data'));
ok('Und faellt weich, wenn der Speicher gesperrt ist',
   str_contains($skript, 'catch'),
   'in einem privaten Fenster wirft schon der Zugriff auf localStorage');

foreach (['/' => 'die App', '/teacher/' => 'der Lehrkraft-Bereich'] as $pfad => $was) {
    $res = $pfad === '/' ? http($base . '/') : teacherGet('index.php');
    ok('Es steht im Kopf - ' . $was,
       str_contains($res['body'], 'vt-thema')
       && strpos($res['body'], 'vt-thema') < strpos($res['body'], '</head>'),
       'nach </head> waere es zu spaet');
}

/*
 * Dieselben drei Knoepfe in beiden Bereichen - der eine baut sie in PHP,
 * der andere in JavaScript. Zwei Fassungen desselben Markups sind ein
 * Risiko; hier steht der Riegel.
 */
$phpWahl = thema_wahl_html();
$jsWahl  = (string) file_get_contents(__DIR__ . '/../menue.js');

foreach (['hell', 'dunkel', 'auto'] as $wahl) {
    ok('Beide Fassungen kennen "' . $wahl . '"',
       str_contains($phpWahl, 'data-thema="' . $wahl . '"')
       && str_contains($jsWahl, 'data-thema="' . $wahl . '"'));
}
ok('Und beide nennen sie gleich',
   str_contains($phpWahl, '> Hell<') && str_contains($jsWahl, '> Hell')
   && str_contains($phpWahl, '> Dunkel<') && str_contains($jsWahl, '> Dunkel')
   && str_contains($phpWahl, '> Automatisch<') && str_contains($jsWahl, '> Automatisch'));

/*
 * Noch einmal anmelden: Ein Abschnitt weiter oben hat die Sitzung dieser
 * Lehrkraft beendet, und von der Anmeldeseite laesst sich kein Menue
 * pruefen - dort gibt es keines.
 */
/*
 * Eine eigene Lehrkraft fuer diesen Abschnitt.
 *
 * Die aus dem Lehrkraft-Abschnitt gibt es hier nicht mehr - er raeumt sein
 * Konto am Ende weg. Und die Anmeldebremse weiter oben hat Fehlversuche
 * erzeugt; sie zaehlt je Konto UND je Adresse, und alle Laeufe dieser Suite
 * kommen von derselben Adresse.
 */
q('DELETE FROM login_attempts');
$themaLehrer = 'themalehr_' . bin2hex(random_bytes(3));
q('INSERT INTO users (school_id, username, display_name, password_hash, color, role, can_import)
   VALUES (?, ?, ?, ?, ?, ?, 1)',
  [(int) qv('SELECT school_id FROM users WHERE id = ?', [$userId]),
   $themaLehrer, 'Frau Farbe',
   password_hash('lehrerin123', PASSWORD_DEFAULT), '#4f7cff', ROLE_TEACHER]);
$themaLehrerId = (int) db()->lastInsertId();

teacherLogin($themaLehrer, 'lehrerin123');
$res = teacherGet('index.php');
ok('Der Lehrkraft-Bereich hat sie im Einstellungsmenue',
   preg_match('/id="menuRechts".*?class="themawahl".*?name="teacher_logout"/s',
              $res['body']) === 1,
   'zwischen dem Konto und dem Abmelden');

section('Die Menues in der Kinderansicht');

/*
 * Sie standen nur im Lehrkraft-Bereich. In der Kinderansicht hiess Kurs
 * wechseln: zurueck, zurueck, antippen - und die Einstellungen lagen hinter
 * einem Zahnrad, das es nur auf der Startseite gab. Wer mitten im Ueben die
 * Farben umstellen wollte, musste erst herausfinden, wo das geht.
 */
$kern = (string) file_get_contents(__DIR__ . '/../core.js');

ok('Die Leiste traegt beide Schubladen',
   str_contains($kern, 'id="menuLinks"') && str_contains($kern, 'id="menuRechts"'));
ok('Links die eigenen Kurse',
   str_contains($kern, 'Meine Kurse') && str_contains($kern, 'href="#/lang/'));
ok('Und der offene Kurs steht darin markiert',
   str_contains($kern, "' on' : ''") && str_contains($kern, 'aria-current="page"'));
ok('Rechts Profil, Passwort, Farben und Abmelden',
   str_contains($kern, 'href="#/konto"')
   && str_contains($kern, 'href="#/konto/passwort"')
   && str_contains($kern, 'themaWahlHtml()')
   && str_contains($kern, 'data-nav-logout'));

ok('Sie stehen in jeder Ansicht, nicht nur auf der Startseite',
   preg_match('/export function topbar\([^)]*\)\s*\{\s*return `\s*<div class="topbar">\s*'
              . '\$\{navLinksHtml\(\)\}/s', $kern) === 1,
   'topbar() baut sie, und topbar() ruft jede Ansicht');
ok('Und werden nach jedem Zeichnen verdrahtet',
   preg_match('/export function render\(html\)\s*\{.*?navAktivieren\(app\);/s',
              $kern) === 1,
   'sonst waere es achtzehnmal dieselbe Zeile, und die neunzehnte fehlte');

ok('Ohne Anmeldung steht dort nichts',
   substr_count($kern, "if (!VT.user) return '';") === 3,
   'auf der Anmeldeseite gibt es weder Kurse noch ein Konto noch eine Serie');

section('Schriften und Wortzeichen');

$stil = (string) file_get_contents(__DIR__ . '/../style.css');

/*
 * Die Schriften liegen hier und werden nicht von Google geholt.
 *
 * Ein eingebundenes Stilblatt von fonts.googleapis.com schickte die Adresse
 * jedes Kindes bei jedem kalten Start dorthin - in einer Schule nicht zu
 * rechtfertigen. Und ohne Netz gaebe es dann gar keine Schrift, obwohl diese
 * App ausdruecklich weiterlaufen soll.
 */
ok('Keine Schrift von einem fremden Server',
   preg_match('#(?:@import|url\()[^;)]*fonts\.(?:googleapis|gstatic)\.com#i', $stil) !== 1
   && !str_contains((string) file_get_contents(__DIR__ . '/../index.php'), 'fonts.googleapis.com'),
   'die Adresse jedes Kindes ginge sonst an Google');

foreach (['fredoka', 'nunito'] as $schrift) {
    $r = http($base . '/assets/fonts/' . $schrift . '.woff2');
    ok("Die Schrift $schrift wird ausgeliefert", $r['status'] === 200, (string) $r['status']);
    ok("Und $schrift liegt als woff2 vor",
       str_starts_with($r['body'], 'wOF2'), substr($r['body'], 0, 4));
    ok("Der Lizenztext zu $schrift liegt bei",
       is_file(__DIR__ . '/../assets/fonts/OFL-' . ucfirst($schrift) . '.txt'));
}

ok('Beide Familien sind als @font-face erklaert',
   substr_count($stil, '@font-face') >= 2
   && str_contains($stil, "assets/fonts/fredoka.woff2")
   && str_contains($stil, "assets/fonts/nunito.woff2"));
ok('Und sie blockieren das erste Bild nicht',
   substr_count($stil, 'font-display: swap') >= 2,
   'ohne swap schaut ein Kind auf eine leere Seite');

// Fredoka fuer Ueberschriften und Knoepfe, Nunito fuer alles zum Lesen.
ok('Ueberschriften stehen in Fredoka',
   preg_match('/h1,\s*h2,\s*h3\s*\{[^}]*--schrift-kopf/s', $stil) === 1);
ok('Knoepfe ebenso',
   preg_match('/\.btn\s*\{.*?--schrift-kopf.*?\}/s', $stil) === 1);

/*
 * Und die Vokabeln ausdruecklich NICHT. Eine runde Anzeigeschrift ueber
 * franzoesischen Wortformen macht das Vergleichen schwerer, nicht leichter -
 * und genau darum geht es beim Ueben.
 */
ok('Die Antwortknoepfe bleiben bei der Leseschrift',
   preg_match('/\.option\s*\{[^}]*\}/s', $stil, $om) === 1
   && !str_contains($om[0], '--schrift-kopf'),
   'dort steht eine Vokabel, kein Knopftext');

// Das Wortzeichen.
$logo = http($base . '/assets/vokidoki_logo.svg');
ok('Das Wortzeichen wird ausgeliefert', $logo['status'] === 200, (string) $logo['status']);
ok('Das V ist Voki selbst, in seinem Gruen',
   stripos($logo['body'], '#AFD535') !== false);
ok('Es holt nichts von aussen nach - kein Bild, keine Schrift',
   !str_contains($logo['body'], '<image') && !str_contains($logo['body'], 'font-family'));
/*
 * "okidoki" steht als Pfad in der Datei und nicht als <text>: Ein <text> in
 * einem ueber <img> eingebundenen SVG faende die Schrift der Seite nicht und
 * faellt auf irgendeine Systemschrift zurueck - ausgerechnet beim Namen der App.
 */
ok('Und "okidoki" steht als Pfad darin, nicht als Text',
   !str_contains($logo['body'], '<text'),
   'ein <text> im <img> faende die Schrift nicht');

$loginJs = (string) file_get_contents(__DIR__ . '/../views/login.js');
ok('Die Anmeldeseite zeigt das Wortzeichen',
   str_contains($loginJs, 'assets/vokidoki_logo.svg') && str_contains($loginJs, 'class="logo"'));
// Die Datei selbst traegt keinen Namen - fuer Vorleseprogramme steht er am <img>.
ok('Mit seinem Namen fuer Vorleseprogramme',
   preg_match('/vokidoki_logo\.svg"\s*alt="Vokidoki"/', $loginJs) === 1);

section('Freies Üben');

$freiQ = http($base . '/views/frei.js')['body'];
$appQ  = http($base . '/app.js')['body'];

/*
 * Zwei Wege hinein: von einer Lerneinheit direkt, vom Kurs ueber die
 * Auswahl. Die gewaehlten Einheiten stehen in der Adresse und nicht in
 * einer Variablen - so ueberlebt eine Runde das Neuladen.
 */
ok('Es gibt einen Weg von der Lerneinheit',
   str_contains(http($base . '/views/unit.js')['body'], 'data-frei='));
ok('Und einen vom Kurs ueber die Auswahl',
   str_contains(http($base . '/views/language.js')['body'], '/frei/waehlen/'));
ok('Beide Adressen sind verdrahtet',
   str_contains($appQ, 'frei\/waehlen') && str_contains($appQ, 'freiView'));
ok('Die Auswahl stellt die Frage',
   str_contains($freiQ, 'Welche Lerneinheiten sollen geübt werden?'));
ok('Mit einem Haken je Lerneinheit', str_contains($freiQ, 'class="wahlbox"'));

/*
 * DAS WICHTIGSTE: Freies Ueben ruehrt den Lernstand nicht an.
 *
 * Geuebt wird alles, auch was laengst sitzt, und ein Fehler beim lockeren
 * Wiederholen soll keine Serie einreissen, die ueber Wochen entstanden ist.
 * Deshalb geht es nicht durch record_answer(), sondern durch einen eigenen,
 * schlanken Weg, der nur den Tag verbucht.
 */
ok('Es geht nicht durch antwortMerken()',
   !str_contains($freiQ, 'antwortMerken('),
   'das waere der Weg, der den Lernstand fortschreibt');
ok('Sondern durch freiMerken()', str_contains($freiQ, 'freiMerken('));

$vorratQ = http($base . '/vorrat.js')['body'];
ok('Und das schickt ein eigenes Ereignis',
   preg_match("/k: 'frei'/", $vorratQ) === 1);
ok('Ohne Lernstand, aber mit Vokabel',
   preg_match("/k: 'frei', v: Number\(vocabId\)/", $vorratQ) === 1,
   'an der Vokabel prueft der Server, ob das Konto antworten darf');

$bundleQ = (string) file_get_contents(__DIR__ . '/../api/bundle.php');
ok('Der Server kennt das Ereignis', str_contains($bundleQ, "\$art === 'frei'"));
ok('Und verbucht nur den Tag',
   preg_match('/art === .frei.*?streak_verbuchen\([^;]*false\)/s', $bundleQ) === 1,
   'kein record_answer() - der Lernstand bleibt, wie er ist');
ok('Die Vokabel wird trotzdem geprueft',
   preg_match('/art === .frei.*?isset\(.{0,12}erlaubt/s', $bundleQ) === 1);
ok('Und eine Quittung gibt es auch',
   preg_match('/art === .frei.*?bundle_quittung/s', $bundleQ) === 1,
   'sonst zaehlte ein zweimal geschickter Stapel doppelt');

/*
 * Die Runde hat kein Ende - deshalb zieht sie auch nicht nur offene
 * Vokabeln. Ein Filter auf "noch nicht gekonnt" liesse sie leerlaufen.
 */
ok('Gezogen wird aus allem, auch aus Gekonntem',
   !str_contains(substr($vorratQ, strpos($vorratQ, 'export function frageFrei'), 2000), '.k !== 1'),
   'sonst waere die Runde nach zwanzig Antworten zu Ende');

// Die Serie der Runde: laufend, Balken, Rekord.
foreach ([['z-richtig', 'links die richtigen Antworten'],
          ['z-folge',   'die laufende Serie'],
          ['z-balken',  'den Balken dazwischen'],
          ['z-beste',   'den Rekord der Runde'],
          ['z-quote',   'rechts die Trefferquote']] as [$id, $was]) {
    ok("Die Leiste zeigt $was", str_contains($freiQ, 'id="' . $id . '"'));
}
ok('Der Voki steht vor den Richtigen', str_contains($freiQ, 'voki-mini.svg'));
ok('In dieser Reihenfolge',
   preg_match('/z-richtig.*z-folge.*z-balken.*z-beste.*z-quote/s', $freiQ) === 1);

// Von der Lerneinheit aus fuehrt der Pfeil zurueck zur Lerneinheit.
$appQ  = (string) file_get_contents(__DIR__ . '/../app.js');
$unitQ = (string) file_get_contents(__DIR__ . '/../views/unit.js');
ok('Die Lerneinheit startet ihre eigene Adresse',
   str_contains($unitQ, 'go(`/unit/${frei.dataset.frei}/frei`)'));
ok('Und die nimmt die Lerneinheit als Ziel des Zurueck-Pfeils mit',
   str_contains($appQ, '(id) => freiView(id, `/unit/${id}`)'));
ok('Eine Tuer hinaus gibt es nicht mehr - der Zurueck-Pfeil tut dasselbe',
   !str_contains($freiQ, 'id="raus"'));
ok('Der Lueckentext ist derselbe Bildschirm wie in der Lueckentext-Uebung',
   str_contains($freiQ, "import { lueckeZeigen } from './cloze.js';")
   && !str_contains($freiQ, 'class="cloze-input"'));

// Die Meilensteine.
ok('Gelobt wird alle 25 Richtigen', str_contains($freiQ, 'LOB_RICHTIGE = 25'));
ok('Und alle 5 in Folge', str_contains($freiQ, 'LOB_FOLGE = 5'));
ok('Darunter steht, wofuer',
   str_contains($freiQ, 'Richtige!') && str_contains($freiQ, 'in Folge'));
ok('Faellt beides zusammen, gewinnt das seltenere',
   preg_match('/LOB_RICHTIGE === 0\) \{.*?return;/s', $freiQ) === 1,
   'wer bei der 25. auch fuenf in Folge hat, soll die 25 lesen');

ok('Jede richtige Antwort klingt', str_contains($freiQ, 'babing()'));
ok('Und die Zahlen springen mit einer Bewegung',
   str_contains($freiQ, 'zahlAktualisieren('));

// Der Zeitgeber darf nicht ueber eine andere Ansicht zeichnen.
ok('Nach dem Verlassen schaltet nichts mehr weiter',
   str_contains($freiQ, 'location.hash !== runde.adresse'),
   'der Zurueck-Pfeil wechselt die Adresse, ohne die Ansicht zu fragen');

// Die Hantel: als SVG, weil es dafuer kein Emoji gibt.
ok('Das Symbol ist eine Hantel', str_contains($freiQ, 'export function hantel'));
preg_match('/function hantel\(.*?\n}/s', $freiQ, $hm);
ok('Und zwar gezeichnet, nicht als Emoji',
   str_contains($hm[0] ?? '', '<svg')
   && preg_match('/[\x{1F300}-\x{1FAFF}]/u', $hm[0] ?? '') !== 1,
   'im Markup steht kein Emoji - im Kommentar darueber darf eines stehen');

section('Belohnung: Punkt, Konfetti, Feuerwerk');

$quizQ  = http($base . '/views/quiz.js')['body'];
$luecke = http($base . '/views/cloze.js')['body'];
// Eigene Namen: weiter oben heisst $kern mal eine Zeichenkette und mal die
// ganze Antwort - hier soll nichts davon abhaengen.
$kernQ = (string) file_get_contents(__DIR__ . '/../core.js');
$stilQ = (string) file_get_contents(__DIR__ . '/../style.css');

/*
 * Die drei Punkte standen auf dem Stand VOR der Antwort und rueckten erst
 * mit der naechsten Frage nach. Wer zweimal richtig lag, sah zwei Punkte -
 * und beim dritten Mal, dem Augenblick, auf den es ankommt, immer noch zwei.
 */
foreach (['Üben' => $quizQ, 'Lückentext' => $luecke] as $wo => $q) {
    ok("Der Punkt springt sofort an ($wo)",
       str_contains($q, 'punkteAktualisieren(document, result.streak)'));
    ok("Und faellt bei einer falschen Antwort zurueck ($wo)",
       str_contains($q, 'punkteAktualisieren(document, 0)'));
    ok("Konfetti, wenn die Vokabel sitzt ($wo)",
       str_contains($q, 'if (result.newly_learned) konfetti();'),
       'beim dritten Mal hintereinander, nicht bei jedem "gekonnt"');
    /*
     * Und zwar NACH dem Zeichnen: render() macht ein laufendes Feuerwerk
     * aus, damit es bei jedem Wechsel der Ansicht von selbst aufhoert.
     * Stuende der Aufruf davor, loeschte die eigene Seite ihn sofort wieder.
     */
    ok("Feuerwerk, wenn die Lerneinheit steht ($wo)",
       preg_match('/function showFinished\(.*?feuerwerk\(\);/s', $q) === 1);
    ok("Und zwar erst nach dem Zeichnen ($wo)",
       preg_match('/function showFinished\(.*?render\(.*?feuerwerk\(\);/s', $q) === 1,
       'sonst loescht render() es sofort wieder');
}

/*
 * Und die Zeit bis zur naechsten Frage bleibt, wie sie war. Eine Feier, die
 * den Ablauf verlangsamt, ist keine Belohnung mehr, sondern eine Bremse.
 */
ok('Im Quiz bleibt es bei 700 ms', str_contains($quizQ, 'NEXT_DELAY_CORRECT = 700'));
ok('Im Lueckentext bei 900 ms', str_contains($luecke, 'NEXT_DELAY_CORRECT = 900'));

/*
 * Konfetti und Feuerwerk haengen an <body>, nicht in der Ansicht: Die
 * naechste Frage wird schon 700 ms spaeter gezeichnet, und render() ersetzt
 * den ganzen Inhalt von #app. In der Ansicht waeren sie mitten im Flug weg.
 */
ok('Die Feier haengt an <body>',
   preg_match('/function buehne\([^)]*\)\s*\{.*?document\.body\.appendChild/s', $kernQ) === 1);
ok('Und laesst jeden Druck durch',
   preg_match('/\.feier\s*\{[^}]*pointer-events:\s*none/s', $stilQ) === 1,
   'sonst faenge sie den Druck auf die naechste Antwort ab');
ok('Sie raeumt sich selbst wieder weg',
   str_contains($kernQ, 'function abraeumen'));

// Das Lob: mehrere, damit dasselbe Wort nicht beim fuenften Mal steht.
preg_match('/const LOB = \[(.*?)\];/s', $kernQ, $lm);
preg_match_all("/'([^']+)'/", $lm[1] ?? '', $worte);
ok('Es gibt mehrere Lobworte', count($worte[1] ?? []) >= 8,
   count($worte[1] ?? []) . ' Stueck');
ok('Und alle sind kurz und mit Ausrufezeichen',
   ($worte[1] ?? []) !== [] && array_filter($worte[1],
       static fn (string $w): bool => !str_ends_with($w, '!') || mb_strlen($w) > 18) === [],
   implode(' / ', $worte[1] ?? []));
ok('Zweimal dasselbe hintereinander wird vermieden',
   str_contains($kernQ, 'letztesLob'));

// Die Punkt-Animation waechst ueber transform, nicht ueber die Groesse.
ok('Der Punkt waechst und faellt zurueck',
   preg_match('/@keyframes punktAuf\s*\{[^}]*transform:\s*scale/s', $stilQ) === 1);
ok('Und zwar ueber transform - sonst zappelt die Reihe',
   preg_match('/@keyframes punktAuf\s*\{(?:(?!\}\s*\n).)*?(width|height):/s', $stilQ) !== 1);

section('Der Kalender im Konto');

$konto = http($base . '/views/profile.js')['body'];
ok('Der Kalender steht in Wochen', str_contains($konto, 'monatsgitter'));
ok('Sieben Spalten',
   preg_match('/\.monatsgitter\s*\{[^}]*grid-template-columns:\s*repeat\(7,/s', $stilQ) === 1);
/*
 * Montag ist die erste Spalte. getUTCDay() zaehlt ab Sonntag, deshalb der
 * Versatz - ein Kalender, der am Sonntag anfaengt, liest sich hier falsch.
 */
ok('Montag ist die erste Spalte',
   str_contains($konto, '.getUTCDay() + 6) % 7'));
ok('Gerechnet wird in UTC',
   str_contains($konto, 'Date.UTC('),
   'sonst verliert der Kalender im Oktober einen Tag');
ok('Es gibt Knoepfe fuer vor und zurueck',
   str_contains($konto, 'monat-zurueck') && str_contains($konto, 'monat-vor'));
ok('In den Kaesten steht die Zahl der richtigen Antworten',
   str_contains($konto, 'Math.min(c, 999)'));

require_once __DIR__ . '/../lib/streak.php';
ok('Zwoelf Monate zurueck', STREAK_KALENDER_MONATE === 12);
$stand = streak_stand($userId);
foreach (['seit', 'heute', 'monate', 'tage'] as $feld) {
    ok("Der Stand nennt $feld", array_key_exists($feld, $stand));
}
ok('Und "seit" ist das Anlegedatum des Kontos',
   $stand['seit'] === (string) qv('SELECT DATE(created_at) FROM users WHERE id = ?', [$userId]),
   $stand['seit']);

/*
 * Die Huelle bekommt den Kalender NICHT mit. Sie wird nie zwischengespeichert
 * und bei jedem Seitenaufruf neu gebaut; ein Jahr Kalender darin waere bei
 * jedem Klick wieder dabei. Das Buendel holt ihn - es liegt im Geraet.
 */
ok('Die Huelle laedt den Kalender nicht mit',
   str_contains((string) file_get_contents(__DIR__ . '/../index.php'),
                'streak_stand((int) $user[\'id\'], false)'));
ok('Das Buendel dagegen schon',
   str_contains((string) file_get_contents(__DIR__ . '/../api/bundle.php'),
                'streak_stand($uid)'));
ok('Ohne Kalender ist der Stand klein',
   count(streak_stand($userId, false)['tage']) === 0);

// Was aelter ist als die Historie, wird weggeraeumt.
ok('Alte Tage werden weggeraeumt', function_exists('streak_aufraeumen'));
ok('Und zwar selten, nebenbei',
   preg_match('/function streak_aufraeumen\(\)[^}]*random_int/s',
              (string) file_get_contents(__DIR__ . '/../lib/streak.php')) === 1,
   'dieses Projekt hat keinen Cron');

section('Der Ton klingt wie ein Gloeckchen');

/*
 * Hier stand einmal ein Dreieckton mit einer Huellkurve von 0,28 Sekunden -
 * ein Piepser, abgeschnitten, bevor er klingen konnte. Zwei Dinge machen
 * daraus eine Glocke, und beide lassen sich hier festhalten.
 */
preg_match('/const GLOCKE = \[(.*?)\];/s', $kern, $gm);
ok('Ein Anschlag besteht aus mehreren Teiltoenen', ($gm[1] ?? '') !== '');

preg_match_all('/\[\s*([\d.]+),/', $gm[1] ?? '', $vm);
$verhaeltnisse = array_map('floatval', $vm[1] ?? []);
ok('Und zwar aus mindestens dreien', count($verhaeltnisse) >= 3,
   count($verhaeltnisse) . ' Teiltoene');

/*
 * Das Entscheidende: Die Verhaeltnisse sind NICHT ganzzahlig. Waeren sie es,
 * klaenge es nach Orgelpfeife - eine Glocke lebt davon, dass ihre Teiltoene
 * neben der Obertonreihe liegen.
 */
$ganzzahlig = array_values(array_filter(
    array_slice($verhaeltnisse, 1),
    static fn (float $v): bool => abs($v - round($v)) < 0.05,
));
ok('Die Teiltoene liegen neben der Obertonreihe', $ganzzahlig === [],
   implode(', ', $ganzzahlig) . ' ist ganzzahlig - das klingt nach Orgelpfeife');

preg_match('/const NACHHALL = ([\d.]+);/', $kern, $nm);
ok('Und der Grundton klingt ueber eine Sekunde nach',
   (float) ($nm[1] ?? 0) >= 1.0, ($nm[1] ?? '0') . ' Sekunden');

/*
 * Der Schluss geht linear auf die Null. Ein exponentieller Verlauf erreicht
 * sie nie, und ein Oszillator, der bei einem Restwert abgeschaltet wird,
 * knackt - genau das war am alten Ton zu hoeren.
 */
ok('Der Ausklang endet wirklich bei null',
   str_contains($kern, 'linearRampToValueAtTime(0,'),
   'sonst wird der Ton bei einem Restwert abgeschnitten und knackt');
ok('Und der Oszillator laeuft bis dahin weiter',
   preg_match('/stop\(aus \+ 0\.04\)/', $kern) === 1);

// Eine gemeinsame Summe, damit sich schnelle Antworten nicht aufaddieren.
ok('Alle Anschlaege laufen ueber einen gemeinsamen Regler',
   str_contains($kern, 'summe = hoerer.createGain()')
   && str_contains($kern, 'connect(summe)'),
   'sonst uebersteuert es, wenn zwei Toene uebereinanderliegen');

section('Farbe hat nur, wer heute gelernt hat');

/*
 * Drei von vier Lagen sind grau - auch die, in der Voki noch froh ist.
 * Die Farbe ist die Belohnung, nicht die Grundeinstellung.
 */
ok('Auch der frohe Voki ist grau, solange der Tag offen ist',
   preg_match('/\.seriebtn\.lage-offen \.serievoki,\s*'
              . '\.seriebtn\.lage-gefahr \.serievoki,\s*'
              . '\.seriebtn\.lage-aus \.serievoki\s*\{[^}]*grayscale\(1\)/s', $stil) === 1,
   'nur lage-heute bekommt Farbe');
ok('Und bei "offen" bleibt es der frohe Voki',
   str_contains($kern, "s.lage === 'heute' || s.lage === 'offen'"),
   'verloren ist noch nichts - es fehlt nur die Farbe');

section('Die Serie haelt still, solange ihr Schema fehlt');

/*
 * Das Fenster zwischen FTP-Upload und Schemaaenderung ist hier kein Unfall,
 * sondern gewollt: Der Code geht sofort live, die Aenderung laeuft erst auf
 * Knopfdruck im Selbsttest. In dieser Zeit gibt es learn_days noch nicht -
 * und eine angemeldete Seite darf deswegen nicht umfallen.
 */
require_once __DIR__ . '/../lib/streak.php';
require_once __DIR__ . '/../lib/progress.php';
require_once __DIR__ . '/../lib/schema.php';

// Irgendeine Vokabel dieses Kontos - welche, ist gleichgueltig.
$serieVokabel = (int) qv(
    'SELECT v.id FROM vocab v
       JOIN units u ON u.id = v.unit_id
      WHERE u.language_id = ? ORDER BY v.id LIMIT 1',
    [$languageId],
);

q('DROP TABLE IF EXISTS learn_days');

$serieOhne = null;
$fehlerOhne = null;
try {
    $serieOhne = streak_stand($userId);
} catch (Throwable $e) {
    $fehlerOhne = $e->getMessage();
}
ok('Ohne Tabelle liefert die Serie einen leeren Stand statt eines Fehlers',
   $fehlerOhne === null && ($serieOhne['kette'] ?? null) === 0, (string) $fehlerOhne);

$verbucht = null;
try {
    streak_verbuchen($userId, streak_heute(), true, true);
} catch (Throwable $e) {
    $verbucht = $e->getMessage();
}
ok('Und das Verbuchen laeuft lautlos durch', $verbucht === null, (string) $verbucht);

// Das Entscheidende: Ueben geht weiter, auch ohne die Tabelle.
$weiter = null;
try {
    record_answer($userId, $serieVokabel, MODE_CHOICE, true);
} catch (Throwable $e) {
    $weiter = $e->getMessage();
}
ok('Vor allem aber kann ein Kind weiter ueben',
   $weiter === null, 'ein Abzeichen darf das Ueben nie aufhalten: ' . (string) $weiter);

// Und die Huelle selbst - dort steht streak_stand() im Seitenkopf.
$res = http($base . '/');
ok('Die Huelle wird weiterhin ausgeliefert', $res['status'] === 200, (string) $res['status']);

/*
 * Zurueck in den richtigen Zustand - aus schema.sql.
 *
 * Frueher stand hier ensure_schema(): Die Tabelle kam aus der
 * Aenderungsliste. Die ist leer, und genau deshalb ist dieser Weg der
 * richtige - er prueft nebenbei, dass schema.sql die Tabelle mitbringt.
 * Ohne das haette eine frische Installation gar keine Serie.
 */
$schemaSqlText = (string) file_get_contents(__DIR__ . '/../schema.sql');
preg_match('/CREATE TABLE IF NOT EXISTS learn_days \(.*?;/s', $schemaSqlText, $ldm);
ok('schema.sql bringt learn_days mit', ($ldm[0] ?? '') !== '');
db()->exec($ldm[0]);

ok('Und danach steht sie wieder',
   (int) qv("SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'learn_days'") === 1);
ok('Es steht weiterhin nichts aus', schema_pending() === []);

section('Die Serie in der Leiste');

/*
 * Das Abzeichen steht links vom Zahnrad, und zwar in topbar() - also auf
 * jeder Seite, die eine Leiste hat. Stuende es in den Ansichten, fehlte es
 * in der naechsten.
 */
ok('Das Abzeichen steht direkt links vom Zahnrad',
   preg_match('/\$\{serieHtml\(\)\}\s*\$\{navRechtsHtml\(\)\}/', $kern) === 1,
   'sonst steht es irgendwo in der Leiste');
ok('Und es ist ein Knopf, kein blosses Bild',
   str_contains($kern, 'class="seriebtn') && str_contains($kern, 'seriekarte'),
   'die Regel dahinter muss sich antippen lassen');
ok('Die Karte erklaert beide Wege zu einem Tag',
   str_contains($kern, 'Eine neue Vokabel lernen')
   && str_contains($kern, 'Oder alte wiederholen'),
   'gerade die zweite Tuer erklaert sich nicht von selbst');
ok('Und was nach einer Pause passiert',
   str_contains($kern, 'Einen Tag darfst du auslassen'));

$stil = (string) file_get_contents(__DIR__ . '/../style.css');
ok('Froh und traurig unterscheiden sich auch ohne Farbe',
   str_contains($stil, 'grayscale(1)'),
   'ein Kind, das Gruen und Grau schlecht trennt, sieht sonst nichts');
ok('Die Zahl ist nur gruen, wenn heute gelernt wurde',
   str_contains($stil, '.seriebtn.lage-heute .seriezahl'));

// Die beiden Bilder muessen da sein - ohne sie stuende in der Leiste ein
// kaputtes Bild auf jeder Seite.
foreach (['voki-mini.svg', 'voki-sad-mini.svg'] as $bild) {
    $r = http($base . '/assets/' . $bild);
    ok("Das Maskottchen $bild wird ausgeliefert", $r['status'] === 200, (string) $r['status']);
    ok("Und $bild hat keinen deckenden Hintergrund",
       !preg_match('/fill="white" d="M0 0L\d+ 0/', $r['body']),
       'ein weisses Quadrat in der dunklen Leiste');
}


/*
 * Das Verhalten steht einmal da. Zwei Abschriften waeren bald zwei
 * verschiedene Menues, und ein Kind und seine Lehrkraft sollen dieselbe
 * Bewegung sehen.
 */
$menue   = (string) file_get_contents(__DIR__ . '/../menue.js');
$lehrJs  = (string) file_get_contents(__DIR__ . '/../teacher/teacher.js');
ok('Das Auf- und Zuklappen steht in menue.js',
   str_contains($menue, 'export function menueAktivieren'));
ok('Die App holt es sich von dort',
   str_contains($kern, "from './menue.js'"));
ok('Und der Lehrkraft-Bereich auch',
   str_contains($lehrJs, 'leiste.dataset.menue')
   && str_contains($lehrJs, 'menueAktivieren'),
   'als Nachladung, weil teacher.js ein gewoehnliches Skript ist');
ok('Es steht nicht zweimal da',
   !str_contains($lehrJs, 'schubladeLinks')
   && substr_count($menue, 'export function menueAktivieren') === 1);

$res = teacherGet('index.php');
ok('Die Leiste sagt dem Skript, wo das Modul liegt',
   preg_match('/data-menue="[^"]*\/menue\.js/', $res['body']) === 1);

q('DELETE FROM users WHERE id = ?', [$themaLehrerId]);

section('Verbindungen bleiben stehen');

/*
 * Jede API-Antwort trug "Connection: close".
 *
 * Gedacht war das als Teil von "antworten und dann weiterarbeiten": Der
 * Browser sollte merken, wo die Antwort aufhoert. Dafuer ist aber die
 * Laengenangabe zustaendig, und die steht dabei.
 *
 * Was der Header wirklich tat: Die Seite laeuft ueber HTTP/1.1, und dort
 * heisst er "wirf diese Verbindung danach weg" - bei jedem einzelnen
 * Aufruf. Beim Ueben gehen viele kurz hintereinander raus, Frage holen,
 * Antwort schicken, naechste Frage, und jede brauchte damit einen neuen
 * TCP- und TLS-Handschlag. Das haelt den Verbindungsvorrat des Browsers
 * dauernd in Bewegung, und genau dort sitzt das Wettrennen: Der Browser
 * schickt auf eine Verbindung, die der Server gerade zumacht. Die faellt
 * ohne Status um, und in der App las sich das als "Keine Verbindung. Bist
 * du online?" - mitten im besten Netz.
 *
 * Geprueft wird am Quelltext, nicht an der Antwort: Der Entwicklungsserver
 * von PHP setzt den Header von sich aus, weil er ohnehin nur eine Anfrage
 * zugleich kann. An ihm liesse sich der Unterschied gar nicht sehen.
 */
foreach (['lib/json.php', 'teacher/_boot.php', 'api/import.php'] as $datei) {
    $quelle = (string) file_get_contents(__DIR__ . '/../' . $datei);
    ok($datei . ' setzt kein Connection: close',
       preg_match('/header\s*\(\s*.Connection:/i', $quelle) !== 1,
       'die Laengenangabe sagt schon, wo die Antwort aufhoert');
}

ok('Die Laengenangabe steht dafuer weiterhin da',
   str_contains((string) file_get_contents(__DIR__ . '/../lib/json.php'),
                'Content-Length: '),
   'ohne sie wartet der Browser doch wieder auf das Ende der Verbindung');

/*
 * Und der Guertel dazu: Faellt ein Abruf ohne Status um, fasst die App
 * genau einmal nach. Ein zweiter Versuch auf frischer Verbindung laeuft
 * durch; ist wirklich kein Netz da, erfaehrt das Kind es nach einem
 * Wimpernschlag und nicht nach einer Minute.
 */
$kern = (string) file_get_contents(__DIR__ . '/../core.js');
ok('Ein Abruf ohne Status wird einmal wiederholt',
   preg_match('/catch \{.*?setTimeout.*?res = await senden\(\);/s', $kern) === 1,
   'ein Wettrennen um eine Verbindung faellt beim zweiten Mal nicht mehr auf');
ok('Aber nur dieser Fall',
   substr_count($kern, 'res = await senden();') === 2,
   'eine Antwort, die ankam und "nein" sagte, wird nicht noch einmal geschickt');

/*
 * Und eine Stoerung sieht immer gleich aus. Vorher hing die Gestalt davon
 * ab, wann sie auftrat: Stand die Ansicht schon, gab es einen roten
 * Kasten; fiel der Abruf beim Laden um, stand dort noch der Ladepunkt ohne
 * #msg - und daraus wurde ein Popup zum Wegdruecken.
 */
ok('Eine Meldung ist immer ein Kasten, nie ein Popup',
   !str_contains($kern, 'alert(message)')
   && str_contains($kern, "box.id = 'msg';"),
   'zwei Gestalten fuer dieselbe Nachricht, und die haesslichere im haeufigeren Fall');

section('Fahnen als Bild');

/*
 * Warum das ueberhaupt sein muss: Windows stellt die Regionalzeichen nicht
 * als Fahne dar, sondern als die zwei Buchstaben des Laenderkuerzels - aus
 * der britischen Fahne wird "GB". Das laesst sich mit keiner Schriftart der
 * Seite aendern; das Bild muss mitgebracht werden.
 *
 * Geprueft wird deshalb zweierlei: dass zu jedem Sinnbild der Sprachliste
 * eine Datei da ist, und dass die Seiten sie auch einsetzen.
 */
require_once __DIR__ . '/../lib/flags.php';

ok('Der Dateiname kommt aus den Unicode-Stellen',
   flag_file("\u{1F1EC}\u{1F1E7}") === '1f1ec-1f1e7', (string) flag_file("\u{1F1EC}\u{1F1E7}"));
ok('Die Variantenwahl faellt dabei weg',
   flag_file("\u{1F3DB}\u{FE0F}") === '1f3db', (string) flag_file("\u{1F3DB}\u{FE0F}"));
ok('Ohne Sinnbild gibt es keinen Namen', flag_file('') === null);

$fehlende = [];
foreach (world_languages() as $name => [$kuerzel, $bild]) {
    if (flag_path($bild) === null) {
        $fehlende[] = $name;
    }
}
ok('Zu jeder Sprache der Liste liegt eine Datei',
   $fehlende === [], implode(', ', array_slice($fehlende, 0, 8)));

ok('Auch fuer die Weltkugel, die als Rueckfall dient',
   flag_path("\u{1F310}") !== null);

/*
 * Fehlt eine Datei, bleibt das Emoji stehen. Das ist der Rueckfall, der auf
 * dem Handy weiterhin richtig aussieht - und eine Sprache, fuer die niemand
 * eine Fahne beigelegt hat, verliert dadurch nichts.
 */
ok('Ohne Datei bleibt das Emoji als Text stehen',
   str_contains(flag_html("\u{1F984}"), '<span') && str_contains(flag_html("\u{1F984}"), "\u{1F984}"),
   flag_html("\u{1F984}"));
ok('Mit Datei wird daraus ein Bild',
   str_contains(flag_html("\u{1F1EC}\u{1F1E7}"), '<img')
   && str_contains(flag_html("\u{1F1EC}\u{1F1E7}"), '1f1ec-1f1e7.svg'));
ok('Und das Bild ist abrufbar',
   http($base . '/assets/flags/1f1ec-1f1e7.svg')['status'] === 200);

// ---- Die Seiten setzen es auch ein.

$res = http($base . '/core.js');
ok('Die App baut Fahnen als Bild',
   str_contains($res['body'], 'export function flagHtml')
   && str_contains($res['body'], 'assets/flags/'));
ok('Und setzt das Emoji ein, wenn das Bild fehlt',
   str_contains($res['body'], 'bild.dataset.emoji')
   && str_contains($res['body'], "addEventListener('error'"),
   'error steigt nicht auf - der Hoerer muss in der Abwaertsphase lauschen');

$res = http($base . '/views/languages.js');
ok('Die Kachelliste ruft ihn auf', str_contains($res['body'], 'flagHtml('));
ok('Und setzt die Fahne nicht mehr als Text',
   !str_contains($res['body'], '<span class="flag">${esc(lang.flag_emoji'));

$res = http($base . '/teacher/teacher.js');
ok('Das Auswahlfeld fuer Sprachen ebenso',
   str_contains($res['body'], 'function fahnenBild')
   && str_contains($res['body'], 'fahnenBild(o.flag'));

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

/*
 * Die Sprachen zuerst - und das fehlte lange.
 *
 * Eine Sprache haengt an der Schule, nicht am Konto: users zu loeschen
 * nimmt sie nicht mit. Jeder Lauf liess deshalb eine Sprache "Testisch"
 * samt Kurs, Lerneinheit und Vokabeln zurueck. Nach zweiundfuenfzig Laeufen
 * lagen zweiundfuenfzig davon herum, und die Pruefung "Nach dem Nachtragen
 * hat jede Vokabel eine Kategorie" zaehlt ALLE Vokabeln ohne Wortart - sie
 * fiel um, ohne dass sich am Code etwas geaendert haette.
 *
 * Kurse, Einheiten, Vokabeln und Saetze fallen per ON DELETE CASCADE mit
 * (fk_course_language, fk_unit_language).
 */
foreach ([$languageId ?? 0, $otherLang ?? 0, $fremdLang ?? 0, $freiLang ?? 0,
          $buLang ?? 0, $buFremdLang ?? 0, $roLang ?? 0, $sfLang ?? 0] as $lid) {
    if ((int) $lid > 0) {
        q('DELETE FROM languages WHERE id = ?', [(int) $lid]);
    }
}
q("DELETE FROM languages WHERE name IN ('Testisch', 'Fremdisch', 'Spanisch', 'Klingonisch')");
q("DELETE FROM languages WHERE name LIKE 'Reihisch%' OR name LIKE 'Fremdreihe%'");
q("DELETE FROM languages WHERE name LIKE 'Satzisch%'");
q("DELETE FROM classes WHERE name LIKE 'Reihe%' OR name LIKE 'Satz%'");

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
