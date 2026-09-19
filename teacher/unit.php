<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/sentences.php';
require_once __DIR__ . '/../lib/vocab.php';

/*
 * Eine Lerneinheit aus Sicht der Lehrkraft - und die Stelle, an der
 * freigegeben wird.
 *
 * Der Sinn der portionsweisen Freigabe ist nicht Paedagogik, sondern Geld:
 * Zu jeder freigegebenen Vokabel entstehen Lueckensaetze, und die kosten.
 * Deshalb wird eine ganze Unit auf einmal eingelesen - das Fotografieren
 * lohnt sich seitenweise - aber nur das aufgemacht, was gerade dran ist.
 */

$user     = teacher_require();
$schoolId = (int) ($user['school_id'] ?? 0);

$unitId = (int) ($_GET['id'] ?? $_POST['unit_id'] ?? 0);

/*
 * Die Einheit muss zur Schule dieser Lehrkraft gehoeren. Geprueft wird ueber
 * den Kurs, nicht ueber units.user_id - eine Vertretung soll an die
 * Unterlagen ihrer Kollegin kommen, ein fremdes Kollegium nicht.
 */
$unit = $schoolId === 0 ? null : q1(
    'SELECT t.*, co.name AS course_name, co.school_id, co.class_id, c.name AS class_name,
            l.name AS language_name, l.flag_emoji, l.code
       FROM units t
       JOIN courses co   ON co.id = t.course_id
       JOIN languages l  ON l.id = t.language_id
       LEFT JOIN classes c ON c.id = co.class_id
      WHERE t.id = ? AND co.school_id = ?',
    [$unitId, $schoolId],
);

if ($unit === null) {
    teacher_flash('Diese Lerneinheit gibt es nicht.', 'bad');
    teacher_redirect('classes.php');
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

    /*
     * Die Freigabe setzt nur noch die Marke.
     *
     * Die Saetze entstehen beim Einlesen fuer die ganze Einheit - einmal,
     * zu einem Zeitpunkt, an dem niemand davorsitzt. Frueher haengte die
     * Erzeugung an dieser Stelle, und dann wartete die Lehrkraft nach jeder
     * Portion eine halbe Minute, waehrend die Lerneinheit fuer die Klasse halb
     * da war.
     *
     * Fehlt trotzdem etwas - ein abgebrochener Lauf, ein aufgebrauchtes
     * Budget -, sagt es die Seite und der Knopf "Saetze nachtragen" holt es.
     */
    if ($bis <= $vorher) {
        teacher_flash($bis === 0
            ? 'Die Freigabe ist zurückgenommen. Gelernt bleibt gelernt - '
              . 'beim nächsten Freigeben ist der Stand wieder da.'
            : sprintf('Freigabe auf %d Vokabeln zurückgenommen.', $bis));
        teacher_redirect($zurueck);
    }

    $fehlen = vocab_without_sentences($unitId);
    teacher_flash($fehlen === 0
        ? sprintf('%d Vokabeln freigegeben.', $bis)
        : sprintf('%d Vokabeln freigegeben. Für %d fehlen noch Lückensätze - '
                  . 'mit „Sätze nachtragen".', $bis, $fehlen));
    teacher_redirect($zurueck);
}

