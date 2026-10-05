<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

/*
 * Die Lehrkräfte dieser Schule - anlegen, neues Passwort, löschen.
 *
 * Gebaut wie die Kinder einer Klasse (class.php): Anfangspasswort, Zettel
 * zum Drucken, ein Passwort zurücksetzen. Nur mit einer Hürde davor: Wer die
 * Seite öffnet, gibt sein Passwort noch einmal ein, und für fünf Minuten
 * gilt eine erhöhte Sitzung (lib/lehrkraefte.php). Jeder Handgriff hier
 * prüft sie - nicht nur das Öffnen der Seite.
 */

$user     = teacher_require();
$schoolId = (int) ($user['school_id'] ?? 0);
$uid      = (int) $user['id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    teacher_csrf_check();

    // ---- Die Hürde: das eigene Passwort noch einmal.
    if (isset($_POST['erhoehen'])) {
        $grund = erhoeht_starten($user, (string) ($_POST['password'] ?? ''));
        if ($grund !== null) {
            teacher_flash($grund, 'bad');
        }
        teacher_redirect('lehrkraefte.php');
    }

    // Alles Weitere nur in der erhöhten Sitzung - sie kann zwischen Laden
    // und Absenden abgelaufen sein.
    if (!erhoeht_aktiv($user)) {
        teacher_flash('Die Verwaltungssitzung ist abgelaufen - bitte das Passwort noch einmal eingeben.', 'bad');
        teacher_redirect('lehrkraefte.php');
    }

    // Nur Lehrkräfte dieser Schule - die Kennung kommt aus dem Formular.
    $kollegin = static function (int $id) use ($schoolId): ?array {
        return q1("SELECT * FROM users WHERE id = ? AND school_id = ? AND role = 'teacher'", [$id, $schoolId]);
    };

    if (isset($_POST['add_teacher'])) {
        $neu = lehrkraft_anlegen($schoolId, (string) ($_POST['display_name'] ?? ''),
                                 (string) ($_POST['username'] ?? ''));
        if (is_string($neu)) {
            teacher_flash($neu, 'bad');
        } else {
            teacher_druckbar_merken([(int) $neu['id']]);
            $_SESSION['teacher_fresh'] = [(int) $neu['id']];
            teacher_flash(sprintf('%s ist angelegt. Den Zettel jetzt drucken - das Anfangspasswort ist nur jetzt zu sehen.',
                $neu['display_name']));
        }
        teacher_redirect('lehrkraefte.php');
    }

    if (isset($_POST['reset_password'])) {
        $k = $kollegin((int) $_POST['reset_password']);
        if ($k === null || (int) $k['id'] === $uid) {
            // Das eigene Passwort ändert man unter "Mein Konto" - mit dem alten.
            teacher_flash('Das geht hier nicht.', 'bad');
            teacher_redirect('lehrkraefte.php');
        }
        if (student_reset_password((int) $k['id']) === null) {
            teacher_flash('Es liess sich kein neues Passwort bilden.', 'bad');
        } else {
            teacher_druckbar_merken([(int) $k['id']]);
            $_SESSION['teacher_fresh'] = [(int) $k['id']];
            teacher_flash(sprintf('%s hat ein neues Anfangspasswort - den Zettel jetzt drucken.', $k['display_name']));
        }
        teacher_redirect('lehrkraefte.php');
    }

    if (isset($_POST['delete_teacher'])) {
        $k = $kollegin((int) $_POST['delete_teacher']);
        if ($k === null || (int) $k['id'] === $uid) {
            teacher_flash('Das geht hier nicht.', 'bad');
            teacher_redirect('lehrkraefte.php');
        }
        $uebernommen = lehrkraft_loeschen((int) $k['id'], $uid);
        teacher_flash(sprintf('%s ist gelöscht.%s', $k['display_name'], $uebernommen > 0
            ? sprintf(' Du führst jetzt %d %s, die %s allein hatte.', $uebernommen,
                      $uebernommen === 1 ? 'Kurs' : 'Kurse', $k['display_name'])
            : ''));
        teacher_redirect('lehrkraefte.php');
    }

    teacher_redirect('lehrkraefte.php');
}

