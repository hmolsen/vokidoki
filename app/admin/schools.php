<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

admin_require();

/*
 * Schulen - der Mandant.
 *
 * Das ist der erste Schritt einer Installation, nicht der letzte: Ohne Schule
 * kann ein Konto weder eine Sprache anlegen noch eine Lerneinheit sehen,
 * denn beides haengt am Kurs und ein Kurs an der Schule. Deshalb steht die
 * Seite auch als erste im Menue.
 *
 * Angelegt wird hier, und nur hier: Eine Schule ist eine Entscheidung des
 * Betreibers, kein Seiteneffekt. Still eine anzulegen, damit irgendetwas
 * funktioniert, hiesse einen Posten zu erzeugen, den spaeter jemand
 * wegraeumen muss - und niemand wuesste, woher er kommt.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    if (isset($_POST['create'])) {
        $name    = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? '')) ?? '');
        // Ohne Kürzel könnte sich niemand anmelden - also gleich beim Anlegen.
        $kuerzel = schulkuerzel_normal((string) ($_POST['kuerzel'] ?? ''));

        if ($name === '') {
            flash('Die Schule braucht einen Namen.', 'bad');
        } elseif (mb_strlen($name) > 128) {
            flash('Der Name ist zu lang.', 'bad');
        } elseif (q1('SELECT id FROM schools WHERE name = ?', [$name]) !== null) {
            flash('Diese Schule gibt es schon.', 'bad');
        } elseif (($grund = schulkuerzel_pruefen($kuerzel)) !== null) {
            flash($grund, 'bad');
        } else {
            // Wie viele Lehrkräfte sie haben darf - Lehrkräfte legen einander an (lib/lehrkraefte.php).
            $grenze = max(1, min(1000, (int) ($_POST['max_lehrkraefte'] ?? LEHRKRAEFTE_VOREINSTELLUNG)));
            q('INSERT INTO schools (name, kuerzel, max_lehrkraefte) VALUES (?, ?, ?)', [$name, $kuerzel, $grenze]);
            flash('Schule "' . $name . '" mit dem Kürzel "' . $kuerzel . '" angelegt. Jetzt die erste Lehrkraft.');
            // Gleich auf ihre Seite - dort entsteht die erste Lehrkraft.
            redirect('schule.php?id=' . (int) db()->lastInsertId() . '&r=konten&g=lk');
        }
        redirect('schools.php');
    }
}

$schulen = qa('SELECT id FROM schools');

admin_head('Neue Schule', 'schools.php');
flash_render();
?>

<?php if ($schulen === []): ?>
<div class="notice">
    <strong>Hier fängt alles an.</strong> Ohne Schule kann kein Konto etwas
    sehen - Lerneinheiten hängen an Kursen, Kurse an Schulen. Leg zuerst eine
    an, dann auf ihrer Seite die erste Lehrkraft.
</div>
<?php endif; ?>

<form method="post" class="card" style="max-width:480px">
    <?= csrf_field() ?>
    <label for="name">Name der Schule</label>
    <input type="text" id="name" name="name" maxlength="128"
           placeholder="Gymnasium Musterstadt" required autofocus>
    <label for="kuerzel">Kürzel zum Anmelden</label>
    <input type="text" id="kuerzel" name="kuerzel" maxlength="12" autocapitalize="off"
           placeholder="gm" required pattern="[a-z0-9]{2,12}"
           title="2 bis 12 Kleinbuchstaben oder Ziffern">
    <p class="tiny muted" style="margin:-4px 0 10px">Kinder und Lehrkräfte tippen es bei der
        Anmeldung ein; es steht auf jedem Zettel.</p>
    <label for="maxlk">Höchstens so viele Lehrkräfte</label>
    <input type="number" id="maxlk" name="max_lehrkraefte" min="1" max="1000"
           value="<?= LEHRKRAEFTE_VOREINSTELLUNG ?>" style="width:120px">
    <p class="tiny muted" style="margin:-4px 0 10px">Lehrkräfte legen einander selbst an
        (&bdquo;Lehrkräfte&ldquo; im Menü) &ndash; bis zu dieser Zahl.</p>
    <button class="btn small" name="create" value="1">Schule anlegen</button>
</form>

<?php admin_foot(); ?>
