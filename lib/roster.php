<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/colors.php';
require_once __DIR__ . '/courses.php';
require_once __DIR__ . '/passwords.php';
require_once __DIR__ . '/throttle.php';

/**
 * Klassen und die Konten der SchülerInnen.
 *
 * Der Weg, auf den es ankommt: Eine Lehrkraft hat eine Klassenliste vor sich
 * und soll in zwei Minuten fertig sein. Also keine Maske je Kind, sondern ein
 * Textfeld, in das sie die Liste hineinkopiert - so wie sie sie hat.
 *
 * Dass dabei die Nachnamen verlorengehen, ist kein Nebeneffekt, sondern der
 * Zweck. Gespeichert werden Vorname und Anfangsbuchstabe, mehr nicht. Ein
 * Vokabeltrainer braucht nicht zu wissen, wer die Kinder sind - er muss sie
 * nur auseinanderhalten können, und dafür reicht "Lilli M.". Der Nachname
 * wird beim Einlesen weggeworfen und kommt gar nicht erst in die Datenbank.
 *
 * Seiteneffektfrei: kein Sitzungsaufbau, keine Ausgabe.
 */

/**
 * Umschrift für Benutzernamen.
 *
 * Anders als DIACRITICS in lib/sentences.php: Dort geht es um den Vergleich
 * einer Antwort, hier um einen Namen, den ein Kind abtippt. "Jürgen" wird
 * deshalb zu "juergen" und nicht zu "jurgen" - so schreibt man es, wenn man
 * keine Umlaute zur Verfügung hat.
 */
const USERNAME_MAP = [
    'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
    'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae',
    'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
    'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
    'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'œ' => 'oe',
    'ú' => 'u', 'ù' => 'u', 'û' => 'u',
    'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y',
];

// ------------------------------------------------------------------ Klassen

/** Alle Klassen einer Schule, mit der Zahl der Kinder darin. */
function classes_for_school(int $schoolId): array
{
    return qa(
        'SELECT c.*,
                (SELECT COUNT(*) FROM class_members m WHERE m.class_id = c.id) AS students
           FROM classes c
          WHERE c.school_id = ?
          ORDER BY c.active DESC, c.name',
        [$schoolId],
    );
}

/** Eine Klasse, aber nur wenn sie zur Schule dieser Lehrkraft gehört. */
function class_in_school(int $classId, int $schoolId): ?array
{
    return q1('SELECT * FROM classes WHERE id = ? AND school_id = ?', [$classId, $schoolId]);
}

/**
 * Eine Klasse anlegen. Gibt die Zeile zurück oder eine Meldung als Zeichenkette.
 *
 * Zwei Rückgabearten in einer Funktion sind unschön, ersparen dem Aufrufer
 * aber, dieselben Regeln noch einmal zu prüfen, um eine Meldung formulieren
 * zu können.
 */
function class_create(int $schoolId, string $name): array|string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

    if ($name === '') {
        return 'Die Klasse braucht einen Namen.';
    }
    if (mb_strlen($name) > 32) {
        return 'Der Name ist zu lang.';
    }

    $da = q1('SELECT * FROM classes WHERE school_id = ? AND name = ?', [$schoolId, $name]);
    if ($da !== null) {
        return sprintf('Die Klasse "%s" gibt es schon.', $name);
    }

    q('INSERT INTO classes (school_id, name) VALUES (?, ?)', [$schoolId, $name]);

    return q1('SELECT * FROM classes WHERE id = ?', [(int) db()->lastInsertId()]);
}

/** Wer ist in der Klasse? Mit dem Anfangspasswort, solange es noch gilt. */
function class_members_list(int $classId): array
{
    return qa(
        'SELECT u.id, u.display_name, u.username, u.role, u.active, u.initial_password,
                u.created_at
           FROM class_members m
           JOIN users u ON u.id = m.user_id
          WHERE m.class_id = ?
          ORDER BY u.display_name',
        [$classId],
    );
}

// ------------------------------------------------- Namensliste einlesen

