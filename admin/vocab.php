<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

admin_require();

/** Zurück zur gefilterten Ansicht, damit die Auswahl nach dem Speichern steht. */
function back_to_filter(int $userId, int $languageId, int $unitId): never
{
    $query = http_build_query(array_filter([
        'user'     => $userId ?: null,
        'language' => $languageId ?: null,
        'unit'     => $unitId ?: null,
    ]));
    header('Location: ' . admin_url('vocab.php') . ($query !== '' ? '?' . $query : ''));
    exit;
}

$userId = (int) ($_REQUEST['user'] ?? 0);
$langId = (int) ($_REQUEST['language'] ?? 0);
$unitId = (int) ($_REQUEST['unit'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    if (isset($_POST['save_rows'])) {
        $foreign = (array) ($_POST['f'] ?? []);
        $native  = (array) ($_POST['n'] ?? []);
        $note    = (array) ($_POST['note'] ?? []);
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
                'UPDATE vocab SET term_foreign = ?, term_native = ?, note = ? WHERE id = ?',
                [mb_substr($f, 0, 255), mb_substr($nv, 0, 255),
                 $nt === '' ? null : mb_substr($nt, 0, 255), $id],
            )->rowCount();
        }

        if (isset($_POST['unit_title'], $_POST['unit_id'])) {
            $title = trim((string) $_POST['unit_title']);
            if ($title !== '') {
                q('UPDATE units SET title = ? WHERE id = ?',
                  [mb_substr($title, 0, 128), (int) $_POST['unit_id']]);
            }
        }

        flash($changed === 0 ? 'Nichts geändert.' : $changed . ' Vokabel(n) aktualisiert.');
        back_to_filter($userId, $langId, $unitId);
    }

    if (isset($_POST['delete_vocab'])) {
        q('DELETE FROM vocab WHERE id = ?', [(int) $_POST['delete_vocab']]);
        flash('Vokabel gelöscht.');
        back_to_filter($userId, $langId, $unitId);
    }

    if (isset($_POST['add_vocab'])) {
        $target = (int) $_POST['add_vocab'];
        $f      = trim((string) ($_POST['new_f'] ?? ''));
        $nv     = trim((string) ($_POST['new_n'] ?? ''));
        if ($f === '' || $nv === '') {
            flash('Beide Felder ausfüllen.', 'bad');
        } else {
            $pos = (int) (qv('SELECT COALESCE(MAX(position), -1) + 1 FROM vocab WHERE unit_id = ?', [$target]) ?? 0);
            q(
                'INSERT INTO vocab (unit_id, term_foreign, term_native, position) VALUES (?, ?, ?, ?)',
                [$target, mb_substr($f, 0, 255), mb_substr($nv, 0, 255), $pos],
            );
            flash('Vokabel ergänzt.');
        }
        back_to_filter($userId, $langId, $unitId);
    }
}

$users = qa('SELECT id, display_name, color FROM users ORDER BY display_name');

$languages = $userId > 0
    ? qa('SELECT id, name, flag_emoji FROM languages WHERE user_id = ? ORDER BY name', [$userId])
    : [];

$units = $langId > 0
    ? qa(
        'SELECT u.id, u.title, COUNT(v.id) AS n
           FROM units u LEFT JOIN vocab v ON v.unit_id = u.id
          WHERE u.language_id = ?
          GROUP BY u.id, u.title
          ORDER BY u.created_at DESC',
        [$langId],
      )
    : [];

$unit  = $unitId > 0 ? q1('SELECT * FROM units WHERE id = ?', [$unitId]) : null;
$vocab = $unit !== null
    ? qa(
        "SELECT v.*, p.streak, p.correct_count, p.wrong_count, p.known_at
           FROM vocab v
           LEFT JOIN progress p ON p.vocab_id = v.id AND p.mode = 'mc'
          WHERE v.unit_id = ?
          ORDER BY v.position, v.id",
        [$unitId],
      )
    : [];

admin_head('Vokabeln', 'vocab.php');
flash_render();
?>

