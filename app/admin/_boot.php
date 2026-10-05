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
require_once __DIR__ . '/../lib/access.php';
require_once __DIR__ . '/../lib/thema.php';
require_once __DIR__ . '/../lib/markdown.php';
require_once __DIR__ . '/../lib/version.php';

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

// ---------------------------------------------------------------- Was zu tun ist

/**
 * Die Farbe einer Schule - immer dieselbe, damit man sie im Vorbeigehen
 * wiedererkennt (Übersicht, Menü). Aus der Kennung, nicht gespeichert:
 * Sie bedeutet nichts, sie unterscheidet nur.
 */
function schule_farbe(int $schuleId): string
{
    $farben = ['#4f7cff', '#1c9d5c', '#e0559a', '#f08c2e', '#8a5cf6', '#159fb5', '#d8402f'];
    return $farben[$schuleId % count($farben)];
}

/** Ein Betrag in Euro, wie ihn ein Mensch liest: "8,30 €". */
function admin_euro(float $usd): string
{
    return number_format(usd_to_eur($usd), 2, ',', '.') . ' €';
}

/**
 * Was der Betreiber gerade erledigen sollte - für die Übersicht.
 *
 * Vorher stand das an drei Orten: ein Band über jeder Seite für das
 * Datenbank-Update und die Kürzel, eine Zahl hinter "Meldungen" und das
 * aufgebrauchte Budget nur auf der Kostenseite. Wer nicht zufällig dort
 * vorbeikam, erfuhr vom leeren Freikontingent erst, als keine Aufnahmen
 * mehr kamen. Jetzt steht es oben auf der ersten Seite, mit dem Weg dorthin.
 *
 * @return list<array{art: string, symbol: string, titel: string, text: string, ziel: string, knopf?: string}>
 *         art: rot (blockiert etwas), gelb (bald), blau (zur Kenntnis)
 */
