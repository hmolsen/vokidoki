<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

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

/** Name der Sprache (klein geschrieben) => Kürzel. */
const LANGUAGE_CODES = [
    'englisch' => 'en', 'französisch' => 'fr', 'franzoesisch' => 'fr',
    'latein' => 'la', 'dänisch' => 'da', 'daenisch' => 'da',
    'spanisch' => 'es', 'italienisch' => 'it', 'niederländisch' => 'nl',
    'niederlaendisch' => 'nl', 'schwedisch' => 'sv', 'norwegisch' => 'no',
    'türkisch' => 'tr', 'tuerkisch' => 'tr', 'russisch' => 'ru',
    'polnisch' => 'pl', 'portugiesisch' => 'pt', 'griechisch' => 'el',
];

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
    return LANGUAGE_CODES[mb_strtolower(trim($name))] ?? null;
}

/** Kürzel => Liste der Namen, die darauf führen. */
function language_codes_by_code(): array
{
    $nach = [];
    foreach (LANGUAGE_CODES as $name => $code) {
        $nach[$code][] = $name;
    }
    return $nach;
}

/**
 * SQL, das vorhandenen Sprachen ihr fehlendes Kürzel nachträgt.
 *
 * Die Spalte kam erst mit dem Lückentext dazu, und gefüllt wird sie sonst nur
 * beim Anlegen - wer seine Sprachen vorher angelegt hat, stand ohne da.
 *
 * Verglichen wird gegen beide Schreibweisen (mit Umlaut und ausgeschrieben),
 * statt sich auf die Sortierregeln der Datenbank zu verlassen: ob die 'ö'
 * und 'o' als gleich ansieht, hängt von der Kollation ab.
 */
function language_code_backfill_sql(): string
{
    $faelle = [];
    foreach (language_codes_by_code() as $code => $namen) {
        $liste = implode(', ', array_map(
            static fn (string $n): string => "'" . str_replace("'", "''", $n) . "'",
            $namen,
        ));
        $faelle[] = sprintf('WHEN LOWER(name) IN (%s) THEN %s',
            $liste, "'" . $code . "'");
    }

    return 'UPDATE languages SET code = CASE ' . implode(' ', $faelle)
         . " ELSE code END WHERE code IS NULL OR code = ''";
}

/**
 * Gibt es Sprachen, deren Kürzel sich nachtragen lässt?
 *
 * Absichtlich nur solche, die in der Zuordnung stehen - sonst bliebe die
 * Schemapflege wegen einer frei benannten Sprache für immer offen.
 */
function language_code_backfill_pending(): bool
{
    $namen = implode(', ', array_map(
        static fn (string $n): string => "'" . str_replace("'", "''", $n) . "'",
        array_keys(LANGUAGE_CODES),
    ));

    return (int) qv(
        "SELECT COUNT(*) FROM languages
           WHERE (code IS NULL OR code = '') AND LOWER(name) IN ($namen)"
    ) > 0;
}
