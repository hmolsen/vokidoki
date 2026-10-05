<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

/*
 * Die Liste aller Konten über alle Schulen gibt es nicht mehr.
 *
 * Gesucht wurde darin fast immer ein Konto einer bestimmten Schule - eine
 * Lehrkraft, die ihr Passwort vergessen hat. Die Konten stehen jetzt auf der
 * Seite ihrer Schule (schule.php). Alte Lesezeichen führen dorthin, mit
 * Schule, sonst auf die Übersicht.
 */

admin_require();

$schule = (int) ($_GET['school'] ?? 0);
redirect($schule > 0 ? 'schule.php?id=' . $schule . '&r=konten' : 'index.php');
