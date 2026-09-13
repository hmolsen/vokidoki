<?php
declare(strict_types=1);

/**
 * QR-Codes, als SVG.
 *
 * Warum selbst gebaut: Die Anwendung wird per FTP hochgeladen, vendor/ wird
 * nicht mit übertragen und composer install läuft auf dem Zielsystem nicht.
 * Eine neue Abhängigkeit ist damit faktisch ausgeschlossen - also eine Datei
 * ohne alles, die man mitkopiert und die dann funktioniert.
 *
 * Bewusst eng zugeschnitten: nur Byte-Modus, nur Fehlerkorrektur M, nur die
 * Versionen 1 bis 6. Das reicht für 108 Zeichen, und mehr als eine Adresse
 * steht hier nie drin. Der Zuschnitt spart die Versionsinformation (erst ab
 * Version 7 nötig) und die grossen Blocktabellen - und damit die Stellen, an
 * denen ein selbstgebauter Encoder still falsch wird.
 *
 * Geprüft wird das Ergebnis nicht am Augenschein, sondern indem ein
 * Decoder es wieder lesen muss; siehe den Abschnitt "QR-Code" in tests/e2e.php.
 *
 * Aufbau nach ISO/IEC 18004. Die Struktur folgt der gut nachvollziehbaren
 * Referenzumsetzung von Project Nayuki (MIT).
 */

/** Fehlerkorrektur M: mittlere Stufe, verträgt etwa 15 % Schaden. */
const QR_ECC_M = 0;

/**
 * Blockaufteilung je Version bei Stufe M.
 * [Fehlerkorrektur-Bytes je Block, Anzahl Blöcke, Datenbytes je Block]
 *
 * Bei M haben in diesen Versionen alle Blöcke dieselbe Länge - deshalb fehlt
 * hier die zweite Gruppe, die die Norm sonst kennt.
 */
const QR_BLOCKS_M = [
    1 => [10, 1, 16],
    2 => [16, 1, 28],
    3 => [26, 1, 44],
    4 => [18, 2, 32],
    5 => [24, 2, 43],
    6 => [16, 4, 27],
];

/** Mitte des Ausrichtungsmusters je Version. Version 1 hat keines. */
const QR_ALIGN = [1 => 0, 2 => 18, 3 => 22, 4 => 26, 5 => 30, 6 => 34];

// ------------------------------------------------------- Galois-Rechnung

/** Multiplikation im Körper GF(256) mit dem Polynom 0x11D. */
function qr_gf_mul(int $x, int $y): int
{
    $z = 0;
    for ($i = 7; $i >= 0; $i--) {
        $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
        $z ^= ((($y >> $i) & 1) * $x) & 0xFF;
    }
    return $z & 0xFF;
}

/** Das Generatorpolynom für so viele Fehlerkorrektur-Bytes. */
function qr_rs_divisor(int $degree): array
{
    $result = array_fill(0, $degree, 0);
    $result[$degree - 1] = 1;

    $root = 1;
    for ($i = 0; $i < $degree; $i++) {
        for ($j = 0; $j < $degree; $j++) {
            $result[$j] = qr_gf_mul($result[$j], $root);
            if ($j + 1 < $degree) {
                $result[$j] ^= $result[$j + 1];
            }
        }
        $root = qr_gf_mul($root, 2);
    }

    return $result;
}

/** Die Fehlerkorrektur-Bytes zu einem Datenblock. */
function qr_rs_remainder(array $data, array $divisor): array
{
    $degree = count($divisor);
    $result = array_fill(0, $degree, 0);

    foreach ($data as $b) {
        $factor = ($b ^ $result[0]) & 0xFF;
        array_shift($result);
        $result[] = 0;
        for ($i = 0; $i < $degree; $i++) {
            $result[$i] ^= qr_gf_mul($divisor[$i], $factor);
        }
    }

    return $result;
}

// ---------------------------------------------------------------- Daten

/**
 * Die kleinste Version, in die der Text passt - oder null.
 *
 * Die Zeichenzahl steht bis Version 9 in 8 Bit, und der Kopf aus Modus und
 * Länge kostet 12 Bit.
 */
function qr_pick_version(int $length): ?int
{
    foreach (QR_BLOCKS_M as $version => [$eccPerBlock, $blocks, $dataPerBlock]) {
        if ($length + 2 <= $blocks * $dataPerBlock) {
            return $version;
        }
    }
    return null;
}

