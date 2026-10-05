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

// Auf eine Schule beschränkt, wenn man von deren Seite kommt (schule.php).
$schule = (int) ($_REQUEST['schule'] ?? 0) ?: null;
$mit    = $schule === null ? '' : 'schule=' . $schule;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    // Ein Wort auf die Ausspracheliste - wie bei der Lehrkraft, nur wartet
    // der Admin, bis neu gesprochen ist.
    if (isset($_POST['aussprache'])) {
        [$ok, $text, $neu] = meldung_aussprache(null, $_POST);
        if ($neu !== null) {
            set_time_limit(600);
            tts_alias_nachsprechen($neu[0], $neu[1]);
            $text .= ' Neu gesprochen - noch einmal anhören.';
        }
        flash($text, $ok ? 'good' : 'bad');
        redirect('meldungen.php?v=' . (int) ($_POST['meldung'] ?? 0) . ($mit === '' ? '' : '&' . $mit));
    }

    [$ok, $text] = meldung_bearbeiten(null, $_POST);
    flash($text, $ok ? 'good' : 'bad');
    redirect('meldungen.php?' . ltrim(($ok ? '' : 'v=' . (int) ($_POST['meldung'] ?? 0)) . '&' . $mit, '&'));
}

$offen   = meldungen_offen(null, $schule);
$vorn    = meldung_vorn($offen, (int) ($_GET['v'] ?? 0));
$meldung = $vorn > 0 ? meldung_laden($vorn, null) : null;

$schulName = $schule === null ? null : qv('SELECT name FROM schools WHERE id = ?', [$schule]);
admin_head($schulName === null ? 'Meldungen' : 'Meldungen: ' . $schulName, 'meldungen.php');
flash_render();
if ($schulName !== null) {
    echo '<p class="tiny"><a href="', h(admin_url('meldungen.php')), '">Meldungen aller Schulen</a></p>';
}

if ($meldung === null) {
    echo '<p class="muted">Keine offenen Meldungen.</p>';
} else {
    echo meldung_html($meldung, count($offen), csrf_field());
}

admin_foot();
