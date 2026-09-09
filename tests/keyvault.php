<?php
declare(strict_types=1);

/**
 * Tests fuer den Keyvault-Abruf.
 *
 *   php -S 127.0.0.1:8124 tests/fake-keyvault.php &
 *   php tests/keyvault.php
 *
 * Setzt voraus, dass in config.php
 *   'keyvault_url'   => 'http://127.0.0.1:8124/'
 *   'keyvault_token' => 'test-token'
 * steht. Der echte Keyvault wird nicht angefasst.
 */

require_once __DIR__ . '/../lib/keyvault.php';

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

/** Ruft den Keyvault auf und liefert [Key, Fehlermeldung]. */
function fetch(?string $name): array
{
    try {
        return [keyvault_anthropic_key($name), null];
    } catch (KeyvaultException $e) {
        return [null, $e->getMessage()];
    }
}

echo "Keyvault-Tests gegen " . cfg('keyvault_url') . "\n\n";

echo "Erfolgsfall\n";
[$key, $err] = fetch('vokabeltrainer');
ok('Key wird abgerufen', $key !== null, (string) $err);
ok('Key hat das erwartete Format', is_string($key) && str_starts_with($key, 'sk-ant-'));

[$key2] = fetch('vokabeltrainer');
ok('Zweiter Abruf liefert denselben Wert', $key2 === $key);

[$trimmed, $err] = fetch('mit_zeilenumbruch');
ok('Zeilenumbrueche werden entfernt', $trimmed === $key, var_export($trimmed, true));

echo "\nFehlerfaelle\n";
[$key, $err] = fetch('leer');
ok('Leerer Wert wird abgelehnt', $key === null && str_contains((string) $err, 'leeren'), (string) $err);

[$key, $err] = fetch('html');
ok('HTML statt Key wird abgelehnt', $key === null && str_contains((string) $err, 'brauchbaren'), (string) $err);

[$key, $err] = fetch('gibtsnicht');
ok('Unbekannter Eintrag meldet sich klar', $key === null && str_contains((string) $err, 'kennt den Eintrag'), (string) $err);

[$key, $err] = fetch('kaputt');
ok('Serverfehler wird gemeldet', $key === null && str_contains((string) $err, 'HTTP 500'), (string) $err);

// Der haeufigste echte Fehlerfall: abgelaufenes oder falsches Token.
[$key, $err] = fetch('abgelehnt');
ok('Abgewiesener Zugriff verweist aufs Token',
   $key === null && str_contains((string) $err, 'Token'), (string) $err);

[$key, $err] = fetch('verboten');
ok('Auch HTTP 403 wird als Zugriffsproblem erkannt',
   $key === null && str_contains((string) $err, 'Token'), (string) $err);

echo "\nGeheimnisse in Protokollen\n";
$realKey = fetch('vokabeltrainer')[0];
$scrubbed = scrub_secrets("Fehler beim Aufruf mit key=$realKey und Token " . cfg('keyvault_token'));
ok('Anthropic-Key wird aus Logtext entfernt', !str_contains($scrubbed, (string) $realKey), $scrubbed);
ok('Keyvault-Token wird aus Logtext entfernt',
   !str_contains($scrubbed, (string) cfg('keyvault_token')), $scrubbed);

echo "\nKein Zwischenspeichern\n";
// Der Key darf nirgends liegenbleiben: weder in einer statischen Variable noch
// in einer Datei. Ein rotierter Key muss beim naechsten Aufruf sofort greifen.
$source = file_get_contents(__DIR__ . '/../lib/keyvault.php');
ok('keyvault.php verwendet keine statische Variable', !str_contains($source, 'static $'));
ok('keyvault.php schreibt nichts in Dateien oder Session',
   !preg_match('/file_put_contents|\\$_SESSION|apcu_store|setcookie/', $source));
ok('TLS-Pruefung ist nicht abgeschaltet',
   !preg_match('/VERIFYPEER\s*=>\s*false|VERIFYHOST\s*=>\s*(false|0)/', $source));

echo "\n" . str_repeat('-', 52) . "\n";
printf("%d bestanden, %d fehlgeschlagen\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
