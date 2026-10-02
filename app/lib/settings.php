<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const SETTING_DEFAULTS = [
    /*
     * Schritt 1, die Fehlerkorrektur: Das Modell bekommt den Text, den die
     * Texterkennung auf dem Gerät gelesen hat, ordnet ihn zu Vokabelpaaren
     * und berichtigt Lesefehler. Der Schlüssel heisst noch "vision_model"
     * aus der Zeit, als hier Fotos hingingen - umbenannt hiesse er auf der
     * laufenden Datenbank anders als alles, was dort schon gespeichert ist.
     */
    'vision_model'         => 'claude-opus-5-5',
    'vision_effort'        => 'medium',
    // Schritt 2, die Lückensätze, schreibt ein eigenes Modell: Einfache
    // Schulsätze aus vorgegebenen Wörtern sind etwas anderes als das
    // Berichtigen von Lesefehlern, und hier bestimmt die Ausgabemenge den Preis.
    'sentence_model'       => 'claude-sonnet-5-5',
    'sentences_per_vocab'  => '3',
    'usd_eur'              => '0.92',
    'monthly_cost_cap_usd' => '10.00',
    'imports_per_hour'     => '20',
    'prices_json'          => '{}',
    'admin_password_hash'  => '',
];

/**
 * Modelle, die im Admin wählbar sind - für beide Schritte.
 *
 * Opus 5 steht nicht mehr darin: Opus 5.5 ist genauer und kostet weniger.
 * Sein Preis bleibt trotzdem in der Preistabelle (save_prices in
 * admin/settings.php behält ihn), weil das Protokoll ihn noch nennt.
 */
const VISION_MODELS = [
    'claude-opus-5-5'   => 'Claude Opus 5.5 - berichtigt am zuverlässigsten',
    'claude-sonnet-5-5' => 'Claude Sonnet 5.5 - halb so teuer',
    'claude-opus-4-8'   => 'Claude Opus 4.8',
    'claude-sonnet-5'   => 'Claude Sonnet 5',
    'claude-haiku-4-5'  => 'Claude Haiku 4.5 - am günstigsten',
];

/**
 * Das gewählte Modell eines Schritts - oder seine Voreinstellung, wenn das
 * gespeicherte nicht mehr wählbar ist. Sonst liefe nach dem Streichen eines
 * Modells still weiter, was im Admin gar nicht mehr zur Wahl steht.
 */
function setting_model(string $key): string
{
    $model = setting($key);
    return array_key_exists($model, VISION_MODELS) ? $model : SETTING_DEFAULTS[$key];
}

const EFFORT_LEVELS = ['low', 'medium', 'high', 'xhigh'];

/** Statischer Cache, über settings_reset_cache() invalidierbar. */
final class SettingsCache
{
    public static ?array $rows = null;
}

function settings_all(): array
{
    if (SettingsCache::$rows === null) {
        $rows = SETTING_DEFAULTS;
        foreach (qa('SELECT k, v FROM settings') as $row) {
            $rows[$row['k']] = $row['v'];
        }
        SettingsCache::$rows = $rows;
    }
    return SettingsCache::$rows;
}

function setting(string $key, ?string $default = null): string
{
    $all = settings_all();
    return $all[$key] ?? $default ?? (SETTING_DEFAULTS[$key] ?? '');
}

function setting_set(string $key, string $value): void
{
    q(
        'INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)',
        [$key, $value],
    );
    settings_reset_cache();
}

function settings_reset_cache(): void
{
    SettingsCache::$rows = null;
}

/** Preistabelle als Array: model => [in, out, cache_read, cache_write] in USD je 1 Mio. Token. */
function price_table(): array
{
    $tbl = json_decode(setting('prices_json', '{}'), true);
    return is_array($tbl) ? $tbl : [];
}
