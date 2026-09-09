<?php
declare(strict_types=1);

/**
 * Keyvault-Simulator für die Tests.
 *
 *   php -S 127.0.0.1:8124 tests/fake-keyvault.php
 *
 * Bildet die Schnittstelle des echten Keyvaults nach
 * (GET ?key=<name>&format=raw, Authorization: Bearer <token>) und liefert je
 * nach angefragtem Namen die Antwortarten, gegen die lib/keyvault.php sich
 * wehren muss. Erwartetes Token: "test-token".
 */

// Wird bewusst per "php -S" ausgeliefert, gehört aber auf keinen echten
// Webserver.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

const FAKE_TOKEN = 'test-token';
const FAKE_KEY   = 'sk-ant-api03-FAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKE';

header('Content-Type: text/plain; charset=utf-8');

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$name = (string) ($_GET['key'] ?? '');

if ($auth !== 'Bearer ' . FAKE_TOKEN) {
    http_response_code(401);
    echo "unauthorized\n";
    exit;
}

switch ($name) {
    case 'vokabeltrainer':
        echo FAKE_KEY;
        break;

    case 'leer':               // Eintrag existiert, ist aber leer
        echo '';
        break;

    case 'html':               // Fehlerseite statt rohem Wert
        echo "<!doctype html>\n<html><body>Fehler</body></html>";
        break;

    case 'mit_zeilenumbruch':  // Key mit Whitespace drumherum
        echo "\n  " . FAKE_KEY . "  \n";
        break;

    // Erzwingen die Zugriffs-Zweige, die sonst nur ein falsches Token auslöst.
    case 'abgelehnt':
        http_response_code(401);
        echo "unauthorized\n";
        break;

    case 'verboten':
        http_response_code(403);
        echo "forbidden\n";
        break;

    case 'kaputt':
        http_response_code(500);
        echo "internal error\n";
        break;

    default:
        http_response_code(404);
        echo "not found\n";
}
