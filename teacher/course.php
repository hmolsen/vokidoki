<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/qr.php';
require_once __DIR__ . '/../lib/handoff.php';

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

/*
 * Aufnehmen ueber den eingetippten Namen.
 *
 * Die Vorschlagsliste im Feld nennt nur, wer noch nicht im Kurs ist - hier
 * wird trotzdem noch einmal gegen genau diese Liste geprueft. Was im
 * Formular steht, hat der Aufrufer geschrieben, nicht die Seite.
 *
 * Angenommen werden Anzeigename und Benutzername. Zwei Kinder koennen
 * "Lilli M." heissen; dann sagt die Meldung das und nennt den Weg, der
 * eindeutig ist.
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['add_member_by_name'])) {
    teacher_csrf_check();

    $eingabe = trim((string) ($_POST['member_name'] ?? ''));
    if ($eingabe === '') {
        teacher_flash('Da steht kein Name.', 'bad');
        teacher_redirect($zurueck);
    }

    $klein   = mb_strtolower($eingabe);
    $treffer = array_values(array_filter(
        course_candidates($courseId, $schoolId),
        static fn (array $k): bool => mb_strtolower((string) $k['display_name']) === $klein
                                   || mb_strtolower((string) $k['username']) === $klein,
    ));

    if ($treffer === []) {
        teacher_flash(sprintf(
            '"%s" steht nicht zur Auswahl - entweder schon im Kurs oder nicht an dieser Schule.',
            $eingabe,
        ), 'bad');
        teacher_redirect($zurueck);
    }

    if (count($treffer) > 1) {
        teacher_flash(sprintf(
            '"%s" gibt es %dmal. Nimm den Benutzernamen, der ist eindeutig: %s.',
            $eingabe,
            count($treffer),
            implode(', ', array_column($treffer, 'username')),
        ), 'bad');
        teacher_redirect($zurueck);
    }

    $konto = $treffer[0];
    course_add_member($courseId, (int) $konto['id'],
        $konto['role'] === ROLE_TEACHER ? COURSE_ROLE_TEACHER : COURSE_ROLE_STUDENT);
    teacher_flash(sprintf('%s ist jetzt im Kurs.', $konto['display_name']));
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
        teacher_flash('Wer das ist, steht gar nicht im Kurs.', 'bad');
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
    /*
     * Zurueck auf die Startseite, nicht in die Klasse: Der Kurs ist die
     * Hauptansicht, und wer einen geloescht hat, will die uebrigen sehen.
     */
    teacher_redirect('index.php');
}

$mitglieder = course_members_list($courseId);
$einheiten  = course_units_list($courseId);
$offene     = course_candidates($courseId, $schoolId);
$verlust    = course_delete_preview($courseId);

/*
 * Der Weg zum Einlesen, an einer Stelle gebildet.
 *
 * Die App faehrt ueber die Raute: /#/lang/<id>/import. Als Adresse fuer
 * einen QR-Code muss sie vollstaendig sein - public_url() statt url(),
 * sonst steht im Code ein Pfad und kein Link.
 */
$importPfad = '/lang/' . (int) $kurs['language_id'] . '/import';
$importUrl  = url('/') . '#' . $importPfad;

/*
 * Der Weg in die Schueleransicht.
 *
 * Eine Lehrkraft sieht in der App genau das, was ihre Klasse sieht - das
 * ist seit der Freigabe so gewollt. Dann muss sie auch hinkommen, und zwar
 * von der Stelle aus, an der sie gerade etwas eingestellt hat.
 */
$schuelerUrl = url('/') . '#/lang/' . (int) $kurs['language_id'];

/*
 * Der Pfad ist kurz geworden: Schule, Kurs. Mehr nicht.
 *
 * Vorher stand die Klasse dazwischen - Schule > Klasse 5B > Englisch - 5B.
 * Das bildete die Datenstruktur ab, nicht den Weg: Eine Klasse oeffnet man
 * zweimal im Jahr, einen Kurs jede Woche, und der Umweg ueber die Klasse
 * war beim Wechseln zwischen zwei eigenen Kursen genau das - ein Umweg.
 * Die Klasse ist deshalb kein Halt mehr, sondern ein Ziel wie jedes andere:
 * Sie steht dort, wo es um ihre Kinder geht.
 */
$pfad = [teacher_course_crumb($user, $kurs, true)];

teacher_head($kurs['name'], $user, $pfad, sprintf(
    '<a class="btn small secondary" href="%s" target="_blank" rel="noopener" '
    . 'title="Die Ansicht, die deine Klasse sieht">'
    . '<span aria-hidden="true">&#128065;</span> So sieht es die Klasse</a>',
    h($schuelerUrl),
));
teacher_flash_render();
?>

<p class="muted"><?= h($kurs['language_name']) ?></p>

<h2>Lerneinheiten</h2>

