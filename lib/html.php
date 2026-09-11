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
