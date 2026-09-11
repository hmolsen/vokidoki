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

$userId = (int) ($_REQUEST['user'] ?? 0);
$langId = (int) ($_REQUEST['language'] ?? 0);
$unitId = (int) ($_REQUEST['unit'] ?? 0);
$suche  = trim((string) ($_REQUEST['q'] ?? ''));
$seite  = max(1, (int) ($_REQUEST['p'] ?? 1));
$nurGemeldet = (int) ($_REQUEST['flagged'] ?? 0) === 1;

$filter = ['user' => $userId, 'language' => $langId, 'unit' => $unitId,
           'q' => $suche, 'p' => $seite, 'flagged' => $nurGemeldet ? 1 : 0];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    if (isset($_POST['save'])) {
        $nat = (array) ($_POST['sn'] ?? []);
        $frn = (array) ($_POST['sf'] ?? []);
        $ans = (array) ($_POST['sa'] ?? []);
        $n   = 0;
        $bad = 0;

        // Die Abstandsregel für Satzzeichen hängt an der Sprache, und diese
        // Seite zeigt alle Kinder und Sprachen gemischt. Deshalb die Kürzel
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
            $id  = (int) $id;
            $row = sentence_clean([
                // Die Zugehörigkeit steht in der Datenbank; hier zählt nur die Form.
                'vocab_id' => 1,
                'native'   => (string) ($nat[$id] ?? ''),
                'foreign'  => (string) ($frn[$id] ?? ''),
                'answer'   => (string) ($ans[$id] ?? ''),
            ], [1], $codes[$id] ?? null);

            if ($row === null) {
                $bad++;
                continue;   // lieber nichts ändern als kaputt speichern
            }
            $n += q(
                'UPDATE sentences SET native_text = ?, foreign_text = ?, answer = ? WHERE id = ?',
                [$row['native'], $row['foreign'], $row['answer'], $id],
            )->rowCount();
        }

        $text = $n === 0 ? 'Nichts geändert.' : $n . ' Satz/Sätze aktualisiert.';
        if ($bad > 0) {
            flash($text . sprintf(' %d wurde(n) nicht gespeichert, weil die Form nicht stimmt '
                . '(genau eine Lücke {} im fremdsprachigen Satz, Lösung nicht leer '
                . 'und nicht daneben im Satz).', $bad), 'bad');
        } else {
            flash($text);
        }
        back_to_view($filter);
    }

    if (isset($_POST['clear_flags'])) {
        $id = (int) $_POST['clear_flags'];
        $n  = q('DELETE FROM sentence_flags WHERE sentence_id = ?', [$id])->rowCount();
        flash($n === 0
            ? 'Für diesen Satz lag keine Meldung vor.'
            : sprintf('Meldung zurückgenommen (%d Eintrag/Einträge).', $n));
        back_to_view($filter);
    }

    if (isset($_POST['delete'])) {
        q('DELETE FROM sentences WHERE id = ?', [(int) $_POST['delete']]);
        flash('Satz gelöscht.');
        back_to_view($filter);
    }
}

// ---------------------------------------------------------------- Filter

$users = qa('SELECT id, display_name FROM users ORDER BY display_name');

$languages = $userId > 0
    ? qa('SELECT id, name, flag_emoji FROM languages WHERE user_id = ? ORDER BY name', [$userId])
    : [];

$units = $langId > 0
    ? qa('SELECT id, title FROM units WHERE language_id = ? ORDER BY created_at DESC', [$langId])
    : [];

