<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

/*
 * Gemeldete Vokabeln aller Schulen - dieselbe Karte, die die Lehrkraft
 * sieht, und dieselben Regeln aus lib/meldungen.php.
 *
 * Der Admin sieht sie zusätzlich, nicht stattdessen: Zuständig ist die
 * Lehrkraft, sie kennt ihre Unterlagen. Hier landet, was dort liegen bleibt
 * - oder was auf einen Fehler in der Satzerzeugung deutet, der mehr als
 * eine Klasse trifft.
 */

admin_require();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    [$ok, $text] = meldung_bearbeiten(null, $_POST);
    flash($text, $ok ? 'good' : 'bad');
    redirect('meldungen.php' . ($ok ? '' : '?v=' . (int) ($_POST['meldung'] ?? 0)));
}

$offen   = meldungen_offen(null);
$vorn    = meldung_vorn($offen, (int) ($_GET['v'] ?? 0));
$meldung = $vorn > 0 ? meldung_laden($vorn, null) : null;

admin_head('Meldungen', 'meldungen.php');
flash_render();

if ($meldung === null) {
    echo '<p class="muted">Keine offenen Meldungen.</p>';
} else {
    echo meldung_html($meldung, count($offen), csrf_field());
}

admin_foot();
