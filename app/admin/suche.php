<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

/*
 * Die Suche über alle Schulen - nach Schule, Lehrkraft oder Kind.
 *
 * Sie ersetzt die Kontenliste über alle Schulen. Der häufigste Grund, dort
 * hineinzusehen, war ein Anruf: "Frau Müller kommt nicht mehr hinein." Dann
 * weiss man den Namen, vielleicht die Schule, und will zu genau diesem
 * Konto. Jeder Treffer führt auf die Seite seiner Schule, mit dem Konto
 * aufgeklappt (schule.php#konto…).
 */

admin_require();

$q = trim((string) ($_GET['q'] ?? ''));
$schulen = [];
$konten  = [];

if (mb_strlen($q) >= 2) {
    $muster = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $schulen = qa('SELECT id, name, kuerzel, active FROM schools WHERE name LIKE ? OR kuerzel LIKE ?
                    ORDER BY name LIMIT 20', [$muster, $muster]);
    $konten = qa(
        "SELECT u.id, u.display_name, u.username, u.role, u.color, u.active, u.school_id,
                s.name AS schule,
                (SELECT MIN(cm.class_id) FROM class_members cm WHERE cm.user_id = u.id) AS klasse,
                (SELECT GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ') FROM class_members cm
                   JOIN classes c ON c.id = cm.class_id WHERE cm.user_id = u.id) AS klassen
           FROM users u
           JOIN schools s ON s.id = u.school_id
          WHERE u.display_name LIKE ? OR u.username LIKE ?
          ORDER BY u.role = ?, u.display_name
          LIMIT 60",
        [$muster, $muster, ROLE_STUDENT],
    );
}

/** Wohin ein Konto führt: seine Schule, seine Gruppe, aufgeklappt. */
$zumKonto = static function (array $k): string {
    $gruppe = $k['role'] === ROLE_TEACHER ? 'lk' : ($k['klasse'] === null ? 'ohne' : (string) (int) $k['klasse']);
    return admin_url('schule.php') . '?' . http_build_query(['id' => (int) $k['school_id'], 'r' => 'konten', 'g' => $gruppe])
         . '#konto' . (int) $k['id'];
};

admin_head('Suchen', 'suche.php');
flash_render();
?>

<form method="get" class="suchfeld" role="search">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Schule, Lehrkraft oder Kind"
           aria-label="Suchen" autofocus autocomplete="off" enterkeyhint="search">
    <button class="btn">Suchen</button>
</form>

<?php if ($q === ''): ?>
    <p class="muted">Name oder Benutzername einer Lehrkraft oder eines Kindes, oder Name oder Kürzel einer Schule.</p>
<?php elseif (mb_strlen($q) < 2): ?>
    <p class="muted">Bitte mindestens zwei Zeichen.</p>
<?php elseif ($schulen === [] && $konten === []): ?>
    <p class="muted">Nichts gefunden für &bdquo;<?= h($q) ?>&ldquo;.</p>
<?php else: ?>
    <?php if ($schulen !== []): ?>
        <h2>Schulen</h2>
        <div class="card liste">
            <?php foreach ($schulen as $s): ?>
                <a class="zeile" href="<?= h(admin_url('schule.php') . '?id=' . (int) $s['id']) ?>">
                    <span class="wappen" style="--c:<?= h(schule_farbe((int) $s['id'])) ?>" aria-hidden="true"><?=
                        h(mb_strtoupper(mb_substr((string) $s['name'], 0, 1))) ?></span>
                    <span class="wer"><strong><?= h($s['name']) ?></strong>
                        <span class="tiny muted"><code><?= h((string) $s['kuerzel']) ?></code><?= $s['active'] ? '' : ' &middot; stillgelegt' ?></span></span>
                    <span class="pfeil" aria-hidden="true">&#8250;</span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($konten !== []): ?>
        <h2>Konten<?= count($konten) === 60 ? ' <span class="tiny muted">(die ersten 60)</span>' : '' ?></h2>
        <div class="card liste">
            <?php foreach ($konten as $k): ?>
                <a class="zeile" href="<?= h($zumKonto($k)) ?>">
                    <span class="avatar" style="--c:<?= h($k['color']) ?>" aria-hidden="true"><?=
                        h(mb_strtoupper(mb_substr((string) $k['display_name'], 0, 1))) ?></span>
                    <span class="wer"><strong><?= h($k['display_name']) ?></strong>
                        <span class="tiny muted"><code><?= h($k['username']) ?></code>
                            &middot; <?= $k['role'] === ROLE_TEACHER ? 'Lehrkraft' : h((string) ($k['klassen'] ?? 'ohne Klasse')) ?>
                            &middot; <?= h($k['schule']) ?><?= $k['active'] ? '' : ' &middot; abgeschaltet' ?></span></span>
                    <span class="pfeil" aria-hidden="true">&#8250;</span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php admin_foot(); ?>
