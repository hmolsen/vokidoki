<?php
declare(strict_types=1);

/**
 * Azure-Speech-Simulator für die Tests.
 *
 *   php -S 127.0.0.1:8126 tests/fake-azure-tts.php
 *
 * Nimmt dieselbe Anfrage an wie Azure (POST /cognitiveservices/v1 mit SSML,
 * Ocp-Apim-Subscription-Key und X-Microsoft-OutputFormat) und antwortet mit
 * einer kurzen, gültigen MP3: lauter stille MPEG-Rahmen. Gültig muss sie
 * sein, weil die Browser-Tests sie wirklich laden.
 *
 * Ein Satz, der FEHLER-TTS enthält, bekommt einen Serverfehler - für den
 * Weg, auf dem eine Aufnahme ausbleibt.
 */

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

/** Steht im Fake-Keyvault unter "vokabeltrainer-tts". */
const FAKE_TTS_KEY = 'fake-azure-tts-key-0123456789abcdef';

$pfad = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || $pfad !== '/cognitiveservices/v1') {
    http_response_code(404);
    exit;
}

if (($_SERVER['HTTP_OCP_APIM_SUBSCRIPTION_KEY'] ?? '') !== FAKE_TTS_KEY) {
    http_response_code(401);
    exit;
}

$ssml = (string) file_get_contents('php://input');
if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/ssml+xml')
    || ($_SERVER['HTTP_X_MICROSOFT_OUTPUTFORMAT'] ?? '') === ''
    || preg_match("~<voice name='([A-Za-z]{2}-[A-Za-z]{2}-\\w+Neural)'>(.*)</voice>~s", $ssml, $m) !== 1) {
    http_response_code(400);
    exit;
}

if (str_contains($m[2], 'FEHLER-TTS')) {
    http_response_code(500);
    exit;
}

/*
 * Die Bremse von Azure (HTTP 429, mit Retry-After): IMMER-429 bekommt sie
 * jedesmal, RATE-429 nur beim ersten Mal je Text - gemerkt in einer Datei,
 * weil jede Anfrage hier ein frischer Lauf ist.
 */
if (str_contains($m[2], 'IMMER-429')) {
    http_response_code(429);
    header('Retry-After: 1');
    exit;
}
if (str_contains($m[2], 'RATE-429')) {
    $merker = sys_get_temp_dir() . '/fake-tts-' . md5($m[2]);
    if (!is_file($merker)) {
        touch($merker);
        http_response_code(429);
        header('Retry-After: 1');
        exit;
    }
    unlink($merker);
}

/*
 * Stille als MP3: MPEG-1 Layer III, 128 kbit/s, 44,1 kHz, mono. Ein Rahmen
 * ist 417 Bytes lang - Kopf, Seiteninformation aus Nullen (also kein Ton),
 * Rest Nullen. Achtunddreissig Rahmen sind knapp eine Sekunde.
 */
$rahmen = "\xFF\xFB\x90\xC0" . str_repeat("\0", 417 - 4);
header('Content-Type: audio/mpeg');
echo str_repeat($rahmen, 38);
