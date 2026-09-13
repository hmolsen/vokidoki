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

<?php if ($klassen === []): ?>
    <p class="muted">Noch keine Klasse. Die erste steht gleich unten.</p>
<?php else: ?>
<table class="data">
    <tr><th>Klasse</th><th class="num">Kinder</th><th></th></tr>
    <?php foreach ($klassen as $k): ?>
        <tr<?= $k['active'] ? '' : ' class="dim"' ?>>
            <td><strong><?= h($k['name']) ?></strong></td>
            <td class="num"><?= (int) $k['students'] ?></td>
            <td><a href="<?= h(teacher_url('class.php') . '?id=' . (int) $k['id']) ?>">öffnen</a></td>
        </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>

<h2>Neue Klasse</h2>

<form method="post" class="card" style="max-width:420px">
    <?= teacher_csrf_field() ?>
    <label for="name">Name</label>
    <input type="text" id="name" name="name" placeholder="5B" maxlength="32" required autofocus>
    <button class="btn" name="create_class" value="1">Anlegen</button>
</form>

<p class="tiny muted">
    So, wie die Klasse im Stundenplan heisst - "5B", "7c", "Q1". Die Kinder
    kommen im nächsten Schritt hinein.
</p>

<?php endif; ?>

<?php teacher_foot(); ?>
