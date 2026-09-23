<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/** Fehler beim Abruf aus dem Keyvault - bewusst von API-Fehlern unterscheidbar. */
final class KeyvaultException extends RuntimeException
{
}

/**
 * Holt den Anthropic-API-Key aus dem Keyvault.
 *
 * Bewusst ohne jede Zwischenspeicherung: Der Key wird bei jedem KI-Aufruf neu
 * geholt, damit er jederzeit rotiert werden kann, ohne dass hier etwas
 * nachgezogen werden muss. Der Key wird nirgends protokolliert oder abgelegt.
 *
 * @param $keyName Abweichender Eintrag im Keyvault; ohne Angabe der aus der
 *                 Konfiguration. Wird nur von den Tests genutzt.
 */
function keyvault_anthropic_key(?string $keyName = null): string
{
    $url   = rtrim((string) cfg('keyvault_url', ''), '?&');
    $token = (string) cfg('keyvault_token', '');
    $name  = $keyName ?? (string) cfg('keyvault_key', 'vokabeltrainer');

    if ($url === '' || $token === '') {
        throw new KeyvaultException(
            'Keyvault ist nicht konfiguriert: keyvault_url und keyvault_token in config.php eintragen.'
        );
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url . '?' . http_build_query(['key' => $name, 'format' => 'raw']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => false,
        // TLS-Prüfung bleibt an - über diese Verbindung geht ein Geheimnis.
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body   = curl_exec($ch);
    $errNo  = curl_errno($ch);
    $errStr = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($errNo !== 0 || $body === false) {
        throw new KeyvaultException('Keyvault nicht erreichbar: ' . $errStr);
    }

    if ($status === 401 || $status === 403) {
        throw new KeyvaultException(
            'Keyvault weist den Zugriff ab (HTTP ' . $status . ') - Token in config.php prüfen.'
        );
    }
    if ($status === 404) {
        throw new KeyvaultException('Keyvault kennt den Eintrag "' . $name . '" nicht.');
    }
    if ($status !== 200) {
        throw new KeyvaultException('Keyvault antwortet mit HTTP ' . $status . '.');
    }

    $key = trim((string) $body);

    // Ein knapper Plausibilitätscheck fängt den häufigsten Fall ab: eine
    // HTML-Fehlerseite oder JSON statt des rohen Keys.
    if ($key === '') {
        throw new KeyvaultException('Keyvault liefert einen leeren Wert für "' . $name . '".');
    }
    if (strlen($key) > 512 || preg_match('/[\s<>"]/', $key) === 1) {
        throw new KeyvaultException(
            'Keyvault liefert keinen brauchbaren Key für "' . $name . '" - '
            . 'kommt dort wirklich das rohe Format zurück?'
        );
    }

    return $key;
}

/**
 * Entfernt Schlüsselmaterial aus Text, der protokolliert wird.
 * Fehlermeldungen von Bibliotheken enthalten gelegentlich den verwendeten Key.
 */
function scrub_secrets(string $text): string
{
    $text = (string) preg_replace('/sk-ant-[A-Za-z0-9_\-]+/', 'sk-ant-***', $text);

    $token = (string) cfg('keyvault_token', '');
    if ($token !== '') {
        $text = str_replace($token, '***', $text);
    }

    return $text;
}
