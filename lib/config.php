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

/** Baut eine absolute URL innerhalb der App. */
function url(string $path = '/'): string
{
    return base_path() . '/' . ltrim($path, '/');
}
