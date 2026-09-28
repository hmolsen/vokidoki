<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/ai.php';
require_once __DIR__ . '/../lib/sentences.php';
require_once __DIR__ . '/../lib/vocab.php';

admin_require();

/*
 * Unterlagen: Schule, Kurs, Lerneinheit - und darin Vokabeln und Saetze.
 *
 * Hier stand einmal "Kind, Sprache, Lerneinheit". Das stammte aus der Zeit,
 * als ein Kind seine Vokabeln besass. Seit sie einer Lerneinheit im Kurs
 * gehoeren, fuehrte der Weg ueber das Kind zu 28 gleichen Antworten, und die
 * Zeile "Kurs" trug in Wahrheit die Sprache.
 *
 * Der Admin sieht und berichtigt hier. Freigeben, Mitglieder und Einlesen
 * bleiben bei der Lehrkraft - sie weiss, was in ihrer Klasse dran ist.
 */

$scope    = admin_scope();
$schoolId = $scope['ids']['school'];
$courseId = $scope['ids']['course'];
$unitId   = $scope['ids']['unit'];
$course   = $scope['course'];
$unit     = $scope['unit'];

/** Zurück zur gewählten Stelle, damit die Auswahl nach dem Speichern steht. */
function back_to_filter(array $scope): never
{
    $query = http_build_query(admin_scope_query($scope));
    header('Location: ' . admin_url('vocab.php') . ($query !== '' ? '?' . $query : ''));
    exit;
}