teacher_head('Lehrkräfte dieser Schule', $user);
teacher_flash_render();

// ---- Ohne erhöhte Sitzung: nur die Hürde.
if (!erhoeht_aktiv($user)): ?>
<div class="card" style="max-width:460px">
    <p style="margin-top:0">
        Hier legst du Konten für Kolleginnen und Kollegen an und gibst ihnen ein neues
        Passwort, wenn sie es vergessen haben. Weil das Konten anderer Erwachsener betrifft,
        bestätige bitte zuerst <strong>dein eigenes Passwort</strong>.
    </p>
    <p class="tiny muted">
        Danach bist du fünf Minuten lang in einer Verwaltungssitzung &ndash; ein gelbes Band oben
        zeigt, wie lange noch.
    </p>
    <form method="post">
        <?= teacher_csrf_field() ?>
        <label for="pw">Dein Passwort</label>
        <input type="password" id="pw" name="password" autocomplete="current-password" required autofocus>
        <button class="btn" name="erhoehen" value="1">Weiter</button>
    </form>
</div>
<?php
teacher_foot();
exit;
endif;

// ---- In der erhöhten Sitzung: die Liste.
$lehrkraefte = lehrkraefte_der_schule($schoolId);
$grenze      = lehrkraefte_grenze($schoolId);
$druckbar    = teacher_druckbar();
$frisch      = array_flip((array) ($_SESSION['teacher_fresh'] ?? []));
unset($_SESSION['teacher_fresh']);
$zuDrucken   = count(array_filter($lehrkraefte, static fn (array $l): bool =>
    isset($druckbar[(int) $l['id']]) && (string) ($l['initial_password'] ?? '') !== ''));
?>

<p class="tiny muted">
    <?= count($lehrkraefte) ?> von höchstens <?= $grenze ?> Lehrkräften.
    Zum Anmelden: Schulkürzel <code class="token"><?= h(schulkuerzel_von($schoolId)) ?></code>,
    Benutzername und Passwort. Vergisst jemand sein Passwort, gibt ihm hier eine Kollegin ein neues
    &ndash; dafür braucht Vokidoki keine E-Mail-Adressen.
</p>

