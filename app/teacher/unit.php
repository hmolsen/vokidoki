<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
// Fuer HANDOFF_TTL im Fenster mit dem QR-Code - der Sprung ans Telefon
// steht jetzt auch hier, nicht nur im Kurs.
require_once __DIR__ . '/../lib/handoff.php';
require_once __DIR__ . '/../lib/sentences.php';
require_once __DIR__ . '/../lib/vocab.php';
// Fuer word_type_clean() beim Anhaengen erkannter Vokabeln.
require_once __DIR__ . '/../lib/wordtypes.php';

/*
 * Eine Lerneinheit aus Sicht der Lehrkraft - und die Stelle, an der
 * freigegeben wird.
 *
 * Freigegeben wird portionsweise: Eingelesen wird eine ganze Unit auf
 * einmal - das Fotografieren lohnt sich seitenweise -, aufgemacht aber nur
 * das, was gerade dran ist.
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
     * Freigeben heisst: Die Klasse sieht es - und kann es ueben.
     *
     * Zum Ueben gehoert der Lueckensatz, also entsteht er hier. Was hinter
     * der Marke wartet, braucht keinen: Eine Vokabel, die gerade von Hand
     * dazugekommen ist, kommt erst dran, wenn jemand sie aufmacht.
     *
     * Zurueckgenommen wird nichts erzeugt - was zu ist, wird nicht geuebt.
     */
    if ($bis <= $vorher) {
        teacher_flash($bis === 0
            ? 'Die Freigabe ist zurückgenommen. Gelernt bleibt gelernt - '
              . 'beim nächsten Freigeben ist der Stand wieder da.'
            : sprintf('Freigabe auf %d Vokabeln zurückgenommen.', $bis));
        teacher_redirect($zurueck);
    }

    $fehlen = vocab_without_sentences($unitId);

    if ($fehlen === 0
        || budget_block_reason((int) $user['id']) !== null
        || !sentence_claim($unitId)) {
        teacher_flash(sprintf('%d Vokabeln freigegeben.', $bis));
        teacher_redirect($zurueck);
    }

    /*
     * Antworten, dann weiterarbeiten.
     *
     * Die Saetze zu zwanzig Vokabeln dauern eine halbe Minute, und solange
     * soll niemand auf eine leere Seite sehen. Die Seite, auf der die
     * Lehrkraft landet, sagt "entsteht gerade" und laedt sich von selbst
     * nach.
     */
    teacher_flash(sprintf(
        '%d Vokabeln freigegeben. Die Lückensätze dazu entstehen gerade.', $bis));
    teacher_redirect_and_continue($zurueck);

    set_time_limit(900);
    generate_sentences_tracked($unitId);
    exit;
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

    /*
     * Derselbe Knopf, zwei Arten zu warten.
     *
     * Als Formular: antworten, weiterleiten, und waehrenddessen im
     * Hintergrund die Saetze erzeugen. Als fetch aus dem Skript: gar nicht
     * erst antworten, bevor es fertig ist - der Aufrufer wartet ohnehin
     * nicht auf diese Antwort, er hat sie schon weggeworfen.
     *
     * Der Unterschied ist nicht Geschmack. "Antworten und weiterarbeiten"
     * setzt voraus, dass die Antwort den Browser wirklich verlaesst,
     * bevor der Vorgang endet - und genau das haelt nicht ueberall: Legt
     * der Webserver eine Komprimierung darueber, ersetzt er die
     * Laengenangabe durch eine stueckweise Uebertragung, und der Browser
     * wartet trotzdem bis zum Schluss. Deshalb wartet hier niemand mehr
     * auf eine Antwort, die noch Arbeit hinter sich herzieht.
     */
    $perSkript = unit_will_json();

    if (vocab_without_sentences($unitId) === 0) {
        if ($perSkript) {
            unit_json(['ok' => true, 'erzeugt' => 0, 'grund' => 'nichts offen']);
        }
        teacher_flash('Es fehlt kein Satz.');
        teacher_redirect($zurueck);
    }

    $blocked = budget_block_reason((int) $user['id']);
    if ($blocked !== null) {
        if ($perSkript) {
            unit_json(['ok' => false, 'error' => $blocked], 429);
        }
        teacher_flash($blocked, 'bad');
        teacher_redirect($zurueck);
    }

    if (!sentence_claim($unitId)) {
        if ($perSkript) {
            unit_json(['ok' => true, 'erzeugt' => 0, 'grund' => 'laeuft schon']);
        }
        teacher_flash('Es läuft schon ein Satzlauf. Bitte abwarten.', 'bad');
        teacher_redirect($zurueck);
    }

    if ($perSkript) {
        /*
         * Das Skript hat diese Anfrage abgeschickt und sich nicht gemerkt.
         * Sie darf also so lange dauern, wie sie dauert - aber sie darf
         * nicht abbrechen, wenn der Tab zugeht, sonst bliebe die Einheit
         * auf "laeuft" stehen.
         */
        ignore_user_abort(true);
        set_time_limit(900);
        generate_sentences_tracked($unitId);
        unit_json(['ok' => true, 'erzeugt' => vocab_without_sentences($unitId) === 0 ? 1 : 0]);
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
 * Beides stand in der Lernansicht, unter "Verwalten", und war fuer eine
 * Lehrkraft der einzige Weg dorthin. Nur ist die Lernansicht das, was
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

/*
 * Erkannte Vokabeln anhaengen.
 *
 * Das Einlesen selbst passiert in api/import.php - dort sitzt das Modell,
 * die Budgetpruefung und die Bildvalidierung. Gespeichert wird aber hier,
 * und zwar aus einem Grund: Diese Seite prueft auf die SCHULE, die API auf
 * die Kursmitgliedschaft. Eine Vertretung soll die Seiten ihrer Kollegin
 * einlesen koennen, und sie tut es an derselben Stelle, an der sie auch
 * von Hand ergaenzt und freigibt.
 *
 * Die Freigabemarke bleibt unberuehrt: Frisch Eingelesenes ist fuer die
 * Klasse zunaechst unsichtbar. Das ist der ganze Sinn dieser Seite.
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['add_scanned'])) {
    teacher_csrf_check();

    $roh = json_decode((string) ($_POST['entries'] ?? ''), true);
    if (!is_array($roh) || $roh === []) {
        unit_json(['ok' => false, 'error' => 'Es gibt nichts zu speichern.'], 422);
    }
    if (count($roh) > 500) {
        unit_json(['ok' => false,
                   'error' => 'Eine Lerneinheit kann höchstens 500 Vokabeln haben.'], 422);
    }

    $paare = [];
    foreach ($roh as $zeile) {
        if (!is_array($zeile)) {
            continue;
        }
        $f = trim((string) ($zeile['foreign'] ?? ''));
        $n = trim((string) ($zeile['native'] ?? ''));
        if ($f === '' || $n === '') {
            continue;
        }
        $notiz = trim((string) ($zeile['note'] ?? ''));
        $paare[] = [
            'foreign'   => $f,
            'native'    => $n,
            'note'      => $notiz === '' ? null : $notiz,
            // Die Wortart bestimmt das Modell; sie wird hier nur durchgereicht.
            'word_type' => word_type_clean($zeile['word_type'] ?? null),
            // Was die KI an einem Lesefehler berichtigt hat - die Zeile steht
            // danach markiert in der Tabelle, bis die Lehrkraft sie prüft.
            'correction' => ($k = trim((string) ($zeile['correction'] ?? ''))) === '' ? null : $k,
        ];
    }

    if ($paare === []) {
        unit_json(['ok' => false, 'error' => 'Keine vollständigen Vokabelpaare dabei.'], 422);
    }

    /*
     * vocab_append() ueberspringt, was schon drinsteht - zweimal dieselbe
     * Buchseite fotografiert ist ein Versehen, keine Absicht. Deshalb
     * werden zwei Zahlen zurueckgemeldet und nicht eine.
     */
    $dazu    = vocab_append($unitId, $paare, $unit['code'] ?? null);
    $doppelt = count($paare) - $dazu;

    /*
     * Und die Lueckensaetze? Hier nicht.
     *
     * Sie entstehen an genau einer Stelle: beim Knopf "Saetze nachtragen",
     * der danebensteht und die fehlende Zahl nennt. Das ist dieselbe Regel
     * wie beim Einlesen ueber die App - auch dort entsteht fuer eine
     * Lehrkraft zunaechst kein Satz, weil sie erst spaeter freigibt.
     *
     * Und es ist die Regel, die haelt: Eine Antwort, hinter der noch eine
     * halbe Minute Arbeit haengt, kommt nicht ueberall an. Sie braucht eine
     * Laengenangabe, die unterwegs stehenbleibt, und eine Komprimierung im
     * Webserver ersetzt die durch eine stueckweise Uebertragung - dann
     * wartet der Browser bis zum Schluss und meldet irgendwann "keine
     * Verbindung", obwohl alles gespeichert ist.
     */
    unit_json(['ok' => true, 'dazu' => $dazu, 'doppelt' => $doppelt,
               'offen' => vocab_without_sentences($unitId)]);
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
     * Und hier hoert diese Anfrage auf.
     *
     * Sie hat einmal mehr getan: antworten, und dann noch den Lueckensatz
     * zur neuen Vokabel erzeugen - eine halbe Minute Arbeit hinter einer
     * Antwort, die angeblich schon draussen war. "Angeblich": Damit das
     * traegt, muss die Antwort den Browser wirklich verlassen, bevor der
     * Vorgang endet, und dafuer braucht es eine Laengenangabe, die
     * unterwegs auch stehenbleibt. Legt der Webserver eine Komprimierung
     * darueber, ersetzt er sie durch eine stueckweise Uebertragung, und
     * der Browser wartet auf das Ende des Stroms - also bis der Satz
     * fertig ist. Bei einem Zeitablauf dazwischen las sich das als "die
     * Antwort kam nicht an", obwohl die Vokabel laengst drinstand. Wer das
     * sah, tippte sie noch einmal.
     *
     * Jetzt zieht diese Antwort nichts mehr hinter sich her. Die Saetze
     * holt eine zweite, eigene Anfrage - das Skript schickt sie ab und
     * wartet nicht darauf, und ohne Skript holt sie der Knopf "Saetze
     * nachtragen", der die fehlende Zahl ohnehin nennt.
     */
    $offen = vocab_without_sentences($unitId);

    if (unit_will_json()) {
        unit_json(['ok' => true, 'vokabel' => $neueVokabel, 'offen' => $offen]);
    }

    teacher_flash(sprintf('„%s" ist dabei.', $f));
    teacher_redirect($zurueck);
}