function admin_zu_tun(): array
{
    $liste = [];

    $offen = schema_pending();
    if ($offen !== []) {
        $liste[] = ['art' => 'rot', 'symbol' => '&#128736;&#65039;', 'titel' => 'Update einspielen',
                    'text' => count($offen) === 1 ? '1 Datenbankänderung wartet'
                                                  : count($offen) . ' Datenbankänderungen warten',
                    'ziel' => 'selfcheck.php#schema', 'knopf' => 'run_migrations'];
        // Ohne das Update fehlen womöglich Spalten, die alles Weitere abfragt.
        return $liste;
    }

    $ohne = schulen_ohne_kuerzel();
    if ($ohne !== []) {
        $liste[] = ['art' => 'rot', 'symbol' => '&#128273;',
                    'titel' => count($ohne) === 1 ? '1 Schule ohne Kürzel' : count($ohne) . ' Schulen ohne Kürzel',
                    'text' => 'Dort kann sich niemand anmelden.', 'ziel' => 'selfcheck.php#kuerzel'];
    }

    $je = meldungen_je_schule();
    if ($je !== []) {
        $namen = array_column(qa('SELECT id, name FROM schools'), 'name', 'id');
        arsort($je);
        $teile = [];
        foreach ($je as $schule => $n) {
            $teile[] = $n . ' an ' . ($namen[$schule] ?? 'einer gelöschten Schule');
        }
        $summe = array_sum($je);
        $liste[] = ['art' => 'rot', 'symbol' => '&#128681;',
                    'titel' => $summe === 1 ? '1 Meldung offen' : $summe . ' Meldungen offen',
                    'text' => count($je) === 1 ? 'An ' . ($namen[array_key_first($je)] ?? '') : implode(', ', $teile),
                    'ziel' => 'meldungen.php'];
    }

    $budget = (float) setting('monthly_cost_cap_usd', '10.00');
    $monat  = cost_this_month();
    if ($budget > 0 && $monat >= $budget) {
        $liste[] = ['art' => 'rot', 'symbol' => '&#128182;', 'titel' => 'Monatsbudget aufgebraucht',
                    'text' => 'Einlesen und neue Sätze sind gesperrt, bis zum 1. oder bis das Budget steigt.',
                    'ziel' => 'settings.php'];
    } elseif ($budget > 0 && $monat >= $budget * 0.8) {
        $liste[] = ['art' => 'gelb', 'symbol' => '&#128182;',
                    'titel' => sprintf('Monatsbudget zu %d %% verbraucht', (int) floor($monat / $budget * 100)),
                    'text' => admin_euro($monat) . ' von ' . admin_euro($budget), 'ziel' => 'kosten.php'];
    }

    foreach (cost_this_month_by_school() as $s) {
        $grenze = $s['monthly_cost_cap_usd'];
        if ($grenze !== null && (float) $grenze > 0 && (float) $s['cost_usd'] >= (float) $grenze) {
            $liste[] = ['art' => 'rot', 'symbol' => '&#127979;', 'titel' => $s['name'] . ': Kostenlimit erreicht',
                        'text' => 'Die Schule kann bis zum 1. nichts mehr einlesen.',
                        'ziel' => 'schule.php?id=' . (int) $s['id'] . '&r=kosten'];
        }
    }

    /*
     * Das Freikontingent der Aufnahmen - mit einer Schätzung, wann es
     * reicht. Bei diesem Tempo heisst: so viele Zeichen je Tag wie bisher in
     * diesem Monat. Ab 70 % steht es hier; vorher wäre es Lärm.
     */
    $frei = tts_tarif_frei() ? tts_freikontingent() : 0;
    if ($frei > 0) {
        $zeichen = tts_zeichen_monat();
        $anteil  = $zeichen / $frei;
        if ($anteil >= TTS_KONTINGENT_RAND) {
            $liste[] = ['art' => 'rot', 'symbol' => '&#127911;', 'titel' => 'Freikontingent der Aufnahmen aufgebraucht',
                        'text' => 'Neue Aufnahmen entstehen erst ab dem 1. wieder.', 'ziel' => 'kosten.php#aufnahmen'];
        } elseif ($anteil >= 0.7) {
            $jeTag = $zeichen / max(1, (int) date('j'));
            $tage  = $jeTag > 0 ? (int) floor(($frei * TTS_KONTINGENT_RAND - $zeichen) / $jeTag) : 99;
            $bis   = (int) date('j') + $tage;
            $liste[] = ['art' => 'gelb', 'symbol' => '&#127911;',
                        'titel' => sprintf('Aufnahmen: %d %% des Freikontingents', (int) floor($anteil * 100)),
                        'text' => $bis >= (int) date('t') ? 'Reicht bei diesem Tempo bis zum Monatsende.'
                                                           : 'Reicht bei diesem Tempo bis etwa zum ' . $bis . '.',
                        'ziel' => 'kosten.php#aufnahmen'];
        }
    }

    return $liste;
}

/** Die Liste als Karten - Symbol, Titel, eine Zeile dazu, und der Weg dorthin. */
function admin_zu_tun_html(array $liste): string
{
    $html = '<div class="aufgaben">';
    foreach ($liste as $a) {
        $inhalt = '<span class="ic ' . $a['art'] . '" aria-hidden="true">' . $a['symbol'] . '</span>'
                . '<span class="t"><strong>' . h($a['titel']) . '</strong><span class="tiny muted">'
                . h($a['text']) . '</span></span>';
        if (isset($a['knopf'])) {
            // Der Knopf tut es gleich - dieselbe Anfrage wie im Selbsttest.
            $html .= '<form method="post" action="' . h(admin_url(strtok($a['ziel'], '#'))) . '" class="aufgabe">'
                   . csrf_field() . $inhalt
                   . '<button class="btn small" name="' . h($a['knopf']) . '" value="1">Jetzt ausführen</button></form>';
        } else {
            $html .= '<a class="aufgabe" href="' . h(admin_url($a['ziel'])) . '">' . $inhalt
                   . '<span class="pfeil" aria-hidden="true">&#8250;</span></a>';
        }
    }
    return $html . '</div>';
}