/**
 * Zerlegt eine eingefügte Klassenliste in Vorname und Anfangsbuchstabe.
 *
 * Erkannt werden beide Schreibweisen, die aus einem Schulverwaltungsprogramm
 * kommen:
 *
 *     Lilli Molsen        ->  Lilli M.
 *     Molsen, Lilli       ->  Lilli M.
 *     Lilli               ->  Lilli
 *
 * Das Komma entscheidet, welcher Teil der Vorname ist - ohne Komma steht er
 * vorn, mit Komma hinten. Doppelnamen mit Bindestrich bleiben ganz
 * ("Anna-Lena"), ein zweiter Vorname ohne Bindestrich zählt zum Nachnamen und
 * faellt damit weg.
 *
 * Rein rechnerisch, ohne Datenbank - so lassen sich die Formate prüfen, ohne
 * Konten anzulegen.
 *
 * @return list<array{first: string, initial: string, raw: string}>
 */
function roster_parse_names(string $text): array
{
    $namen = [];

    foreach (preg_split('/\R/u', $text) ?: [] as $zeile) {
        $zeile = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $zeile) ?? $zeile);
        if ($zeile === '') {
            continue;
        }

        if (str_contains($zeile, ',')) {
            [$hinten, $vorn] = array_map('trim', explode(',', $zeile, 2));
            $vorname  = explode(' ', $vorn)[0] ?? '';
            $nachname = $hinten;
        } else {
            $teile    = explode(' ', $zeile);
            $vorname  = array_shift($teile) ?? '';
            $nachname = $teile === [] ? '' : end($teile);
        }

        if ($vorname === '') {
            continue;
        }

        $namen[] = [
            'first'   => mb_substr($vorname, 0, 32),
            'initial' => $nachname === '' ? '' : mb_strtoupper(mb_substr($nachname, 0, 1)),
            'raw'     => $zeile,
        ];
    }

    return $namen;
}

/**
 * Eine Farbe zum Namen.
 *
 * Nicht zufällig, sondern aus dem Benutzernamen gerechnet: Dann bekommt
 * dasselbe Kind bei einem Neuanlegen wieder seine Farbe, und eine Klasse
 * wird trotzdem bunt statt einfarbig. Die Lehrkraft kann sie später ändern -
 * das hier ist nur ein Anfang, der besser ist als 28-mal dasselbe Blau.
 */
function roster_color_for(string $username): string
{
    $palette = color_palette();
    return $palette[hexdec(substr(md5($username), 0, 8)) % count($palette)];
}

/** Wie der Name in der App erscheint: "Lilli M." */
function roster_display_name(string $first, string $initial): string
{
    return $initial === '' ? $first : $first . ' ' . $initial . '.';
}

/** Die Grundform des Benutzernamens - noch ohne Rücksicht auf Dubletten. */
function roster_username_base(string $first, string $initial): string
{
    $roh = mb_strtolower($first) . ($initial === '' ? '' : '.' . mb_strtolower($initial));
    $roh = strtr($roh, USERNAME_MAP);

    // Alles, was auf einer Tastatur Ärger macht, fliegt raus. Der Punkt
    // zwischen Vorname und Buchstabe bleibt, der Bindestrich in
    // "anna-lena" auch.
    $roh = preg_replace('/[^a-z0-9.\-]/', '', $roh) ?? $roh;
    $roh = trim($roh, '.-');

    return $roh === '' ? 'kind' : mb_substr($roh, 0, 48);
}

/**
 * Macht aus der Grundform einen freien Benutzernamen.
 *
 * Benutzernamen sind über alle Schulen hinweg eindeutig - so steht es in der
 * Tabelle, und so soll es bleiben: Ein Kind tippt seinen Namen ein, ohne
 * vorher eine Schule zu wählen. Bei einer zweiten "lilli.m" wird also
 * durchgezählt.
 */
function roster_free_username(string $base): string
{
    if (q1('SELECT id FROM users WHERE username = ?', [$base]) === null) {
        return $base;
    }

    for ($n = 2; $n < 200; $n++) {
        $kandidat = $base . $n;
        if (q1('SELECT id FROM users WHERE username = ?', [$kandidat]) === null) {
            return $kandidat;
        }
    }

    // Sollte nie eintreten; besser ein hässlicher Name als ein Abbruch
    // mitten in einer Klassenliste.
    return mb_substr($base, 0, 40) . '.' . bin2hex(random_bytes(3));
}

