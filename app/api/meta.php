<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/version.php';

/**
 * Auskunft über die aktuelle Fassung der Oberfläche.
 *
 * Die laufende App fragt hier in Abständen nach und vergleicht mit der
 * Fassung, mit der sie selbst gestartet ist. Bewusst ohne require_user():
 * Auch eine App, deren Sitzung abgelaufen ist, soll erfahren, dass es etwas
 * Neues gibt - sonst bliebe gerade die am längsten auf altem Stand.
 *
 * Die API wird nie zwischengespeichert (weder vom Service Worker noch vom
 * Browser), deshalb ist die Antwort hier immer die des Servers.
 */

require_api_request();

switch (action()) {
    case 'version':
        json_out([
            'ok'      => true,
            'version' => app_version(),
        ]);

    default:
        json_fail('Unbekannte Aktion.', 404);
}
