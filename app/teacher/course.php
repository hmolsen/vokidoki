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

/*
 * Eine leere Lerneinheit anlegen.
 *
 * Bis hierher entstand eine Lerneinheit nur beim Einlesen - aus Fotos, in
 * der App. Wer eine Handvoll Vokabeln von Hand eintragen wollte, brauchte
 * trotzdem erst ein Foto. Jetzt entsteht sie leer, und auf ihrer Seite
 * stehen alle drei Wege nebeneinander.
 *
 * Der Titel ist vorlaeufig: "Unbenannte Lerneinheit" steht dort, bis
 * jemand oben auf den Stift drueckt. Ein Pflichtfeld an dieser Stelle
 * waere eine Frage vor der Arbeit - und beim Einlesen kommt der Titel
 * ohnehin von der Buchseite.
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['add_unit'])) {
    teacher_csrf_check();

    // Ans Ende - dorthin, wo man sie sucht. Die Reihenfolge in der Tabelle
    // ist dieselbe, die die Klasse in ihrer App sieht.
    q('INSERT INTO units (language_id, course_id, title, released_position, position)
       VALUES (?, ?, ?, 0, ?)',
      [(int) $kurs['language_id'], $courseId, 'Unbenannte Lerneinheit',
       unit_next_position($courseId)]);

    teacher_flash('Leere Lerneinheit angelegt. Gib ihr einen Namen und füll sie.');
    teacher_redirect('unit.php?id=' . (int) db()->lastInsertId());
}

/*
 * Die Lerneinheiten umsortieren.
 *
 * Die Reihenfolge in der Tabelle ist die, in der die Klasse sie in ihrer App
 * sieht. Bis hierher war es die Entstehungsreihenfolge, neueste zuerst - wer
 * Unit 7 vor Unit 3 fotografierte, weil die Seite gerade aufgeschlagen war,
 * bekam sie auch so vorgesetzt.
 *
 * Mit Skript kommt die Liste per fetch und die Seite bleibt stehen. Ohne
 * Skript tun es die beiden Pfeile je Zeile: Sie schicken dieselbe Liste als
 * gewoehnliches Formular, nur eben mit vertauschten Nachbarn.
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['reorder_units'])) {
    teacher_csrf_check();

    $roh = (string) ($_POST['reihenfolge'] ?? '');
    $ids = array_filter(array_map('intval', explode(',', $roh)));

    if ($ids === []) {
        if (unit_will_json()) {
            unit_json(['ok' => false, 'error' => 'Keine Reihenfolge angekommen.'], 422);
        }
        teacher_flash('Keine Reihenfolge angekommen.', 'bad');
        teacher_redirect($zurueck);
    }

    $wie_viele = course_units_reorder($courseId, $ids);

    if (unit_will_json()) {
        unit_json(['ok' => true, 'sortiert' => $wie_viele]);
    }

    teacher_flash('Reihenfolge gespeichert.');
    teacher_redirect($zurueck);
}

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

    $nein = course_remove_member($courseId, $wer);
    if ($nein !== null) {
        teacher_flash($nein, 'bad');
        teacher_redirect($zurueck);
    }
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
// Wie viele Lehrkraefte - die letzte laesst sich nicht entfernen.
$lehrkraefteImKurs = count(array_filter($mitglieder, static fn (array $m): bool => $m['member_role'] === 'teacher'));
$einheiten  = course_units_list($courseId);
$offene     = course_candidates($courseId, $schoolId);
$verlust    = course_delete_preview($courseId);

/*
 * Die Fahne vor den Kursnamen, und die Zeile mit der Sprache darunter
 * faellt weg: "Englisch - 6B" und darunter noch einmal "Englisch" ist
 * dieselbe Auskunft zweimal.
 */
$kursTitel = flag_html((string) $kurs['flag_emoji'] ?: FLAG_FALLBACK, 'kopfflagge')
           . h((string) $kurs['name']);

/*
 * Neben der Ueberschrift steht kein Knopf mehr.
 *
 * "So sieht es die Klasse" stand hier und auf der Lerneinheit - auf zwei
 * von sieben Seiten, und nur in eine Richtung. Der Wechsel zwischen den
 * beiden Ansichten gehoert nicht neben eine Ueberschrift, sondern dorthin,
 * wo man einstellt, wie man die Anwendung sieht: ins Zahnrad, ueber die
 * Farbwahl. Dort steht er jetzt, auf jeder Seite und in beide Richtungen.
 */
