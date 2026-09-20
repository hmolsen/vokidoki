<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

$user     = teacher_require();
$schoolId = (int) ($user['school_id'] ?? 0);

$classId = (int) ($_GET['id'] ?? $_POST['class_id'] ?? 0);
$klasse  = $schoolId > 0 ? class_in_school($classId, $schoolId) : null;

if ($klasse === null) {
    // Dieselbe Meldung wie bei einer Klasse, die es nicht gibt: Wer sie nicht
    // sehen darf, soll nicht erfahren, dass sie existiert.
    teacher_flash('Diese Klasse gibt es nicht.', 'bad');
    teacher_redirect('classes.php');
}

/*
 * Aus welchem Kurs kommt man?
 *
 * Die Klasse ist keine Ebene der Navigation mehr, sondern ein Ziel: Man
 * kommt hierher, weil ein Kind fehlt oder ein Zettel gebraucht wird - und
 * zwar fast immer aus einem Kurs. Der reist deshalb in der Adresse mit,
 * damit der Pfad oben wieder dorthin zurueckfuehrt statt irgendwohin.
 */
$ausKurs = course_in_school((int) ($_GET['kurs'] ?? $_POST['kurs'] ?? 0), $schoolId);
$anhang  = $ausKurs === null ? '' : '&kurs=' . (int) $ausKurs['id'];

// Damit jedes Formular der Seite ihn mitnimmt, auch das abgeschickte.
$kursFeld = $ausKurs === null
    ? ''
    : '<input type="hidden" name="kurs" value="' . (int) $ausKurs['id'] . '">';

$zurück = 'class.php?id=' . $classId . $anhang;

/**
 * Will der Aufrufer eine Zeile statt einer Seite?
 *
 * Das Formular funktioniert ohne JavaScript ganz gewöhnlich: abschicken,
 * weiterleiten, neue Seite. Mit JavaScript wird daraus ein Zug - Namen
 * tippen, Enter, nächster Name -, und dafür braucht es die frische Zeile
 * als Antwort statt einer ganzen Seite.
 */
function will_json(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
}

function json_antwort(array $daten, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($daten, JSON_UNESCAPED_UNICODE);
    exit;
}

