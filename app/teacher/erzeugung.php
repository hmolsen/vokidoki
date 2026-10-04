<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/erzeugung.php';

/*
 * Der Stand von Lückensätzen und Aufnahmen eines Kurses - als Strom
 * (Server-Sent Events) für die Kursseite.
 *
 *     teacher/erzeugung.php?id=<Kurs>
 *
 * Die Seite öffnet einen EventSource, und der Server schickt, sobald sich
 * etwas ändert: Ein Ring wird zum Haken, ohne dass jemand neu lädt.
 *
 * Kurz gehaltene Verbindungen statt einer endlosen:
 *
 * - Nach einer Änderung endet die Antwort, und der Browser verbindet sich
 *   neu. Puffert unterwegs etwas - eine Komprimierung des Webservers, ein
 *   Proxy (siehe teacher_redirect_and_continue()) -, kommt die Änderung
 *   trotzdem gleich an: mit dem Ende der Antwort. Bliebe die Verbindung
 *   offen, hinge sie hinter dem Puffer bis zum Ende der 25 Sekunden.
 * - Läuft nichts, endet sie sofort, und der Browser fragt in vier Sekunden
 *   wieder (retry). Ein offener Strom hält auf dem Webhoster einen
 *   PHP-Prozess fest - eine Kursseite, die im Hintergrund offen steht, soll
 *   das nicht tun. So taucht ein Ring auch dann auf, wenn eine Kollegin
 *   gerade freigibt.
 * - Läuft etwas, bleibt sie höchstens 25 Sekunden offen und schaut alle
 *   zwei Sekunden nach.
 */

$user = teacher_require();

$courseId = (int) ($_GET['id'] ?? 0);
if (course_in_school($courseId, (int) ($user['school_id'] ?? 0)) === null) {
    http_response_code(404);
    exit;
}

// Die Sitzung freigeben: Sonst wartet jede andere Seite derselben Lehrkraft,
// bis dieser Strom endet.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

const ERZEUGUNG_TAKT      = 2;    // Sekunden zwischen zwei Blicken
const ERZEUGUNG_HOECHSTENS = 25;  // so lange bleibt eine Verbindung offen

@ini_set('zlib.output_compression', '0');
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no');
set_time_limit(ERZEUGUNG_HOECHSTENS + 15);

$senden = static function (string $text): void {
    echo $text;
    flush();
};

$ende   = time() + ERZEUGUNG_HOECHSTENS;
$vorher = null;
while (true) {
    db_ensure();
    $stand = erzeugung_kurs($courseId);

    if ($stand !== $vorher) {
        $senden(sprintf("retry: %d\nevent: stand\ndata: %s\n\n",
            $stand['laeuft'] ? 1000 : 4000,
            json_encode($stand, JSON_UNESCAPED_UNICODE)));
        if ($vorher !== null) {
            break;      // nach einer Änderung neu verbinden - siehe oben
        }
        $vorher = $stand;
    }
    if (!$stand['laeuft'] || time() >= $ende) {
        break;
    }

    sleep(ERZEUGUNG_TAKT);
    // Ein Kommentar - so merkt PHP, wenn der Tab zu ist, und hört auf.
    $senden(":\n\n");
    if (connection_aborted()) {
        break;
    }
}
