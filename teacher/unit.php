<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/sentences.php';

/*
 * Eine Lerneinheit aus Sicht der Lehrkraft - und die Stelle, an der
 * freigegeben wird.
 *
 * Der Sinn der portionsweisen Freigabe ist nicht Paedagogik, sondern Geld:
 * Zu jeder freigegebenen Vokabel entstehen Lueckensaetze, und die kosten.
 * Deshalb wird eine ganze Unit auf einmal eingelesen - das Fotografieren
 * lohnt sich seitenweise - aber nur das aufgemacht, was gerade dran ist.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['teacher_logout'])) {
    teacher_csrf_check();
    logout_user();
    teacher_redirect('index.php');
}

$user     = teacher_require();
$schoolId = (int) ($user['school_id'] ?? 0);

$unitId = (int) ($_GET['id'] ?? $_POST['unit_id'] ?? 0);

/*
 * Die Einheit muss zur Schule dieser Lehrkraft gehoeren. Geprueft wird ueber
 * den Kurs, nicht ueber units.user_id - eine Vertretung soll an die
 * Unterlagen ihrer Kollegin kommen, ein fremdes Kollegium nicht.
 */
$unit = $schoolId === 0 ? null : q1(
    'SELECT t.*, co.name AS course_name, co.school_id, c.name AS class_name,
            l.name AS language_name, l.flag_emoji
       FROM units t
       JOIN courses co   ON co.id = t.course_id
       JOIN languages l  ON l.id = t.language_id
       LEFT JOIN classes c ON c.id = co.class_id
      WHERE t.id = ? AND co.school_id = ?',
    [$unitId, $schoolId],
);

if ($unit === null) {
    teacher_flash('Diese Lerneinheit gibt es nicht.', 'bad');
    teacher_redirect('index.php');
}

$zurueck = 'unit.php?id=' . $unitId;
$gesamt  = (int) qv('SELECT COUNT(*) FROM vocab WHERE unit_id = ?', [$unitId]);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['release'])) {
    teacher_csrf_check();

    $bis = (int) $_POST['release'];
    if ($bis < 0 || $bis > $gesamt) {
        teacher_flash('Diese Stelle gibt es in der Lerneinheit nicht.', 'bad');
        teacher_redirect($zurueck);
    }

    $vorher = (int) $unit['released_position'];

    /*
     * Der Hoechstwert ist keine Zahl, die jemand meint, sondern "alles" -
     * so steht der Altbestand da. Er wird beim Rechnen wie $gesamt
     * behandelt, damit "eins zurueck" nicht vier Milliarden Schritte weit
     * springt.
     */
    if ($vorher > $gesamt) {
        $vorher = $gesamt;
    }

    q('UPDATE units SET released_position = ? WHERE id = ?', [$bis, $unitId]);

    if ($bis <= $vorher) {
        teacher_flash($bis === 0
            ? 'Die Freigabe ist zurückgenommen. Gelernt bleibt gelernt - '
              . 'beim nächsten Freigeben ist der Stand wieder da.'
            : sprintf('Freigabe auf %d Vokabeln zurückgenommen.', $bis));
        teacher_redirect($zurueck);
    }

    // Ab hier ist etwas dazugekommen, und dafuer braucht es Saetze.
    $offen = vocab_without_sentences($unitId);
    if ($offen === 0) {
        teacher_flash(sprintf('%d Vokabeln freigegeben. Sätze waren schon da.', $bis));
        teacher_redirect($zurueck);
    }

    $blocked = budget_block_reason((int) $user['id']);
    if ($blocked !== null) {
        teacher_flash('Freigegeben, aber ohne Sätze: ' . $blocked, 'bad');
        teacher_redirect($zurueck);
    }

    sentence_status_set($unitId, SENTENCE_RUNNING);
    teacher_flash(sprintf(
        '%d Vokabeln freigegeben. Die Lückensätze für %d neue entstehen gerade.',
        $bis, $offen,
    ));

    teacher_redirect_and_continue($zurueck);

    set_time_limit(900);
    generate_sentences_tracked($unitId);
    exit;
}

$vokabeln = qa(
    'SELECT v.id, v.position, v.term_foreign, v.term_native, v.word_type,
            (SELECT COUNT(*) FROM sentences s WHERE s.vocab_id = v.id) AS saetze
       FROM vocab v
      WHERE v.unit_id = ?
      ORDER BY v.position, v.id',
    [$unitId],
);

$frei   = (int) $unit['released_position'];
$alles  = $frei >= $gesamt;
$zustand = sentence_status($unitId, (int) $user['id']);

teacher_head($unit['title'], 'index.php', $user);
teacher_flash_render();
?>

