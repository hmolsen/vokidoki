<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/html.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/cost.php';
require_once __DIR__ . '/../lib/colors.php';
require_once __DIR__ . '/../lib/flags.php';
require_once __DIR__ . '/../lib/pager.php';
require_once __DIR__ . '/../lib/punctuation.php';
require_once __DIR__ . '/../lib/errors.php';
require_once __DIR__ . '/../lib/keyvault.php';
require_once __DIR__ . '/../lib/schema.php';
require_once __DIR__ . '/../lib/wordtypes.php';
require_once __DIR__ . '/../lib/throttle.php';
require_once __DIR__ . '/../lib/courses.php';
require_once __DIR__ . '/../lib/meldungen.php';
require_once __DIR__ . '/../lib/letter.php';
require_once __DIR__ . '/../lib/lehrkraefte.php';
require_once __DIR__ . '/../lib/passwords.php';
require_once __DIR__ . '/../lib/tts.php';
require_once __DIR__ . '/../lib/schulkuerzel.php';

boot_error_handling();

session_boot();


function admin_url(string $file = 'index.php'): string
{
    return url('/admin/' . $file);
}

// ---------------------------------------------------------------- CSRF

function csrf_token(): string
{
    if (empty($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['admin_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

/** Prüft das CSRF-Token jedes schreibenden Formulars. */
function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(403);
        exit('Ungültiges Formular-Token. Bitte Seite neu laden.');
    }
}

// ---------------------------------------------------------------- Anmeldung

function admin_logged_in(): bool
{
    return !empty($_SESSION['is_admin']);
}

/**
 * Erzwingt die Admin-Anmeldung. Beim ersten Login wird das Passwort aus
 * config.php übernommen und als Hash in der Datenbank abgelegt; danach ist
 * der Wert in der Konfiguration wirkungslos.
 */
function admin_require(): void
{
    if (admin_logged_in()) {
        return;
    }

    $error = null;

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['admin_password'])) {
        csrf_check();
        $password = (string) $_POST['admin_password'];

        /*
         * Auch der Betreiber wird gebremst. Der Schlüssel "#admin" kann mit
         * keinem Benutzernamen kollidieren - Konten werden kleingeschrieben
         * und enthalten keine Rautezeichen.
         */
        $ip    = login_client_ip();
        $sperr = login_guard('#admin', $ip);
        if ($sperr !== null) {
            admin_login_page($sperr);
        }

        $hash = setting('admin_password_hash', '');

        if ($hash === '') {
            $bootstrap = (string) cfg('admin_bootstrap_password', '');
            if ($bootstrap !== '' && hash_equals($bootstrap, $password)) {
                setting_set('admin_password_hash', password_hash($password, PASSWORD_DEFAULT));
                $hash = setting('admin_password_hash', '');
            }
        }

        if ($hash !== '' && password_verify($password, $hash)) {
            login_attempts_reset('#admin');
            session_regenerate_id(true);
            $_SESSION['is_admin'] = true;
            header('Location: ' . admin_url());
            exit;
        }

        login_attempt_record('#admin', $ip);
        usleep(random_int(200_000, 500_000));
        $error = 'Passwort stimmt nicht.';
    }

    admin_login_page($error);
}

