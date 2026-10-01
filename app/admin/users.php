<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/courses.php';
require_once __DIR__ . '/../lib/access.php';

admin_require();

function valid_username(string $name): bool
{
    return (bool) preg_match('/^[a-z0-9._-]{3,64}$/', $name);
}

/*
 * Welche Konten zu sehen sind: Schule, Klasse, Rolle.
 *
 * Die Seite war eine Karte je Konto, alle Formulare offen, ueber alle
 * Schulen. Bei drei Kindern ging das; bei einer Schule mit dreihundert
 * sucht man sich darin tot. Die Klasse -1 heisst "ohne Klasse": Lehrkraefte
 * stehen meist dort, und ein Kind dort ist fast immer ein vergessenes.
 */
const ROLLE_LEHRKRAFT = 1;
const ROLLE_KIND      = 2;
const OHNE_KLASSE     = -1;

$filter = array_filter([
    'school' => (int) ($_REQUEST['school'] ?? 0),
    'klasse' => (int) ($_REQUEST['klasse'] ?? 0),
    'rolle'  => (int) ($_REQUEST['rolle'] ?? 0),
]);
// Eine Klasse gilt nur in ihrer Schule - sonst stuende ein Filter da,
// der nichts finden kann und nicht sagt, warum.
if (($filter['klasse'] ?? 0) > 0 && (int) (qv('SELECT school_id FROM classes WHERE id = ?',
        [$filter['klasse']]) ?? 0) !== ($filter['school'] ?? 0)) {
    unset($filter['klasse']);
}

/** Nach dem Speichern dorthin zurueck, wo man war. */
function back_to_users(array $filter): never
{
    redirect('users.php' . ($filter === [] ? '' : '?' . http_build_query($filter)));
}

/** Der Filter als versteckte Felder fuer jedes Formular der Seite. */
function filter_fields(array $filter): string
{
    $html = '';
    foreach ($filter as $k => $v) {
        $html .= sprintf('<input type="hidden" name="%s" value="%d">', h($k), (int) $v);
    }
    return $html;
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
        back_to_users($filter);
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

            /*
             * Ein Kurs braucht mindestens eine Lehrkraft (lib/courses.php).
             * Der Umzug in eine andere Schule nimmt alle Mitgliedschaften -
             * also nicht, solange das Konto die letzte Lehrkraft eines
             * Kurses ist.
             */
            $letzte = $vorher !== (int) $schule['id'] ? courses_where_last_teacher($id) : [];
            if ($letzte !== []) {
                flash('Nicht umgezogen: Das Konto ist die einzige Lehrkraft in '
                      . implode(', ', $letzte) . '. Dort erst eine andere Lehrkraft '
                      . 'aufnehmen oder den Kurs löschen.', 'bad');
                back_to_users($filter);
            }

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
        back_to_users($filter);
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
        back_to_users($filter);
    }

    if (isset($_POST['revoke_token'])) {
        q('UPDATE device_tokens SET revoked_at = NOW() WHERE id = ?', [(int) $_POST['revoke_token']]);
        flash('Gerät abgemeldet. Das Symbol landet beim nächsten Start im Login.');
        back_to_users($filter);
    }

    if (isset($_POST['delete'])) {
        $id   = (int) $_POST['delete'];
        $user = q1('SELECT display_name FROM users WHERE id = ?', [$id]);
        $letzte = $user !== null ? courses_where_last_teacher($id) : [];
        if ($letzte !== []) {
            // Ein Kurs braucht mindestens eine Lehrkraft (lib/courses.php).
            flash('Nicht gelöscht: "' . $user['display_name'] . '" ist die einzige Lehrkraft in '
                  . implode(', ', $letzte) . '. Dort erst eine andere Lehrkraft aufnehmen '
                  . 'oder den Kurs löschen.', 'bad');
            back_to_users($filter);
        }
        if ($user !== null) {
            /*
             * Mitgliedschaften, Geraete und Lernstand haengen per ON DELETE
             * CASCADE daran; das Kostenprotokoll bleibt erhalten. Vokabeln
             * gehen NICHT mit - sie gehoeren dem Kurs, nicht dem Konto. Hier
             * stand "mit allen Vokabeln geloescht", aus der Zeit, als das so
             * war; wer eine Lehrkraft loeschte, bekam einen Schreck, und wer
             * ein Kind loeschen wollte, zoegerte ohne Grund.
             */
            q('DELETE FROM users WHERE id = ?', [$id]);
            flash('Account "' . $user['display_name'] . '" gelöscht, samt Kursmitgliedschaften '
                  . 'und Lernstand. Die Unterlagen der Kurse bleiben.');
        }
        back_to_users($filter);
    }
}

