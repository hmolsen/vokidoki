<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/qr.php';

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

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['delete_course'])) {
    teacher_csrf_check();

    /*
     * Mit dem eigenen Passwort bestaetigen.
     *
     * Nicht, weil jemand Fremdes an der Tastatur sitzen koennte - die
     * Sitzung ist ohnehin angemeldet -, sondern als Zaesur: Zwischen dem
     * Lesen der Warnung und dem Loeschen soll eine Handlung liegen, die
     * man nicht aus Versehen ausfuehrt. Ein Haekchen setzt man im
     * Vorbeigehen, ein Passwort tippt man nicht.
     *
     * Ausdruecklich OHNE die Anmeldebremse: Ein Vertipper hier duerfte die
     * Lehrkraft nicht aus der ganzen Anwendung aussperren.
     */
    $passwort = (string) ($_POST['password'] ?? '');

    if (!password_verify($passwort, (string) $user['password_hash'])) {
        usleep(random_int(200_000, 500_000));
        teacher_flash('Das Passwort stimmt nicht. Der Kurs ist unveraendert.', 'bad');
        teacher_redirect($zurueck);
    }

    $verlust = course_delete_preview($courseId);
    $name    = (string) $kurs['name'];

    try {
        course_delete($courseId);
    } catch (Throwable $e) {
        error_log('[vokabeltrainer] Kurs loeschen: ' . $e->getMessage());
        teacher_flash('Der Kurs liess sich nicht loeschen. Es wurde nichts veraendert.', 'bad');
        teacher_redirect($zurueck);
    }

    teacher_flash(sprintf(
        'Kurs "%s" geloescht - mit %d Lerneinheiten, %d Vokabeln und den '
        . 'Lernstaenden von %d Kindern.',
        $name, $verlust['units'], $verlust['vocab'], $verlust['students'],
    ));
    teacher_redirect('index.php');
}

$mitglieder = course_members_list($courseId);
$einheiten  = course_units_list($courseId);
$offene     = course_candidates($courseId, $schoolId);
$verlust    = course_delete_preview($courseId);

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

<?php
/*
 * Eingelesen wird in der App, nicht hier - dafuer braucht es die Kamera.
 * Das stand bisher nur als Randnotiz unter der Tabelle und war damit
 * unsichtbar, solange es noch keine Lerneinheit gab. Genau dann braucht man
 * es aber: Der Kurs ist angelegt, und die Seite sagte nur, dass nichts da
 * ist - ohne zu verraten, wie etwas hinkommt.
 */
$appAdresse = public_url('/');
$appQr      = qr_svg($appAdresse, 3, 'Adresse der App');
?>

<?php if ($einheiten === []): ?>
<div class="card" style="display:flex;gap:18px;align-items:flex-start;flex-wrap:wrap">
    <?php if ($appQr !== null): ?>
        <div style="flex:none;width:120px"><?= $appQr ?></div>
    <?php endif; ?>
    <div style="flex:1 1 260px">
        <h3 style="margin:0 0 6px">Noch keine Lerneinheit</h3>
        <p style="margin:0 0 10px">
            Eingelesen wird <strong>in der App am Handy</strong> - dort ist die
            Kamera. Buchseite fotografieren, das Modell erkennt die Vokabeln,
            und die Lektion landet in diesem Kurs.
        </p>
        <p class="tiny muted" style="margin:0">
            Code scannen oder <a href="<?= h(url('/')) ?>" target="_blank" rel="noopener">die
            App hier öffnen</a> und mit demselben Konto anmelden. Die Lektion
            erscheint danach in dieser Liste, und du gibst sie portionsweise frei -
            Lückensätze entstehen nur für Freigegebenes.
        </p>
    </div>
</div>
<?php else: ?>
<table class="data">
    <tr>
        <th>Titel</th><th class="num">Vokabeln</th><th>Freigegeben</th>
        <th>Angelegt</th><th class="actions"></th>
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
            <td class="actions">
                <a class="iconaction" title="Vokabeln freigeben"
                   href="<?= h(teacher_url('unit.php') . '?id=' . (int) $e['id']) ?>">
                    <span aria-hidden="true">&#128275;</span> Freigeben
                </a>
            </td>
        </tr>
    <?php endforeach; ?>