/*
 * Saetze nachtragen.
 *
 * Das Sicherheitsnetz fuer alles, was einen Lauf unvollstaendig zuruecklaesst:
 * ein abgebrochener Lauf, ein aufgebrauchtes Budget, oder eine Freigabe, die
 * einen bereits laufenden Lauf nicht mehr erreicht hat. Ein Knopf, der genau
 * das erzeugt, was fehlt.
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['catch_up'])) {
    teacher_csrf_check();

    if (vocab_without_sentences($unitId) === 0) {
        teacher_flash('Es fehlt kein Satz.');
        teacher_redirect($zurueck);
    }

    $blocked = budget_block_reason((int) $user['id']);
    if ($blocked !== null) {
        teacher_flash($blocked, 'bad');
        teacher_redirect($zurueck);
    }

    if (!sentence_claim($unitId)) {
        teacher_flash('Es läuft schon ein Satzlauf. Bitte abwarten.', 'bad');
        teacher_redirect($zurueck);
    }

    teacher_flash('Die fehlenden Lückensätze entstehen gerade.');
    teacher_redirect_and_continue($zurueck);

    set_time_limit(900);
    generate_sentences_tracked($unitId);
    exit;
}

/*
 * Vokabeln von Hand: hinzufuegen, aendern, loeschen.
 *
 * Bisher konnte das nur der Betreiber im Admin. Eine Lehrkraft sah in ihrer
 * eigenen Lerneinheit ein falsch erkanntes Wort und konnte nichts tun -
 * ausser die ganze Einheit neu einzulesen.
 *
 * Alles laeuft ueber lib/vocab.php: Positionen bleiben lueckenlos, die
 * Freigabemarke wird beim Loeschen mitgefuehrt, und punctuation_fix()
 * greift wie beim Einlesen.
 *
 * Zur Berechtigung: Diese Seite prueft auf die SCHULE (oben, beim Laden der
 * Einheit) - nicht auf die Kursmitgliedschaft wie die API. Das ist Absicht
 * und bleibt so: Eine Vertretung muss an den Unterlagen ihrer Kollegin
 * arbeiten koennen, und genau dafuer ist der Lehrkraft-Bereich da.
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['add_vocab'])) {
    teacher_csrf_check();

    $f = trim((string) ($_POST['new_f'] ?? ''));
    $n = trim((string) ($_POST['new_n'] ?? ''));

    if ($f === '' || $n === '') {
        teacher_flash('Beide Felder ausfüllen - Fremdsprache und Deutsch.', 'bad');
        teacher_redirect($zurueck);
    }

    $dazu = vocab_append($unitId, [['foreign' => $f, 'native' => $n]],
                         $unit['code'] ?? null);
    if ($dazu === 0) {
        teacher_flash('Die Vokabel liess sich nicht anlegen.', 'bad');
        teacher_redirect($zurueck);
    }

    /*
     * Und gleich einen Lueckensatz dazu - sonst bleibt die neue Vokabel im
     * Lueckentext stumm, und niemand sieht, warum. Dasselbe Muster wie
     * "Saetze nachtragen": antworten, dann weiterarbeiten.
     */
    if (budget_block_reason((int) $user['id']) === null && sentence_claim($unitId)) {
        teacher_flash(sprintf('„%s" ist dabei. Der Lückensatz entsteht gerade.', $f));
        teacher_redirect_and_continue($zurueck);
        set_time_limit(900);
        generate_sentences_tracked($unitId);
        exit;
    }

    teacher_flash(sprintf('„%s" ist dabei.', $f));
    teacher_redirect($zurueck);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_vocab'])) {
    teacher_csrf_check();

    $vokabelId = (int) $_POST['save_vocab'];

    // Nur Vokabeln DIESER Einheit - sonst liesse sich ueber ein
    // untergeschobenes Formular jede Vokabel der Anwendung aendern.
    if ((int) qv('SELECT COUNT(*) FROM vocab WHERE id = ? AND unit_id = ?',
                 [$vokabelId, $unitId]) !== 1) {
        teacher_flash('Diese Vokabel gehört nicht zu dieser Lerneinheit.', 'bad');
        teacher_redirect($zurueck);
    }

    $ok = vocab_update(
        $vokabelId,
        (string) ($_POST['edit_f'] ?? ''),
        (string) ($_POST['edit_n'] ?? ''),
        $unit['code'] ?? null,
    );
    teacher_flash($ok ? 'Geändert.' : 'Beide Felder ausfüllen.', $ok ? 'good' : 'bad');
    teacher_redirect($zurueck);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['delete_vocab'])) {
    teacher_csrf_check();

    $vokabelId = (int) $_POST['delete_vocab'];

    if ((int) qv('SELECT COUNT(*) FROM vocab WHERE id = ? AND unit_id = ?',
                 [$vokabelId, $unitId]) !== 1) {
        teacher_flash('Diese Vokabel gehört nicht zu dieser Lerneinheit.', 'bad');
        teacher_redirect($zurueck);
    }

    $wort = (string) qv('SELECT term_foreign FROM vocab WHERE id = ?', [$vokabelId]);
    vocab_delete($vokabelId);
    teacher_flash(sprintf(
        '„%s" ist gelöscht - mit den Lückensätzen dazu und dem, was die '
        . 'Kinder daran gelernt hatten.', $wort,
    ));
    teacher_redirect($zurueck);
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

/*
 * Der Pfad statt einer Fliesstextzeile.
 *
 * Klasse, Kurs, Lerneinheit - drei Ebenen, und die beiden oberen sind von
 * hier aus erreichbar. Vorher stand dasselbe als "Englisch - Klasse 7b -
 * zurueck zum Kurs" untereinander, wobei nur das letzte Stueck ein Link war
 * und als solcher kaum zu erkennen.
 */
$pfad = [];
if (($unit['class_name'] ?? null) !== null && ($unit['class_id'] ?? null) !== null) {
    $pfad[] = [
        'label' => 'Klasse ' . $unit['class_name'],
        'href'  => teacher_url('class.php') . '?id=' . (int) $unit['class_id'],
    ];
}
$pfad[] = teacher_course_crumb($user, [
    'id'         => (int) $unit['course_id'],
    'name'       => (string) $unit['course_name'],
    'flag_emoji' => (string) $unit['flag_emoji'],
], false);
$pfad[] = ['label' => (string) $unit['title'], 'href' => null];

teacher_head($unit['title'], $user, $pfad, sprintf(
    '<a class="btn small secondary" href="%s" target="_blank" rel="noopener" '
    . 'title="Die Ansicht, die deine Klasse sieht">'
    . '<span aria-hidden="true">&#128065;</span> So sieht es die Klasse</a>',
    h(url('/') . '#/unit/' . $unitId),
));
teacher_flash_render();
?>

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

<?php
/*
 * Auch ohne Vokabeln wird die Tabelle gezeigt.
 *
 * Vorher verschluckte dieser Zweig alles - und seit unten eine Anlegezeile
 * steht, war damit ausgerechnet in einer leeren Lerneinheit der einzige
 * Weg verdeckt, eine Vokabel von Hand einzutragen.
 */
?>
<?php if ($gesamt === 0): ?>
    <?= teacher_leer(
        'Diese Lerneinheit hat noch keine Vokabeln. Trag unten eine von Hand '
        . 'ein, oder lies eine Buchseite ein.',
        sprintf('<a class="btn small" href="%s" target="_blank" rel="noopener">'
                . 'Vokabeln einlesen</a>',
                h(url('/') . '#/lang/' . (int) $unit['language_id'] . '/import')),
    ) ?>
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
<?php endif; ?>

<?php
/*
 * Die Knoepfe in einer Reihe und von gleicher Bauart.
 *
 * "Alles freigeben" war ein Knopf, "Freigabe zuruecknehmen" ein roter Text
 * mit Schloss daneben, und beide verschwanden abwechselnd - je nachdem, ob
 * sie gerade etwas bewirkt haetten. Die Reihe sprang dadurch bei jedem
 * Freigeben um. Jetzt stehen beide immer da, gleich gebaut und gleich gross;
 * was nichts bewirken wuerde, ist abgeblendet und sagt im Titel, warum.
 *
 * Ein Formular fuer alle drei: Sie gehen an dieselbe Adresse und
 * unterscheiden sich nur im Namen des Knopfes.
 */
$fehlen = vocab_without_sentences($unitId);
?>
<form method="post">
    <?= teacher_csrf_field() ?>
    <input type="hidden" name="unit_id" value="<?= $unitId ?>">

    <div class="buttonrow">
        <button class="btn small" name="release" value="<?= $gesamt ?>"
                <?= $alles ? 'disabled title="Es ist schon alles freigegeben."' : '' ?>
                data-confirm="Alle <?= $gesamt ?> Vokabeln freigeben? Die Klasse sieht dann die ganze Lerneinheit.">
            Alles freigeben
        </button>

        <button class="btn small secondary" name="release" value="0"
                <?= $frei === 0 ? 'disabled title="Es ist nichts freigegeben."' : '' ?>
                data-confirm="Die ganze Lerneinheit wieder zumachen? Die Klasse sieht sie dann als leer. Gelernt bleibt gelernt.">
            Nichts freigeben
        </button>

        <?php if ($fehlen > 0 && $zustand['status'] !== SENTENCE_RUNNING): ?>
            <button class="btn small secondary" name="catch_up" value="1"
                    data-confirm="Für <?= $fehlen ?> Vokabeln fehlen noch Lückensätze. Jetzt nachholen? Das kostet.">
                Sätze nachtragen (<?= $fehlen ?>)
            </button>
        <?php endif; ?>
    </div>
</form>

<?php
/*
 * Die Freigabe als verschiebbarer Balken.
 *
 * Vorher stand neben jeder Zeile ein Knopf "bis hier freigeben" - bei
 * hundert Vokabeln hundert Knoepfe, und keiner sagte, was er bewirkt,
 * bevor man ihn gedrueckt hatte. Jetzt gibt es einen Balken: Alles
 * darueber ist auf, alles darunter zu. Wer ihn anfasst und verschiebt,
 * sieht die Zeilen beim Ziehen umschlagen und laesst ihn los, wo er hin
 * soll.
 *
 * Ohne JavaScript bleibt er eine gewoehnliche Tabelle mit einem Knopf je
 * Zeile - das Formular darunter ist dasselbe.
 */
?>
<table class="data release" id="freigabe" data-released="<?= $frei ?>">
    <tr>
        <th class="num">#</th>
        <th>Fremdsprache</th>
        <th>Deutsch</th>
        <th class="num">Sätze</th>
        <th class="actions"></th>
    </tr>
    <?php foreach ($vokabeln as $i => $v): ?>
        <?php $istFrei = $i < $frei; ?>
        <tr class="<?= $istFrei ? 'released' : 'locked' ?>" data-pos="<?= $i + 1 ?>">
            <td class="num"><?= $i + 1 ?></td>
            <td data-label="Fremdsprache">
                <strong data-wort><?= h($v['term_foreign']) ?></strong>
                <input type="text" name="edit_f" value="<?= h($v['term_foreign']) ?>"
                       form="vokabel<?= (int) $v['id'] ?>" maxlength="255" hidden>
            </td>
            <td data-label="Deutsch">
                <span data-wort><?= h($v['term_native']) ?></span>
                <input type="text" name="edit_n" value="<?= h($v['term_native']) ?>"
                       form="vokabel<?= (int) $v['id'] ?>" maxlength="255" hidden>
            </td>
            <td class="num" data-label="Sätze">
                <?= (int) $v['saetze'] > 0
                    ? (int) $v['saetze']
                    : '<span class="muted" title="Für diese Vokabel gibt es noch keinen Lückensatz">&ndash;</span>' ?>
            </td>
            <td class="actions">
                <button class="iconaction quiet js-hide" name="release" value="<?= $i + 1 ?>"
                        form="releaseform" title="Bis hier freigeben">
                    <span aria-hidden="true">&#128275;</span> bis hier
                </button>
                <button class="iconaction quiet" data-edit="<?= (int) $v['id'] ?>"
                        type="button" title="Diese Vokabel ändern">
                    <span aria-hidden="true">&#9998;</span> Ändern
                </button>
                <button class="iconaction primary" form="vokabel<?= (int) $v['id'] ?>"
                        name="save_vocab" value="<?= (int) $v['id'] ?>"
                        data-save="<?= (int) $v['id'] ?>" title="Änderung speichern" hidden>
                    <span aria-hidden="true">&#10003;</span> Sichern
                </button>
                <button class="iconaction danger" form="vokabel<?= (int) $v['id'] ?>"
                        name="delete_vocab" value="<?= (int) $v['id'] ?>"
                        title="Diese Vokabel löschen"
                        data-confirm="&bdquo;<?= h($v['term_foreign']) ?>&ldquo; löschen? Die Lückensätze dazu und der Lernstand aller Kinder daran verschwinden mit.">
                    <span aria-hidden="true">&#10005;</span> Löschen
                </button>
            </td>
        </tr>
    <?php endforeach; ?>

    <?php
    /*
     * Die Anlegezeile, wie in jeder anderen Tabelle. Sie steht ausserhalb
     * der Freigabelogik: kein data-pos, keine released/locked-Klasse - der
     * Balken darf sie nicht als Vokabelzeile zaehlen.
     */
    ?>
    <tr class="newrow">
        <td class="num"><span class="cflag plus">+</span></td>
        <td data-label="Fremdsprache">
            <input type="text" name="new_f" form="neueVokabel" maxlength="255"
                   placeholder="apple" aria-label="Fremdsprache">
        </td>
        <td data-label="Deutsch">
            <input type="text" name="new_n" form="neueVokabel" maxlength="255"
                   placeholder="Apfel" aria-label="Deutsch">
        </td>
        <td></td>
        <td class="actions">
            <button class="iconaction primary" form="neueVokabel"
                    name="add_vocab" value="1" title="Vokabel hinzufügen">
                <span aria-hidden="true">+</span> Hinzufügen
            </button>
        </td>
    </tr>
</table>

<form method="post" id="releaseform">
    <?= teacher_csrf_field() ?>
    <input type="hidden" name="unit_id" value="<?= $unitId ?>">
</form>

<form method="post" id="neueVokabel">
    <?= teacher_csrf_field() ?>
    <input type="hidden" name="unit_id" value="<?= $unitId ?>">
</form>

<?php
/*
 * Je Vokabel ein eigenes Formular - ein Formular kann sich in HTML nicht
 * ueber mehrere Zellen spannen, und die Felder gehoeren ueber form= dazu.
 */
?>
<?php foreach ($vokabeln as $v): ?>
    <form method="post" id="vokabel<?= (int) $v['id'] ?>">
        <?= teacher_csrf_field() ?>
        <input type="hidden" name="unit_id" value="<?= $unitId ?>">
    </form>
<?php endforeach; ?>

<p class="tiny muted">
    Die Lückensätze entstehen schon beim Einlesen, für die ganze Einheit.
    Die Freigabe entscheidet also nicht, wofür bezahlt wird, sondern nur,
    was die Klasse zu sehen bekommt &ndash; Vokabeln wie Lückensätze.
    Zurücknehmen kostet nichts: Die Sätze bleiben, und der Lernstand der
    Kinder ist beim nächsten Freigeben wieder da.
</p>


<?php teacher_foot(); ?>
