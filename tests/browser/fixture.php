<?php
declare(strict_types=1);

/**
 * Der Bestand, gegen den die Browser-Prüfungen laufen.
 *
 * Eigene Schule, eigene Lehrkraft, eigene Klasse - nichts davon berührt,
 * was sonst in der Datenbank steht, und am Ende ist alles wieder weg. Die
 * Kennungen gehen als JSON auf die Ausgabe, damit die .mjs-Dateien sie
 * lesen können, ohne selbst mit der Datenbank zu reden.
 *
 *   php tests/browser/fixture.php          legt an und gibt JSON aus
 *   php tests/browser/fixture.php weg      räumt weg
 *
 * Die Namen tragen alle das Präfix BROWSERTEST. Wer in der Datenbank danach
 * sucht, weiss sofort, woher die Zeilen kommen - und das Wegräumen kann
 * sich daran halten statt an Kennungen, die es nach einem Abbruch nicht
 * mehr gibt.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/lib/db.php';
require_once $root . '/lib/access.php';
require_once $root . '/lib/roster.php';
require_once $root . '/lib/courses.php';
require_once $root . '/lib/worldlanguages.php';

const BT_SCHULE   = 'BROWSERTEST-Schule';
const BT_LEHRER   = 'browsertest_lehr';
const BT_PASSWORT = 'lehrerin123';

function bt_weg(): void
{
    $schule = q1('SELECT id FROM schools WHERE name = ?', [BT_SCHULE]);
    if ($schule === null) {
        return;
    }
    $sid = (int) $schule['id'];

    // Sprachen zuerst: An ihnen hängen Kurse, Einheiten, Vokabeln und Sätze
    // per ON DELETE CASCADE.
    foreach (qa('SELECT DISTINCT l.id FROM languages l
                   JOIN courses c ON c.language_id = l.id
                  WHERE c.school_id = ?', [$sid]) as $l) {
        q('DELETE FROM languages WHERE id = ?', [(int) $l['id']]);
    }
    q('DELETE FROM courses WHERE school_id = ?', [$sid]);
    q('DELETE FROM classes WHERE school_id = ?', [$sid]);
    q('DELETE FROM users   WHERE school_id = ?', [$sid]);
    q('DELETE FROM schools WHERE id = ?', [$sid]);
}

if (($argv[1] ?? '') === 'weg') {
    bt_weg();
    echo "weggeraeumt\n";
    exit;
}

bt_weg();

q('INSERT INTO schools (name, active) VALUES (?, 1)', [BT_SCHULE]);
$schuleId = (int) db()->lastInsertId();

q('INSERT INTO users (school_id, username, display_name, password_hash, color, role, can_import)
   VALUES (?, ?, ?, ?, ?, ?, 1)',
  [$schuleId, BT_LEHRER, 'Frau Browsertest',
   password_hash(BT_PASSWORT, PASSWORD_DEFAULT), '#4f7cff', ROLE_TEACHER]);
$lehrerId = (int) db()->lastInsertId();

$klasse   = class_create($schuleId, '8c');
$klasseId = (int) $klasse['id'];

// Eine leere Klasse dazu - für die Prüfung, dass der Klassenzettel beim
// ersten Kind freigegeben wird.
$leere    = class_create($schuleId, '8d');
$leereId  = (int) $leere['id'];

/*
 * Die Kinder VOR dem Kurs: course_create() uebernimmt die Klasse in den
 * Kurs. Andersherum steht der Kurs da und ist leer - und die Karte meldete
 * "0 Kinder", obwohl die Klasse voll war.
 */
students_bulk_create($schuleId, $klasseId, "Nora Wendt\nPaul Timm\nSina Krug");

$lehrer = q1('SELECT * FROM users WHERE id = ?', [$lehrerId]);
$kurs   = course_create($lehrer, 'Englisch', language_flag('Englisch'), $klasseId, '');
if (is_string($kurs)) {
    fwrite(STDERR, "Kurs: $kurs\n");
    exit(1);
}
$kursId = (int) $kurs['id'];

// Ein zweiter Kurs in einer zweiten Klasse - dafür ist der Kurswechsler da.
$klasse2   = class_create($schuleId, '9a');
$kurs2     = course_create($lehrer, 'Französisch', language_flag('Französisch'),
                           (int) $klasse2['id'], '');
$kurs2Id   = is_string($kurs2) ? 0 : (int) $kurs2['id'];

// Eine Lerneinheit mit genug Vokabeln, dass sich ein Balken ziehen lässt.
q('INSERT INTO units (language_id, course_id, title, released_position)
   VALUES (?, ?, ?, 5)', [(int) $kurs['language_id'], $kursId, 'Unit 1 - Browsertest']);
$unitId = (int) db()->lastInsertId();

$woerter = ['apple', 'bridge', 'candle', 'dragon', 'engine', 'forest',
            'garden', 'harbour', 'island', 'jungle', 'kitten', 'ladder',
            'meadow', 'needle', 'orange', 'pepper', 'quiver', 'rabbit',
            'saddle', 'tunnel', 'umpire', 'violet', 'wagon', 'yellow'];
foreach ($woerter as $i => $w) {
    q('INSERT INTO vocab (unit_id, position, term_foreign, term_native)
       VALUES (?, ?, ?, ?)', [$unitId, $i, $w, 'de-' . $w]);
}

echo json_encode([
    'basis'      => 'http://127.0.0.1:8123',
    'lehrer'     => BT_LEHRER,
    'passwort'   => BT_PASSWORT,
    'schule'     => $schuleId,
    'klasse'     => $klasseId,
    'leereKlasse' => $leereId,
    'kurs'       => $kursId,
    'kurs2'      => $kurs2Id,
    'sprache'    => (int) $kurs['language_id'],
    'unit'       => $unitId,
    'vokabeln'   => count($woerter),
    'frei'       => 5,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
