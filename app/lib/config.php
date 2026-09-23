<?php
declare(strict_types=1);

/**
 * Der Ordner mit allem, was ein Update überleben muss: config.php und
 * storage/ (Sitzungen, Fehlerprotokoll, Symbole).
 *
 * Die Anwendung liegt in app/ und wird bei jedem Update als Ganzes
 * überschrieben. Stünde config.php darin, wäre sie nach dem ersten Upload
 * weg - oder man müsste bei jedem Upload daran denken, sie auszulassen, und
 * einmal vergisst man es. Deshalb liegt beides daneben:
 *
 *   ../../vokidoki-daten/   oberhalb des Webroots - am besten, wenn der
 *                           Hoster das zulässt: Der Webserver kommt gar
 *                           nicht erst heran.
 *   ../daten/               neben app/ im Webroot - gesperrt durch die
 *                           .htaccess darin und die im Webroot.
 *
 * Gesucht wird in dieser Reihenfolge; es gilt der erste Ordner, in dem eine
 * config.php liegt.
 */
function daten_dir(): string
{
    static $dir = null;

    if ($dir === null) {
        $app = dirname(__DIR__);
        foreach ([dirname($app, 2) . '/vokidoki-daten', dirname($app) . '/daten'] as $kandidat) {
            if (is_file($kandidat . '/config.php')) {
                $dir = $kandidat;
                break;
            }
        }
        if ($dir === null) {
            http_response_code(500);
            exit('config.php fehlt. Den Ordner daten-vorlage/ als daten/ neben app/ '
                 . 'hochladen und darin config.example.php als config.php ausfüllen.');
        }
    }

    return $dir;
}

/** Ein Pfad unter daten/storage/ - für alles, was zur Laufzeit entsteht. */
function storage_path(string $pfad = ''): string
{
    return daten_dir() . '/storage' . ($pfad === '' ? '' : '/' . ltrim($pfad, '/'));
}

/** Lädt config.php einmalig aus daten_dir(). */
function cfg(?string $key = null, mixed $default = null): mixed
{
    static $config = null;

    if ($config === null) {
        $config = require daten_dir() . '/config.php';
        if (!is_array($config)) {
            http_response_code(500);
            exit('config.php liefert keine Konfiguration. Vorlage: config.example.php.');
        }
    }

    if ($key === null) {
        return $config;
    }
    return $config[$key] ?? $default;
}

/** Basis-URL-Pfad der App, z. B. '' oder '/vokabeln'. */
function base_path(): string
{
    return rtrim((string) cfg('base_path', ''), '/');
}

/**
 * Baut einen Pfad innerhalb der App - ohne Schema und Host.
 *
 * Das genügt überall, wo der Browser ohnehin schon auf der Seite ist: Links,
 * Formulare, Weiterleitungen. Für etwas, das die Anwendung verlässt - ein
 * QR-Code, ein Ausdruck, eine Mail - reicht es nicht, dafür gibt es
 * public_url().
 */
function url(string $path = '/'): string
{
    return base_path() . '/' . ltrim($path, '/');
}

/**
 * Die vollständige Adresse der App, mit Schema und Host.
 *
 * Gebraucht für alles, was auf Papier landet oder abfotografiert wird. Ein
 * QR-Code mit "/vokabeltrainer/" darin ist kein Link, sondern eine
 * Zeichenkette, und ein Kind, das "/vokabeltrainer/" in die Adresszeile
 * tippt, landet nirgends.
 *
 * Vorrang hat der Eintrag public_url aus der Konfiguration. Ohne ihn wird
 * die Adresse aus der laufenden Anfrage gebaut - das stimmt fast immer, hängt
 * aber am Host-Header, den der Aufrufer setzt. Für einen Ausdruck ist das
 * hinnehmbar; wer es genau haben will, trägt public_url ein.
 */
function public_url(string $path = '/'): string
{
    $fest = trim((string) cfg('public_url', ''));
    if ($fest !== '') {
        return rtrim($fest, '/') . '/' . ltrim($path, '/');
    }

    $schema = 'http';
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== '') {
        $schema = strtolower(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]);
    } elseif (($_SERVER['HTTPS'] ?? 'off') !== 'off' && ($_SERVER['HTTPS'] ?? '') !== '') {
        $schema = 'https';
    } elseif ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        $schema = 'https';
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
    // Nur, was in einem Host stehen darf - der Wert kommt vom Aufrufer.
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host) ?: 'localhost';

    return $schema . '://' . $host . url($path);
}
