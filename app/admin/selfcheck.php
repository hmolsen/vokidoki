<?php
declare(strict_types=1);

/**
 * Server-Diagnose. Prüft nach dem Deployment, ob alle Voraussetzungen
 * erfüllt sind - Extensions, Datenbank, Schema, SDK, Schreibrechte, HTTPS.
 */

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/keyvault.php';
require_once __DIR__ . '/../lib/sentences.php';
require_once __DIR__ . '/../lib/vocab.php';

admin_require();

/*
 * Schemaaenderungen laufen nur hier und nur auf Knopfdruck.
 *
 * Frueher geschah das beim Aufruf jeder Admin-Seite von selbst. Bequem, aber
 * blind: Ein Fehlschlag stand nur im Protokoll, und niemand wusste, ob und
 * wann eine Aenderung gelaufen war. Eine Schemaaenderung ist eine
 * Entscheidung, kein Seiteneffekt - also mit Knopf, Rueckmeldung je Schritt
 * und Abbruch beim ersten Fehler.
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['run_migrations'])) {
    csrf_check();
    set_time_limit(300);

    $ergebnis = ensure_schema();

    if ($ergebnis === []) {
        flash('Es stand nichts aus.');
    } else {
        $gelaufen  = array_filter($ergebnis, static fn (array $r): bool => $r['ok']);
        $gescheitert = array_filter($ergebnis, static fn (array $r): bool => !$r['ok']);

        $text = sprintf('%d Änderung(en) ausgeführt: %s.',
            count($gelaufen),
            implode(', ', array_column($gelaufen, 'name')) ?: 'keine');

        if ($gescheitert !== []) {
            $erste = reset($gescheitert);
            flash($text . sprintf(' Abgebrochen bei "%s": %s', $erste['name'], $erste['error']), 'bad');
        } else {
            flash($text);
        }
    }
    redirect('selfcheck.php');
}

$checks = [];

/** @param callable():array{bool,string} $test */
function check(array &$checks, string $name, callable $test): void
{
    try {
        [$ok, $detail] = $test();
    } catch (Throwable $e) {
        [$ok, $detail] = [false, scrub_secrets($e->getMessage())];
    }
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
}

check($checks, 'PHP-Version', static function (): array {
    return [PHP_VERSION_ID >= 80200, PHP_VERSION . ' (benötigt 8.2 oder neuer)'];
});

foreach (['pdo_mysql', 'gd', 'curl', 'mbstring', 'openssl', 'json'] as $ext) {
    check($checks, "Extension $ext", static fn (): array => [
        extension_loaded($ext),
        extension_loaded($ext) ? 'geladen' : 'fehlt - beim Hoster aktivieren lassen',
    ]);
}

/*
 * Hier standen "GD mit FreeType" und "Schriftdatei fuer Icons" - fuer den
 * Anfangsbuchstaben auf dem Symbol. Jetzt ist es Voki, als fertiges PNG:
 * GD muss es nur lesen koennen, eine Schrift braucht es nicht mehr.
 */
check($checks, 'Voki fürs App-Symbol', static function (): array {
    $f = dirname(__DIR__) . '/assets/voki-icon.png';
    if (!is_file($f)) {
        return [false, 'assets/voki-icon.png fehlt - Symbole zeigen nur einen Punkt'];
    }
    if (!function_exists('imagecreatefrompng') || @imagecreatefrompng($f) === false) {
        return [false, 'GD kann assets/voki-icon.png nicht lesen'];
    }
    return [true, 'assets/voki-icon.png lesbar'];
});

/*
 * Alle Dateien der App hochgeladen? Eine fehlende genügte, und die App
 * blieb weiss - der Server selbst lief dabei tadellos.
 */
check($checks, 'Module der App vollständig', static function (): array {
    require_once dirname(__DIR__) . '/lib/version.php';
    $fehlen = modules_missing();
    return $fehlen === []
        ? [true, 'jeder Import findet seine Datei']
        : [false, 'fehlt auf dem Server: ' . implode(', ', $fehlen) . ' - diese Dateien hochladen'];
});

/*
 * Die Texterkennung im Browser besteht aus einem Dutzend Dateien unter
 * ocr/ - vier Megabyte große darunter, die ein FTP-Programm beim
 * Hochladen gern auslässt oder abbricht. Fehlt eine, scheitert das
 * Einlesen erst beim Lehrer am Telefon.
 */
