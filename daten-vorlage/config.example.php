<?php
/**
 * Vokidoki - Konfiguration
 *
 * Kopie dieser Datei als config.php im selben Ordner anlegen und ausfüllen:
 *     cp config.example.php config.php && chmod 640 config.php
 *
 * 640, nicht 600: Bei geteiltem Hosting läuft PHP oft als ein anderer
 * Benutzer als der, dem die Datei gehört (bei ALL-INKL der Konto-Benutzer
 * statt des SSH-Benutzers). Mit 600 kann PHP sie nicht lesen, und jede Seite
 * endet in einem leeren 500er.
 *
 * Dieser Ordner wird einmal als daten/ neben app/ hochgeladen (oder als
 * vokidoki-daten/ oberhalb des Webroots) und danach nie wieder: Updates
 * überschreiben nur app/. config.php steht in .gitignore und ist durch die
 * .htaccess dieses Ordners gesperrt.
 */

return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'vokabeltrainer',
        'user' => '',
        'pass' => '',
    ],

    // Der Anthropic-Key steht bewusst NICHT hier, sondern im Keyvault. Er wird
    // bei jedem KI-Aufruf frisch geholt und nirgends zwischengespeichert -
    // so lässt er sich jederzeit rotieren, ohne diese Datei anzufassen.
    'keyvault_url'   => 'https://cqrity.de/keyvault/api.php',
    'keyvault_token' => '',              // Bearer-Token für den Keyvault
    'keyvault_key'   => 'vokabeltrainer', // Name des Eintrags im Keyvault

    // Normalerweise leer lassen. Nur setzen, wenn die Anfragen über ein
    // Gateway statt direkt an api.anthropic.com gehen sollen.
    'anthropic_base_url' => '',

    // Wird beim ersten Admin-Login als Hash in die settings-Tabelle übernommen.
    // Danach lässt sich das Passwort im Admin ändern; dieser Wert wird dann ignoriert.
    'admin_bootstrap_password' => 'bitte-ändern',

    // Unterverzeichnis, in dem die App läuft - auf vokidoki.de liegt sie
    // unter /app, die Startseite davor im Webroot. Ohne Slash am Ende; leer,
    // wenn die App selbst der Webroot ist.
    'base_path' => '/app',

    // Die vollständige Adresse, unter der die App erreichbar ist - für den
    // QR-Code und die Adresse auf den Zetteln der Kinder. Leer lassen ist in
    // Ordnung: Dann wird sie aus der Anfrage gebaut. Eintragen, wenn die App
    // hinter einem Proxy liegt oder unter mehreren Namen erreichbar ist.
    // Beispiel: 'https://vokidoki.de/app'
    'public_url' => '',

    // true blendet PHP-Fehler im Browser ein - nur lokal verwenden.
    'dev' => false,
];