// ------------------------------------------------------- Konten anlegen

/**
 * Ein Schülerkonto samt Anfangspasswort.
 *
 * Das Passwort steht danach im Klartext in der Zeile - das ist die bewusste
 * Entscheidung des Betreibers, damit sich das Anschreiben nachdrucken lässt.
 * Der Wert verschwindet, sobald das Kind sein Passwort ändert.
 *
 * @param string[] $vermeiden Schon vergebene Passwörter dieses Durchlaufs
 */
function student_create(
    int $schoolId,
    ?int $classId,
    string $first,
    string $initial,
    array $vermeiden = [],
): ?array {
    $passwort = password_generate($vermeiden);
    if ($passwort === null) {
        return null;
    }

    $username = roster_free_username(roster_username_base($first, $initial));

    q(
        'INSERT INTO users (school_id, username, display_name, role, password_hash,
                            initial_password, color)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [
            $schoolId,
            $username,
            roster_display_name($first, $initial),
            'student',
            password_hash($passwort, PASSWORD_DEFAULT),
            $passwort,
            roster_color_for($username),
        ],
    );

    $id = (int) db()->lastInsertId();

    if ($classId !== null) {
        q('INSERT IGNORE INTO class_members (class_id, user_id) VALUES (?, ?)',
          [$classId, $id]);
    }

    return q1('SELECT * FROM users WHERE id = ?', [$id]);
}

/**
 * Eine ganze Klassenliste auf einmal.
 *
 * Bereits vorhandene Kinder werden übersprungen statt doppelt angelegt - eine
 * Lehrkraft, die die Liste ein zweites Mal einfügt, weil sie unsicher war,
 * soll keine 28 Karteileichen erzeugen. Erkannt wird das am Anzeigenamen
 * innerhalb derselben Klasse; zwei echte "Lilli M." in einer Klasse sind
 * selten genug, dass die Lehrkraft den zweiten von Hand anlegen kann.
 *
 * @return array{created: list<array>, skipped: list<string>}
 */
function students_bulk_create(int $schoolId, int $classId, string $text): array
{
    $vorhanden = [];
    foreach (class_members_list($classId) as $m) {
        $vorhanden[mb_strtolower($m['display_name'])] = true;
    }

    $angelegt   = [];
    $übersprung = [];
    $passwörter = [];

    foreach (roster_parse_names($text) as $n) {
        $anzeige = mb_strtolower(roster_display_name($n['first'], $n['initial']));

        if (isset($vorhanden[$anzeige])) {
            $übersprung[] = $n['raw'];
            continue;
        }
        $vorhanden[$anzeige] = true;

        $konto = student_create($schoolId, $classId, $n['first'], $n['initial'], $passwörter);
        if ($konto === null) {
            $übersprung[] = $n['raw'];
            continue;
        }

        $passwörter[] = (string) $konto['initial_password'];
        $angelegt[]   = $konto;
    }

    return ['created' => $angelegt, 'skipped' => $übersprung];
}

/**
 * Neues Anfangspasswort für ein Kind, das seines vergessen hat.
 *
 * Setzt zugleich die Anmeldebremse zurück - wer sein Passwort vergessen hat,
 * hat es meist mehrfach falsch versucht, und ein frisches Passwort nützt
 * nichts, solange das Konto noch gesperrt ist.
 */
function student_reset_password(int $userId): ?string
{
    $konto = q1('SELECT username FROM users WHERE id = ?', [$userId]);
    if ($konto === null) {
        return null;
    }

    $passwort = password_generate();
    if ($passwort === null) {
        return null;
    }

    q('UPDATE users SET password_hash = ?, initial_password = ? WHERE id = ?',
      [password_hash($passwort, PASSWORD_DEFAULT), $passwort, $userId]);

    login_attempts_reset((string) $konto['username']);

    return $passwort;
}
