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

        /*
         * Bindestrich statt Leerzeichen.
         *
         * Ein Leerzeichen im Passwort ist auf einer Tablet-Tastatur die
         * grosse Taste unten, und wer sie zweimal trifft, kommt nicht
         * hinein. Der Bindestrich steht daneben und laesst sich nicht
         * verdoppeln, ohne dass man es sieht. Ausserdem markiert ein
         * Doppelklick das ganze Wort statt nur der Haelfte - auf dem Zettel
         * steht es damit als EIN Wort da.
         *
         * Nur fuer neue: Was vergeben ist, bleibt, wie es ist. Ein Kind mit
         * "mueder Gepard" auf dem Zettel soll sich weiter anmelden koennen,
         * und password_tidy() kuemmert sich unveraendert um die
         * Leerzeichen darin.
         */
        $wort = $a['word'] . password_ending((string) ($t['gender'] ?? 'm'))
              . '-' . $t['word'];

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

// -------------------------------------------------------------- Pflege

/**
 * Die Liste zum Bearbeiten: ein Wort je Zeile, bei Tieren mit Geschlecht.
 *
 * Ein Textfeld statt einer Tabelle mit hundert Zeilen. Wer ein Wort streichen
 * will, markiert die Zeile und drückt Entf - das ist schneller als hundert
 * Häkchen, und die Liste lässt sich am Stück ersetzen.
 */
function password_words_text(string $kind): string
{
    $zeilen = [];
    foreach (password_words($kind) as $w) {
        $zeilen[] = $kind === PW_ANIMAL
            ? $w['word'] . ' ' . $w['gender']
            : $w['word'];
    }
    return implode("\n", $zeilen);
}

/**
 * Ersetzt die Liste einer Wortart durch die eingegebene.
 *
 * Gestrichene Wörter werden nicht gelöscht, sondern abgeschaltet: Wer aus
 * Versehen die halbe Liste markiert hat, soll sie wiederbekommen können, und
 * eine abgeschaltete Zeile kostet nichts.
 *
 * @return array{0: int, 1: ?string} Anzahl und, falls etwas nicht ging, eine Meldung
 */
function password_words_replace(string $kind, string $text): array
{
    $woerter = [];
    $fehler  = [];

    foreach (preg_split('/\R/u', $text) ?: [] as $nr => $zeile) {
        $zeile = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $zeile) ?? $zeile);
        if ($zeile === '') {
            continue;
        }

        if ($kind === PW_ANIMAL) {
            $teile   = explode(' ', $zeile);
            $genus   = mb_strtolower((string) array_pop($teile));
            $wort    = implode(' ', $teile);

            if (!in_array($genus, ['m', 'f', 'n'], true)) {
                $fehler[] = sprintf('Zeile %d ("%s"): am Ende fehlt m, f oder n',
                                    $nr + 1, mb_substr($zeile, 0, 24));
                continue;
            }
        } else {
            $wort  = $zeile;
            $genus = null;
        }

        if ($wort === '' || mb_strlen($wort) > 48
            || preg_match('/^[\p{L}\-]+$/u', $wort) !== 1) {
            $fehler[] = sprintf('Zeile %d ("%s"): nur Buchstaben und Bindestriche',
                                $nr + 1, mb_substr($zeile, 0, 24));
            continue;
        }

        $woerter[$wort] = $genus;
    }

    if ($fehler !== []) {
        return [0, implode('; ', array_slice($fehler, 0, 3))
                   . (count($fehler) > 3 ? sprintf(' (und %d weitere)', count($fehler) - 3) : '')];
    }

    /*
     * Unter zwanzig wird es zu eng. Zwanzig Adjektive und zwanzig Tiere
     * ergeben vierhundert Kombinationen - dagegen hilft auch die
     * Anmeldebremse nicht mehr zuverlässig.
     */
    if (count($woerter) < 20) {
        return [0, sprintf('Zu wenige Wörter (%d). Mindestens zwanzig, sonst sind '
                           . 'die Passwörter zu leicht zu erraten.', count($woerter))];
    }

    db()->beginTransaction();
    try {
        q('UPDATE password_words SET active = 0 WHERE kind = ?', [$kind]);
        foreach ($woerter as $wort => $genus) {
            q('INSERT INTO password_words (kind, word, gender, active)
               VALUES (?, ?, ?, 1)
               ON DUPLICATE KEY UPDATE gender = VALUES(gender), active = 1',
              [$kind, (string) $wort, $genus]);
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        return [0, 'Speichern fehlgeschlagen: ' . $e->getMessage()];
    }

    return [count($woerter), null];
}