teacher_head($kurs['name'], $user, '', $kursTitel, $courseId);
teacher_flash_render();
?>

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
    'Noch keine Lerneinheit. Leg eine an &ndash; auf ihrer Seite stehen die '
    . 'drei Wege, sie zu f&uuml;llen: von Hand, aus Dateien, oder mit dem '
    . 'Telefon fotografiert.',
    '<button class="iconaction primary" form="neueEinheit" name="add_unit" value="1">'
    . '<span aria-hidden="true">+</span> Lerneinheit anlegen</button>',
) ?>
<?php else: ?>
<table class="data courses rowlink kompakt" id="einheiten">
    <?php
    /*
     * Zwei Spalten weniger.
     *
     * "Vokabeln" stand neben "Freigegeben", und dort steht die Gesamtzahl
     * ohnehin - zweimal dieselbe Zahl. Und das Anlegedatum beantwortete
     * keine Frage, die sich beim Unterrichten stellt.
     */
    ?>
    <tr>
        <th class="griffspalte"><span class="nurvorlesen">Reihenfolge</span></th>
        <th>Titel</th>
        <th>Freigegeben</th>
        <th class="actions"></th>
    </tr>
    <?php foreach ($einheiten as $i => $e): ?>
        <?php $ziel = teacher_url('unit.php') . '?id=' . (int) $e['id']; ?>
        <tr data-href="<?= h($ziel) ?>" data-unit="<?= (int) $e['id'] ?>" draggable="true">
            <?php
            /*
             * Der Anfasser. Ein eigenes Feld ganz vorn, damit klar ist,
             * woran man zieht - und damit ein Druck darauf nicht die Zeile
             * oeffnet.
             *
             * Daneben zwei Pfeile fuer alle, die nicht ziehen koennen oder
             * wollen: ohne Skript, mit der Tastatur, auf einem Telefon. Sie
             * schicken dieselbe Liste als gewoehnliches Formular.
             */
            $nachOben  = $einheiten;
            $nachUnten = $einheiten;
            if ($i > 0) {
                [$nachOben[$i - 1], $nachOben[$i]] = [$nachOben[$i], $nachOben[$i - 1]];
            }
            if ($i < count($einheiten) - 1) {
                [$nachUnten[$i + 1], $nachUnten[$i]] = [$nachUnten[$i], $nachUnten[$i + 1]];
            }
            $alsListe = static fn (array $liste): string => implode(',', array_map(
                static fn (array $u): int => (int) $u['id'], $liste));
            ?>
            <td class="griff" data-label="Reihenfolge">
                <span class="anfasser" aria-hidden="true" title="Zum Sortieren ziehen">&#10303;</span>
                <button class="iconaction quiet js-hide" form="sortierform"
                        name="reihenfolge" value="<?= h($alsListe($nachOben)) ?>"
                        title="Nach oben" <?= $i === 0 ? 'disabled' : '' ?>>
                    <span aria-hidden="true">&#9650;</span>
                    <span class="nurvorlesen">Nach oben</span>
                </button>
                <button class="iconaction quiet js-hide" form="sortierform"
                        name="reihenfolge" value="<?= h($alsListe($nachUnten)) ?>"
                        title="Nach unten" <?= $i === count($einheiten) - 1 ? 'disabled' : '' ?>>
                    <span aria-hidden="true">&#9660;</span>
                    <span class="nurvorlesen">Nach unten</span>
                </button>
            </td>
            <td data-label="Titel">
                <a class="rowmain" href="<?= h($ziel) ?>"><?= h($e['title']) ?></a>
            </td>
            <?php
            /*
             * Immer "n von m", und die Farbe sagt, wo man steht: rot heisst
             * nichts aufgemacht, gelb mittendrin, gruen fertig. Vorher
             * stand da mal "alle", mal "noch keine", mal eine Zahl - drei
             * Formen fuer dieselbe Auskunft, und keine davon liess sich mit
             * der Zeile darueber vergleichen.
             */
            $frei   = (int) $e['released_count'];
            $gesamt = (int) $e['vocab_count'];
            $ton    = $frei === 0 ? 'bad' : ($frei >= $gesamt ? 'good' : 'halb');
            ?>
            <td data-label="Freigegeben">
                <?php if ($gesamt === 0): ?>
                    <span class="pill ohne">noch keine Vokabeln</span>
                <?php else: ?>
                    <span class="pill <?= $ton ?>"><?= $frei ?> von <?= $gesamt ?></span>
                <?php endif; ?>
            </td>
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
    <?php
    /*
     * Eine Anlegezeile, ein Knopf.
     *
     * Hier standen zwei - "Vokabeln einlesen" und "Am Smartphone einlesen"
     * -, und beide waren nicht die Frage, die man an dieser Stelle hat.
     * Die lautet: Ich brauche eine neue Lerneinheit. Wie sie gefuellt wird,
     * entscheidet man auf ihrer Seite, wo alle drei Wege nebeneinander
     * stehen - auch der von Hand, den es hier gar nicht gab.
     */
    ?>
    <tr class="newrow">
        <td colspan="4" data-label="Neue Lerneinheit">
            <span class="coursetitle addbuttons">
                <button class="iconaction primary" form="neueEinheit"
                        name="add_unit" value="1">
                    <span aria-hidden="true">+</span> Lerneinheit anlegen
                </button>
            </span>
        </td>
    </tr>
