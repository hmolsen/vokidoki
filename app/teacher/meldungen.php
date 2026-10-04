<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

/*
 * Was die Kinder gemeldet haben - eins nach dem anderen.
 *
 * Erreichbar über das Zahnrad, an dem rot steht, wie viele Vokabeln warten.
 * Die Seite zeigt immer nur eine: die, die die meisten Kinder gemeldet
 * haben. Nach "Ändern" oder "Stimmt so" steht die nächste da, bis keine
 * mehr übrig ist. Die Regeln dahinter stehen in lib/meldungen.php - der
 * Admin arbeitet mit denselben.
 */

$user = teacher_require();
$uid  = (int) $user['id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    teacher_csrf_check();

    // Ein Wort auf die Ausspracheliste - die Meldung bleibt offen, damit sich
    // die neue Aufnahme anhören lässt. Gesprochen wird nach der Antwort.
    if (isset($_POST['aussprache'])) {
        [$ok, $text, $neu] = meldung_aussprache($uid, $_POST);
        teacher_flash($text . ($neu !== null ? ' Die neue Aufnahme entsteht gerade - gleich noch einmal anhören.' : ''),
                      $ok ? 'good' : 'bad');
        $zurueck = 'meldungen.php?v=' . (int) ($_POST['meldung'] ?? 0);
        if ($neu === null) {
            teacher_redirect($zurueck);
        }
        teacher_redirect_and_continue($zurueck);
        set_time_limit(600);
        tts_alias_nachsprechen($neu[0], $neu[1]);
        exit;
    }

    [$ok, $text] = meldung_bearbeiten($uid, $_POST);
    teacher_flash($text, $ok ? 'good' : 'bad');
    // Hat es nicht geklappt, bleibt dieselbe Meldung stehen - mit dem
    // Hinweis, was fehlte.
    teacher_redirect('meldungen.php' . ($ok ? '' : '?v=' . (int) ($_POST['meldung'] ?? 0)));
}

$offen   = meldungen_offen($uid);
$vorn    = meldung_vorn($offen, (int) ($_GET['v'] ?? 0));
$meldung = $vorn > 0 ? meldung_laden($vorn, $uid) : null;

teacher_head('Meldungen', $user);
teacher_flash_render();

if ($meldung === null) {
    echo teacher_leer('Keine offenen Meldungen. Wenn ein Kind beim Üben auf '
        . '&#9873; drückt, steht die Vokabel hier.');
} else {
    echo meldung_html($meldung, count($offen), teacher_csrf_field());
}

teacher_foot();
