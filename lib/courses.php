<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/languages.php';

/**
 * Kurse: Klasse plus Sprache, etwa "Englisch 5B".
 *
 * Ein Kurs hält zusammen, wer mit welchem Material arbeitet. Die
 * Lerneinheiten hängen daran, nicht an einem einzelnen Konto - das ist der
 * Unterschied zwischen einer Familien- und einer Schul-App.
 *
 * Seiteneffektfrei: kein Sitzungsaufbau, keine Ausgabe. Diese Datei wird von
 * den API-Endpunkten ebenso gebraucht wie später vom Lehrkraft-Bereich.
 */

const COURSE_ROLE_TEACHER = 'teacher';
const COURSE_ROLE_STUDENT = 'student';

/** Der Kurs zu einer Sprache, oder null. */
function course_for_language(int $languageId): ?array
{
    return q1('SELECT * FROM courses WHERE language_id = ? LIMIT 1', [$languageId]);
}

/** Rolle eines Kontos in einem Kurs, oder null wenn es nicht dazugehört. */
function course_role(int $userId, int $courseId): ?string
{
    $rolle = qv(
        'SELECT member_role FROM course_members WHERE course_id = ? AND user_id = ?',
        [$courseId, $userId],
    );
    return $rolle === null ? null : (string) $rolle;
}

/** Jemanden in einen Kurs aufnehmen. Doppelte Aufnahme ändert nichts. */
function course_add_member(int $courseId, int $userId, string $role = COURSE_ROLE_STUDENT): void
{
    q(
        'INSERT IGNORE INTO course_members (course_id, user_id, member_role)
         VALUES (?, ?, ?)',
        [$courseId, $userId, $role],
    );
}

/**
 * Legt zu einer frisch angelegten Sprache den passenden Kurs an.
 *
 * Schule und Klasse kommen vom Konto, das die Sprache anlegt. Ohne Schule
 * entsteht kein Kurs - dann steht etwas anderes im Argen, und stillschweigend
 * einen Kurs ohne Zugehörigkeit zu erzeugen machte es nur schlimmer.
 */
function course_create_for_language(array $language, array $user): ?array
{
    $schoolId = (int) ($user['school_id'] ?? 0);
    if ($schoolId === 0) {
        return null;
    }

    $classId = qv(
        'SELECT c.id FROM class_members m
           JOIN classes c ON c.id = m.class_id
          WHERE m.user_id = ? AND c.school_id = ?
          LIMIT 1',
        [(int) $user['id'], $schoolId],
    );

    q(
        'INSERT INTO courses (school_id, class_id, language_id, name, created_by)
         VALUES (?, ?, ?, ?, ?)',
        [
            $schoolId,
            $classId === null ? null : (int) $classId,
            (int) $language['id'],
            mb_substr(trim($language['name'] . ' ' . $user['display_name']), 0, 128),
            (int) $user['id'],
        ],
    );

    $courseId = (int) db()->lastInsertId();

    // Mit der Rolle, die das Konto wirklich hat. Stand hier fest "student",
    // fuehrte eine Lehrkraft, die in der App eine Sprache anlegt, ihren
    // eigenen Kurs als Schuelerin - und die Uebersicht meldete null
    // Lehrkraefte.
    course_add_member(
        $courseId,
        (int) $user['id'],
        ($user['role'] ?? '') === COURSE_ROLE_TEACHER
            ? COURSE_ROLE_TEACHER : COURSE_ROLE_STUDENT,
    );

    return q1('SELECT * FROM courses WHERE id = ?', [$courseId]);
}

/**
 * Einen Kurs anlegen - der Weg, den eine Lehrkraft geht.
 *
 * Ein Kurs ist Klasse plus Sprache: "Englisch 5B". Dazu gehört immer eine
 * eigene Zeile in languages, auch wenn die Schule schon Englisch führt -
 * eine Sprache trägt Flagge und Kürzel für genau einen Kurs, und
 * course_for_language() verlässt sich darauf, dass die Zuordnung eindeutig
 * ist. Zwei Kurse "Englisch 5B" und "Englisch 6A" haben deshalb zwei
 * Zeilen desselben Namens. Das ist keine Unsauberkeit, sondern der Preis
 * dafür, dass jede Lerngruppe ihre eigenen Unterlagen hat.
 *
 * Die Kinder der Klasse kommen gleich mit hinein - so war es gedacht:
 * ausdrücklich je Kurs, aber mit der Klasse vorbelegt. Wer später dazukommt
 * oder wegfällt, wird im Kurs selbst nachgetragen.
 *
 * @return array|string Der angelegte Kurs, oder eine Meldung im Klartext.
 */