/** Text zu Datenbytes: Kopf, Nutzlast, Abschluss, Auffüllung. */
function qr_data_codewords(string $text, int $version): array
{
    [$eccPerBlock, $blocks, $dataPerBlock] = QR_BLOCKS_M[$version];
    $capacity = $blocks * $dataPerBlock;

    $bits = '0100';                                     // Modus: Bytes
    $bits .= str_pad(decbin(strlen($text)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($text) as $ch) {
        $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
    }

    // Abschluss: bis zu vier Nullen, aber nicht über die Kapazität hinaus.
    $bits .= str_repeat('0', min(4, $capacity * 8 - strlen($bits)));

    // Auf ganze Bytes bringen.
    $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);

    $codewords = [];
    foreach (str_split($bits, 8) as $byte) {
        $codewords[] = bindec($byte);
    }

    // Der Rest wird mit diesen beiden Werten im Wechsel gefüllt; so schreibt
    // es die Norm vor, damit keine grossen einfarbigen Flächen entstehen.
    $fueller = [0xEC, 0x11];
    for ($i = 0; count($codewords) < $capacity; $i++) {
        $codewords[] = $fueller[$i % 2];
    }

    return $codewords;
}

/** Datenbytes und Fehlerkorrektur verschränken, wie die Norm es verlangt. */
function qr_interleave(array $codewords, int $version): array
{
    [$eccPerBlock, $blocks, $dataPerBlock] = QR_BLOCKS_M[$version];
    $divisor = qr_rs_divisor($eccPerBlock);

    $datenBloecke = [];
    $eccBloecke   = [];
    for ($b = 0; $b < $blocks; $b++) {
        $block          = array_slice($codewords, $b * $dataPerBlock, $dataPerBlock);
        $datenBloecke[] = $block;
        $eccBloecke[]   = qr_rs_remainder($block, $divisor);
    }

    $out = [];
    for ($i = 0; $i < $dataPerBlock; $i++) {
        for ($b = 0; $b < $blocks; $b++) {
            $out[] = $datenBloecke[$b][$i];
        }
    }
    for ($i = 0; $i < $eccPerBlock; $i++) {
        for ($b = 0; $b < $blocks; $b++) {
            $out[] = $eccBloecke[$b][$i];
        }
    }

    return $out;
}

// ------------------------------------------------------------- Zeichnung

/** Setzt ein Modul und merkt sich, dass die Stelle belegt ist. */
function qr_set(array &$m, array &$fest, int $row, int $col, bool $dark): void
{
    $m[$row][$col]    = $dark;
    $fest[$row][$col] = true;
}

/** Sucher, Trenner, Taktlinien, Ausrichtung und das immer dunkle Modul. */
function qr_draw_function_patterns(array &$m, array &$fest, int $version, int $size): void
{
    // Taktlinien
    for ($i = 0; $i < $size; $i++) {
        qr_set($m, $fest, 6, $i, $i % 2 === 0);
        qr_set($m, $fest, $i, 6, $i % 2 === 0);
    }

    // Die drei Sucher, jeweils mit ihrem Trennstreifen. Gezeichnet wird ein
    // 9x9-Feld um die Mitte; was ausserhalb liegt, fällt weg.
    foreach ([[3, 3], [3, $size - 4], [$size - 4, 3]] as [$cr, $cc]) {
        for ($dr = -4; $dr <= 4; $dr++) {
            for ($dc = -4; $dc <= 4; $dc++) {
                $r = $cr + $dr;
                $c = $cc + $dc;
                if ($r < 0 || $r >= $size || $c < 0 || $c >= $size) {
                    continue;
                }
                $dist = max(abs($dr), abs($dc));
                qr_set($m, $fest, $r, $c, $dist !== 2 && $dist <= 3);
            }
        }
    }

    // Ausrichtungsmuster. In den Versionen 2 bis 6 gibt es genau eines; die
    // drei anderen rechnerischen Stellen fallen mit den Suchern zusammen.
    $a = QR_ALIGN[$version];
    if ($a > 0) {
        for ($dr = -2; $dr <= 2; $dr++) {
            for ($dc = -2; $dc <= 2; $dc++) {
                qr_set($m, $fest, $a + $dr, $a + $dc, max(abs($dr), abs($dc)) !== 1);
            }
        }
    }

    /*
     * Die Felder der Formatinformation freihalten - beschrieben werden sie
     * erst, wenn die Maske feststeht.
     *
     * Index 6 bleibt ausgespart: Dort kreuzen sich Formatstreifen und
     * Taktlinie, und die Taktlinie behaelt an dieser Stelle das Sagen. Wird
     * sie hier ueberschrieben, entsteht ein Code, den nachsichtige Leser noch
     * entziffern, strenge aber nicht mehr finden.
     */
    for ($i = 0; $i <= 8; $i++) {
        if ($i === 6) {
            continue;
        }
        qr_set($m, $fest, 8, $i, false);
        qr_set($m, $fest, $i, 8, false);
    }
    for ($i = 0; $i < 8; $i++) {
        qr_set($m, $fest, $size - 1 - $i, 8, false);
        qr_set($m, $fest, 8, $size - 1 - $i, false);
    }

    // Dieses eine Modul ist immer dunkel.
    qr_set($m, $fest, $size - 8, 8, true);
}

