<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/tts.php';

/*
 * Die Aufnahme eines Lückensatzes für "Hören" (lib/tts.php).
 *
 * Ein gewöhnlicher GET ohne den Kopf X-Vokabeltrainer: Ein <audio>-Element
 * kann keine eigenen Köpfe schicken, nur das Cookie der Sitzung. Es ändert
 * nichts, und ausgeliefert wird nur, was das Konto ohnehin üben darf -
 * dieselbe Schranke wie im Bündel (api/bundle.php): Mitglied im Kurs, und
 * die Vokabel freigegeben.
 *
 * Die Adresse trägt das Kurzzeichen der Aufnahme (?h=). Ändert sich der
 * Satz, ändert sich die Adresse - deshalb darf der Browser eine Antwort
 * ein Jahr lang behalten, und der Service Worker legt sie ab, ohne je
 * nachzufragen.
 */

/*
 * ?s= ein Satz (fürs Hören), ?w= eine Vokabel (fürs Auswählen). Dieselben
 * Schranken - nur die Tabelle ist eine andere.
 */
$wort  = isset($_GET['w']);
$id    = (int) ($wort ? $_GET['w'] : ($_GET['s'] ?? 0));
$quelle = $wort
    ? 'FROM vocab_audio a JOIN vocab v ON v.id = a.vocab_id'
    : 'FROM sentence_audio a JOIN sentences s ON s.id = a.sentence_id JOIN vocab v ON v.id = s.vocab_id';
$schluessel = $wort ? 'a.vocab_id' : 'a.sentence_id';

/*
 * Die Meldungen (lib/meldungen.php) spielen die Aufnahme zum Anhören ab -
 * der Lehrkraft des Kurses auch, wenn die Freigabe inzwischen
 * zurückgenommen ist, und dem Admin, der kein Konto in der App hat.
 */
session_boot();
if (!empty($_SESSION['is_admin'])) {
    $zeile = q1("SELECT a.file, a.hash $quelle WHERE $schluessel = ?", [$id]);
} else {
    $user  = require_user();
    $zeile = q1(
        "SELECT a.file, a.hash
           $quelle
           JOIN units u ON u.id = v.unit_id
           JOIN courses co ON co.id = u.course_id
           JOIN course_members m ON m.course_id = co.id AND m.user_id = ?
          WHERE $schluessel = ?
            AND (v.position < u.released_position OR m.member_role = 'teacher')",
        [(int) $user['id'], $id],
    );
}

// Dieselbe Antwort für "gibt es nicht" und "darfst du nicht" - wie überall.
$pfad = $zeile === null ? null : storage_path((string) $zeile['file']);
if ($pfad === null || !is_file($pfad)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Diese Aufnahme gibt es nicht.\n";
    exit;
}

$etag = '"' . $zeile['hash'] . '"';
header('Content-Type: audio/mpeg');
header('Cache-Control: private, max-age=31536000, immutable');
header('ETag: ' . $etag);
header('Accept-Ranges: bytes');

if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

// json_boot() puffert für JSON-Antworten - eine Datei geht ungepuffert hinaus.
while (ob_get_level() > 0) {
    ob_end_clean();
}

/*
 * Stücke, wenn danach gefragt wird. Safari holt eine Aufnahme so (erst
 * bytes=0-1, dann den Rest) und spielt eine Antwort, die das nicht kann,
 * auf dem iPhone unter Umständen gar nicht ab.
 */
$laenge = (int) filesize($pfad);
if (preg_match('/^bytes=(\d*)-(\d*)$/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $b) === 1) {
    $von = $b[1] === '' ? $laenge - (int) $b[2] : (int) $b[1];
    $bis = $b[1] !== '' && $b[2] !== '' ? (int) $b[2] : $laenge - 1;
    $von = max(0, $von);
    $bis = min($bis, $laenge - 1);
    if ($von > $bis) {
        http_response_code(416);
        header('Content-Range: bytes */' . $laenge);
        exit;
    }
    http_response_code(206);
    header('Content-Range: bytes ' . $von . '-' . $bis . '/' . $laenge);
    header('Content-Length: ' . ($bis - $von + 1));
    $datei = fopen($pfad, 'rb');
    fseek($datei, $von);
    echo fread($datei, $bis - $von + 1);
    fclose($datei);
    exit;
}

header('Content-Length: ' . $laenge);
readfile($pfad);
