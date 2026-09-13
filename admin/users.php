<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/courses.php';

admin_require();

/**
 * Prüft nur das Format, nicht die Zugehörigkeit zur aktuellen Palette.
 * Sonst liesse sich eine früher gesetzte Farbe beim Speichern nicht halten,
 * wenn die Palette einmal wechselt.
 */
function valid_color(string $color): string
{
    return preg_match('/^#[0-9a-f]{6}$/i', $color) === 1
        ? strtolower($color)
        : color_palette()[27];   // ein kräftiges Blau als Rückfall
}

function valid_username(string $name): bool
{
    return (bool) preg_match('/^[a-z0-9._-]{3,64}$/', $name);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    if (isset($_POST['create'])) {
        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $display  = trim((string) ($_POST['display_name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $color    = (string) ($_POST['color'] ?? '');

        if (!valid_username($username)) {
            flash('Benutzername: 3-64 Zeichen, nur Kleinbuchstaben, Ziffern, . _ -', 'bad');
        } elseif ($display === '') {
            flash('Bitte einen Anzeigenamen angeben.', 'bad');
        } elseif (strlen($password) < 4) {
            flash('Das Passwort braucht mindestens 4 Zeichen.', 'bad');
        } elseif (q1('SELECT id FROM users WHERE username = ?', [$username]) !== null) {
            flash('Diesen Benutzernamen gibt es schon.', 'bad');
        } else {
            q(
                'INSERT INTO users (username, display_name, password_hash, color) VALUES (?, ?, ?, ?)',
                [$username, mb_substr($display, 0, 64), password_hash($password, PASSWORD_DEFAULT),
                 valid_color($color)],
            );

            // Ohne Schule kann das Konto weder eine Sprache anlegen noch eine
            // Lerneinheit sehen - beides haengt am Kurs. Solange es nur eine
            // Schule gibt, faellt die Wahl leicht; mit dem Lehrkraft-Bereich
            // wird sie hier zur Auswahl.
            user_assign_to_school((int) db()->lastInsertId());
            flash('Account "' . $display . '" angelegt.');
        }
        redirect('users.php');
    }

    if (isset($_POST['update'])) {
        $id      = (int) ($_POST['id'] ?? 0);
        $display = trim((string) ($_POST['display_name'] ?? ''));
        $color   = (string) ($_POST['color'] ?? '');
        $active  = isset($_POST['active']) ? 1 : 0;

        if ($display === '') {
            flash('Der Anzeigename darf nicht leer sein.', 'bad');
        } else {
            q(
                'UPDATE users SET display_name = ?, color = ?, active = ? WHERE id = ?',
                [mb_substr($display, 0, 64), valid_color($color), $active, $id],
            );
            flash('Account aktualisiert.');
        }
        redirect('users.php');
    }

    if (isset($_POST['set_password'])) {
        $id       = (int) ($_POST['id'] ?? 0);
        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) < 4) {
            flash('Das Passwort braucht mindestens 4 Zeichen.', 'bad');
        } else {
            q('UPDATE users SET password_hash = ? WHERE id = ?',
              [password_hash($password, PASSWORD_DEFAULT), $id]);
            flash('Passwort gesetzt.');
        }
        redirect('users.php');
    }

    if (isset($_POST['revoke_token'])) {
        q('UPDATE device_tokens SET revoked_at = NOW() WHERE id = ?', [(int) $_POST['revoke_token']]);
        flash('Gerät abgemeldet. Das Symbol landet beim nächsten Start im Login.');
        redirect('users.php');
    }

    if (isset($_POST['delete'])) {
        $id   = (int) $_POST['delete'];
        $user = q1('SELECT display_name FROM users WHERE id = ?', [$id]);
        if ($user !== null) {
            // Sprachen, Einheiten, Vokabeln und Lernstand hängen per
            // ON DELETE CASCADE daran; das Kostenprotokoll bleibt erhalten.
            q('DELETE FROM users WHERE id = ?', [$id]);
            flash('Account "' . $user['display_name'] . '" mit allen Vokabeln gelöscht.');
        }
        redirect('users.php');
    }
}

$users = qa(
    "SELECT u.*,
            (SELECT COUNT(*) FROM languages l WHERE l.user_id = u.id) AS langs,
            (SELECT COUNT(*) FROM vocab v JOIN units t ON t.id = v.unit_id WHERE t.user_id = u.id) AS words
       FROM users u
      ORDER BY u.display_name"
);

admin_head('Accounts', 'users.php');
flash_render();
?>

<h2>Neuen Account anlegen</h2>
<form method="post" class="card">
    <?= csrf_field() ?>
    <div class="formgrid">
        <div>
            <label for="display_name">Anzeigename</label>
            <input type="text" id="display_name" name="display_name" placeholder="Lilli" maxlength="64" required>
        </div>
        <div>
            <label for="username">Benutzername zum Anmelden</label>
            <input type="text" id="username" name="username" placeholder="lilli" maxlength="64"
                   autocapitalize="none" autocorrect="off" required>
        </div>
        <div>
            <label for="password">Passwort</label>
            <input type="text" id="password" name="password" minlength="4" required>
        </div>

    </div>
    <div class="inline" style="margin-bottom:12px">
        <label style="margin:0">Farbe</label>
        <?= color_picker(color_palette()[27]) ?>
    </div>

    <p class="tiny muted">
        Der Anzeigename erscheint als App-Name auf dem Home-Bildschirm -
        aus "Lilli" wird "Lillis Vokabeln". Die Farbe ist die des
        Homescreen-Symbols; die Initiale darauf wird hell oder dunkel gesetzt,
        je nachdem was besser lesbar ist.
    </p>
    <button class="btn small" name="create" value="1">Account anlegen</button>
</form>

<h2>Vorhandene Accounts</h2>
<?php if ($users === []): ?>
    <p class="muted">Noch kein Account angelegt.</p>
<?php endif; ?>

<?php foreach ($users as $u): ?>
    <?php
    $tokens = qa(
        'SELECT * FROM device_tokens WHERE user_id = ? ORDER BY revoked_at IS NOT NULL, created_at DESC',
        [(int) $u['id']],
    );
    ?>
    <div class="card">
        <h3 style="margin:0 0 12px">
            <span class="swatch" style="background:<?= h($u['color']) ?>"></span>
            <?= h($u['display_name']) ?>
            <span class="muted" style="font-weight:400">
                (<?= h($u['username']) ?>) &middot; <?= (int) $u['langs'] ?> Sprachen,
                <?= (int) $u['words'] ?> Vokabeln
                <?= $u['active'] ? '' : ' &middot; deaktiviert' ?>
            </span>
        </h3>

        <form method="post" class="inline" style="margin-bottom:12px">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
            <input type="text" name="display_name" value="<?= h($u['display_name']) ?>"
                   maxlength="64" style="width:180px;margin:0">
            <?= color_picker($u['color']) ?>
            <label style="display:flex;align-items:center;gap:6px;margin:0;font-weight:500">
                <input type="checkbox" name="active" value="1"<?= $u['active'] ? ' checked' : '' ?>
                       style="width:auto;min-height:auto;margin:0"> aktiv
            </label>
            <button class="btn secondary small" name="update" value="1">Speichern</button>
        </form>

        <form method="post" class="inline" style="margin-bottom:12px">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
            <input type="text" name="password" placeholder="Neues Passwort" minlength="4"
                   style="width:180px;margin:0">
            <button class="btn secondary small" name="set_password" value="1">Passwort setzen</button>
        </form>

        <details>
            <summary class="muted tiny" style="cursor:pointer;margin-bottom:8px">
                Home-Bildschirm-Symbole (<?= count(array_filter($tokens, fn ($t) => $t['revoked_at'] === null)) ?> aktiv)
            </summary>
            <?php if ($tokens === []): ?>
                <p class="tiny muted">Noch kein Gerät angemeldet.</p>
            <?php else: ?>
                <table class="data">
                    <tr><th>Angelegt</th><th>Zuletzt benutzt</th><th>Gerät</th><th></th></tr>
                    <?php foreach ($tokens as $t): ?>
                        <tr class="<?= $t['revoked_at'] === null ? '' : 'dim' ?>">
                            <td><?= h(date('d.m.Y H:i', strtotime((string) $t['created_at']))) ?></td>
                            <td><?= $t['last_used_at']
                                    ? h(date('d.m.Y H:i', strtotime((string) $t['last_used_at'])))
                                    : '<span class="muted">nie</span>' ?></td>
                            <td><code class="token"><?= h(mb_substr((string) ($t['label'] ?? ''), 0, 70)) ?></code></td>
                            <td>
                                <?php if ($t['revoked_at'] === null): ?>
                                    <form method="post" class="compact"
                                          onsubmit="return confirm('Dieses Gerät abmelden?')">
                                        <?= csrf_field() ?>
                                        <button class="linkbtn" name="revoke_token" value="<?= (int) $t['id'] ?>">
                                            widerrufen
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted tiny">widerrufen</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </details>

        <form method="post" class="compact"
              onsubmit="return confirm('<?= h($u['display_name']) ?> wirklich mit allen Sprachen und Vokabeln löschen?')">
            <?= csrf_field() ?>
            <button class="linkbtn" name="delete" value="<?= (int) $u['id'] ?>"
                    style="color:var(--bad)">Account löschen</button>
        </form>
    </div>
<?php endforeach; ?>

<?php admin_foot(); ?>
