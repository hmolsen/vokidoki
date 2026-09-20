<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

/*
 * Einen Kurs anlegen - in zwei Schritten.
 *
 * Vorher entstand ein Kurs in einer Anlegezeile am Fuss der Kurstabelle
 * EINER Klasse. Wer einen Kurs wollte, musste also erst wissen, dass Kurse
 * in Klassen wohnen, dann die Klassenliste finden, dann die richtige Klasse
 * oeffnen - drei Entscheidungen, von denen nur eine mit dem Kurs zu tun
 * hat. Und einen Kurs ohne Klasse gab es ueber die Oberflaeche gar nicht
 * mehr, obwohl das Datenmodell ihn kann.
 *
 * Jetzt fragt der Assistent das, was wirklich zu entscheiden ist, und zwar
 * eins nach dem anderen:
 *
 *   1. Fuer welche Klasse?  (oder: ohne Klasse)
 *   2. Fuer welche Sprache?
 *
 * Danach steht der Kurs, und man ist da, wo man hinwollte - auf seiner
 * Seite. Der Name ergibt sich aus beidem ("Englisch - 5B"); gefragt wird
 * nur, wenn er schon vergeben ist.
 *
 * Jeder Schritt ist eine eigene Adresse. Das ist nicht Geschmack: Der
 * Zurueck-Knopf des Browsers, ein Lesezeichen und das Neuladen sollen tun,
 * was man von ihnen erwartet - und ohne JavaScript muss es genauso gehen.
 */

$user     = teacher_require();
$schoolId = (int) ($user['school_id'] ?? 0);

if ($schoolId === 0) {
    teacher_flash('Dieses Konto gehört zu keiner Schule. Ohne Schule gibt es '
                . 'keine Kurse - der Betreiber kann das im Admin-Bereich zuordnen.', 'bad');
    teacher_redirect('index.php');
}

/*
 * Welche Klasse ist gewaehlt?
 *
 * Drei Zustaende, und sie sind zu unterscheiden: gar nichts gewaehlt
 * (Schritt 1), eine Klasse (Schritt 2), oder ausdruecklich keine
 * (Schritt 2, Kurs ohne Klasse). Deshalb nicht bloss eine Zahl - "0" und
 * "nicht da" bedeuten hier Verschiedenes.
 */
$gewaehlt = $_GET['klasse'] ?? $_POST['klasse'] ?? null;
$schritt2 = is_string($gewaehlt) && $gewaehlt !== '';
$classId  = (int) $gewaehlt;

$klasse = null;
if ($schritt2 && $classId > 0) {
    $klasse = class_in_school($classId, $schoolId);
    if ($klasse === null) {
        // Dieselbe Meldung wie bei einer Klasse, die es nicht gibt: Wer sie
        // nicht sehen darf, soll nicht erfahren, dass es sie gibt.
        teacher_flash('Diese Klasse gibt es nicht.', 'bad');
        teacher_redirect('neu.php');
    }
}

// ------------------------------------------------ Schritt 1: eine Klasse dazu

/*
 * Eine Klasse laesst sich hier anlegen, statt den Assistenten dafuer zu
 * verlassen. Ohne das waere der erste Schritt fuer eine neue Lehrkraft eine
 * Sackgasse: lauter Klassen, die es noch nicht gibt.
 *
 * Danach geht es gleich weiter zur Sprache - wer eine Klasse anlegt,
 * waehrend er einen Kurs anlegt, will sie auch nehmen.
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['neue_klasse'])) {
    teacher_csrf_check();

    $ergebnis = class_create($schoolId, (string) ($_POST['klassenname'] ?? ''));
    if (is_string($ergebnis)) {
        teacher_flash($ergebnis, 'bad');
        teacher_redirect('neu.php');
    }

    teacher_flash(sprintf('Klasse "%s" angelegt.', $ergebnis['name']));
    teacher_redirect('neu.php?klasse=' . (int) $ergebnis['id']);
}

// ------------------------------------------------ Schritt 2: den Kurs anlegen

$fehler = '';
$name   = trim((string) ($_POST['name'] ?? ''));

/*
 * Die Sprache ist das Signal: Sie kommt entweder aus einer Kachel (ein
 * Absendeknopf, der seinen Namen traegt) oder aus der Auswahlliste. Beides
 * ist dieselbe Entscheidung, also auch derselbe Weg hierher.
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['sprache'])) {
    teacher_csrf_check();

    $sprache = trim((string) $_POST['sprache']);

    if ($sprache === '') {
        $fehler = 'Da ist keine Sprache gewählt.';
    } else {
        /*
         * Flagge und Kuerzel kommen aus der Sprachliste, nicht aus dem
         * Formular: Eine Flagge ist eine Eigenschaft der Sprache und keine
         * Entscheidung, die eine Lehrkraft treffen soll. Steht der Name
         * nicht in der Liste, gibt es die Weltkugel.
         */
        $ergebnis = course_create(
            $user, $sprache, language_flag($sprache),
            $klasse === null ? null : (int) $klasse['id'], $name,
        );

        if (is_string($ergebnis)) {
            $fehler = $ergebnis;
        } else {
            teacher_flash(sprintf('Kurs "%s" angelegt.%s', $ergebnis['name'],
                $klasse === null
                    ? ' Kinder nimmst du unten einzeln auf.'
                    : sprintf(' Die Kinder der Klasse %s sind schon drin.',
                              (string) $klasse['name'])));
            teacher_redirect('course.php?id=' . (int) $ergebnis['id']);
        }
    }

    // Ein Fehler wirft nicht zurueck auf Schritt 1 - die Klasse steht ja
    // schon fest. Schritt 2 wird noch einmal gezeigt, mit der Meldung.
    $schritt2 = true;
}

