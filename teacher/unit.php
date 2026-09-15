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
    'SELECT t.*, co.name AS course_name, co.school_id, co.class_id, c.name AS class_name,
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

    /*
     * Die Freigabe setzt nur noch die Marke.
     *
     * Die Saetze entstehen beim Einlesen fuer die ganze Einheit - einmal,
     * zu einem Zeitpunkt, an dem niemand davorsitzt. Frueher haengte die
     * Erzeugung an dieser Stelle, und dann wartete die Lehrkraft nach jeder
     * Portion eine halbe Minute, waehrend die Lektion fuer die Klasse halb
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
$pfad[] = [
    'label' => (string) $unit['course_name'],
    'href'  => teacher_url('course.php') . '?id=' . (int) $unit['course_id'],
    'flag'  => (string) $unit['flag_emoji'],
];
$pfad[] = ['label' => (string) $unit['title'], 'href' => null];

teacher_head($unit['title'], 'index.php', $user, $pfad);
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
                data-confirm="Alle <?= $gesamt ?> Vokabeln freigeben? Die Klasse sieht dann die ganze Lektion.">
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
            <td><strong><?= h($v['term_foreign']) ?></strong></td>
            <td><?= h($v['term_native']) ?></td>
            <td class="num">
                <?= (int) $v['saetze'] > 0
                    ? (int) $v['saetze']
                    : '<span class="muted" title="Für diese Vokabel gibt es noch keinen Lückensatz">&ndash;</span>' ?>
            </td>
            <td class="actions">
                <button class="iconaction quiet js-hide" name="release" value="<?= $i + 1 ?>"
                        form="releaseform" title="Bis hier freigeben">
                    <span aria-hidden="true">&#128275;</span> bis hier
                </button>
            </td>
        </tr>
    <?php endforeach; ?>
</table>

<form method="post" id="releaseform">
    <?= teacher_csrf_field() ?>
    <input type="hidden" name="unit_id" value="<?= $unitId ?>">
</form>

<p class="tiny muted">
    Die Lückensätze entstehen schon beim Einlesen, für die ganze Einheit.
    Die Freigabe entscheidet also nicht, wofür bezahlt wird, sondern nur,
    was die Klasse zu sehen bekommt &ndash; Vokabeln wie Lückensätze.
    Zurücknehmen kostet nichts: Die Sätze bleiben, und der Lernstand der
    Kinder ist beim nächsten Freigeben wieder da.
</p>

<?php endif; ?>

<script>
document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-confirm]');
    if (b && !confirm(b.dataset.confirm)) e.preventDefault();
});
</script>

<?php teacher_foot(); ?>
