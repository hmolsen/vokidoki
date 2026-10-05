<?php
declare(strict_types=1);

require_once __DIR__ . '/fpdf/fpdf.php';
require_once __DIR__ . '/qr.php';
require_once __DIR__ . '/letter.php';

/*
 * Die Zettel als PDF - eine A4-Seite je Kind (oder Lehrkraft).
 *
 * Bis hierher gab es nur die Druckseite (teacher/print.php), und der
 * Druckdialog des Browsers machte daraus, was er wollte: Auf einem iPhone
 * passte ein Zettel erst bei 87 % auf eine Seite, auf einem Rechner bei
 * 100 %, je nach Browser mit anderen Rändern. Und in der Verwaltung als
 * App auf dem Home-Bildschirm gab es gar keinen Druckdialog: window.print()
 * tut dort nichts, und einen Teilen-Knopf hat die App nicht.
 *
 * Ein PDF hat seine Seitengrösse selbst. Am Rechner öffnet es der Browser
 * und druckt es Seite für Seite; auf dem Telefon geht es über das
 * Teilen-Menü an "Drucken" oder "In Dateien sichern" (teacher.js).
 *
 * FPDF (lib/fpdf/, eine Datei, freie Lizenz) statt eines eigenen Erzeugers:
 * Schriften einbetten und Text umbrechen ist genau die Arbeit, bei der ein
 * selbstgebauter still falsch wird. Die Schriften sind dieselben wie in der
 * App - Nunito und Fredoka, in lib/fpdf/font/ auf die Zeichen von
 * Windows-1252 zugeschnitten (deutsche Umlaute, ß, Anführungszeichen).
 */

/** Ein Blatt: was darauf steht, alles schon fertig eingesetzt. */
const ZETTEL_FELDER = ['ueber', 'name', 'unter', 'adresse', 'kuerzel', 'benutzername', 'passwort', 'qr', 'brief'];

/** FPDF mit dem, was der Zettel zusätzlich braucht: runde Ecken, gestrichelte Linien. */
final class ZettelPdf extends FPDF
{
    /** Ein Rechteck mit runden Ecken - F füllt, D zieht den Rand, DF beides. */
    public function rundeck(float $x, float $y, float $w, float $h, float $r, string $art = 'D'): void
    {
        $k  = $this->k;
        $hp = $this->h;
        $op = ['F' => 'f', 'FD' => 'B', 'DF' => 'B'][$art] ?? 'S';
        $b  = 4 / 3 * (M_SQRT2 - 1);    // Bézier-Faktor für einen Viertelkreis
        $p  = static fn (float $px, float $py): string => sprintf('%.2F %.2F', $px * $k, ($hp - $py) * $k);
        $c  = static fn (float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): string =>
            sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $x1 * $k, ($hp - $y1) * $k, $x2 * $k, ($hp - $y2) * $k,
                    $x3 * $k, ($hp - $y3) * $k);
        $s  = $p($x + $r, $y) . ' m ';
        $s .= $p($x + $w - $r, $y) . ' l ';
        $s .= $c($x + $w - $r + $b * $r, $y, $x + $w, $y + $r - $b * $r, $x + $w, $y + $r);
        $s .= $p($x + $w, $y + $h - $r) . ' l ';
        $s .= $c($x + $w, $y + $h - $r + $b * $r, $x + $w - $r + $b * $r, $y + $h, $x + $w - $r, $y + $h);
        $s .= $p($x + $r, $y + $h) . ' l ';
        $s .= $c($x + $r - $b * $r, $y + $h, $x, $y + $h - $r + $b * $r, $x, $y + $h - $r);
        $s .= $p($x, $y + $r) . ' l ';
        $s .= $c($x, $y + $r - $b * $r, $x + $r - $b * $r, $y, $x + $r, $y);
        $this->_out($s . $op);
    }

    /** Gestrichelt zeichnen - oder mit [] wieder durchgezogen. */
    public function strichelung(array $muster): void
    {
        $this->_out($muster === []
            ? '[] 0 d'
            : '[' . implode(' ', array_map(fn (float $m): string => sprintf('%.2F', $m * $this->k), $muster)) . '] 0 d');
    }
}

/** UTF-8 in die Kodierung der Schriften (Windows-1252); Unbekanntes wird angenähert. */
function zettel_text(string $s): string
{
    $s = strtr($s, ["\u{2009}" => ' ', "\u{202F}" => ' ', "\u{00A0}" => ' ']);
    $t = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
    return $t === false ? (string) preg_replace('/[^\x20-\x7E]/', '?', $s) : $t;
}

/**
 * Zeilen umbrechen, wie sie gesetzt werden - in der aktuellen Schrift.
 *
 * Selbst statt MultiCell(): Erst wird gemessen, ob der Brief auf die Seite
 * passt, dann gesetzt. MultiCell() setzt sofort.
 *
 * @return list<string>
 */
