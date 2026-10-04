<?php
declare(strict_types=1);

/**
 * Weiterleiten und danach im selben Vorgang weiterarbeiten.
 *
 * Für Läufe, die länger dauern als eine Seite warten darf - Sätze und
 * Aufnahmen im Hintergrund. Gebraucht vom Lehrkraft-Bereich
 * (teacher_redirect_and_continue()) und vom Admin ("Fehlendes erzeugen");
 * für JSON-Antworten dasselbe in json_out_and_continue() (lib/json.php).
 * Nach dem Aufruf darf nichts mehr ausgegeben werden.
 */
function redirect_and_continue(string $ziel): void
{
    $stray = ob_get_level() > 0 ? (string) ob_get_clean() : '';
    if (trim($stray) !== '') {
        error_log('[vokabeltrainer] Unerwartete Ausgabe vor der Weiterleitung: '
            . substr(trim($stray), 0, 500));
    }

    ignore_user_abort(true);

    http_response_code(303);
    header('Location: ' . $ziel);
    header('Content-Length: 0');
    // Kein "Connection: close" - der Grund steht in lib/json.php.

    // Die Sitzung freigeben, sonst wartet die weitergeleitete Anfrage
    // desselben Kontos auf das Ende dieses Vorgangs.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}

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

/**
 * Die Stile des Lehrkraft-Bereichs und des Admins, mit Versionsstempel.
 *
 * Standen an sieben Stellen ohne Stempel da. Nach einer Änderung an
 * admin.css lieferte der Browser deshalb tagelang die alte Fassung, und
 * die neue Seite sah aus wie kaputt - bis jemand von Hand neu lud.
 */
function verwaltung_stile_html(): string
{
    require_once __DIR__ . '/version.php';
    $v = '?v=' . app_version();
    return sprintf(
        '<link rel="stylesheet" href="%s">' . "\n" . '<link rel="stylesheet" href="%s">',
        h(url('/style.css' . $v)),
        h(url('/admin/admin.css' . $v)),
    );
}

/**
 * Das Band "Es gibt eine neue Fassung" für Seiten ausserhalb der App.
 *
 * Der Lehrkraft-Bereich hat ein eigenes Symbol auf dem Home-Bildschirm und
 * liegt dort wochenlang im Hintergrund - ohne Band merkte er von keiner
 * Aktualisierung etwas. aktualisieren.js startet von selbst, wenn es so
 * eingebunden ist: mit der Fassung, mit der diese Seite ausgeliefert wurde,
 * und den Dateien, die beim Aktualisieren frisch geholt werden.
 */
function fassung_skript_html(): string
{
    require_once __DIR__ . '/version.php';
    return sprintf(
        '<script type="module" src="%s" data-fassung="%s" data-base="%s" data-assets="%s"></script>',
        h(url('/aktualisieren.js?v=' . app_version())),
        h(app_version()),
        h(base_path()),
        h(json_encode(app_assets(), JSON_UNESCAPED_SLASHES)),
    );
}