/*
 * "Passt" an einer Zeile, die die KI beim Einlesen berichtigt hat.
 *
 * Die Markierung soll nicht ewig stehen: Wer die Zeile angesehen hat und
 * sie richtig findet, nimmt sie weg. Wer sie ändert, ebenso - das erledigt
 * vocab_update().
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['check_ok'])) {
    teacher_csrf_check();

    $vokabelId = (int) $_POST['check_ok'];
    if ((int) qv('SELECT COUNT(*) FROM vocab WHERE id = ? AND unit_id = ?',
                 [$vokabelId, $unitId]) === 1) {
        vocab_check_done($vokabelId);
    }
    if (unit_will_json()) {
        unit_json(['ok' => true]);
    }
    teacher_redirect($zurueck . '#zeile' . $vokabelId);
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
    'SELECT v.id, v.position, v.term_foreign, v.term_native, v.word_type, v.check_note
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

/*
 * Neben der Ueberschrift steht kein Knopf mehr - siehe course.php. Der
 * Wechsel in die Lernansicht steht im Zahnrad, ueber der Farbwahl.
 */
teacher_head($unit['title'], $user, '', $titelHtml, (int) $unit['course_id']);

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
        <p>
            Bei den Lückensätzen ist etwas schiefgegangen:
            <?= h((string) ($zustand['error'] ?? 'unbekannter Fehler')) ?>
        </p>
        <?php
        /*
         * Der einzige Ort, an dem noch von Hand nachgeholt wird.
         *
         * Im Regelfall entstehen die Sätze beim Freigeben. Bricht ein Lauf
         * ab, würde ohne diesen Knopf erst die nächste Freigabe es wieder
         * versuchen - und wer schon alles aufgemacht hat, hätte gar keine
         * mehr.
         */
        ?>
        <form method="post">
            <?= teacher_csrf_field() ?>
            <input type="hidden" name="unit_id" value="<?= $unitId ?>">
            <button class="btn small secondary" name="catch_up" value="1">
                Noch einmal versuchen
            </button>
        </form>
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

