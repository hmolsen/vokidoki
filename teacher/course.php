<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['teacher_logout'])) {
    teacher_csrf_check();
    logout_user();
    teacher_redirect('index.php');
}

$user = teacher_require();

$courseId = (int) ($_GET['id'] ?? 0);
$kurs     = course_in_school($courseId, (int) ($user['school_id'] ?? 0));

if ($kurs === null) {
    // Bewusst dieselbe Meldung wie bei einem Kurs, den es gar nicht gibt:
    // Wer ihn nicht sehen darf, soll nicht erfahren, dass er existiert.
    teacher_flash('Diesen Kurs gibt es nicht.', 'bad');
    teacher_redirect('index.php');
}

$mitglieder = course_members_list($courseId);
$einheiten  = course_units_list($courseId);

teacher_head($kurs['name'], 'index.php', $user);
teacher_flash_render();
?>

<p class="muted">
    <?= h(trim($kurs['flag_emoji'] . ' ' . $kurs['language_name'])) ?>
    <?php if (($kurs['class_name'] ?? null) !== null): ?>
        &middot; Klasse <?= h($kurs['class_name']) ?>
    <?php endif; ?>
    &middot; <a href="<?= h(teacher_url('index.php')) ?>">zurück zur Übersicht</a>
</p>

<h2>Lerneinheiten</h2>

<?php if ($einheiten === []): ?>
    <p class="muted">In diesem Kurs gibt es noch keine Lerneinheit.</p>
<?php else: ?>
<table class="data">
    <tr>
        <th>Titel</th><th class="num">Vokabeln</th><th>Freigegeben</th>
        <th>Angelegt</th><th></th>
    </tr>
    <?php foreach ($einheiten as $e): ?>
        <tr>
            <td><?= h($e['title']) ?></td>
            <td class="num"><?= (int) $e['vocab_count'] ?></td>
            <td>
                <?php
                $frei   = (int) $e['released_count'];
                $gesamt = (int) $e['vocab_count'];
                if ($gesamt === 0) {
                    echo '<span class="muted">&ndash;</span>';
                } elseif ($frei >= $gesamt) {
                    echo 'alle';
                } elseif ($frei === 0) {
                    echo '<span class="muted">noch keine</span>';
                } else {
                    printf('%d von %d', $frei, $gesamt);
                }
                ?>
            </td>
            <td class="tiny muted"><?= h(substr((string) $e['created_at'], 0, 10)) ?></td>
            <td>
                <a href="<?= h(teacher_url('unit.php') . '?id=' . (int) $e['id']) ?>">
                    freigeben
                </a>
            </td>
        </tr>
    <?php endforeach; ?>
</table>
<p class="tiny muted">
    Freigegeben wird portionsweise, und das hat einen Grund: Zu jeder
    freigegebenen Vokabel entstehen Lückensätze, und die kosten. Eine ganze
    Unit einlesen und nur das aufmachen, was dran ist, spart den Rest -
    solange er nicht dran ist. Fotografiert wird in der App auf dem Handy;
    dafür braucht es die Kamera.
</p>
<?php endif; ?>

<h2>Wer im Kurs ist</h2>

<?php if ($mitglieder === []): ?>
    <p class="muted">Noch niemand.</p>
<?php else: ?>
<table class="data">
    <tr><th>Name</th><th>Benutzername</th><th>Rolle</th></tr>
    <?php foreach ($mitglieder as $m): ?>
        <tr<?= $m['active'] ? '' : ' class="dim"' ?>>
            <td><?= h($m['display_name']) ?></td>
            <td><code class="token"><?= h($m['username']) ?></code></td>
            <td><?= $m['member_role'] === 'teacher' ? 'Lehrkraft' : 'SchülerIn' ?></td>
        </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>

<?php teacher_foot(); ?>
