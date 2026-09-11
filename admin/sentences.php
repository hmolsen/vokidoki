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

$filter = ['user' => $userId, 'language' => $langId, 'unit' => $unitId, 'q' => $suche, 'p' => $seite];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    if (isset($_POST['save'])) {
        $nat = (array) ($_POST['sn'] ?? []);
        $frn = (array) ($_POST['sf'] ?? []);
        $ans = (array) ($_POST['sa'] ?? []);
        $n   = 0;
        $bad = 0;

        foreach ($nat as $id => $_v) {
            $id  = (int) $id;
            $row = sentence_clean([
                // Die Zugehörigkeit steht in der Datenbank; hier zählt nur die Form.
                'vocab_id' => 1,
                'native'   => (string) ($nat[$id] ?? ''),
                'foreign'  => (string) ($frn[$id] ?? ''),
                'answer'   => (string) ($ans[$id] ?? ''),
            ], [1]);

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

$rows = qa(
    'SELECT s.*, v.term_foreign, v.term_native, t.title AS unit_title,
            l.name AS language, u.display_name
       FROM sentences s
       JOIN vocab v ON v.id = s.vocab_id
       JOIN units t ON t.id = v.unit_id
       JOIN languages l ON l.id = t.language_id
       JOIN users u ON u.id = t.user_id' . $sql . '
      ORDER BY u.display_name, l.name, t.created_at DESC, v.position, s.id
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

admin_head('Lückensätze', 'sentences.php');
flash_render();
?>

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

<p class="tiny muted">
    <?= $gesamt ?> Satz/Sätze<?= $seiten > 1 ? sprintf(' &middot; Seite %d von %d', $seite, $seiten) : '' ?>
</p>

<form method="post">
    <?= csrf_field() ?>
    <?php foreach (['user' => $userId, 'language' => $langId, 'unit' => $unitId,
                    'q' => $suche, 'p' => $seite] as $k => $v): ?>
        <input type="hidden" name="<?= h($k) ?>" value="<?= h((string) $v) ?>">
    <?php endforeach; ?>

    <table class="data">
        <tr>
            <th>Wo</th><th>Vokabel</th><th>Deutscher Satz</th>
            <th>Fremdsprache (<code>{}</code> = Lücke)</th><th>Lösung</th><th></th>
        </tr>
        <?php foreach ($rows as $s): ?>
            <tr>
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
        <?php if ($seiten > 1): ?>
            <span class="tiny muted">
                <?php if ($seite > 1): ?>
                    <a href="<?= h(page_link($filter, $seite - 1)) ?>">&laquo; zurück</a>
                <?php endif; ?>
                <?php if ($seite < $seiten): ?>
                    <a href="<?= h(page_link($filter, $seite + 1)) ?>">weiter &raquo;</a>
                <?php endif; ?>
            </span>
        <?php endif; ?>
    </div>
</form>

<p class="tiny muted">
    Gespeichert wird nur, was die Prüfung besteht: genau eine Lücke <code>{}</code>
    im fremdsprachigen Satz, keine im deutschen, eine nicht leere Lösung, und die
    Lösung darf nicht daneben im Satz stehen. Was durchfällt, bleibt unverändert
    und wird gemeldet.
</p>

<?php endif; ?>

<?php admin_foot(); ?>