function admin_login_page(?string $error): never
{
    ?>
    <!doctype html>
    <html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>Admin - Vokidoki</title>
        <?= favicon_html() ?>
        <?= verwaltung_stile_html() ?>
    </head>
    <body>
    <div class="app" style="max-width:420px">
        <div class="center" style="margin:12vh 0 6px">
            <div style="font-size:3rem">&#128274;</div>
            <h1>Administration</h1>
            <p class="sub">Vokidoki</p>
        </div>
        <?php if ($error !== null): ?>
            <div class="notice"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="post" class="card">
            <?= csrf_field() ?>
            <label for="pw">Passwort</label>
            <input type="password" id="pw" name="admin_password" autocomplete="current-password" autofocus required>
            <button class="btn" type="submit">Anmelden</button>
        </form>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// ---------------------------------------------------------------- Filter

/**
 * Eine Zeile Auswahlknöpfe statt eines Dropdowns.
 *
 * Ein Dropdown kostet zwei Klicks - aufklappen und wählen - und verbirgt, was
 * es überhaupt zur Auswahl gibt. Bei zwei Kindern und einer Handvoll Sprachen
 * ist eine Knopfreihe schneller und zeigt alles auf einen Blick. Nebenbei
 * braucht sie kein JavaScript.
 *
 * @param $items  Einträge als ['id' => int, 'label' => string]
 * @param $base   Übrige Filter, die erhalten bleiben
 * @param $resets Filter, die beim Wechsel zurückfallen - wer ein anderes Kind
 *                wählt, darf nicht auf dessen Sprache stehen bleiben
 */
function filter_chips(
    string $label,
    array $items,
    int $current,
    array $base,
    string $param,
    array $resets = [],
    ?string $allLabel = null,
): string {
    if ($items === []) {
        return '';
    }

    $page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));

    $link = static function (int $id) use ($base, $param, $resets, $page): string {
        $query = $base;
        foreach ($resets as $r) {
            unset($query[$r]);
        }
        $query[$param] = $id;
        $query = array_filter($query, static fn ($v): bool => $v !== 0 && $v !== '' && $v !== null);
        return admin_url($page) . ($query === [] ? '' : '?' . http_build_query($query));
    };

    $chips = '';
    if ($allLabel !== null) {
        $chips .= sprintf(
            '<a class="chip%s" href="%s">%s</a>',
            $current === 0 ? ' on' : '',
            h($link(0)),
            h($allLabel),
        );
    }
    /*
     * Ein Eintrag darf eine Fahne mitbringen. Zusammengesetzt wird sie hier
     * und nicht beim Aufrufer: Sonst muesste der fertiges HTML liefern, und
     * ein Feld, das mal maskiert und mal nicht maskiert wird, geht frueher
     * oder spaeter schief.
     */
    foreach ($items as $item) {
        $inhalt = (($item['flag'] ?? '') !== ''
                    ? flag_html((string) $item['flag'], 'chipflag') . ' '
                    : '')
                . h($item['label']);
        $chips .= sprintf(
            '<a class="chip%s" href="%s">%s</a>',
            (int) $item['id'] === $current ? ' on' : '',
            h($link((int) $item['id'])),
            $inhalt,
        );
    }

    return sprintf(
        '<div class="filterrow"><span class="lbl">%s</span><span class="chips">%s</span></div>',
        h($label),
        $chips,
    );
}

// ---------------------------------------------------------------- Schule, Kurs, Lerneinheit

/**
 * Wo man im Bestand steht: Schule, Kurs, Lerneinheit - aus der Anfrage,
 * und von oben nach unten geprueft.
 *
 * Frueher begann die Auswahl beim Kind, und der "Kurs" trug in Wahrheit die
 * Sprache. Beides stammte aus der Zeit, als ein Kind seine Vokabeln besass.
 * Heute gehoeren sie einer Lerneinheit, die Einheit einem Kurs, der Kurs
 * einer Schule - und genau so wird ausgewaehlt.
 *
 * Eine untere Stufe bleibt nur, wenn sie zur oberen gehoert. Sonst zeigte
 * ein alter Link die Lerneinheit eines fremden Kurses unter dem Namen des
 * gewaehlten - und wer dort speichert, aendert das Falsche. Fehlt dagegen
 * eine obere Stufe, wird sie von der unteren abgeleitet: Ein Link, der nur
 * die Lerneinheit nennt, soll auch dorthin fuehren.
 *
 * @return array{school:?array, course:?array, unit:?array,
 *               ids:array{school:int, course:int, unit:int}}
 */