/** Die Nutzdaten im Zickzack von rechts unten nach oben. */
function qr_draw_codewords(array &$m, array $fest, array $codewords, int $size): void
{
    $bits = '';
    foreach ($codewords as $cw) {
        $bits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
    }

    $i   = 0;
    $len = strlen($bits);
    $row = $size - 1;
    $dir = -1;

    for ($col = $size - 1; $col > 0; $col -= 2) {
        // Die senkrechte Taktlinie wird übersprungen.
        if ($col === 6) {
            $col--;
        }

        while (true) {
            for ($s = 0; $s < 2; $s++) {
                $c = $col - $s;
                if (!($fest[$row][$c] ?? false)) {
                    // Reicht die Bitfolge nicht, bleibt der Rest hell - das
                    // sind die Restbits, die die Norm ohne Bedeutung lässt.
                    $m[$row][$c] = $i < $len && $bits[$i] === '1';
                    $i++;
                }
            }

            $row += $dir;
            if ($row < 0 || $row >= $size) {
                $row -= $dir;
                $dir = -$dir;
                break;
            }
        }
    }
}

/** Ist dieses Modul unter dieser Maske umzukehren? */
function qr_mask_bit(int $mask, int $r, int $c): bool
{
    return match ($mask) {
        0 => ($r + $c) % 2 === 0,
        1 => $r % 2 === 0,
        2 => $c % 3 === 0,
        3 => ($r + $c) % 3 === 0,
        4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
        5 => ($r * $c) % 2 + ($r * $c) % 3 === 0,
        6 => (($r * $c) % 2 + ($r * $c) % 3) % 2 === 0,
        7 => (($r + $c) % 2 + ($r * $c) % 3) % 2 === 0,
    };
}

/** Die Formatinformation, zweimal eingetragen. */
function qr_draw_format(array &$m, int $mask, int $size): void
{
    $data = (QR_ECC_M << 3) | $mask;

    // BCH(15,5), danach die feste Maske der Norm.
    $rem = $data;
    for ($i = 0; $i < 10; $i++) {
        $rem = ($rem << 1) ^ ((($rem >> 9) & 1) * 0x537);
    }
    $bits = (($data << 10) | $rem) ^ 0x5412;

    $bit = static fn (int $i): bool => (($bits >> $i) & 1) === 1;

    // Erste Kopie, um den Sucher oben links.
    for ($i = 0; $i <= 5; $i++) {
        $m[$i][8] = $bit($i);
    }
    $m[7][8] = $bit(6);
    $m[8][8] = $bit(7);
    $m[8][7] = $bit(8);
    for ($i = 9; $i < 15; $i++) {
        $m[8][14 - $i] = $bit($i);
    }

    // Zweite Kopie, aufgeteilt auf unten links und oben rechts.
    for ($i = 0; $i < 8; $i++) {
        $m[8][$size - 1 - $i] = $bit($i);
    }
    for ($i = 8; $i < 15; $i++) {
        $m[$size - 15 + $i][8] = $bit($i);
    }
}

/**
 * Die Strafpunkte einer Maske nach den vier Regeln der Norm.
 *
 * Je weniger, desto besser lesbar - grosse gleichfarbige Flächen und alles,
 * was einem Sucher ähnelt, machen einer Kamera Mühe.
 */
