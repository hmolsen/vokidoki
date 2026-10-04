<?php
declare(strict_types=1);

/*
 * Eine Vorführklasse für die Bildschirmfotos der Startseite.
 *
 *     php tests/browser/demo.php        anlegen (vorher aufräumen), JSON auf stdout
 *     php tests/browser/demo.php weg    wieder wegräumen
 *
 * Gebraucht von tests/browser/website-bilder.mjs. Eine eigene Schule statt der
 * Testschule: Die Bilder zeigen, wie die App im Unterricht aussieht - mit
 * Wörtern aus dem ersten Lernjahr, einer Klasse mit Namen statt "Paul Timm"
 * und "apple, bridge, candle", und einem Kind, das schon eine Weile übt.
 *
 * Alle Namen sind erfunden. Die Schule trägt das Präfix VORFUEHRUNG, damit
 * niemand sie in der Datenbank für echt hält.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2) . '/app';
require_once $root . '/lib/db.php';
require_once $root . '/lib/access.php';
require_once $root . '/lib/roster.php';
require_once $root . '/lib/courses.php';
require_once $root . '/lib/vocab.php';
require_once $root . '/lib/worldlanguages.php';
require_once $root . '/lib/einwilligung.php';
require_once $root . '/lib/tts.php';

const DEMO_SCHULE   = 'VORFUEHRUNG Gymnasium am See';
const DEMO_PASSWORT = 'vorfuehrung';

function demo_weg(): void
{
    $sid = (int) (qv('SELECT id FROM schools WHERE name = ?', [DEMO_SCHULE]) ?? 0);
    if ($sid === 0) {
        return;
    }
    foreach (qa('SELECT DISTINCT language_id FROM courses WHERE school_id = ?', [$sid]) as $l) {
        q('DELETE FROM languages WHERE id = ?', [(int) $l['language_id']]);
    }
    q('DELETE FROM courses WHERE school_id = ?', [$sid]);
    q('DELETE FROM classes WHERE school_id = ?', [$sid]);
    q('DELETE FROM users   WHERE school_id = ?', [$sid]);
    q('DELETE FROM schools WHERE id = ?', [$sid]);
}

if (($argv[1] ?? '') === 'weg') {
    demo_weg();
    echo "weggeraeumt\n";
    exit;
}

demo_weg();

q('INSERT INTO schools (name, active) VALUES (?, 1)', [DEMO_SCHULE]);
$schuleId = (int) db()->lastInsertId();

q('INSERT INTO users (school_id, username, display_name, password_hash, color, role, can_import)
   VALUES (?, ?, ?, ?, ?, ?, 1)',
  [$schuleId, 'vorfuehrung.berger', 'Frau Berger',
   password_hash(DEMO_PASSWORT, PASSWORD_DEFAULT), '#4f7cff', ROLE_TEACHER]);
$lehrerId = (int) db()->lastInsertId();
$lehrer   = q1('SELECT * FROM users WHERE id = ?', [$lehrerId]);

$klasseId = (int) class_create($schuleId, '6b')['id'];
students_bulk_create($schuleId, $klasseId, implode("\n", [
    'Lina Hoffmann', 'Mats Becker', 'Emilia Schulz', 'Noah Wagner', 'Ida Richter',
    'Ben Klein', 'Frieda Wolf', 'Jonas Neumann', 'Mila Schwarz', 'Paul Zimmermann',
    'Lotta Braun', 'Elias Krüger', 'Hanna Hofmann', 'Leon Hartmann', 'Clara Lange',
]));
// Eine zweite Klasse, damit die Übersicht nicht nach Einzelfall aussieht.
$klasse7 = (int) class_create($schuleId, '7a')['id'];
students_bulk_create($schuleId, $klasse7, "Anton Vogel\nGreta Roth\nMax Kühn\nSophie Haas");

// Lina ist das Kind auf den Fotos: bekanntes Passwort, eine freundliche Farbe.
$lina = (int) qv("SELECT id FROM users WHERE school_id = ? AND display_name LIKE 'Lina%'", [$schuleId]);
// Seit zwei Monaten dabei: Der Kalender blättert nicht vor das Anlegen des Kontos zurück.
q('UPDATE users SET password_hash = ?, initial_password = NULL, color = ?, streak_best = 14,
          created_at = NOW() - INTERVAL 60 DAY WHERE id = ?',
  [password_hash(DEMO_PASSWORT, PASSWORD_DEFAULT), '#7c5cff', $lina]);

$fr = course_create($lehrer, 'Französisch', language_flag('Französisch'), $klasseId, '');
$en = course_create($lehrer, 'Englisch', language_flag('Englisch'), $klasseId, '');
$en7 = course_create($lehrer, 'Englisch', language_flag('Englisch'), $klasse7, '');
foreach ([$fr, $en, $en7] as $k) {
    if (is_string($k)) {
        fwrite(STDERR, "Kurs: $k\n");
        exit(1);
    }
}

/**
 * Eine Lerneinheit mit Vokabeln und je einem Lückensatz.
 *
 * @param list<array{0:string,1:string,2:string,3?:array{0:string,1:string,2:string}}> $woerter
 *        [fremd, deutsch, wortart, [deutscher Satz, Satz mit {}, Lösung]]
 */
