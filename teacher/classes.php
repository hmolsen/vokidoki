<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

$user     = teacher_require();
$schoolId = (int) ($user['school_id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['create_class'])) {
    teacher_csrf_check();

    if ($schoolId === 0) {
        teacher_flash('Dieses Konto gehört zu keiner Schule.', 'bad');
        teacher_redirect('classes.php');
    }

    $ergebnis = class_create($schoolId, (string) ($_POST['name'] ?? ''));

    if (is_string($ergebnis)) {
        teacher_flash($ergebnis, 'bad');
        teacher_redirect('classes.php');
    }

    teacher_flash(sprintf('Klasse "%s" angelegt.', $ergebnis['name']));
    teacher_redirect('class.php?id=' . (int) $ergebnis['id']);
}

$klassen = $schoolId > 0 ? classes_for_school($schoolId) : [];

/*
 * Die Klassenliste ist Verwaltung, kein Durchgang mehr.
 *
 * Hier stand einmal der Anfang jedes Weges: Klassen > Klasse > Kurs. Der
 * Kurs ist jetzt die Hauptansicht und steht auf der Startseite; diese Seite
 * ist das, wonach sie aussieht - die Liste der Klassen, in denen Kinder
 * angelegt und Zettel gedruckt werden. Auch die Liste "Kurse ohne Klasse"
 * ist deshalb weg: Alle Kurse stehen auf der Startseite, mit und ohne.
 */
teacher_head('Klassen', $user, [['label' => 'Klassen', 'href' => null]]);
teacher_flash_render();
?>

<?php if ($schoolId === 0): ?>
    <div class="notice">
        Dieses Konto gehört zu keiner Schule. Ohne Schule gibt es keine Klassen -
        der Betreiber kann das im Admin-Bereich zuordnen.
    </div>
<?php else: ?>

<?php
/*
 * Eine Tabelle, und die letzte Zeile ist die neue - wie bei den Kursen.
 * Anlegen gehoert zur Liste: Man legt eine Klasse an, um sie dort zu haben.
 */
?>
<table class="data courses rowlink">
    <tr>
        <th>Klasse</th>
        <th class="num">Kinder</th>
        <th class="num">Kurse</th>
        <th class="actions"></th>
    </tr>

    <?php foreach ($klassen as $k): ?>
        <?php $ziel = teacher_url('class.php') . '?id=' . (int) $k['id']; ?>
        <tr<?= $k['active'] ? '' : ' class="dim"' ?> data-href="<?= h($ziel) ?>">
            <td data-label="Klasse">
                <span class="coursetitle">
                    <span class="cflag">&#128101;</span>
                    <span>
                        <a class="rowmain" href="<?= h($ziel) ?>"><?= h($k['name']) ?></a>
                        <?php if (!$k['active']): ?>
                            <span class="tiny muted">stillgelegt</span>
                        <?php endif; ?>
                    </span>
                </span>
            </td>
            <td class="num" data-label="Kinder">
                <?= (int) $k['students'] === 0
                    ? '<span class="muted">&ndash;</span>'
                    : (int) $k['students'] ?>
            </td>
            <td class="num" data-label="Kurse">
                <?= (int) $k['courses'] === 0
                    ? '<span class="muted">&ndash;</span>'
                    : (int) $k['courses'] ?>
            </td>
            <td class="actions chev" aria-hidden="true">&#8250;</td>
        </tr>
    <?php endforeach; ?>

    <?php
    /*
     * Wie bei den Kursen: Das Formular liegt neben der Tabelle, weil es sich
     * in HTML nicht ueber mehrere Zellen spannen laesst. Das Feld verweist
     * ueber form= darauf.
     */
    ?>
    <tr class="newrow">
        <td data-label="Neue Klasse">
            <span class="coursetitle">
                <span class="cflag plus">+</span>
                <input type="text" name="name" form="newclass" placeholder="5B"
                       maxlength="32" required aria-label="Name der neuen Klasse">
            </span>
        </td>
        <td colspan="2"></td>
        <td class="actions">
            <button class="iconaction primary" form="newclass"
                    name="create_class" value="1" title="Klasse anlegen">
                <span aria-hidden="true">+</span> Anlegen
            </button>
        </td>
    </tr>
</table>

<form method="post" id="newclass" hidden>
    <?= teacher_csrf_field() ?>
</form>

<p class="tiny muted">
    Eine Zeile anklicken öffnet die Klasse. Dort trägst du die Kinder ein
    und druckst ihre Zettel.
    Die Klasse nennst du am besten so, wie sie in der Schule heißt (zum
    Beispiel „5a", „9B" oder „7.2"). Ein Kurs darin heißt dann von selbst
    „Englisch - 9B".<br>
    <strong>Kurse stehen nicht hier, sondern auf der Startseite</strong> &ndash;
    dort legst du auch neue an. Die Kinder einer Klasse kommen dabei gleich
    mit in den Kurs; danach sind Klassenliste und Kursliste unabhängig
    voneinander.
</p>

<?php endif; ?>

<?php teacher_foot(); ?>