function admin_scope(): array
{
    $schoolId = (int) ($_REQUEST['school'] ?? 0);
    $courseId = (int) ($_REQUEST['course'] ?? 0);
    $unitId   = (int) ($_REQUEST['unit'] ?? 0);

    if ($courseId === 0 && $unitId > 0) {
        $courseId = (int) (qv('SELECT course_id FROM units WHERE id = ?', [$unitId]) ?? 0);
    }
    if ($schoolId === 0 && $courseId > 0) {
        $schoolId = (int) (qv('SELECT school_id FROM courses WHERE id = ?', [$courseId]) ?? 0);
    }

    $school = $schoolId > 0 ? q1('SELECT * FROM schools WHERE id = ?', [$schoolId]) : null;
    $course = $school !== null && $courseId > 0
        ? course_in_school($courseId, (int) $school['id'])
        : null;
    $unit   = $course !== null && $unitId > 0
        ? q1('SELECT * FROM units WHERE id = ? AND course_id = ?', [$unitId, (int) $course['id']])
        : null;

    return [
        'school' => $school,
        'course' => $course,
        'unit'   => $unit,
        'ids'    => [
            'school' => (int) ($school['id'] ?? 0),
            'course' => (int) ($course['id'] ?? 0),
            'unit'   => (int) ($unit['id'] ?? 0),
        ],
    ];
}

/** Die Auswahl als Anfrageparameter - fuer Links, Weiterleitungen und versteckte Felder. */
function admin_scope_query(array $scope, array $extra = []): array
{
    return array_filter(
        $scope['ids'] + $extra,
        static fn ($v): bool => $v !== 0 && $v !== '' && $v !== null,
    );
}

/** Dieselbe Auswahl als versteckte Felder, damit sie ein POST uebersteht. */
function admin_scope_fields(array $scope, array $extra = []): string
{
    $html = '';
    foreach (admin_scope_query($scope, $extra) as $k => $v) {
        $html .= sprintf('<input type="hidden" name="%s" value="%s">', h((string) $k), h((string) $v));
    }
    return $html;
}

/**
 * Die drei Knopfreihen Schule, Kurs, Lerneinheit.
 *
 * Wer oben wechselt, verliert die Auswahl darunter - eine Lerneinheit
 * gehoert zu genau einem Kurs, und in einem anderen gibt es sie nicht.
 * Steht hier einmal statt auf jeder Seite, die so auswaehlt.
 *
 * @param $extra Uebrige Filter der Seite, die beim Wechsel erhalten bleiben
 *               (etwa die Suche). Die Seitenzahl faellt immer weg.
 * @param $alle  Mit "alle" je Reihe - fuer Seiten, die auch ohne Auswahl
 *               etwas zeigen.
 */
function admin_scope_chips(array $scope, array $extra = [], bool $alle = false): string
{
    ['school' => $s, 'course' => $c, 'unit' => $u] = $scope['ids'];
    $allLabel = $alle ? 'alle' : null;

    $html = filter_chips(
        'Schule',
        array_map(static fn (array $r): array => [
            'id'    => (int) $r['id'],
            'label' => $r['name'] . ($r['active'] ? '' : ' (stillgelegt)'),
        ], qa('SELECT id, name, active FROM schools ORDER BY active DESC, name')),
        $s, $extra, 'school', ['course', 'unit', 'p'], $allLabel,
    );

    if ($s > 0) {
        $html .= filter_chips(
            'Kurs',
            array_map(static fn (array $r): array => [
                'id'    => (int) $r['id'],
                'label' => $r['name'] . ($r['active'] ? '' : ' (inaktiv)'),
                'flag'  => (string) $r['flag_emoji'],
            ], courses_for_school($s)),
            $c, ['school' => $s] + $extra, 'course', ['unit', 'p'], $allLabel,
        );
    }

    if ($c > 0) {
        $html .= filter_chips(
            'Lerneinheit',
            array_map(static fn (array $r): array => [
                'id'    => (int) $r['id'],
                'label' => $r['title'] . ' (' . (int) $r['vocab_count'] . ')',
            ], course_units_list($c)),
            $u, ['school' => $s, 'course' => $c] + $extra, 'unit', ['p'], $allLabel,
        );
    }

    return $html;
}

// ---------------------------------------------------------------- Layout