function course_create(
    array $teacher,
    string $languageName,
    string $flag,
    ?int $classId,
    string $courseName = '',
): array|string {
    $schoolId = (int) ($teacher['school_id'] ?? 0);
    if ($schoolId === 0) {
        return 'Dieses Konto gehört zu keiner Schule.';
    }

    $languageName = trim(preg_replace('/\s+/u', ' ', $languageName) ?? $languageName);
    if ($languageName === '') {
        return 'Der Kurs braucht eine Sprache.';
    }
    if (mb_strlen($languageName) > 64) {
        return 'Der Name der Sprache ist zu lang.';
    }

    $klasse = null;
    if ($classId !== null && $classId > 0) {
        $klasse = q1('SELECT * FROM classes WHERE id = ? AND school_id = ?',
                     [$classId, $schoolId]);
        if ($klasse === null) {
            return 'Diese Klasse gibt es in dieser Schule nicht.';
        }
    }

    $name = trim(preg_replace('/\s+/u', ' ', $courseName) ?? $courseName);
    if ($name === '') {
        $name = trim($languageName . ' ' . (string) ($klasse['name'] ?? ''));
    }
    $name = mb_substr($name, 0, 128);

    if (q1('SELECT id FROM courses WHERE school_id = ? AND name = ?',
           [$schoolId, $name]) !== null) {
        return sprintf('Einen Kurs "%s" gibt es an dieser Schule schon.', $name);
    }

    db()->beginTransaction();
    try {
        q(
            'INSERT INTO languages (school_id, name, flag_emoji, code) VALUES (?, ?, ?, ?)',
            [$schoolId, $languageName, mb_substr(trim($flag), 0, 16),
             language_code('', $languageName)],
        );
        $languageId = (int) db()->lastInsertId();

        q(
            'INSERT INTO courses (school_id, class_id, language_id, name, created_by)
             VALUES (?, ?, ?, ?, ?)',
            [$schoolId, $klasse === null ? null : (int) $klasse['id'],
             $languageId, $name, (int) $teacher['id']],
        );
        $courseId = (int) db()->lastInsertId();

        course_add_member($courseId, (int) $teacher['id'], COURSE_ROLE_TEACHER);

        if ($klasse !== null) {
            q(
                "INSERT IGNORE INTO course_members (course_id, user_id, member_role)
                 SELECT ?, m.user_id, 'student'
                   FROM class_members m
                   JOIN users u ON u.id = m.user_id
                  WHERE m.class_id = ? AND u.role <> 'teacher'",
                [$courseId, (int) $klasse['id']],
            );
        }

        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        return 'Der Kurs liess sich nicht anlegen: ' . $e->getMessage();
    }

    return q1('SELECT * FROM courses WHERE id = ?', [$courseId]);
}

/**
 * Die Kinder der Klasse in den Kurs nachtragen.
 *
 * Für den Fall, dass nach dem Anlegen des Kurses noch jemand in die Klasse
 * gekommen ist. Wer schon drin ist, bleibt unberührt.
 */
function course_sync_class(int $courseId): int
{
    $kurs = q1('SELECT class_id FROM courses WHERE id = ?', [$courseId]);
    if ($kurs === null || $kurs['class_id'] === null) {
        return 0;
    }

    return q(
        "INSERT IGNORE INTO course_members (course_id, user_id, member_role)
         SELECT ?, m.user_id, 'student'
           FROM class_members m
           JOIN users u ON u.id = m.user_id
          WHERE m.class_id = ? AND u.role <> 'teacher'",
        [$courseId, (int) $kurs['class_id']],
    )->rowCount();
}

/** Jemanden aus einem Kurs nehmen. Der Lernstand bleibt, falls er zurückkommt. */
function course_remove_member(int $courseId, int $userId): void
{
    q('DELETE FROM course_members WHERE course_id = ? AND user_id = ?',
      [$courseId, $userId]);
}

/** Konten der Schule, die in diesem Kurs noch fehlen. */
function course_candidates(int $courseId, int $schoolId): array
{
    return qa(
        'SELECT u.id, u.display_name, u.username, u.role
           FROM users u
          WHERE u.school_id = ? AND u.active = 1
            AND NOT EXISTS (SELECT 1 FROM course_members m
                             WHERE m.course_id = ? AND m.user_id = u.id)
          ORDER BY u.role = ?, u.display_name',
        [$schoolId, $courseId, COURSE_ROLE_STUDENT],
    );
}

/**
 * Ordnet ein frisch angelegtes Konto einer Schule und Klasse zu.
 *
 * Ohne Schule kann ein Konto weder eine Sprache anlegen noch eine
 * Lerneinheit sehen - beides haengt am Kurs, und ein Kurs haengt an der
 * Schule. Ein Konto ohne Zugehoerigkeit ist deshalb kein sparsamer
 * Sonderfall, sondern ein kaputtes Konto.
 *
 * Solange es nur eine Schule gibt, faellt die Wahl leicht. Sobald der
 * Lehrkraft-Bereich da ist, entscheidet die Lehrkraft beim Anlegen, und
 * diese Funktion bekommt die Schule mitgegeben statt sie zu suchen.
 */
