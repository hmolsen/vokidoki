<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['teacher_logout'])) {
    teacher_csrf_check();
    logout_user();
    teacher_redirect('index.php');
}

$user = teacher_require();

$schoolId = (int) ($user['school_id'] ?? 0);
$courseId = (int) ($_GET['id'] ?? $_POST['course_id'] ?? 0);
$kurs     = course_in_school($courseId, $schoolId);

if ($kurs === null) {
    // Bewusst dieselbe Meldung wie bei einem Kurs, den es gar nicht gibt:
    // Wer ihn nicht sehen darf, soll nicht erfahren, dass er existiert.
    teacher_flash('Diesen Kurs gibt es nicht.', 'bad');
    teacher_redirect('index.php');
}

$zurueck = 'course.php?id=' . $courseId;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['sync_class'])) {
    teacher_csrf_check();
    $neu = course_sync_class($courseId);
    teacher_flash($neu === 0
        ? 'Es war niemand nachzutragen.'
        : sprintf('%d aus der Klasse nachgetragen.', $neu));
    teacher_redirect($zurueck);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['add_member'])) {
    teacher_csrf_check();

    $wer = (int) $_POST['add_member'];

    // Nur Konten der eigenen Schule. Sonst liesse sich ueber ein
    // untergeschobenes Formular jedes Konto der Anwendung in den Kurs holen.
    $konto = q1('SELECT id, display_name, role FROM users WHERE id = ? AND school_id = ?',
                [$wer, $schoolId]);

    if ($konto === null) {
        teacher_flash('Dieses Konto gehört nicht zu dieser Schule.', 'bad');
        teacher_redirect($zurueck);
    }

    course_add_member($courseId, $wer,
        $konto['role'] === ROLE_TEACHER ? COURSE_ROLE_TEACHER : COURSE_ROLE_STUDENT);
    teacher_flash(sprintf('%s ist jetzt im Kurs.', $konto['display_name']));
    teacher_redirect($zurueck);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['remove_member'])) {
    teacher_csrf_check();

    $wer   = (int) $_POST['remove_member'];
    $konto = q1(
        'SELECT u.display_name FROM course_members m
           JOIN users u ON u.id = m.user_id
          WHERE m.course_id = ? AND m.user_id = ?',
        [$courseId, $wer],
    );

    if ($konto === null) {
        teacher_flash('Diese Person ist gar nicht im Kurs.', 'bad');
        teacher_redirect($zurueck);
    }

    course_remove_member($courseId, $wer);
    teacher_flash(sprintf(
        '%s ist nicht mehr im Kurs. Der Lernstand bleibt erhalten, falls '
        . 'die Aufnahme zurückgenommen wird.', $konto['display_name'],
    ));
    teacher_redirect($zurueck);
}

$mitglieder = course_members_list($courseId);
$einheiten  = course_units_list($courseId);
$offene     = course_candidates($courseId, $schoolId);

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
    <tr><th>Name</th><th>Benutzername</th><th>Rolle</th><th></th></tr>
    <?php foreach ($mitglieder as $m): ?>
        <tr<?= $m['active'] ? '' : ' class="dim"' ?>>
            <td><?= h($m['display_name']) ?></td>
            <td><code class="token"><?= h($m['username']) ?></code></td>
            <td><?= $m['member_role'] === 'teacher' ? 'Lehrkraft' : 'SchülerIn' ?></td>
            <td>
                <form method="post" class="compact">
                    <?= teacher_csrf_field() ?>
                    <input type="hidden" name="course_id" value="<?= $courseId ?>">
                    <button class="linkbtn" name="remove_member" value="<?= (int) $m['id'] ?>"
                            data-confirm="<?= h($m['display_name']) ?> aus dem Kurs nehmen? Der Lernstand bleibt erhalten.">
                        entfernen
                    </button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>

<?php if (($kurs['class_name'] ?? null) !== null): ?>
<form method="post" class="compact">
    <?= teacher_csrf_field() ?>
    <input type="hidden" name="course_id" value="<?= $courseId ?>">
    <button class="btn secondary small" name="sync_class" value="1">
        Klasse <?= h($kurs['class_name']) ?> nachtragen
    </button>
</form>
<?php endif; ?>

<?php if ($offene !== []): ?>
<form method="post" class="inline" style="margin-top:10px">
    <?= teacher_csrf_field() ?>
    <input type="hidden" name="course_id" value="<?= $courseId ?>">
    <select name="add_member" style="width:auto;margin:0">
        <?php foreach ($offene as $o): ?>
            <option value="<?= (int) $o['id'] ?>">
                <?= h($o['display_name']) ?><?= $o['role'] === ROLE_TEACHER ? ' (Lehrkraft)' : '' ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button class="btn secondary small" type="submit">Einzeln aufnehmen</button>
</form>
<?php endif; ?>

<p class="tiny muted">
    Wer im Kurs ist, übt dessen Lerneinheiten - unabhängig davon, in welcher
    Klasse er steht. Beim Anlegen wird die Klasse übernommen; danach ist die
    Kursliste die maßgebliche. Jemanden zu entfernen löscht keinen Lernstand:
    Kommt er zurück, ist der Stand wieder da.
</p>

<script>
document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-confirm]');
    if (b && !confirm(b.dataset.confirm)) e.preventDefault();
});
</script>

<?php teacher_foot(); ?>
