<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

/*
 * Schüler:innen ohne Klassenzuordnung.
 *
 * Hierher kommt ein Kind, wenn eine Lehrkraft es aus seiner Klasse nimmt
 * und das Konto behält (class.php, "Entfernen"). Von hier geht es in eine
 * neue Klasse - mit der Frage, in welche Kurse dieser Klasse es gleich
 * mitkommt. Alle vorzuschlagen und nichts zu fragen hiesse, dass ein Kind,
 * das nur für Französisch in die 6A wechselt, auch in deren Latein steht.
 */

$user     = teacher_require();
$schoolId = (int) ($user['school_id'] ?? 0);

if ($schoolId === 0) {
    teacher_flash('Dieses Konto gehört zu keiner Schule.', 'bad');
    teacher_redirect('index.php');
}

/** Ein Kind ohne Klasse aus dieser Schule - oder null. */
function kind_ohne_klasse(int $kindId, int $schoolId): ?array
{
    foreach (students_without_class($schoolId) as $k) {
        if ((int) $k['id'] === $kindId) {
            return $k;
        }
    }
    return null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['assign'])) {
    teacher_csrf_check();

    $kind   = kind_ohne_klasse((int) ($_POST['kind'] ?? 0), $schoolId);
    $klasse = class_in_school((int) ($_POST['klasse'] ?? 0), $schoolId);

    if ($kind === null || $klasse === null) {
        teacher_flash('Das Kind oder die Klasse gibt es nicht (mehr).', 'bad');
        teacher_redirect('ohneklasse.php');
    }

    $n = student_assign_class((int) $kind['id'], (int) $klasse['id'],
                              (array) ($_POST['kurse'] ?? []));

    teacher_flash(sprintf(
        '%s ist jetzt in der Klasse %s%s.',
        $kind['display_name'],
        $klasse['name'],
        match ($n) { 0 => '', 1 => ' und in einem ihrer Kurse', default => " und in $n ihrer Kurse" },
    ));
    teacher_redirect('ohneklasse.php');
}

$kinder  = students_without_class($schoolId);
$klassen = array_values(array_filter(classes_for_school($schoolId),
    static fn (array $c): bool => (bool) $c['active']));

/*
 * Die Rückfrage: Kind und Klasse sind gewählt, fehlen die Kurse. Sie kommt
 * über die Adresse (?kind=&klasse=), damit die Wahl auch ohne Skript geht -
 * das Skript schickt das Formular nur beim Wählen gleich ab.
 */
$fragKind   = isset($_GET['kind']) ? kind_ohne_klasse((int) $_GET['kind'], $schoolId) : null;
$fragKlasse = isset($_GET['klasse']) ? class_in_school((int) $_GET['klasse'], $schoolId) : null;
$fragKurse  = $fragKind !== null && $fragKlasse !== null
    ? courses_for_class((int) $fragKlasse['id']) : [];

teacher_head('Schüler:innen ohne Klassenzuordnung', $user);
teacher_flash_render();
?>

<?php if ($kinder === []): ?>
    <div class="empty">
        <strong>Alle Kinder sind in einer Klasse.</strong>
        <p class="tiny muted">
            Hier erscheint ein Kind, wenn du es in seiner Klasse entfernst und
            das Konto dabei behältst.
        </p>
    </div>
<?php else: ?>

<table class="data courses" id="ohneklasse">
    <tr>
        <th>Name</th>
        <th>Benutzername</th>
        <th class="num">Kurse</th>
        <th>Klasse zuordnen</th>
    </tr>
    <?php foreach ($kinder as $k): ?>
        <tr<?= $k['active'] ? '' : ' class="dim"' ?>>
            <td data-label="Name">
                <span class="coursetitle">
                    <span class="cflag">&#128100;</span>
                    <strong><?= h($k['display_name']) ?></strong>
                </span>
            </td>
            <td data-label="Benutzername"><code class="token"><?= h($k['username']) ?></code></td>
            <td class="num" data-label="Kurse">
                <?= (int) $k['courses'] === 0 ? '<span class="muted">&ndash;</span>' : (int) $k['courses'] ?>
            </td>
            <td data-label="Klasse zuordnen">
                <?php if ($klassen === []): ?>
                    <span class="tiny muted">Es gibt noch keine Klasse.</span>
                <?php else: ?>
                <form method="get" class="compact klassenwahl" data-sofortsenden>
                    <input type="hidden" name="kind" value="<?= (int) $k['id'] ?>">
                    <select name="klasse" required aria-label="Klasse für <?= h($k['display_name']) ?>">
                        <option value="">Klasse wählen …</option>
                        <?php foreach ($klassen as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"><?= h($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="iconaction primary">Zuordnen</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
</table>

<p class="tiny muted">
    Diese Kinder haben ein Konto, sind aber in keiner Klasse. Ihr Lernstand
    ist erhalten. Wählst du eine Klasse, fragt die Seite, in welche Kurse
    dieser Klasse das Kind mitkommt.
</p>

<?php endif; ?>

<?php if ($fragKind !== null && $fragKlasse !== null): ?>
<dialog id="kurswahl" class="rueckfrage" data-sofort="kind klasse">
    <p class="rueckfrage-text">
        <?= h($fragKind['display_name']) ?> in die Klasse <?= h($fragKlasse['name']) ?>
    </p>
    <form method="post" action="<?= h(teacher_url('ohneklasse.php')) ?>">
        <?= teacher_csrf_field() ?>
        <input type="hidden" name="kind" value="<?= (int) $fragKind['id'] ?>">
        <input type="hidden" name="klasse" value="<?= (int) $fragKlasse['id'] ?>">
        <?php if ($fragKurse === []): ?>
            <p class="tiny muted">Die Klasse hat noch keinen Kurs.</p>
        <?php else: ?>
            <p class="tiny muted">In welche ihrer Kurse soll das Kind auch?</p>
            <div class="haken kurshaken">
                <?php foreach ($fragKurse as $c): ?>
                    <label>
                        <input type="checkbox" name="kurse[]" value="<?= (int) $c['id'] ?>"
                               <?= $c['active'] ? 'checked' : '' ?>>
                        <?= flag_html((string) $c['flag_emoji'] ?: FLAG_FALLBACK, 'cflag') ?>
                        <span><?= h($c['name']) ?><?= $c['active'] ? '' : ' <span class="tiny muted">stillgelegt</span>' ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="rueckfrage-knoepfe">
            <button class="btn secondary" type="submit" formmethod="dialog" formnovalidate>Abbrechen</button>
            <button class="btn" name="assign" value="1">Zuordnen</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<?php teacher_foot(); ?>
