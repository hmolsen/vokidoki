<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/html.php';

/**
 * Fahnen, die überall zu sehen sind.
 *
 * Die Fahnen stehen als Emoji in der Datenbank, und auf dem Handy sehen sie
 * gut aus. Unter Windows sieht man statt der britischen Fahne die zwei
 * Buchstaben „GB" - Windows liefert für die Regionalzeichen keine Fahnen aus,
 * und kein Schriftschnitt der Seite ändert daran etwas. Das ist kein Fehler
 * der Anwendung, sondern eine Entscheidung des Betriebssystems; umgehen lässt
 * sie sich nur, indem man das Bild selbst mitbringt.
 *
 * Also bringen wir es mit: Zu jedem Sinnbild liegt in assets/flags eine
 * SVG-Datei, benannt nach den Unicode-Stellen. Was dort liegt, wird als Bild
 * ausgeliefert; was fehlt, bleibt das Emoji - dann sieht es auf dem Handy
 * weiterhin richtig aus und unter Windows so wie bisher. Eine Sprache ohne
 * Fahne verliert dadurch nichts.
 *
 * Seiteneffektfrei: nur Zeichenketten, keine Datenbank, kein Ausgeben.
 */

/** Wo die Dateien liegen, vom Projektstamm aus. */
const FLAG_DIR = 'assets/flags';

/**
 * Der Dateiname zu einem Sinnbild, oder null.
 *
 * Twemoji benennt nach den Unicode-Stellen in Kleinbuchstaben, verbunden mit
 * Bindestrichen. Die Variantenwahl U+FE0F fällt dabei weg - sie sagt nur
 * „bitte farbig" und gehört nicht zum Zeichen.
 */
function flag_file(string $emoji): ?string
{
    $teile = preg_split('//u', trim($emoji), -1, PREG_SPLIT_NO_EMPTY);
    if ($teile === false || $teile === []) {
        return null;
    }

    $stellen = [];
    foreach ($teile as $zeichen) {
        $nr = mb_ord($zeichen, 'UTF-8');
        if ($nr === false || $nr === 0xFE0F) {
            continue;
        }
        $stellen[] = dechex($nr);
    }

    return $stellen === [] ? null : implode('-', $stellen);
}

/**
 * Gibt es zu diesem Sinnbild ein Bild?
 *
 * Der Blick auf die Platte statt eine feste Liste: Kommt eine Sprache dazu,
 * genügt es, die Datei danebenzulegen.
 */
function flag_path(string $emoji): ?string
{
    $name = flag_file($emoji);
    if ($name === null) {
        return null;
    }

    $pfad = dirname(__DIR__) . '/' . FLAG_DIR . '/' . $name . '.svg';
    return is_file($pfad) ? FLAG_DIR . '/' . $name . '.svg' : null;
}

/**
 * Das fertige Stück HTML für eine Fahne.
 *
 * Ohne alt-Text: Daneben steht immer der Name der Sprache, und eine Fahne,
 * die „Flagge von Grossbritannien" vorliest, sagt einem Vorleseprogramm
 * nichts, was nicht schon dasteht.
 */
function flag_html(string $emoji, string $klasse = 'flag'): string
{
    $emoji = trim($emoji);
    if ($emoji === '') {
        return '';
    }

    $pfad = flag_path($emoji);
    if ($pfad === null) {
        return '<span class="' . h($klasse) . '">' . h($emoji) . '</span>';
    }

    return '<img class="' . h($klasse) . '" src="' . h(url($pfad))
         . '" alt="" width="24" height="24">';
}
