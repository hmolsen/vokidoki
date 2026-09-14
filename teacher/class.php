<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['teacher_logout'])) {
    teacher_csrf_check();
    logout_user();
    teacher_redirect('index.php');
}

$user     = teacher_require();
$schoolId = (int) ($user['school_id'] ?? 0);

$classId = (int) ($_GET['id'] ?? $_POST['class_id'] ?? 0);
$klasse  = $schoolId > 0 ? class_in_school($classId, $schoolId) : null;

if ($klasse === null) {
    // Dieselbe Meldung wie bei einer Klasse, die es nicht gibt: Wer sie nicht
    // sehen darf, soll nicht erfahren, dass sie existiert.
    teacher_flash('Diese Klasse gibt es nicht.', 'bad');
    teacher_redirect('classes.php');
}

$zurück = 'class.php?id=' . $classId;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['add_students'])) {
    teacher_csrf_check();

    $bericht = students_bulk_create($schoolId, $classId, (string) ($_POST['names'] ?? ''));
    $neu     = count($bericht['created']);
    $weg     = count($bericht['skipped']);

    if ($neu === 0 && $weg === 0) {
        teacher_flash('Da war keine einzige Zeile mit einem Namen darin.', 'bad');
    } else {
        /*
         * Die frisch angelegten Konten wandern in die Sitzung, damit die
         * folgende Seite die Passwörter zeigen kann. Sie stehen zwar auch in
         * der Datenbank, aber nur so hebt sich hervor, wer gerade neu ist -
         * bei einer Klasse mit 28 Kindern ist das der Unterschied zwischen
         * "brauchbar" und "such es dir raus".
         */
        $_SESSION['teacher_fresh'] = array_map(
            static fn (array $u): int => (int) $u['id'],
            $bericht['created'],
        );

        teacher_flash(sprintf(
            '%d %s angelegt%s.',
            $neu,
            $neu === 1 ? 'Konto' : 'Konten',
            $weg === 0 ? '' : sprintf(', %d schon vorhanden übersprungen', $weg),
        ));
    }

    teacher_redirect($zurück);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['unlock'])) {
    teacher_csrf_check();

    $kindId = (int) $_POST['unlock'];

    $gehoert = q1(
        'SELECT u.id, u.username, u.display_name FROM class_members m
           JOIN users u ON u.id = m.user_id
          WHERE m.class_id = ? AND u.id = ?',
        [$classId, $kindId],
    );

    if ($gehoert === null) {
        teacher_flash('Dieses Kind ist nicht in dieser Klasse.', 'bad');
        teacher_redirect($zurück);
    }

    /*
     * Aufschliessen, ohne das Passwort anzufassen. Wer sich nur vertippt hat
     * und sein Passwort kennt, soll nicht eine Viertelstunde warten und auch
     * kein neues Passwort abtippen muessen.
     */
    login_attempts_reset((string) $gehoert['username']);
    teacher_flash(sprintf('%s kann sich wieder anmelden.', $gehoert['display_name']));
    teacher_redirect($zurück);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['reset_password'])) {
    teacher_csrf_check();

    $kindId = (int) $_POST['reset_password'];

    // Nur Kinder aus genau dieser Klasse - sonst liesse sich über ein
    // gefälschtes Formular jedes Konto der Anwendung zurücksetzen.
    $gehörtDazu = q1(
        'SELECT u.id, u.display_name FROM class_members m
           JOIN users u ON u.id = m.user_id
          WHERE m.class_id = ? AND u.id = ?',
        [$classId, $kindId],
    );

    if ($gehörtDazu === null) {
        teacher_flash('Dieses Kind ist nicht in dieser Klasse.', 'bad');
        teacher_redirect($zurück);
    }

    $neuesPasswort = student_reset_password($kindId);

    if ($neuesPasswort === null) {
        teacher_flash('Das Passwort liess sich nicht neu setzen.', 'bad');
    } else {
        $_SESSION['teacher_fresh'] = [$kindId];
        teacher_flash(sprintf('%s hat ein neues Passwort.', $gehörtDazu['display_name']));
    }

    teacher_redirect($zurück);
}

$kinder    = class_members_list($classId);
$gesperrt  = login_locked_usernames(array_column($kinder, 'username'));
$frisch    = array_flip((array) ($_SESSION['teacher_fresh'] ?? []));
unset($_SESSION['teacher_fresh']);

teacher_head('Klasse ' . $klasse['name'], 'classes.php', $user);
teacher_flash_render();
?>

<p class="muted">
    <a href="<?= h(teacher_url('classes.php')) ?>">zurück zu den Klassen</a>
</p>

<?php if ($kinder === []): ?>
    <p class="muted">Noch niemand in dieser Klasse.</p>
