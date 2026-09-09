<?php
declare(strict_types=1);

/**
 * Server-Diagnose. Prueft nach dem Deployment, ob alle Voraussetzungen
 * erfuellt sind - Extensions, Datenbank, Schema, SDK, Schreibrechte, HTTPS.
 */

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/keyvault.php';

admin_require();

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
    return [PHP_VERSION_ID >= 80200, PHP_VERSION . ' (benoetigt 8.2 oder neuer)'];
});

foreach (['pdo_mysql', 'gd', 'curl', 'mbstring', 'openssl', 'json'] as $ext) {
    check($checks, "Extension $ext", static fn (): array => [
        extension_loaded($ext),
        extension_loaded($ext) ? 'geladen' : 'fehlt - beim Hoster aktivieren lassen',
    ]);
}

check($checks, 'GD mit FreeType', static function (): array {
    $info = function_exists('gd_info') ? gd_info() : [];
    $ok   = !empty($info['FreeType Support']);
    return [$ok, $ok
        ? 'vorhanden - Icons mit Initiale moeglich'
        : 'fehlt - Icons werden ohne Buchstabe erzeugt'];
});

check($checks, 'Schriftdatei fuer Icons', static function (): array {
    $f = dirname(__DIR__) . '/assets/Roboto-Bold.ttf';
    return [is_file($f), is_file($f) ? 'assets/Roboto-Bold.ttf' : 'assets/Roboto-Bold.ttf fehlt'];
});

check($checks, 'Icon-Cache beschreibbar', static function (): array {
    $dir = dirname(__DIR__) . '/storage/icons';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return [is_dir($dir) && is_writable($dir), $dir];
});

// Ohne eigenes Sitzungsverzeichnis landen wir beim Standardpfad des Servers -
// und der existiert bei geteiltem Hosting nicht immer.
check($checks, 'Sitzungen im eigenen Verzeichnis', static function (): array {
    $dir  = dirname(__DIR__) . '/storage/sessions';
    $used = session_save_path();
    if (!is_dir($dir) || !is_writable($dir)) {
        return [false, 'storage/sessions fehlt oder ist nicht beschreibbar - '
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

check($checks, 'Schema vollstaendig', static function (): array {
    $needed = ['users', 'device_tokens', 'languages', 'units', 'vocab',
               'progress', 'ai_requests', 'settings'];
    $have   = [];
    foreach (qa('SHOW TABLES') as $row) {
        $have[] = strtolower((string) reset($row));
    }
    $missing = array_diff($needed, $have);
    return [$missing === [], $missing === []
        ? count($needed) . ' Tabellen vorhanden'
        : 'fehlt: ' . implode(', ', $missing) . ' - schema.sql einspielen'];
});

check($checks, 'Anthropic-SDK', static function (): array {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        return [false, 'vendor/ fehlt - auf dem Server "composer install" ausfuehren'];
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

// Holt den Key wirklich ab - so faellt eine abgelaufene Berechtigung hier auf
// und nicht erst, wenn ein Kind ein Foto hochlaedt. Der Key selbst wird
// bewusst nicht angezeigt, nur seine Laenge und sein Praefix.
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
 * Ein Ergebnis von null heisst "nicht messbar".
 *
 * Der eingebaute PHP-Entwicklungsserver beantwortet nur eine Anfrage zur Zeit -
 * ein Selbstaufruf wuerde ihn blockieren. Dort wird deshalb nicht gemessen;
 * .htaccess wertet er ohnehin nicht aus.
 */
function probe(string $path): ?array
{
    if (PHP_SAPI === 'cli-server' && (int) getenv('PHP_CLI_SERVER_WORKERS') < 2) {
        return null;
    }

    $ch = curl_init(self_base_url() . $path);
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

// Die .htaccess-Sperren werden wirklich gemessen statt nur angenommen -
// unter nginx oder bei abgeschaltetem AllowOverride greifen sie naemlich nicht.
check($checks, 'config.php nicht abrufbar', static function (): array {
    if (!is_file(dirname(__DIR__) . '/config.php')) {
        return [true, 'liegt ausserhalb des Webroots - ideal'];
    }
    $result = probe('/config.php');
    if ($result === null) {
        return [true, 'nicht messbar im Entwicklungsserver - auf dem echten Server pruefen'];
    }
    [$status, $body] = $result;
    if ($status === 403 || $status === 404) {
        return [true, "gesperrt (HTTP $status)"];
    }
    // PHP-Dateien werden ausgefuehrt und geben nichts aus - gefaehrlich waere
    // nur ausgelieferter Quelltext.
    $leaks = str_contains($body, '<?php') || str_contains($body, 'keyvault_token');
    return [!$leaks, $leaks
        ? "ACHTUNG: Quelltext wird ausgeliefert (HTTP $status)"
        : "HTTP $status, aber kein Quelltext sichtbar - Sperre trotzdem einrichten"];
});

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

check($checks, 'tests/ nicht ausfuehrbar', static function (): array {
    if (!is_dir(dirname(__DIR__) . '/tests')) {
        return [true, 'nicht auf den Server geladen - ideal'];
    }
    $result = probe('/tests/e2e.php');
    if ($result === null) {
        return [true, 'nicht messbar im Entwicklungsserver'];
    }
    [$status, $body] = $result;
    // Die Testskripte lehnen Web-Aufrufe selbst mit 404 ab; zusaetzlich
    // greift die .htaccess. Alles andere waere ein Problem.
    $ok = in_array($status, [403, 404], true) && trim($body) === '';
    return [$ok, $ok
        ? "gesperrt (HTTP $status)"
        : "ACHTUNG: liefert HTTP $status mit Inhalt - Ordner tests/ loeschen"];
});

check($checks, 'Accounts angelegt', static function (): array {
    $n = (int) qv('SELECT COUNT(*) FROM users WHERE active = 1');
    return [$n > 0, $n > 0 ? $n . ' aktive(r) Account(s)' : 'noch keiner - unter "Accounts" anlegen'];
});

$failed = count(array_filter($checks, static fn ($c) => !$c['ok']));

admin_head('Selbsttest', 'index.php');
?>

<?php if ($failed === 0): ?>
    <div class="notice good">Alle Pruefungen bestanden.</div>
<?php else: ?>
    <div class="notice"><?= $failed ?> Punkt(e) brauchen Aufmerksamkeit.</div>
<?php endif; ?>

<table class="data">
    <tr><th style="width:34px"></th><th>Pruefung</th><th>Ergebnis</th></tr>
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
    <code>config.php</code>, <code>schema.sql</code> und <code>lib/db.php</code>
    ueber die eigene Adresse auf und prueft, dass kein Quelltext herauskommt.
    Meldet dein Hoster hier ein Problem, greift <code>.htaccess</code> nicht -
    dann config.php oberhalb des Webroots ablegen (siehe README).
</p>

<?php admin_foot(); ?>