<table class="data courses" id="lehrkraefte">
    <tr>
        <th>Name</th>
        <th>Benutzername</th>
        <th>Anfangspasswort</th>
        <th class="num">Kurse</th>
        <th class="actions"></th>
    </tr>
    <?php foreach ($lehrkraefte as $l): ?>
        <?php
        $id     = (int) $l['id'];
        $selbst = $id === $uid;
        ?>
        <tr<?= isset($frisch[$id]) ? ' class="hit"' : ($l['active'] ? '' : ' class="dim"') ?> data-lehrkraft="<?= $id ?>">
            <td data-label="Name">
                <span class="coursetitle">
                    <span class="cflag" aria-hidden="true">&#129489;&#8205;&#127979;</span>
                    <span><strong><?= h($l['display_name']) ?></strong>
                        <?= $selbst ? '<span class="tiny muted">(du)</span>' : '' ?></span>
                </span>
            </td>
            <td data-label="Benutzername"><code class="token"><?= h($l['username']) ?></code></td>
            <td data-label="Anfangspasswort">
                <?php // Wie bei den Kindern: nur direkt nach dem Vergeben (teacher_druckbar()). ?>
                <?php if (isset($druckbar[$id]) && (string) ($l['initial_password'] ?? '') !== ''): ?>
                    <code class="token"><?= h($l['initial_password']) ?></code>
                <?php else: ?>
                    <span class="tiny muted" title="Zu sehen nur direkt nach dem Vergeben">&bull;&bull;&bull;&bull;&bull;&bull;</span>
                <?php endif; ?>
            </td>
            <td class="num" data-label="Kurse"><?= (int) $l['kurse'] ?></td>
            <td class="actions">
                <?php if (!$selbst): ?>
                <form method="post" class="compact">
                    <?= teacher_csrf_field() ?>
                    <button class="iconaction quiet" name="reset_password" value="<?= $id ?>"
                            title="Neues Anfangspasswort und Zettel"
                            data-confirm="Neues Passwort für <?= h($l['display_name']) ?>? Das bisherige gilt dann nicht mehr.">
                        <span aria-hidden="true">&#128273;</span> Neues Passwort
                    </button>
                </form>
                <?php endif; ?>
                <?php if (isset($druckbar[$id]) && (string) ($l['initial_password'] ?? '') !== ''): ?>
                <a class="iconaction quiet" title="Zettel drucken" target="_blank" rel="noopener"
                   href="<?= h(teacher_url('print.php') . '?pdf=1&lehrkraefte=1&user=' . $id) ?>" data-zettel>
                    <span aria-hidden="true">&#128424;</span> Zettel
                </a>
                <?php endif; ?>
                <?php if (!$selbst): ?>
                <?php
                /*
                 * Die Rückfrage nennt, was geschieht: aus wie vielen Kursen
                 * die Lehrkraft nur herausgenommen wird, und wie viele die
                 * löschende übernimmt, weil sie dort allein war
                 * (lehrkraft_loeschen_vorschau()).
                 */
                $v = lehrkraft_loeschen_vorschau($id);
                $n = $v['geteilt'];
                $m = count($v['allein']);
                $text = sprintf(
                    '%s wird gelöscht. Das lässt sich nicht rückgängig machen. '
                    . '%s hat %d %s mit weiteren Lehrkräften - dort wird das Konto nur herausgenommen - '
                    . 'und %d %s allein, die du dann automatisch übernimmst.',
                    $l['display_name'], $l['display_name'],
                    $n, $n === 1 ? 'Kurs' : 'Kurse', $m, $m === 1 ? 'Kurs' : 'Kurse');
                ?>
                <form method="post" class="compact">
                    <?= teacher_csrf_field() ?>
                    <button class="iconaction danger" name="delete_teacher" value="<?= $id ?>"
                            title="Lehrkraft löschen" data-confirm="<?= h($text) ?>">
                        <span aria-hidden="true">&#10005;</span> Löschen
                    </button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>

    <?php if (count($lehrkraefte) < $grenze): ?>
    <?php
    /*
     * Eine neue Lehrkraft: Name und Benutzername. Als Benutzername am besten
     * das Kürzel der Lehrkraft - an einer Schule kennt es jede, und es ist
     * kurz zu tippen.
     */
    ?>
    <?= teacher_anlegezeile([
        'was'     => 'Lehrkraft anlegen',
        'feld_id' => 'neueLehrkraft',
        'feld'    => '<span class="lehrkraftfelder">'
                   . '<input type="text" id="neueLehrkraft" name="display_name" form="neueLk" maxlength="64"'
                   . ' required placeholder="Name, z. B. Frau Müller" autocomplete="off">'
                   . '<input type="text" name="username" form="neueLk" maxlength="64" required'
                   . ' placeholder="Kürzel als Benutzername, z. B. mue" autocapitalize="off" autocomplete="off">'
                   . '</span>',
        'form'    => 'neueLk',
        'name'    => 'add_teacher',
        'spalten' => 4,
        'hinweis' => 'Als Benutzername am besten das Kürzel der Lehrkraft. Das Anfangspasswort entsteht von selbst.',
    ]) ?>
    <?php endif; ?>
</table>
<form method="post" id="neueLk"><?= teacher_csrf_field() ?></form>

<?php if (count($lehrkraefte) >= $grenze): ?>
    <p class="notice">Diese Schule hat schon <?= $grenze ?> Lehrkräfte &ndash; mehr sind nicht vorgesehen.
        Der Betreiber kann die Grenze anheben.</p>
<?php endif; ?>

<?php if ($zuDrucken > 0): ?>
<p>
    <a class="btn small secondary" target="_blank" rel="noopener"
       href="<?= h(teacher_url('print.php') . '?pdf=1&lehrkraefte=1') ?>" data-zettel>
        &#128424; <?= $zuDrucken ?> Zettel drucken
    </a>
</p>
<?php endif; ?>

<?php teacher_foot(); ?>
