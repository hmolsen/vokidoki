<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/sentences.php';

admin_require();

const PER_PAGE = 50;

/** Zurück zur gefilterten Ansicht, damit die Auswahl nach dem Speichern steht. */
function back_to_view(array $filter): never
{
    $query = http_build_query(array_filter($filter, static fn ($v): bool => $v !== 0 && $v !== ''));
    header('Location: ' . admin_url('sentences.php') . ($query !== '' ? '?' . $query : ''));
    exit;
}

/*
 * Die Saetze einer Lerneinheit stehen unter "Unterlagen" bei ihren Vokabeln.
 * Diese Seite ist die Suche quer durch alles: Ein Fehler der Satzerzeugung
 * trifft selten nur eine Klasse, und finden laesst er sich nur, wenn man
 * ueber die Kurse hinweg nach ihm suchen kann.
 */
$scope  = admin_scope();
$suche  = trim((string) ($_REQUEST['q'] ?? ''));
$seite  = max(1, (int) ($_REQUEST['p'] ?? 1));

$filter = admin_scope_query($scope, ['q' => $suche, 'p' => $seite]);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    if (isset($_POST['save'])) {
        $nat = (array) ($_POST['sn'] ?? []);
        $frn = (array) ($_POST['sf'] ?? []);
        $ans = (array) ($_POST['sa'] ?? []);
        $n   = 0;
        $bad = 0;

        // Die Abstandsregel für Satzzeichen hängt an der Sprache, und diese
        // Seite zeigt alle Kurse und Sprachen gemischt. Deshalb die Kürzel
        // der bearbeiteten Zeilen in einem Zug holen.
        $codes = [];
        $ids   = array_map('intval', array_keys($nat));
        if ($ids !== []) {
            foreach (qa(
                'SELECT s.id, l.code
                   FROM sentences s
                   JOIN vocab v ON v.id = s.vocab_id
                   JOIN units t ON t.id = v.unit_id
                   JOIN languages l ON l.id = t.language_id
                  WHERE s.id IN (' . implode(',', $ids) . ')'
            ) as $r) {
                $codes[(int) $r['id']] = $r['code'];
            }
        }

        foreach ($nat as $id => $_v) {
            $id   = (int) $id;
            $neue = sentence_update($id, (string) ($nat[$id] ?? ''),
                                    (string) ($frn[$id] ?? ''),
                                    (string) ($ans[$id] ?? ''), $codes[$id] ?? null);
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
        back_to_view($filter);
    }

    if (isset($_POST['delete'])) {
        q('DELETE FROM sentences WHERE id = ?', [(int) $_POST['delete']]);
        flash('Satz gelöscht.');
        back_to_view($filter);
    }
}

// ---------------------------------------------------------------- Filter

/*
 * Hier stand ein Filter "Kind" und darunter einer, der "Kurs" hiess, aber
 * die Sprache trug. Beides stammte aus der Zeit, als ein Kind seine
 * Vokabeln besass. Gefiltert wird jetzt, wo die Saetze wirklich haengen.
 */
$where  = [];
$params = [];
foreach (['school' => 'co.school_id', 'course' => 't.course_id', 'unit' => 't.id'] as $k => $spalte) {
    if ($scope['ids'][$k] > 0) {
        $where[]  = $spalte . ' = ?';
        $params[] = $scope['ids'][$k];
    }
}
if ($suche !== '') {
    $where[]  = '(s.native_text LIKE ? OR s.foreign_text LIKE ? OR s.answer LIKE ?'
              . ' OR v.term_foreign LIKE ?)';
    $like     = '%' . $suche . '%';
    array_push($params, $like, $like, $like, $like);
}
$sql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

$gesamt = (int) qv(
    'SELECT COUNT(*) FROM sentences s
       JOIN vocab v ON v.id = s.vocab_id
       JOIN units t ON t.id = v.unit_id
       LEFT JOIN courses co ON co.id = t.course_id' . $sql,
    $params,
);

$seiten = max(1, (int) ceil($gesamt / PER_PAGE));
$seite  = min($seite, $seiten);
$offset = ($seite - 1) * PER_PAGE;

/*
 * Gemeldete Sätze stehen nicht mehr hier, sondern unter "Meldungen" - dort,
 * wo auch die Lehrkraft sie sieht, eine nach der anderen, zusammen mit den
 * Meldungen aus dem Auswählen.
 */
$rows = qa(
    // Statt "wem gehoert der Satz" steht hier jetzt der Kurs. Ein Satz hat
    // keinen Besitzer mehr - er gehoert zu Unterlagen, mit denen eine ganze
    // Gruppe arbeitet.
    'SELECT s.*, v.term_foreign, v.term_native, t.title AS unit_title,
            t.id AS unit_id, t.course_id, co.school_id,
            l.name AS language, co.name AS display_name, sc.name AS school_name
       FROM sentences s
       JOIN vocab v ON v.id = s.vocab_id
       JOIN units t ON t.id = v.unit_id
       JOIN languages l ON l.id = t.language_id
       LEFT JOIN courses co ON co.id = t.course_id
       LEFT JOIN schools sc ON sc.id = co.school_id' . $sql . '
      ORDER BY sc.name, co.name, t.position, t.id, v.position, s.id
      LIMIT ' . PER_PAGE . ' OFFSET ' . $offset,
    $params,
);

