<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/courses.php';
require_once __DIR__ . '/../lib/access.php';

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

        $role     = (string) ($_POST['role'] ?? ROLE_STUDENT);
        $schoolId = (int) ($_POST['school_id'] ?? 0);

        if (!in_array($role, [ROLE_STUDENT, ROLE_TEACHER], true)) {
            $role = ROLE_STUDENT;
        }

        /*
         * Die Schule ist Pflicht, und zwar hier und nicht spaeter.
         *
         * Ohne sie kann das Konto weder eine Sprache anlegen noch eine
         * Lerneinheit sehen - beides haengt am Kurs, ein Kurs an der Schule.
         * Ein Konto ohne Zugehoerigkeit ist kein sparsamer Sonderfall,
         * sondern ein kaputtes Konto, und der Fehler faellt erst auf, wenn
         * jemand vor einer leeren App sitzt.
         */
        $schule = $schoolId > 0
            ? q1('SELECT * FROM schools WHERE id = ?', [$schoolId])
            : null;

        if (!valid_username($username)) {
            flash('Benutzername: 3-64 Zeichen, nur Kleinbuchstaben, Ziffern, . _ -', 'bad');
        } elseif ($display === '') {
            flash('Bitte einen Anzeigenamen angeben.', 'bad');
        } elseif (strlen($password) < 4) {
            flash('Das Passwort braucht mindestens 4 Zeichen.', 'bad');
        } elseif ($schule === null) {
            flash('Bitte eine Schule wählen. Ohne Schule sieht das Konto nichts - '
                  . 'unter "Schulen" lässt sich eine anlegen.', 'bad');
        } elseif (q1('SELECT id FROM users WHERE username = ?', [$username]) !== null) {
            flash('Diesen Benutzernamen gibt es schon.', 'bad');
        } else {
            q(
                'INSERT INTO users (username, display_name, password_hash, color, role, can_import)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$username, mb_substr($display, 0, 64), password_hash($password, PASSWORD_DEFAULT),
                 valid_color($color), $role,
                 // Lehrkraefte lesen ein, das gehoert zu ihrer Arbeit.
                 $role === ROLE_TEACHER ? 1 : (isset($_POST['can_import']) ? 1 : 0)],
            );

            user_assign_to_school((int) db()->lastInsertId(), (int) $schule['id']);
            flash(sprintf('%s "%s" an %s angelegt.',
                $role === ROLE_TEACHER ? 'Lehrkraft' : 'Account',
                $display, $schule['name']));
        }
        redirect('users.php');
    }

    if (isset($_POST['update'])) {
        $id      = (int) ($_POST['id'] ?? 0);
        $display = trim((string) ($_POST['display_name'] ?? ''));
        $color   = (string) ($_POST['color'] ?? '');
        $active  = isset($_POST['active']) ? 1 : 0;
        $role    = (string) ($_POST['role'] ?? ROLE_STUDENT);
        $import  = isset($_POST['can_import']) ? 1 : 0;

        if (!in_array($role, [ROLE_STUDENT, ROLE_TEACHER], true)) {
            $role = ROLE_STUDENT;
        }

        $schoolId = (int) ($_POST['school_id'] ?? 0);
        $schule   = $schoolId > 0 ? q1('SELECT id FROM schools WHERE id = ?', [$schoolId]) : null;

        if ($display === '') {
            flash('Der Anzeigename darf nicht leer sein.', 'bad');
        } elseif ($schule === null) {
            flash('Bitte eine Schule wählen.', 'bad');
        } else {
            /*
             * Der Schulwechsel geht ueber user_assign_to_school(), nicht per
             * UPDATE: Dort haengt die Klassenzugehoerigkeit mit dran. Ein
             * Konto, das nur die Schule wechselt, aber in der Klasse der
             * alten bliebe, waere schlimmer als eines ganz ohne.
             */
            $vorher = (int) (qv('SELECT school_id FROM users WHERE id = ?', [$id]) ?? 0);

            q(
                'UPDATE users SET display_name = ?, color = ?, active = ?,
                                  role = ?, can_import = ?
                  WHERE id = ?',
                [mb_substr($display, 0, 64), valid_color($color), $active,
                 $role, $import, $id],
            );

            if ($vorher !== (int) $schule['id']) {
                q('DELETE FROM class_members WHERE user_id = ?', [$id]);
                q('DELETE FROM course_members WHERE user_id = ?', [$id]);
                user_assign_to_school($id, (int) $schule['id']);
                flash('Account aktualisiert und in die andere Schule umgezogen. '
                      . 'Klassen und Kurse dort bitte neu zuordnen.');
            } else {
                flash('Account aktualisiert.');
            }
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

/*
 * Sprachen und Vokabeln je Konto - gezaehlt ueber die Kurse, in denen es ist.
 *
 * Frueher zaehlte hier, was das Konto angelegt hat. In einer Klasse hat die
 * Lehrkraft alles angelegt: Sie stuende mit 2.800 Vokabeln da und die 28
 * Kinder mit null, obwohl alle dasselbe lernen. Gezaehlt wird deshalb, womit
 * ein Konto arbeitet.
 */
$schulen = qa('SELECT id, name FROM schools WHERE active = 1 ORDER BY name');

$users = qa(
    "SELECT u.*,
            (SELECT s.name FROM schools s WHERE s.id = u.school_id) AS school_name,
            (SELECT COUNT(DISTINCT co.language_id)
               FROM course_members m
               JOIN courses co ON co.id = m.course_id
              WHERE m.user_id = u.id) AS langs,
            (SELECT COUNT(*)
               FROM course_members m
               JOIN units t  ON t.course_id = m.course_id
               JOIN vocab v  ON v.unit_id = t.id
              WHERE m.user_id = u.id) AS words
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
        <div>
            <label for="school_id">Schule</label>
            <select id="school_id" name="school_id" required>
                <?php if ($schulen === []): ?>
                    <option value="">- erst eine Schule anlegen -</option>
                <?php else: ?>
                    <?php foreach ($schulen as $s): ?>
                        <option value="<?= (int) $s['id'] ?>"><?= h($s['name']) ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>
        <div>
            <label for="new_role">Rolle</label>
            <select id="new_role" name="role">
                <option value="student">SchülerIn</option>
                <option value="teacher">Lehrkraft</option>
            </select>
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
                (<?= h($u['username']) ?>)
                &middot; <?= $u['school_name'] === null
                    ? '<strong>ohne Schule</strong>' : h($u['school_name']) ?>
                <?= $u['role'] === 'teacher' ? ' &middot; Lehrkraft' : '' ?>
                &middot; <?= (int) $u['langs'] ?> Sprachen,
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
            <select name="role" style="width:auto;margin:0">
                <option value="student"<?= $u['role'] === 'teacher' ? '' : ' selected' ?>>SchülerIn</option>
                <option value="teacher"<?= $u['role'] === 'teacher' ? ' selected' : '' ?>>Lehrkraft</option>
            </select>
            <select name="school_id" style="width:auto;margin:0" title="Schule">
                <?php foreach ($schulen as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"
                        <?= (int) $s['id'] === (int) ($u['school_id'] ?? 0) ? ' selected' : '' ?>>
                        <?= h($s['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label style="display:flex;align-items:center;gap:6px;margin:0;font-weight:500">
                <input type="checkbox" name="active" value="1"<?= $u['active'] ? ' checked' : '' ?>
                       style="width:auto;min-height:auto;margin:0"> aktiv
            </label>
            <label style="display:flex;align-items:center;gap:6px;margin:0;font-weight:500"
                   title="Lektionen per Foto einlesen. Kostet Geld - Lehrkräfte dürfen es immer.">
                <input type="checkbox" name="can_import" value="1"<?= $u['can_import'] ? ' checked' : '' ?>
                       style="width:auto;min-height:auto;margin:0"> einlesen
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