function zettel_zeilen(ZettelPdf $pdf, string $text, float $breite): array
{
    $zeilen = [];
    foreach (explode("\n", $text) as $absatzZeile) {
        $zeile = '';
        foreach (preg_split('/ +/', trim($absatzZeile)) ?: [] as $wort) {
            $probe = $zeile === '' ? $wort : $zeile . ' ' . $wort;
            if ($zeile !== '' && $pdf->GetStringWidth($probe) > $breite) {
                $zeilen[] = $zeile;
                $zeile = $wort;
            } else {
                $zeile = $probe;
            }
            // Ein Wort, länger als die Zeile (eine Adresse): hart trennen.
            while ($pdf->GetStringWidth($zeile) > $breite && strlen($zeile) > 1) {
                $n = strlen($zeile);
                while ($n > 1 && $pdf->GetStringWidth(substr($zeile, 0, $n)) > $breite) {
                    $n--;
                }
                $zeilen[] = substr($zeile, 0, $n);
                $zeile = substr($zeile, $n);
            }
        }
        $zeilen[] = $zeile;
    }
    return $zeilen;
}

/**
 * Die Blätter als PDF.
 *
 * @param list<array<string, string>> $blaetter je Blatt die Felder aus ZETTEL_FELDER
 */
function zettel_pdf(array $blaetter, string $titel): string
{
    $pdf = new ZettelPdf('P', 'mm', 'A4');
    $pdf->SetTitle($titel, true);
    $pdf->SetCreator('Vokidoki', true);
    $pdf->SetAutoPageBreak(false);
    $pdf->SetMargins(18, 16, 18);
    $schriften = __DIR__ . '/fpdf/font';
    $pdf->AddFont('Nunito', '', 'Nunito-Regular.json', $schriften);
    $pdf->AddFont('Nunito', 'B', 'Nunito-Bold.json', $schriften);
    $pdf->AddFont('Fredoka', '', 'Fredoka-SemiBold.json', $schriften);
    $pdf->AddFont('Courier', 'B', 'courierb.json', $schriften);

    $tinte = [23, 26, 33];
    $leise = [91, 98, 112];
    $blau  = [79, 124, 255];
    $tief  = [95, 125, 16];
    $assets = __DIR__ . '/../assets';

    foreach ($blaetter as $b) {
        $pdf->AddPage();
        $links = 18.0;
        $breit = 210 - 2 * $links;

        // Oben ein grüner Streifen - Vokis Farbe, auch ausgedruckt zu erkennen.
        $pdf->SetFillColor(175, 213, 53);
        $pdf->Rect(0, 0, 210, 5, 'F');

        $pdf->Image($assets . '/vokidoki_logo.png', $links - 2, 13, 48);
        $pdf->Image($assets . '/voki-icon.png', 210 - $links - 22, 10, 22);

        // Für wen.
        $y = 34.0;
        $pdf->SetTextColor(...$leise);
        $pdf->SetFont('Nunito', '', 10);
        $pdf->SetXY($links, $y);
        $pdf->Cell($breit, 5, zettel_text($b['ueber']));
        $pdf->SetTextColor(...$tinte);
        $pdf->SetFont('Fredoka', '', 24);
        $pdf->SetXY($links, $y + 5);
        $pdf->Cell($breit, 11, zettel_text($b['name']));
        $pdf->SetTextColor(...$leise);
        $pdf->SetFont('Nunito', '', 11);
        $pdf->SetXY($links, $y + 16);
        $pdf->Cell($breit, 6, zettel_text($b['unter']));

        // Der Zugang: QR-Code und die vier Angaben in einer Karte.
        $y = 60.0;
        $kh = 46.0;
        $pdf->SetFillColor(244, 247, 255);
        $pdf->SetDrawColor(214, 224, 255);
        $pdf->SetLineWidth(0.4);
        $pdf->rundeck($links, $y, $breit, $kh, 5, 'DF');

        $qr = qr_matrix($b['qr']);
        $wx = $links + 6;
        if ($qr !== null) {
            $pdf->SetFillColor(255, 255, 255);
            $pdf->rundeck($links + 5, $y + 4, 33, 33, 3, 'F');
            // Mit gut drei Millimetern Weiss ringsum - so viel Ruhezone will ein Scanner.
            $modul = 26.5 / count($qr);
            $pdf->SetFillColor(0, 0, 0);
            foreach ($qr as $r => $reihe) {
                foreach ($reihe as $c => $dunkel) {
                    if ($dunkel) {
                        $pdf->Rect($links + 8.25 + $c * $modul, $y + 7.25 + $r * $modul, $modul + 0.02, $modul + 0.02, 'F');
                    }
                }
            }
            $pdf->SetTextColor(...$leise);
            $pdf->SetFont('Nunito', '', 8);
            $pdf->SetXY($links + 5, $y + 38);
            $pdf->Cell(33, 4, 'Code scannen', 0, 0, 'C');
            $wx = $links + 46;
        }

        $angaben = [
            ['ADRESSE', $b['adresse'], 10.5, $tinte],
            ['SCHULKÜRZEL', $b['kuerzel'], 12, $tinte],
            ['BENUTZERNAME', $b['benutzername'], 12, $tinte],
            ['PASSWORT', $b['passwort'], 14, $blau],
        ];
        $ay = $y + 6.5;
        foreach ($angaben as [$was, $wert, $groesse, $farbe]) {
            $pdf->SetTextColor(...$leise);
            $pdf->SetFont('Nunito', 'B', 8);
            $pdf->SetXY($wx, $ay);
            $pdf->Cell(36, 6, zettel_text($was));
            $pdf->SetTextColor(...$farbe);
            $pdf->SetFont('Courier', 'B', $groesse);
            // Eine lange Adresse wird kleiner, statt über die Karte hinauszulaufen.
            $platz = $links + $breit - 5 - ($wx + 36);
            while ($groesse > 7 && $pdf->GetStringWidth(zettel_text($wert)) > $platz) {
                $groesse -= 0.5;
                $pdf->SetFont('Courier', 'B', $groesse);
            }
            $pdf->SetXY($wx + 36, $ay);
            $pdf->Cell($platz, 6, zettel_text($wert));
            $ay += 8.5;
        }

        /*
         * Der Brief - so gross, wie es geht, ohne auf eine zweite Seite zu
         * laufen. Eine Lehrkraft, die ihre Vorlage um zwei Absätze
         * verlängert, bekommt eine etwas kleinere Schrift statt eines
         * zweiten Blattes je Kind.
         */
        $oben  = $y + $kh + 8;
        $unten = 279.0;
        $teile = array_map(static fn (array $t): array => [$t[0], zettel_text($t[1])], letter_absaetze($b['brief']));
        $groesse = 11.0;
        do {
            $zeilenH = $groesse * 0.47;
            $hoehe = 0.0;
            foreach ($teile as [$art, $text]) {
                if ($art === 'h') {
                    $pdf->SetFont('Fredoka', '', $groesse + 1.5);
                    $hoehe += 8 + $zeilenH * 1.2 * count(zettel_zeilen($pdf, $text, $breit)) + 1.5;
                } else {
                    $pdf->SetFont('Nunito', '', $groesse);
                    $hoehe += $zeilenH * count(zettel_zeilen($pdf, $text, $breit)) + 2.5;
                }
            }
            if ($oben + $hoehe <= $unten || $groesse <= 7) {
                break;
            }
            $groesse -= 0.25;
        } while (true);

        $zeilenH = $groesse * 0.47;
        $yy = $oben;
        foreach ($teile as [$art, $text]) {
            if ($art === 'h') {
                $yy += 3;
                $pdf->SetDrawColor(223, 227, 236);
                $pdf->SetLineWidth(0.4);
                $pdf->strichelung([1.5, 1.2]);
                $pdf->Line($links, $yy, $links + $breit, $yy);
                $pdf->strichelung([]);
                $yy += 5;
                $pdf->SetTextColor(...$tief);
                $pdf->SetFont('Fredoka', '', $groesse + 1.5);
                foreach (zettel_zeilen($pdf, $text, $breit) as $zeile) {
                    $pdf->SetXY($links, $yy);
                    $pdf->Cell($breit, $zeilenH * 1.2, $zeile);
                    $yy += $zeilenH * 1.2;
                }
                $yy += 1.5;
                continue;
            }
            $pdf->SetTextColor(...$tinte);
            $pdf->SetFont('Nunito', '', $groesse);
            foreach (zettel_zeilen($pdf, $text, $breit) as $zeile) {
                $pdf->SetXY($links, $yy);
                $pdf->Cell($breit, $zeilenH, $zeile);
                $yy += $zeilenH;
            }
            $yy += 2.5;
        }

        // Unten: der Hinweis auf das Passwort.
        $pdf->SetDrawColor(223, 227, 236);
        $pdf->SetLineWidth(0.3);
        $pdf->Line($links, 283, $links + $breit, 283);
        $pdf->SetTextColor(...$leise);
        $pdf->SetFont('Nunito', '', 8);
        $pdf->SetXY($links, 285);
        $pdf->Cell($breit - 30, 4, zettel_text('Dieser Zettel enthält ein Passwort – bitte gut aufheben und nicht offen liegen lassen.'));
        $pdf->Cell(30, 4, 'vokidoki.de', 0, 0, 'R');
    }

    return $pdf->Output('S');
}
