<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Fehlerbehandlung.
 *
 * Ohne das hier endet jeder unerwartete Fehler in einer leeren Seite mit
 * HTTP 500 - im Browser nicht von einem Serverausfall zu unterscheiden und
 * ohne jeden Anhaltspunkt. Stattdessen bekommt jeder Fehler eine Kennung,
 * wird protokolliert und als verständliche Meldung ausgegeben.
 */

/** Vollständiger Pfad der Protokolldatei. */
function error_log_path(): string
{
    return storage_path('error.log');
}

/**
 * @param $json true für API-Endpunkte (Antwort als JSON statt HTML).
 */
function boot_error_handling(bool $json = false): void
{
    $dev = (bool) cfg('dev', false);

    error_reporting(E_ALL);
    // In der API niemals Meldungen ausgeben: Eine Warnung vor dem JSON macht
    // die Antwort unlesbar. Dort wird ausschließlich protokolliert; was
    // wirklich schiefging, steht im JSON-Feld "detail".
    ini_set('display_errors', ($dev && !$json) ? '1' : '0');
    ini_set('log_errors', '1');

    // Ins Anwendungsverzeichnis protokollieren, damit die Datei auffindbar
    // ist - bei geteiltem Hosting weiß man selten, wo das Serverlog liegt.
    $dir = dirname(error_log_path());
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        ini_set('error_log', error_log_path());
    }

    set_exception_handler(static function (Throwable $e) use ($dev, $json): void {
        report_fatal(
            sprintf('%s: %s', $e::class, $e->getMessage()),
            $e->getFile(),
            $e->getLine(),
            $dev,
            $json,
            $e->getTraceAsString(),
        );
    });

    // Fängt auch das ab, was keine Ausnahme ist - etwa erschöpfter Speicher.
    register_shutdown_function(static function () use ($dev, $json): void {
        $err = error_get_last();
        if ($err === null) {
            return;
        }
        if (!in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        report_fatal($err['message'], $err['file'], (int) $err['line'], $dev, $json, null);
    });
}

/** Protokolliert den Fehler und gibt eine brauchbare Antwort aus. */
function report_fatal(
    string $message,
    string $file,
    int $line,
    bool $dev,
    bool $json,
    ?string $trace,
): void {
    $ref = bin2hex(random_bytes(4));

    // Schlüsselmaterial nie ins Protokoll - die Meldung kann es enthalten.
    if (function_exists('scrub_secrets')) {
        $message = scrub_secrets($message);
    }

    error_log(sprintf('[%s] %s in %s:%d', $ref, $message, $file, $line));
    if ($trace !== null) {
        error_log(sprintf('[%s] %s', $ref, $trace));
    }

    // Angefangene Ausgabe verwerfen, sonst steht die Fehlermeldung hinter
    // einer halben Seite - und bei JSON wäre die Antwort unbrauchbar.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Cache-Control: no-store');
        header('Content-Type: ' . ($json ? 'application/json; charset=utf-8' : 'text/html; charset=utf-8'));
    }

    $detail = $dev ? sprintf('%s in %s:%d', $message, $file, $line) : null;

    if ($json) {
        echo json_encode([
            'ok'        => false,
            'error'     => 'Unerwarteter Serverfehler. Bitte der Lehrkraft Bescheid sagen.',
            'reference' => $ref,
            'detail'    => $detail,
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $e   = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $log = $e(error_log_path());
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Fehler</title><style>'
       . 'body{font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;'
       . 'margin:0;padding:14vh 20px;background:#f4f5f8;color:#171a21}'
       . 'div{max-width:520px;margin:0 auto;background:#fff;border:1px solid #dde0e8;'
       . 'border-radius:18px;padding:26px}h1{font-size:1.3rem;margin:0 0 10px}'
       . 'code{background:#eceef3;padding:2px 6px;border-radius:5px;font-size:.9em}'
       . 'pre{background:#eceef3;padding:12px;border-radius:10px;overflow-x:auto;font-size:.82rem}'
       . 'p{margin:0 0 10px}</style></head><body><div>'
       . '<h1>Da ist etwas schiefgelaufen</h1>'
       . '<p>Der Fehler wurde protokolliert. Kennung: <code>' . $e($ref) . '</code></p>'
       . ($detail !== null
            ? '<pre>' . $e($detail) . '</pre>'
            : '<p>Details stehen in <code>' . $log . '</code>.</p>')
       . '</div></body></html>';
}
