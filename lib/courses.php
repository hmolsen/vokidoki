<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

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
    course_add_member($courseId, (int) $user['id'], COURSE_ROLE_STUDENT);

    return q1('SELECT * FROM courses WHERE id = ?', [$courseId]);
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