function demo_einheit(array $kurs, string $titel, array $woerter, int $frei, string $code): int
{
    q("INSERT INTO units (language_id, course_id, title, position, released_position, sentences_status)
       VALUES (?, ?, ?, ?, 0, 'done')",
      [(int) $kurs['language_id'], (int) $kurs['id'], $titel, unit_next_position((int) $kurs['id'])]);
    $unit = (int) db()->lastInsertId();

    vocab_append($unit, array_map(
        static fn (array $w): array => ['foreign' => $w[0], 'native' => $w[1]], $woerter), $code);

    foreach (qa('SELECT id, position FROM vocab WHERE unit_id = ? ORDER BY position', [$unit]) as $v) {
        $w = $woerter[(int) $v['position']];
        q('UPDATE vocab SET word_type = ? WHERE id = ?', [$w[2], (int) $v['id']]);
        if (isset($w[3])) {
            q('INSERT INTO sentences (vocab_id, native_text, foreign_text, answer) VALUES (?, ?, ?, ?)',
              [(int) $v['id'], $w[3][0], $w[3][1], $w[3][2]]);
        }
    }
    q('UPDATE units SET released_position = ? WHERE id = ?', [$frei, $unit]);
    return $unit;
}

$u1 = demo_einheit($fr, 'Unité 1 – Bonjour !', [
    ['bonjour', 'hallo, guten Tag', 'interjektion', ['Hallo, ich bin Lina.', '{}, je suis Lina.', 'Bonjour']],
    ['salut', 'hallo; tschüss', 'interjektion', ['Tschüss, bis morgen!', '{}, à demain !', 'Salut']],
    ['Je m\'appelle …', 'Ich heiße …', 'aussage', ['Ich heiße Mats.', '{} Mats.', 'Je m\'appelle']],
    ['Tu t\'appelles comment ?', 'Wie heißt du?', 'frage', ['Wie heißt du?', 'Tu {} comment ?', 't\'appelles']],
    ['Ça va ?', 'Wie geht\'s?', 'frage', ['Hallo Emma, wie geht\'s?', 'Salut Emma, {} ?', 'ça va']],
    ['merci', 'danke', 'interjektion', ['Danke, Frau Berger!', '{}, madame Berger !', 'Merci']],
    ['au revoir', 'auf Wiedersehen', 'interjektion', ['Auf Wiedersehen, bis Montag!', '{}, à lundi !', 'Au revoir']],
    ['le copain', 'der Freund', 'substantiv', ['Das ist mein Freund Noah.', 'C\'est mon {} Noah.', 'copain']],
    ['la copine', 'die Freundin', 'substantiv', ['Ida ist meine Freundin.', 'Ida est ma {}.', 'copine']],
    ['oui', 'ja', 'adverb', ['Ja, ich komme.', '{}, je viens.', 'Oui']],
], 10, 'fr');

$u2 = demo_einheit($fr, 'Unité 2 – Ma famille', [
    ['la mère', 'die Mutter', 'substantiv', ['Meine Mutter heißt Anne.', 'Ma {} s\'appelle Anne.', 'mère']],
    ['le père', 'der Vater', 'substantiv', ['Mein Vater kocht gern.', 'Mon {} aime faire la cuisine.', 'père']],
    ['la sœur', 'die Schwester', 'substantiv', ['Meine Schwester ist zehn.', 'Ma {} a dix ans.', 'sœur']],
    ['le frère', 'der Bruder', 'substantiv', ['Hast du einen Bruder?', 'Tu as un {} ?', 'frère']],
    ['le chat', 'die Katze', 'substantiv', ['Die Katze schläft auf dem Sofa.', 'Le {} dort sur le canapé.', 'chat']],
    ['le chien', 'der Hund', 'substantiv', ['Unser Hund heißt Filou.', 'Notre {} s\'appelle Filou.', 'chien']],
    ['habiter', 'wohnen', 'verb', ['Ich wohne in Hamburg.', 'J\'{} à Hambourg.', 'habite']],
    ['avoir', 'haben', 'verb', ['Wir haben eine Katze.', 'Nous {} un chat.', 'avons']],
    ['petit, petite', 'klein', 'adjektiv', ['Meine Schwester ist klein.', 'Ma sœur est {}.', 'petite']],
    ['grand, grande', 'groß', 'adjektiv', ['Mein Bruder ist groß.', 'Mon frère est {}.', 'grand']],
    ['les grands-parents', 'die Großeltern', 'substantiv', ['Meine Großeltern wohnen in Lyon.', 'Mes {} habitent à Lyon.', 'grands-parents']],
    ['aussi', 'auch', 'adverb', ['Ich habe auch einen Hund.', 'J\'ai {} un chien.', 'aussi']],
], 8, 'fr');

