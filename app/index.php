<?php
declare(strict_types=1);

/**
 * App-Shell.
 *
 * Bewusst PHP statt statischem HTML: iOS liest das Web-App-Manifest im Moment
 * des Antippens von "Zum Home-Bildschirm" aus dem ausgelieferten HTML. Ein per
 * JavaScript nachgeschobener <link rel="manifest"> ist dafür unzuverlässig,
 * deshalb muss der personalisierte Manifest-Link bereits im ersten Response
 * stehen.
 */

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/html.php';
require_once __DIR__ . '/lib/handoff.php';
require_once __DIR__ . '/lib/access.php';
require_once __DIR__ . '/lib/errors.php';
require_once __DIR__ . '/lib/version.php';
require_once __DIR__ . '/lib/thema.php';
require_once __DIR__ . '/lib/streak.php';

boot_error_handling();

// Versionsstempel und Dateiliste stehen in lib/version.php - api/meta.php
// braucht beides ebenso, wenn die laufende App nachfragt.
$appVersion = app_version();

// Start aus dem Homescreen-Icon: start_url trägt den Geräte-Token. Er wird
// gegen eine Session in *diesem* Container getauscht und danach aus der URL
// entfernt, damit er nicht in Verlauf oder Screenshots landet.
$token = isset($_GET['t']) && is_string($_GET['t']) ? $_GET['t'] : '';
if ($token !== '') {
    device_token_einloesen($token);
    header('Location: ' . url('/'), true, 302);
    exit;
}

/*
 * Der Sprung vom Rechner ans Telefon: eine Einmal-Marke aus einem QR-Code.
 *
 * Anders als der Geraete-Token oben ist sie keine Installation, sondern ein
 * einziger Uebergang - sie gilt Minuten, laesst sich genau einmal einloesen
 * und bringt gleich an die Stelle mit, an der weitergearbeitet wird. Das
 * Ziel kommt aus der Marke, nicht aus der Adresse: Sonst schickte ein
 * praeparierter Link jemanden irgendwohin.
 *
 * Weitergeleitet wird in jedem Fall, auch wenn die Marke nicht mehr gilt -
 * dann landet man auf der Anmeldung, und die Adresse ist die Marke los.
 */
$sprung = isset($_GET['h']) && is_string($_GET['h']) ? $_GET['h'] : '';
if ($sprung !== '') {
    $ziel = url('/');
    $eingeloest = handoff_redeem($sprung);
    if ($eingeloest !== null) {
        login_user((int) $eingeloest['user']['id']);
        $aus = handoff_target_url($eingeloest['target']);
        if ($aus !== null) {
            $ziel = $aus;
        }
    }
    header('Location: ' . $ziel, true, 302);
    exit;
}

$user = current_user();

// Der Token für den Manifest-Link - siehe install_token().
$manifestToken = $user !== null ? install_token($user) : null;

$appName = $user !== null ? app_name_for($user) : 'Vokidoki';
$color   = $user !== null ? $user['color'] : '#4f7cff';
$e       = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// Die Hülle selbst nie vorhalten: Sie trägt den Namen des Kindes und die
// Versionsstempel der Dateien - beides muss immer frisch sein.
header('Cache-Control: no-store, must-revalidate');
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $e($appName) ?></title>
<?= favicon_html() ?>

<?php if ($manifestToken !== null): ?>
<link rel="manifest" href="<?= $e(url('/manifest.php?t=' . urlencode($manifestToken))) ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?= $e(url('/icon.php?u=' . (int) $user['id'] . '&s=180')) ?>">
<?php endif; ?>

<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= $e($appName) ?>">
<meta name="theme-color" content="<?= $e($color) ?>">
<meta name="robots" content="noindex, nofollow">

<link rel="stylesheet" href="<?= $e(url('/style.css?v=' . $appVersion)) ?>">
<?php
/*
 * Die Farbwahl vor dem ersten Bild. Als Modul ginge das nicht - Module
 * laufen nach dem Aufbau, und dann blitzt eine halbe Sekunde lang die
 * helle Seite auf, bevor sie dunkel wird.
 */
?>
<?= thema_kopf_skript() ?>
</head>
<body style="--accent: <?= $e($color) ?>">

<div id="app" class="app"></div>

<script>
window.VT = {
    base: <?= json_encode(base_path(), JSON_UNESCAPED_SLASHES) ?>,
    // Dieselben Felder wie bei api/auth.php - siehe app_user_data().
    user: <?= $user === null ? 'null' : json_encode(app_user_data($user), JSON_UNESCAPED_UNICODE) ?>,
    /*
     * Die Serie gleich mit der Huelle, nicht erst mit dem Buendel.
     *
     * Das Abzeichen steht in der Leiste jeder Seite. Kaeme es erst mit dem
     * ersten Abruf, blitzte auf jeder Seite kurz eine Null auf und spraenge
     * dann auf die richtige Zahl - ausgerechnet bei der Zahl, auf die ein
     * Kind stolz ist.
     */
    serie: <?= $user === null ? 'null' : json_encode(streak_stand((int) $user['id'], false)) ?>,
    standalone: false,
    version: <?= json_encode($appVersion) ?>,
    // Damit "Aktualisieren" jede Datei frisch holen kann, statt zu hoffen,
    // dass Browser und Service Worker von selbst darauf kommen.
    assets: <?= json_encode(app_assets(), JSON_UNESCAPED_SLASHES) ?>
};
window.VT.standalone = window.navigator.standalone === true
    || window.matchMedia('(display-mode: standalone)').matches;
</script>
<script type="module" src="<?= $e(url('/app.js?v=' . $appVersion)) ?>"></script>
</body>
</html>
