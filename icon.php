<?php
declare(strict_types=1);

/**
 * Icon-Generator für Homescreen, Manifest und Favicon.
 *
 * Zeichnet ein deckendes Quadrat in der Account-Farbe und darauf den frohen
 * Voki mit weissem Rand. Deckend und ohne eigene Rundung, weil iOS die Ecken
 * selbst maskiert - ein transparenter Hintergrund würde dort schwarz
 * erscheinen. Ergebnisse werden unter storage/icons/ gecacht.
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
 * farbiges Quadrat stehen).
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

$uid      = isset($_GET['u']) ? (int) $_GET['u'] : 0;
$size     = isset($_GET['s']) ? (int) $_GET['s'] : 192;
$maskable = !empty($_GET['p']);
$favicon  = !empty($_GET['f']);

$size = max($favicon ? 16 : 48, min(1024, $size));

$user  = $uid > 0 ? q1('SELECT color FROM users WHERE id = ?', [$uid]) : null;
$color = preg_match('/^#[0-9a-f]{6}$/i', (string) ($user['color'] ?? ''))
    ? $user['color'] : '#4f7cff';

$vorlage = __DIR__ . '/assets/voki-icon.png';

/*
 * Die Vorlage gehört in den Schlüssel. Sonst liefert der Zwischenspeicher
 * nach einer neuen Zeichnung weiter die alte aus - bis jemand von Hand
 * storage/icons/ leert, und darauf kommt niemand.
 */
$cacheKey = sprintf(
    'voki-%d-%d-%s-%s-%d',
    $favicon ? 0 : $uid, $size,
    $favicon ? 'f' : ($maskable ? 'm' : 'n'),
    $favicon ? '' : ltrim($color, '#'),
    is_file($vorlage) ? filemtime($vorlage) : 0,
);
$cacheFile = __DIR__ . '/storage/icons/' . hash('sha256', $cacheKey) . '.png';

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
    $anteil = $favicon ? 1.0 : ($maskable ? VOKI_ANTEIL_MASKABLE : VOKI_ANTEIL);
    $kante  = (int) round($size * $anteil);
    $rand   = intdiv($size - $kante, 2);

    // imagecopyresampled mischt mit dem Alphakanal der Vorlage, solange
    // alphablending am Ziel an ist - der Rand bleibt weich statt gezackt.
    imagecopyresampled($img, $voki, $rand, $rand, 0, 0, $kante, $kante,
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

imagepng($img, $cacheFile, 6);
imagepng($img);
imagedestroy($img);
