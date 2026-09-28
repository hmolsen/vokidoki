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
 *
 * Mit b=verwaltung das Manifest des Lehrkraft-Bereichs: Start auf "Meine
 * Kurse" statt in der Lernansicht, ein eigener Name und ein Symbol mit dem
 * grauen Balken (icon.php?w=1). Vorher hatte der Lehrkraft-Bereich gar kein
 * Manifest - wer dort "Zum Home-Bildschirm" wählte, bekam ein Lesezeichen
 * auf die gerade offene Seite, ohne Anmeldung darin.
 *
 * Der Geltungsbereich ist in beiden Fällen die ganze App: Der Schalter
 * zwischen Verwaltung und Lernansicht soll in der installierten App bleiben
 * und nicht in Safari aufgehen.
 */

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/access.php';

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
        // Voki auf dem Blau der Voreinstellung - ohne Symbol legte Android
        // hier einen grauen Buchstaben auf den Home-Bildschirm.
        'icons'            => [
            ['src' => url('/icon.php?s=192'), 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => url('/icon.php?s=512'), 'sizes' => '512x512', 'type' => 'image/png'],
            ['src' => url('/icon.php?s=512&p=1'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

$uid        = (int) $user['id'];
$verwaltung = ($_GET['b'] ?? '') === 'verwaltung' && user_is_teacher($user);
$w          = $verwaltung ? '&w=1' : '';

echo json_encode([
    // Die Kennung der Installation - ohne sie nähme Chrome die start_url,
    // und die trägt den Token: Jede neue Sitzung wäre eine neue App.
    'id'               => $verwaltung ? url("/teacher/?konto=$uid") : url("/?konto=$uid"),
    'name'             => $verwaltung ? 'Vokidoki Verwaltung' : app_name_for($user),
    'short_name'       => $verwaltung ? 'Verwaltung' : $user['display_name'],
    'description'      => $verwaltung
        ? 'Vokidoki - Verwaltung für ' . $user['display_name']
        : 'Vokidoki von ' . $user['display_name'],
    'start_url'        => $verwaltung
        ? url('/teacher/index.php?t=' . urlencode($token))
        : url('/?t=' . urlencode($token)),
    'scope'            => url('/'),
    'display'          => 'standalone',
    'orientation'      => 'portrait',
    'background_color' => '#f5f6f8',
    'theme_color'      => $user['color'],
    'lang'             => 'de',
    'icons'            => [
        ['src' => url("/icon.php?u=$uid&s=192$w"), 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => url("/icon.php?u=$uid&s=512$w"), 'sizes' => '512x512', 'type' => 'image/png'],
        ['src' => url("/icon.php?u=$uid&s=512&p=1$w"), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
