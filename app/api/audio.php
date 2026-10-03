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

$user = require_user();
$id   = (int) ($_GET['s'] ?? 0);

$zeile = q1(
    'SELECT a.file, a.hash
       FROM sentence_audio a
       JOIN sentences s ON s.id = a.sentence_id
       JOIN vocab v ON v.id = s.vocab_id
       JOIN units u ON u.id = v.unit_id
       JOIN courses co ON co.id = u.course_id
       JOIN course_members m ON m.course_id = co.id AND m.user_id = ?
      WHERE a.sentence_id = ? AND v.position < u.released_position',
    [(int) $user['id'], $id],
);

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
header('Accept-Ranges: none');

if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

// json_boot() puffert für JSON-Antworten - eine Datei geht ungepuffert hinaus.
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Length: ' . filesize($pfad));
readfile($pfad);