check($checks, 'Texterkennung vollständig', static function (): array {
    $ordner = dirname(__DIR__) . '/ocr/';
    $noetig = ['tesseract.min.js', 'worker.min.js', 'tesseract-core-lstm.wasm.js',
               'tesseract-core-simd-lstm.wasm.js', 'tesseract-core-relaxedsimd-lstm.wasm.js',
               'sprachen/deu.traineddata.gz', 'sprachen/eng.traineddata.gz'];
    $fehlen = array_values(array_filter($noetig, static fn (string $f): bool =>
        !is_file($ordner . $f) || filesize($ordner . $f) < 1000));
    $sprachen = glob($ordner . 'sprachen/*.traineddata.gz') ?: [];
    return $fehlen === []
        ? [true, sprintf('ocr/ mit %d Sprachen', count($sprachen))]
        : [false, 'fehlt oder unvollständig: ocr/' . implode(', ocr/', $fehlen)];
});

check($checks, 'Icon-Cache beschreibbar', static function (): array {
    $dir = storage_path('icons');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return [is_dir($dir) && is_writable($dir), $dir];
});

// Ohne eigenes Sitzungsverzeichnis landen wir beim Standardpfad des Servers -
// und der existiert bei geteiltem Hosting nicht immer.
check($checks, 'Sitzungen im eigenen Verzeichnis', static function (): array {
    $dir  = storage_path('sessions');
    $used = session_save_path();
    if (!is_dir($dir) || !is_writable($dir)) {
        return [false, 'daten/storage/sessions fehlt oder ist nicht beschreibbar - '
                     . 'PHP nutzt stattdessen ' . ($used !== '' ? $used : 'den Standardpfad')];
    }
    $same = realpath($used) !== false && realpath($used) === realpath($dir);
    return [$same, 'benutzt ' . $used];
});

check($checks, 'Fehlerprotokoll beschreibbar', static function (): array {
    $path = error_log_path();
    $dir  = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
        return [false, $dir . ' ist nicht beschreibbar'];
    }
    $size = is_file($path) ? filesize($path) : 0;
    return [true, $path . ($size > 0 ? sprintf(' (%d Bytes)', $size) : ' (noch leer)')];
});

check($checks, 'Datenbankverbindung', static function (): array {
    $v = qv('SELECT VERSION()');
    return [true, 'verbunden mit ' . $v];
});

check($checks, 'Schema vollständig', static function (): array {
    /*
     * Die Liste kommt aus schema.sql selbst. Hier stand sie von Hand - mit
     * den acht Tabellen der ersten Fassung. Schulen, Klassen und Kurse kamen
     * dazu, die Liste nicht, und der Test meldete "vollstaendig" fuer eine
     * Datenbank, in der der ganze Lehrkraft-Bereich fehlte.
     */
    $sql = (string) @file_get_contents(dirname(__DIR__) . '/schema.sql');
    preg_match_all('/CREATE TABLE IF NOT EXISTS\s+`?(\w+)`?/i', $sql, $m);
    $needed = array_map('strtolower', $m[1]);
    if ($needed === []) {
        return [false, 'schema.sql nicht gefunden - gehört neben index.php'];
    }
    $have   = [];
    foreach (qa('SHOW TABLES') as $row) {
        $have[] = strtolower((string) reset($row));
    }
    $missing = array_diff($needed, $have);
    return [$missing === [], $missing === []
        ? count($needed) . ' Tabellen vorhanden'
        : 'fehlt: ' . implode(', ', $missing) . ' - schema.sql einspielen'];
});

check($checks, 'Schema auf dem Stand des Codes', static function (): array {
    $pending = schema_pending();
    return [$pending === [], $pending === []
        ? 'keine offenen Änderungen'
        : count($pending) . ' ausstehend: ' . implode(', ', $pending)];
});

