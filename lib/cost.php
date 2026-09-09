<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';

/**
 * Kosten eines Requests in USD.
 * Preise stehen in settings.prices_json als USD je 1 Mio. Token und sind
 * im Admin editierbar, damit die Rechnung bei Preisaenderungen stimmt.
 */
function cost_for(string $model, int $in, int $out, int $cacheRead = 0, int $cacheWrite = 0): float
{
    $p = price_table()[$model] ?? null;
    if (!is_array($p)) {
        return 0.0;
    }

    return (
        $in         * (float) ($p['in']          ?? 0)
        + $out      * (float) ($p['out']         ?? 0)
        + $cacheRead  * (float) ($p['cache_read']  ?? 0)
        + $cacheWrite * (float) ($p['cache_write'] ?? 0)
    ) / 1_000_000;
}

function usd_to_eur(float $usd): float
{
    return $usd * (float) setting('usd_eur', '0.92');
}

/** Bisherige Kosten des laufenden Kalendermonats in USD. */
function cost_this_month(): float
{
    return (float) (qv(
        "SELECT COALESCE(SUM(cost_usd), 0) FROM ai_requests
          WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
    ) ?? 0);
}

/**
 * Prueft Monatsbudget und Stundenlimit, bevor ein KI-Request abgesetzt wird.
 * Gibt null zurueck, wenn der Aufruf erlaubt ist, sonst eine Klartextmeldung.
 */
function budget_block_reason(int $userId): ?string
{
    $cap = (float) setting('monthly_cost_cap_usd', '10.00');
    if ($cap > 0 && cost_this_month() >= $cap) {
        return 'Das Monatsbudget fuer die Bilderkennung ist aufgebraucht. '
             . 'Papa kann es im Admin-Bereich erhoehen.';
    }

    $perHour = (int) setting('imports_per_hour', '20');
    if ($perHour > 0) {
        $recent = (int) qv(
            'SELECT COUNT(*) FROM ai_requests
              WHERE user_id = ? AND created_at >= (NOW() - INTERVAL 1 HOUR)',
            [$userId],
        );
        if ($recent >= $perHour) {
            return 'Zu viele Foto-Analysen in der letzten Stunde. Bitte spaeter erneut versuchen.';
        }
    }

    return null;
}

/** Schreibt einen Eintrag ins Kostenprotokoll und liefert die Kosten in USD. */
function ai_log(array $row): float
{
    $cost = cost_for(
        (string) $row['model'],
        (int) ($row['input_tokens'] ?? 0),
        (int) ($row['output_tokens'] ?? 0),
        (int) ($row['cache_read_tokens'] ?? 0),
        (int) ($row['cache_write_tokens'] ?? 0),
    );

    q(
        'INSERT INTO ai_requests
            (user_id, user_label, model, purpose, input_tokens, output_tokens,
             cache_read_tokens, cache_write_tokens, image_count, entry_count,
             cost_usd, duration_ms, status, error)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $row['user_id'] ?? null,
            (string) ($row['user_label'] ?? ''),
            (string) $row['model'],
            (string) ($row['purpose'] ?? 'vocab_ocr'),
            (int) ($row['input_tokens'] ?? 0),
            (int) ($row['output_tokens'] ?? 0),
            (int) ($row['cache_read_tokens'] ?? 0),
            (int) ($row['cache_write_tokens'] ?? 0),
            (int) ($row['image_count'] ?? 0),
            (int) ($row['entry_count'] ?? 0),
            $cost,
            (int) ($row['duration_ms'] ?? 0),
            (string) ($row['status'] ?? 'ok'),
            $row['error'] ?? null,
        ],
    );

    return $cost;
}
