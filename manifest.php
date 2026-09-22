<?php
declare(strict_types=1);

/**
 * Dynamisches Web-App-Manifest - pro Kind eines.
 *
 * Der Geräte-Token steckt in der start_url. Dadurch bekommt jedes Kind
 * (a) einen eigenen App-Namen und (b) eine eigene start_url, weshalb iOS die
 * Installationen als getrennte Apps mit getrenntem Storage führt. Erst das
 * macht "zwei Icons auf einem iPhone, jedes dauerhaft beim eigenen Kind
 * eingeloggt" möglich.
 */

require_once __DIR__ . '/lib/auth.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-store');

$token = isset($_GET['t']) && is_string($_GET['t']) ? $_GET['t'] : '';
$user  = $token !== '' ? device_token_user($token) : null;

if ($user === null) {
    // Ohne gültigen Token ein generisches Manifest, das im Login landet.
    echo json_encode([
        'name'             => 'Vokidoki',
        'short_name'       => 'Vokidoki',
        'start_url'        => url('/'),
        'scope'            => url('/'),
        'display'          => 'standalone',
        'background_color' => '#f5f6f8',
        'theme_color'      => '#4f7cff',
        'lang'             => 'de',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

$uid = (int) $user['id'];

echo json_encode([
    'name'             => app_name_for($user),
    'short_name'       => $user['display_name'],
    'description'      => 'Vokidoki von ' . $user['display_name'],
    'start_url'        => url('/?t=' . urlencode($token)),
    'scope'            => url('/'),
    'display'          => 'standalone',
    'orientation'      => 'portrait',
    'background_color' => '#f5f6f8',
    'theme_color'      => $user['color'],
    'lang'             => 'de',
    'icons'            => [
        ['src' => url("/icon.php?u=$uid&s=192"), 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => url("/icon.php?u=$uid&s=512"), 'sizes' => '512x512', 'type' => 'image/png'],
        ['src' => url("/icon.php?u=$uid&s=512&p=1"), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
