<?php
/**
 * Vokabeltrainer - Konfiguration
 *
 * Kopie dieser Datei als config.php anlegen und ausfuellen:
 *     cp config.example.php config.php && chmod 600 config.php
 * config.php ist per .gitignore und .htaccess geschuetzt und wird nie deployt.
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
    // so laesst er sich jederzeit rotieren, ohne diese Datei anzufassen.
    'keyvault_url'   => 'https://cqrity.de/keyvault/api.php',
    'keyvault_token' => '',              // Bearer-Token fuer den Keyvault
    'keyvault_key'   => 'vokabeltrainer', // Name des Eintrags im Keyvault

    // Normalerweise leer lassen. Nur setzen, wenn die Anfragen ueber ein
    // Gateway statt direkt an api.anthropic.com gehen sollen.
    'anthropic_base_url' => '',

    // Wird beim ersten Admin-Login als Hash in die settings-Tabelle uebernommen.
    // Danach laesst sich das Passwort im Admin aendern; dieser Wert wird dann ignoriert.
    'admin_bootstrap_password' => 'bitte-aendern',

    // Unterverzeichnis, in dem die App laeuft. Leer, wenn sie direkt unter der
    // Domain liegt; sonst z. B. '/vokabeln' (ohne Slash am Ende).
    'base_path' => '',

    // true blendet PHP-Fehler im Browser ein - nur lokal verwenden.
    'dev' => false,
];