check($checks, 'Kategorien der Vokabeln', static function (): array {
    if (!column_exists('vocab', 'word_type')) {
        return [false, 'Spalte fehlt'];
    }
    $offen = (int) qv('SELECT COUNT(*) FROM vocab WHERE word_type IS NULL');
    $alle  = (int) qv('SELECT COUNT(*) FROM vocab');
    return [true, $offen === 0
        ? $alle . ' Vokabeln, alle eingeordnet'
        : sprintf('%d von %d ohne Kategorie - unter "Unterlagen" nachtragen', $offen, $alle)];
});

/*
 * Hier stand "Lernstand je Kind": ob progress noch am alten Schluessel
 * (vocab_id, mode) hing, der einen Lernstand fuer alle Kinder zuliess. Eine
 * Frage der Umstellung - schema.sql bringt uq_progress_user von Anfang an
 * mit, und eine Datenbank von vor der Umstellung gibt es nicht mehr.
 */

check($checks, 'Reihenfolge der Vokabeln', static function (): array {
    if (!table_exists('vocab')) {
        return [false, 'Tabelle vocab fehlt'];
    }

    /*
     * vocab.position ist Reihenfolge UND Freigabezeiger zugleich. Elf
     * Abfragen vergleichen v.position < u.released_position, und „Alles
     * freigeben" setzt die Marke auf COUNT(*). Sind die Positionen einer
     * Einheit nicht lückenlos 0..n-1, zeigt die Marke ins Leere: Die letzte
     * Vokabel bleibt unsichtbar, ohne dass irgendwo etwas meldet.
     */
    $luecken = vocab_units_with_gaps();
    $riegel  = index_exists('vocab', 'uq_vocab_pos');

    if ($luecken !== []) {
        $namen = array_slice(array_column($luecken, 'title'), 0, 3);
        return [false, sprintf(
            '%d Lerneinheit(en) mit Lücken in den Positionen (%s%s) - '
            . 'dort erreicht „Alles freigeben" die letzte Vokabel nicht',
            count($luecken),
            implode(', ', $namen),
            count($luecken) > 3 ? ', …' : '',
        )];
    }

    if (!$riegel) {
        return [false, 'Positionen sind lückenlos, aber uq_vocab_pos fehlt - '
                     . 'die Tabelle stammt nicht aus dem aktuellen schema.sql'];
    }

    return [true, 'lückenlos, und uq_vocab_pos hält es so'];
});

check($checks, 'Sprachkürzel', static function (): array {
    if (!column_exists('languages', 'code')) {
        return [false, 'Spalte languages.code fehlt'];
    }
    $gesamt = (int) qv('SELECT COUNT(*) FROM languages');
    if ($gesamt === 0) {
        return [true, 'noch keine Sprache angelegt'];
    }

    $ohne = qa("SELECT name FROM languages WHERE code IS NULL OR code = '' ORDER BY name");
    if ($ohne === []) {
        return [true, sprintf('alle %d Sprache(n) haben ein Kürzel', $gesamt)];
    }

    // Kein Fehler, nur unbequem: Die Übung läuft, aber ohne Tastaturhinweis
    // und ohne die Reihe der Sonderzeichen über dem Eingabefeld.
    return [true, sprintf('%d von %d ohne Kürzel (%s) - unter "Unterlagen" beim Kurs nachtragbar',
        count($ohne), $gesamt,
        implode(', ', array_column($ohne, 'name')))];
});

check($checks, 'Lückentext', static function (): array {
    if (!table_exists('sentences')) {
        return [false, 'Tabelle sentences fehlt'];
    }
    $saetze = (int) qv('SELECT COUNT(*) FROM sentences');
    if ($saetze === 0) {
        return [true, 'noch keine Sätze - sie entstehen im Hintergrund, sobald eine Lerneinheit eingelesen ist'];
    }
    $vokabeln = (int) qv('SELECT COUNT(DISTINCT vocab_id) FROM sentences');
    return [true, sprintf('%d Sätze zu %d Vokabeln (%s)',
        $saetze, $vokabeln, setting('sentence_model'))];
});

check($checks, 'Anthropic-SDK', static function (): array {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        return [false, 'vendor/ fehlt - auf dem Server in app/ "composer install --no-dev" ausführen'];
    }
    require_once $autoload;
    return [class_exists(\Anthropic\Client::class), 'anthropic-ai/sdk geladen'];
});

