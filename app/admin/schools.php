<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

admin_require();

/*
 * Schulen - der Mandant.
 *
 * Das ist der erste Schritt einer Installation, nicht der letzte: Ohne Schule
 * kann ein Konto weder eine Sprache anlegen noch eine Lerneinheit sehen,
 * denn beides haengt am Kurs und ein Kurs an der Schule. Deshalb steht die
 * Seite auch als erste im Menue.
 *
 * Angelegt wird hier, und nur hier: Eine Schule ist eine Entscheidung des
 * Betreibers, kein Seiteneffekt. Still eine anzulegen, damit irgendetwas
 * funktioniert, hiesse einen Posten zu erzeugen, den spaeter jemand
 * wegraeumen muss - und niemand wuesste, woher er kommt.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    if (isset($_POST['create'])) {
        $name    = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? '')) ?? '');
        // Ohne Kürzel könnte sich niemand anmelden - also gleich beim Anlegen.
        $kuerzel = schulkuerzel_normal((string) ($_POST['kuerzel'] ?? ''));

        if ($name === '') {
            flash('Die Schule braucht einen Namen.', 'bad');
        } elseif (mb_strlen($name) > 128) {
            flash('Der Name ist zu lang.', 'bad');
        } elseif (q1('SELECT id FROM schools WHERE name = ?', [$name]) !== null) {
            flash('Diese Schule gibt es schon.', 'bad');
        } elseif (($grund = schulkuerzel_pruefen($kuerzel)) !== null) {
            flash($grund, 'bad');
        } else {
            q('INSERT INTO schools (name, kuerzel) VALUES (?, ?)', [$name, $kuerzel]);
            flash('Schule "' . $name . '" mit dem Kürzel "' . $kuerzel . '" angelegt.');
        }
        redirect('schools.php');
    }

    if (isset($_POST['update'])) {
        $id   = (int) ($_POST['id'] ?? 0);
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? '')) ?? '');
        $cap  = trim((string) ($_POST['cap'] ?? ''));

        $andere = q1('SELECT id FROM schools WHERE name = ? AND id <> ?', [$name, $id]);
        $kuerzel = schulkuerzel_normal((string) ($_POST['kuerzel'] ?? ''));

        if ($name === '') {
            flash('Die Schule braucht einen Namen.', 'bad');
        } elseif ($andere !== null) {
            flash('Diesen Namen trägt schon eine andere Schule.', 'bad');
        } elseif (($grund = schulkuerzel_pruefen($kuerzel, $id)) !== null) {
            flash($grund, 'bad');
        } else {
            /*
             * Ein neues Kürzel gilt sofort: Wer angemeldet ist, bleibt es;
             * wer sich neu anmeldet, braucht das neue. Gedruckte Zettel
             * tragen dann noch das alte.
             */
            q('UPDATE schools SET kuerzel = ? WHERE id = ?', [$kuerzel, $id]);
            /*
             * Leeres Feld heisst "kein eigenes Limit" und nicht "null Dollar".
             * Der Unterschied ist erheblich: NULL laesst nur das Budget des
             * Betreibers greifen, 0.00 wuerde die Schule sofort aussperren.
             */
            q(
                'UPDATE schools SET name = ?, active = ?, monthly_cost_cap_usd = ? WHERE id = ?',
                [
                    mb_substr($name, 0, 128),
                    isset($_POST['active']) ? 1 : 0,
                    $cap === '' ? null : number_format((float) str_replace(',', '.', $cap), 2, '.', ''),
                    $id,
                ],
            );
            flash('Schule gespeichert.');
        }
        redirect('schools.php');
    }

    if (isset($_POST['delete'])) {
        $id     = (int) $_POST['delete'];
        $schule = q1('SELECT * FROM schools WHERE id = ?', [$id]);

        if ($schule === null) {
            flash('Diese Schule gibt es nicht mehr.', 'bad');
            redirect('schools.php');
        }

        /*
         * Loeschen nur, solange nichts daran haengt.
         *
         * Der Fremdschluessel raeumte sonst Klassen und Kurse mit ab, und an
         * den Kursen haengen die Lerneinheiten. Ein Fehlklick loeschte die
         * Arbeit einer ganzen Schule. Wer wirklich loeschen will, leert sie
         * erst - und wer sie nur aus dem Weg haben will, schaltet sie ab.
         */
        $konten  = (int) qv('SELECT COUNT(*) FROM users WHERE school_id = ?', [$id]);
        $klassen = (int) qv('SELECT COUNT(*) FROM classes WHERE school_id = ?', [$id]);
        $kurse   = (int) qv('SELECT COUNT(*) FROM courses WHERE school_id = ?', [$id]);

        if ($konten + $klassen + $kurse > 0) {
            flash(sprintf(
                'Nicht gelöscht: An dieser Schule hängen noch %d Konten, %d Klassen '
                . 'und %d Kurse. Zum Stilllegen das Häkchen "aktiv" entfernen.',
                $konten, $klassen, $kurse,
            ), 'bad');
        } else {
            q('DELETE FROM schools WHERE id = ?', [$id]);
            flash('Schule "' . $schule['name'] . '" gelöscht.');
        }
        redirect('schools.php');
    }
}

