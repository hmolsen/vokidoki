<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Initialpasswörter aus Adjektiv und Tier: "müder Gepard", "schnelle Schnecke".
 *
 * Der Sinn ist, dass ein Zehnjähriger sein Passwort vom Blatt abtippen und
 * sich merken kann. Zwei Wörter, die zusammen ein Bild ergeben, leisten das;
 * eine Zeichenfolge nicht.
 *
 * Die Endung des Adjektivs richtet sich nach dem Geschlecht des Tieres -
 * "müder Gepard", aber "müde Schnecke" und "müdes Nilpferd". Ohne diese
 * Rücksicht entstünde "müde Gepard", und das wäre in einer Schule peinlich.
 * Deshalb steht bei jedem Tier sein Geschlecht, und die Adjektive werden als
 * Stamm gepflegt und hier gebeugt.
 */

const PW_ADJECTIVE = 'adjective';
const PW_ANIMAL    = 'animal';

/** Endung des Adjektivs nach dem Geschlecht des Tieres. */
function password_ending(string $gender): string
{
    return match ($gender) {
        'm'     => 'er',
        'n'     => 'es',
        default => 'e',
    };
}

/** Alle brauchbaren Wörter einer Art. */
function password_words(string $kind): array
{
    return qa(
        'SELECT word, gender FROM password_words
          WHERE kind = ? AND active = 1
          ORDER BY word',
        [$kind],
    );
}

/**
 * Ein Initialpasswort. Gibt null zurück, wenn eine der Listen leer ist -
 * still ein unbrauchbares Passwort zu erzeugen wäre schlimmer.
 *
 * @param string[] $vermeiden Bereits vergebene, damit eine Klassenliste
 *                            möglichst keine Dubletten enthält
 */
function password_generate(array $vermeiden = []): ?string
{
    $adjektive = password_words(PW_ADJECTIVE);
    $tiere     = password_words(PW_ANIMAL);

    if ($adjektive === [] || $tiere === []) {
        return null;
    }

    $wort = null;

    // Ein paar Anläufe gegen Dubletten in derselben Klasse. Danach zählt,
    // dass überhaupt ein Passwort entsteht: Zwei Kinder mit demselben
    // Passwort, aber verschiedenen Benutzernamen sind kein Beinbruch.
    for ($i = 0; $i < 25; $i++) {
        $a = $adjektive[random_int(0, count($adjektive) - 1)];
        $t = $tiere[random_int(0, count($tiere) - 1)];

        $wort = $a['word'] . password_ending((string) ($t['gender'] ?? 'm'))
              . ' ' . $t['word'];

        if (!in_array($wort, $vermeiden, true)) {
            return $wort;
        }
    }

    return $wort;
}

/**
 * Räumt eine Eingabe auf, bevor sie geprüft wird.
 *
 * Auf einer Tablet-Tastatur rutscht schnell ein zweites Leerzeichen hinein,
 * und am Zeilenende hängt gern eines. Beides soll ein Kind nicht aus seinem
 * Konto aussperren. Die Grossschreibung bleibt unangetastet - das Passwort
 * steht so auf dem Blatt, wie es zu tippen ist.
 */
function password_tidy(string $typed): string
{
    $t = preg_replace('/[\s\x{00A0}]+/u', ' ', $typed) ?? $typed;
    return trim($t);
}

// ---------------------------------------------------------------- Saatgut

/**
 * Der Anfangsbestand an Adjektiven, als Stamm ohne Endung.
 *
 * Bewusst nur regelmässige: "dunkel" würde zu "dunkler" (das e fällt weg),
 * "hoch" zu "hoher". Solche Fälle gehören nicht in eine Liste, die jemand
 * später ohne Grammatikbuch bearbeiten soll. Im Admin ist alles änderbar;
 * das hier ist nur der Start.
 */
function password_seed_adjectives(): array
{
    return [
        'müd', 'schnell', 'faul', 'lustig', 'klug', 'mutig', 'fröhlich',
        'ruhig', 'wild', 'sanft', 'flink', 'stark', 'klein', 'groß', 'bunt',
        'frech', 'brav', 'neugierig', 'freundlich', 'tapfer', 'schlau',
        'munter', 'leise', 'laut', 'weich', 'warm', 'kühl', 'hungrig', 'satt',
        'wach', 'verträumt', 'glücklich', 'zufrieden', 'geduldig', 'eifrig',
        'gemütlich', 'heiter', 'herzlich', 'höflich', 'artig', 'keck',
        'lebhaft', 'listig', 'lieb', 'nett', 'offen', 'ordentlich', 'pfiffig',
        'prächtig', 'putzig', 'quirlig', 'rasch', 'sauber', 'schüchtern',
        'sportlich', 'stolz', 'tapsig', 'treu', 'übermütig', 'verspielt',
        'wachsam', 'wendig', 'witzig', 'zart', 'zäh', 'fleißig', 'geschickt',
        'gesund', 'glatt', 'grimmig', 'hell', 'jung', 'kräftig', 'langsam',
        'leicht', 'nass', 'niedlich', 'rund', 'schlank', 'schwer', 'sonnig',
        'sparsam', 'still', 'streng', 'trocken', 'verschmust', 'vorsichtig',
        'weise', 'wuschelig', 'zutraulich', 'brummig', 'dankbar', 'eilig',
        'emsig', 'flauschig', 'gelassen', 'hilfsbereit', 'kribbelig',
        'schusselig', 'sprunghaft', 'staunend', 'zappelig',
    ];
}