function qr_penalty(array $m, int $size): int
{
    $strafe = 0;

    // Regel 1: fünf und mehr gleiche Module hintereinander, waagerecht wie
    // senkrecht. Gezählt wird je Lauf einmal, nicht je Modul.
    for ($i = 0; $i < $size; $i++) {
        foreach ([true, false] as $waagerecht) {
            $lauf    = 1;
            $voriges = $waagerecht ? $m[$i][0] : $m[0][$i];

            for ($j = 1; $j < $size; $j++) {
                $jetzt = $waagerecht ? $m[$i][$j] : $m[$j][$i];
                if ($jetzt === $voriges) {
                    $lauf++;
                    continue;
                }
                if ($lauf >= 5) {
                    $strafe += 3 + ($lauf - 5);
                }
                $lauf    = 1;
                $voriges = $jetzt;
            }
            if ($lauf >= 5) {
                $strafe += 3 + ($lauf - 5);
            }
        }
    }

    // Regel 2: gleichfarbige 2x2-Felder.
    for ($r = 0; $r < $size - 1; $r++) {
        for ($c = 0; $c < $size - 1; $c++) {
            $v = $m[$r][$c];
            if ($m[$r][$c + 1] === $v && $m[$r + 1][$c] === $v && $m[$r + 1][$c + 1] === $v) {
                $strafe += 3;
            }
        }
    }

    // Regel 3: das Muster eines Suchers, mit vier hellen Modulen davor oder
    // dahinter - waagerecht wie senkrecht.
    $muster  = [true, false, true, true, true, false, true];
    $leer    = [false, false, false, false];
    $suchen  = [array_merge($muster, $leer), array_merge($leer, $muster)];

    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c < $size; $c++) {
            foreach ($suchen as $folge) {
                $n = count($folge);
                if ($c + $n <= $size) {
                    $treffer = true;
                    for ($k = 0; $k < $n; $k++) {
                        if ($m[$r][$c + $k] !== $folge[$k]) {
                            $treffer = false;
                            break;
                        }
                    }
                    if ($treffer) {
                        $strafe += 40;
                    }
                }
                if ($r + $n <= $size) {
                    $treffer = true;
                    for ($k = 0; $k < $n; $k++) {
                        if ($m[$r + $k][$c] !== $folge[$k]) {
                            $treffer = false;
                            break;
                        }
                    }
                    if ($treffer) {
                        $strafe += 40;
                    }
                }
            }
        }
    }

    // Regel 4: das Verhältnis von dunkel zu hell soll nahe der Hälfte liegen.
    $dunkel = 0;
    for ($r = 0; $r < $size; $r++) {
        $dunkel += count(array_filter($m[$r]));
    }
    $anteil = $dunkel * 100 / ($size * $size);
    $strafe += (int) (abs($anteil - 50) / 5) * 10;

    return $strafe;
}

// ----------------------------------------------------------------- API

/**
 * Der fertige Code als Feld aus Zeilen von true/false.
 * Gibt null zurück, wenn der Text nicht in Version 6 passt.
 *
 * @return list<list<bool>>|null
 */
function qr_matrix(string $text): ?array
{
    $version = qr_pick_version(strlen($text));
    if ($version === null) {
        return null;
    }

    $size = 17 + 4 * $version;

    $leer = array_fill(0, $size, array_fill(0, $size, false));
    $fest = array_fill(0, $size, array_fill(0, $size, false));

    $grund = $leer;
    qr_draw_function_patterns($grund, $fest, $version, $size);

    $codewords = qr_interleave(qr_data_codewords($text, $version), $version);
    qr_draw_codewords($grund, $fest, $codewords, $size);

    // Alle acht Masken durchrechnen und die günstigste nehmen.
    $beste      = null;
    $besteMaske = 0;
    $bestePunkte = PHP_INT_MAX;

    for ($mask = 0; $mask < 8; $mask++) {
        $m = $grund;
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if (!$fest[$r][$c] && qr_mask_bit($mask, $r, $c)) {
                    $m[$r][$c] = !$m[$r][$c];
                }
            }
        }
        qr_draw_format($m, $mask, $size);

        $punkte = qr_penalty($m, $size);
        if ($punkte < $bestePunkte) {
            $bestePunkte = $punkte;
            $beste       = $m;
            $besteMaske  = $mask;
        }
    }

    return $beste;
}

/**
 * Der Code als SVG, fertig zum Einbetten.
 *
 * SVG statt eines Bildes, weil die Seite gedruckt wird: Ein Drucker löst
 * viermal feiner auf als ein Bildschirm, und ein Pixelbild wird dabei
 * unscharf. Ausserdem braucht es so keine Datei und keine GD-Erweiterung.
 *
 * Der helle Rand von vier Modulen gehört dazu - ohne ihn finden viele
 * Kameras den Code nicht.
 */
function qr_svg(string $text, int $pixelProModul = 4, string $beschreibung = ''): ?string
{
    $m = qr_matrix($text);
    if ($m === null) {
        return null;
    }

    $size  = count($m);
    $rand  = 4;
    $ganz  = ($size + 2 * $rand) * $pixelProModul;

    // Ein einziger Pfad statt tausend Rechtecke - das SVG bleibt klein.
    $pfad = '';
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c < $size; $c++) {
            if ($m[$r][$c]) {
                $pfad .= sprintf('M%d %dh1v1h-1z', $c + $rand, $r + $rand);
            }
        }
    }

    return sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" '
        . 'viewBox="0 0 %d %d" shape-rendering="crispEdges" role="img" aria-label="%s">'
        . '<rect width="%d" height="%d" fill="#fff"/>'
        . '<path d="%s" fill="#000"/></svg>',
        $ganz, $ganz,
        $size + 2 * $rand, $size + 2 * $rand,
        htmlspecialchars($beschreibung, ENT_QUOTES, 'UTF-8'),
        $size + 2 * $rand, $size + 2 * $rand,
        $pfad,
    );
}