<?php if ($einheiten === []): ?>
<?php
/*
 * Der leere Kurs erklaert sich selbst - ohne QR-Code.
 *
 * Er stand hier einmal, fuehrte aber nur dorthin, wo man sich erst noch
 * anmelden muss. Der Weg, der das ueberspringt, ist "Am Smartphone
 * einlesen"; ein zweiter Code daneben war einer zu viel.
 */
?>
<?= teacher_leer(
    'Noch keine Lerneinheit. Eingelesen wird <strong>am Handy</strong> &ndash; '
    . 'dort ist die Kamera. Buchseite fotografieren, das Modell erkennt die '
    . 'Vokabeln, und die Lerneinheit landet in diesem Kurs.',
    sprintf(
        '<a class="btn small" href="%s" target="_blank" rel="noopener">Vokabeln einlesen</a>'
        . '<button class="btn small secondary" type="button" data-handoff>'
        . '<span aria-hidden="true">&#128241;</span> Am Smartphone einlesen</button>',
        h($importUrl),
    ),
) ?>
<?php else: ?>
<table class="data courses rowlink" id="einheiten">
    <tr>
        <th>Titel</th>
        <th class="num">Vokabeln</th>
        <th>Freigegeben</th>
        <th>Angelegt</th>
        <th class="actions"></th>
    </tr>
    <?php foreach ($einheiten as $e): ?>
        <?php $ziel = teacher_url('unit.php') . '?id=' . (int) $e['id']; ?>
        <tr data-href="<?= h($ziel) ?>">
            <td data-label="Titel">
                <a class="rowmain" href="<?= h($ziel) ?>"><?= h($e['title']) ?></a>
            </td>
            <td class="num" data-label="Vokabeln"><?= (int) $e['vocab_count'] ?></td>
            <td data-label="Freigegeben">
                <?php
                $frei   = (int) $e['released_count'];
                $gesamt = (int) $e['vocab_count'];
                if ($gesamt === 0) {
                    echo '<span class="muted">&ndash;</span>';
                } elseif ($frei >= $gesamt) {
                    echo '<span class="pill good">alle</span>';
                } elseif ($frei === 0) {
                    echo '<span class="muted">noch keine</span>';
                } else {
                    printf('<span class="pill">%d von %d</span>', $frei, $gesamt);
                }
                ?>
            </td>
            <td class="tiny muted" data-label="Angelegt"><?= h(substr((string) $e['created_at'], 0, 10)) ?></td>
            <td class="actions chev" aria-hidden="true">&#8250;</td>
        </tr>
    <?php endforeach; ?>

    <?php
    /*
     * Die Anlegezeile, wie in jeder anderen Tabelle - nur dass hier nichts
     * einzutippen ist. Eine Lerneinheit entsteht aus Fotos, und die macht
     * man mit dem Geraet, das eine Kamera hat. Also zwei Wege: hier weiter
     * (wenn das hier schon das Telefon ist) oder hinueber aufs Telefon.
     */
    ?>
    <tr class="newrow">
        <td colspan="5" data-label="Neue Lerneinheit">
            <span class="coursetitle addbuttons">
                <span class="cflag plus">+</span>
                <a class="btn small" href="<?= h($importUrl) ?>"
                   target="_blank" rel="noopener">Vokabeln einlesen</a>
                <button class="btn small secondary" type="button" data-handoff>
                    <span aria-hidden="true">&#128241;</span> Am Smartphone einlesen
                </button>
            </span>
        </td>
    </tr>
</table>

<p class="tiny muted">
    Eine Zeile anklicken öffnet die Freigabe. Freigegeben wird portionsweise:
    Die Klasse sieht nur, was aufgemacht ist. Eingelesen wird am Handy &ndash;
    dafür braucht es die Kamera.
</p>
<?php endif; ?>

<?php
/*
 * Das Fenster mit dem Code.
 *
 * Es steht leer im HTML und wird erst gefuellt, wenn jemand darauf drueckt -
 * die Marke darin ist eine Anmeldung, und die soll nicht auf Vorrat
 * entstehen und zehn Minuten lang auf einem unbeaufsichtigten Bildschirm
 * liegen. Ohne JavaScript bleibt der Knopf wirkungslos; der Weg ueber
 * "Vokabeln einlesen" und eine Anmeldung am Telefon steht daneben.
 */
?>
<dialog id="handoff" class="qrdialog"
        data-url="<?= h(teacher_url('handoff.php')) ?>"
        data-course="<?= $courseId ?>"
        data-csrf="<?= h(teacher_csrf_token()) ?>">
    <h3>Am Smartphone einlesen</h3>
    <div class="qrslot" id="handoffSlot"></div>
    <p class="tiny muted" id="handoffHint">
        Code mit der Kamera des Telefons scannen. Du bist dann angemeldet und
        stehst direkt im Einlesen dieses Kurses.
    </p>
    <p class="tiny muted">
        <strong>Der Code ist ein Schlüssel.</strong> Er gilt
        <?= HANDOFF_TTL ?> Minuten und nur ein einziges Mal &ndash; wer ihn
        einlöst, ist als du angemeldet. Nicht abfotografieren lassen.
    </p>
    <form method="dialog"><button class="btn small secondary">Schließen</button></form>