/*
 * Wie viele Vokabeln gerade eingelesen wurden.
 *
 * Nach dem Erkennen laedt die Seite neu - anders als beim Tippen, wo jede
 * Vokabel einzeln nachwaechst. Hier aendern sich zu viele Dinge auf
 * einmal: die Zahl im Satz darueber, der Knopf "Alles freigeben", die
 * Zeilen, an denen der Freigabebalken misst. Eine Seite, die all das
 * richtig hat, ist ehrlicher als eine, die es an sechs Stellen nachtraegt.
 *
 * Damit trotzdem sichtbar ist, was neu ist, stehen die letzten $neu
 * Zeilen gruen da und verblassen - dasselbe Zeichen wie beim Tippen.
 */
$neu = max(0, min($gesamt, (int) ($_GET['neu'] ?? 0)));
?>

<?php if ($neu > 0): ?>
    <div class="notice info">
        <strong><?= $neu ?> Vokabeln sind dazugekommen</strong> &ndash; sie
        stehen unten am Ende der Liste. Das Einlesen macht ein Sprachmodell,
        und das verliest sich: Bitte einmal durchsehen und, wo nötig,
        über das Stift-Zeichen berichtigen, <em>bevor</em> du freigibst.
    </div>
<?php endif; ?>

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
 * Die beiden Knoepfe stehen NICHT mehr ueber der Tabelle, sondern an ihren
 * Enden - siehe weiter unten in <thead> und <tfoot>.
 *
 * Der Grund ist der Balken dazwischen: "Nichts freigeben" schiebt ihn ganz
 * nach oben, "Alles freigeben" ganz nach unten. Als Knopfreihe ueber der
 * Tabelle sagten die beiden nichts darueber, wohin sie greifen; an den
 * Enden der Tabelle sind sie die beiden Endstellungen des Balkens, den man
 * dazwischen von Hand zieht. Man sieht die Strecke, auf der sie wirken.
 */
