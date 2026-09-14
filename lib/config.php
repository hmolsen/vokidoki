<?php
declare(strict_types=1);

/** Lädt config.php einmalig; sucht sie auch eine Ebene oberhalb des Webroots. */
function cfg(?string $key = null, mixed $default = null): mixed
{
    static $config = null;

    if ($config === null) {
        $candidates = [
            dirname(__DIR__) . '/config.php',
            dirname(__DIR__, 2) . '/vokabeltrainer-config.php',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                $config = require $path;
                break;
            }
        }
        if (!is_array($config)) {
            http_response_code(500);
            exit('config.php fehlt. Bitte config.example.php kopieren und ausfüllen.');
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