/** Die Auswahl eine Stufe hoeher - nach dem Loeschen dessen, was gewaehlt war. */
function scope_up(array $scope, string $bis): array
{
    $stufen = ['school', 'course', 'unit'];
    foreach (array_slice($stufen, array_search($bis, $stufen, true)) as $k) {
        $scope['ids'][$k] = 0;
    }
    return $scope;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    /*
     * Alles, was eine Lerneinheit braucht, bekommt sie aus der geprueften
     * Auswahl, nicht aus einem Formularfeld. Frueher ging "unit_id" aus dem
     * Formular direkt ins UPDATE - und traf jede Einheit, die man hineinschrieb.
     */
    $braucheEinheit = ['save_rows', 'save_sentence_rows', 'delete_sentence',
                       'make_sentences', 'delete_vocab', 'add_vocab'];
    foreach ($braucheEinheit as $aktion) {
        if (isset($_POST[$aktion]) && $unit === null) {
            flash('Diese Lerneinheit gibt es nicht mehr.', 'bad');
            back_to_filter(scope_up($scope, 'unit'));
        }
    }

    if (isset($_POST['save_rows'])) {
        $foreign = (array) ($_POST['f'] ?? []);
        $native  = (array) ($_POST['n'] ?? []);
        $note    = (array) ($_POST['note'] ?? []);
        $type    = (array) ($_POST['wt'] ?? []);
        $changed = 0;

        foreach ($foreign as $id => $value) {
            $id = (int) $id;
            $f  = trim((string) $value);
            $nv = trim((string) ($native[$id] ?? ''));
            $nt = trim((string) ($note[$id] ?? ''));
            if ($f === '' || $nv === '') {
                continue;   // leere Felder ignorieren statt Daten zu zerstören
            }
            $changed += q(
                'UPDATE vocab SET term_foreign = ?, term_native = ?, note = ?, word_type = ?
                  WHERE id = ? AND unit_id = ?',
                [mb_substr($f, 0, 255), mb_substr($nv, 0, 255),
                 $nt === '' ? null : mb_substr($nt, 0, 255),
                 word_type_clean($type[$id] ?? null), $id, $unitId],
            )->rowCount();
        }

        $title = trim((string) ($_POST['unit_title'] ?? ''));
        if ($title !== '') {
            q('UPDATE units SET title = ? WHERE id = ?', [mb_substr($title, 0, 128), $unitId]);
        }

        flash($changed === 0 ? 'Nichts geändert.' : $changed . ' Vokabel(n) aktualisiert.');
        back_to_filter($scope);
    }

    if (isset($_POST['save_sentence_rows'])) {
        /*
         * Ueber sentence_update(), wie auf der Satzliste und bei der
         * Lehrkraft. Hier stand eine eigene Fassung mit sentence_clean() -
         * nie aufgerufen, weil kein Formular sie abschickte, aber bereit,
         * eine zweite Vorstellung davon zu haben, was ein gueltiger Satz ist.
         */
        $nat  = (array) ($_POST['sn'] ?? []);
        $frn  = (array) ($_POST['sf'] ?? []);
        $ans  = (array) ($_POST['sa'] ?? []);
        $n    = 0;
        $bad  = 0;
        $code = ($course['code'] ?? '') !== '' ? (string) $course['code'] : null;

        $eigene = [];
        foreach (qa('SELECT s.id FROM sentences s JOIN vocab v ON v.id = s.vocab_id
                      WHERE v.unit_id = ?', [$unitId]) as $r) {
            $eigene[(int) $r['id']] = true;
        }

        foreach ($nat as $id => $_v) {
            $id = (int) $id;
            if (!isset($eigene[$id])) {
                continue;
            }
            $neue = sentence_update($id, (string) ($nat[$id] ?? ''),
                                    (string) ($frn[$id] ?? ''),
                                    (string) ($ans[$id] ?? ''), $code);
            if ($neue === null) {
                $bad++;
                continue;
            }
            $n += $neue;
        }

        $text = $n === 0 ? 'Nichts geändert.' : $n . ' Satz/Sätze aktualisiert.';
        if ($bad > 0) {
            flash($text . sprintf(' %d wurde(n) nicht gespeichert, weil die Form nicht stimmt (%s).',
                $bad, SENTENCE_FORM_HINT), 'bad');
        } else {
            flash($text);
        }
        back_to_filter($scope);
    }

    if (isset($_POST['delete_sentence'])) {
        $weg = q('DELETE s FROM sentences s JOIN vocab v ON v.id = s.vocab_id
                   WHERE s.id = ? AND v.unit_id = ?',
                 [(int) $_POST['delete_sentence'], $unitId])->rowCount();
        flash($weg > 0 ? 'Satz gelöscht.' : 'Diesen Satz gibt es hier nicht.', $weg > 0 ? 'good' : 'bad');
        back_to_filter($scope);
    }

    if (isset($_POST['make_sentences'])) {
        set_time_limit(300);

        // Auf wessen Rechnung. Siehe course_billing_user().
        $owner = course_billing_user($courseId);
        if ($owner === null) {
            flash('In diesem Kurs ist niemand - kein Konto, das für die Kosten geradesteht.', 'bad');
            back_to_filter($scope);
        }

        $blocked = budget_block_reason((int) $owner['id']);
        if ($blocked !== null) {
            flash($blocked, 'bad');
            back_to_filter($scope);
        }

        try {
            $res = generate_sentences($unit, $owner);
            $text = sprintf(
                '%d Satz/Sätze erzeugt%s.%s',
                $res['created'],
                $res['skipped'] > 0 ? sprintf(' (%d verworfen)', $res['skipped']) : '',
                $res['without'] > 0
                    ? sprintf(' %d Vokabel(n) haben noch keinen - Knopf noch einmal drücken.',
                              $res['without'])
                    : '',
            );
            if ($res['failed'] !== null) {
                flash($text . ' Abgebrochen: ' . $res['failed'], 'bad');
            } else {
                flash($text);
            }
        } catch (Throwable $e) {
            error_log('[vokabeltrainer] Sätze: ' . scrub_secrets($e->getMessage()));
            flash('Die Sätze konnten nicht erzeugt werden. Details stehen im Protokoll.', 'bad');
        }
        back_to_filter($scope);
    }

    if (isset($_POST['fix_punctuation'])) {
        // Kostet nichts und ruft kein Modell - reine Textarbeit.
        set_time_limit(300);
        $n = punctuation_repair(true);

        flash($n['vocab'] === 0 && $n['sentences'] === 0
            ? 'Die Abstände stimmten schon überall.'
            : sprintf('Abstände zurechtgerückt: %d Vokabel(n) und %d Satz/Sätze.',
                      $n['vocab'], $n['sentences']));
        back_to_filter($scope);
    }

    if (isset($_POST['fill_word_types'])) {
        // In Blöcken arbeiten: Ein Aufruf über hunderte Vokabeln wäre lang,
        // teuer und ginge bei einem Fehler komplett verloren.
        $perBatch  = 100;
        $maxBatch  = 5;
        $done      = 0;
        $problem   = null;

        set_time_limit(300);

        for ($i = 0; $i < $maxBatch; $i++) {
            $rows = qa(
                /*
                 * Wer die Anfrage bezahlt: die Lehrkraft des Kurses, sonst
                 * irgendein Mitglied. Frueher stand hier units.user_id -
                 * ein Besitzer, den es nicht mehr gibt. Gebraucht wird das
                 * Konto nur fuers Kostenprotokoll und das Budget.
                 */
                "SELECT v.id, v.term_foreign, v.term_native,
                        l.name AS language,
                        (SELECT m.user_id FROM course_members m
                          WHERE m.course_id = t.course_id
                          ORDER BY m.member_role = 'student', m.id
                          LIMIT 1) AS user_id,
                        co.name AS display_name
                   FROM vocab v
                   JOIN units t     ON t.id = v.unit_id
                   JOIN languages l ON l.id = t.language_id
                   LEFT JOIN courses co ON co.id = t.course_id
                  WHERE v.word_type IS NULL
                  ORDER BY l.id, v.id
                  LIMIT {$perBatch}",
            );
            if ($rows === []) {
                break;
            }

            // Ein Block je Sprache - die Wortart hängt von der Sprache ab.
            $language = (string) $rows[0]['language'];
            $rows     = array_values(array_filter(
                $rows,
                static fn (array $r): bool => $r['language'] === $language,
            ));
            $owner = ['id' => $rows[0]['user_id'], 'display_name' => $rows[0]['display_name']];

            $blocked = budget_block_reason((int) $owner['id']);
            if ($blocked !== null) {
                $problem = $blocked;
                break;
            }

            try {
                $types = classify_word_types($rows, $language, $owner);
            } catch (Throwable $e) {
                error_log('[vokabeltrainer] Kategorien: ' . scrub_secrets($e->getMessage()));
                $problem = 'Die Kategorien konnten nicht bestimmt werden. Details stehen im Protokoll.';
                break;
            }

            $st = db()->prepare('UPDATE vocab SET word_type = ? WHERE id = ?');
            foreach ($types as $id => $type) {
                $st->execute([$type, $id]);
                $done++;
            }

            // Nichts zugeordnet: ein weiterer Durchlauf brächte dasselbe Ergebnis.
            if ($types === []) {
                $problem = 'Das Modell hat keine Kategorie zurückgeliefert.';
                break;
            }
        }

        $remaining = (int) qv('SELECT COUNT(*) FROM vocab WHERE word_type IS NULL');

        if ($problem !== null) {
            flash($problem, 'bad');
        } elseif ($done === 0) {
            flash('Es gab nichts nachzutragen.');
        } else {
            flash(sprintf(
                '%d Kategorie(n) nachgetragen.%s',
                $done,
                $remaining > 0
                    ? sprintf(' Es fehlen noch %d - Knopf noch einmal drücken.', $remaining)
                    : ' Jetzt hat jede Vokabel eine.',
            ));
        }
        back_to_filter($scope);
    }

    if (isset($_POST['save_language'])) {
        /*
         * Das Kuerzel gehoert zur Sprache des Kurses - jeder Kurs hat seine
         * eigene Zeile in languages (siehe course_create()). Welche, sagt
         * die gepruefte Auswahl, nicht der Wert des Knopfes.
         */
        if ($course === null) {
            flash('Diesen Kurs gibt es nicht mehr.', 'bad');
            back_to_filter(scope_up($scope, 'course'));
        }
        $code = strtolower(trim((string) ($_POST['lang_code'] ?? '')));

        if ($code !== '' && preg_match('/^[a-z]{2,3}$/', $code) !== 1) {
            flash('Das Kürzel besteht aus zwei oder drei Buchstaben, z. B. fr.', 'bad');
        } else {
            q('UPDATE languages SET code = ? WHERE id = ?',
              [$code === '' ? null : $code, (int) $course['language_id']]);
            flash($code === ''
                ? 'Kürzel entfernt - Tastaturhinweis und Sonderzeichen entfallen.'
                : sprintf('Kürzel auf "%s" gesetzt.', $code));
        }
        back_to_filter($scope);
    }

    if (isset($_POST['delete_course'])) {
        /*
         * Hier stand "Diese Sprache loeschen". Das loeschte ueber den
         * Fremdschluessel den ganzen Kurs mit - Mitglieder, Einheiten,
         * Lernstand -, und die Rueckfrage nannte nur Vokabeln. Jetzt heisst
         * es, was es tut, und geht denselben Weg wie bei der Lehrkraft.
         */
        if ($course === null) {
            flash('Diesen Kurs gibt es nicht mehr.', 'bad');
            back_to_filter(scope_up($scope, 'course'));
        }

        // Vorher zählen, damit die Meldung sagt, was tatsächlich weg ist.
        $weg = course_delete_preview($courseId);
        try {
            course_delete($courseId);
        } catch (Throwable $e) {
            error_log('[vokabeltrainer] Kurs loeschen: ' . scrub_secrets($e->getMessage()));
            flash('Der Kurs liess sich nicht löschen. Details stehen im Protokoll.', 'bad');
            back_to_filter($scope);
        }

        flash(sprintf(
            'Kurs "%s" gelöscht - mit %d Lerneinheit(en), %d Vokabel(n) und dem Lernstand von %d Kind(ern).',
            $course['name'], $weg['units'], $weg['vocab'], $weg['students'],
        ));
        // Die Auswahl darf nicht auf etwas zeigen, das es nicht mehr gibt.
        back_to_filter(scope_up($scope, 'course'));
    }

    if (isset($_POST['delete_vocab'])) {
        /*
         * Ueber lib/vocab.php und nicht mit einem blanken DELETE.
         *
         * Zwei Dinge haengen daran, die ein DELETE allein nicht tut: Lag die
         * Vokabel unterhalb der Freigabemarke, muss die Marke mitsinken -
         * sonst rueckt stillschweigend ein gesperrtes Wort nach. Und die
         * Positionen muessen danach wieder lueckenlos sein, sonst erreicht
         * "Alles freigeben" die letzte Vokabel nicht mehr.
         */
        $id = (int) $_POST['delete_vocab'];
        if ((int) qv('SELECT unit_id FROM vocab WHERE id = ?', [$id]) === $unitId) {
            vocab_delete($id);
            flash('Vokabel gelöscht. Der Lernstand der Kinder dazu ist mit weg.');
        } else {
            flash('Diese Vokabel gibt es hier nicht.', 'bad');
        }
        back_to_filter($scope);
    }

    if (isset($_POST['add_vocab'])) {
        $f  = trim((string) ($_POST['new_f'] ?? ''));
        $nv = trim((string) ($_POST['new_n'] ?? ''));
        if ($f === '' || $nv === '') {
            flash('Beide Felder ausfüllen.', 'bad');
        } else {
            // Derselbe Weg wie beim Einlesen und im Lehrkraft-Bereich: eine
            // Transaktion, lueckenlose Positionen, und punctuation_fix() -
            // das fehlte hier, weshalb von Hand ergaenzte Vokabeln hinterher
            // in der Liste "Abstaende" auftauchten.
            $code = (string) ($course['code'] ?? '');
            $dazu = vocab_append($unitId, [['foreign' => $f, 'native' => $nv]],
                                 $code !== '' ? $code : null);
            flash($dazu > 0 ? 'Vokabel ergänzt.' : 'Die Vokabel liess sich nicht ergänzen.',
                  $dazu > 0 ? 'good' : 'bad');
        }
        back_to_filter($scope);
    }
}

// ---------------------------------------------------------------- Daten

$kurse     = $schoolId > 0 && $course === null ? courses_for_school($schoolId) : [];
$einheiten = $course !== null && $unit === null ? course_units_list($courseId) : [];

/*
 * Wie weit die Klasse ist - ueber alle Kinder des Kurses.
 *
 * Hier stand der Lernstand EINES Kontos: dessen, das fuer den Kurs
 * geradesteht, meist der Lehrkraft. Die uebt nicht, die Spalten standen auf
 * null, und es sah aus, als koenne niemand etwas. Gezaehlt werden jetzt die
 * Kinder, die im Kurs sind - wer ihn verlassen hat, zaehlt nicht mehr mit.
 */
$kinder = $course === null ? 0 : (int) qv(
    "SELECT COUNT(*) FROM course_members WHERE course_id = ? AND member_role = 'student'",
    [$courseId],
);

$vocab = $unit !== null
    ? qa(
        "SELECT v.*,
                COALESCE(k.gekonnt, 0) AS gekonnt,
                COALESCE(k.richtig, 0) AS richtig,
                COALESCE(k.falsch, 0)  AS falsch
           FROM vocab v
           LEFT JOIN (
                SELECT p.vocab_id,
                       SUM(p.known_at IS NOT NULL) AS gekonnt,
                       SUM(p.correct_count)        AS richtig,
                       SUM(p.wrong_count)          AS falsch
                  FROM progress p
                  JOIN course_members m ON m.user_id = p.user_id
                                       AND m.course_id = ?
                                       AND m.member_role = 'student'
                 WHERE p.mode = 'mc'
                 GROUP BY p.vocab_id
           ) k ON k.vocab_id = v.id
          WHERE v.unit_id = ?
          ORDER BY v.position, v.id",
        [$courseId, $unitId],
      )
    : [];

$sentences = $unit !== null
    ? qa(
        'SELECT s.*, v.term_foreign, v.term_native
           FROM sentences s
           JOIN vocab v ON v.id = s.vocab_id
          WHERE v.unit_id = ?
          ORDER BY v.position, v.id, s.id',
        [$unitId],
      )
    : [];
$openSentences = $unit !== null ? vocab_without_sentences($unitId) : 0;

/*
 * Die beiden Karten fuer den ganzen Bestand stehen nur ueber der Auswahl,
 * nicht ueber einer Lerneinheit. Sie betreffen alle Schulen - und wer eine
 * Einheit offen hat, will ihre Vokabeln sehen, nicht zwei Karten davor.
 */
$missingTypes = $unit === null ? (int) qv('SELECT COUNT(*) FROM vocab WHERE word_type IS NULL') : 0;
// Nur zählen, nicht ändern - die Karte erscheint dann, wenn es etwas zu tun
// gibt, und verschwindet danach von selbst.
$badSpacing = $unit === null ? punctuation_repair(false) : ['vocab' => 0, 'sentences' => 0];

admin_head('Unterlagen', 'vocab.php');
flash_render();
?>

<?php if ($badSpacing['vocab'] > 0 || $badSpacing['sentences'] > 0): ?>
<div class="card">
    <strong>
        Abstände vor Satzzeichen:
        <?= $badSpacing['vocab'] ?> Vokabel(n),
        <?= $badSpacing['sentences'] ?> Satz/Sätze
    </strong>
    <p class="tiny muted" style="margin:6px 0 12px">
        Vor <code>!</code> <code>?</code> <code>:</code> <code>;</code>
        <code>.</code> <code>,</code> steht kein Leerzeichen, in keiner Sprache
        &ndash; auch nicht mehr im Französischen: &bdquo;Salut!&ldquo; statt
        &bdquo;Salut !&ldquo;. Der Knopf rückt den Bestand zurecht und räumt
        doppelte Abstände mit weg - in allen Schulen. Kostet nichts und fragt
        kein Modell.
    </p>
    <form method="post">
        <?= csrf_field() ?>
        <?= admin_scope_fields($scope) ?>
        <button class="btn small" name="fix_punctuation" value="1">Abstände zurechtrücken</button>
    </form>
</div>
<?php endif; ?>

<?php if ($missingTypes > 0): ?>
<div class="card">
    <strong><?= $missingTypes ?> Vokabel(n) ohne Kategorie</strong>
    <p class="tiny muted" style="margin:6px 0 12px">
        Vokabeln, die vor dieser Funktion eingelesen wurden, haben noch keine
        Kategorie. Der Knopf lässt sie vom Modell bestimmen - in Blöcken zu 100,
        höchstens 500 je Klick, über alle Schulen. Das kostet wie eine
        Bilderkennung und zählt aufs Monatsbudget.
    </p>
    <form method="post">
        <?= csrf_field() ?>
        <?= admin_scope_fields($scope) ?>
        <button class="btn small" name="fill_word_types" value="1">Kategorien nachtragen</button>
    </form>
</div>
<?php endif; ?>

<div class="card filters">
    <?= admin_scope_chips($scope) ?>
</div>

<p class="tiny muted">
    <a href="<?= h(admin_url('sentences.php') . (admin_scope_query($scope) === [] ? ''
        : '?' . http_build_query(admin_scope_query($scope)))) ?>">Alle Lückensätze
        <?= $unit !== null ? 'dieser Lerneinheit' : ($course !== null ? 'dieses Kurses'
            : ($schoolId > 0 ? 'dieser Schule' : '')) ?> durchsuchen &rsaquo;</a>