$rate = (float) setting('usd_eur', '0.92');
$eur  = static fn (float $usd): string => number_format($usd * $rate, 2, ',', '.') . ' EUR';

$schulen = qa(
    "SELECT s.*,
            (SELECT COUNT(*) FROM users u   WHERE u.school_id = s.id) AS konten,
            (SELECT COUNT(*) FROM users u   WHERE u.school_id = s.id
                                              AND u.role = 'teacher') AS lehrkraefte,
            (SELECT COUNT(*) FROM classes c WHERE c.school_id = s.id) AS klassen,
            (SELECT COUNT(*) FROM courses o WHERE o.school_id = s.id) AS kurse,
            (SELECT COALESCE(SUM(a.cost_usd), 0) FROM ai_requests a
              WHERE a.school_id = s.id
                AND a.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')) AS kosten
       FROM schools s
      ORDER BY s.active DESC, s.name"
);

admin_head('Schulen', 'schools.php');
flash_render();
?>

<?php if ($schulen === []): ?>
<div class="notice">
    <strong>Hier fängt alles an.</strong> Ohne Schule kann kein Konto etwas
    sehen - Lerneinheiten hängen an Kursen, Kurse an Schulen. Leg zuerst eine
    an, dann unter <em>Accounts</em> die Lehrkräfte dazu.
</div>
<?php endif; ?>

<form method="post" class="card" style="max-width:480px">
    <?= csrf_field() ?>
    <label for="name">Name der Schule</label>
    <input type="text" id="name" name="name" maxlength="128"
           placeholder="Gymnasium Musterstadt" required autofocus>
    <label for="kuerzel">Kürzel zum Anmelden</label>
    <input type="text" id="kuerzel" name="kuerzel" maxlength="12" autocapitalize="off"
           placeholder="gm" required pattern="[a-z0-9]{2,12}"
           title="2 bis 12 Kleinbuchstaben oder Ziffern">
    <p class="tiny muted" style="margin:-4px 0 10px">Kinder und Lehrkräfte tippen es bei der
        Anmeldung ein; es steht auf jedem Zettel.</p>
    <button class="btn small" name="create" value="1">Schule anlegen</button>
</form>

<?php if ($schulen !== []): ?>
<h2>Vorhandene Schulen</h2>

<?php foreach ($schulen as $s): ?>
    <div class="card">
        <h3 style="margin:0 0 12px">
            <?= h($s['name']) ?>
            <span class="muted" style="font-weight:400">
                &middot; <?= (int) $s['konten'] ?> Konten
                (<?= (int) $s['lehrkraefte'] ?> Lehrkräfte)
                &middot; <?= (int) $s['klassen'] ?> Klassen
                &middot; <?= (int) $s['kurse'] ?> Kurse
                &middot; <?= $eur((float) $s['kosten']) ?> diesen Monat
                <?= $s['active'] ? '' : ' &middot; stillgelegt' ?>
            </span>
        </h3>

        <form method="post" class="inline" style="margin-bottom:8px">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <input type="text" name="name" value="<?= h($s['name']) ?>" maxlength="128"
                   style="width:240px;margin:0">
            <input type="text" name="kuerzel" value="<?= h((string) ($s['kuerzel'] ?? '')) ?>" maxlength="12"
                   placeholder="Kürzel" title="Kürzel zum Anmelden" autocapitalize="off"
                   style="width:100px;margin:0" required>
            <input type="text" name="cap" inputmode="decimal"
                   value="<?= $s['monthly_cost_cap_usd'] === null
                              ? '' : h((string) (float) $s['monthly_cost_cap_usd']) ?>"
                   placeholder="Limit USD" title="Monatslimit dieser Schule in USD. Leer = kein eigenes Limit."
                   style="width:110px;margin:0">
            <label style="display:flex;align-items:center;gap:6px;margin:0;font-weight:500">
                <input type="checkbox" name="active" value="1"<?= $s['active'] ? ' checked' : '' ?>
                       style="width:auto;min-height:auto;margin:0"> aktiv
            </label>
            <button class="btn small secondary" name="update" value="1">Speichern</button>
        </form>

        <p class="tiny" style="margin:0 0 8px">
            <a href="<?= h(admin_url('users.php') . '?school=' . (int) $s['id']) ?>">Accounts</a>
            &middot;
            <a href="<?= h(admin_url('vocab.php') . '?school=' . (int) $s['id']) ?>">Kurse und Unterlagen</a>
        </p>

        <form method="post" class="compact">
            <?= csrf_field() ?>
            <button class="linkbtn" name="delete" value="<?= (int) $s['id'] ?>"
                    data-confirm="Schule &quot;<?= h($s['name']) ?>&quot; löschen? Geht nur, solange nichts daran hängt.">
                löschen
            </button>
        </form>
    </div>
<?php endforeach; ?>

<p class="tiny muted">
    Das Monatslimit gilt <strong>zusätzlich</strong> zu dem des Betreibers
    unter <em>Einstellungen</em>: Eine Schule soll sich verrechnen können, ohne
    die anderen mit auszusperren, und eine Schule ohne eigenes Limit darf das
    des Betreibers nicht umgehen. Leeres Feld heißt „kein eigenes Limit" - eine
    eingetragene 0 würde die Schule sofort aussperren.
</p>
<?php endif; ?>

<?php admin_foot(); ?>