</table>

<?php
/*
 * Das Formular fuer die Pfeile. Es liegt ausserhalb der Tabelle - ein
 * <form> darf sich nicht ueber mehrere Zellen spannen -, und die Knoepfe
 * gehoeren ueber form= dazu.
 */
?>
<form method="post" id="sortierform"
      action="<?= h(teacher_url('course.php') . '?id=' . $courseId) ?>">
    <?= teacher_csrf_field() ?>
    <input type="hidden" name="course_id" value="<?= $courseId ?>">
    <input type="hidden" name="reorder_units" value="1">
</form>

<p class="tiny muted">
    Die Reihenfolge hier ist die, in der deine Klasse die Lerneinheiten
    sieht &ndash; zieh sie am Griff links dorthin, wo sie hingeh&ouml;ren.
    Neue kommen immer ans Ende.
    Eine Zeile anklicken öffnet die Freigabe. Freigegeben wird portionsweise:
    Die Klasse sieht nur, was aufgemacht ist. Gefüllt wird eine Lerneinheit
    auf ihrer eigenen Seite &ndash; von Hand, aus Dateien oder mit dem Telefon
    fotografiert.
</p>
<?php endif; ?>

<?php
/*
 * Das Fenster mit dem QR-Code stand einmal hier.
 *
 * Es gehoert zur Lerneinheit, nicht zum Kurs: Der Code fuehrt ins
 * Einlesen, und eingelesen wird IN eine Lerneinheit. Auf ihrer Seite steht
 * er als einer von drei Wegen neben den beiden anderen.
 */
?>
<h2>Wer im Kurs ist</h2>