</p>

<?php if ($schoolId === 0): ?>

    <p class="muted"><?= (int) qv('SELECT COUNT(*) FROM schools') === 0
        ? 'Es gibt noch keine Schule - unter „Schulen" anlegen.'
        : 'Wähle oben eine Schule aus.' ?></p>

<?php elseif ($course === null): ?>

    <?php if ($kurse === []): ?>
        <p class="muted">An dieser Schule gibt es noch keinen Kurs. Kurse legen die Lehrkräfte an.</p>
    <?php else: ?>
    <table class="data">
        <tr><th>Kurs</th><th>Klasse</th><th class="num">Kinder</th><th class="num">Lehrkräfte</th>
            <th class="num">Lerneinheiten</th><th class="num">Vokabeln</th><th class="num">freigegeben</th></tr>
        <?php foreach ($kurse as $k): ?>
            <tr<?= $k['active'] ? '' : ' class="dim"' ?>>
                <td>
                    <?= flag_html((string) $k['flag_emoji'], 'chipflag') ?>
                    <a href="<?= h(admin_url('vocab.php') . '?' . http_build_query(
                        ['school' => $schoolId, 'course' => (int) $k['id']])) ?>"><?= h($k['name']) ?></a>
                </td>
                <td><?= $k['class_name'] === null ? '<span class="muted">&ndash;</span>' : h($k['class_name']) ?></td>
                <td class="num"><?= (int) $k['students'] ?></td>
                <td class="num"><?= (int) $k['teachers'] ?></td>
                <td class="num"><?= (int) $k['units'] ?></td>
                <td class="num"><?= (int) $k['vocab'] ?></td>
                <td class="num"><?= (int) $k['released'] ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

