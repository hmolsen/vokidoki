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

teacher_head('Klassen', 'classes.php', $user);
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
<table class="data courses">
    <tr>
        <th>Klasse</th>
        <th class="num">Kinder</th>
        <th class="num">Kurse</th>
        <th class="actions"></th>
    </tr>

    <?php foreach ($klassen as $k): ?>
        <tr<?= $k['active'] ? '' : ' class="dim"' ?>>
            <td>
                <span class="coursetitle">
                    <span class="cflag">&#128101;</span>
                    <span>
                        <strong><?= h($k['name']) ?></strong>
                        <?php if (!$k['active']): ?>
                            <span class="tiny muted">stillgelegt</span>
                        <?php endif; ?>
                    </span>
                </span>
            </td>
            <td class="num">
                <?= (int) $k['students'] === 0
                    ? '<span class="muted">&ndash;</span>'
                    : (int) $k['students'] ?>
            </td>
            <td class="num">
                <?= (int) $k['courses'] === 0
                    ? '<span class="muted">&ndash;</span>'
                    : (int) $k['courses'] ?>
            </td>
            <td class="actions">
                <a class="iconaction" title="Klasse oeffnen"
                   href="<?= h(teacher_url('class.php') . '?id=' . (int) $k['id']) ?>">
                    <span aria-hidden="true">&#128101;</span> Öffnen
                </a>
            </td>
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
        <td>
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
    So, wie die Klasse im Stundenplan heisst - „5B", „7c", „Q1". Die Kinder
    kommen im naechsten Schritt hinein: Klasse oeffnen, Namensliste
    hineinkopieren. Aus einer Klasse wird noch kein Kurs - den legst du unter
    <a href="<?= h(teacher_url('index.php')) ?>">Kurse</a> an, und die Kinder
    der Klasse kommen dann gleich mit hinein.
</p>

<?php endif; ?>

<?php teacher_foot(); ?>
