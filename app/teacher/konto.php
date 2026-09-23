<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/colors.php';
require_once __DIR__ . '/../lib/profile.php';

/*
 * Das eigene Konto - im Lehrkraft-Bereich.
 *
 * Es lag bisher in der App: ein Menüeintrag, der aus dem Lehrkraft-Bereich
 * hinausführte. Gedacht war das als Sparsamkeit - Name, Farbe und Passwort
 * sind dieselben, egal von welcher Seite man kommt, und eine zweite Fassung
 * wären zwei Orte für eine Sache. In der Bedienung war es das Gegenteil:
 * Wer aus dem Menü hierher kam, stand in einer anderen Anwendung, und der
 * Zurück-Knopf führte an den Anfang der Kinderansicht.
 *
 * Doppelt ist jetzt nur die Oberfläche. Die Regeln - Mindestlänge, das
 * bisherige Passwort, das Löschen des Anfangspassworts - stehen einmal in
 * lib/profile.php, und die App ruft genau dieselben auf.
 *
 * Und ganz ohne JavaScript: zwei Formulare, zwei POSTs, eine Weiterleitung.
 */

$user = teacher_require();
$uid  = (int) $user['id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_profile'])) {
    teacher_csrf_check();

    $fehler = profile_save($uid, (string) ($_POST['name'] ?? ''),
                           (string) ($_POST['color'] ?? ''));
    if ($fehler !== null) {
        teacher_flash($fehler, 'bad');
        teacher_redirect('konto.php');
    }

    teacher_flash('Gesichert.');
    teacher_redirect('konto.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['change_password'])) {
    teacher_csrf_check();

    $neu     = (string) ($_POST['password'] ?? '');
    $nochmal = (string) ($_POST['password2'] ?? '');

    if ($neu !== $nochmal) {
        teacher_flash('Die beiden neuen Passwörter sind nicht gleich.', 'bad');
        teacher_redirect('konto.php#passwort');
    }

    $fehler = profile_change_password($user, (string) ($_POST['current'] ?? ''), $neu);
    if ($fehler !== null) {
        teacher_flash($fehler, 'bad');
        teacher_redirect('konto.php#passwort');
    }

    teacher_flash('Passwort geändert. Merk es dir gut!');
    teacher_redirect('konto.php');
}

teacher_head('Mein Konto', $user);
teacher_flash_render();
?>

<h2>Name und Farbe</h2>

<form method="post" class="card kontoform">
    <?= teacher_csrf_field() ?>

    <label for="name">Dein Name</label>
    <input type="text" id="name" name="name" maxlength="64" required
           value="<?= h((string) $user['display_name']) ?>">
    <p class="tiny muted">
        So heißt die App auf deinem Home-Bildschirm und so steht es oben in
        der Leiste.
    </p>

    <label>Deine Farbe</label>
    <?php
    /*
     * Derselbe Farbwähler wie im Admin-Bereich - ein <details> mit einer
     * Palette darin. Ohne JavaScript bedienbar, weil <details> das von
     * selbst kann, und die Wahl ist ein gewöhnliches Radiofeld.
     */
    ?>
    <div class="kontofarbe"><?= color_picker((string) $user['color']) ?></div>

    <button class="btn" name="save_profile" value="1">Speichern</button>
</form>

<h2 id="passwort">Passwort ändern</h2>

<?php if (profile_has_initial_password($user)): ?>
    <div class="notice warn">
        Du hast noch dein Anfangspasswort. Denk dir eins aus, das nur du
        kennst &ndash; dann steht es nirgends mehr auf einem Zettel.
    </div>
<?php endif; ?>

<form method="post" class="card kontoform">
    <?= teacher_csrf_field() ?>

    <?php
    /*
     * Das bisherige Passwort wird verlangt, obwohl die Sitzung angemeldet
     * ist. Der Grund ist ein liegengelassener Rechner im Lehrerzimmer: Wer
     * sich davorsetzt, soll das Konto nicht übernehmen können.
     */
    ?>
    <label for="current">Bisheriges Passwort</label>
    <input type="password" id="current" name="current" autocomplete="current-password" required>

    <label for="pw1">Neues Passwort</label>
    <input type="password" id="pw1" name="password" autocomplete="new-password" required>

    <label for="pw2">Noch einmal</label>
    <input type="password" id="pw2" name="password2" autocomplete="new-password" required>

    <button class="btn secondary" name="change_password" value="1">Passwort ändern</button>
    <p class="tiny muted">
        Mindestens sechs Zeichen. Wenn du es vergisst, kann dir nur der
        Betreiber ein neues geben &ndash; anders als bei den Kindern, denen
        du selbst eines geben kannst.
    </p>
</form>

<p class="tiny muted">
    Dein Benutzername zum Anmelden ist
    <code><?= h((string) $user['username']) ?></code> und lässt sich nicht
    ändern.
</p>

<?php teacher_foot(); ?>