/*
 * Kurse je Konto statt "Sprachen und Vokabeln".
 *
 * Gezaehlt wurde einmal, was ein Konto angelegt hat - dann stand die
 * Lehrkraft mit 2.800 Vokabeln da und die 28 Kinder mit null. Danach, womit
 * es arbeitet, aber in Sprachen: Die gibt es nur noch als Teil eines Kurses.
 * Was man wissen will, ist, in welchen Kursen ein Konto steckt.
 */
$schulen = qa('SELECT id, name FROM schools WHERE active = 1 ORDER BY name');

$where  = [];
$params = [];
if (isset($filter['school'])) {
    $where[]  = 'u.school_id = ?';
    $params[] = $filter['school'];
}
if (($filter['klasse'] ?? 0) === OHNE_KLASSE) {
    $where[] = 'NOT EXISTS (SELECT 1 FROM class_members cm WHERE cm.user_id = u.id)';
} elseif (isset($filter['klasse'])) {
    $where[]  = 'EXISTS (SELECT 1 FROM class_members cm WHERE cm.user_id = u.id AND cm.class_id = ?)';
    $params[] = $filter['klasse'];
}
if (isset($filter['rolle'])) {
    $where[]  = 'u.role ' . ($filter['rolle'] === ROLLE_LEHRKRAFT ? '=' : '<>') . ' ?';
    $params[] = ROLE_TEACHER;
}

$users = qa(
    "SELECT u.*,
            s.name AS school_name,
            (SELECT GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ')
               FROM class_members cm
               JOIN classes c ON c.id = cm.class_id
              WHERE cm.user_id = u.id) AS klassen,
            (SELECT GROUP_CONCAT(co.name ORDER BY co.name SEPARATOR ', ')
               FROM course_members m
               JOIN courses co ON co.id = m.course_id
              WHERE m.user_id = u.id) AS kurse,
            (SELECT COUNT(*) FROM course_members m WHERE m.user_id = u.id) AS kurszahl,
            (SELECT MAX(d.last_used_at) FROM device_tokens d WHERE d.user_id = u.id) AS zuletzt
       FROM users u
       LEFT JOIN schools s ON s.id = u.school_id"
    . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . "
      ORDER BY u.role = 'student', u.display_name",
    $params,
);