$where  = [];
$params = [];
if ($userId > 0) {
    $where[]  = 't.user_id = ?';
    $params[] = $userId;
}
if ($langId > 0) {
    $where[]  = 't.language_id = ?';
    $params[] = $langId;
}
if ($unitId > 0) {
    $where[]  = 't.id = ?';
    $params[] = $unitId;
}
if ($suche !== '') {
    $where[]  = '(s.native_text LIKE ? OR s.foreign_text LIKE ? OR s.answer LIKE ?'
              . ' OR v.term_foreign LIKE ?)';
    $like     = '%' . $suche . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($nurGemeldet) {
    $where[] = 'EXISTS (SELECT 1 FROM sentence_flags f WHERE f.sentence_id = s.id)';
}
$sql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

$gesamt = (int) qv(
    'SELECT COUNT(*) FROM sentences s
       JOIN vocab v ON v.id = s.vocab_id
       JOIN units t ON t.id = v.unit_id' . $sql,
    $params,
);

$seiten = max(1, (int) ceil($gesamt / PER_PAGE));
$seite  = min($seite, $seiten);
$offset = ($seite - 1) * PER_PAGE;

/*
 * Gemeldete Sätze stehen immer oben, auch ohne Filter.
 *
 * Sie zwischen hunderten heraussuchen zu müssen, wäre die sicherste Art,
 * sie nie zu bearbeiten - und ein gemeldeter Satz ist genau der, der Arbeit
 * verlangt.
 */
$rows = qa(
    'SELECT s.*, v.term_foreign, v.term_native, t.title AS unit_title,
            l.name AS language, u.display_name,
            (SELECT COUNT(*) FROM sentence_flags f WHERE f.sentence_id = s.id) AS flags
       FROM sentences s
       JOIN vocab v ON v.id = s.vocab_id
       JOIN units t ON t.id = v.unit_id
       JOIN languages l ON l.id = t.language_id
       JOIN users u ON u.id = t.user_id' . $sql . '
      ORDER BY flags DESC, u.display_name, l.name, t.created_at DESC, v.position, s.id
      LIMIT ' . PER_PAGE . ' OFFSET ' . $offset,
    $params,
);

// Wer hat gemeldet, und was war eingetippt? Genau das entscheidet meist, ob
// der Satz schief war oder die erwartete Antwort.
$meldungen = [];
$ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
if ($ids !== []) {
    foreach (qa(
        'SELECT f.sentence_id, f.typed, u.display_name, f.created_at
           FROM sentence_flags f
           JOIN users u ON u.id = f.user_id
          WHERE f.sentence_id IN (' . implode(',', $ids) . ')
          ORDER BY f.created_at DESC'
    ) as $f) {
        $meldungen[(int) $f['sentence_id']][] = $f;
    }
}

$offeneMeldungen = (int) qv('SELECT COUNT(DISTINCT sentence_id) FROM sentence_flags');

/** Erhält die Filter beim Blättern. */
function page_link(array $filter, int $seite): string
{
    $filter['p'] = $seite;
    return admin_url('sentences.php') . '?' . http_build_query(
        array_filter($filter, static fn ($v): bool => $v !== 0 && $v !== '')
    );
}

admin_head('Lückensätze', 'sentences.php');
flash_render();
?>

<?php if ($offeneMeldungen > 0): ?>
<div class="card flagged-note">
    <strong>&#9873; <?= $offeneMeldungen ?> gemeldete<?= $offeneMeldungen === 1 ? 'r' : '' ?> Satz/Sätze</strong>
    <p class="tiny muted" style="margin:6px 0 12px">
        Ein Kind hat hier etwas als möglicherweise falsch markiert. Gemeldete
        Sätze stehen in der Liste immer oben - unabhängig von Filter und Seite.
        War die Meldung unbegründet, nimmt <em>erledigt</em> sie zurück.
    </p>
    <?php if (!$nurGemeldet): ?>
        <a class="btn small" href="<?= h(admin_url('sentences.php') . '?flagged=1') ?>">Nur gemeldete zeigen</a>
    <?php else: ?>
        <a class="btn secondary small" href="<?= h(admin_url('sentences.php')) ?>">Alle Sätze zeigen</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card filters">
    <?= filter_chips('Kind',
        array_map(static fn (array $u): array =>
            ['id' => (int) $u['id'], 'label' => $u['display_name']], $users),
        $userId, ['q' => $suche], 'user', ['language', 'unit', 'p'], 'alle') ?>

    <?= filter_chips('Sprache',
        array_map(static fn (array $l): array => [
            'id'    => (int) $l['id'],
            'label' => trim($l['flag_emoji'] . ' ' . $l['name']),
        ], $languages),
        $langId, ['user' => $userId, 'q' => $suche], 'language', ['unit', 'p'], 'alle') ?>

    <?= filter_chips('Lerneinheit',
        array_map(static fn (array $t): array =>
            ['id' => (int) $t['id'], 'label' => $t['title']], $units),
        $unitId, ['user' => $userId, 'language' => $langId, 'q' => $suche], 'unit', ['p'], 'alle') ?>

    <form method="get" class="filterrow">
        <span class="lbl">Suche</span>
        <span class="inline">
            <?php foreach (['user' => $userId, 'language' => $langId, 'unit' => $unitId] as $k => $v): ?>
                <?php if ($v > 0): ?>
                    <input type="hidden" name="<?= h($k) ?>" value="<?= (int) $v ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <input type="text" name="q" value="<?= h($suche) ?>"
                   placeholder="Satz oder Vokabel" style="margin:0;width:200px">
            <button class="btn secondary small">Suchen</button>
            <?php if ($suche !== ''): ?>
                <a class="chip" href="<?= h(admin_url('sentences.php') . '?' . http_build_query(
                    array_filter(['user' => $userId, 'language' => $langId, 'unit' => $unitId])
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
    <?php foreach (['user' => $userId, 'language' => $langId, 'unit' => $unitId,
                    'q' => $suche, 'p' => $seite,
                    'flagged' => $nurGemeldet ? 1 : 0] as $k => $v): ?>
        <input type="hidden" name="<?= h($k) ?>" value="<?= h((string) $v) ?>">
    <?php endforeach; ?>

    <table class="data">
        <tr>
            <th>Wo</th><th>Vokabel</th><th>Deutscher Satz</th>
            <th>Fremdsprache (<code>{}</code> = Lücke)</th><th>Lösung</th>
            <th>Gemeldet</th><th></th>
        </tr>
        <?php foreach ($rows as $s): ?>
            <tr<?= (int) $s['flags'] > 0 ? ' class="flagged"' : '' ?>>
                <td class="tiny muted">
                    <?= h($s['display_name']) ?><br>
                    <?= h($s['language']) ?> &middot; <?= h($s['unit_title']) ?>
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
                <td class="tiny">
                    <?php if ((int) $s['flags'] > 0): ?>
                        <span class="flagcount">&#9873; <?= (int) $s['flags'] ?></span>
                        <?php foreach ($meldungen[(int) $s['id']] ?? [] as $f): ?>
                            <div class="muted" style="margin-top:4px">
                                <?= h($f['display_name']) ?>
                                <?php if (($f['typed'] ?? '') !== ''): ?>
                                    tippte &bdquo;<?= h($f['typed']) ?>&ldquo;
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <button class="linkbtn" name="clear_flags" value="<?= (int) $s['id'] ?>"
                                formnovalidate style="margin-top:4px">erledigt</button>
                    <?php else: ?>
                        <span class="muted">&ndash;</span>
                    <?php endif; ?>
                </td>
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
