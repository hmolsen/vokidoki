<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/einwilligung.php';

/*
 * Die Hinweise der ersten Anmeldung - im Lehrkraft-Bereich.
 *
 * teacher_require() schickt jede Seite hierher, solange das Konto sie nicht
 * bestätigt hat. Was bestätigt wird und wie es gespeichert wird, steht in
 * lib/einwilligung.php; die App zeigt dieselben Punkte (views/einwilligung.js).
 * Diese Seite ist die Fassung ohne JavaScript, wie alles hier.
 *
 * Ohne Menü: Jeder Link darin führte ohnehin wieder hierher.
 */

$user = teacher_require();

if (!einwilligung_noetig($user)) {
    teacher_redirect('index.php');
}

$fehler = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['einwilligen'])) {
    teacher_csrf_check();
    $haken  = array_values(array_filter((array) ($_POST['angehakt'] ?? []), 'is_string'));
    $fehler = einwilligung_speichern($user, $haken);
    if ($fehler === null) {
        teacher_redirect('index.php');
    }
}

?><!doctype html>
<html lang="de"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Willkommen - Vokidoki</title>
<?= favicon_html() ?>
<link rel="stylesheet" href="<?= h(url('/style.css')) ?>">
<link rel="stylesheet" href="<?= h(url('/admin/admin.css')) ?>">
<?= thema_kopf_skript() ?>
</head><body class="admin"><main class="adminmain" style="max-width:560px">

<img class="logo" src="<?= h(url('/assets/vokidoki_logo.svg')) ?>" alt="Vokidoki"
     width="768" height="256" style="margin-top:4vh">

<div class="card einwilligung">
    <img class="einwilligung-voki" src="<?= h(url('/assets/voki-icon.svg')) ?>" alt=""
         width="96" height="96">
    <h1>Willkommen bei Vokidoki!</h1>
    <p class="sub">Bitte bestätige vor der Nutzung kurz unsere rechtlichen Hinweise.</p>

    <?php if ($fehler !== null): ?>
        <div class="notice"><?= h($fehler) ?></div>
    <?php endif; ?>

    <form method="post">
        <?= teacher_csrf_field() ?>
        <ul class="haken">
            <?php foreach (einwilligung_punkte($user) as $p): ?>
                <li><label>
                    <input type="checkbox" name="angehakt[]" value="<?= h($p['schluessel']) ?>" required>
                    <span><?= $p['html'] ?></span>
                </label></li>
            <?php endforeach; ?>
        </ul>
        <button class="btn" name="einwilligen" value="1">Jetzt starten</button>
    </form>
</div>

<form method="post" action="<?= h(teacher_url('index.php')) ?>" class="center">
    <?= teacher_csrf_field() ?>
    <button class="linkbtn" name="teacher_logout" value="1">Nicht jetzt &ndash; abmelden</button>
</form>

</main></body></html>