<?php elseif ($unit === null): ?>

    <?php $weg = course_delete_preview($courseId); ?>
    <div class="card">
        <strong><?= flag_html((string) $course['flag_emoji']) ?> <?= h($course['name']) ?></strong>
        <span class="tiny muted">
            &middot; <?= h($course['language_name']) ?>
            <?= $course['class_name'] !== null ? '&middot; Klasse ' . h($course['class_name']) : '' ?>
            &middot; <?= $weg['students'] ?> Kind(er), <?= $weg['teachers'] ?> Lehrkraft/-kräfte
            <?= $course['active'] ? '' : '&middot; inaktiv' ?>
        </span>

        <p class="tiny muted" style="margin:8px 0 12px">
            Das K&uuml;rzel steuert im L&uuml;ckentext den Tastaturhinweis am
            Eingabefeld und die Reihe der Sonderzeichen dar&uuml;ber
            (fr, en, la, da &hellip;). Beim Anlegen des Kurses wird es aus dem
            Namen der Sprache abgeleitet; steht hier nichts, entf&auml;llt beides.
            Leeren schaltet es wieder ab.
        </p>

        <form method="post" class="inline" style="margin-bottom:10px">
            <?= csrf_field() ?>
            <?= admin_scope_fields($scope) ?>
            <span class="lbl">K&uuml;rzel</span>
            <input type="text" name="lang_code" value="<?= h((string) ($course['code'] ?? '')) ?>"
                   maxlength="8" placeholder="z. B. fr" style="margin:0;width:90px">
            <button class="btn small secondary" name="save_language" value="1">Speichern</button>
        </form>

        <form method="post">
            <?= csrf_field() ?>
            <?= admin_scope_fields($scope) ?>
            <button class="linkbtn" name="delete_course" value="<?= $courseId ?>"
                    formnovalidate style="color:var(--bad)"
                    data-confirm="<?= h(sprintf(
                        'Den Kurs "%s" wirklich löschen? Damit verschwinden %d Lerneinheit(en), '
                        . '%d Vokabel(n), %d Satz/Sätze und %d Lernstände von %d Kind(ern).',
                        $course['name'], $weg['units'], $weg['vocab'], $weg['sentences'],
                        $weg['progress'], $weg['students'],
                    )) ?>">Diesen Kurs l&ouml;schen</button>
        </form>
    </div>

    <?php if ($einheiten === []): ?>
        <p class="muted">In diesem Kurs gibt es noch keine Lerneinheit.</p>
    <?php else: ?>
    <table class="data">
        <tr><th>Lerneinheit</th><th class="num">Vokabeln</th><th class="num">freigegeben</th>
            <th>Lückensätze</th></tr>
        <?php foreach ($einheiten as $t): ?>
            <tr>
                <td><a href="<?= h(admin_url('vocab.php') . '?' . http_build_query(
                    ['school' => $schoolId, 'course' => $courseId, 'unit' => (int) $t['id']])) ?>"><?= h($t['title']) ?></a></td>
                <td class="num"><?= (int) $t['vocab_count'] ?></td>
                <td class="num"><?= (int) $t['released_count'] ?></td>
                <td class="tiny muted"><?= h((string) ($t['sentences_status'] ?? '')) ?:
                    '&ndash;' ?><?= $t['sentences_error'] !== null
                    ? ' &middot; ' . h((string) $t['sentences_error']) : '' ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <p class="tiny muted">
        Freigeben, Mitglieder und Einlesen erledigt die Lehrkraft in ihrem Bereich.
    </p>