$u3 = demo_einheit($fr, 'Unité 3 – Au collège', [
    ['le collège', 'die Schule (Sek. I)', 'substantiv', ['Die Schule beginnt um acht.', 'Le {} commence à huit heures.', 'collège']],
    ['la récréation', 'die Pause', 'substantiv', ['In der Pause spielen wir Fußball.', 'À la {}, on joue au foot.', 'récré']],
    ['le cahier', 'das Heft', 'substantiv', ['Wo ist mein Heft?', 'Où est mon {} ?', 'cahier']],
    ['la trousse', 'das Mäppchen', 'substantiv', ['Mein Mäppchen ist blau.', 'Ma {} est bleue.', 'trousse']],
    ['travailler', 'arbeiten', 'verb', ['Wir arbeiten zu zweit.', 'Nous {} à deux.', 'travaillons']],
    ['écouter', 'zuhören', 'verb', ['Hört gut zu!', '{} bien !', 'Écoutez']],
], 0, 'fr');

demo_einheit($en, 'Unit 1 – Hello!', [
    ['hello', 'hallo', 'interjektion', ['Hallo, ich bin Lina.', '{}, I\'m Lina.', 'Hello']],
    ['What\'s your name?', 'Wie heißt du?', 'frage', ['Wie heißt du?', 'What\'s your {}?', 'name']],
    ['the pencil', 'der Bleistift', 'substantiv', ['Mein Bleistift ist rot.', 'My {} is red.', 'pencil']],
    ['the teacher', 'die Lehrerin, der Lehrer', 'substantiv', ['Unsere Lehrerin ist nett.', 'Our {} is nice.', 'teacher']],
    ['friend', 'Freund, Freundin', 'substantiv', ['Das ist meine Freundin Ida.', 'This is my {} Ida.', 'friend']],
    ['to like', 'mögen', 'verb', ['Ich mag Katzen.', 'I {} cats.', 'like']],
    ['big', 'groß', 'adjektiv', ['Der Hund ist groß.', 'The dog is {}.', 'big']],
    ['Goodbye!', 'Auf Wiedersehen!', 'interjektion', ['Auf Wiedersehen, bis morgen!', '{}, see you tomorrow!', 'Goodbye']],
], 8, 'en');

demo_einheit($en7, 'Unit 1 – Back to school', [
    ['the timetable', 'der Stundenplan', 'substantiv', ['Wo ist unser Stundenplan?', 'Where is our {}?', 'timetable']],
    ['the break', 'die Pause', 'substantiv', ['In der Pause essen wir.', 'We eat in the {}.', 'break']],
], 2, 'en');

/*
 * Der Lernstand. Lina ist weit: die erste Unité in allen Übungen durch, die
 * zweite halb. Die Klasse streut, damit die Übersicht der Lehrkraft etwas
 * zu zeigen hat.
 */
$stand = static function (int $user, int $vocab, string $modus, int $serie, bool $gekonnt): void {
    q('INSERT INTO progress (user_id, vocab_id, mode, streak, correct_count, wrong_count, known_at, last_seen_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
       ON DUPLICATE KEY UPDATE streak = VALUES(streak), known_at = VALUES(known_at)',
      [$user, $vocab, $modus, $serie, $serie + 2, $gekonnt ? 1 : 2, $gekonnt ? date('Y-m-d H:i:s') : null]);
};

$v1 = array_map('intval', array_column(qa('SELECT id FROM vocab WHERE unit_id = ? ORDER BY position', [$u1]), 'id'));
$v2 = array_map('intval', array_column(qa('SELECT id FROM vocab WHERE unit_id = ? ORDER BY position', [$u2]), 'id'));