// ------------------------------------------------------------------ Anzeige

teacher_head($schritt2 ? 'Für welche Sprache?' : 'Für welche Klasse?', $user);
teacher_flash_render();

if ($fehler !== '') {
    printf('<div class="notice bad">%s</div>', h($fehler));
}
?>

<p class="schritt tiny muted">
    <?= $schritt2 ? 'Schritt 2 von 2' : 'Schritt 1 von 2' ?>
    <?= $schritt2
        ? '&middot; danach steht der Kurs'
        : '&middot; danach kommt die Sprache' ?>
</p>

<?php if (!$schritt2): ?>
<?php
/*
 * Schritt 1. Die Klassen als Kacheln - und "ohne Klasse" als eine davon,
 * nicht als Kleingedrucktes darunter: Beides ist ein gueltiger Anfang.
 *
 * Auf jeder Kachel steht, was die Wahl bedeutet. Bei einer Klasse kommen
 * ihre Kinder gleich in den Kurs, ohne Klasse bleibt er leer - das muss
 * man nicht erraten muessen.
 */
$klassen = classes_for_school($schoolId);
?>
<div class="kurskarten">
    <?php foreach ($klassen as $k): ?>
        <a class="card wahlkarte<?= $k['active'] ? '' : ' dim' ?>"
           href="<?= h(teacher_url('neu.php') . '?klasse=' . (int) $k['id']) ?>">
            <span class="cflag">&#128101;</span>
            <span class="wahltext">
                <strong><?= h($k['name']) ?></strong>
                <span class="tiny muted">
                    <?= (int) $k['students'] === 0
                        ? 'noch keine Kinder darin'
                        : sprintf('%d %s kommen mit in den Kurs',
                                  (int) $k['students'],
                                  (int) $k['students'] === 1 ? 'Kind' : 'Kinder') ?>
                    <?= $k['active'] ? '' : '&middot; stillgelegt' ?>
                </span>
            </span>
            <span class="chev" aria-hidden="true">&#8250;</span>
        </a>
    <?php endforeach; ?>

    <a class="card wahlkarte" href="<?= h(teacher_url('neu.php') . '?klasse=0') ?>">
        <?php // Ein Einzelner, keine Gruppe: Hier kommt niemand von selbst mit. ?>
        <span class="cflag">&#128100;</span>
        <span class="wahltext">
            <strong>Kurs ohne Klasse</strong>
            <span class="tiny muted">
                Bleibt leer &ndash; Kinder der Schule nimmst du einzeln auf
            </span>
        </span>
        <span class="chev" aria-hidden="true">&#8250;</span>
    </a>
</div>

<?php
/*
 * Und eine Klasse, die es noch nicht gibt: dieselbe Form wie die
 * Anlegezeile in jeder Tabelle, nur ohne Tabelle darum.
 */
?>
<form method="post" class="card anlegezeile">
    <?= teacher_csrf_field() ?>
    <span class="coursetitle">
        <span class="cflag plus">+</span>
        <input type="text" name="klassenname" placeholder="5B" maxlength="32" required
               aria-label="Name der neuen Klasse">
    </span>
    <button class="btn small secondary" name="neue_klasse" value="1">
        Klasse anlegen
    </button>