function user_assign_to_school(int $userId, ?int $schoolId = null): ?int
{
    if ($schoolId === null) {
        $schoolId = qv('SELECT id FROM schools WHERE active = 1 ORDER BY id LIMIT 1');
    }
    if ($schoolId === null) {
        return null;
    }
    $schoolId = (int) $schoolId;

    q('UPDATE users SET school_id = ? WHERE id = ?', [$schoolId, $userId]);

    $classId = qv(
        'SELECT id FROM classes WHERE school_id = ? AND active = 1 ORDER BY id LIMIT 1',
        [$schoolId],
    );
    if ($classId !== null) {
        q('INSERT IGNORE INTO class_members (class_id, user_id) VALUES (?, ?)',
          [(int) $classId, $userId]);
    }

    return $schoolId;
}

/**
 * Alle Kurse einer Schule, mit den Zahlen, die eine Lehrkraft sehen will.
 *
 * Eine Lehrkraft sieht die Kurse ihrer Schule, nicht nur die eigenen. Eine
 * Schule ist eine Vertrauensgemeinschaft; dass eine Kollegin bei einer
 * Vertretung an die Unterlagen kommt, ist der Normalfall und kein Einbruch.
 * Wer einen Kurs leitet, steht als Mitglied mit der Rolle "teacher" darin.
 */
function courses_for_school(int $schoolId): array
{
    return qa(
        "SELECT co.*,
                c.name AS class_name,
                l.name AS language_name, l.flag_emoji,
                (SELECT COUNT(*) FROM course_members m
                  WHERE m.course_id = co.id AND m.member_role = 'student') AS students,
                (SELECT COUNT(*) FROM course_members m
                  WHERE m.course_id = co.id AND m.member_role = 'teacher') AS teachers,
                (SELECT COUNT(*) FROM units t WHERE t.course_id = co.id) AS units
           FROM courses co
           JOIN languages l ON l.id = co.language_id
           LEFT JOIN classes c ON c.id = co.class_id
          WHERE co.school_id = ?
          ORDER BY c.name IS NULL, c.name, co.name",
        [$schoolId],
    );
}

/**
 * Wessen Konto steht für einen Kurs gerade.
 *
 * Gebraucht an drei Stellen, die einen Menschen brauchen, wo es nur noch
 * einen Kurs gibt: das Kostenprotokoll, die Budgetprüfung, und der
 * Lernstand, den der Admin zu einer Lerneinheit anzeigt. Früher stand dort
 * units.user_id - der Besitzer, den es nicht mehr gibt.
 *
 * Bevorzugt die Lehrkraft: Sie hat den Kurs zu verantworten, und wenn eine
 * Anfrage Geld kostet, gehört sie in ihre Abrechnung und nicht in die eines
 * Kindes. Gibt es keine, tut es irgendein Mitglied - dann ist es ein
 * Familienkurs mit genau einem.
 */
function course_billing_user(?int $courseId): ?array
{
    if ($courseId === null) {
        return null;
    }

    return q1(
        "SELECT u.*
           FROM course_members m
           JOIN users u ON u.id = m.user_id
          WHERE m.course_id = ?
          ORDER BY m.member_role = 'student', m.id
          LIMIT 1",
        [$courseId],
    );
}

/** Ein Kurs, aber nur wenn er zur Schule dieser Lehrkraft gehört. */
function course_in_school(int $courseId, int $schoolId): ?array
{
    return q1(
        'SELECT co.*, c.name AS class_name, l.name AS language_name, l.flag_emoji, l.code
           FROM courses co
           JOIN languages l ON l.id = co.language_id
           LEFT JOIN classes c ON c.id = co.class_id
          WHERE co.id = ? AND co.school_id = ?',
        [$courseId, $schoolId],
    );
}

/** Wer gehört zum Kurs? Lehrkräfte zuerst. */
function course_members_list(int $courseId): array
{
    return qa(
        "SELECT m.member_role, u.id, u.display_name, u.username, u.active
           FROM course_members m
           JOIN users u ON u.id = m.user_id
          WHERE m.course_id = ?
          ORDER BY m.member_role = 'student', u.display_name",
        [$courseId],
    );
}

/** Die Lerneinheiten eines Kurses samt Umfang und Freigabestand. */
function course_units_list(int $courseId): array
{
    return qa(
        'SELECT t.*,
                (SELECT COUNT(*) FROM vocab v WHERE v.unit_id = t.id) AS vocab_count,
                (SELECT COUNT(*) FROM vocab v
                  WHERE v.unit_id = t.id AND v.position < t.released_position) AS released_count
           FROM units t
          WHERE t.course_id = ?
          ORDER BY t.created_at DESC',
        [$courseId],
    );
}