<form method="get" class="card">
    <div class="formgrid">
        <div>
            <label for="user">Kind</label>
            <select name="user" id="user" onchange="this.form.submit()">
                <option value="0">- wählen -</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= (int) $u['id'] ?>"<?= (int) $u['id'] === $userId ? ' selected' : '' ?>>
                        <?= h($u['display_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($languages !== []): ?>
        <div>
            <label for="language">Sprache</label>
            <select name="language" id="language" onchange="this.form.submit()">
                <option value="0">- wählen -</option>
                <?php foreach ($languages as $l): ?>
                    <option value="<?= (int) $l['id'] ?>"<?= (int) $l['id'] === $langId ? ' selected' : '' ?>>
                        <?= h($l['flag_emoji'] . ' ' . $l['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <?php if ($units !== []): ?>
        <div>
            <label for="unit">Lerneinheit</label>
            <select name="unit" id="unit" onchange="this.form.submit()">
                <option value="0">- wählen -</option>
                <?php foreach ($units as $t): ?>
                    <option value="<?= (int) $t['id'] ?>"<?= (int) $t['id'] === $unitId ? ' selected' : '' ?>>
                        <?= h($t['title']) ?> (<?= (int) $t['n'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>
    <noscript><button class="btn small">Anzeigen</button></noscript>
</form>

<?php if ($userId === 0): ?>
    <p class="muted">Wähle oben ein Kind aus.</p>
<?php elseif ($langId === 0): ?>
    <p class="muted"><?= $languages === []
        ? 'Dieses Kind hat noch keine Sprache angelegt.'
        : 'Wähle eine Sprache aus.' ?></p>
<?php elseif ($unit === null): ?>
    <p class="muted"><?= $units === []
        ? 'In dieser Sprache gibt es noch keine Lerneinheit.'
        : 'Wähle eine Lerneinheit aus.' ?></p>
<?php else: ?>

<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="user" value="<?= $userId ?>">
    <input type="hidden" name="language" value="<?= $langId ?>">
    <input type="hidden" name="unit" value="<?= $unitId ?>">
    <input type="hidden" name="unit_id" value="<?= (int) $unit['id'] ?>">

    <div class="inline" style="margin-bottom:14px">
        <label for="unit_title" style="margin:0">Titel</label>
        <input type="text" id="unit_title" name="unit_title" value="<?= h($unit['title']) ?>"
               maxlength="128" style="width:280px;margin:0">
    </div>

    <table class="data">
        <tr>
            <th>Fremdsprache</th><th>Deutsch</th><th>Hinweis</th>
            <th class="num">richtig</th><th class="num">falsch</th><th>Stand</th><th></th>
        </tr>
        <?php foreach ($vocab as $v): ?>
            <tr>
                <td><input type="text" name="f[<?= (int) $v['id'] ?>]" value="<?= h($v['term_foreign']) ?>" maxlength="255"></td>
                <td><input type="text" name="n[<?= (int) $v['id'] ?>]" value="<?= h($v['term_native']) ?>" maxlength="255"></td>
                <td><input type="text" name="note[<?= (int) $v['id'] ?>]" value="<?= h((string) ($v['note'] ?? '')) ?>" maxlength="255"></td>
                <td class="num"><?= (int) ($v['correct_count'] ?? 0) ?></td>
                <td class="num"><?= (int) ($v['wrong_count'] ?? 0) ?></td>
                <td class="tiny muted">
                    <?= $v['known_at'] !== null
                        ? 'gekonnt'
                        : (int) ($v['streak'] ?? 0) . '/3' ?>
                </td>
                <td>
                    <button class="linkbtn" name="delete_vocab" value="<?= (int) $v['id'] ?>"
                            formnovalidate style="color:var(--bad)"
                            onclick="return confirm('Diese Vokabel löschen?')">löschen</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($vocab === []): ?>
            <tr><td colspan="7" class="muted">Diese Lerneinheit ist leer.</td></tr>
        <?php endif; ?>
    </table>

    <button class="btn small" name="save_rows" value="1">Änderungen speichern</button>
</form>

<h2>Vokabel ergänzen</h2>
<form method="post" class="card inline">
    <?= csrf_field() ?>
    <input type="hidden" name="user" value="<?= $userId ?>">
    <input type="hidden" name="language" value="<?= $langId ?>">
    <input type="hidden" name="unit" value="<?= $unitId ?>">
    <input type="text" name="new_f" placeholder="Fremdsprache" maxlength="255" style="width:220px;margin:0">
    <input type="text" name="new_n" placeholder="Deutsch" maxlength="255" style="width:220px;margin:0">
    <button class="btn secondary small" name="add_vocab" value="<?= (int) $unit['id'] ?>">Hinzufügen</button>
</form>

<?php endif; ?>

<?php admin_foot(); ?>
