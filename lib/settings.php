<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const SETTING_DEFAULTS = [
    'vision_model'         => 'claude-opus-5',
    'vision_effort'        => 'medium',
    'usd_eur'              => '0.92',
    'monthly_cost_cap_usd' => '10.00',
    'imports_per_hour'     => '20',
    'prices_json'          => '{}',
    'admin_password_hash'  => '',
];

/** Modelle, die im Admin für die Bilderkennung wählbar sind. */
const VISION_MODELS = [
    'claude-opus-5'    => 'Claude Opus 5 - beste Genauigkeit (Standard)',
    'claude-opus-4-8'  => 'Claude Opus 4.8',
    'claude-sonnet-5'  => 'Claude Sonnet 5 - günstiger',
    'claude-haiku-4-5' => 'Claude Haiku 4.5 - am günstigsten',
];

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
