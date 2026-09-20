<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/errors.php';

/** Fehlerdarstellung an die Umgebung anpassen und JSON-Header setzen. */
function json_boot(): void
{
    boot_error_handling(json: true);

    // Alles auffangen, was vor der eigentlichen Antwort ausgegeben wird.
    // Ein einziges Zeichen davor - eine PHP-Meldung, ein Leerzeichen hinter
    // einem schließenden Tag - macht die JSON-Antwort für den Browser
    // unlesbar, und das Kind sieht nur "Der Server hat unerwartet geantwortet".
    ob_start();

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
}

function json_out(array $data, int $status = 200): never
{
    // Streuausgabe verwerfen, aber protokollieren - sie weist auf ein
    // Problem hin, das sonst unbemerkt bliebe.
    $stray = ob_get_level() > 0 ? (string) ob_get_clean() : '';
    if (trim($stray) !== '') {
        error_log('[vokabeltrainer] Unerwartete Ausgabe vor der JSON-Antwort: '
            . substr(trim($stray), 0, 500));
    }

    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Schickt die Antwort ab und laeuft danach weiter.
 *
 * So bekommt das Kind sofort Bescheid, waehrend im selben Vorgang noch die
 * Lueckensaetze entstehen - auf geteiltem Hosting gibt es keine Warteschlange
 * und keinen Dienst, den man dafuer anwerfen koennte.
 *
 * Nach diesem Aufruf darf nichts mehr ausgegeben werden; der Aufrufer soll
 * seine Arbeit erledigen und dann beenden.
 */
function json_out_and_continue(array $data): void
{
    $stray = ob_get_level() > 0 ? (string) ob_get_clean() : '';
    if (trim($stray) !== '') {
        error_log('[vokabeltrainer] Unerwartete Ausgabe vor der JSON-Antwort: '
            . substr(trim($stray), 0, 500));
    }

    // Der Browser darf die Verbindung schliessen, ohne den Auftrag zu killen.
    ignore_user_abort(true);

    $body = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($body));
    /*
     * Kein "Connection: close".
     *
     * Es stand hier, damit der Browser merkt, wo die Antwort aufhoert,
     * wenn der Vorgang danach noch weiterarbeitet. Dafuer ist aber die
     * Laengenangabe darueber zustaendig, und die steht da.
     *
     * Was es stattdessen tat: Die Seite laeuft ueber HTTP/1.1, und dort
     * heisst der Header "wirf diese Verbindung danach weg". Bei jedem
     * einzelnen Aufruf. Beim Ueben sind das viele kurz hintereinander -
     * Frage holen, Antwort schicken, naechste Frage -, und jede einzelne
     * brauchte damit einen neuen TCP- und TLS-Handschlag. Das ist nicht
     * nur langsam: Es haelt den Verbindungsvorrat des Browsers dauernd in
     * Bewegung, und genau dort sitzt ein altbekanntes Wettrennen - der
     * Browser schickt eine Anfrage auf eine Verbindung, die der Server
     * gerade zumacht. Die faellt dann ohne Status um, und in der App las
     * sich das als "Keine Verbindung. Bist du online?", mitten im besten
     * Netz.
     */
    echo $body;

    // Die Sitzung freigeben, sonst warten alle weiteren Anfragen desselben
    // Kindes auf das Ende dieses Vorgangs.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }

    // Ohne FPM: Puffer leeren und hoffen, dass der Server durchlaesst.
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
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
        json_fail('Ungültiger Request-Body (JSON erwartet).', 400);
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
