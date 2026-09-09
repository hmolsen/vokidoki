<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/** Fehlerdarstellung an die Umgebung anpassen und JSON-Header setzen. */
function json_boot(): void
{
    $dev = (bool) cfg('dev', false);
    ini_set('display_errors', $dev ? '1' : '0');
    error_reporting(E_ALL);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
}

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_fail(string $message, int $status = 400, array $extra = []): never
{
    json_out(['ok' => false, 'error' => $message] + $extra, $status);
}

/** JSON-Request-Body als Array. Erzwingt zugleich, dass es kein Formular-POST ist. */
function json_body(): array
{
    $raw  = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_fail('Ungueltiger Request-Body (JSON erwartet).', 400);
    }
    return $data;
}

function body_str(array $b, string $key, int $maxLen = 255): string
{
    $v = $b[$key] ?? '';
    if (!is_string($v)) {
        json_fail("Feld '$key' muss Text sein.");
    }
    $v = trim($v);
    if (mb_strlen($v) > $maxLen) {
        $v = mb_substr($v, 0, $maxLen);
    }
    return $v;
}

function body_int(array $b, string $key): int
{
    $v = $b[$key] ?? null;
    if (!is_int($v) && !(is_string($v) && ctype_digit($v))) {
        json_fail("Feld '$key' muss eine Zahl sein.");
    }
    return (int) $v;
}