function admin_head(string $title, string $active): void
{
    $nav = [
        'index.php'     => 'Kosten',
        'schools.php'   => 'Schulen',
        'users.php'     => 'Accounts',
        /*
         * Ein Eintrag fuer Vokabeln und Saetze. Die Saetze einer Lerneinheit
         * stehen jetzt unter ihren Vokabeln; die Liste ueber alle Kurse ist
         * von dort aus verlinkt und markiert diesen Eintrag mit.
         */
        'vocab.php'     => 'Unterlagen',
        'meldungen.php' => 'Meldungen',
        'aussprache.php' => 'Aussprache',
        'settings.php'  => 'Einstellungen',
        'selfcheck.php' => 'Selbsttest',
    ];
    // Rot hinter "Meldungen", solange etwas wartet - dieselbe Zahl, die die
    // Lehrkraft an ihrem Zahnrad sieht, nur ueber alle Schulen.
    $gemeldet = meldungen_zahl(null);
    ?>
    <!doctype html>
    <html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title><?= h($title) ?> - Vokidoki Admin</title>
        <?= favicon_html() ?>
        <?= verwaltung_stile_html() ?>
    </head>
    <body class="admin">
    <header class="adminbar">
        <strong>Vokidoki</strong>
        <nav>
            <?php foreach ($nav as $file => $label): ?>
                <a href="<?= h(admin_url($file)) ?>"<?= $file === $active ? ' class="on"' : '' ?>><?= h($label) ?><?=
                    $file === 'meldungen.php' && $gemeldet > 0
                        ? '<span class="zaehler">' . $gemeldet . '</span>' : '' ?></a>
            <?php endforeach; ?>
        </nav>
        <form method="post" action="<?= h(admin_url('index.php')) ?>" class="logout">
            <?= csrf_field() ?>
            <button name="admin_logout" value="1" class="linkbtn">Abmelden</button>
        </form>
    </header>
    <main class="adminmain">
        <h1><?= h($title) ?></h1>
    <?php

    /*
     * Ein offener Schemastand darf nicht nur im Selbsttest stehen.
     *
     * Ausgefuehrt wird nur auf Knopfdruck - also muss man auch erfahren, dass
     * es etwas zu druecken gibt. Ohne diesen Hinweis liefe die Anwendung nach
     * einem Upload auf einem Schema, das nicht zum Code passt, und niemand
     * wuesste warum.
     */
    if ($active !== 'selfcheck.php') {
        $offen = schema_pending();
        if ($offen !== []) {
            printf(
                '<div class="notice">Die Datenbank ist noch nicht auf dem Stand des Codes '
                . '(%d Änderung%s ausstehend). <a href="%s">Im Selbsttest ausführen</a>.</div>',
                count($offen),
                count($offen) === 1 ? '' : 'en',
                h(admin_url('selfcheck.php')),
            );
        } elseif (column_exists('schools', 'kuerzel')) {
            /*
             * Ohne Kürzel kann sich an einer Schule niemand anmelden
             * (lib/schulkuerzel.php). Nach dem Update betrifft das jede
             * Schule - also steht es hier, bis es erledigt ist.
             */
            $ohne = schulen_ohne_kuerzel();
            if ($ohne !== []) {
                printf(
                    '<div class="notice bad">%d Schule%s ohne Kürzel - dort kann sich niemand '
                    . 'anmelden. <a href="%s">Im Selbsttest vergeben</a>.</div>',
                    count($ohne),
                    count($ohne) === 1 ? '' : 'n',
                    h(admin_url('selfcheck.php') . '#kuerzel'),
                );
            }
        }
    }
}

