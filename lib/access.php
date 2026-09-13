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

// ---------------------------------------------------------------- Fähigkeiten

/** Lektionen per Foto einlesen. Kostet Geld, wird deshalb einzeln vergeben. */
const CAP_IMPORT = 'import';

/**
 * Darf dieser Account das?
 *
 * Heute darf jeder alles - es gibt weder Rollen noch Freischaltungen. Die
 * Abfrage steht trotzdem schon an den richtigen Stellen, damit später nur
 * diese Funktion Bescheid wissen muss und nicht jeder Endpunkt.
 */
function user_can(array $user, string $cap): bool
{
    return $user !== [] && $cap !== '';
}

// ---------------------------------------------------------------- Sprachen

/** Sprache zum Ansehen laden, oder null. */
function load_language_for_view(array $user, int $languageId): ?array
{
    return q1(
        'SELECT * FROM languages WHERE id = ? AND user_id = ?',
        [$languageId, (int) $user['id']],
    );
}

/**
 * Sprache zum Ändern laden, oder null.
 *
 * "Ändern" schließt das Anlegen von Lerneinheiten darin ein - wer eine Lektion
 * in eine Sprache einliest, verändert deren Inhalt.
 */
function load_language_for_edit(array $user, int $languageId): ?array
{
    return q1(
        'SELECT * FROM languages WHERE id = ? AND user_id = ?',
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
        'SELECT * FROM units WHERE id = ? AND user_id = ?',
        [$unitId, (int) $user['id']],
    );
}

/** Lerneinheit zum Ändern laden, oder null. Umbenennen, löschen, Vokabeln. */
function load_unit_for_edit(array $user, int $unitId): ?array
{
    return q1(
        'SELECT * FROM units WHERE id = ? AND user_id = ?',
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
           JOIN vocab v ON v.id = s.vocab_id
           JOIN units t ON t.id = v.unit_id
          WHERE s.id = ? AND t.user_id = ?',
        [$sentenceId, (int) $user['id']],
    );
}