// ---------------------------------------------------------------- Layout

/**
 * Ein Eintrag im Menü - dieselbe Gestalt wie im Lehrkraft-Bereich.
 *
 * @param string $ziel   Datei samt Anfrage, etwa 'schools.php#schule3'
 * @param string $aktiv  die Datei der Seite, auf der man steht
 */
function admin_menuepunkt(string $ziel, string $symbol, string $text, string $aktiv, int $zahl = 0): string
{
    // Genau diese Seite - mit Anfrage, denn die Schulen unterscheiden sich
    // nur in ?id= und die Einstellungen in ?s=. Ein #Anker zählt nicht.
    $an = strtok($ziel, '#') === $aktiv;
    return sprintf(
        '<a class="mitem%s" href="%s"%s><span class="micon" aria-hidden="true">%s</span><span>%s</span>%s</a>',
        $an ? ' on' : '',
        h(admin_url($ziel)),
        $an ? ' aria-current="page"' : '',
        $symbol,
        h($text),
        $zahl > 0 ? '<span class="zaehler" aria-label="' . $zahl . ' offen">' . $zahl . '</span>' : '',
    );
}

/**
 * Die Leiste und ihre beiden Schubladen.
 *
 * Hier standen acht gleichrangige Reiter in einer Zeile. Am Telefon brachen
 * sie über drei Zeilen und füllten den Bildschirm, bevor eine Zahl zu sehen
 * war. Jetzt dieselbe Hülle wie im Lehrkraft-Bereich: links die Navigation,
 * am Rechner fest stehend (seitenleiste_skript()), rechts Farben und
 * Abmelden. Wer zwischen beiden Bereichen wechselt, bedient beide gleich.
 *
 * Gegliedert, wie der Betreiber arbeitet: die Schulen einzeln - fast alles
 * betrifft genau eine -, dann was quer über alle geht (Qualität), dann der
 * Betrieb.
 */
