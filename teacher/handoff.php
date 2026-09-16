<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/qr.php';
require_once __DIR__ . '/../lib/handoff.php';

/**
 * Erzeugt eine Einmal-Marke und liefert den QR-Code dazu.
 *
 * Getrennt von der Kursseite, weil eine Marke eine Handlung ist und keine
 * Eigenschaft der Seite: Sie entsteht, wenn jemand darauf drückt, nicht bei
 * jedem Aufruf. Deshalb POST und nicht GET - ein Bild, das bei jedem Laden
 * einen Schlüssel erzeugt, hinterlässt eine Spur von Schlüsseln.
 *
 * Geprüft wird dreierlei, und keines davon ist verhandelbar:
 *   - Die Sitzung gehört einer Lehrkraft (teacher_require).
 *   - Das Formular kommt von uns (teacher_csrf_check).
 *   - Der Kurs gehört zur Schule dieser Lehrkraft.
 * Die Marke lautet danach auf das eigene Konto, nie auf ein anderes.
 */

$user = teacher_require();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['ok' => false, 'error' => 'POST erwartet.']));
}

teacher_csrf_check();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$schoolId = (int) ($user['school_id'] ?? 0);
$courseId = (int) ($_POST['course_id'] ?? 0);
$kurs     = course_in_school($courseId, $schoolId);

if ($kurs === null) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'error' => 'Diesen Kurs gibt es nicht.']));
}

$ziel  = '/lang/' . (int) $kurs['language_id'] . '/import';
$marke = handoff_create((int) $user['id'], $ziel);

/*
 * Die Adresse muss vollstaendig sein - sie wird abfotografiert, nicht
 * angeklickt. public_url() nimmt dafuer den Eintrag aus der Konfiguration,
 * sonst den Host der laufenden Anfrage.
 */
$adresse = public_url('/') . '?h=' . rawurlencode($marke);
$svg     = qr_svg($adresse, 4, 'Anmelden und einlesen');

if ($svg === null) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'Der Code liess sich nicht erzeugen.']));
}

echo json_encode([
    'ok'      => true,
    'svg'     => $svg,
    'minuten' => HANDOFF_TTL,
    'kurs'    => (string) $kurs['name'],
], JSON_UNESCAPED_UNICODE);
