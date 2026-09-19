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
 * Kurse ohne Klasse.
 *
 * Es gibt sie: Der Altbestand aus der Familienzeit hat je Kind einen Kurs
 * und keine Klasse, und frueher liess sich hier "ohne Klasse" auswaehlen.
 * Neue Kurse entstehen jetzt nur noch in einer Klasse - aber die alten
 * duerfen deshalb nicht unerreichbar werden. Sie stehen unten, und nur
 * wenn es sie gibt.
 */
$ohneKlasse = $schoolId > 0
    ? array_values(array_filter(
        courses_for_school($schoolId),
        static fn (array $c): bool => $c['class_id'] === null,
    ))
    : [];

teacher_head('Klassen', $user);
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
    Eine Zeile anklicken öffnet die Klasse. Dort stehen die Kurse und die
    Kinder &ndash; beides legst du in der Klasse an, nicht hier.
    Die Klasse nennst du am besten so, wie sie in der Schule heißt (zum
    Beispiel „5a", „9B" oder „7.2"). Ein Kurs darin heißt dann von selbst
    „Englisch - 9B".<br>
    Die Kinder einer Klasse kommen beim Anlegen eines Kurses gleich mit
    hinein. Danach sind Klassenliste und Kursliste unabhängig voneinander:
    Im Kurs kannst du einzelne Kinder nachtragen oder herausnehmen.
</p>

<?php if ($ohneKlasse !== []): ?>
<h2>Kurse ohne Klasse</h2>

<table class="data courses rowlink">
    <tr>
        <th>Kurs</th>
        <th class="num">Kinder</th>
        <th class="num">Lerneinheiten</th>
        <th class="actions"></th>
    </tr>
    <?php foreach ($ohneKlasse as $c): ?>
        <?php $ziel = teacher_url('course.php') . '?id=' . (int) $c['id']; ?>
        <tr<?= $c['active'] ? '' : ' class="dim"' ?> data-href="<?= h($ziel) ?>">
            <td data-label="Kurs">
                <span class="coursetitle">
                    <?= flag_html($c['flag_emoji'] ?: FLAG_FALLBACK, 'cflag') ?>
                    <span>
                        <a class="rowmain" href="<?= h($ziel) ?>"><?= h($c['name']) ?></a>
                        <span class="tiny muted"><?= h($c['language_name']) ?><?php
                            if (!$c['active']) { echo ' &middot; stillgelegt'; } ?></span>
                    </span>
                </span>
            </td>
            <td class="num" data-label="Kinder"><?= (int) $c['students'] ?></td>
            <td class="num" data-label="Lerneinheiten"><?= (int) $c['units'] ?></td>
            <td class="actions chev" aria-hidden="true">&#8250;</td>
        </tr>
    <?php endforeach; ?>
</table>

<p class="tiny muted">
    Diese Kurse hängen an keiner Klasse &ndash; so entstanden sie früher, als
    es noch keine Klassen gab. Sie funktionieren weiter; neue Kurse legst du
    in einer Klasse an.
</p>
<?php endif; ?>

<?php endif; ?>

<?php teacher_foot(); ?>
