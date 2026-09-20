<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
// Fuer HANDOFF_TTL im Fenster mit dem QR-Code - der Sprung ans Telefon
// steht jetzt auch hier, nicht nur im Kurs.
require_once __DIR__ . '/../lib/handoff.php';
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
 * Umbenennen und Loeschen - jetzt hier statt in der App.
 *
 * Beides stand in der Schueleransicht, unter "Verwalten", und war fuer eine
 * Lehrkraft der einzige Weg dorthin. Nur ist die Schueleransicht das, was
 * die Klasse sieht: Wer sie aufmacht, um auszuprobieren, wie eine
 * Lerneinheit ankommt, soll genau das sehen und nicht zwei Knoepfe mehr.
 * Also stehen sie dort, wo verwaltet wird - auf derselben Lerneinheit.
 *
 * Wie beim Aendern einer Vokabel gilt die SCHULE als Regel, nicht die
 * Kursmitgliedschaft: Eine Vertretung muss arbeiten koennen.
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['rename_unit'])) {
    teacher_csrf_check();

    $titel = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['title'] ?? '')) ?? '');
    if ($titel === '') {
        teacher_flash('Die Lerneinheit braucht einen Titel.', 'bad');
        teacher_redirect($zurueck);
    }
    $titel = mb_substr($titel, 0, 128);

    if ($titel === (string) $unit['title']) {
        teacher_flash('Der Titel war schon so.');
        teacher_redirect($zurueck);
    }

    q('UPDATE units SET title = ? WHERE id = ?', [$titel, $unitId]);
    teacher_flash(sprintf('Heisst jetzt „%s“.', $titel));
    teacher_redirect($zurueck);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['delete_unit'])) {
    teacher_csrf_check();

    /*
     * Was mitgeht, wird vorher gezaehlt - danach ist es weg, und die
     * Meldung soll sagen, was wirklich verschwunden ist.
     *
     * Geloescht wird nur die Zeile in units: Vokabeln haengen per
     * ON DELETE CASCADE daran, Saetze und Lernstaende wiederum an den
     * Vokabeln.
     */
    $weg = q1(
        'SELECT (SELECT COUNT(*) FROM vocab v WHERE v.unit_id = t.id) AS vokabeln,
                (SELECT COUNT(*) FROM sentences s
                   JOIN vocab v2 ON v2.id = s.vocab_id
                  WHERE v2.unit_id = t.id) AS saetze,
                (SELECT COUNT(*) FROM progress p
                   JOIN vocab v3 ON v3.id = p.vocab_id
                  WHERE v3.unit_id = t.id) AS staende
           FROM units t WHERE t.id = ?',
        [$unitId],
    ) ?? ['vokabeln' => 0, 'saetze' => 0, 'staende' => 0];

    $titel = (string) $unit['title'];
    $kurs  = (int) $unit['course_id'];

    q('DELETE FROM units WHERE id = ?', [$unitId]);

    teacher_flash(sprintf(
        '„%s“ ist gelöscht - mit %d Vokabeln, %d Lückensätzen und den '
        . 'Lernständen von %d Kindern daran.',
        $titel, (int) $weg['vokabeln'], (int) $weg['saetze'], (int) $weg['staende'],
    ));
    teacher_redirect('course.php?id=' . $kurs);
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
/**
 * Will der Aufrufer eine Zeile statt einer Seite?
 *
 * Das Formular funktioniert ohne JavaScript ganz gewoehnlich: abschicken,
 * weiterleiten, neue Seite. Mit JavaScript wird daraus ein Zug - Wort,
 * Tab, Wort, Enter, naechste Vokabel -, und dafuer braucht es die frische
 * Zeile als Antwort statt einer ganzen Seite. Dasselbe Muster wie beim
 * Eintragen einer Klassenliste.
 */
function unit_will_json(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
}

/**
 * Eine JSON-Antwort - mit Laenge.
 *
 * Content-Length ist hier nicht Beiwerk, sondern der Unterschied zwischen
 * "angekommen" und "haengt": Wenn der Vorgang nach dem Abschicken noch
 * weiterarbeitet (die Lueckensaetze), bleibt die Verbindung offen. Ohne
 * Laengenangabe weiss der Browser nicht, wo die Antwort aufhoert - er
 * wartet auf das Schliessen der Verbindung und meldet am Ende "keine
 * Verbindung", obwohl die Vokabel laengst in der Datenbank steht. Wer das
 * sieht, drueckt noch einmal, und dann steht sie zweimal drin.
 *
 * teacher_redirect_and_continue() setzt aus demselben Grund
 * Content-Length: 0.
 */
function unit_json(array $daten, int $status = 200): never
{
    $koerper = (string) json_encode($daten, JSON_UNESCAPED_UNICODE);

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($koerper));
    echo $koerper;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['add_vocab'])) {
    teacher_csrf_check();

    $f = trim((string) ($_POST['new_f'] ?? ''));
    $n = trim((string) ($_POST['new_n'] ?? ''));

    if ($f === '' || $n === '') {
        if (unit_will_json()) {
            unit_json(['ok' => false,
                       'error' => 'Beide Felder ausfüllen - Fremdsprache und Deutsch.'], 422);
        }
        teacher_flash('Beide Felder ausfüllen - Fremdsprache und Deutsch.', 'bad');
        teacher_redirect($zurueck);
    }

    /*
     * vocab_append() ueberspringt, was schon drinsteht. Null heisst hier
     * also nicht "ging schief", sondern "gibt es schon" - und genau das
     * soll dastehen: Wer zweimal drueckt, soll die Vokabel einmal haben
     * und wissen, warum nichts dazugekommen ist.
     */
    $dazu = vocab_append($unitId, [['foreign' => $f, 'native' => $n]],
                         $unit['code'] ?? null);
    if ($dazu === 0) {
        $meldung = sprintf('„%s – %s" steht schon in dieser Lerneinheit.', $f, $n);
        if (unit_will_json()) {
            unit_json(['ok' => false, 'error' => $meldung, 'doppelt' => true], 409);
        }
        teacher_flash($meldung, 'bad');
        teacher_redirect($zurueck);
    }

    /*
     * Die frisch angelegte Zeile - vocab_append() setzt punctuation_fix()
     * darauf an, es steht also nicht zwingend das in der Datenbank, was
     * getippt wurde. Zurueckgegeben wird, was wirklich drinsteht.
     */
    $neueVokabel = q1('SELECT id, term_foreign, term_native FROM vocab
                        WHERE unit_id = ? ORDER BY position DESC, id DESC LIMIT 1',
                      [$unitId]);

    /*
     * Und gleich einen Lueckensatz dazu - sonst bleibt die neue Vokabel im
     * Lueckentext stumm, und niemand sieht, warum. Dasselbe Muster wie
     * "Saetze nachtragen": antworten, dann weiterarbeiten.
     */
    $saetze = budget_block_reason((int) $user['id']) === null && sentence_claim($unitId);

    if (unit_will_json()) {
        if (!$saetze) {
            unit_json(['ok' => true, 'vokabel' => $neueVokabel]);
        }

        /*
         * Antworten, dann weiterarbeiten: Die Zeile steht beim Tippenden
         * schon, waehrend der Satz dazu noch entsteht.
         */
        $koerper = (string) json_encode(['ok' => true, 'vokabel' => $neueVokabel],
                                        JSON_UNESCAPED_UNICODE);
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Content-Length: ' . strlen($koerper));
        echo $koerper;

        teacher_flush_and_continue();
        set_time_limit(900);
        generate_sentences_tracked($unitId);
        exit;
    }

    if ($saetze) {
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
    'SELECT v.id, v.position, v.term_foreign, v.term_native, v.word_type
       FROM vocab v
      WHERE v.unit_id = ?
      ORDER BY v.position, v.id',
    [$unitId],
);

$frei   = (int) $unit['released_position'];
$alles  = $frei >= $gesamt;
$zustand = sentence_status($unitId, (int) $user['id']);

/*
 * Die Ueberschrift ist der Weg zurueck - und das Umbenennen.
 *
 * Sie lautet "Englisch - 5B > Unit 4": Der Kurs davor ist ein Knopf, der
 * dorthin zurueckfuehrt. Er sieht auch nach einem aus; ein unterstrichenes
 * Wort in einer Ueberschrift liest man als Ueberschrift.
 *
 * "Meine Kurse" stand hier einmal daneben. Dafuer gibt es jetzt das
 * Haeuschen im Pfad, und eine Ebene hoeher will man von hier aus oefter
 * als ganz nach oben.
 *
 * Und der Stift: Der Titel wird an Ort und Stelle zum Eingabefeld, mit
 * Haken zum Sichern und Kreuz zum Verwerfen. Er stand vorher als eigenes
 * Formular am Fuss der Seite - eine Zeile, die dasselbe noch einmal sagte,
 * was oben schon stand. Ohne JavaScript ist das Feld von Anfang an da und
 * das Formular ein gewoehnliches; das Skript blendet nur um.
 */
$kursUrl   = teacher_url('course.php') . '?id=' . (int) $unit['course_id'];
$importUrl = url('/') . '#/lang/' . (int) $unit['language_id'] . '/import';

$titelHtml = sprintf(
    '<a class="kursknopf" href="%s" title="Zur&uuml;ck zum Kurs">%s%s</a>'
    . '<span class="titelsep" aria-hidden="true">&#8250;</span>'
    . '<span class="einheitname" data-titel>%s</span>'
    . '<input class="einheitfeld" type="text" name="title" form="titelform"'
    . ' value="%s" maxlength="128" required aria-label="Titel der Lerneinheit">'
    . '<button class="iconbtn" type="button" data-rename'
    . ' title="Umbenennen" aria-label="Umbenennen">&#9999;&#65039;</button>'
    . '<button class="iconbtn gut" form="titelform" name="rename_unit" value="1"'
    . ' data-rename-save title="Sichern" aria-label="Sichern">&#10003;</button>'
    . '<button class="iconbtn" type="button" data-rename-cancel'
    . ' title="Verwerfen" aria-label="Verwerfen">&#10005;</button>',
    h($kursUrl),
    flag_html((string) $unit['flag_emoji'] ?: FLAG_FALLBACK, 'kopfflagge'),
    h((string) $unit['course_name']),
    h((string) $unit['title']),
    h((string) $unit['title']),
);

teacher_head($unit['title'], $user, sprintf(
    '<a class="btn small secondary" href="%s" '
    . 'title="Die Ansicht, die deine Klasse sieht">'
    . '<span aria-hidden="true">&#128065;</span> So sieht es die Klasse</a>',
    h(url('/') . '#/unit/' . $unitId),
), $titelHtml, (int) $unit['course_id']);

teacher_flash_render();
?>

<?php
/*
 * Das Formular zum Titel liegt neben der Ueberschrift: In HTML darf ein
 * <form> nicht in einer <h1> stehen, das Feld gehoert ueber form= dazu.
 */
?>
<form method="post" id="titelform" hidden>
    <?= teacher_csrf_field() ?>
    <input type="hidden" name="unit_id" value="<?= $unitId ?>">
</form>

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

<?php
/*
 * "Von Hand" steht in der Adresse, nicht nur im Skript.
 *
 * Der Knopf unten ist ein Link auf dieselbe Seite mit ?vonhand=1. Ohne
 * JavaScript laedt sie neu und die Zeile steht da; mit JavaScript faengt
 * das Skript den Klick ab und blendet sie ein, ohne zu laden. Ein Zustand,
 * zwei Wege dorthin.
 */
$vonHand = isset($_GET['vonhand']);
?>

<h2>Freigabe</h2>

<?php
/*
 * Ohne Vokabeln keine Tabelle.
 *
 * Sie stand hier einmal auch dann, weil die Anlegezeile in ihr wohnte -
 * ein Tabellengeruest mit einem Kopf und nichts darunter. Seit die drei
 * Wege unter der Tabelle stehen, braucht es das nicht mehr: Ein Satz
 * sagt, was los ist, und darunter steht, was zu tun ist.
 */
?>
<?php if ($gesamt === 0): ?>
    <?= teacher_leer(
        'Diese Lerneinheit hat noch keine Vokabeln &ndash; die Klasse sieht '
        . 'sie als leer. Darunter stehen die drei Wege, sie zu f&uuml;llen.',
    ) ?>
<?php endif; ?>

<?php if ($gesamt > 0): ?>

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
<?php endif; /* $gesamt > 0 */ ?>

<?php
/*
 * Die Tabelle steht auch fuer eine leere Lerneinheit da, sobald jemand
 * "von Hand" gewaehlt hat: Dann besteht sie aus dem Kopf und der
 * Anlegezeile, und genau das ist der Ort, an dem die erste Vokabel
 * entsteht.
 */
?>
<?php if ($gesamt > 0 || $vonHand): ?>

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
 *
 * Drei Spalten, mehr nicht: das fremde Wort, das deutsche, die Handgriffe.
 * Die laufende Nummer stand einmal davor und die Zahl der Lueckensaetze
 * dahinter - am Telefon kostete beides die Breite, die die Woerter
 * brauchen, und keines davon sagte etwas, das nicht anderswo steht: wie
 * viel freigegeben ist, sagt die Blase am Balken, und wie viele Saetze
 * fehlen, der Knopf "Saetze nachtragen" darueber.
 */
?>
<table class="data release" id="freigabe" data-released="<?= $frei ?>">
    <thead>
        <?php
        /*
         * Im Kopf steht die Sprache, nicht das Wort "Fremdsprache".
         *
         * Welche Spalte welche ist, weiss man ohnehin - aber "Englisch" und
         * "Deutsch" mit ihren Fahnen davor sagen es, ohne dass man es sich
         * sagen muss. Und sie sind kuerzer: "FREMDSPRACHE" ist ein Wort ohne
         * Bruchstelle und passte bei 320 px gerade eben.
         *
         * Die deutsche Seite heisst immer Deutsch - term_native ist in
         * dieser Anwendung nicht verhandelbar.
         */
        ?>
        <tr>
            <th><?= flag_html($unit['flag_emoji'] ?: FLAG_FALLBACK, 'kopfflagge') ?><?=
                h((string) $unit['language_name']) ?></th>
            <th><?= flag_html("\u{1F1E9}\u{1F1EA}", 'kopfflagge') ?>Deutsch</th>
            <th class="actions"></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($vokabeln as $i => $v): ?>
        <?php $istFrei = $i < $frei; ?>
        <tr class="<?= $istFrei ? 'released' : 'locked' ?>" data-pos="<?= $i + 1 ?>">
            <td>
                <strong data-wort><?= h($v['term_foreign']) ?></strong>
                <input type="text" name="edit_f" value="<?= h($v['term_foreign']) ?>"
                       form="vokabel<?= (int) $v['id'] ?>" maxlength="255" hidden>
            </td>
            <td>
                <span data-wort><?= h($v['term_native']) ?></span>
                <input type="text" name="edit_n" value="<?= h($v['term_native']) ?>"
                       form="vokabel<?= (int) $v['id'] ?>" maxlength="255" hidden>
            </td>
            <td class="actions">
                <button class="iconaction quiet js-hide nurbild" name="release"
                        value="<?= $i + 1 ?>" form="releaseform" title="Bis hier freigeben">
                    <span aria-hidden="true">&#128275;</span><span class="nurvorlesen">Bis hier freigeben</span>
                </button>
                <button class="iconaction quiet nurbild" data-edit="<?= (int) $v['id'] ?>"
                        type="button" title="Diese Vokabel ändern">
                    <span aria-hidden="true">&#9999;&#65039;</span><span class="nurvorlesen">Ändern</span>
                </button>
                <button class="iconaction primary nurbild" form="vokabel<?= (int) $v['id'] ?>"
                        name="save_vocab" value="<?= (int) $v['id'] ?>"
                        data-save="<?= (int) $v['id'] ?>" title="Änderung speichern" hidden>
                    <span aria-hidden="true">&#10003;</span><span class="nurvorlesen">Sichern</span>
                </button>
                <button class="iconaction danger nurbild" form="vokabel<?= (int) $v['id'] ?>"
                        name="delete_vocab" value="<?= (int) $v['id'] ?>"
                        title="Diese Vokabel löschen"
                        data-confirm="&bdquo;<?= h($v['term_foreign']) ?>&ldquo; löschen? Die Lückensätze dazu und der Lernstand aller Kinder daran verschwinden mit.">
                    <span aria-hidden="true">&#128465;&#65039;</span><span class="nurvorlesen">Löschen</span>
                </button>
            </td>
        </tr>
    <?php endforeach; ?>

    <?php
    /*
     * Die Anlegezeile - eine Zusatzzeile unter den Vokabeln, keine
     * Vokabelzeile: kein data-pos, keine released/locked-Klasse, damit der
     * Balken sie nicht mitzaehlt.
     *
     * Sie stand eine Weile am Knopf unter der Tabelle. Dort war sie weit
     * weg von dem, was entsteht - man tippt eine Vokabel und sieht sie
     * zwei Bildschirme hoeher erscheinen. Hier steht sie, wo die naechste
     * Zeile hinkommt.
     */
    ?>
    <tr class="newrow" id="handzeile"<?= $vonHand ? '' : ' hidden' ?>>
        <td>
            <input type="text" name="new_f" form="neueVokabel" maxlength="255"
                   placeholder="apple" autocomplete="off"
                   aria-label="<?= h((string) $unit['language_name']) ?>">
        </td>
        <td>
            <input type="text" name="new_n" form="neueVokabel" maxlength="255"
                   placeholder="Apfel" autocomplete="off" aria-label="Deutsch">
        </td>
        <td class="actions">
            <button class="iconaction primary nurbild" form="neueVokabel"
                    name="add_vocab" value="1" title="Vokabel hinzufügen">
                <span aria-hidden="true">+</span><span class="nurvorlesen">Hinzufügen</span>
            </button>
        </td>
    </tr>
    <?php
    /*
     * Und eine Zeile fuer den Fall, dass etwas nicht klappt - eine Vokabel,
     * die schon drinsteht, oder eine Antwort, die nicht ankam. Ein alert()
     * waere hier falsch: Es hielte den Zug an, um etwas zu sagen, das
     * danebenpasst.
     */
    ?>
    <tr class="newrow handfehler" id="handfehler" hidden>
        <td colspan="3"></td>
    </tr>
    </tbody>
</table>

<form method="post" id="neueVokabel"
      action="<?= h(teacher_url('unit.php') . '?id=' . $unitId) ?>">
    <?= teacher_csrf_field() ?>
    <input type="hidden" name="unit_id" value="<?= $unitId ?>">
</form>

<form method="post" id="releaseform">
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

<?php if ($gesamt > 0): ?>
<p class="tiny muted">
    Die Lückensätze entstehen schon beim Einlesen, für die ganze Einheit.
    Die Freigabe entscheidet also nicht, wofür bezahlt wird, sondern nur,
    was die Klasse zu sehen bekommt &ndash; Vokabeln wie Lückensätze.
    Zurücknehmen kostet nichts: Die Sätze bleiben, und der Lernstand der
    Kinder ist beim nächsten Freigeben wieder da.
</p>
<?php endif; ?>
<?php endif; /* $gesamt > 0 || $vonHand */ ?>

<h2>Vokabeln zur Lerneinheit hinzufügen</h2>

<?php
/*
 * Drei Wege, eine Lerneinheit zu fuellen - nebeneinander, weil sie
 * gleichwertig sind.
 *
 * "Von Hand" oeffnet keine Felder an dieser Stelle, sondern eine
 * Zusatzzeile unter der Tabelle - dort, wo die neue Vokabel gleich stehen
 * wird. Als Link auf ?vonhand=1: Ohne JavaScript laedt die Seite neu und
 * die Zeile steht da, mit JavaScript blendet das Skript sie ein.
 */
?>
<div class="erweitern">
    <a class="card erweiternkarte" id="vonHand"
       href="<?= h(teacher_url('unit.php') . '?id=' . $unitId . '&vonhand=1#handzeile') ?>">
        <span class="cflag">&#9999;&#65039;</span>
        <span class="wahltext">
            <strong>Vokabel manuell hinzufügen</strong>
            <span class="tiny muted">Eine Zeile unter der Tabelle</span>
        </span>
    </a>

    <a class="card erweiternkarte" href="<?= h($importUrl) ?>">
        <span class="cflag">&#128193;</span>
        <span class="wahltext">
            <strong>Vokabeln aus Dateisystem hochladen</strong>
            <span class="tiny muted">Fotos einer Buchseite auswählen</span>
        </span>
    </a>

    <button class="card erweiternkarte" type="button" data-handoff>
        <span class="cflag">&#128241;</span>
        <span class="wahltext">
            <strong>Vokabeln mit Smartphone fotografieren</strong>
            <span class="tiny muted">QR-Code scannen, direkt weiterarbeiten</span>
        </span>
    </button>
</div>

<?php
/*
 * Das Fenster mit dem Code - dasselbe wie im Kurs.
 *
 * Es steht leer im HTML und wird erst gefuellt, wenn jemand darauf drueckt:
 * Die Marke darin ist eine Anmeldung, und die soll nicht auf Vorrat
 * entstehen und zehn Minuten lang auf einem unbeaufsichtigten Bildschirm
 * liegen.
 */
?>
<dialog id="handoff" class="qrdialog"
        data-url="<?= h(teacher_url('handoff.php')) ?>"
        data-course="<?= (int) $unit['course_id'] ?>"
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

<?php
/*
 * Umbenannt wird oben in der Ueberschrift, nicht hier unten.
 *
 * Hier stand einmal eine zweite Zeile mit demselben Titel darin - dieselbe
 * Sache an zwei Stellen, und die untere zwei Bildschirme von der oberen
 * entfernt. Uebrig bleibt das Loeschen.
 */
?>
<h2>Diese Lerneinheit</h2>

<?php
/*
 * Zugeklappt, und das ist der Punkt: Loeschen ist nichts, worueber man
 * stolpert. Wer es sucht, findet es; wer die Seite ueberfliegt, nicht.
 *
 * Ohne Passwortabfrage - anders als beim Kurs. Eine Lerneinheit ist eine
 * Buchseite, kein Schuljahr; die Rueckfrage nennt die Zahlen, und das
 * reicht als Zaesur.
 */
?>
<details class="card">
    <summary style="cursor:pointer;font-weight:600">
        Diese Lerneinheit löschen
    </summary>

    <div class="notice bad" style="margin-top:14px">
        <strong>Das lässt sich nicht rückgängig machen.</strong>
        Mit der Lerneinheit gehen ihre <?= $gesamt ?> Vokabeln, die
        Lückensätze dazu und alles, was die Kinder daran gelernt haben.
    </div>

    <p class="tiny muted">
        Soll die Klasse nur aufhören, damit zu arbeiten, ist „Nichts
        freigeben" das mildere Mittel: Die Lerneinheit verschwindet aus der
        App, Unterlagen und Lernstände bleiben.
    </p>

    <form method="post">
        <?= teacher_csrf_field() ?>
        <input type="hidden" name="unit_id" value="<?= $unitId ?>">
        <button class="btn small danger" name="delete_unit" value="1"
                data-confirm="&bdquo;<?= h((string) $unit['title']) ?>&ldquo; mit <?= $gesamt ?> Vokabeln, den Lückensätzen und dem Lernstand aller Kinder daran endgültig löschen?">
            Endgültig löschen
        </button>
    </form>
</details>


<?php teacher_foot(); ?>
