<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Wer darf was sehen und ändern.
 *
 * Bisher steckte die Antwort in zwei Funktionen in api/_boot.php, die drei
 * Dinge zugleich taten: die Regel anwenden, die Zeile laden und im Fehlerfall
 * eine JSON-Antwort schicken. Für einen Lehrkraft-Bereich, der HTML ausliefert
 * und weiterleitet statt json_fail() zu rufen, ist das unbrauchbar.
 *
 * Hier steht deshalb nur die Regel. Die Funktionen kennen weder Sitzung noch
 * Ausgabe und liefern im Zweifel null - was daraus wird, entscheidet die
 * aufrufende Schicht: api/_boot.php mit json_fail(), später teacher/_boot.php
 * mit flash() und redirect(). Seiteneffektfrei und damit prüfbar, wie
 * lib/colors.php und lib/pager.php.
 *
 * ACHTUNG, Zwischenstand: Die Regeln bilden noch das heutige Modell ab -
 * "eine Lerneinheit gehört genau einem Kind". Der Sinn dieses Schritts ist
 * nicht, das zu ändern, sondern alle Aufrufstellen hinter eine Naht zu holen.
 * Wenn Schulen, Klassen und Kurse dazukommen, ändert sich nur noch diese
 * Datei, nicht mehr fünfzehn Aufrufstellen.
 */

// ---------------------------------------------------------------- Rollen

const ROLE_STUDENT = 'student';
const ROLE_TEACHER = 'teacher';

/** Die Rolle eines Kontos. Unbekanntes gilt als SchülerIn. */
function user_role(array $user): string
{
    $rolle = (string) ($user['role'] ?? ROLE_STUDENT);
    return in_array($rolle, [ROLE_STUDENT, ROLE_TEACHER], true) ? $rolle : ROLE_STUDENT;
}

function user_is_teacher(array $user): bool
{
    return user_role($user) === ROLE_TEACHER;
}

// ---------------------------------------------------------------- Fähigkeiten

/** Lektionen per Foto einlesen. Kostet Geld, wird deshalb einzeln vergeben. */
const CAP_IMPORT = 'import';

/**
 * Darf dieser Account das?
 *
 * Lehrkräfte dürfen einlesen, weil es zu ihrer Arbeit gehört. Für einzelne
 * Kinder lässt es sich im Admin freischalten - gedacht als spätere
 * Zusatzleistung, deshalb steht es am Konto und nicht an der Rolle.
 */
function user_can(array $user, string $cap): bool
{
    if ($cap === CAP_IMPORT) {
        return user_is_teacher($user) || (int) ($user['can_import'] ?? 0) === 1;
    }
    return false;
}

// ---------------------------------------------------------------- Sprachen

/*
 * Ab hier entscheidet die Kurszugehörigkeit, nicht mehr das Feld user_id.
 *
 * Vorher lautete die Regel "die Lerneinheit gehört genau einem Kind". Jetzt
 * lautet sie "wer im Kurs ist, darf". Für eine Familie ist das dieselbe
 * Aussage - jedes Kind ist alleiniges Mitglied seiner eigenen Kurse -, und
 * genau deshalb dürfen die Prüfungen sich nicht rühren. Täten sie es, wäre
 * die Überführung des Bestandes unvollständig gewesen.
 */

/** Sprache zum Ansehen laden, oder null. */
function load_language_for_view(array $user, int $languageId): ?array
{
    return q1(
        'SELECT l.*
           FROM languages l
           JOIN courses co        ON co.language_id = l.id
           JOIN course_members m  ON m.course_id = co.id
          WHERE l.id = ? AND m.user_id = ?
          LIMIT 1',
        [$languageId, (int) $user['id']],
    );
}

/**
 * Sprache zum Ändern laden, oder null.
 *
 * "Ändern" schließt das Anlegen von Lerneinheiten darin ein - wer eine Lektion
 * in eine Sprache einliest, verändert deren Inhalt.
 *
 * Heute darf jedes Mitglied ändern. Sobald es Lehrkräfte gibt, wird hier auf
 * member_role eingeschränkt - und weil alle Endpunkte durch diese Funktion
 * gehen, ist das dann eine Zeile.
 */
function load_language_for_edit(array $user, int $languageId): ?array
{
    return q1(
        'SELECT l.*
           FROM languages l
           JOIN courses co        ON co.language_id = l.id
           JOIN course_members m  ON m.course_id = co.id
          WHERE l.id = ? AND m.user_id = ?
          LIMIT 1',
        [$languageId, (int) $user['id']],
    );
}

// ---------------------------------------------------------------- Lerneinheiten

/**
 * Lerneinheit zum Ansehen laden, oder null.
 *
 * Ansehen heißt: üben, den eigenen Lernstand führen, zurücksetzen. Das ist
 * ausdrücklich etwas anderes als den Inhalt zu ändern - und genau diese
 * Trennung braucht der Schulbetrieb, wo eine Klasse übt, was eine Lehrkraft
 * geschrieben hat.
 */
