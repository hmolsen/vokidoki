<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';

/**
 * Kosten eines Requests in USD.
 * Preise stehen in settings.prices_json als USD je 1 Mio. Token und sind
 * im Admin editierbar, damit die Rechnung bei Preisänderungen stimmt.
 */
function cost_for(string $model, int $in, int $out, int $cacheRead = 0, int $cacheWrite = 0): float
{
    $p = price_table()[$model] ?? null;

    if (!is_array($p)) {
        /*
         * Unbekanntes Modell - und das passiert, weil Anthropic Modelle
         * umbenennt und neue herausbringt.
         *
         * Frueher kam hier 0.00 heraus. Das ist die gefaehrlichste aller
         * Antworten: Ab dem Tag der Umbenennung kostet scheinbar alles
         * nichts, kein Deckel greift mehr, und auffallen wuerde es erst auf
         * der Rechnung. Stattdessen wird der teuerste bekannte Preis
         * angesetzt. Dann ist die Schaetzung zu hoch statt zu niedrig, der
         * Deckel greift zu frueh statt gar nicht, und jemand merkt es.
         */
        $p = price_table_worst();
        error_log(sprintf(
            '[vokabeltrainer] Unbekanntes Modell "%s" - gerechnet wird mit dem '
            . 'teuersten bekannten Preis. Bitte im Admin die Preisliste ergaenzen.',
            $model,
        ));
    }

    return (
        $in         * (float) ($p['in']          ?? 0)
        + $out      * (float) ($p['out']         ?? 0)
        + $cacheRead  * (float) ($p['cache_read']  ?? 0)
        + $cacheWrite * (float) ($p['cache_write'] ?? 0)
    ) / 1_000_000;
}

/**
 * Der jeweils hoechste bekannte Preis je Token-Art.
 *
 * Nicht die Preise eines bestimmten Modells, sondern spaltenweise das
 * Maximum - so ist die Schaetzung fuer ein unbekanntes Modell sicher zu hoch
 * und nicht zufaellig zu niedrig, weil das teuerste Modell gerade bei den
 * Cache-Preisen guenstig ist.
 */
function price_table_worst(): array
{
    $schlimmst = ['in' => 0.0, 'out' => 0.0, 'cache_read' => 0.0, 'cache_write' => 0.0];

    foreach (price_table() as $p) {
        if (!is_array($p)) {
            continue;
        }
        foreach ($schlimmst as $art => $bisher) {
            $schlimmst[$art] = max($bisher, (float) ($p[$art] ?? 0));
        }
    }

    // Eine leere Preisliste darf nicht dazu fuehren, dass wieder alles
    // nichts kostet. Dann lieber ein grob geschaetzter Wert.
    if (array_sum($schlimmst) <= 0) {
        return ['in' => 15.0, 'out' => 75.0, 'cache_read' => 1.5, 'cache_write' => 18.75];
    }

    return $schlimmst;
}

/** Modelle, die im Protokoll vorkommen, aber keinen Preis haben. */
function models_without_price(): array
{
    $bekannt = array_keys(price_table());

    $gesehen = array_column(qa(
        "SELECT DISTINCT model FROM ai_requests
          WHERE created_at >= NOW() - INTERVAL 90 DAY"
    ), 'model');

    return array_values(array_diff($gesehen, $bekannt));
}

function usd_to_eur(float $usd): float
{
    return $usd * (float) setting('usd_eur', '0.92');
}

/**
 * Bisherige Kosten des laufenden Kalendermonats in USD.
 *
 * Ohne Schule die Summe ueber alles - das ist der Blick des Betreibers, der
 * die Rechnung bezahlt. Mit Schule nur deren Anteil.
 */
