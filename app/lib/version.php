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

/**
 * Alle Dateien, aus denen die Oberfläche besteht - relativ zum Projekt.
 *
 * Auch die Module im Wurzelverzeichnis über glob() und nicht als Liste, und
 * zwar aus genau dem Grund, aus dem es die views schon so machen: Hier
 * standen einmal vier Namen von Hand, vorrat.js und menue.js gehörten nicht
 * dazu. Damit bewegte sich der Versionsstempel nicht, wenn sich der Vorrat
 * änderte - eine auf dem Homescreen liegende App erfuhr von der Änderung
 * also überhaupt nichts und übte wochenlang mit der alten Fassung weiter.
 *
 * Dazu die Dateien des Lehrkraft-Bereichs und des Admins (teacher/*.js,
 * admin/*.css). Die standen nicht in der Liste, und eine Änderung an ihnen
 * bewegte den Stempel nicht - teacher.js?v= blieb derselbe, der Browser
 * behielt die alte Fassung, und das Band "Es gibt eine neue Fassung", das
 * es inzwischen auch dort gibt, hätte von ihr nie erfahren.
 */
function app_assets(): array
{
    $root = dirname(__DIR__);

    $sammeln = static function (string $muster) use ($root): array {
        $treffer = array_map(
            static fn (string $pfad): string => ltrim(
                str_replace('\\', '/', substr($pfad, strlen($root))), '/',
            ),
            glob($root . '/' . $muster) ?: [],
        );
        sort($treffer);
        return $treffer;
    };

    // array_unique, weil sw.js sowohl in der festen Liste steht als auch vom
    // Muster oben getroffen wird - doppelt geholt wird sie sonst auch.
    return array_values(array_unique(array_filter(
        array_merge(['style.css'], $sammeln('*.js'), $sammeln('views/*.js'),
                    $sammeln('teacher/*.js'), $sammeln('admin/*.css')),
        static fn (string $f): bool => is_file($root . '/' . $f),
    )));
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