</dialog>

<h2>Wer im Kurs ist</h2>

<table class="data" id="mitglieder">
    <tr>
        <th>Name</th>
        <th>Benutzername</th>
        <th>Rolle</th>
        <th class="actions"></th>
    </tr>
    <?php foreach ($mitglieder as $m): ?>
        <tr<?= $m['active'] ? '' : ' class="dim"' ?>>
            <td data-label="Name"><?= h($m['display_name']) ?></td>
            <td data-label="Benutzername"><code class="token"><?= h($m['username']) ?></code></td>
            <td data-label="Rolle"><?= $m['member_role'] === 'teacher' ? 'Lehrkraft' : 'Kind' ?><?php
                if (!$m['active']) { echo ' <span class="tiny muted">stillgelegt</span>'; } ?></td>
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

    <?php
    /*
     * Aufnehmen wie ueberall sonst: eine Zeile mit einem Feld.
     *
     * Vorher stand darunter ein Auswahlfeld mit allen Konten der Schule und
     * daneben "Einzeln aufnehmen" - bei dreihundert Kindern eine Liste, durch
     * die man scrollt. Jetzt tippt man den Namen, und die Vorschlagsliste
     * engt ein. Sie enthaelt nur, wer noch nicht im Kurs ist; wer drin ist,
     * steht ja schon oben.
     *
     * <datalist> und nicht selbstgebaut: Der Browser kann das, auf dem
     * Telefon auch, und ohne JavaScript bleibt es ein Textfeld, in das man
     * den Namen tippt.
     */
    ?>
    <?php
    /*
     * Die Zeile steht auch dann da, wenn es niemanden mehr aufzunehmen gibt -
     * dann sagt sie das. Eine Zeile, die je nach Datenlage verschwindet,
     * laesst einen suchen, wo sie hin ist.
     */
    ?>
    <tr class="newrow">
        <td colspan="3" data-label="Aufnehmen">
            <?php if ($offene === []): ?>
                <span class="tiny muted">
                    Alle Konten dieser Schule sind schon im Kurs. Neue Kinder
                    legst du in der Klasse an.
                </span>
            <?php else: ?>
                <span class="coursetitle">
                    <span class="cflag plus">+</span>
                    <input type="text" name="member_name" form="newmember"
                           list="kandidaten" autocomplete="off" maxlength="80" required
                           placeholder="Name eintippen" aria-label="Wen aufnehmen?">
                </span>
                <datalist id="kandidaten">
                    <?php foreach ($offene as $o): ?>
                        <option value="<?= h($o['display_name']) ?>"
                            <?= $o['role'] === ROLE_TEACHER ? 'label="Lehrkraft"' : '' ?>></option>
                    <?php endforeach; ?>
                </datalist>
            <?php endif; ?>
        </td>
        <td class="actions">
            <?php if ($offene !== []): ?>
                <button class="iconaction primary" form="newmember"
                        name="add_member_by_name" value="1" title="In den Kurs aufnehmen">
                    <span aria-hidden="true">+</span> Aufnehmen
                </button>
            <?php endif; ?>
        </td>
    </tr>
</table>

<?php if ($offene !== []): ?>
<form method="post" id="newmember" hidden>
    <?= teacher_csrf_field() ?>
    <input type="hidden" name="course_id" value="<?= $courseId ?>">
</form>
<?php endif; ?>

<?php if (($kurs['class_name'] ?? null) !== null): ?>
<?php
/*
 * Die beiden Wege, die mit der Klasse zu tun haben, stehen nebeneinander:
 * nachtragen, wer seit dem Anlegen dazugekommen ist - und in die Klasse
 * selbst, wo Kinder entstehen und Zettel gedruckt werden. Der Kurs traegt
 * sich dabei mit: Von dort fuehrt der Pfad wieder hierher zurueck.
 */
?>
<div class="buttonrow">
    <form method="post">
        <?= teacher_csrf_field() ?>
        <input type="hidden" name="course_id" value="<?= $courseId ?>">
        <button class="btn small secondary" name="sync_class" value="1">
            Klasse <?= h($kurs['class_name']) ?> nachtragen
        </button>
    </form>
    <a class="btn small secondary" href="<?= h(teacher_url('class.php')
        . '?id=' . (int) $kurs['class_id'] . '&kurs=' . $courseId) ?>">
        Klasse <?= h($kurs['class_name']) ?> verwalten
    </a>
</div>
<?php else: ?>
<div class="buttonrow">
    <a class="btn small secondary" href="<?= h(teacher_url('classes.php')) ?>">
        Klassen und Kinder
    </a>
</div>
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
        <button class="btn small danger" name="delete_course" value="1"
                data-confirm="Kurs &quot;<?= h($kurs['name']) ?>&quot; mit allen Unterlagen und Lernständen endgültig löschen?">
            Endgültig löschen
        </button>
    </form>
</details>

<?php teacher_foot(); ?>
