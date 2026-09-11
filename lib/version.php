<?php
declare(strict_types=1);

/**
 * Versionsstempel und Dateiliste der Oberfläche.
 *
 * Aus dem jüngsten Änderungsdatum der Oberflächendateien statt von Hand
 * gepflegt - eine feste Zahl vergisst man beim Hochladen, und dann liefern
 * Browser und Service Worker ewig die alte Fassung an die installierte App.
 *
 * Bewusst über alle views/*.js statt über eine gepflegte Liste: Die Liste war
 * einmal unvollständig, und eine Ansicht, die nicht darin stand, erreichte
 * eine auf dem Homescreen liegende App überhaupt nicht.
 *
 * Eigene Datei, weil zwei sehr verschiedene Stellen dasselbe brauchen: die
 * Hülle beim Ausliefern und api/meta.php, wenn die laufende App nachfragt,
 * ob es etwas Neues gibt.
 */

/** Alle Dateien, aus denen die Oberfläche besteht - relativ zum Projekt. */
function app_assets(): array
{
    $root  = dirname(__DIR__);
    $views = array_map(
        static fn (string $pfad): string => 'views/' . basename($pfad),
        glob($root . '/views/*.js') ?: [],
    );
    sort($views);

    return array_values(array_filter(
        array_merge(['app.js', 'core.js', 'style.css', 'sw.js'], $views),
        static fn (string $f): bool => is_file($root . '/' . $f),
    ));
}

/** Jüngstes Änderungsdatum dieser Dateien - der Versionsstempel. */
function app_version(): string
{
    $root   = dirname(__DIR__);
    $zeiten = array_map(
        static fn (string $f): int => (int) filemtime($root . '/' . $f),
        app_assets(),
    );

    return (string) ($zeiten === [] ? 0 : max($zeiten));
}