/*
 * Ein Kind, einzeln. Der Weg für alles nach dem ersten Mal: Es kommt
 * jemand dazu, und niemand will dafür eine Liste einfügen.
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['add_student'])) {
    teacher_csrf_check();

    $eingabe = (string) ($_POST['student'] ?? '');
    $namen   = roster_parse_names($eingabe);

    if ($namen === []) {
        if (will_json()) {
            json_antwort(['ok' => false, 'error' => 'Da steht kein Name.'], 422);
        }
        teacher_flash('Da steht kein Name.', 'bad');
        teacher_redirect($zurück);
    }

    $n       = $namen[0];
    $anzeige = roster_display_name($n['first'], $n['initial']);

    // Wer schon in der Klasse ist, wird nicht doppelt angelegt.
    foreach (class_members_list($classId) as $m) {
        if (mb_strtolower($m['display_name']) === mb_strtolower($anzeige)) {
            if (will_json()) {
                json_antwort(['ok' => false,
                    'error' => sprintf('%s ist schon in der Klasse.', $anzeige)], 409);
            }
            teacher_flash(sprintf('%s ist schon in der Klasse.', $anzeige), 'bad');
            teacher_redirect($zurück);
        }
    }

    $konto = student_create($schoolId, $classId, $n['first'], $n['initial']);

    if ($konto === null) {
        if (will_json()) {
            json_antwort(['ok' => false,
                'error' => 'Das Konto liess sich nicht anlegen.'], 500);
        }
        teacher_flash('Das Konto liess sich nicht anlegen.', 'bad');
        teacher_redirect($zurück);
    }

    if (will_json()) {
        json_antwort(['ok' => true, 'kind' => [
            'id'       => (int) $konto['id'],
            'name'     => $konto['display_name'],
            'username' => $konto['username'],
            'password' => (string) $konto['initial_password'],
        ]]);
    }

    $_SESSION['teacher_fresh'] = [(int) $konto['id']];
    teacher_flash(sprintf('%s ist dabei.', $konto['display_name']));
    teacher_redirect($zurück);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['add_students'])) {
    teacher_csrf_check();

    $bericht = students_bulk_create($schoolId, $classId, (string) ($_POST['names'] ?? ''));
    $neu     = count($bericht['created']);
    $weg     = count($bericht['skipped']);

    if ($neu === 0 && $weg === 0) {
        teacher_flash('Da war keine einzige Zeile mit einem Namen darin.', 'bad');
    } else {
        /*
         * Die frisch angelegten Konten wandern in die Sitzung, damit die
         * folgende Seite die Passwörter zeigen kann. Sie stehen zwar auch in
         * der Datenbank, aber nur so hebt sich hervor, wer gerade neu ist -
         * bei einer Klasse mit 28 Kindern ist das der Unterschied zwischen
         * "brauchbar" und "such es dir raus".
         */
        $_SESSION['teacher_fresh'] = array_map(
            static fn (array $u): int => (int) $u['id'],
            $bericht['created'],
        );

        teacher_flash(sprintf(
            '%d %s angelegt%s.',
            $neu,
            $neu === 1 ? 'Konto' : 'Konten',
            $weg === 0 ? '' : sprintf(', %d schon vorhanden übersprungen', $weg),
        ));
    }

    teacher_redirect($zurück);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['unlock'])) {
    teacher_csrf_check();

    $kindId = (int) $_POST['unlock'];

    $gehoert = q1(
        'SELECT u.id, u.username, u.display_name FROM class_members m
           JOIN users u ON u.id = m.user_id
          WHERE m.class_id = ? AND u.id = ?',
        [$classId, $kindId],
    );

    if ($gehoert === null) {
        teacher_flash('Dieses Kind ist nicht in dieser Klasse.', 'bad');
        teacher_redirect($zurück);
    }

    /*
     * Aufschliessen, ohne das Passwort anzufassen. Wer sich nur vertippt hat
     * und sein Passwort kennt, soll nicht eine Viertelstunde warten und auch
     * kein neues Passwort abtippen muessen.
     */
    login_attempts_reset((string) $gehoert['username']);
    teacher_flash(sprintf('%s kann sich wieder anmelden.', $gehoert['display_name']));
    teacher_redirect($zurück);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['reset_password'])) {
    teacher_csrf_check();

    $kindId = (int) $_POST['reset_password'];

    // Nur Kinder aus genau dieser Klasse - sonst liesse sich über ein
    // gefälschtes Formular jedes Konto der Anwendung zurücksetzen.
    $gehörtDazu = q1(
        'SELECT u.id, u.display_name FROM class_members m
           JOIN users u ON u.id = m.user_id
          WHERE m.class_id = ? AND u.id = ?',
        [$classId, $kindId],
    );

    if ($gehörtDazu === null) {
        teacher_flash('Dieses Kind ist nicht in dieser Klasse.', 'bad');
        teacher_redirect($zurück);
    }

    $neuesPasswort = student_reset_password($kindId);

    if ($neuesPasswort === null) {
        teacher_flash('Das Passwort liess sich nicht neu setzen.', 'bad');
    } else {
        $_SESSION['teacher_fresh'] = [$kindId];
        teacher_flash(sprintf('%s hat ein neues Passwort.', $gehörtDazu['display_name']));
    }

    teacher_redirect($zurück);
}

$kinder    = array_values(array_filter(
    class_members_list($classId),
    static fn (array $m): bool => $m['role'] !== 'teacher',
));
$kurse     = courses_for_class($classId);
$gesperrt  = login_locked_usernames(array_column($kinder, 'username'));
$frisch    = array_flip((array) ($_SESSION['teacher_fresh'] ?? []));
unset($_SESSION['teacher_fresh']);

teacher_head('Klasse ' . $klasse['name'], $user,
    $ausKurs === null ? '' : sprintf(
        '<a class="btn small secondary" href="%s">&#8249; Zurück zum Kurs</a>',
        h(teacher_url('course.php') . '?id=' . (int) $ausKurs['id']),
    ), '', $ausKurs === null ? null : (int) $ausKurs['id']);