// Die Geraete aller gezeigten Konten in einem Zug - vorher eine Abfrage je Konto.
$tokens = [];
if ($users !== []) {
    $ids = implode(',', array_map(static fn (array $u): int => (int) $u['id'], $users));
    foreach (qa("SELECT * FROM device_tokens WHERE user_id IN ($ids)
                  ORDER BY revoked_at IS NOT NULL, created_at DESC") as $t) {
        $tokens[(int) $t['user_id']][] = $t;
    }
}

$klassen = isset($filter['school'])
    ? qa('SELECT id, name FROM classes WHERE school_id = ? ORDER BY name', [$filter['school']])
    : [];

admin_head('Accounts', 'users.php');
flash_render();
?>

<div class="card filters">
    <?= filter_chips('Schule',
        array_map(static fn (array $s): array => ['id' => (int) $s['id'], 'label' => $s['name']], $schulen),
        $filter['school'] ?? 0, $filter, 'school', ['klasse'], 'alle') ?>

    <?php if (isset($filter['school'])): ?>
        <?= filter_chips('Klasse',
            array_merge(
                array_map(static fn (array $c): array =>
                    ['id' => (int) $c['id'], 'label' => $c['name']], $klassen),
                [['id' => OHNE_KLASSE, 'label' => 'ohne Klasse']],
            ),
            $filter['klasse'] ?? 0, $filter, 'klasse', [], 'alle') ?>
    <?php endif; ?>

    <?= filter_chips('Rolle',
        [['id' => ROLLE_LEHRKRAFT, 'label' => 'Lehrkräfte'], ['id' => ROLLE_KIND, 'label' => 'SchülerInnen']],
        $filter['rolle'] ?? 0, $filter, 'rolle', [], 'alle') ?>
</div>

<h2>Accounts (<?= count($users) ?>)</h2>
<?php if ($users === []): ?>
    <p class="muted"><?= $filter === [] ? 'Noch kein Account angelegt.' : 'Keine Accounts für diese Auswahl.' ?></p>
<?php else: ?>

<div class="inline" style="margin-bottom:10px">
    <label for="kontosuche" style="margin:0">Suche</label>
    <input type="search" id="kontosuche" placeholder="Name, Benutzername, Klasse oder Kurs"
           style="width:300px;margin:0" autocomplete="off"
           data-filter-ziel="kontenliste" data-filter-zaehler="kontozaehler">
    <span class="tiny muted" id="kontozaehler"></span>
</div>

<?php
/*
 * Eine Zeile je Konto, die Formulare dahinter aufklappbar.
 *
 * Eine Tabelle waere naheliegend, geht aber nicht: table.data schneidet mit
 * overflow: hidden alles ab, was ueber eine Zeile hinausragt - und das
 * Farbfeld ragt hinaus. Deshalb ein Raster in <details>.
 */
?>
<div class="konten" id="kontenliste">
    <div class="konten-kopf">
        <span>Name</span><span>Rolle &middot; Schule</span><span>Klassen</span>
        <span>Kurse</span><span>Zuletzt da</span>
    </div>
    <?php foreach ($users as $u): ?>
        <?php
        $ut       = $tokens[(int) $u['id']] ?? [];
        $aktiv    = count(array_filter($ut, static fn (array $t): bool => $t['revoked_at'] === null));
        $suchtext = mb_strtolower(implode(' ', [
            $u['display_name'], $u['username'], (string) ($u['klassen'] ?? ''),
            (string) ($u['kurse'] ?? ''), (string) ($u['school_name'] ?? ''),
        ]));
        ?>
        <details class="konto<?= $u['active'] ? '' : ' aus' ?>" data-suchtext="<?= h($suchtext) ?>">
            <summary>
                <span>
                    <span class="swatch" style="background:<?= h($u['color']) ?>"></span><strong><?= h($u['display_name']) ?></strong>
                    <span class="tiny muted"><?= h($u['username']) ?><?= $u['active'] ? '' : ' &middot; deaktiviert' ?></span>
                </span>
                <span class="tiny">
                    <?= $u['role'] === ROLE_TEACHER ? 'Lehrkraft' : 'SchülerIn' ?><br>
                    <span class="muted"><?= $u['school_name'] === null
                        ? '<strong>ohne Schule</strong>' : h($u['school_name']) ?></span>
                </span>
                <span class="tiny"><?= $u['klassen'] === null
                    ? '<span class="muted">&ndash;</span>' : h($u['klassen']) ?></span>
                <span class="tiny" title="<?= h((string) ($u['kurse'] ?? '')) ?>">
                    <?= (int) $u['kurszahl'] === 0
                        ? '<span class="muted">keine Kurse</span>'
                        : (int) $u['kurszahl'] . ' Kurs' . ((int) $u['kurszahl'] === 1 ? '' : 'e')
                          . '<br><span class="muted">' . h(mb_strimwidth((string) $u['kurse'], 0, 60, '…')) . '</span>' ?>
                </span>
                <span class="tiny muted">
                    <?= $u['zuletzt'] === null ? 'nie' : h(date('d.m.Y', strtotime((string) $u['zuletzt']))) ?>
                    <?= $aktiv > 0 ? '<br>' . $aktiv . ' Gerät' . ($aktiv === 1 ? '' : 'e') : '' ?>
                </span>
            </summary>

            <div class="konto-inhalt">
                <form method="post" class="inline">
                    <?= csrf_field() ?>
                    <?= filter_fields($filter) ?>
                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <input type="text" name="display_name" value="<?= h($u['display_name']) ?>"
                           maxlength="64" style="width:180px;margin:0" aria-label="Anzeigename">
                    <?= color_picker($u['color']) ?>
                    <select name="role" style="width:auto;margin:0" aria-label="Rolle">
                        <option value="student"<?= $u['role'] === 'teacher' ? '' : ' selected' ?>>SchülerIn</option>
                        <option value="teacher"<?= $u['role'] === 'teacher' ? ' selected' : '' ?>>Lehrkraft</option>
                    </select>
                    <select name="school_id" style="width:auto;margin:0" title="Schule" aria-label="Schule">
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
                    <button class="btn small secondary" name="update" value="1">Speichern</button>
                </form>

                <form method="post" class="inline">
                    <?= csrf_field() ?>
                    <?= filter_fields($filter) ?>
                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <input type="text" name="password" placeholder="Neues Passwort" minlength="4"
                           style="width:180px;margin:0" aria-label="Neues Passwort">
                    <button class="btn small secondary" name="set_password" value="1">Passwort setzen</button>
                </form>

                <?php if ($ut === []): ?>
                    <p class="tiny muted">Noch kein Gerät angemeldet.</p>
                <?php else: ?>
                    <table class="data">
                        <tr><th>Angelegt</th><th>Zuletzt benutzt</th><th>Gerät</th><th></th></tr>
                        <?php foreach ($ut as $t): ?>
                            <tr class="<?= $t['revoked_at'] === null ? '' : 'dim' ?>">
                                <td><?= h(date('d.m.Y H:i', strtotime((string) $t['created_at']))) ?></td>
                                <td><?= $t['last_used_at']
                                        ? h(date('d.m.Y H:i', strtotime((string) $t['last_used_at'])))
                                        : '<span class="muted">nie</span>' ?></td>
                                <td><code class="token"><?= h(mb_substr((string) ($t['label'] ?? ''), 0, 70)) ?></code></td>
                                <td>
                                    <?php if ($t['revoked_at'] === null): ?>
                                        <form method="post" class="compact">
                                            <?= csrf_field() ?>
                                            <?= filter_fields($filter) ?>
                                            <button class="linkbtn" name="revoke_token" value="<?= (int) $t['id'] ?>"
                                                    data-confirm="Dieses Gerät abmelden?">widerrufen</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted tiny">widerrufen</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>

                <form method="post" class="compact">
                    <?= csrf_field() ?>
                    <?= filter_fields($filter) ?>
                    <button class="linkbtn" name="delete" value="<?= (int) $u['id'] ?>" style="color:var(--bad)"
                            data-confirm="<?= h($u['display_name']) ?> wirklich löschen? Kursmitgliedschaften und Lernstand gehen mit, die Unterlagen der Kurse bleiben.">Account löschen</button>
                </form>
            </div>
        </details>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<h2>Neuen Account anlegen</h2>
<form method="post" class="card">
    <?= csrf_field() ?>
    <?= filter_fields($filter) ?>
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
                        <option value="<?= (int) $s['id'] ?>"<?=
                            (int) $s['id'] === ($filter['school'] ?? 0) ? ' selected' : '' ?>><?= h($s['name']) ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>
        <div>
            <label for="new_role">Rolle</label>
            <select id="new_role" name="role">
                <option value="student">SchülerIn</option>
                <option value="teacher"<?= ($filter['rolle'] ?? 0) === ROLLE_LEHRKRAFT ? ' selected' : '' ?>>Lehrkraft</option>
            </select>
        </div>
    </div>
    <div class="inline" style="margin-bottom:12px">
        <label style="margin:0">Farbe</label>
        <?= color_picker(color_default()) ?>
    </div>

    <p class="tiny muted">
        Der Anzeigename erscheint als App-Name auf dem Home-Bildschirm -
        aus "Lilli" wird "Lillis Vokabeln". Die Farbe ist die des
        Homescreen-Symbols; darauf steht Voki, mit weissem Rand, damit er
        auf jeder Farbe zu sehen ist. Kinder legt in der Regel die
        Lehrkraft in ihrer Klasse an - dort kommen sie gleich in die Kurse.
    </p>
    <button class="btn small" name="create" value="1">Account anlegen</button>
</form>

<?php admin_foot(); ?>