function load_unit_for_view(array $user, int $unitId): ?array
{
    return q1(
        'SELECT t.*
           FROM units t
           JOIN course_members m ON m.course_id = t.course_id
          WHERE t.id = ? AND m.user_id = ?
          LIMIT 1',
        [$unitId, (int) $user['id']],
    );
}

/** Lerneinheit zum Ändern laden, oder null. Umbenennen, löschen, Vokabeln. */
function load_unit_for_edit(array $user, int $unitId): ?array
{
    return q1(
        'SELECT t.*
           FROM units t
           JOIN course_members m ON m.course_id = t.course_id
          WHERE t.id = ? AND m.user_id = ?
          LIMIT 1',
        [$unitId, (int) $user['id']],
    );
}

// ---------------------------------------------------------------- Freigabe

/**
 * "Alles" - für alle, die keine Freigabemarke kennen.
 *
 * Ein echter Wert und kein Sonderfall wie null: Dadurch trägt jede Abfrage
 * dieselbe Bedingung, egal wer fragt. Eine Abfrage, die den Filter nur
 * manchmal anhängt, ist eine Abfrage, die ihn irgendwann vergisst.
 */
const POSITION_ALL = PHP_INT_MAX;

/**
 * "Alles" als Wert, der in die Spalte passt.
 *
 * units.released_position ist INT UNSIGNED; PHP_INT_MAX passt dort nicht
 * hinein. Beim Vergleichen ist das gleichgültig, beim Speichern nicht -
 * deshalb zwei Konstanten statt einer, die an der falschen Stelle abgeschnitten
 * würde.
 */
const RELEASED_ALL = 4294967295;

/**
 * Bis zu welcher vocab.position darf dieses Konto in diese Lerneinheit sehen?
 *
 * Das ist absichtlich kein Ja/Nein. Eine Lehrkraft liest eine ganze Unit ein
 * und gibt sie portionsweise frei - "Unit 1 bis 'stressed'". Für die Klasse
 * endet die Einheit dann dort, für die Lehrkraft nicht.
 *
 * Gemeint ist "so viele Vokabeln sind auf": Freigegeben ist, was
 * `v.position < released_position` erfüllt. Damit ist 0 = noch nichts, und
 * der Altbestand steht auf dem Höchstwert.
 *
 * Jede schülerseitige Vokabelabfrage muss das anwenden - einschliesslich des
 * Ablenkerpools im Quiz, der sonst nicht freigegebene Wörter als falsche
 * Antworten ausplaudert.
 */
function visible_position(array $user, array $unit): int
{
    if (user_is_teacher($user)) {
        return POSITION_ALL;
    }
    return (int) ($unit['released_position'] ?? 0);
}

/**
 * Dasselbe, wenn nur die Nummern zur Hand sind.
 *
 * Die Funktionen in lib/progress.php und lib/sentences.php bekommen Zahlen,
 * keine Zeilen. Sie sollen die Marke trotzdem selbst ermitteln statt sie
 * durchgereicht zu bekommen: Ein Parameter mit Vorgabewert wäre eine
 * Einladung, ihn an einer Aufrufstelle zu vergessen - und das fiele erst
 * auf, wenn ein Kind Vokabeln sieht, die es nicht sehen soll.
 */
/**
 * Womit eine frisch eingelesene Lerneinheit anfängt.
 *
 * Wer für sich selbst einliest, gibt sich damit auch frei - ein Kind, das
 * seine eigene Buchseite abfotografiert, soll danach üben können und nicht
 * auf eine Freigabe warten, die niemand erteilen wird.
 *
 * Eine Lehrkraft liest dagegen für andere ein. Ihre Einheit fängt bei null
 * an und wird portionsweise aufgemacht - das ist der Sinn der ganzen Etappe,
 * und es spart die Sätze für alles, was noch nicht dran ist.
 */
function initial_released_position(array $user): int
{
    return user_is_teacher($user) ? 0 : RELEASED_ALL;
}

function visible_position_for(int $unitId, int $userId): int
{
    $row = q1(
        'SELECT t.released_position, u.role
           FROM units t
           JOIN users u ON u.id = ?
          WHERE t.id = ?',
        [$userId, $unitId],
    );

    if ($row === null) {
        // Kein Konto oder keine Einheit: nichts sehen ist die sichere Antwort.
        return 0;
    }

    return visible_position(
        ['role' => $row['role']],
        ['released_position' => $row['released_position']],
    );
}

// ---------------------------------------------------------------- Lückensätze

/**
 * Lückensatz zum Ansehen laden, oder null.
 *
 * Ein Satz hat keinen eigenen Besitzer - er hängt an einer Vokabel und damit
 * an einer Lerneinheit. Die Frage "darf dieses Kind den Satz melden" ist
 * deshalb dieselbe wie "darf es die Lerneinheit sehen", nur über zwei Ecken.
 */
function load_sentence_for_view(array $user, int $sentenceId): ?array
{
    return q1(
        'SELECT s.*
           FROM sentences s
           JOIN vocab v          ON v.id = s.vocab_id
           JOIN units t          ON t.id = v.unit_id
           JOIN course_members m ON m.course_id = t.course_id
          WHERE s.id = ? AND m.user_id = ?
          LIMIT 1',
        [$sentenceId, (int) $user['id']],
    );
}