function admin_nav(string $aktiv): void
{
    $schulen  = qa('SELECT id, name, active FROM schools ORDER BY active DESC, name');
    $je       = meldungen_je_schule();
    $gemeldet = array_sum($je);
    ?>
<div class="adminbar" data-menue="<?= h(url('/menue.js') . '?v=' . app_version()) ?>">
    <details class="menue" id="menuLinks">
        <summary class="burger" aria-label="Menü" title="Menü">
            <span aria-hidden="true">&#9776;</span>
            <?php if ($gemeldet > 0): ?><span class="zaehler" aria-hidden="true"><?= $gemeldet ?></span><?php endif; ?>
        </summary>
        <span class="schleier" data-zu></span>
        <nav class="schublade" aria-label="Navigation">
            <div class="mkopf">
                <a href="<?= h(admin_url('index.php')) ?>" class="mlogo" aria-label="Vokidoki Admin - zur Übersicht">
                    <img src="<?= h(url('/assets/vokidoki_logo.svg')) ?>" alt="Vokidoki" width="768" height="256">
                </a>
                <span class="mschule">Admin</span>
            </div>
            <?= admin_menuepunkt('index.php', '&#127968;', 'Übersicht', $aktiv) ?>

            <p class="mueber">Schulen</p>
            <?php foreach ($schulen as $s): ?>
                <?= admin_menuepunkt('schule.php?id=' . (int) $s['id'],
                                     $s['active'] ? '&#127979;' : '&#128164;',
                                     (string) $s['name'], $aktiv, $je[(int) $s['id']] ?? 0) ?>
            <?php endforeach; ?>
            <?= admin_menuepunkt('schools.php', '&#10133;', 'Neue Schule', $aktiv) ?>

            <p class="mueber">Qualität</p>
            <?= admin_menuepunkt('meldungen.php', '&#128681;', 'Meldungen', $aktiv, $gemeldet) ?>
            <?= admin_menuepunkt('aussprache.php', '&#128483;&#65039;', 'Aussprache', $aktiv) ?>
            <?= admin_menuepunkt('sentences.php', '&#128269;', 'Lückensätze durchsuchen', $aktiv) ?>
            <?= admin_menuepunkt('vocab.php', '&#128218;', 'Unterlagen aller Schulen', $aktiv) ?>

            <p class="mueber">Betrieb</p>
            <?= admin_menuepunkt('kosten.php', '&#128182;', 'Kosten', $aktiv) ?>
            <?= admin_menuepunkt('settings.php', '&#10024;', 'KI und Aufnahmen', $aktiv) ?>
            <?= admin_menuepunkt('settings.php?s=zettel', '&#9993;&#65039;', 'Zettel und Vorlagen', $aktiv) ?>
            <?= admin_menuepunkt('selfcheck.php', '&#129658;', 'Selbsttest und Updates', $aktiv) ?>
            <?= admin_menuepunkt('settings.php?s=passwort', '&#128273;', 'Admin-Passwort', $aktiv) ?>
        </nav>
    </details>
    <?= seitenleiste_skript() ?>

    <?php
    /*
     * Die Suche steht in der Leiste, auf jeder Seite: Der häufigste Weg zu
     * einem Konto beginnt mit seinem Namen (suche.php). Am Telefon nur das
     * Zeichen - das Feld hätte dort keinen Platz.
     */
    ?>
    <form method="get" action="<?= h(admin_url('suche.php')) ?>" class="barsuche" role="search">
        <input type="search" name="q" placeholder="Schule, Lehrkraft oder Kind suchen" aria-label="Suchen"
               value="<?= h($aktiv === 'suche.php' ? (string) ($_GET['q'] ?? '') : '') ?>" autocomplete="off">
    </form>
    <a class="barsuchknopf" href="<?= h(admin_url('suche.php')) ?>" aria-label="Suchen" title="Suchen">&#128269;</a>

    <span class="barname">Admin</span>

    <details class="menue rechts" id="menuRechts">
        <summary class="burger" aria-label="Einstellungen" title="Einstellungen">
            <span aria-hidden="true">&#9881;</span>
        </summary>
        <span class="schleier" data-zu></span>
        <nav class="schublade" aria-label="Einstellungen">
            <?= thema_wahl_html() ?>
            <hr class="mtrenner">
            <?php foreach (legal_documents() as $k => $d): ?>
                <a class="mitem" href="<?= h(url('/rechtliches.php') . '?d=' . $k) ?>">
                    <span class="micon" aria-hidden="true">&#167;</span>
                    <span><?= h($d['kurz']) ?></span>
                </a>
            <?php endforeach; ?>
            <hr class="mtrenner">
            <form method="post" action="<?= h(admin_url('index.php')) ?>">
                <?= csrf_field() ?>
                <button class="mitem" name="admin_logout" value="1">
                    <span class="micon" aria-hidden="true">&#9099;</span>
                    <span>Abmelden</span>
                </button>
            </form>
        </nav>
    </details>
</div>
    <?php
}

function admin_head(string $title, string $active): void
{
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
        <?= thema_kopf_skript() ?>
    </head>
    <body class="admin">
    <?php admin_nav($active); ?>
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
    // Auf der Übersicht steht dasselbe in "Zu tun" (admin_zu_tun()) - zweimal wäre Lärm.
    if ($active !== 'selfcheck.php' && $active !== 'index.php') {
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
    // Die Schubladen - dasselbe Verhalten wie im Lehrkraft-Bereich (menue.js).
    printf('<script type="module">import { menueAktivieren, themaWahlAktivieren } from %s;'
           . ' menueAktivieren(); themaWahlAktivieren();</script>' . "\n",
           json_encode(url('/menue.js') . '?v=' . app_version(), JSON_UNESCAPED_SLASHES));
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

    // Aus der Suche: das gesuchte Konto aufgeklappt und im Blick (suche.php).
    const ziel = location.hash.startsWith('#konto') ? document.querySelector(location.hash) : null;
    if (ziel && ziel.tagName === 'DETAILS') {
        ziel.open = true;
        ziel.scrollIntoView({ block: 'center' });
    }

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