foreach ($v1 as $v) {
    foreach (['mc', 'pick', 'cloze', 'listen'] as $modus) {
        $stand($lina, $v, $modus, 3, true);
    }
}
foreach ($v2 as $i => $v) {
    if ($i < 6) {
        $stand($lina, $v, 'mc', 3, true);
    } elseif ($i < 8) {
        /*
         * Höchstens ein Punkt: Beim Foto vom Auswählen soll "Richtig!"
         * stehen, nicht Konfetti und ein Lob quer über den Antworten. Mit
         * zwei Punkten hinge es davon ab, welches Wort die Übung zieht.
         */
        $stand($lina, $v, 'mc', $i - 6, false);
    }
    if ($i < 5) {
        $stand($lina, $v, 'pick', 3, true);
    }
    if ($i < 3) {
        $stand($lina, $v, 'cloze', 3, true);
    }
    if ($i < 2) {
        $stand($lina, $v, 'listen', 3, true);
    }
}

/*
 * Die Aufnahmen fürs Hören. Lokal spricht tests/fake-azure-tts.php - die
 * Tonspur ist dort nur Stille, aber fotografiert wird ja nur der Bildschirm.
 * Ohne Aufnahmen gäbe es in der Lerneinheit kein Hören, und die Startseite
 * zeigte drei Übungen statt vier.
 */
foreach ([$u1, $u2] as $u) {
    $t = tts_nachtragen($u, $lehrer);
    if ($t['fehler'] !== null) {
        fwrite(STDERR, "Aufnahmen: {$t['fehler']}\n");
    }
}

$kinder = array_map('intval', array_column(qa(
    "SELECT u.id FROM users u JOIN class_members m ON m.user_id = u.id
      WHERE m.class_id = ? AND u.id <> ? ORDER BY u.display_name", [$klasseId, $lina]), 'id'));
foreach ($kinder as $n => $kind) {
    $weit = ($n * 7) % 11;              // 0..10 - gestreut, aber reproduzierbar
    foreach ($v1 as $i => $v) {
        if ($i < $weit) {
            $stand($kind, $v, 'mc', 3, true);
        }
        if ($i < $weit - 3) {
            $stand($kind, $v, 'cloze', 3, true);
        }
    }
    foreach ($v2 as $i => $v) {
        if ($i < $weit - 5) {
            $stand($kind, $v, 'mc', 3, true);
        }
    }
    // Die meisten haben in den letzten Tagen geübt.
    for ($t = 0; $t < ($n % 5) + 1; $t++) {
        q('INSERT IGNORE INTO learn_days (user_id, `day`, learned, correct) VALUES (?, ?, ?, ?)',
          [$kind, date('Y-m-d', strtotime("-$t days")), 3, 12]);
    }
}

// Linas Serie: die letzten zwölf Tage, davor eine kleine Lücke und ältere Tage.
for ($t = 0; $t < 12; $t++) {
    q('INSERT INTO learn_days (user_id, `day`, learned, correct) VALUES (?, ?, ?, ?)',
      [$lina, date('Y-m-d', strtotime("-$t days")), 2 + ($t % 4), 10 + $t]);
}
foreach ([15, 16, 17, 19, 20, 21, 22, 24] as $t) {
    q('INSERT INTO learn_days (user_id, `day`, learned, correct) VALUES (?, ?, ?, ?)',
      [$lina, date('Y-m-d', strtotime("-$t days")), 3, 11]);
}

// Eine Meldung aus dem Lückentext - für die Seite, auf der die Lehrkraft sie abarbeitet.
$mats = (int) qv("SELECT id FROM users WHERE school_id = ? AND display_name LIKE 'Mats%'", [$schuleId]);
$satz = (int) qv('SELECT s.id FROM sentences s WHERE s.vocab_id = ?', [$v2[6]]);
q('INSERT INTO vocab_flags (vocab_id, sentence_id, user_id, typed) VALUES (?, ?, ?, ?)',
  [$v2[6], $satz, $mats, 'habite']);

// Die Hinweise der ersten Anmeldung gelten fuer alle Konten hier als bestaetigt -
// sonst stuende jede Pruefung zuerst vor dieser Seite.
q('UPDATE users SET consent_version = ?, consent_at = NOW() WHERE school_id = ?',
  [EINWILLIGUNG_FASSUNG, $schuleId]);

echo json_encode([
    'lehrer'   => 'vorfuehrung.berger',
    'kind'     => (string) qv('SELECT username FROM users WHERE id = ?', [$lina]),
    'passwort' => DEMO_PASSWORT,
    'kursFr'   => (int) $fr['id'],
    'kursEn'   => (int) $en['id'],
    'klasse'   => $klasseId,
    'unit1'    => $u1,
    'unit2'    => $u2,
    'unit3'    => $u3,
], JSON_PRETTY_PRINT), "\n";
