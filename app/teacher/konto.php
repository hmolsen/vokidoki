<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/colors.php';
require_once __DIR__ . '/../lib/profile.php';
require_once __DIR__ . '/../lib/letter.php';

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

/*
 * Die eigene Vorlage für die Zettel an die Kinder.
 *
 * Ohne eigene gilt die des Betreibers. Zurückstellen geht nur mit
 * Rückfrage: Eine lange umformulierte Vorlage ist mit einem Klick weg.
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_letter'])) {
    teacher_csrf_check();

    $fehlend = letter_save_own($uid, (string) ($_POST['letter_template'] ?? ''));
    teacher_flash($fehlend === []
        ? 'Vorlage gesichert. Deine nächsten Zettel sehen so aus.'
        : 'Vorlage gesichert - ohne ' . implode(' und ', $fehlend)
          . '. Das ist erlaubt, aber bitte einmal Probe drucken.',
        $fehlend === [] ? 'good' : 'warn');
    teacher_redirect('konto.php#vorlage');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['reset_letter'])) {
    teacher_csrf_check();

    letter_save_own($uid, '');
    teacher_flash('Vorlage zurückgestellt - es gilt wieder die Voreinstellung.');
    teacher_redirect('konto.php#vorlage');
}

teacher_head('Mein Konto', $user);
teacher_flash_render();
?>

<h2>Name und Farbe</h2>

<form method="post" class="card kontoform namefarbe">
    <?= teacher_csrf_field() ?>

    <?php
    /*
     * Dieselbe Karte wie im Konto der App (views/profile.js) - Name,
     * App-Symbol, Farbwahl -, nur zeigt das Symbol hier das der
     * Verwaltung: kleinerer Voki, grauer Balken mit dem Wort (icon.php,
     * w=1). Vorher stand hier der kleine Farbwähler des Admins, ohne
     * Vorschau; wer die Farbe wechselte, sah erst auf dem Telefon, was er
     * gewählt hatte.
     */
    ?>
    <label for="name">Dein Name</label>
    <input type="text" id="name" name="name" maxlength="64" required
           value="<?= h((string) $user['display_name']) ?>">
    <p class="tiny muted">
        So steht es oben in der Leiste. Auf dem Home-Bildschirm heißt die
        Lernansicht „<?= h(app_name_for($user)) ?>“, die Verwaltung „Verwaltung“.
    </p>

    <label>Dein App-Symbol</label>
    <div class="appsymbolzeile">
        <div class="homescreen" aria-hidden="true">
            <div class="appsymbol verwaltung" id="appsymbol">
                <img src="<?= h(url('/assets/voki-icon.svg')) ?>" alt="">
                <span class="appbalken"><img src="<?= h(url('/assets/verwaltung-schrift.png')) ?>" alt=""></span>
            </div>
            <span class="appname">Verwaltung</span>
        </div>
        <p class="tiny muted">
            So sieht die Verwaltung auf dem Home-Bildschirm aus. Die Farbe
            gilt auch in der App selbst.
        </p>
    </div>

    <div data-farbwahl="<?= h(url('/appsymbol.js') . '?v=' . app_version()) ?>">
        <?= farbwahl_html((string) $user['color']) ?>
    </div>

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

<h2 id="vorlage">Vorlage für die Zettel</h2>

<form method="post" class="card kontoform">
    <?= teacher_csrf_field() ?>
    <p class="tiny muted" style="margin-top:0">
        <?= letter_is_own($user)
            ? '<strong>Du nutzt eine eigene Vorlage.</strong> Sie gilt für alle Zettel, die du druckst.'
            : 'Du nutzt die Voreinstellung. Änderst du sie hier, gilt deine Fassung nur für deine Zettel.' ?>
    </p>
    <label for="letter">Text des Zettels</label>
    <textarea id="letter" name="letter_template" rows="22"
              style="width:100%;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.9rem"
    ><?= h(letter_template($user)) ?></textarea>
    <p class="tiny muted">
        Reiner Text. Diese Platzhalter werden auf jedem Zettel ersetzt:
        <?php foreach (letter_placeholders() as $p => $was): ?>
            <br><code class="token">{<?= h($p) ?>}</code> &ndash; <?= h($was) ?>
        <?php endforeach; ?>
    </p>
    <div class="buttonrow">
        <button class="btn" name="save_letter" value="1">Vorlage sichern</button>
        <?php if (letter_is_own($user)): ?>
        <button class="btn secondary" name="reset_letter" value="1" formnovalidate
                data-confirm="Deine eigene Vorlage verwerfen und wieder die Voreinstellung nutzen? Dein Text ist danach weg.">
            Auf Voreinstellung zurücksetzen
        </button>
        <?php endif; ?>
    </div>
</form>

<p class="tiny muted">
    Zum Anmelden: Schulkürzel
    <code><?= h(schulkuerzel_von(isset($user['school_id']) ? (int) $user['school_id'] : null)) ?></code>,
    Benutzername <code><?= h((string) $user['username']) ?></code> &ndash; beides lässt sich nicht
    selbst ändern.
</p>

<?php teacher_foot(); ?>
