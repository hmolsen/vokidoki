<?php
declare(strict_types=1);

/**
 * App-Shell.
 *
 * Bewusst PHP statt statischem HTML: iOS liest das Web-App-Manifest im Moment
 * des Antippens von "Zum Home-Bildschirm" aus dem ausgelieferten HTML. Ein per
 * JavaScript nachgeschobener <link rel="manifest"> ist dafuer unzuverlaessig,
 * deshalb muss der personalisierte Manifest-Link bereits im ersten Response
 * stehen.
 */

require_once __DIR__ . '/lib/auth.php';

$appVersion = '1';

// Start aus dem Homescreen-Icon: start_url traegt den Geraete-Token. Er wird
// gegen eine Session in *diesem* Container getauscht und danach aus der URL
// entfernt, damit er nicht in Verlauf oder Screenshots landet.
$token = isset($_GET['t']) && is_string($_GET['t']) ? $_GET['t'] : '';
if ($token !== '') {
    $tokenUser = device_token_user($token);
    if ($tokenUser !== null) {
        login_user((int) $tokenUser['id']);
        $_SESSION['device_token'] = $token;
    }
    header('Location: ' . url('/'), true, 302);
    exit;
}

$user = current_user();

// Der Token fuer den Manifest-Link: der Token dieser Installation, sonst ein
// frisch erzeugter fuer den naechsten "Zum Home-Bildschirm"-Vorgang.
$manifestToken = null;
if ($user !== null) {
    session_boot();
    $known = $_SESSION['device_token'] ?? null;
    if (is_string($known) && device_token_user($known) !== null) {
        $manifestToken = $known;
    } else {
        $manifestToken = device_token_create(
            (int) $user['id'],
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unbekanntes Geraet'), 0, 128),
        );
        $_SESSION['device_token'] = $manifestToken;
    }
}

$appName = $user !== null ? app_name_for($user) : 'Vokabeln';
$color   = $user !== null ? $user['color'] : '#4f7cff';
$e       = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $e($appName) ?></title>

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
</head>
<body style="--accent: <?= $e($color) ?>">

<div id="app" class="app"></div>

<script>
window.VT = {
    base: <?= json_encode(base_path(), JSON_UNESCAPED_SLASHES) ?>,
    user: <?= $user === null ? 'null' : json_encode([
        'id'       => (int) $user['id'],
        'name'     => $user['display_name'],
        'color'    => $user['color'],
        'appName'  => $appName,
    ], JSON_UNESCAPED_UNICODE) ?>,
    standalone: false,
    version: <?= json_encode($appVersion) ?>
};
window.VT.standalone = window.navigator.standalone === true
    || window.matchMedia('(display-mode: standalone)').matches;
</script>
<script type="module" src="<?= $e(url('/app.js?v=' . $appVersion)) ?>"></script>
</body>
</html>