<?php else: ?>

<form method="post">
    <?= csrf_field() ?>
    <?= admin_scope_fields($scope) ?>

    <div class="inline" style="margin-bottom:14px">
        <label for="unit_title" style="margin:0">Titel</label>
        <input type="text" id="unit_title" name="unit_title" value="<?= h($unit['title']) ?>"
               maxlength="128" style="width:280px;margin:0">
        <span class="tiny muted">
            <?= (int) $unit['released_position'] ?> von <?= count($vocab) ?> freigegeben
            &middot; <?= $kinder ?> Kind(er) im Kurs
        </span>
    </div>

    <?php
    /*
     * Eine Suche gab es hier gar nicht - bei einer Lerneinheit mit
     * zweihundert Vokabeln hiess "die eine finden" scrollen und lesen.
     *
     * Sie filtert im Browser und schickt nichts ab: Was hier steht, IST die
     * ganze Lerneinheit, es gibt also keine zweite Seite, auf der noch
     * etwas liegen koennte. Ohne JavaScript steht das Feld da und tut
     * nichts - dann ist die Liste eben so lang, wie sie ist, und
     * vollstaendig.
     */
    ?>
    <div class="inline" style="margin-bottom:10px">
        <label for="vokabelsuche" style="margin:0">Suche</label>
        <input type="search" id="vokabelsuche" placeholder="Wort, Hinweis oder Kategorie"
               style="width:260px;margin:0" autocomplete="off"
               data-filter-ziel="vokabelliste" data-filter-zaehler="vokabelzaehler">
        <span class="tiny muted" id="vokabelzaehler"></span>
    </div>

    <table class="data" id="vokabelliste">
        <tr>
            <th>Fremdsprache</th><th>Deutsch</th><th>Kategorie</th><th>Hinweis</th>
            <th class="num" title="Summe über alle Kinder des Kurses">richtig</th>
            <th class="num" title="Summe über alle Kinder des Kurses">falsch</th>
            <th>gekonnt</th><th></th>
        </tr>
        <?php foreach ($vocab as $v): ?>
            <?php
            // Wonach der Sofortfilter sucht - als Attribut, weil in den
            // Zellen Eingabefelder stehen und deren Inhalt nicht zum Text
            // des Elements gehoert.
            $suchtext = mb_strtolower(implode(' ', [
                (string) $v['term_foreign'], (string) $v['term_native'],
                (string) ($v['note'] ?? ''),
                (string) (WORD_TYPES[$v['word_type'] ?? '']['label'] ?? ''),
            ]));
            // Nur anzeigen, nicht aendern: Was freigegeben ist, entscheidet
            // die Lehrkraft.
            $frei = (int) $v['position'] < (int) $unit['released_position'];
            ?>
            <tr data-suchtext="<?= h($suchtext) ?>"<?= $frei ? '' : ' class="dim"' ?>>
                <td><input type="text" name="f[<?= (int) $v['id'] ?>]" value="<?= h($v['term_foreign']) ?>" maxlength="255"></td>
                <td><input type="text" name="n[<?= (int) $v['id'] ?>]" value="<?= h($v['term_native']) ?>" maxlength="255"></td>
                <td class="wtcell">
                    <?= word_type_badge($v['word_type'] ?? null) ?>
                    <select name="wt[<?= (int) $v['id'] ?>]" aria-label="Kategorie">
                        <option value="">&ndash; keine &ndash;</option>
                        <?php foreach (WORD_TYPES as $key => $meta): ?>
                            <option value="<?= h($key) ?>"<?= ($v['word_type'] ?? null) === $key ? ' selected' : '' ?>>
                                <?= h($meta['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="text" name="note[<?= (int) $v['id'] ?>]" value="<?= h((string) ($v['note'] ?? '')) ?>" maxlength="255"></td>
                <td class="num"><?= (int) $v['richtig'] ?></td>
                <td class="num"><?= (int) $v['falsch'] ?></td>
                <td class="tiny muted">
                    <?= $frei ? (int) $v['gekonnt'] . '/' . $kinder : 'nicht freigegeben' ?>
                </td>
                <td>
                    <button class="linkbtn" name="delete_vocab" value="<?= (int) $v['id'] ?>"
                            formnovalidate style="color:var(--bad)"
                            data-confirm="Diese Vokabel löschen? Der Lernstand der Kinder dazu geht mit.">löschen</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($vocab === []): ?>
            <tr><td colspan="8" class="muted">Diese Lerneinheit ist leer.</td></tr>
        <?php endif; ?>
    </table>

    <button class="btn small" name="save_rows" value="1">Änderungen speichern</button>
</form>

<h2>Vokabel ergänzen</h2>
<form method="post" class="card inline">
    <?= csrf_field() ?>
    <?= admin_scope_fields($scope) ?>
    <input type="text" name="new_f" placeholder="Fremdsprache" maxlength="255" style="width:220px;margin:0">
    <input type="text" name="new_n" placeholder="Deutsch" maxlength="255" style="width:220px;margin:0">
    <button class="btn small secondary" name="add_vocab" value="1">Hinzufügen</button>
</form>

<h2>Lückensätze (<?= count($sentences) ?>)</h2>

<div class="card">
    <?php $zustand = sentence_status($unitId, 0); ?>
    <?php if ($zustand['status'] === SENTENCE_RUNNING): ?>
        <div class="notice info">Die Sätze entstehen gerade im Hintergrund.</div>
    <?php elseif ($zustand['status'] === SENTENCE_FAILED): ?>
        <div class="notice">Letzter Versuch fehlgeschlagen<?= $zustand['error'] !== null
            ? ': ' . h($zustand['error']) : '.' ?></div>
    <?php elseif ($zustand['error'] !== null): ?>
        <div class="notice info"><?= h($zustand['error']) ?></div>
    <?php endif; ?>
    <?php if ($openSentences > 0): ?>
        <strong><?= $openSentences ?> Vokabel(n) ohne Satz</strong>
        <p class="tiny muted" style="margin:6px 0 12px">
            Erzeugt wird in Blöcken für die ganze Lerneinheit, auf Rechnung des
            Kurses. Auch ganze Äußerungen wie &bdquo;Tu t'appelles comment&nbsp;?&ldquo;
            bekommen einen Lückentext &ndash; dort deckt die Lücke einen
            kennzeichnenden Teil ab.
        </p>
    <?php else: ?>
        <p class="tiny muted" style="margin:0 0 12px">
            Jede geeignete Vokabel hat mindestens einen Satz.
        </p>
    <?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <?= admin_scope_fields($scope) ?>
        <button class="btn small" name="make_sentences" value="1"<?= $openSentences > 0 ? '' : ' disabled' ?>>
            <?= $openSentences > 0 ? 'Fehlende Sätze erzeugen' : 'Nichts zu erzeugen' ?>
        </button>
    </form>
</div>

<?php if ($sentences !== []): ?>
<?php
/*
 * Die Saetze stehen unter ihren Vokabeln, nicht auf einer eigenen Seite.
 * Wer eine Vokabel berichtigt, will ihre Saetze gleich mit ansehen - frueher
 * hiess das: Link klicken, Filter stehen lassen, zurueckfinden.
 */
?>
<div class="inline" style="margin-bottom:10px">
    <label for="satzsuche" style="margin:0">Suche</label>
    <input type="search" id="satzsuche" placeholder="Satz oder Vokabel"
           style="width:260px;margin:0" autocomplete="off"
           data-filter-ziel="satzliste" data-filter-zaehler="satzzaehler">
    <span class="tiny muted" id="satzzaehler"></span>
</div>

<form method="post">
    <?= csrf_field() ?>
    <?= admin_scope_fields($scope) ?>

    <table class="data" id="satzliste">
        <tr>
            <th>Vokabel</th><th>Deutscher Satz</th>
            <th>Fremdsprache (<code>{}</code> = Lücke)</th><th>Lösung</th><th></th>
        </tr>
        <?php $vorige = 0; ?>
        <?php foreach ($sentences as $s): ?>
            <?php
            $suchtext = mb_strtolower(implode(' ', [
                (string) $s['term_foreign'], (string) $s['term_native'],
                (string) $s['native_text'], (string) $s['foreign_text'], (string) $s['answer'],
            ]));
            // Die Vokabel nur in der ersten Zeile ihrer Saetze kraeftig -
            // so sieht man die Gruppen, und gefiltert bleibt sie trotzdem lesbar.
            $erste  = (int) $s['vocab_id'] !== $vorige;
            $vorige = (int) $s['vocab_id'];
            ?>
            <tr data-suchtext="<?= h($suchtext) ?>">
                <td class="tiny<?= $erste ? '' : ' muted' ?>">
                    <?= h($s['term_foreign']) ?><br>
                    <span class="muted"><?= h($s['term_native']) ?></span>
                </td>
                <td><input type="text" name="sn[<?= (int) $s['id'] ?>]"
                           value="<?= h($s['native_text']) ?>" maxlength="255"></td>
                <td><input type="text" name="sf[<?= (int) $s['id'] ?>]"
                           value="<?= h($s['foreign_text']) ?>" maxlength="255"></td>
                <td><input type="text" name="sa[<?= (int) $s['id'] ?>]"
                           value="<?= h($s['answer']) ?>" maxlength="128" style="width:130px"></td>
                <td>
                    <button class="linkbtn" name="delete_sentence" value="<?= (int) $s['id'] ?>"
                            formnovalidate style="color:var(--bad)"
                            data-confirm="Diesen Satz löschen?">löschen</button>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>

    <button class="btn small" name="save_sentence_rows" value="1">Sätze speichern</button>
</form>

<p class="tiny muted">
    Gespeichert wird nur, was die Prüfung besteht: <?= h(SENTENCE_FORM_HINT) ?>.
    Was durchfällt, bleibt unverändert und wird gemeldet.
</p>
<?php endif; ?>

<?php endif; ?>

<?php admin_foot(); ?>
