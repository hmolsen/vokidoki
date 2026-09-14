<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

// Abmelden vor der Anmeldepflicht - sonst käme man nie heraus.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['teacher_logout'])) {
    teacher_csrf_check();
    logout_user();
    teacher_redirect('index.php');
}

$user = teacher_require();

$schoolId = (int) ($user['school_id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['create_course'])) {
    teacher_csrf_check();

    /*
     * Die Flagge kommt aus der Sprachliste, nicht aus einem Feld. Sie ist
     * eine Eigenschaft der Sprache und keine Entscheidung, die eine
     * Lehrkraft treffen soll.
     */
    $sprache  = (string) ($_POST['language'] ?? '');
    $ergebnis = course_create(
        $user,
        $sprache,
        language_flag($sprache),
        (int) ($_POST['class_id'] ?? 0) ?: null,
        (string) ($_POST['name'] ?? ''),
    );

    if (is_string($ergebnis)) {
        teacher_flash($ergebnis, 'bad');
        teacher_redirect('index.php');
    }

    $drin = (int) qv('SELECT COUNT(*) FROM course_members WHERE course_id = ?',
                     [(int) $ergebnis['id']]);
    teacher_flash(sprintf(
        'Kurs "%s" angelegt, %d Teilnehmende.', $ergebnis['name'], $drin,
    ));
    teacher_redirect('course.php?id=' . (int) $ergebnis['id']);
}

$schule  = $schoolId > 0 ? q1('SELECT * FROM schools WHERE id = ?', [$schoolId]) : null;
$kurse   = $schoolId > 0 ? courses_for_school($schoolId) : [];
$klassen = $schoolId > 0 ? classes_for_school($schoolId) : [];

teacher_head('Kurse', 'index.php', $user);
teacher_flash_render();
?>

<?php if ($schule === null): ?>
    <div class="notice">
        Dieses Konto gehört zu keiner Schule. Ohne Schule gibt es keine Kurse -
        der Betreiber kann das im Admin-Bereich zuordnen.
    </div>
<?php else: ?>

<p class="muted"><?= h($schule['name']) ?></p>

<?php
/*
 * Alles in einer Tabelle - auch das Anlegen.
 *
 * Vorher standen Liste und Anlegeformular als zwei getrennte Bloecke
 * untereinander, das Formular in einer eigenen Karte. Fuer eine Sache, die
 * zur Liste gehoert, ist das ein Bruch: Man legt einen Kurs an, um ihn in
 * der Liste zu haben. Jetzt ist die letzte Zeile die neue Zeile.
 */
?>
<table class="data courses">
    <tr>
        <th>Kurs</th>
        <th>Klasse</th>
        <th class="num">Kinder</th>
        <th class="num">Lerneinheiten</th>
        <th class="num">Freigegeben</th>
        <th class="actions"></th>
    </tr>

    <?php foreach ($kurse as $k): ?>
        <tr<?= $k['active'] ? '' : ' class="dim"' ?>>
            <td>
                <span class="coursetitle">
                    <span class="cflag"><?= h($k['flag_emoji'] ?: "\u{1F310}") ?></span>
                    <span>
                        <strong><?= h($k['name']) ?></strong>
                        <span class="tiny muted"><?= h($k['language_name']) ?></span>
                    </span>
                </span>
            </td>
            <td><?= $k['class_name'] === null
                    ? '<span class="muted">&ndash;</span>' : h($k['class_name']) ?></td>
            <td class="num"><?= (int) $k['students'] ?></td>
            <td class="num"><?= (int) $k['units'] ?></td>
            <td class="num">
                <?php
                $frei   = (int) $k['released'];
                $gesamt = (int) $k['vocab'];
                if ($gesamt === 0) {
                    echo '<span class="muted">&ndash;</span>';
                } elseif ($frei >= $gesamt) {
                    printf('<span class="pill good">alle %d</span>', $gesamt);
                } else {
                    printf('<span class="pill">%d von %d</span>', $frei, $gesamt);
                }
                ?>
            </td>
            <td class="actions">
                <a class="iconaction" title="Kurs oeffnen"
                   href="<?= h(teacher_url('course.php') . '?id=' . (int) $k['id']) ?>">
                    <span aria-hidden="true">&#128214;</span> Öffnen
                </a>
            </td>
        </tr>
    <?php endforeach; ?>

    <?php
    /*
     * Die Anlegezeile. Ein Formular ueber mehrere Zellen geht in HTML nicht -
     * deshalb liegt es ausserhalb der Tabelle, und die Felder verweisen
     * ueber form="..." darauf. So bleibt die Zeile eine Zeile.
     */
    ?>
    <tr class="newrow">
        <td>
            <span class="coursetitle">
                <span class="cflag plus">+</span>
                <select name="language" form="newcourse" data-picker required>
                    <?php foreach (language_choices() as $s): ?>
                        <option value="<?= h($s['name']) ?>"
                                data-flag="<?= h($s['flag']) ?>"
                                data-top="<?= $s['top'] ? '1' : '0' ?>">
                            <?= h($s['flag'] . ' ' . $s['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </span>
        </td>
        <td>
            <select name="class_id" form="newcourse">
                <?php foreach ($klassen as $k): ?>
                    <option value="<?= (int) $k['id'] ?>" data-name="<?= h($k['name']) ?>">
                        <?= h($k['name']) ?>
                    </option>
                <?php endforeach; ?>
                <option value="0" data-name="">ohne Klasse</option>
            </select>
        </td>
        <td colspan="2">
            <div class="coursename">
                <strong data-coursename></strong>
                <button type="button" class="iconbtn" data-editname
                        title="Namen selbst waehlen" aria-label="Namen selbst waehlen">&#9998;</button>
                <input type="text" name="name" form="newcourse" maxlength="128" hidden>
            </div>
        </td>
        <td></td>
        <td class="actions">
            <button class="iconaction primary" form="newcourse"
                    name="create_course" value="1" title="Kurs anlegen">
                <span aria-hidden="true">+</span> Anlegen
            </button>
        </td>
    </tr>
</table>

<form method="post" id="newcourse" data-coursform hidden>
    <?= teacher_csrf_field() ?>
</form>

<p class="tiny muted">
    <?php if ($klassen === []): ?>
        <strong>Es gibt noch keine Klasse.</strong> Ein Kurs ohne Klasse geht,
        dann traegst du die Teilnehmenden einzeln ein -
        <a href="<?= h(teacher_url('classes.php')) ?>">erst eine Klasse anlegen</a>
        ist meistens schneller.<br>
    <?php endif; ?>
    Der Name ergibt sich aus Sprache und Klasse; der Stift macht ihn frei
    waehlbar. Die Kinder der Klasse kommen gleich mit in den Kurs.
    Hier stehen alle Kurse dieser Schule, nicht nur die eigenen - wer eine
    Kollegin vertritt, kommt so ohne Umweg an die Unterlagen.
    <strong>Jeder Kurs hat seine eigenen Unterlagen:</strong> „Englisch - 5B"
    und „Englisch - 6A" teilen sich nichts.
</p>

<?php endif; ?>

<?php teacher_foot(); ?>