function cost_this_month(?int $schoolId = null): float
{
    if ($schoolId === null) {
        return (float) (qv(
            "SELECT COALESCE(SUM(cost_usd), 0) FROM ai_requests
              WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
        ) ?? 0);
    }

    return (float) (qv(
        "SELECT COALESCE(SUM(cost_usd), 0) FROM ai_requests
          WHERE school_id = ? AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')",
        [$schoolId],
    ) ?? 0);
}

/** Kosten des laufenden Monats je Schule, teuerste zuerst. */
function cost_this_month_by_school(): array
{
    return qa(
        "SELECT s.id, s.name, s.monthly_cost_cap_usd,
                COALESCE(SUM(a.cost_usd), 0) AS cost_usd,
                COUNT(a.id) AS requests
           FROM schools s
           LEFT JOIN ai_requests a
                  ON a.school_id = s.id
                 AND a.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
          GROUP BY s.id, s.name, s.monthly_cost_cap_usd
          ORDER BY cost_usd DESC, s.name"
    );
}

/**
 * Prüft Monatsbudget und Stundenlimit, bevor ein KI-Request abgesetzt wird.
 * Gibt null zurück, wenn der Aufruf erlaubt ist, sonst eine Klartextmeldung.
 */
function budget_block_reason(int $userId): ?string
{
    /*
     * Zwei Deckel, und beide muessen halten.
     *
     * Der eine gehoert der Schule: Sie soll sich verrechnen koennen, ohne
     * dass es die anderen trifft. Der andere gehoert dem Betreiber und faengt
     * alles zusammen ab - er bekommt die Rechnung, und eine Schule ohne
     * eigenen Deckel darf ihn nicht umgehen.
     */
    $schoolId = (int) (qv('SELECT school_id FROM users WHERE id = ?', [$userId]) ?? 0);

    if ($schoolId > 0) {
        $eigener = qv('SELECT monthly_cost_cap_usd FROM schools WHERE id = ?', [$schoolId]);
        if ($eigener !== null && (float) $eigener > 0
            && cost_this_month($schoolId) >= (float) $eigener) {
            return 'Die Bilderkennung ist für diese Schule gerade gesperrt. '
                 . 'Die Schulleitung kann sie wieder freischalten lassen.';
        }
    }

    $cap = (float) setting('monthly_cost_cap_usd', '10.00');
    if ($cap > 0 && cost_this_month() >= $cap) {
        return 'Die Bilderkennung ist gerade nicht verfügbar. '
             . 'Der Betreiber kann sie im Admin-Bereich wieder freischalten.';
    }

    $perHour = (int) setting('imports_per_hour', '20');
    if ($perHour > 0) {
        $recent = (int) qv(
            'SELECT COUNT(*) FROM ai_requests
              WHERE user_id = ? AND created_at >= (NOW() - INTERVAL 1 HOUR)',
            [$userId],
        );
        if ($recent >= $perHour) {
            return 'Zu viele Foto-Analysen in der letzten Stunde. Bitte später erneut versuchen.';
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

    /*
     * Die Schule wird hier nachgeschlagen und nicht vom Aufrufer erwartet.
     * Jede Aufrufstelle daran zu erinnern hiesse, dass eine es vergisst - und
     * fehlt sie einmal, laesst sich hinterher nicht mehr feststellen, wessen
     * Klasse die Rechnung getrieben hat. Der Weg ueber die Lerneinheit hilft
     * dann nicht: Die kann geloescht sein.
     */
    $schoolId = $row['school_id'] ?? null;
    if ($schoolId === null && ($row['user_id'] ?? null) !== null) {
        $schoolId = qv('SELECT school_id FROM users WHERE id = ?', [(int) $row['user_id']]);
    }

    q(
        'INSERT INTO ai_requests
            (user_id, school_id, user_label, model, purpose, input_tokens, output_tokens,
             cache_read_tokens, cache_write_tokens, image_count, entry_count,
             cost_usd, duration_ms, status, error)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $row['user_id'] ?? null,
            $schoolId === null ? null : (int) $schoolId,
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