check($checks, 'Keyvault konfiguriert', static function (): array {
    $url   = (string) cfg('keyvault_url', '');
    $token = (string) cfg('keyvault_token', '');
    if ($url === '' || $token === '') {
        return [false, 'keyvault_url und keyvault_token in config.php eintragen'];
    }
    return [true, $url . ' (Eintrag: ' . cfg('keyvault_key', 'vokabeltrainer') . ')'];
});

// Holt den Key wirklich ab - so fällt eine abgelaufene Berechtigung hier auf
// und nicht erst, wenn ein Kind ein Foto hochlädt. Der Key selbst wird
// bewusst nicht angezeigt, nur seine Länge und sein Präfix.
check($checks, 'Anthropic-Key aus dem Keyvault', static function (): array {
    $started = microtime(true);
    $key     = keyvault_anthropic_key();
    $ms      = (int) ((microtime(true) - $started) * 1000);

    $shape = str_starts_with($key, 'sk-ant-')
        ? 'sieht aus wie ein Anthropic-Key'
        : 'ACHTUNG: beginnt nicht mit sk-ant-';

    return [true, sprintf('abgerufen in %d ms, %d Zeichen - %s', $ms, strlen($key), $shape)];
});

check($checks, 'HTTPS', static function (): array {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return [$https, $https
        ? 'aktiv'
        : 'inaktiv - ohne HTTPS gibt es keinen Service Worker und keine Kamera in iOS'];
});

/** Basis-URL dieser Installation, um die Zugriffssperren aktiv zu messen. */
function self_base_url(): string
{
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host . base_path();
}

/**
 * Ruft einen eigenen Pfad auf und liefert [HTTP-Status, erste Zeichen des Inhalts].
 * Ein Ergebnis von null heißt "nicht messbar".
 *
 * Der eingebaute PHP-Entwicklungsserver beantwortet nur eine Anfrage zur Zeit -
 * ein Selbstaufruf würde ihn blockieren. Dort wird deshalb nicht gemessen;
 * .htaccess wertet er ohnehin nicht aus.
 */
function probe(string $path): ?array
{
    return probe_url(self_base_url() . $path);
}

/** Wie probe(), aber mit vollständiger Adresse - für daten/ neben der App. */
function probe_url(string $url): ?array
{
    if (PHP_SAPI === 'cli-server' && (int) getenv('PHP_CLI_SERVER_WORKERS') < 2) {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body   = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$status, substr($body, 0, 400)];
}

/*
 * Die .htaccess-Sperren werden wirklich gemessen statt nur angenommen - unter
 * nginx oder bei abgeschaltetem AllowOverride greifen sie nämlich nicht.
 *
 * config.php und storage/ liegen in daten/, nicht mehr in app/ - app/ wird bei
 * jedem Update überschrieben. Liegt daten/ neben app/ im Webroot, muss die
 * .htaccess dort greifen; gemessen wird deshalb die wirkliche Adresse, eine
 * Ebene über der App. Oberhalb des Webroots gibt es nichts zu messen. Neben
 * config.php auch das Fehlerprotokoll: Darin stehen Pfade und Meldungen, die
 * niemanden draussen etwas angehen.
 */
check($checks, 'daten/ nicht abrufbar', static function (): array {
    $app = dirname(__DIR__);
    if (realpath(daten_dir()) !== realpath(dirname($app) . '/daten') || base_path() === '') {
        return [true, daten_dir() . ' liegt außerhalb des Webroots - ideal'];
    }
    // Die Adresse des Webroots: die der App ohne das letzte Stück ("/app").
    $wurzel = substr(self_base_url(), 0, -strlen(base_path()))
            . rtrim(str_replace('\\', '/', dirname(base_path())), '/');

    foreach (['/daten/config.php', '/daten/storage/error.log'] as $pfad) {
        $result = probe_url($wurzel . $pfad);
        if ($result === null) {
            return [true, 'nicht messbar im Entwicklungsserver - auf dem echten Server prüfen'];
        }
        [$status] = $result;
        if ($status === 200) {
            return [false, "ACHTUNG: $pfad ist abrufbar - die .htaccess in daten/ greift nicht. "
                         . 'daten/ besser oberhalb des Webroots als vokidoki-daten/ ablegen'];
        }
    }
    return [true, 'daten/ liegt im Webroot, ist aber gesperrt'];
});