?>
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
 * viel freigegeben ist, sagt die Blase am Balken, und die Saetze entstehen
 * beim Freigeben von selbst.
 */
?>
<?php
/*
 * Was die KI beim Einlesen berichtigt hat, steht gelb markiert - und hier
 * gezählt, damit niemand die Tabelle nach den gelben Zeilen absuchen muss.
 */
$zuPruefen = count(array_filter($vokabeln, static fn (array $v): bool =>
    (string) ($v['check_note'] ?? '') !== ''));
?>
<?php if ($zuPruefen > 0): ?>
<p class="notice warn pruefhinweis-kopf">
    <strong><span data-pruefzahl><?= $zuPruefen === 1 ? 'Eine Vokabel' : $zuPruefen . ' Vokabeln' ?></span>
    hat die KI beim Einlesen berichtigt.</strong> Die Texterkennung hatte sie anders gelesen. Sie
    sind gelb markiert &ndash; bitte besonders genau prüfen, dann „Passt“ drücken oder
    die Vokabel ändern.
</p>
<?php endif; ?>
<table class="data release" id="freigabe" data-released="<?= $frei ?>">
    <thead>
        <?php
        /*
         * Ganz oben "Nichts freigeben" - die obere Endstellung des Balkens.
         *
         * Als <td> und nicht als <th>: Die Kopfzellen kleben beim Rollen
         * oben fest (position: sticky), und das soll fuer diesen Knopf
         * gerade nicht gelten. Ein Knopf, der eine ganze Lerneinheit
         * zumacht, muss nicht die ganze Zeit in Reichweite haengen - er
         * gehoert an seinen Platz am Anfang der Liste.
         *
         * Er haengt per form= am Formular unter der Tabelle: Ein <form>
         * kann in HTML nicht um Tabellenzeilen herumstehen. Denselben Weg
         * gehen die Knoepfe je Zeile schon.
         */
        ?>
        <?php if ($gesamt > 0): ?>
        <tr class="mengen">
            <td colspan="3">
                <button class="mengenknopf zu" name="release" value="0" form="releaseform"
                        <?= $frei === 0 ? 'disabled title="Es ist nichts freigegeben."' : '' ?>
                        data-confirm="Die ganze Lerneinheit wieder zumachen? Die Klasse sieht sie dann als leer. Gelernt bleibt gelernt.">
                    <span aria-hidden="true">&#128274;</span> Nichts freigeben
                </button>
            </td>
        </tr>
        <?php endif; ?>
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
        <?php
        $istFrei   = $i < $frei;
        $istFrisch = $neu > 0 && $i >= $gesamt - $neu;
        $pruefen   = (string) ($v['check_note'] ?? '');
        $klassen   = ($istFrei ? 'released' : 'locked') . ($istFrisch ? ' frisch' : '')
                   . ($pruefen !== '' ? ' pruefen' : '');
        // Ein Anker auf der ersten frischen Zeile: Das Skript springt nach
        // dem Einlesen dorthin, statt oben auf der Seite zu landen.
        $anker     = $istFrisch && $i === $gesamt - $neu ? ' id="frisch"' : '';
        ?>
        <tr class="<?= $klassen ?>" data-pos="<?= $i + 1 ?>"<?= $anker ?>>
            <td id="zeile<?= (int) $v['id'] ?>">
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
        <?php
        /*
         * Was die KI berichtigt hat, in einer eigenen Zeile über die ganze
         * Breite - direkt unter der Vokabel.
         *
         * Es stand einmal klein unter dem fremden Wort, in der linken
         * Spalte eingezwängt, und "Passt" schob die Stifte der Zeile aus
         * der Flucht. Ohne data-pos: Der Freigabebalken zählt nur
         * Vokabelzeilen, diese gehört zu der darüber.
         */
        ?>
        <?php if ($pruefen !== ''): ?>
        <tr class="pruefzeile" data-pruefzeile="<?= (int) $v['id'] ?>">
            <td colspan="3"><div class="pruefinhalt">
                <span class="pruefnotiz">
                    <span aria-hidden="true">&#9888;&#65039;</span>
                    Von der KI berichtigt: <strong><?= h($pruefen) ?></strong>
                    &ndash; bitte genau prüfen
                </span>
                <button class="btn small passt" form="vokabel<?= (int) $v['id'] ?>"
                        name="check_ok" value="<?= (int) $v['id'] ?>" data-passt="<?= (int) $v['id'] ?>"
                        title="Geprüft - die Vokabel stimmt so">
                    <span aria-hidden="true">&#10003;</span> Passt
                </button>
            </div></td>
        </tr>
        <?php endif; ?>
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

    <?php
    /*
     * Und ganz unten "Alles freigeben" - die untere Endstellung des Balkens.
     *
     * Unter der Anlegezeile, nicht darueber: Wer gerade von Hand eine
     * Vokabel angefuegt hat, will sie mit freigeben. Stuende der Knopf
     * darueber, laege die frisch getippte Zeile ausserhalb dessen, worauf
     * er zu zeigen scheint - und genau diese Frage ("ist die neue mit
     * dabei?") soll er nicht aufwerfen.
     *
     * Als <tfoot> nach dem <tbody> im Markup: So steht er auch ohne
     * Stilblatt an der richtigen Stelle.
     */
    ?>
    <?php if ($gesamt > 0): ?>
    <tfoot>
        <tr class="mengen">
            <td colspan="3">
                <button class="mengenknopf auf" name="release" value="<?= $gesamt ?>"
                        form="releaseform"
                        <?= $alles ? 'disabled title="Es ist schon alles freigegeben."' : '' ?>
                        data-confirm="Alle <?= $gesamt ?> Vokabeln freigeben? Die Klasse sieht dann die ganze Lerneinheit.">
                    <span aria-hidden="true">&#128275;</span> Alles freigeben
                </button>
            </td>
        </tr>
    </tfoot>
    <?php endif; ?>
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
    Die Freigabe entscheidet also nur, was die Klasse zu sehen bekommt
    &ndash; Vokabeln wie Lückensätze. Zurücknehmen lässt sich jederzeit:
    Die Sätze bleiben, und der Lernstand der Kinder ist beim nächsten
    Freigeben wieder da.
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

    <?php
    /*
     * Aus Dateien: ein Link auf die Einleseansicht der App - und mit
     * JavaScript faengt das Skript den Klick ab und oeffnet stattdessen
     * gleich den Dateidialog. Ein Zwischenschritt weniger: Wer hier
     * drueckt, will Dateien auswaehlen, nicht erst eine Seite sehen, auf
     * der ein Knopf steht, mit dem man Dateien auswaehlt.
     */
    ?>
    <a class="card erweiternkarte" id="ausDateien" href="<?= h($importUrl) ?>">
        <span class="cflag">&#128193;</span>
        <span class="wahltext">
            <strong>Vokabeln aus Dateisystem hochladen</strong>
            <span class="tiny muted">Fotos von Buchseiten auswählen</span>
        </span>
    </a>

    <?php
    /*
     * Und der dritte Weg, der zwei ist.
     *
     * Am Rechner ist die Kamera woanders - also ein QR-Code, der genau auf
     * diese Seite fuehrt; wer ihn scannt, steht am Telefon hier und
     * fotografiert dort weiter. Am Telefon waere derselbe Code Unsinn:
     * Dort ist die Kamera in der Hand, und der Knopf oeffnet sie.
     *
     * Beide Karten stehen im HTML, das Skript blendet die falsche aus.
     * Welche das ist, weiss nur der Browser - ein Geraet am Kennzeichen
     * der Anfrage zu erraten geht seit Jahren schief.
     */
    ?>
    <button class="card erweiternkarte" type="button" data-handoff id="perQr">
        <span class="cflag">&#128241;</span>
        <span class="wahltext">
            <strong>Mit dem Smartphone fotografieren</strong>
            <span class="tiny muted">QR-Code scannen &ndash; das Telefon
                landet genau hier</span>
        </span>
    </button>

    <button class="card erweiternkarte" type="button" id="perKamera" hidden>
        <span class="cflag">&#128247;</span>
        <span class="wahltext">
            <strong>Buchseite fotografieren</strong>
            <span class="tiny muted">Öffnet die Kamera</span>
        </span>
    </button>