teacher_flash_render();
?>

<?php
/*
 * Die Kurse stehen oben.
 *
 * Eine Klasse wird einmal angelegt und einmal gefuellt; danach geht es bei
 * jedem Besuch um einen Kurs - freigeben, einlesen, nachsehen. Was man
 * staendig braucht, gehoert nach oben; die Namensliste ist Verwaltung und
 * steht darunter.
 */
?>
<h2>Kurse dieser Klasse</h2>

<table class="data courses rowlink kompakt" id="kurse">
    <tr>
        <th>Kurs</th>
        <th class="num">Kinder</th>
        <th class="num">Lerneinheiten</th>
        <th class="num">Freigegeben</th>
        <th class="actions"></th>
    </tr>

    <?php foreach ($kurse as $c): ?>
        <?php $ziel = teacher_url('course.php') . '?id=' . (int) $c['id']; ?>
        <tr<?= $c['active'] ? '' : ' class="dim"' ?> data-href="<?= h($ziel) ?>">
            <td data-label="Kurs">
                <span class="coursetitle">
                    <?= flag_html($c['flag_emoji'] ?: FLAG_FALLBACK, 'cflag') ?>
                    <span>
                        <a class="rowmain" href="<?= h($ziel) ?>"><?= h($c['name']) ?></a>
                        <span class="tiny muted"><?= h($c['language_name']) ?><?php
                            if (!$c['active']) { echo ' &middot; stillgelegt'; } ?></span>
                    </span>
                </span>
            </td>
            <td class="num" data-label="Kinder"><?= (int) $c['students'] ?></td>
            <td class="num" data-label="Lerneinheiten"><?= (int) $c['units'] ?></td>
            <td class="num" data-label="Freigegeben">
                <?php
                $frei   = (int) $c['released'];
                $gesamt = (int) $c['vocab'];
                if ($gesamt === 0) {
                    echo '<span class="muted">&ndash;</span>';
                } elseif ($frei >= $gesamt) {
                    printf('<span class="pill good">alle %d</span>', $gesamt);
                } else {
                    printf('<span class="pill">%d von %d</span>', $frei, $gesamt);
                }
                ?>
            </td>
            <td class="actions chev" aria-hidden="true">&#8250;</td>
        </tr>
    <?php endforeach; ?>

    <?php
    /*
     * Die Anlegezeile fuehrt in den Assistenten, statt selbst eine zu sein.
     *
     * Hier stand einmal das ganze Formular: ein Sprachfeld, der Kursname als
     * Text mit einem Stift daneben, ein Anlegeknopf - in einer Tabellenzeile
     * mit vier Spalten. Es funktionierte, aber es war der einzige Ort der
     * Anwendung, an dem ein Kurs entstehen konnte, und man musste ihn erst
     * finden. Jetzt entsteht ein Kurs von der Startseite aus; der Weg von
     * hier ist derselbe, nur mit der Klasse schon gesetzt.
     */
    ?>
    <tr class="newrow">
        <td colspan="5" data-label="Neuer Kurs">
            <span class="coursetitle addbuttons">
                <span class="cflag plus">+</span>
                <a class="btn small" href="<?= h(teacher_url('neu.php')
                    . '?klasse=' . $classId) ?>">
                    Neuer Kurs für diese Klasse
                </a>
            </span>
        </td>
    </tr>
</table>

<p class="tiny muted">
    Eine Zeile anklicken öffnet den Kurs. Der Name ergibt sich aus Sprache
    und Klasse. Die Kinder dieser Klasse kommen beim Anlegen gleich mit in
    den Kurs.
    <strong>Jeder Kurs hat seine eigenen Unterlagen:</strong> „Englisch - 5B"
    und „Englisch - 6A" teilen sich nichts.
</p>

<h2>Kinder dieser Klasse</h2>

