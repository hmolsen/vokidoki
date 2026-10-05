<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/roster.php';
require_once __DIR__ . '/../lib/geraete.php';

/*
 * Eine Schule - fast alles, was der Betreiber tut, betrifft genau eine.
 *
 * Bis hierher lag das auf drei Seiten verteilt: die Schule selbst unter
 * "Schulen", ihre Konten unter "Accounts" - einer Liste über alle Schulen,
 * die man erst filtern musste -, ihre Kurse unter "Unterlagen". Wer einer
 * Lehrkraft ein neues Passwort geben wollte, suchte sie in hunderten
 * Konten. Jetzt führt jeder Weg über die Schule, und darin über eine Reihe
 * Knöpfe: Konten, Kurse, Meldungen, Kosten, Einstellungen.
 */

admin_require();

const SCHULE_REITER = ['konten', 'kurse', 'kosten', 'einstellungen'];

// Aus der Adresse, nicht aus $_REQUEST: Die Formulare der Konten schicken
// ein eigenes "id" - das des Kontos -, und POST schlägt GET.
$id     = (int) ($_GET['id'] ?? 0);
$schule = $id > 0 ? q1('SELECT * FROM schools WHERE id = ?', [$id]) : null;
if ($schule === null) {
    flash('Diese Schule gibt es nicht.', 'bad');
    redirect('index.php');
}
$reiter = in_array($_REQUEST['r'] ?? '', SCHULE_REITER, true) ? (string) $_REQUEST['r'] : 'konten';

/*
 * Welche Konten zu sehen sind: die Lehrkräfte, eine Klasse oder die Kinder
 * ohne Klasse. Lehrkräfte zuerst - um sie geht es dem Betreiber meist, wenn
 * er hier ist (ein vergessenes Passwort, und keine Kollegin ist da).
 */
$gruppe = (string) ($_REQUEST['g'] ?? 'lk');

/** Zurück auf diese Seite, in denselben Reiter und dieselbe Gruppe. */
$zurueck = static function (string $r = '', array $mehr = []) use ($id, $reiter, $gruppe): never {
    $r = $r === '' ? $reiter : $r;
    redirect('schule.php?' . http_build_query(['id' => $id, 'r' => $r]
        + ($r === 'konten' ? ['g' => $gruppe] : []) + $mehr));
};

