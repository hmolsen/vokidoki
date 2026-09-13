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
