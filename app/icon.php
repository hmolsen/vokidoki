<?php
declare(strict_types=1);

/**
 * Icon-Generator für Homescreen, Manifest und Favicon.
 *
 * Zeichnet ein deckendes Quadrat in der Account-Farbe und darauf den frohen
 * Voki mit weissem Rand. Deckend und ohne eigene Rundung, weil iOS die Ecken
 * selbst maskiert - ein transparenter Hintergrund würde dort schwarz
 * erscheinen. Ergebnisse werden unter daten/storage/icons/ gecacht.
 *
 * Hier stand einmal der Anfangsbuchstabe des Kindes, hell oder dunkel je nach
 * Farbe, mit Roboto gesetzt. Voki braucht keine Schriftfarbe: Der weisse Rand
 * trennt ihn von jeder Farbe der Palette, auch von Gruen und Gelb, in denen
 * er und seine Sterne sonst verschwaenden.
 *
 * GD liest kein SVG. Voki liegt deshalb als fertiges PNG mit durchsichtigem
 * Grund bereit (assets/voki-icon.png), gebaut aus assets/voki-mini.svg von
 * tests/browser/voki-symbol.mjs.
 *
 * Parameter: u = Konto, s = Kantenlänge, p = maskable (mit Schutzrand für
 * Android), f = Favicon (nur Voki, ohne Fläche - im Browser-Tab soll kein
 * farbiges Quadrat stehen), w = Verwaltung (grauer Balken mit dem Wort).
 *
 * Die Verwaltung bekommt ein eigenes Symbol, weil eine Lehrkraft oft beide
 * auf dem Telefon hat: die Lernansicht, die ihre Klasse sieht, und den
 * Lehrkraft-Bereich. Zwei gleiche Vokis nebeneinander waren nicht zu
 * unterscheiden. Die Farbe bleibt die des Kontos - es ist dieselbe Person -,
 * unten liegt ein grauer Balken mit "Verwaltung", und Voki rückt dafür
 * kleiner nach oben.
 *
 * Das Wort ist ein fertiges Bild (assets/verwaltung-schrift.png, gebaut von
 * tests/browser/verwaltung-schrift.mjs): GD setzt keine woff2-Schrift.
 */

require_once __DIR__ . '/lib/db.php';

/*
 * Wie viel der Kante Voki einnimmt. Die Vorlage ist schon eng um die Figur
 * geschnitten, der Rand also Teil davon.
 *
 * Maskable kleiner: Android schneidet auf einen Kreis von 80 % Durchmesser
 * zu, und die Sterne an den Ecken lagen sonst darausserhalb. Die Vorschau im
 * Profil (views/profile.js, .appsymbol img in style.css) nimmt dieselben
 * 84 % - eine Prüfung in tests/e2e.php hält die beiden Stellen zusammen.
 */
const VOKI_ANTEIL          = 0.84;
const VOKI_ANTEIL_MASKABLE = 0.66;

/*
 * Die Verwaltung: Balken vom unteren Rand herauf, Voki darüber.
 *
 * Maskable beginnt der Balken höher und das Wort sitzt in seinem oberen
 * Teil, schmaler - Android schneidet auf einen Kreis von 80 % Durchmesser,
 * und an der Unterkante bliebe vom Wort nur die Mitte. Der Balken läuft
 * trotzdem bis ganz unten, sonst stuende unter ihm ein Streifen Farbe.
 *
 * Als Anteile der Kante: [Voki, Voki oben, Balken oben, Wort unten, Wortbreite].
 */
const VERWALTUNG          = [0.64, 0.06, 0.77, 1.00, 0.62];
const VERWALTUNG_MASKABLE = [0.46, 0.13, 0.63, 0.80, 0.46];
const VERWALTUNG_GRAU     = [84, 90, 100];

$uid      = isset($_GET['u']) ? (int) $_GET['u'] : 0;
$size     = isset($_GET['s']) ? (int) $_GET['s'] : 192;
$maskable = !empty($_GET['p']);
$favicon  = !empty($_GET['f']);
$verwaltung = !empty($_GET['w']) && !$favicon;

$size = max($favicon ? 16 : 48, min(1024, $size));

$user  = $uid > 0 ? q1('SELECT color FROM users WHERE id = ?', [$uid]) : null;
$color = preg_match('/^#[0-9a-f]{6}$/i', (string) ($user['color'] ?? ''))
    ? $user['color'] : '#4f7cff';

$vorlage = __DIR__ . '/assets/voki-icon.png';
$wortBild = __DIR__ . '/assets/verwaltung-schrift.png';

/*
 * Die Vorlage gehört in den Schlüssel. Sonst liefert der Zwischenspeicher
 * nach einer neuen Zeichnung weiter die alte aus - bis jemand von Hand
 * daten/storage/icons/ leert, und darauf kommt niemand.
 */