/** Ein Konto dieser Schule - die Kennung kommt aus dem Formular. */
$kontoHier = static fn (int $uid): ?array =>
    q1('SELECT * FROM users WHERE id = ? AND school_id = ?', [$uid, $id]);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    // ------------------------------------------------ Die Schule selbst

    if (isset($_POST['save_school'])) {
        $name    = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? '')) ?? '');
        $cap     = trim((string) ($_POST['cap'] ?? ''));
        $kuerzel = schulkuerzel_normal((string) ($_POST['kuerzel'] ?? ''));

        if ($name === '') {
            flash('Die Schule braucht einen Namen.', 'bad');
        } elseif (q1('SELECT id FROM schools WHERE name = ? AND id <> ?', [$name, $id]) !== null) {
            flash('Diesen Namen trägt schon eine andere Schule.', 'bad');
        } elseif (($grund = schulkuerzel_pruefen($kuerzel, $id)) !== null) {
            flash($grund, 'bad');
        } else {
            /*
             * Ein neues Kürzel gilt sofort: Wer angemeldet ist, bleibt es;
             * wer sich neu anmeldet, braucht das neue. Gedruckte Zettel
             * tragen dann noch das alte.
             *
             * Leeres Limit heisst "kein eigenes Limit" und nicht "null
             * Dollar". Der Unterschied ist erheblich: NULL lässt nur das
             * Budget des Betreibers greifen, 0.00 sperrte die Schule sofort.
             */
            q('UPDATE schools SET name = ?, kuerzel = ?, active = ?, monthly_cost_cap_usd = ?, max_lehrkraefte = ?
                WHERE id = ?',
              [mb_substr($name, 0, 128), $kuerzel, isset($_POST['active']) ? 1 : 0,
               $cap === '' ? null : number_format((float) str_replace(',', '.', $cap), 2, '.', ''),
               max(1, min(1000, (int) ($_POST['max_lehrkraefte'] ?? LEHRKRAEFTE_VOREINSTELLUNG))), $id]);
            flash('Schule gespeichert.');
        }
        $zurueck('einstellungen');
    }

    if (isset($_POST['delete_school'])) {
        /*
         * Löschen nur, solange nichts daran hängt.
         *
         * Der Fremdschlüssel räumte sonst Klassen und Kurse mit ab, und an
         * den Kursen hängen die Lerneinheiten. Ein Fehlklick löschte die
         * Arbeit einer ganzen Schule. Wer wirklich löschen will, leert sie
         * erst - und wer sie nur aus dem Weg haben will, schaltet sie ab.
         */
        $konten  = (int) qv('SELECT COUNT(*) FROM users WHERE school_id = ?', [$id]);
        $klassen = (int) qv('SELECT COUNT(*) FROM classes WHERE school_id = ?', [$id]);
        $kurse   = (int) qv('SELECT COUNT(*) FROM courses WHERE school_id = ?', [$id]);
        if ($konten + $klassen + $kurse > 0) {
            flash(sprintf('Nicht gelöscht: An dieser Schule hängen noch %d Konten, %d Klassen und %d Kurse. '
                          . 'Zum Stilllegen das Häkchen "aktiv" entfernen.', $konten, $klassen, $kurse), 'bad');
            $zurueck('einstellungen');
        }
        q('DELETE FROM schools WHERE id = ?', [$id]);
        flash('Schule "' . $schule['name'] . '" gelöscht.');
        redirect('index.php');
    }

    // ------------------------------------------------ Konten

    if (isset($_POST['create'])) {
        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $display  = trim((string) ($_POST['display_name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role     = ($_POST['role'] ?? '') === ROLE_TEACHER ? ROLE_TEACHER : ROLE_STUDENT;

        if (!preg_match('/^[a-z0-9._-]{2,64}$/', $username)) {
            flash('Benutzername: 2 bis 64 Zeichen, nur Kleinbuchstaben, Ziffern, . _ -', 'bad');
        } elseif ($display === '') {
            flash('Bitte einen Namen angeben.', 'bad');
        } elseif ($password !== '' && strlen($password) < 4) {
            flash('Das Passwort braucht mindestens 4 Zeichen - oder leer lassen, dann entsteht eines.', 'bad');
        } elseif (q1('SELECT id FROM users WHERE school_id = ? AND username = ?', [$id, $username]) !== null) {
            // Eindeutig je Schule - an einer anderen darf es ihn geben.
            flash('Diesen Benutzernamen gibt es an dieser Schule schon.', 'bad');
        } else {
            /*
             * Ohne Passwort entsteht eines, wie bei den Kindern einer Klasse
             * und den Lehrkräften untereinander - und es steht einmal hier,
             * damit es auf einen Zettel kommt.
             */
            $erzeugt = $password === '' ? password_generate() : null;
            q('INSERT INTO users (username, display_name, password_hash, initial_password, color, role, can_import)
               VALUES (?, ?, ?, ?, ?, ?, ?)',
              [$username, mb_substr($display, 0, 64),
               password_hash($erzeugt ?? $password, PASSWORD_DEFAULT), $erzeugt,
               valid_color((string) ($_POST['color'] ?? color_default())), $role,
               // Lehrkräfte lesen ein, das gehört zu ihrer Arbeit.
               $role === ROLE_TEACHER ? 1 : (isset($_POST['can_import']) ? 1 : 0)]);
            user_assign_to_school((int) db()->lastInsertId(), $id);
            flash(sprintf('%s "%s" angelegt.%s', $role === ROLE_TEACHER ? 'Lehrkraft' : 'Konto', $display,
                          $erzeugt === null ? '' : ' Anfangspasswort: ' . $erzeugt));
        }
        $zurueck('konten', ['g' => $role === ROLE_TEACHER ? 'lk' : $gruppe]);
    }

    $uid   = (int) ($_POST['id'] ?? $_POST['delete'] ?? $_POST['reset_password'] ?? 0);
    $konto = $uid > 0 ? $kontoHier($uid) : null;

    if (isset($_POST['update']) && $konto !== null) {
        $display = trim((string) ($_POST['display_name'] ?? ''));
        $role    = ($_POST['role'] ?? '') === ROLE_TEACHER ? ROLE_TEACHER : ROLE_STUDENT;
        $ziel    = (int) ($_POST['school_id'] ?? $id);

        if ($display === '') {
            flash('Der Name darf nicht leer sein.', 'bad');
            $zurueck();
        }
        if ($ziel !== $id && q1('SELECT id FROM schools WHERE id = ?', [$ziel]) === null) {
            flash('Diese Schule gibt es nicht.', 'bad');
            $zurueck();
        }
        /*
         * Ein Kurs braucht mindestens eine Lehrkraft (lib/courses.php). Der
         * Umzug in eine andere Schule nimmt alle Mitgliedschaften - also
         * nicht, solange das Konto die letzte Lehrkraft eines Kurses ist.
         */
        $letzte = $ziel !== $id ? courses_where_last_teacher($uid) : [];
        if ($letzte !== []) {
            flash('Nicht umgezogen: Das Konto ist die einzige Lehrkraft in ' . implode(', ', $letzte)
                  . '. Dort erst eine andere Lehrkraft aufnehmen oder den Kurs löschen.', 'bad');
            $zurueck();
        }
        q('UPDATE users SET display_name = ?, color = ?, active = ?, role = ?, can_import = ? WHERE id = ?',
          [mb_substr($display, 0, 64), valid_color((string) ($_POST['color'] ?? '')),
           isset($_POST['active']) ? 1 : 0, $role, isset($_POST['can_import']) ? 1 : 0, $uid]);

        if ($ziel !== $id) {
            /*
             * Der Schulwechsel geht über user_assign_to_school(), nicht per
             * UPDATE: Dort hängt die Klassenzugehörigkeit mit dran. Ein
             * Konto, das nur die Schule wechselt, aber in der Klasse der
             * alten bliebe, wäre schlimmer als eines ganz ohne.
             */
            q('DELETE FROM class_members WHERE user_id = ?', [$uid]);
            q('DELETE FROM course_members WHERE user_id = ?', [$uid]);
            user_assign_to_school($uid, $ziel);
            flash('Konto gespeichert und in die andere Schule umgezogen. Klassen und Kurse dort bitte neu zuordnen.');
        } else {
            flash('Konto gespeichert.');
        }
        $zurueck();
    }

    if (isset($_POST['reset_password']) && $konto !== null) {
        $neu = student_reset_password($uid);
        flash($neu === null ? 'Es liess sich kein neues Passwort bilden.'
                            : sprintf('Neues Passwort für %s: %s - das bisherige gilt nicht mehr.',
                                      $konto['display_name'], $neu),
              $neu === null ? 'bad' : 'good');
        $zurueck();
    }

    if (isset($_POST['set_password']) && $konto !== null) {
        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) < 4) {
            flash('Das Passwort braucht mindestens 4 Zeichen.', 'bad');
        } else {
            q('UPDATE users SET password_hash = ?, initial_password = NULL WHERE id = ?',
              [password_hash($password, PASSWORD_DEFAULT), $uid]);
            flash('Passwort gesetzt.');
        }
        $zurueck();
    }

    if (isset($_POST['revoke_token'])) {
        // Nur Geräte von Konten dieser Schule (lib/geraete.php).
        $besitzer = (int) (qv('SELECT d.user_id FROM device_tokens d JOIN users u ON u.id = d.user_id
                                WHERE d.id = ? AND u.school_id = ?', [(int) $_POST['revoke_token'], $id]) ?? 0);
        if ($besitzer > 0 && geraet_widerrufen($besitzer, (int) $_POST['revoke_token'])) {
            flash('Abgeschaltet. Das Symbol führt jetzt auf die Anmeldung.');
        }
        $zurueck();
    }

    if (isset($_POST['delete']) && $konto !== null) {
        $letzte = courses_where_last_teacher($uid);
        if ($letzte !== []) {
            // Ein Kurs braucht mindestens eine Lehrkraft (lib/courses.php).
            flash('Nicht gelöscht: "' . $konto['display_name'] . '" ist die einzige Lehrkraft in '
                  . implode(', ', $letzte) . '. Dort erst eine andere Lehrkraft aufnehmen oder den Kurs löschen.', 'bad');
            $zurueck();
        }
        /*
         * Mitgliedschaften, Geräte und Lernstand hängen per ON DELETE
         * CASCADE daran; das Kostenprotokoll bleibt. Vokabeln gehen NICHT
         * mit - sie gehören dem Kurs, nicht dem Konto.
         */
        q('DELETE FROM users WHERE id = ?', [$uid]);
        flash('"' . $konto['display_name'] . '" gelöscht, samt Kursmitgliedschaften und Lernstand. '
              . 'Die Unterlagen der Kurse bleiben.');
        $zurueck();
    }

    $zurueck();
}

// ---------------------------------------------------------------- Zahlen

$zahlen = q1(
    "SELECT (SELECT COUNT(*) FROM users WHERE school_id = ? AND role = ?) AS lehrkraefte,
            (SELECT COUNT(*) FROM users WHERE school_id = ? AND role = ?) AS kinder,
            (SELECT COUNT(*) FROM courses WHERE school_id = ?) AS kurse,
            (SELECT COUNT(*) FROM classes WHERE school_id = ?) AS klassen",
    [$id, ROLE_TEACHER, $id, ROLE_STUDENT, $id, $id],
);
$kostenMonat = cost_this_month($id);
$gemeldet    = meldungen_je_schule()[$id] ?? 0;
$monatName   = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August',
                'September', 'Oktober', 'November', 'Dezember'][(int) date('n')];

admin_head((string) $schule['name'], 'schule.php?id=' . $id);
flash_render();

$reiterLink = static fn (string $r, array $mehr = []): string =>
    admin_url('schule.php') . '?' . http_build_query(['id' => $id, 'r' => $r] + $mehr);
?>

<div class="schulkopf">
    <span class="pills">
        <span class="pill code" title="Kürzel zum Anmelden"><?= h((string) ($schule['kuerzel'] ?? '')) ?></span>
        <?= $schule['active'] ? '<span class="pill gruen">aktiv</span>' : '<span class="pill">stillgelegt</span>' ?>
    </span>
</div>

<div class="kennzahlen">
    <span><b><?= (int) $zahlen['lehrkraefte'] ?><small>/<?= (int) $schule['max_lehrkraefte'] ?></small></b>Lehrkräfte</span>
    <span><b><?= number_format((int) $zahlen['kinder'], 0, ',', '.') ?></b>Kinder</span>
    <span><b><?= (int) $zahlen['kurse'] ?></b>Kurse</span>
    <span><b><?= admin_euro($kostenMonat) ?></b><?= h($monatName) ?></span>
</div>

<nav class="segment" aria-label="Bereiche der Schule">
    <a href="<?= h($reiterLink('konten')) ?>"<?= $reiter === 'konten' ? ' class="on" aria-current="page"' : '' ?>>Konten</a>
    <a href="<?= h($reiterLink('kurse')) ?>"<?= $reiter === 'kurse' ? ' class="on" aria-current="page"' : '' ?>>Kurse und Unterlagen</a>
    <a href="<?= h(admin_url('meldungen.php') . '?schule=' . $id) ?>">Meldungen<?=
        $gemeldet > 0 ? ' <span class="zaehler">' . $gemeldet . '</span>' : '' ?></a>
    <a href="<?= h($reiterLink('kosten')) ?>"<?= $reiter === 'kosten' ? ' class="on" aria-current="page"' : '' ?>>Kosten</a>
    <a href="<?= h($reiterLink('einstellungen')) ?>"<?= $reiter === 'einstellungen' ? ' class="on" aria-current="page"' : '' ?>>Einstellungen</a>
</nav>

<?php if ($reiter === 'konten'): ?>
    <?php
    $klassen = qa('SELECT id, name FROM classes WHERE school_id = ? ORDER BY name', [$id]);
    $ohne    = (int) qv("SELECT COUNT(*) FROM users u WHERE u.school_id = ? AND u.role = ?
                          AND NOT EXISTS (SELECT 1 FROM class_members cm WHERE cm.user_id = u.id)", [$id, ROLE_STUDENT]);
    [$wo, $p] = match (true) {
        $gruppe === 'lk'   => ['u.role = ?', [ROLE_TEACHER]],
        $gruppe === 'ohne' => ['u.role = ? AND NOT EXISTS (SELECT 1 FROM class_members cm WHERE cm.user_id = u.id)', [ROLE_STUDENT]],
        default            => ['EXISTS (SELECT 1 FROM class_members cm WHERE cm.user_id = u.id AND cm.class_id = ?)', [(int) $gruppe]],
    };
    $konten = qa(
        "SELECT u.*,
                (SELECT GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ') FROM class_members cm
                   JOIN classes c ON c.id = cm.class_id WHERE cm.user_id = u.id) AS klassen,
                (SELECT COUNT(*) FROM course_members m WHERE m.user_id = u.id) AS kurszahl,
                (SELECT MAX(d.last_used_at) FROM device_tokens d WHERE d.user_id = u.id) AS zuletzt
           FROM users u
          WHERE u.school_id = ? AND $wo
          ORDER BY u.display_name",
        array_merge([$id], $p),
    );
    $andere = qa('SELECT id, name FROM schools WHERE id <> ? ORDER BY name', [$id]);
    $gruppeLink = static fn (string $g): string => $reiterLink('konten', ['g' => $g]);
    ?>
    <nav class="segment klein" aria-label="Welche Konten">
        <a href="<?= h($gruppeLink('lk')) ?>"<?= $gruppe === 'lk' ? ' class="on"' : '' ?>>&#129489;&#8205;&#127979; Lehrkräfte</a>
        <?php foreach ($klassen as $k): ?>
            <a href="<?= h($gruppeLink((string) $k['id'])) ?>"<?= $gruppe === (string) $k['id'] ? ' class="on"' : '' ?>><?= h($k['name']) ?></a>
        <?php endforeach; ?>
        <?php if ($ohne > 0 || $gruppe === 'ohne'): ?>
            <a href="<?= h($gruppeLink('ohne')) ?>"<?= $gruppe === 'ohne' ? ' class="on"' : '' ?>>ohne Klasse <span class="muted"><?= $ohne ?></span></a>
        <?php endif; ?>
    </nav>

    <?php if ($konten === []): ?>
        <p class="muted"><?= $gruppe === 'lk' ? 'Noch keine Lehrkraft - unten lässt sich die erste anlegen.' : 'Hier ist niemand.' ?></p>
    <?php else: ?>
        <?php if (count($konten) > 8): ?>
            <input type="search" class="listensuche" placeholder="Name oder Benutzername" autocomplete="off"
                   aria-label="In der Liste suchen" data-filter-ziel="kontoliste" data-filter-zaehler="kontozaehler">
            <span class="tiny muted" id="kontozaehler"></span>
        <?php endif; ?>
        <div class="card liste" id="kontoliste">
            <?php foreach ($konten as $u): ?>
                <?php
                $uid  = (int) $u['id'];
                $text = (int) $u['kurszahl'] === 1 ? '1 Kurs' : (int) $u['kurszahl'] . ' Kurse';
                $geraete = geraete_liste($uid);
                ?>
                <details class="kontozeile<?= $u['active'] ? '' : ' aus' ?>" id="konto<?= $uid ?>"
                         data-suchtext="<?= h(mb_strtolower($u['display_name'] . ' ' . $u['username'])) ?>">
                    <summary>
                        <span class="avatar" style="--c:<?= h($u['color']) ?>" aria-hidden="true"><?=
                            h(mb_strtoupper(mb_substr((string) $u['display_name'], 0, 1))) ?></span>
                        <span class="wer">
                            <strong><?= h($u['display_name']) ?></strong>
                            <span class="tiny muted"><code><?= h($u['username']) ?></code>
                                &middot; <?= $u['role'] === ROLE_TEACHER ? $text : h((string) ($u['klassen'] ?? 'ohne Klasse')) ?>
                                &middot; <?= $u['zuletzt'] === null ? 'noch nie da' : 'zuletzt ' . h(geraet_zeit((string) $u['zuletzt'])) ?>
                                <?= $u['active'] ? '' : '&middot; <strong>abgeschaltet</strong>' ?></span>
                        </span>
                        <span class="pfeil" aria-hidden="true">&#8250;</span>
                    </summary>

                    <div class="kontoinhalt">
                        <form method="post" class="knopfreihe">
                            <?= csrf_field() ?>
                            <button class="btn small" name="reset_password" value="<?= $uid ?>"
                                    data-confirm="Neues Passwort für <?= h($u['display_name']) ?>? Das bisherige gilt dann nicht mehr.">
                                &#128273; Neues Passwort
                            </button>
                            <button class="btn small secondary danger" name="delete" value="<?= $uid ?>"
                                    data-confirm="<?= h($u['display_name']) ?> löschen? Kursmitgliedschaften und Lernstand gehen mit, die Unterlagen der Kurse bleiben.">
                                Löschen
                            </button>
                        </form>

                        <form method="post" class="kontofelder">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $uid ?>">
                            <label>Name <input type="text" name="display_name" value="<?= h($u['display_name']) ?>" maxlength="64"></label>
                            <label>Rolle
                                <select name="role">
                                    <option value="student"<?= $u['role'] === ROLE_TEACHER ? '' : ' selected' ?>>Kind</option>
                                    <option value="teacher"<?= $u['role'] === ROLE_TEACHER ? ' selected' : '' ?>>Lehrkraft</option>
                                </select>
                            </label>
                            <label>Schule
                                <select name="school_id">
                                    <option value="<?= $id ?>" selected><?= h($schule['name']) ?></option>
                                    <?php foreach ($andere as $s): ?>
                                        <option value="<?= (int) $s['id'] ?>">umziehen nach <?= h($s['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <span class="kontohaken">
                                <label><input type="checkbox" name="active" value="1"<?= $u['active'] ? ' checked' : '' ?>> darf sich anmelden</label>
                                <label title="Lektionen per Foto einlesen - kostet Geld. Lehrkräfte dürfen es immer.">
                                    <input type="checkbox" name="can_import" value="1"<?= $u['can_import'] ? ' checked' : '' ?>> darf einlesen</label>
                                <span class="farbe">Farbe <?= color_picker((string) $u['color']) ?></span>
                            </span>
                            <button class="btn small secondary" name="update" value="1">Speichern</button>
                        </form>

                        <?php if ($geraete !== []): ?>
                            <ul class="geraete">
                                <?php foreach ($geraete as $g): ?>
                                <li>
                                    <span class="geraetzeichen" style="--zeichen:url('<?= h($g['zeichen']) ?>')" aria-hidden="true"></span>
                                    <span class="geraettext"><strong><?= h($g['name']) ?></strong>
                                        <span class="tiny muted">angelegt <?= h($g['angelegt']) ?> &middot; zuletzt benutzt <?= h($g['zuletzt']) ?></span></span>
                                    <form method="post"><?= csrf_field() ?>
                                        <button class="geraetweg" name="revoke_token" value="<?= $g['id'] ?>"
                                                data-confirm="<?= h($g['frage']) ?>" title="Abschalten"
                                                aria-label="<?= h($g['name']) ?> abschalten">&#128465;&#65039;</button></form>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <details class="card anlegen"<?= $konten === [] && $gruppe === 'lk' ? ' open' : '' ?>>
        <summary><span class="ic" aria-hidden="true">+</span>
            <?= $gruppe === 'lk' ? 'Lehrkraft anlegen' : 'Konto anlegen' ?></summary>
        <form method="post" class="kontofelder">
            <?= csrf_field() ?>
            <input type="hidden" name="g" value="<?= h($gruppe) ?>">
            <label>Name <input type="text" name="display_name" maxlength="64" required placeholder="Frau Müller"></label>
            <label>Benutzername <input type="text" name="username" maxlength="64" required autocapitalize="none"
                                       placeholder="<?= $gruppe === 'lk' ? 'mue' : 'lilli.m' ?>"></label>
            <label>Rolle
                <select name="role">
                    <option value="teacher"<?= $gruppe === 'lk' ? ' selected' : '' ?>>Lehrkraft</option>
                    <option value="student"<?= $gruppe === 'lk' ? '' : ' selected' ?>>Kind</option>
                </select>
            </label>
            <button class="btn small" name="create" value="1">Anlegen</button>
        </form>
        <p class="tiny muted">Das Anfangspasswort entsteht von selbst und steht danach oben. Weitere
            Lehrkräfte legen die Lehrkräfte selbst an (unter &bdquo;Lehrkräfte&ldquo;), Kinder ihre Lehrkraft
            in der Klasse &ndash; hier geht es vor allem um die erste Lehrkraft einer Schule.</p>
    </details>

<?php elseif ($reiter === 'kurse'): ?>
    <?php $kurse = courses_for_school($id); ?>
    <?php if ($kurse === []): ?>
        <p class="muted">Noch keine Kurse. Kurse legen die Lehrkräfte an.</p>
    <?php else: ?>
        <div class="card liste">
            <?php foreach ($kurse as $k): ?>
                <a class="zeile" href="<?= h(admin_url('vocab.php') . '?school=' . $id . '&course=' . (int) $k['id']) ?>">
                    <span class="flagge" aria-hidden="true"><?= flag_html((string) $k['flag_emoji'] ?: FLAG_FALLBACK, 'mflagge') ?></span>
                    <span class="wer">
                        <strong><?= h((string) $k['name']) ?></strong>
                        <span class="tiny muted"><?= $k['class_name'] === null ? 'ohne Klasse' : 'Klasse ' . h($k['class_name']) ?>
                            &middot; <?= (int) $k['students'] ?> Kinder &middot; <?= (int) $k['units'] ?> Lerneinheiten
                            &middot; <?= (int) $k['released'] ?> von <?= (int) $k['vocab'] ?> Vokabeln frei</span>
                    </span>
                    <span class="pfeil" aria-hidden="true">&#8250;</span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php elseif ($reiter === 'kosten'): ?>
    <?php
    $grenze = $schule['monthly_cost_cap_usd'] === null ? null : (float) $schule['monthly_cost_cap_usd'];
    $jeKonto = qa(
        "SELECT COALESCE(u.display_name, a.user_label, 'gelöscht') AS name, COUNT(*) AS n,
                COALESCE(SUM(a.cost_usd), 0) AS c
           FROM ai_requests a LEFT JOIN users u ON u.id = a.user_id
          WHERE a.school_id = ? AND a.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
          GROUP BY name ORDER BY c DESC",
        [$id],
    );
    ?>
    <div class="monatkarten">
        <div class="card monat">
            <?php if ($grenze !== null && $grenze > 0): ?>
                <?php $anteil = $kostenMonat / $grenze * 100; ?>
                <span class="anteil<?= $anteil >= 80 ? ' gelb' : '' ?>" style="--p:<?= (int) min(100, round($anteil)) ?>" aria-hidden="true"><span><?= (int) round($anteil) ?>&nbsp;%</span></span>
            <?php endif; ?>
            <span>
                <span class="tiny muted">Kosten im <?= h($monatName) ?></span>
                <span class="zahl"><?= admin_euro($kostenMonat) ?></span>
                <span class="tiny muted"><?= $grenze === null ? 'ohne eigenes Limit' : 'Limit ' . admin_euro($grenze) ?></span>
            </span>
        </div>
    </div>
    <h2>Nach Konto</h2>
    <?php if ($jeKonto === []): ?>
        <p class="muted">In diesem Monat noch nichts.</p>
    <?php else: ?>
        <div class="card liste">
            <?php foreach ($jeKonto as $k): ?>
                <div class="zeile"><span class="wer"><strong><?= h($k['name']) ?></strong>
                    <span class="tiny muted"><?= (int) $k['n'] ?> Anfragen</span></span>
                    <span class="betrag"><?= admin_euro((float) $k['c']) ?></span></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <p class="tiny"><a href="<?= h(admin_url('kosten.php')) ?>">Kosten aller Schulen im Einzelnen</a></p>

<?php else: ?>
    <form method="post" class="card kontofelder schulfelder">
        <?= csrf_field() ?>
        <label>Name der Schule <input type="text" name="name" value="<?= h($schule['name']) ?>" maxlength="128" required></label>
        <label>Kürzel zum Anmelden
            <input type="text" name="kuerzel" value="<?= h((string) ($schule['kuerzel'] ?? '')) ?>" maxlength="12"
                   autocapitalize="off" required pattern="[a-z0-9]{2,12}" title="2 bis 12 Kleinbuchstaben oder Ziffern"></label>
        <label>Höchstens so viele Lehrkräfte
            <input type="number" name="max_lehrkraefte" min="1" max="1000" value="<?= (int) $schule['max_lehrkraefte'] ?>"></label>
        <label>Eigenes Kostenlimit im Monat (USD)
            <input type="text" name="cap" inputmode="decimal" placeholder="kein eigenes Limit"
                   value="<?= $schule['monthly_cost_cap_usd'] === null ? '' : h((string) (float) $schule['monthly_cost_cap_usd']) ?>"></label>
        <span class="kontohaken"><label><input type="checkbox" name="active" value="1"<?= $schule['active'] ? ' checked' : '' ?>>
            aktiv &ndash; ohne das kann sich niemand anmelden</label></span>
        <button class="btn small" name="save_school" value="1">Speichern</button>
    </form>
    <p class="tiny muted">
        Ein neues Kürzel gilt sofort; gedruckte Zettel tragen dann noch das alte. Das Kostenlimit gilt
        <strong>zusätzlich</strong> zum Budget unter <em>Einstellungen</em> &ndash; leer heisst &bdquo;kein eigenes&ldquo;,
        eine 0 würde die Schule sofort aussperren.
    </p>

    <form method="post" class="card gefahr">
        <?= csrf_field() ?>
        <div><strong>Schule löschen</strong>
            <span class="tiny muted">Geht nur, solange keine Konten, Klassen und Kurse daran hängen. Zum Stilllegen
                reicht es, &bdquo;aktiv&ldquo; auszuschalten.</span></div>
        <button class="btn small secondary danger" name="delete_school" value="1"
                data-confirm="Schule &quot;<?= h($schule['name']) ?>&quot; löschen?">Löschen</button>
    </form>
<?php endif; ?>

<?php admin_foot(); ?>