<?php else: ?>
<table class="data">
    <tr>
        <th>Name</th><th>Benutzername</th><th>Anfangspasswort</th><th class="actions"></th>
    </tr>
    <?php foreach ($kinder as $k): ?>
        <tr<?= isset($frisch[(int) $k['id']]) ? ' class="hit"' : ($k['active'] ? '' : ' class="dim"') ?>>
            <td>
                <?= h($k['display_name']) ?>
                <?php if (isset($gesperrt[$k['username']])): ?>
                    <span class="tiny" style="color:var(--bad)">&nbsp;gesperrt</span>
                <?php endif; ?>
            </td>
            <td><code class="token"><?= h($k['username']) ?></code></td>
            <td>
                <?php if (($k['initial_password'] ?? null) !== null && $k['initial_password'] !== ''): ?>
                    <code class="token"><?= h($k['initial_password']) ?></code>
                <?php else: ?>
                    <span class="tiny muted">selbst geändert</span>
                <?php endif; ?>
            </td>
            <td class="actions">
                <?php if ($k['role'] !== 'teacher'): ?>
                <?php if (isset($gesperrt[$k['username']])): ?>
                <form method="post" class="compact">
                    <?= teacher_csrf_field() ?>
                    <input type="hidden" name="class_id" value="<?= $classId ?>">
                    <button class="iconaction" name="unlock"
                            value="<?= (int) $k['id'] ?>" title="Konto wieder freigeben">
                        <span aria-hidden="true">&#128275;</span> Entsperren
                    </button>
                </form>
                <?php endif; ?>
                <form method="post" class="compact">
                    <?= teacher_csrf_field() ?>
                    <input type="hidden" name="class_id" value="<?= $classId ?>">
                    <?php
                    /*
                     * Der Name steht in einem data-Attribut statt im
                     * onclick-Text. Dort müsste er zugleich für JavaScript
                     * und für HTML maskiert werden, und ein Kind namens
                     * "N'Diaye" bricht so eine Verschachtelung zuverlässig
                     * auf. h() allein genügt für ein Attribut.
                     */
                    ?>
                    <button class="iconaction quiet" name="reset_password"
                            value="<?= (int) $k['id'] ?>" title="Neues Anfangspasswort"
                            data-confirm="Neues Anfangspasswort für <?= h($k['display_name']) ?>? Das alte gilt dann nicht mehr.">
                        <span aria-hidden="true">&#128273;</span> Passwort
                    </button>
                </form>
                <a class="iconaction quiet" title="Zettel für dieses Kind drucken"
                   href="<?= h(teacher_url('print.php') . '?class=' . $classId . '&user=' . (int) $k['id']) ?>"
                   target="_blank" rel="noopener">
                    <span aria-hidden="true">&#128424;</span> Zettel
                </a>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
</table>

<p>
    <a class="btn" href="<?= h(teacher_url('print.php') . '?class=' . $classId) ?>"
       target="_blank" rel="noopener">Zettel für die ganze Klasse</a>
</p>

<p class="tiny muted">
    Das Anfangspasswort steht hier im Klartext, damit sich das Anschreiben
    nachdrucken lässt. Sobald ein Kind sein Passwort selbst ändert,
    verschwindet es aus dieser Spalte. Den Text des Anschreibens legt der
    Betreiber im Admin-Bereich fest.
</p>
<?php endif; ?>

<h2>Kinder hinzufügen</h2>

<form method="post" class="card" style="max-width:560px">
    <?= teacher_csrf_field() ?>
    <input type="hidden" name="class_id" value="<?= $classId ?>">
    <label for="names">Klassenliste, ein Name je Zeile</label>
    <textarea id="names" name="names" rows="12"
              placeholder="Lilli Molsen&#10;Schmidt, Anna-Lena&#10;Max"></textarea>
    <button class="btn" name="add_students" value="1">Konten anlegen</button>
</form>

<p class="tiny muted">
    Einfach die Liste hineinkopieren, wie sie vorliegt - "Lilli Molsen" und
    "Molsen, Lilli" werden beide verstanden. <strong>Der Nachname wird dabei
    weggeworfen</strong> und gar nicht erst gespeichert; übrig bleibt
    "Lilli M.". Benutzername und Anfangspasswort entstehen von selbst. Wer
    schon in der Klasse ist, wird übersprungen - die Liste lässt sich also
    gefahrlos ein zweites Mal einfügen.
</p>

<script>
// Rückfrage für alles, was ein data-confirm trägt. Ohne Zeilenweise-
// onclick-Attribute, in denen ein Name mit Apostroph den Code zerlegt.
document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-confirm]');
    if (b && !confirm(b.dataset.confirm)) e.preventDefault();
});
</script>

<?php teacher_foot(); ?>
