<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/worldlanguages.php';

/**
 * Sprachkürzel (fr, en, la, da ...).
 *
 * Das Kürzel steuert zweierlei im Lückentext: den lang-Hinweis am
 * Eingabefeld und die Reihe der Sonderzeichen darüber. Fehlt es, bleibt
 * beides weg - die Übung funktioniert, ist aber unbequemer.
 *
 * Bewusst hier und nicht in api/languages.php: Die Schemapflege muss dieselbe
 * Zuordnung benutzen, um den Bestand nachzutragen, und darf dafür keinen
 * API-Endpunkt laden.
 */

/**
 * Name der Sprache (klein geschrieben) => Kürzel.
 *
 * Abgeleitet aus lib/worldlanguages.php, damit beide Listen nicht
 * auseinanderlaufen: Was im Auswahlfeld steht, bekommt auch sein Kürzel und
 * damit die Sonderzeichenreihe im Lückentext.
 *
 * Dazu je eine Schreibweise ohne Umlaute. Wer "Franzoesisch" eintippt, meint
 * dasselbe, und die Datenbank soll darüber nicht nachdenken müssen.
 */
function language_codes(): array
{
    static $karte = null;
    if ($karte !== null) {
        return $karte;
    }

    $karte = [];
    foreach (world_languages() as $name => [$code, $_flag]) {
        $klein = mb_strtolower($name);
        $karte[$klein] = $code;

        $ascii = strtr($klein, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        if ($ascii !== $klein) {
            $karte[$ascii] = $code;
        }
    }

    return $karte;
}

/**
 * Kürzel aus dem Namen ableiten, wenn die App keines mitschickt.
 * Findet sich nichts, bleibt es leer und die Tastatur verhält sich wie bisher.
 */
function language_code(string $sent, string $name): ?string
{
    $code = strtolower(trim($sent));
    if (preg_match('/^[a-z]{2,3}$/', $code) === 1) {
        return $code;
    }
    return language_codes()[mb_strtolower(trim($name))] ?? null;
}