/*
 * Hier stand "tests/ nicht ausführbar". Die Tests liegen neben app/ und
 * werden nicht mehr hochgeladen - hochgeladen wird app/, und darin gibt es
 * keine.
 */
check($checks, 'schema.sql nicht abrufbar', static function (): array {
    $result = probe('/schema.sql');
    if ($result === null) {
        return [true, 'nicht messbar im Entwicklungsserver'];
    }
    [$status, $body] = $result;
    $leaks = str_contains($body, 'CREATE TABLE');
    return [!$leaks, $leaks
        ? "wird im Klartext ausgeliefert (HTTP $status) - .htaccess greift nicht"
        : "gesperrt (HTTP $status)"];
});

check($checks, 'lib/ nicht abrufbar', static function (): array {
    $result = probe('/lib/db.php');
    if ($result === null) {
        return [true, 'nicht messbar im Entwicklungsserver'];
    }
    [$status, $body] = $result;
    $leaks = str_contains($body, '<?php');
    return [!$leaks, $leaks ? "Quelltext sichtbar (HTTP $status)" : "gesperrt (HTTP $status)"];
});

check($checks, 'Accounts angelegt', static function (): array {
    $n = (int) qv('SELECT COUNT(*) FROM users WHERE active = 1');
    return [$n > 0, $n > 0 ? $n . ' aktive(r) Account(s)' : 'noch keiner - unter "Accounts" anlegen'];
});

$failed = count(array_filter($checks, static fn ($c) => !$c['ok']));

// Der zweite Parameter markiert den aktiven Punkt in der Navigation - hier
// stand faelschlich 'index.php', wodurch "Kosten" hervorgehoben wurde.
admin_head('Selbsttest', 'selfcheck.php');
flash_render();

$offen = schema_pending();
?>

<?php if ($offen !== []): ?>
<div class="card" style="border-left:4px solid var(--bad)">
    <strong><?= count($offen) ?> ausstehende Schemaänderung<?= count($offen) === 1 ? '' : 'en' ?></strong>
    <p class="tiny muted" style="margin:6px 0 10px">
        Die Anwendung wurde aktualisiert, die Datenbank noch nicht. Bis das
        erledigt ist, arbeitet der Bereich für Lehrkräfte nicht, und die App
        kann sich unerwartet verhalten. Die Änderungen laufen nacheinander;
        beim ersten Fehler wird abgebrochen, damit keine halbe Umstellung
        entsteht.
    </p>
    <ol class="tiny muted" style="margin:0 0 12px 18px">
        <?php foreach ($offen as $name): ?>
            <li><code class="token"><?= h($name) ?></code></li>
        <?php endforeach; ?>
    </ol>
    <form method="post">
        <?= csrf_field() ?>
        <button class="btn small" name="run_migrations" value="1">Jetzt ausführen</button>
    </form>
</div>
<?php endif; ?>

<?php if ($failed === 0): ?>
    <div class="notice good">Alle Prüfungen bestanden.</div>
<?php else: ?>
    <div class="notice"><?= $failed ?> Punkt(e) brauchen Aufmerksamkeit.</div>
<?php endif; ?>

<table class="data">
    <tr><th style="width:34px"></th><th>Prüfung</th><th>Ergebnis</th></tr>
    <?php foreach ($checks as $c): ?>
        <tr class="<?= $c['ok'] ? '' : 'dim' ?>">
            <td style="font-size:1.1rem"><?= $c['ok'] ? '&#9989;' : '&#9888;&#65039;' ?></td>
            <td><strong><?= h($c['name']) ?></strong></td>
            <td class="muted"><?= h($c['detail']) ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<p class="tiny muted">
    Die Zugriffssperren werden aktiv gemessen: Der Selbsttest ruft
    <code>daten/config.php</code>, das Fehlerprotokoll, <code>schema.sql</code>
    und <code>lib/db.php</code> über die eigene Adresse auf und prüft, dass
    nichts davon herauskommt. Meldet dein Hoster hier ein Problem, greift
    <code>.htaccess</code> nicht - dann den Ordner <code>daten/</code> als
    <code>vokidoki-daten/</code> oberhalb des Webroots ablegen (siehe README).
</p>

<?php admin_foot(); ?>