/** Tiere mit Geschlecht: m = der, f = die, n = das. */
function password_seed_animals(): array
{
    return [
        ['Gepard', 'm'], ['Bär', 'm'], ['Adler', 'm'], ['Affe', 'm'],
        ['Dachs', 'm'], ['Delfin', 'm'], ['Elefant', 'm'], ['Esel', 'm'],
        ['Falke', 'm'], ['Frosch', 'm'], ['Fuchs', 'm'], ['Hamster', 'm'],
        ['Hase', 'm'], ['Hirsch', 'm'], ['Hund', 'm'], ['Igel', 'm'],
        ['Käfer', 'm'], ['Kater', 'm'], ['Löwe', 'm'], ['Marder', 'm'],
        ['Panda', 'm'], ['Papagei', 'm'], ['Pinguin', 'm'], ['Rabe', 'm'],
        ['Schmetterling', 'm'], ['Seehund', 'm'], ['Specht', 'm'],
        ['Storch', 'm'], ['Tiger', 'm'], ['Wal', 'm'], ['Waschbär', 'm'],
        ['Wolf', 'm'], ['Biber', 'm'], ['Eisbär', 'm'], ['Elch', 'm'],
        ['Kranich', 'm'], ['Leopard', 'm'], ['Luchs', 'm'], ['Maulwurf', 'm'],
        ['Pfau', 'm'], ['Puma', 'm'], ['Salamander', 'm'], ['Schwan', 'm'],
        ['Uhu', 'm'], ['Wellensittich', 'm'], ['Zeisig', 'm'],

        ['Schnecke', 'f'], ['Ameise', 'f'], ['Biene', 'f'], ['Ente', 'f'],
        ['Eule', 'f'], ['Fledermaus', 'f'], ['Gans', 'f'], ['Giraffe', 'f'],
        ['Grille', 'f'], ['Hummel', 'f'], ['Katze', 'f'], ['Krähe', 'f'],
        ['Kuh', 'f'], ['Libelle', 'f'], ['Maus', 'f'], ['Meise', 'f'],
        ['Möwe', 'f'], ['Qualle', 'f'], ['Robbe', 'f'], ['Schildkröte', 'f'],
        ['Schlange', 'f'], ['Schwalbe', 'f'], ['Spinne', 'f'], ['Taube', 'f'],
        ['Ziege', 'f'], ['Amsel', 'f'], ['Eidechse', 'f'], ['Elster', 'f'],
        ['Forelle', 'f'], ['Krabbe', 'f'], ['Lerche', 'f'], ['Muschel', 'f'],
        ['Nachtigall', 'f'], ['Raupe', 'f'], ['Seekuh', 'f'],

        ['Nilpferd', 'n'], ['Eichhörnchen', 'n'], ['Fohlen', 'n'],
        ['Huhn', 'n'], ['Kamel', 'n'], ['Känguru', 'n'], ['Kaninchen', 'n'],
        ['Lamm', 'n'], ['Meerschweinchen', 'n'], ['Pferd', 'n'], ['Reh', 'n'],
        ['Rentier', 'n'], ['Schaf', 'n'], ['Zebra', 'n'], ['Faultier', 'n'],
        ['Frettchen', 'n'], ['Krokodil', 'n'], ['Küken', 'n'],
        ['Murmeltier', 'n'], ['Nashorn', 'n'], ['Okapi', 'n'], ['Pony', 'n'],
        ['Wiesel', 'n'], ['Chamäleon', 'n'], ['Erdmännchen', 'n'],
        ['Gürteltier', 'n'], ['Seepferdchen', 'n'], ['Walross', 'n'],
        ['Zicklein', 'n'],
    ];
}

/** Die Saat als eine einzige Anweisung - so passt sie in eine Migration. */
function password_seed_sql(): string
{
    $q = static fn (string $s): string => "'" . str_replace("'", "''", $s) . "'";

    $zeilen = [];
    foreach (password_seed_adjectives() as $a) {
        $zeilen[] = '(' . $q(PW_ADJECTIVE) . ', ' . $q($a) . ', NULL)';
    }
    foreach (password_seed_animals() as [$t, $g]) {
        $zeilen[] = '(' . $q(PW_ANIMAL) . ', ' . $q($t) . ', ' . $q($g) . ')';
    }

    return 'INSERT IGNORE INTO password_words (kind, word, gender) VALUES '
         . implode(', ', $zeilen);
}
