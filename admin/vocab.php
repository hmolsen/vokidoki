<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/ai.php';
require_once __DIR__ . '/../lib/sentences.php';

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

/** Lerneinheit samt Eigentümer - im Admin ohne Beschränkung auf ein Kind. */
function own_unit_admin(int $unitId): ?array
{
    return q1('SELECT * FROM units WHERE id = ?', [$unitId]);
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
                  WHERE id = ?',
                [mb_substr($f, 0, 255), mb_substr($nv, 0, 255),
                 $nt === '' ? null : mb_substr($nt, 0, 255),
                 word_type_clean($type[$id] ?? null), $id],
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

    if (isset($_POST['save_sentence_rows'])) {
        $nat = (array) ($_POST['sn'] ?? []);
        $frn = (array) ($_POST['sf'] ?? []);
        $ans = (array) ($_POST['sa'] ?? []);
        $n   = 0;

        foreach ($nat as $id => $_v) {
            $id = (int) $id;
            $row = sentence_clean([
                'vocab_id' => 1,   // Zugehörigkeit steht schon in der Datenbank
                'native'   => (string) ($nat[$id] ?? ''),
                'foreign'  => (string) ($frn[$id] ?? ''),
                'answer'   => (string) ($ans[$id] ?? ''),
            ], [1]);

            if ($row === null) {
                continue;   // unbrauchbar - lieber nichts ändern als kaputt speichern
            }
            $n += q(
                'UPDATE sentences SET native_text = ?, foreign_text = ?, answer = ? WHERE id = ?',
                [$row['native'], $row['foreign'], $row['answer'], $id],
            )->rowCount();
        }

        flash($n === 0 ? 'Nichts geändert.' : $n . ' Satz/Sätze aktualisiert.');
        back_to_filter($userId, $langId, $unitId);
    }

    if (isset($_POST['delete_sentence'])) {
        q('DELETE FROM sentences WHERE id = ?', [(int) $_POST['delete_sentence']]);
        flash('Satz gelöscht.');
        back_to_filter($userId, $langId, $unitId);
    }

    if (isset($_POST['make_sentences'])) {
        $target = own_unit_admin((int) $_POST['make_sentences']);
        if ($target === null) {
            flash('Diese Lerneinheit gibt es nicht mehr.', 'bad');
            back_to_filter($userId, $langId, $unitId);
        }

        set_time_limit(300);
        $owner = q1('SELECT id, display_name FROM users WHERE id = ?', [(int) $target['user_id']]);

        $blocked = budget_block_reason((int) $owner['id']);
        if ($blocked !== null) {
            flash($blocked, 'bad');
            back_to_filter($userId, $langId, $unitId);
        }

        try {
            $res = generate_sentences($target, $owner);
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
        back_to_filter($userId, $langId, $unitId);
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
                "SELECT v.id, v.term_foreign, v.term_native,
                        l.name AS language, u.id AS user_id, u.display_name
                   FROM vocab v
                   JOIN units t   ON t.id = v.unit_id
                   JOIN languages l ON l.id = t.language_id
                   JOIN users u   ON u.id = t.user_id
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
        back_to_filter($userId, $langId, $unitId);
    }

    if (isset($_POST['delete_language'])) {
        $id   = (int) $_POST['delete_language'];
        $lang = q1(
            'SELECT l.name, l.flag_emoji, u.display_name
               FROM languages l JOIN users u ON u.id = l.user_id
              WHERE l.id = ?',
            [$id],
        );
        if ($lang === null) {
            flash('Diese Sprache gibt es nicht mehr.', 'bad');
            back_to_filter($userId, 0, 0);
        }

        // Vorher zählen, damit die Meldung sagt, was tatsächlich weg ist.
        $n = q1(
            'SELECT COUNT(DISTINCT t.id) AS units, COUNT(v.id) AS words
               FROM units t LEFT JOIN vocab v ON v.unit_id = t.id
              WHERE t.language_id = ?',
            [$id],
        );

        // Lerneinheiten, Vokabeln und Lernstand hängen per ON DELETE CASCADE
        // daran und verschwinden mit.
        q('DELETE FROM languages WHERE id = ?', [$id]);

        flash(sprintf(
            '%s "%s" von %s gelöscht - mit %d Lerneinheit(en) und %d Vokabel(n).',
            $lang['flag_emoji'] !== '' ? $lang['flag_emoji'] : 'Sprache',
            $lang['name'],
            $lang['display_name'],
            (int) $n['units'],
            (int) $n['words'],
        ));

        // Die Auswahl darf nicht auf etwas zeigen, das es nicht mehr gibt.
        back_to_filter($userId, 0, 0);
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
    ? qa(
        'SELECT l.id, l.name, l.flag_emoji,
                (SELECT COUNT(*) FROM units t WHERE t.language_id = l.id) AS units,
                (SELECT COUNT(*) FROM vocab v
                   JOIN units t2 ON t2.id = v.unit_id
                  WHERE t2.language_id = l.id) AS words
           FROM languages l
          WHERE l.user_id = ?
          ORDER BY l.name',
        [$userId],
      )
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

$missingTypes = (int) qv('SELECT COUNT(*) FROM vocab WHERE word_type IS NULL');

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

admin_head('Vokabeln', 'vocab.php');
flash_render();
?>

<?php if ($missingTypes > 0): ?>
<div class="card">
    <strong><?= $missingTypes ?> Vokabel(n) ohne Kategorie</strong>
    <p class="tiny muted" style="margin:6px 0 12px">
        Vokabeln, die vor dieser Funktion eingelesen wurden, haben noch keine
        Kategorie. Der Knopf lässt sie vom Modell bestimmen - in Blöcken zu 100,
        höchstens 500 je Klick. Das kostet wie eine Bilderkennung und zählt
        aufs Monatsbudget.
    </p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="user" value="<?= $userId ?>">
        <input type="hidden" name="language" value="<?= $langId ?>">
        <input type="hidden" name="unit" value="<?= $unitId ?>">
        <button class="btn small" name="fill_word_types" value="1">Kategorien nachtragen</button>
    </form>
</div>
<?php endif; ?>

<div class="card filters">
    <?= filter_chips('Kind',
        array_map(static fn (array $u): array =>
            ['id' => (int) $u['id'], 'label' => $u['display_name']], $users),
        $userId, [], 'user') ?>

    <?= filter_chips('Sprache',
        array_map(static fn (array $l): array => [
            'id'    => (int) $l['id'],
            'label' => trim($l['flag_emoji'] . ' ' . $l['name']),
        ], $languages),
        $langId, ['user' => $userId], 'language', ['unit']) ?>

    <?= filter_chips('Lerneinheit',
        array_map(static fn (array $t): array => [
            'id'    => (int) $t['id'],
            'label' => $t['title'] . ' (' . (int) $t['n'] . ')',
        ], $units),
        $unitId, ['user' => $userId, 'language' => $langId], 'unit') ?>
</div>

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
            <th>Fremdsprache</th><th>Deutsch</th><th>Kategorie</th><th>Hinweis</th>
            <th class="num">richtig</th><th class="num">falsch</th><th>Stand</th><th></th>
        </tr>
        <?php foreach ($vocab as $v): ?>
            <tr>
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
            <tr><td colspan="8" class="muted">Diese Lerneinheit ist leer.</td></tr>
        <?php endif; ?>
    </table>

    <button class="btn small" name="save_rows" value="1">Änderungen speichern</button>
</form>

<h2>Lückensätze (<?= count($sentences) ?>)</h2>

<div class="card">
    <?php $zustand = sentence_status($unitId); ?>
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
            Erzeugt wird in Blöcken für die ganze Lerneinheit. Auch ganze
            Äußerungen wie &bdquo;Tu t'appelles comment&nbsp;?&ldquo; bekommen
            einen Lückentext &ndash; dort deckt die Lücke einen
            kennzeichnenden Teil ab.
        </p>
    <?php else: ?>
        <p class="tiny muted" style="margin:0 0 12px">
            Jede geeignete Vokabel hat mindestens einen Satz.
        </p>
    <?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="user" value="<?= $userId ?>">
        <input type="hidden" name="language" value="<?= $langId ?>">
        <input type="hidden" name="unit" value="<?= $unitId ?>">
        <button class="btn small" name="make_sentences" value="<?= (int) $unit['id'] ?>">
            <?= $openSentences > 0 ? 'Fehlende Sätze erzeugen' : 'Nichts zu erzeugen' ?>
        </button>
    </form>
</div>

<?php if ($sentences !== []): ?>
    <p class="tiny muted">
        <a href="<?= h(admin_url('sentences.php') . '?' . http_build_query([
            'user' => $userId, 'language' => $langId, 'unit' => $unitId,
        ])) ?>">Diese <?= count($sentences) ?> Sätze ansehen und bearbeiten &rsaquo;</a>
    </p>
<?php endif; ?>

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