<table class="data courses" id="kinder">
    <tr>
        <th>Name</th>
        <th>Benutzername</th>
        <th>Anfangspasswort</th>
        <th class="actions"></th>
    </tr>

    <?php foreach ($kinder as $k): ?>
        <tr<?= isset($frisch[(int) $k['id']]) ? ' class="hit"' : ($k['active'] ? '' : ' class="dim"') ?>>
            <td data-label="Name">
                <span class="coursetitle">
                    <span class="cflag">&#128100;</span>
                    <span>
                        <strong><?= h($k['display_name']) ?></strong>
                        <?php if (isset($gesperrt[$k['username']])): ?>
                            <span class="tiny" style="color:var(--bad)">gesperrt</span>
                        <?php elseif (!$k['active']): ?>
                            <span class="tiny muted">stillgelegt</span>
                        <?php endif; ?>
                    </span>
                </span>
            </td>
            <td data-label="Benutzername"><code class="token"><?= h($k['username']) ?></code></td>
            <td data-label="Anfangspasswort">
                <?php if (($k['initial_password'] ?? null) !== null && $k['initial_password'] !== ''): ?>
                    <code class="token"><?= h($k['initial_password']) ?></code>
                <?php else: ?>
                    <span class="tiny muted">selbst geändert</span>
                <?php endif; ?>
            </td>
            <td class="actions">
                <?php if (isset($gesperrt[$k['username']])): ?>
                <form method="post" class="compact">
                    <?= teacher_csrf_field() ?>
                    <input type="hidden" name="class_id" value="<?= $classId ?>"><?= $kursFeld ?>
                    <button class="iconaction" name="unlock"
                            value="<?= (int) $k['id'] ?>" title="Konto wieder freigeben">
                        <span aria-hidden="true">&#128275;</span> Entsperren
                    </button>
                </form>
                <?php endif; ?>
                <form method="post" class="compact">
                    <?= teacher_csrf_field() ?>
                    <input type="hidden" name="class_id" value="<?= $classId ?>"><?= $kursFeld ?>
                    <?php
                    /*
                     * Der Name steht in einem data-Attribut statt im
                     * onclick-Text. Dort muesste er zugleich fuer JavaScript
                     * und fuer HTML maskiert werden, und ein Kind namens
                     * "N'Diaye" bricht so eine Verschachtelung zuverlaessig
                     * auf. h() allein genuegt fuer ein Attribut.
                     */
                    ?>
                    <button class="iconaction quiet" name="reset_password"
                            value="<?= (int) $k['id'] ?>" title="Neues Anfangspasswort"
                            data-confirm="Neues Anfangspasswort für <?= h($k['display_name']) ?>? Das alte gilt dann nicht mehr.">
                        <span aria-hidden="true">&#128273;</span> Passwort
                    </button>
                </form>
                <a class="iconaction quiet" title="Zettel für dieses Kind drucken"
                   href="<?= h(teacher_url('print.php') . '?class=' . $classId . '&user=' . (int) $k['id']) ?>"
                   target="_blank" rel="noopener">
                    <span aria-hidden="true">&#128424;</span> Zettel
                </a>
            </td>
        </tr>
    <?php endforeach; ?>

    <?php
    /*
     * Die Zeile zum Hinzufuegen. Mit JavaScript wird daraus ein Zug: Namen
     * tippen, Enter, naechster Name - die neue Zeile kommt als Antwort
     * zurueck und wird eingehaengt, der Fokus bleibt im Feld. Ohne
     * JavaScript schickt dasselbe Formular ganz gewoehnlich ab und die
     * Seite laedt neu; das Ergebnis ist dasselbe, nur langsamer.
     */
    ?>
    <tr class="newrow" id="neuesKind">
        <td data-label="Neues Kind">
            <span class="coursetitle">
                <span class="cflag plus">+</span>
                <input type="text" name="student" form="newstudent"
                       placeholder="Fritz Brinkmann" maxlength="80" required
                       aria-label="Name des Kindes">
            </span>
        </td>
        <td colspan="2" class="tiny muted">
            Name eintippen und Enter &ndash; Benutzername und Passwort
            entstehen von selbst.
        </td>
        <td class="actions">
            <button class="iconaction primary" form="newstudent"
                    name="add_student" value="1" title="Kind hinzufügen">
                <span aria-hidden="true">+</span> Hinzufügen
            </button>
        </td>
    </tr>