</table>
<p class="tiny muted">
    Freigegeben wird portionsweise, und das hat einen Grund: Zu jeder
    freigegebenen Vokabel entstehen Lückensätze, und die kosten. Eine ganze
    Unit einlesen und nur das aufmachen, was dran ist, spart den Rest -
    solange er nicht dran ist. Eine weitere Lektion liest du
    <a href="<?= h(url('/')) ?>" target="_blank" rel="noopener">in der App</a>
    am Handy ein; dafür braucht es die Kamera.
</p>
<?php endif; ?>

<h2>Wer im Kurs ist</h2>

<?php if ($mitglieder === []): ?>
    <p class="muted">Noch niemand.</p>
<?php else: ?>
<table class="data">
    <tr><th>Name</th><th>Benutzername</th><th>Rolle</th><th class="actions"></th></tr>
    <?php foreach ($mitglieder as $m): ?>
        <tr<?= $m['active'] ? '' : ' class="dim"' ?>>
            <td><?= h($m['display_name']) ?></td>
            <td><code class="token"><?= h($m['username']) ?></code></td>
            <td><?= $m['member_role'] === 'teacher' ? 'Lehrkraft' : 'SchülerIn' ?></td>
            <td class="actions">
                <form method="post" class="compact">
                    <?= teacher_csrf_field() ?>
                    <input type="hidden" name="course_id" value="<?= $courseId ?>">
                    <button class="iconaction danger" name="remove_member"
                            value="<?= (int) $m['id'] ?>" title="Aus dem Kurs nehmen"
                            data-confirm="<?= h($m['display_name']) ?> aus dem Kurs nehmen? Der Lernstand bleibt erhalten.">
                        <span aria-hidden="true">&#10005;</span> Entfernen
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

<h2>Kurs löschen</h2>

<?php
/*
 * Zugeklappt, und das ist der Punkt: Loeschen ist nichts, worueber man
 * stolpert. Wer es sucht, findet es; wer die Seite ueberfliegt, nicht.
 */
?>
<details class="card">
    <summary style="cursor:pointer;font-weight:600">
        Diesen Kurs endgültig löschen
    </summary>

    <div class="notice bad" style="margin-top:14px">
        <strong>Das lässt sich nicht rückgängig machen.</strong>
        Gelöscht werden nicht nur der Kurs, sondern auch seine Unterlagen und
        alles, was die Kinder darin gelernt haben:
    </div>

    <table class="data">
        <tr><th>Was</th><th class="num">Anzahl</th></tr>
        <tr>
            <td>Kinder verlieren den Zugang</td>
            <td class="num"><strong><?= $verlust['students'] ?></strong></td>
        </tr>
        <tr>
            <td>Lerneinheiten</td>
            <td class="num"><?= $verlust['units'] ?></td>
        </tr>
        <tr>
            <td>Vokabeln</td>
            <td class="num"><?= $verlust['vocab'] ?></td>
        </tr>
        <tr>
            <td>Lückensätze <span class="tiny muted">(erzeugt und bezahlt)</span></td>
            <td class="num"><?= $verlust['sentences'] ?></td>
        </tr>
        <tr>
            <td><strong>Gespeicherte Lernstände</strong>
                <span class="tiny muted">Serien, Fehler, „gekonnt"</span></td>
            <td class="num"><strong><?= $verlust['progress'] ?></strong></td>
        </tr>
    </table>

    <p class="tiny muted">
        Soll die Klasse nur aufhören, damit zu arbeiten, ist das Zurücknehmen
        der Freigabe das mildere Mittel: Die Lerneinheit verschwindet aus der
        App, Unterlagen und Lernstände bleiben. Und wer nur einzelne Kinder
        herausnehmen will, tut das oben unter „Wer im Kurs ist".
    </p>

    <form method="post" style="max-width:360px">
        <?= teacher_csrf_field() ?>
        <input type="hidden" name="course_id" value="<?= $courseId ?>">
        <label for="pw_delete">Zum Bestätigen dein eigenes Passwort</label>
        <input type="password" id="pw_delete" name="password"
               autocomplete="current-password" required>
        <button class="btn danger small" name="delete_course" value="1"
                data-confirm="Kurs &quot;<?= h($kurs['name']) ?>&quot; mit allen Unterlagen und Lernständen endgültig löschen?">
            Endgültig löschen
        </button>
    </form>
</details>

<script>
document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-confirm]');
    if (b && !confirm(b.dataset.confirm)) e.preventDefault();
});
</script>

<?php teacher_foot(); ?>
