<?php
declare(strict_types=1);

/**
 * Die Farbpalette für die Homescreen-Symbole.
 *
 * Bewusst ohne Seiteneffekte in einer eigenen Datei: admin/_boot.php startet
 * beim Laden eine Sitzung, und das vertragen weder die Tests noch irgendein
 * anderer Aufrufer, der nur die Farben braucht.
 */

/** Kurzform für die Ausgabe - hier ohne Abhängigkeit vom Admin-Gerüst. */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}


/**
 * 49 Farben als 7x7-Feld: sieben Farbtöne, je sieben Helligkeiten von hell
 * nach dunkel. Berechnet statt aufgelistet - so bleiben die Abstufungen
 * gleichmäßig und die Reihenfolge ergibt das Regenbogenmuster von selbst.
 *
 * Sieben, weil das Feld in der App dieselben Kästchen hat wie der
 * Monatskalender darüber - sieben Spalten, eine je Wochentag. Acht Spalten
 * daneben sahen aus wie ein verrutschter Kalender. Weggefallen ist dafür
 * ein Ton zwischen Blau und Rosa; das Lila liegt jetzt dazwischen.
 */
function color_palette(): array
{
    $huePerRow = [0, 30, 52, 130, 190, 222, 290];
    $steps     = [0.80, 0.70, 0.61, 0.52, 0.43, 0.35, 0.27];

    $farben = [];
    foreach ($huePerRow as $hue) {
        foreach ($steps as $light) {
            // Helle Töne wirken schnell blass, dunkle schnell matschig -
            // deshalb die Sättigung an den Rändern etwas anheben.
            $sat      = $light > 0.7 || $light < 0.35 ? 0.72 : 0.64;
            $farben[] = hsl_to_hex((float) $hue, $sat, $light);
        }
    }
    return $farben;
}

/**
 * Prüft nur das Format, nicht die Zugehörigkeit zur aktuellen Palette.
 * Sonst liesse sich eine früher gesetzte Farbe beim Speichern nicht halten,
 * wenn die Palette einmal wechselt.
 *
 * Lag früher in admin/users.php. Seit auch die App die Farbe ändern lässt,
 * brauchen zwei sehr verschiedene Stellen dieselbe Regel - und eine Regel,
 * die es zweimal gibt, ist bald zwei verschiedene Regeln.
 */
function valid_color(string $color): string
{
    return preg_match('/^#[0-9a-f]{6}$/i', $color) === 1
        ? strtolower($color)
        : color_default();
}

/**
 * Ein kräftiges Blau - die Farbe, wenn keine andere feststeht.
 *
 * Über den Namen und nicht als color_palette()[27] an zwei Stellen: Mit dem
 * 8x8-Feld war 27 Blau, im 7x7-Feld wäre es ein Grün gewesen.
 */
function color_default(): string
{
    return color_palette()[5 * 7 + 3];   // Zeile Blau, mittlere Helligkeit
}

function hsl_to_hex(float $h, float $s, float $l): string
{
    $c = (1 - abs(2 * $l - 1)) * $s;
    $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
    $m = $l - $c / 2;

    [$r, $g, $b] = match (true) {
        $h < 60  => [$c, $x, 0.0],
        $h < 120 => [$x, $c, 0.0],
        $h < 180 => [0.0, $c, $x],
        $h < 240 => [0.0, $x, $c],
        $h < 300 => [$x, 0.0, $c],
        default  => [$c, 0.0, $x],
    };

    return sprintf('#%02x%02x%02x',
        (int) round(($r + $m) * 255),
        (int) round(($g + $m) * 255),
        (int) round(($b + $m) * 255));
}

/**
 * Farbwahl als Flyout.
 *
 * Das Raster stand vorher offen in der Bearbeitungszeile eines Accounts - in
 * einer flex-Zeile schrumpften die Kacheln auf Pixelgrösse, während die
 * Abstände blieben. Jetzt zeigt ein Knopf die aktuelle Farbe und klappt das
 * Feld darüber auf.
 *
 * details/summary statt eigener Klapplogik: Das kommt ohne JavaScript aus und
 * ist mit der Tastatur bedienbar.
 */
function color_picker(string $selected, string $name = 'color'): string
{
    $farben = color_palette();
    $gewaehlt = preg_match('/^#[0-9a-f]{6}$/i', $selected) === 1 ? strtolower($selected) : color_default();

    $kacheln = '';
    foreach ($farben as $farbe) {
        $kacheln .= sprintf(
            '<label class="swatch-pick" style="--c:%s" title="%s">'
                . '<input type="radio" name="%s" value="%s"%s><span></span></label>',
            e($farbe), e($farbe), e($name), e($farbe),
            $farbe === $gewaehlt ? ' checked' : '',
        );
    }

    // Eine Farbe aus einer früheren Palette darf nicht verlorengehen.
    $extra = '';
    if (!in_array($gewaehlt, $farben, true)) {
        $extra = sprintf(
            '<label class="swatch-pick extra" style="--c:%s" title="bisherige Farbe %s">'
                . '<input type="radio" name="%s" value="%s" checked><span></span></label>',
            e($gewaehlt), e($gewaehlt), e($name), e($gewaehlt),
        );
    }

    return sprintf(
        '<details class="colorpick">'
            . '<summary title="Farbe wählen"><span class="swatch-current" style="--c:%s"></span></summary>'
            . '<div class="colorpop"><div class="palette">%s%s</div></div>'
            . '</details>',
        e($gewaehlt), $kacheln, $extra,
    );
}
