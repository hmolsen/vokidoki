<?php
declare(strict_types=1);

/**
 * Maskierung für HTML-Ausgaben.
 *
 * Eigene Datei, weil admin/_boot.php beim Laden die Schemapflege anstösst.
 * Alles, was nur diesen Helfer braucht - und von den Tests ohne laufenden
 * Admin-Bereich geprüft werden soll - lädt lieber diese Zeile hier.
 */
function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Voki als Favicon - für jeden Seitenkopf dieselben zwei Zeilen.
 *
 * Das SVG für Browser, die es können; das PNG aus icon.php für die übrigen
 * (Safari vor Version 17). Browser halten Favicons zäh fest, deshalb trägt
 * die Adresse das Datum der Zeichnung: Eine neue Fassung ist dann eine neue
 * Adresse und kommt auch an.
 *
 * Braucht url() aus lib/config.php - jede Seite mit einem Kopf hat die ohnehin.
 */
function favicon_html(): string
{
    $svg   = dirname(__DIR__) . '/assets/voki-icon.svg';
    $stand = is_file($svg) ? (string) filemtime($svg) : '0';

    return sprintf(
        '<link rel="icon" type="image/png" sizes="32x32" href="%s">'
        . "\n" . '<link rel="icon" type="image/svg+xml" href="%s">',
        h(url('/icon.php?f=1&s=32&v=' . $stand)),
        h(url('/assets/voki-icon.svg?v=' . $stand)),
    );
}