<p class="muted">
    <?= h(trim($unit['flag_emoji'] . ' ' . $unit['language_name'])) ?>
    <?php if (($unit['class_name'] ?? null) !== null): ?>
        &middot; Klasse <?= h($unit['class_name']) ?>
    <?php endif; ?>
    &middot; <a href="<?= h(teacher_url('course.php') . '?id=' . (int) $unit['course_id']) ?>">zurück zum Kurs</a>
</p>

<?php if ($zustand['status'] === SENTENCE_RUNNING): ?>
    <div class="notice">
        Die Lückensätze entstehen gerade. Das dauert je zwanzig Vokabeln etwa
        eine halbe Minute; die Seite lädt sich von selbst neu.
    </div>
    <meta http-equiv="refresh" content="10">
<?php elseif ($zustand['status'] === SENTENCE_FAILED): ?>
    <div class="notice bad">
        Bei den Lückensätzen ist etwas schiefgegangen:
        <?= h((string) ($zustand['error'] ?? 'unbekannter Fehler')) ?>
        Ein erneutes Freigeben versucht es noch einmal.
    </div>
<?php endif; ?>

<h2>Freigabe</h2>

<?php if ($gesamt === 0): ?>
    <p class="muted">Diese Lerneinheit hat noch keine Vokabeln.</p>
<?php else: ?>

<p>
    <?php if ($frei === 0): ?>
        <strong>Noch nichts freigegeben.</strong>
        Die Klasse sieht diese Lerneinheit als leer.
    <?php elseif ($alles): ?>
        <strong>Alle <?= $gesamt ?> Vokabeln sind freigegeben.</strong>
    <?php else: ?>
        <strong><?= $frei ?> von <?= $gesamt ?> Vokabeln freigegeben.</strong>
        Die Klasse übt bis „<?= h($vokabeln[$frei - 1]['term_foreign'] ?? '') ?>".
    <?php endif; ?>
</p>

<form method="post" class="compact">
    <?= teacher_csrf_field() ?>
    <input type="hidden" name="unit_id" value="<?= $unitId ?>">
    <?php if (!$alles): ?>
        <button class="btn small" name="release" value="<?= $gesamt ?>"
                data-confirm="Alle <?= $gesamt ?> Vokabeln freigeben? Für die noch offenen entstehen dann Lückensätze, und die kosten.">
            Alles freigeben
        </button>
    <?php endif; ?>
    <?php if ($frei > 0): ?>
        <button class="linkbtn" name="release" value="0"
                data-confirm="Die ganze Lerneinheit wieder zumachen? Die Klasse sieht sie dann als leer. Gelernt bleibt gelernt.">
            Freigabe zurücknehmen
        </button>
    <?php endif; ?>
</form>

<table class="data">
    <tr>
        <th class="num">#</th><th>Fremdsprache</th><th>Deutsch</th>
        <th class="num">Sätze</th><th></th>
    </tr>
    <?php foreach ($vokabeln as $i => $v): ?>
        <?php $istFrei = $i < $frei; ?>
        <tr<?= $istFrei ? '' : ' class="dim"' ?>>
            <td class="num"><?= $i + 1 ?></td>
            <td><strong><?= h($v['term_foreign']) ?></strong></td>
            <td><?= h($v['term_native']) ?></td>
            <td class="num">
                <?= (int) $v['saetze'] > 0
                    ? (int) $v['saetze']
                    : '<span class="muted">&ndash;</span>' ?>
            </td>
            <td>
                <?php if (!$istFrei): ?>
                <form method="post" class="compact">
                    <?= teacher_csrf_field() ?>
                    <input type="hidden" name="unit_id" value="<?= $unitId ?>">
                    <button class="linkbtn" name="release" value="<?= $i + 1 ?>"
                            data-confirm="Bis einschliesslich „<?= h($v['term_foreign']) ?>" freigeben? Für die neuen Vokabeln entstehen Lückensätze, und die kosten.">
                        bis hier freigeben
                    </button>
                </form>
                <?php elseif ($i + 1 === $frei): ?>
                    <span class="tiny muted">Freigabe endet hier</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
</table>

<p class="tiny muted">
    Lückensätze entstehen nur für Freigegebenes - das ist der Grund für die
    Stufen. Eine ganze Unit auf einmal aufzumachen kostet nichts anderes als
    sie in Portionen aufzumachen, nur alles sofort. Zurücknehmen kostet gar
    nichts: Bereits erzeugte Sätze bleiben erhalten, und beim nächsten
    Freigeben ist der Lernstand der Kinder wieder da.
</p>

<?php endif; ?>

<script>
document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-confirm]');
    if (b && !confirm(b.dataset.confirm)) e.preventDefault();
});
</script>

<?php teacher_foot(); ?>
