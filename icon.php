<?php
declare(strict_types=1);

/**
 * Icon-Generator für Homescreen und Manifest.
 *
 * Zeichnet ein deckendes Quadrat in der Account-Farbe mit der Initiale des
 * Kindes. Deckend und ohne eigene Rundung, weil iOS die Ecken selbst maskiert -
 * ein transparenter Hintergrund würde dort schwarz erscheinen.
 * Ergebnisse werden unter storage/icons/ gecacht.
 */

require_once __DIR__ . '/lib/db.php';

$uid      = isset($_GET['u']) ? (int) $_GET['u'] : 0;
$size     = isset($_GET['s']) ? (int) $_GET['s'] : 192;
$maskable = !empty($_GET['p']);

$size = max(48, min(1024, $size));

$user = $uid > 0 ? q1('SELECT display_name, color FROM users WHERE id = ?', [$uid]) : null;
if ($user === null) {
    $user = ['display_name' => 'V', 'color' => '#4f7cff'];
}

$color   = preg_match('/^#[0-9a-f]{6}$/i', $user['color']) ? $user['color'] : '#4f7cff';
$initial = mb_strtoupper(mb_substr(trim($user['display_name']), 0, 1)) ?: 'V';

$cacheKey  = sprintf('%d-%d-%s-%s-%s', $uid, $size, $maskable ? 'm' : 'n', ltrim($color, '#'), $initial);
$cacheFile = __DIR__ . '/storage/icons/' . hash('sha256', $cacheKey) . '.png';

header('Content-Type: image/png');
header('Cache-Control: public, max-age=604800');

if (is_file($cacheFile) && filesize($cacheFile) > 0) {
    readfile($cacheFile);
    exit;
}

$img = imagecreatetruecolor($size, $size);
imagealphablending($img, true);

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

// Maskable-Variante braucht Rand ("safe zone"), damit Android nichts abschneidet.
$scale    = $maskable ? 0.42 : 0.56;
$fontFile = __DIR__ . '/assets/Roboto-Bold.ttf';

// Schriftfarbe nach der Helligkeit des Untergrunds. Die Palette im Admin reicht
// von sehr hell bis sehr dunkel - weisse Schrift waere auf einem hellen Gelb
// nicht zu lesen. Die Gewichte stammen aus der Helligkeitsformel für sRGB.
$helligkeit = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
$ink        = $helligkeit > 0.62
    ? imagecolorallocatealpha($img, 26, 26, 30, 12)
    : imagecolorallocatealpha($img, 255, 255, 255, 12);

if (is_file($fontFile) && function_exists('imagettfbbox')) {
    $fontSize = $size * $scale;
    $bbox     = imagettfbbox($fontSize, 0, $fontFile, $initial);
    if ($bbox !== false) {
        $textW = $bbox[2] - $bbox[0];
        $textH = $bbox[1] - $bbox[7];
        $x     = (int) (($size - $textW) / 2 - $bbox[0]);
        $y     = (int) (($size + $textH) / 2 - ($bbox[1]));
        imagettftext($img, $fontSize, 0, $x, $y, $ink, $fontFile, $initial);
    }
} else {
    // Fallback ohne FreeType: schlichter weißer Balken als Unterscheidungsmerkmal.
    $m = (int) ($size * 0.3);
    imagefilledrectangle($img, $m, (int) ($size * 0.46), $size - $m, (int) ($size * 0.54), $white);
}

imagepng($img, $cacheFile, 6);
imagepng($img);
imagedestroy($img);
