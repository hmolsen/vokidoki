<?php
declare(strict_types=1);

/*
 * Der Webroot von vokidoki.de, nachgestellt für den eingebauten PHP-Server.
 *
 *     php -S 127.0.0.1:8123 -t . tests/router.php
 *
 * Auf dem Server liegen nebeneinander:
 *
 *     index.html, bilder/ ...   die Startseite, aus website/
 *     app/                      die Anwendung, aus app/
 *     daten/                    config.php und storage/ - gesperrt
 *
 * Im Repository liegen website/ und app/ nebeneinander, und die Startseite
 * gehört auf /, nicht auf /website/. Dieser Router macht aus dem Repository
 * denselben Baum: /app/... geht an den eingebauten Server (-t . zeigt auf
 * die Wurzel, dort liegt app/), alles andere kommt aus website/.
 *
 * Getestet wird damit unter /app wie auf dem Server. Ein Pfad, der das
 * Unterverzeichnis vergisst, fällt hier auf und nicht erst nach dem Upload.
 *
 * Nur für die Entwicklung - der eingebaute Server wertet keine .htaccess
 * aus, deshalb sperrt dieser Router selbst, was dort gesperrt ist.
 */

$pfad = rawurldecode((string) (parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/'));

// Wie die .htaccess in daten/ und in app/ - und wie Apache bei .htaccess selbst.
if (preg_match('~^/(daten|app/(lib|vendor))(/|$)|/\.~', $pfad) === 1) {
    http_response_code(403);
    exit;
}

if ($pfad === '/app') {
    header('Location: /app/');
    exit;
}

if (str_starts_with($pfad, '/app/')) {
    return false;
}

// Die Startseite.
$wurzel = realpath(dirname(__DIR__) . '/website');
$datei  = realpath($wurzel . $pfad);
if ($datei !== false && is_dir($datei)) {
    $datei = realpath($datei . '/index.html');
}
// Nur, was wirklich unter website/ liegt - kein Weg mit ../ hinaus.
if ($datei === false || !str_starts_with($datei, $wurzel . DIRECTORY_SEPARATOR) || !is_file($datei)) {
    http_response_code(404);
    echo 'Nicht gefunden';
    exit;
}

$typen = [
    'html'  => 'text/html; charset=utf-8',
    'css'   => 'text/css; charset=utf-8',
    'js'    => 'text/javascript; charset=utf-8',
    'png'   => 'image/png',
    'jpg'   => 'image/jpeg',
    'webp'  => 'image/webp',
    'svg'   => 'image/svg+xml',
    'woff2' => 'font/woff2',
    'ico'   => 'image/x-icon',
    'txt'   => 'text/plain; charset=utf-8',
];
header('Content-Type: ' . ($typen[strtolower(pathinfo($datei, PATHINFO_EXTENSION))]
                            ?? 'application/octet-stream'));
readfile($datei);