</table>

<?php
/*
 * Das Formular traegt alles, was die frisch eingehaengte Zeile braucht:
 * das CSRF-Feld, die Klasse und die Adresse fuer den Zettel. So baut das
 * Skript nichts nach, was hier schon steht - und eine Aenderung an der
 * Adresse muss nicht an zwei Stellen gepflegt werden.
 */
?>
<form method="post" id="newstudent" data-addstudent
      action="<?= h(teacher_url('class.php') . '?id=' . $classId) ?>"
      data-print-user="<?= h(teacher_url('print.php') . '?class=' . $classId . '&user=') ?>"
      hidden>
    <?= teacher_csrf_field() ?>
    <input type="hidden" name="class_id" value="<?= $classId ?>"><?= $kursFeld ?>
</form>

<?php
/*
 * Der Zettel fuer die ganze Klasse.
 *
 * Er steht immer da und ist abgeblendet, solange die Klasse leer ist -
 * vorher erschien er erst nach dem naechsten Laden, und wer gerade seine
 * erste Klassenliste eingetippt hatte, sah ihn ausgerechnet dann nicht.
 * Das Skript nimmt die Sperre weg, sobald das erste Kind in der Tabelle
 * steht.
 */
?>
<p class="buttonrow" id="klassenzettel">
    <a class="btn small secondary<?= $kinder === [] ? ' aus' : '' ?>"
       id="zettelAlle"
       href="<?= h(teacher_url('print.php') . '?class=' . $classId) ?>"
       target="_blank" rel="noopener"
       <?= $kinder === [] ? 'aria-disabled="true" tabindex="-1" title="Erst ein Kind anlegen"' : '' ?>>
        <span aria-hidden="true">&#128424;</span> Zettel für die ganze Klasse
    </a>
</p>

<p class="tiny muted">
    <strong>Nachnamen werden nicht gespeichert.</strong> Du kannst „Fritz
    Brinkmann" eintippen oder eine ganze Klassenliste einfügen &ndash;
    gespeichert wird daraus nur „Fritz B.". Den Nachnamen wirft die Anwendung
    beim Einlesen weg; er steht in keiner Tabelle und auf keinem Zettel.
    Das Anfangspasswort steht im Klartext, damit sich der Zettel
    nachdrucken lässt; sobald ein Kind es selbst ändert, verschwindet es aus
    der Spalte.
</p>

<?php
/*
 * Die Liste am Stueck gibt es nur, solange die Klasse leer ist.
 *
 * Beim ersten Mal hat die Lehrkraft die Klassenliste vor sich und will sie
 * in einem Zug hineinkopieren. Danach kommt jemand einzeln dazu, und dafuer
 * ist die Zeile oben der kuerzere Weg - ein Textfeld mit 28 Namen darin
 * waere dann nur noch im Weg.
 */
?>
<?php if ($kinder === []): ?>
<h2>Die ganze Klassenliste auf einmal</h2>

<form method="post" class="card" style="max-width:560px">
    <?= teacher_csrf_field() ?>
    <input type="hidden" name="class_id" value="<?= $classId ?>"><?= $kursFeld ?>
    <label for="names">Ein Name je Zeile</label>
    <textarea id="names" name="names" rows="12"
              placeholder="Lilli Molsen&#10;Schmidt, Anna-Lena&#10;Max"></textarea>
    <button class="btn small" name="add_students" value="1">Konten anlegen</button>
    <p class="tiny muted">
        Einfach die Liste hineinkopieren, wie sie vorliegt &ndash; „Lilli
        Molsen" und „Molsen, Lilli" werden beide verstanden. Wer schon in der
        Klasse ist, wird übersprungen; die Liste lässt sich also auch ein
        zweites Mal einfügen ohne Duplikate zu erzeugen.
    </p>
</form>
<?php endif; ?>

<?php teacher_foot(); ?>