</form>

<p class="tiny muted">
    Die Klasse nennst du am besten so, wie sie in der Schule heißt &ndash;
    „5a", „9B" oder „7.2". Der Kurs heißt dann von selbst „Englisch - 9B".
    Die Kinder der Klasse kommen beim Anlegen gleich mit in den Kurs; danach
    sind Klassenliste und Kursliste unabhängig voneinander.
</p>

<?php else: ?>
<?php
/*
 * Schritt 2. Die fuenf Schulsprachen als Kacheln, alles andere in der
 * durchsuchbaren Liste darunter - so war es in der Familien-App, und es
 * stimmt hier genauso: Neunundneunzig von hundert Kursen sind eine dieser
 * fuenf, aber der hundertste muss trotzdem gehen.
 *
 * Jede Kachel ist ein Absendeknopf desselben Formulars und traegt ihren
 * Sprachnamen als Wert. Abgeschickt wird nur der gedrueckte - das kann
 * HTML von sich aus, ohne eine Zeile JavaScript.
 */
$oben = array_values(array_filter(language_choices(),
                                  static fn (array $s): bool => $s['top']));
?>
<form method="post" id="sprachwahl">
    <?= teacher_csrf_field() ?>
    <input type="hidden" name="klasse" value="<?= $classId ?>">

    <div class="kurskarten">
        <?php foreach ($oben as $s): ?>
            <button class="card wahlkarte" name="sprache" value="<?= h($s['name']) ?>">
                <?= flag_html($s['flag'], 'cflag') ?>
                <span class="wahltext">
                    <strong><?= h($s['name']) ?></strong>
                    <span class="tiny muted">
                        heißt dann &bdquo;<?= $klasse === null
                            ? h($s['name'])
                            : h($s['name'] . ' - ' . (string) $klasse['name']) ?>&ldquo;
                    </span>
                </span>
                <span class="chev" aria-hidden="true">&#8250;</span>
            </button>
        <?php endforeach; ?>
    </div>
</form>

<h2>Andere Sprache</h2>

<form method="post" class="card anlegezeile">
    <?= teacher_csrf_field() ?>
    <input type="hidden" name="klasse" value="<?= $classId ?>">
    <span class="coursetitle">
        <select name="sprache" data-picker required>
            <?php foreach (language_choices() as $s): ?>
                <option value="<?= h($s['name']) ?>"
                        data-flag="<?= h($s['flag']) ?>"
                        data-top="<?= $s['top'] ? '1' : '0' ?>">
                    <?= h($s['flag'] . ' ' . $s['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </span>
    <button class="btn small">Kurs anlegen</button>
</form>

<?php if ($fehler !== ''): ?>
<?php
/*
 * Nur im Fehlerfall: ein eigener Name.
 *
 * Der Regelfall braucht ihn nicht - "Englisch - 5B" ist genau das, was man
 * geschrieben haette. Gefragt wird erst, wenn dieser Name schon vergeben
 * ist; dann ist es keine Zusatzfrage, sondern die Antwort auf ein Problem.
 */
?>
<h2>Mit eigenem Namen</h2>

<form method="post" class="card" style="max-width:420px">
    <?= teacher_csrf_field() ?>
    <input type="hidden" name="klasse" value="<?= $classId ?>">
    <label for="eigenername">Name des Kurses</label>
    <input type="text" id="eigenername" name="name" maxlength="128" required
           value="<?= h($name) ?>" placeholder="Englisch - 5B (zweite Gruppe)">
    <label for="eigenesprache">Sprache</label>
    <select id="eigenesprache" name="sprache" required>
        <?php foreach (language_choices() as $s): ?>
            <option value="<?= h($s['name']) ?>">
                <?= h($s['flag'] . ' ' . $s['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button class="btn small">Kurs anlegen</button>
</form>
<?php endif; ?>

<p class="tiny muted">
    <strong>Jeder Kurs hat seine eigenen Unterlagen.</strong> „Englisch - 5B"
    und „Englisch - 6A" teilen sich nichts &ndash; jede Lerngruppe liest ihre
    eigenen Buchseiten ein und gibt sie in ihrem eigenen Tempo frei.
</p>
<?php endif; ?>

<?php teacher_foot(); ?>