$cacheKey = sprintf(
    'voki-%d-%d-%s-%s-%d-%s',
    $favicon ? 0 : $uid, $size,
    $favicon ? 'f' : ($maskable ? 'm' : 'n'),
    $favicon ? '' : ltrim($color, '#'),
    is_file($vorlage) ? filemtime($vorlage) : 0,
    $verwaltung ? 'w' . (is_file($wortBild) ? filemtime($wortBild) : 0) : '',
);
$cacheFile = storage_path('icons/' . hash('sha256', $cacheKey) . '.png');

header('Content-Type: image/png');
header('Cache-Control: public, max-age=604800');

if (is_file($cacheFile) && filesize($cacheFile) > 0) {
    readfile($cacheFile);
    exit;
}

$img = imagecreatetruecolor($size, $size);

if ($favicon) {
    // Durchsichtiger Grund - sonst stuende ein schwarzes Quadrat im Tab.
    imagealphablending($img, false);
    imagesavealpha($img, true);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
    imagealphablending($img, true);
} else {
    [$r, $g, $b] = sscanf($color, '#%02x%02x%02x');

    // Sanfter Verlauf von der Account-Farbe zu einer abgedunkelten Variante -
    // wirkt auf dem Homescreen weniger flach als eine einzelne Fläche.
    $dark = static fn (int $c): int => (int) max(0, min(255, $c * 0.68));
    for ($y = 0; $y < $size; $y++) {
        $t    = $y / max(1, $size - 1);
        $line = imagecolorallocate(
            $img,
            (int) ($r + ($dark($r) - $r) * $t),
            (int) ($g + ($dark($g) - $g) * $t),
            (int) ($b + ($dark($b) - $b) * $t),
        );
        imageline($img, 0, $y, $size, $y, $line);
    }
}

$voki = is_file($vorlage) ? @imagecreatefrompng($vorlage) : false;

if ($voki !== false) {
    if ($verwaltung) {
        [$anteil, $oben] = $maskable ? VERWALTUNG_MASKABLE : VERWALTUNG;
        $kante = (int) round($size * $anteil);
        $links = intdiv($size - $kante, 2);
        $top   = (int) round($size * $oben);
    } else {
        $anteil = $favicon ? 1.0 : ($maskable ? VOKI_ANTEIL_MASKABLE : VOKI_ANTEIL);
        $kante  = (int) round($size * $anteil);
        $links  = $top = intdiv($size - $kante, 2);
    }

    // imagecopyresampled mischt mit dem Alphakanal der Vorlage, solange
    // alphablending am Ziel an ist - der Rand bleibt weich statt gezackt.
    imagecopyresampled($img, $voki, $links, $top, 0, 0, $kante, $kante,
                       imagesx($voki), imagesy($voki));
    imagedestroy($voki);
} elseif (!$favicon) {
    // Ohne Vorlage: ein weisser Punkt als Unterscheidungsmerkmal, damit
    // wenigstens kein leeres Quadrat auf dem Home-Bildschirm liegt. Der
    // Selbsttest meldet die fehlende Datei.
    $weiss = imagecolorallocatealpha($img, 255, 255, 255, 20);
    imagefilledellipse($img, intdiv($size, 2), intdiv($size, 2),
                       (int) ($size * 0.3), (int) ($size * 0.3), $weiss);
}

if ($verwaltung) {
    [, , $balkenOben, $wortUnten, $wortAnteil] = $maskable ? VERWALTUNG_MASKABLE : VERWALTUNG;
    $y0 = (int) round($size * $balkenOben);
    $y1 = (int) round($size * $wortUnten) - 1;
    imagefilledrectangle($img, 0, $y0, $size - 1, $size - 1,
                         imagecolorallocate($img, ...VERWALTUNG_GRAU));

    $wort = is_file($wortBild) ? @imagecreatefrompng($wortBild) : false;
    if ($wort !== false) {
        // So breit wie vorgesehen, aber nie höher als der Platz dafür.
        $breite = $size * $wortAnteil;
        $hoehe  = $breite * imagesy($wort) / imagesx($wort);
        $platz  = ($y1 - $y0) * 0.8;
        if ($hoehe > $platz) {
            $breite *= $platz / $hoehe;
            $hoehe   = $platz;
        }
        imagecopyresampled($img, $wort,
            (int) round(($size - $breite) / 2), (int) round($y0 + ($y1 - $y0 - $hoehe) / 2),
            0, 0, (int) round($breite), (int) round($hoehe), imagesx($wort), imagesy($wort));
        imagedestroy($wort);
    }
}

imagepng($img, $cacheFile, 6);
imagepng($img);
imagedestroy($img);