<table class="data kompakt" id="mitglieder">
    <tr>
        <th>Name</th>
        <th>Benutzername</th>
        <th>Klasse</th>
        <th class="actions"></th>
    </tr>
    <?php foreach ($mitglieder as $m): ?>
        <tr<?= $m['active'] ? '' : ' class="dim"' ?>>
            <td data-label="Name"><?= h($m['display_name']) ?></td>
            <td data-label="Benutzername"><code class="token"><?= h($m['username']) ?></code></td>
            <?php
            /*
             * Die Klasse statt der Rolle: "Kind" in jeder Zeile sagte
             * nichts - dass im Kurs Kinder sind, weiss man. Die Klasse
             * unterscheidet, und bei einem Kurs quer durch die Jahrgaenge
             * ist sie die einzige Auskunft, die zaehlt.
             */
            ?>
            <td data-label="Klasse"><?= $m['member_role'] === 'teacher'
                    ? 'Lehrkraft'
                    : (($m['class_name'] ?? null) === null
                        ? '<span class="muted">ohne Klasse</span>'
                        : h((string) $m['class_name'])) ?><?php
                if (!$m['active']) { echo ' <span class="tiny muted">stillgelegt</span>'; } ?></td>
            <td class="actions">
                <?php if ($m['member_role'] === 'teacher' && $lehrkraefteImKurs === 1): ?>
                    <?php // Die letzte Lehrkraft bleibt - siehe course_is_last_teacher(). ?>
                    <?php // Am Telefon war "einzige Lehrkraft" abgeschnitten - wie bei "Entfernen" nur das Zeichen. ?>
                    <span class="tiny muted" title="Ein Kurs braucht mindestens eine Lehrkraft"><span aria-hidden="true">&#128274;</span><span class="nurbreit"> einzige Lehrkraft</span></span>
                <?php else: ?>
                <form method="post" class="compact">
                    <?= teacher_csrf_field() ?>
                    <input type="hidden" name="course_id" value="<?= $courseId ?>">
                    <button class="iconaction danger" name="remove_member"
                            value="<?= (int) $m['id'] ?>" title="Aus dem Kurs nehmen"
                            data-confirm="<?= h($m['display_name']) ?> aus dem Kurs nehmen? Der Lernstand bleibt erhalten.">
                        <span aria-hidden="true">&#10005;</span>
                        <span class="nurbreit"> Entfernen</span>
                    </button>
                </form>
                <?php endif; ?>
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
        <?php
        /*
         * Eine Zelle ueber alle Spalten: Suchfeld und Knopf gehoeren
         * zusammen, und in Spalten zerlegt musste der Knopf in die Spalte
         * des Hinauswurfs passen - am Telefon sechsunddreissig Pixel.
         */
        ?>
        <td colspan="4" data-label="Aufnehmen">
            <span class="anlegezeile">
            <?php if ($offene === []): ?>
                <span class="tiny muted">
                    Alle Konten dieser Schule sind schon im Kurs. Neue Kinder
                    legst du in der Klasse an.
                </span>
            <?php else: ?>
                <?php
                /*
                 * Das Feld sucht mit, waehrend getippt wird.
                 *
                 * Die Liste steht als <datalist> im HTML - das ist der Weg
                 * ohne JavaScript: ein Textfeld mit Vorschlaegen, das der
                 * Browser selbst anbietet. Mit JavaScript liest das Skript
                 * dieselbe Liste aus, filtert bei jedem Zeichen und zeigt
                 * die Treffer darunter; bleibt einer uebrig, schreibt es
                 * ihn grau zu Ende. Zwei Wege, eine Quelle.
                 */
                ?>
                <span class="coursetitle suchfeld" data-suche>
                    <span class="cflag plus">+</span>
                    <span class="feldbox">
                        <span class="geist" aria-hidden="true"></span>
                        <input type="text" name="member_name" form="newmember"
                               list="kandidaten" autocomplete="off" maxlength="80" required
                               role="combobox" aria-expanded="false" aria-autocomplete="both"
                               placeholder="Name eintippen" aria-label="Wen aufnehmen?">
                    </span>
                    <ul class="vorschlaege" role="listbox" hidden></ul>
                </span>
                <?php
                /*
                 * Der Wert bleibt der blosse Name - danach sucht der
                 * Server, und ohne JavaScript schickt das Feld genau das
                 * ab. Die Klasse steht daneben in data-zusatz: Das Skript
                 * schreibt sie in Klammern hinter den Namen und sucht
                 * darin mit.
                 */
                ?>
                <datalist id="kandidaten">
                    <?php foreach ($offene as $o): ?>
                        <?php $zusatz = $o['role'] === ROLE_TEACHER
                            ? 'Lehrkraft'
                            : (string) ($o['class_name'] ?? 'ohne Klasse'); ?>
                        <option value="<?= h($o['display_name']) ?>"
                                data-zusatz="<?= h($zusatz) ?>"
                                label="<?= h($zusatz) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <button class="iconaction primary" form="newmember"
                        name="add_member_by_name" value="1" title="In den Kurs aufnehmen">
                    <span aria-hidden="true">+</span> Aufnehmen
                </button>
            <?php endif; ?>
            </span>
        </td>
    </tr>
</table>

<form method="post" id="neueEinheit" hidden>
    <?= teacher_csrf_field() ?>
    <input type="hidden" name="course_id" value="<?= $courseId ?>">
</form>

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
<?php
/*
 * Der Knopf sagt, was er tun wuerde.
 *
 * "Klasse 5B nachtragen" liess offen, ob dabei etwas passiert - und meistens
 * passierte nichts: Die Kinder kommen beim Anlegen des Kurses mit hinein,
 * nachzutragen ist nur, wer seither dazugekommen ist. Wer draufdrueckte,
 * bekam "Es war niemand nachzutragen", also eine Auskunft auf eine Frage,
 * die er nicht gestellt hatte. Jetzt steht die Zahl im Knopf, und ohne
 * etwas zu tun ist er abgeblendet - dastehen soll er trotzdem, sonst sucht
 * man ihn beim naechsten Mal.
 */
$fehlende = course_class_missing($courseId);
?>
<div class="buttonrow">
    <form method="post">
        <?= teacher_csrf_field() ?>
        <input type="hidden" name="course_id" value="<?= $courseId ?>">
        <button class="btn small secondary" name="sync_class" value="1"
                <?= $fehlende === 0
                    ? 'disabled title="Alle Kinder der Klasse sind schon im Kurs."'
                    : '' ?>>
            <?php if ($fehlende === 0): ?>
                Alle Kinder aus Klasse <?= h($kurs['class_name']) ?> sind im Kurs
            <?php else: ?>
                <?= $fehlende ?>
                <?= $fehlende === 1 ? 'fehlendes Kind' : 'fehlende Kinder' ?>
                aus Klasse <?= h($kurs['class_name']) ?> eintragen
            <?php endif; ?>
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
            <td>Lückensätze</td>
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
