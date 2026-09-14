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

    $ergebnis = course_create(
        $user,
        (string) ($_POST['language'] ?? ''),
        (string) ($_POST['flag'] ?? ''),
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

teacher_head('Meine Kurse', 'index.php', $user);
teacher_flash_render();
?>

<?php if ($schule === null): ?>
    <div class="notice">
        Dieses Konto gehört zu keiner Schule. Ohne Schule gibt es keine Kurse -
        der Betreiber kann das im Admin-Bereich zuordnen.
    </div>
<?php else: ?>

<p class="muted"><?= h($schule['name']) ?></p>

<?php if ($kurse === []): ?>
    <p class="muted">
        In dieser Schule gibt es noch keinen Kurs. Der erste steht gleich unten.
    </p>
<?php else: ?>

<table class="data">
    <tr>
        <th>Kurs</th><th>Klasse</th><th>Sprache</th>
        <th class="num">SuS</th><th class="num">Lerneinheiten</th><th></th>
    </tr>
    <?php foreach ($kurse as $k): ?>
        <tr<?= $k['active'] ? '' : ' class="dim"' ?>>
            <td><strong><?= h($k['name']) ?></strong></td>
            <td><?= h($k['class_name'] ?? '-') ?></td>
            <td><?= h(trim($k['flag_emoji'] . ' ' . $k['language_name'])) ?></td>
            <td class="num"><?= (int) $k['students'] ?></td>
            <td class="num"><?= (int) $k['units'] ?></td>
            <td>
                <a href="<?= h(teacher_url('course.php') . '?id=' . (int) $k['id']) ?>">ansehen</a>
            </td>
        </tr>
    <?php endforeach; ?>
</table>

<p class="tiny muted">
    Hier stehen alle Kurse dieser Schule, nicht nur die eigenen. Eine Schule
    ist eine Vertrauensgemeinschaft: Wer eine Kollegin vertritt, kommt so ohne
    Umweg an die Unterlagen.
</p>

<?php endif; ?>

<h2>Neuer Kurs</h2>

<?php if ($klassen === []): ?>
    <div class="notice">
        Es gibt noch keine Klasse. Ein Kurs ohne Klasse ist möglich, aber dann
        musst du die Teilnehmenden einzeln eintragen -
        <a href="<?= h(teacher_url('classes.php')) ?>">erst eine Klasse anlegen</a>
        ist meistens schneller.
    </div>
<?php endif; ?>

<form method="post" class="card" style="max-width:560px">
    <?= teacher_csrf_field() ?>
    <div class="formgrid">
        <div>
            <label for="language">Sprache</label>
            <input type="text" id="language" name="language" placeholder="Englisch"
                   maxlength="64" required autofocus>
        </div>
        <div>
            <label for="class_id">Klasse</label>
            <select id="class_id" name="class_id">
                <?php foreach ($klassen as $k): ?>
                    <option value="<?= (int) $k['id'] ?>">
                        <?= h($k['name']) ?> (<?= (int) $k['students'] ?>)
                    </option>
                <?php endforeach; ?>
                <option value="0">- ohne Klasse -</option>
            </select>
        </div>
        <div>
            <label for="flag">Flagge</label>
            <input type="text" id="flag" name="flag" placeholder="🇬🇧" maxlength="16">
        </div>
        <div>
            <label for="name">Name des Kurses</label>
            <input type="text" id="name" name="name" placeholder="(Sprache + Klasse)"
                   maxlength="128">
        </div>
    </div>
    <button class="btn small" name="create_course" value="1">Kurs anlegen</button>
</form>

<p class="tiny muted">
    Der Name entsteht von selbst aus Sprache und Klasse - „Englisch 5B" -, lässt
    sich aber überschreiben. Die Kinder der Klasse kommen gleich mit in den Kurs;
    wer später dazukommt oder wegfällt, wird im Kurs selbst nachgetragen.
    <strong>Jeder Kurs hat seine eigenen Unterlagen:</strong> „Englisch 5B" und
    „Englisch 6A" teilen sich nichts, auch wenn beide Englisch unterrichten. Das
    kostet beim Einlesen doppelt und ist so gewollt - so kann jede Lehrkraft
    unabhängig arbeiten.
</p>

<?php endif; ?>

<?php teacher_foot(); ?>