</div>

<?php
/*
 * Die beiden Dateifelder liegen versteckt daneben, nicht in den Karten:
 * Ein <input type="file"> laesst sich nicht so gestalten, dass es wie eine
 * Karte aussieht, und ein Klick darauf ist ohnehin das, was die Karte
 * ausloest. Ohne JavaScript ruehrt sie niemand an.
 */
?>
<input type="file" id="bildwahl" accept="image/*" multiple hidden
       aria-hidden="true" tabindex="-1">
<input type="file" id="kamerawahl" accept="image/*" capture="environment" multiple hidden
       aria-hidden="true" tabindex="-1">

<?php
/*
 * Die Ablage: was ausgewaehlt ist, bevor es zum Modell geht.
 *
 * Buchseiten sehen einander aehnlich - zwei Spalten, dieselbe Schrift, oft
 * dieselbe Ueberschrift. Deshalb laesst sich hier jede Seite gross
 * ansehen, in der Reihenfolge verschieben und wieder entfernen, bevor
 * etwas eingelesen wird. Sie steht leer im HTML und fuellt sich erst,
 * wenn jemand Dateien gewaehlt hat.
 */
?>
<section class="stapel" id="stapel" hidden
         data-unit="<?= $unitId ?>"
         data-language="<?= (int) $unit['language_id'] ?>"
         data-api="<?= h(url('/api/import.php')) ?>"
         data-bilder="<?= h(url('/views/bilder.js') . '?v=' . app_version()) ?>"
         data-lesevoki="<?= h(url('/lesevoki.js') . '?v=' . app_version()) ?>"
         data-ocr="<?= h(url('/ocr.js') . '?v=' . app_version()) ?>"
         data-sprachcode="<?= h((string) ($unit['code'] ?? '')) ?>"
         data-ziel="<?= h(teacher_url('unit.php') . '?id=' . $unitId) ?>"
         data-csrf="<?= h(teacher_csrf_token()) ?>">
    <h3>Ausgew&auml;hlte Seiten</h3>

    <p class="notice warn" id="stapelZuviel" hidden></p>

    <ol class="seiten" id="seiten"></ol>

    <div class="buttonrow">
        <button class="btn small" type="button" id="erkennen">
            <span data-knopftext>Vokabeln erkennen</span>
        </button>
        <button class="btn small secondary" type="button" id="stapelWeg">
            Auswahl verwerfen
        </button>
    </div>

    <p class="notice bad" id="stapelFehler" hidden></p>

    <p class="tiny muted">
        Die Seiten werden auf diesem Gerät gelesen &ndash; die Fotos verlassen es
        nicht. Nur der erkannte Text geht an ein Sprachmodell, das ihn zu
        Vokabeln ordnet und Lesefehler berichtigt; was es berichtigt hat, steht
        danach gelb markiert. Bitte sieh die neuen Vokabeln durch, bevor du sie
        freigibst. Den Titel der Lerneinheit gibst du oben selbst ein.
    </p>
</section>

<?php
/*
 * Die Lupe. Ein <dialog> und kein eigenes Fenster: Das Bild liegt im
 * Browser, nicht auf dem Server - es gibt keine Adresse, die sich oeffnen
 * liesse.
 */
?>
<dialog id="lupe" class="lupe">
    <img id="lupeBild" alt="">
    <form method="dialog">
        <button class="btn small secondary" autofocus>Schließen</button>
    </form>
</dialog>

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
        data-unit="<?= $unitId ?>"
        data-csrf="<?= h(teacher_csrf_token()) ?>">
    <h3>Am Smartphone fotografieren</h3>
    <div class="qrslot" id="handoffSlot"></div>
    <p class="tiny muted" id="handoffHint">
        Code mit der Kamera des Telefons scannen. Du bist dann angemeldet und
        stehst am Telefon auf genau dieser Seite &ndash; dort fotografierst du
        die Buchseiten.
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
