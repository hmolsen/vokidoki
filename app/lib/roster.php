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
                (SELECT COUNT(*) FROM class_members m WHERE m.class_id = c.id) AS students,
                (SELECT COUNT(*) FROM courses co WHERE co.class_id = c.id) AS courses
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
 * Macht aus der Grundform einen freien Benutzernamen - in dieser Schule.
 *
 * Benutzernamen sind nur innerhalb ihrer Schule eindeutig: Angemeldet wird
 * mit Schulkürzel, Benutzername und Passwort (lib/schulkuerzel.php). Über
 * alle Schulen hinweg gezählt hiess die zweite "lilli.m" an einer ganz
 * anderen Schule "lilli.m2" - und kein Kind konnte erraten, welche Ziffer
 * seine war. Durchgezählt wird jetzt nur noch innerhalb der Schule.
 */
function roster_free_username(string $base, int $schoolId): string
{
    $frei = static fn (string $name): bool =>
        q1('SELECT id FROM users WHERE school_id = ? AND username = ?', [$schoolId, $name]) === null;

    if ($frei($base)) {
        return $base;
    }

    for ($n = 2; $n < 200; $n++) {
        $kandidat = $base . $n;
        if ($frei($kandidat)) {
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

    $username = roster_free_username(roster_username_base($first, $initial), $schoolId);

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
    $konto = q1('SELECT username, school_id FROM users WHERE id = ?', [$userId]);
    if ($konto === null) {
        return null;
    }

    $passwort = password_generate();
    if ($passwort === null) {
        return null;
    }

    q('UPDATE users SET password_hash = ?, initial_password = ? WHERE id = ?',
      [password_hash($passwort, PASSWORD_DEFAULT), $passwort, $userId]);

    login_attempts_reset(login_schluessel((int) $konto['school_id'], (string) $konto['username']));

    return $passwort;
}

// ------------------------------------------------- Ohne Klasse

/**
 * Die Kinder einer Schule, die in keiner Klasse sind.
 *
 * So entstehen sie: Eine Lehrkraft nimmt ein Kind aus seiner Klasse, will
 * das Konto aber behalten - es wechselt die Klasse, oder es kommt nach
 * einer Pause zurück. Ohne diese Liste wäre es danach unauffindbar; der
 * einzige Weg zu einem Kind führte über seine Klasse.
 */
function students_without_class(int $schoolId): array
{
    return qa(
        "SELECT u.id, u.display_name, u.username, u.active,
                (SELECT COUNT(*) FROM course_members cm WHERE cm.user_id = u.id) AS courses
           FROM users u
          WHERE u.school_id = ? AND u.role = 'student'
            AND NOT EXISTS (SELECT 1 FROM class_members m WHERE m.user_id = u.id)
          ORDER BY u.display_name, u.id",
        [$schoolId],
    );
}

/** Wie viele es sind - für die Zahl im Menü, ohne die Liste zu laden. */
function students_without_class_count(int $schoolId): int
{
    return (int) qv(
        "SELECT COUNT(*) FROM users u
          WHERE u.school_id = ? AND u.role = 'student'
            AND NOT EXISTS (SELECT 1 FROM class_members m WHERE m.user_id = u.id)",
        [$schoolId],
    );
}

/**
 * Ein Kind aus der Klasse nehmen, das Konto bleibt.
 *
 * Mit der Klasse gehen die Kurse DIESER Klasse: Wer nicht mehr in der 5B
 * ist, soll auch nicht mehr in "Englisch - 5B" stehen. Kurse quer durch
 * die Jahrgänge bleiben. Der Lernstand bleibt ohnehin - er hängt am Konto,
 * nicht an der Mitgliedschaft, und ist wieder da, wenn das Kind zurückkommt.
 */
function student_remove_from_class(int $userId, int $classId): void
{
    q('DELETE FROM class_members WHERE class_id = ? AND user_id = ?', [$classId, $userId]);
    q("DELETE cm FROM course_members cm
         JOIN courses co ON co.id = cm.course_id
        WHERE cm.user_id = ? AND co.class_id = ? AND cm.member_role = 'student'",
      [$userId, $classId]);
}

/**
 * Das Konto eines Kindes ganz löschen.
 *
 * Mitgliedschaften in Klassen und Kursen, Geräte und Lernstand hängen per
 * ON DELETE CASCADE daran; das Kostenprotokoll behält seine Zeilen ohne
 * Konto. Nur Kinder - eine Lehrkraft verschwindet nicht über eine
 * Klassenliste.
 */
function student_delete(int $userId): bool
{
    return q("DELETE FROM users WHERE id = ? AND role = 'student'", [$userId])->rowCount() > 0;
}

/**
 * Was das Löschen einer ganzen Klasse mitnimmt - für die Warnung davor.
 *
 * Kinder, die noch in einer anderen Klasse stehen, verlieren ihr Konto
 * NICHT: Sonst nähme das Löschen der 5B einer anderen Klasse ein Kind weg,
 * und deren Lehrkraft erführe es nicht. Sie verlassen nur diese Klasse und
 * stehen gesondert in der Warnung.
 *
 * @return array{
 *     loeschen: list<array{id: int, display_name: string}>,
 *     bleiben: list<array{id: int, display_name: string}>,
 *     kurse: list<array{id: int, name: string}>,
 *     units: int, vocab: int, sentences: int, progress: int
 * }
 */
function class_delete_preview(int $classId): array
{
    $loeschen = [];
    $bleiben  = [];
    foreach (class_members_list($classId) as $m) {
        if ($m['role'] === 'teacher') {
            continue;
        }
        $woanders = (int) qv('SELECT COUNT(*) FROM class_members
                               WHERE user_id = ? AND class_id <> ?',
                             [(int) $m['id'], $classId]) > 0;
        $zeile = ['id' => (int) $m['id'], 'display_name' => (string) $m['display_name']];
        if ($woanders) {
            $bleiben[] = $zeile;
        } else {
            $loeschen[] = $zeile;
        }
    }

    $kurse = array_map(static fn (array $c): array =>
        ['id' => (int) $c['id'], 'name' => (string) $c['name']], courses_for_class($classId));

    $summe = ['units' => 0, 'vocab' => 0, 'sentences' => 0];
    foreach ($kurse as $k) {
        $v = course_delete_preview($k['id']);
        foreach ($summe as $was => $n) {
            $summe[$was] = $n + $v[$was];
        }
    }

    /*
     * Lernstände zweimal zu zählen wäre leicht: die eines gelöschten Kindes
     * in einem gelöschten Kurs. Deshalb eine Abfrage über beides - was am
     * Kind hängt oder am Kurs, je Zeile einmal.
     */
    $kindIds = array_column($loeschen, 'id') ?: [0];
    $kursIds = array_column($kurse, 'id') ?: [0];
    $fragen  = static fn (array $l): string => implode(',', array_fill(0, count($l), '?'));
    $progress = (int) qv(
        'SELECT COUNT(*) FROM progress p
           JOIN vocab v ON v.id = p.vocab_id
           JOIN units t ON t.id = v.unit_id
          WHERE p.user_id IN (' . $fragen($kindIds) . ')
             OR t.course_id IN (' . $fragen($kursIds) . ')',
        [...$kindIds, ...$kursIds],
    );

    return ['loeschen' => $loeschen, 'bleiben' => $bleiben, 'kurse' => $kurse]
         + $summe + ['progress' => $progress];
}

/**
 * Eine Klasse mit ihren Kursen und den Konten ihrer Kinder löschen.
 *
 * Alles oder nichts, in einer Transaktion: Bräche es nach dem dritten Kurs
 * ab, stünde eine halbe Klasse da, deren Rest niemand mehr gezeigt bekommt.
 * Lehrkräfte bleiben, und Kinder, die noch in einer anderen Klasse stehen,
 * auch (class_delete_preview()) - sie verlieren nur diese Klasse; ihre
 * Kurse gehen ohnehin mit.
 *
 * @return array Was gelöscht wurde, wie class_delete_preview() es zählt.
 */
function class_delete(int $classId): array
{
    $verlust = class_delete_preview($classId);

    db()->beginTransaction();
    try {
        foreach ($verlust['kurse'] as $k) {
            course_delete($k['id']);
        }
        foreach ($verlust['loeschen'] as $kind) {
            student_delete($kind['id']);
        }
        // Nimmt class_members mit, das haengt am Fremdschluessel.
        q('DELETE FROM classes WHERE id = ?', [$classId]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    return $verlust;
}

/**
 * Ein Kind ohne Klasse einer Klasse zuordnen - und den gewählten ihrer Kurse.
 *
 * Nur Kurse genau dieser Klasse werden angenommen; eine gefälschte Nummer
 * aus einer anderen Klasse fällt still heraus.
 *
 * @param list<int> $kursIds
 * @return int In wie viele Kurse das Kind kam
 */
function student_assign_class(int $userId, int $classId, array $kursIds): int
{
    q('INSERT IGNORE INTO class_members (class_id, user_id) VALUES (?, ?)', [$classId, $userId]);

    $erlaubt = array_map(static fn (array $c): int => (int) $c['id'], courses_for_class($classId));
    $n = 0;
    foreach (array_unique(array_map('intval', $kursIds)) as $kursId) {
        if (in_array($kursId, $erlaubt, true)) {
            course_add_member($kursId, $userId);
            $n++;
        }
    }
    return $n;
}
