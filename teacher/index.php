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
$schule   = $schoolId > 0 ? q1('SELECT * FROM schools WHERE id = ?', [$schoolId]) : null;
$kurse    = $schoolId > 0 ? courses_for_school($schoolId) : [];

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
    <p class="muted">In dieser Schule gibt es noch keinen Kurs.</p>
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
<?php endif; ?>

<?php teacher_foot(); ?>