/** Erhält die Filter beim Blättern. */
function page_link(array $filter, int $seite): string
{
    $filter['p'] = $seite;
    return admin_url('sentences.php') . '?' . http_build_query(
        array_filter($filter, static fn ($v): bool => $v !== 0 && $v !== '')
    );
}

// Unter "Unterlagen" eingehaengt - von dort kommt man her.
admin_head('Lückensätze', 'sentences.php');
flash_render();
?>

<p class="tiny muted">
    <a href="<?= h(admin_url('vocab.php') . (admin_scope_query($scope) === [] ? ''
        : '?' . http_build_query(admin_scope_query($scope)))) ?>">&lsaquo; Zurück zu den Unterlagen</a>
</p>

<div class="card filters">
    <?= admin_scope_chips($scope, ['q' => $suche], true) ?>

    <form method="get" class="filterrow">
        <span class="lbl">Suche</span>
        <span class="inline">
            <?= admin_scope_fields($scope) ?>
            <?php
            /*
             * Tippen filtert sofort, ohne die Seite neu zu laden - bei
             * zweihundert Saetzen sucht man nicht einmal, sondern zehnmal
             * hintereinander, und jedesmal war der Bildschirm kurz weg.
             *
             * Der Knopf bleibt trotzdem: Der Sofortfilter sieht nur, was
             * auf dieser Seite steht. Wer im ganzen Bestand sucht, schickt
             * ab - und ohne JavaScript ist das ohnehin der einzige Weg.
             */
            ?>
            <input type="text" name="q" value="<?= h($suche) ?>"
                   placeholder="Satz oder Vokabel" style="margin:0;width:200px"
                   data-filter-ziel="satzliste" data-filter-zaehler="satzzaehler"
                   autocomplete="off">
            <button class="btn small secondary">Im ganzen Bestand suchen</button>
            <span class="tiny muted" id="satzzaehler"></span>
            <?php if ($suche !== ''): ?>
                <a class="chip" href="<?= h(admin_url('sentences.php') . '?' . http_build_query(
                    admin_scope_query($scope)
                )) ?>">zurücksetzen</a>
            <?php endif; ?>
        </span>
    </form>
</div>

<?php if ($gesamt === 0): ?>
    <p class="muted">Keine Sätze gefunden.</p>
<?php else: ?>

<p class="tiny muted"><?= $gesamt ?> Satz/Sätze</p>

<form method="post">
    <?= csrf_field() ?>
    <?= admin_scope_fields($scope, ['q' => $suche, 'p' => $seite]) ?>

    <table class="data" id="satzliste">
        <tr>
            <th>Wo</th><th>Vokabel</th><th>Deutscher Satz</th>
            <th>Fremdsprache (<code>{}</code> = Lücke)</th><th>Lösung</th>
            <th></th>
        </tr>
        <?php foreach ($rows as $s): ?>
            <?php
            /*
             * Wonach der Sofortfilter sucht. Es steht als Attribut da und
             * nicht im Text der Zeile, weil in den Zellen Eingabefelder
             * stehen - und deren Inhalt gehoert nicht zum Text des
             * Elements. Der Kurs ist mit dabei: Danach sortiert die Liste,
             * und danach sucht man auch.
             */
            $suchtext = mb_strtolower(implode(' ', [
                (string) ($s['school_name'] ?? ''),
                (string) ($s['display_name'] ?? ''), (string) $s['language'],
                (string) $s['unit_title'], (string) $s['term_foreign'],
                (string) $s['term_native'], (string) $s['native_text'],
                (string) $s['foreign_text'], (string) $s['answer'],
            ]));
            ?>
            <tr data-suchtext="<?= h($suchtext) ?>">
                <td class="tiny muted">
                    <?= h((string) ($s['school_name'] ?? '')) ?><br>
                    <?= $s['display_name'] === null ? '&ndash;' : h((string) $s['display_name']) ?>
                    &middot;
                    <?php // Die Einheit fuehrt dorthin, wo ihre Vokabeln stehen. ?>
                    <a href="<?= h(admin_url('vocab.php') . '?' . http_build_query(array_filter([
                        'school' => (int) $s['school_id'], 'course' => (int) $s['course_id'],
                        'unit'   => (int) $s['unit_id'],
                    ]))) ?>"><?= h($s['unit_title']) ?></a>
                </td>
                <td class="tiny">
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
                    <button class="linkbtn" name="delete" value="<?= (int) $s['id'] ?>"
                            formnovalidate style="color:var(--bad)"
                            onclick="return confirm('Diesen Satz löschen?')">löschen</button>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>

    <div class="inline" style="margin-bottom:14px">
        <button class="btn small" name="save" value="1">Änderungen speichern</button>
    </div>
</form>

<?php
/*
 * Die Blätterleiste steht bewusst ausserhalb des Formulars. Ein Klick darauf
 * verlaesst die Seite, und alles, was in den Feldern steht und noch nicht
 * gespeichert wurde, ist dann weg - direkt neben dem Speichern-Knopf war das
 * eine Falle.
 */
echo pager($seite, $seiten, static fn (int $n): string => page_link($filter, $n));
?>

<p class="tiny muted">
    Gespeichert wird nur, was die Prüfung besteht: genau eine Lücke <code>{}</code>
    im fremdsprachigen Satz, keine im deutschen, eine nicht leere Lösung, und die
    Lösung darf nicht daneben im Satz stehen. Was durchfällt, bleibt unverändert
    und wird gemeldet.
</p>

<?php endif; ?>

<?php admin_foot(); ?>