function admin_foot(): void
{
    // Das Band "Es gibt eine neue Fassung" - wie in der App.
    echo fassung_skript_html(), "\n";
    // Kleine Zugabe: Nach der Wahl klappt das Farbfeld zu und der Knopf zeigt
    // die neue Farbe. Ohne dieses Skript funktioniert die Wahl trotzdem - dann
    // bleibt das Feld eben offen stehen, bis gespeichert wird.
    ?>
    <script>
    document.addEventListener('change', (event) => {
        const input = event.target;
        if (!input.matches('.swatch-pick input')) return;

        const picker = input.closest('.colorpick');
        if (!picker) return;

        const knopf = picker.querySelector('.swatch-current');
        if (knopf) knopf.style.setProperty('--c', input.value);
        picker.open = false;
    });

    // Rueckfrage vor dem Loeschen. Der Text steht am Knopf, damit er die
    // Zahlen nennen kann ("12 Lerneinheiten, 340 Vokabeln") statt nur
    // "sicher?" zu fragen.
    document.addEventListener('click', (event) => {
        const knopf = event.target.closest('[data-confirm]');
        if (knopf && !confirm(knopf.dataset.confirm)) event.preventDefault();
    });

    // Klick daneben schliesst ein offenes Farbfeld.
    document.addEventListener('click', (event) => {
        document.querySelectorAll('.colorpick[open]').forEach((picker) => {
            if (!picker.contains(event.target)) picker.open = false;
        });
    });

    /*
     * Sofortfilter fuer lange Tabellen.
     *
     * Vorher war die Suche ein Formular: tippen, abschicken, warten, Seite
     * neu. Bei zweihundert Saetzen sucht man aber nicht einmal, sondern
     * zehnmal hintereinander - und jedesmal war der Bildschirm kurz weg und
     * die Stelle, an der man war, auch.
     *
     * Gefiltert wird ueber data-suchtext, nicht ueber den Text der Zeile:
     * In den Zellen stehen Eingabefelder, und deren Inhalt steht nicht im
     * Text des Elements. Die Seite schreibt deshalb hinein, wonach gesucht
     * werden soll.
     *
     * Was das NICHT kann: ueber die Seitengrenze hinaussehen. Deshalb sagt
     * die Zeile darunter, wie viele von wie vielen gerade zu sehen sind,
     * und daneben steht der Weg zur Suche im ganzen Bestand.
     */
    document.querySelectorAll('[data-filter-ziel]').forEach((feld) => {
        const tabelle = document.getElementById(feld.dataset.filterZiel);
        const zaehler = document.getElementById(feld.dataset.filterZaehler || '');
        if (!tabelle) return;

        // Zeilen sind meist <tr>, in der Kontenliste aber <details> - dort
        // haette eine Tabelle das Farbfeld abgeschnitten.
        const zeilen = [...tabelle.querySelectorAll('[data-suchtext]')];

        const filtern = () => {
            const wort = feld.value.trim().toLowerCase();
            let sichtbar = 0;

            zeilen.forEach((zeile) => {
                const passt = wort === '' || zeile.dataset.suchtext.includes(wort);
                zeile.hidden = !passt;
                if (passt) sichtbar++;
            });

            if (zaehler) {
                zaehler.textContent = wort === ''
                    ? ''
                    : sichtbar + ' von ' + zeilen.length + ' auf dieser Seite';
            }
        };

        feld.addEventListener('input', filtern);
        /*
         * Enter schickt nicht ab, sondern filtert nur: Wer tippt, will die
         * Liste kuerzer sehen, nicht die Seite neu. Der Knopf daneben
         * bleibt der Weg in den ganzen Bestand.
         */
        feld.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            filtern();
        });

        filtern();
    });
    </script>
    </main></body></html>
    <?php
}

/** Meldung für die nächste Seite hinterlegen (Redirect-nach-POST). */
function flash(string $message, string $kind = 'good'): void
{
    $_SESSION['admin_flash'] = ['msg' => $message, 'kind' => $kind];
}

function flash_render(): void
{
    $f = $_SESSION['admin_flash'] ?? null;
    unset($_SESSION['admin_flash']);
    if (is_array($f)) {
        printf('<div class="notice %s">%s</div>', h($f['kind']), h($f['msg']));
    }
}

/** Nach einem POST immer weiterleiten, damit Neuladen nichts doppelt ausführt. */
function redirect(string $file): never
{
    header('Location: ' . admin_url($file));
    exit;
}

