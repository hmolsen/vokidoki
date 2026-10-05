<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

/*
 * Die Übersicht - die erste Seite des Admins.
 *
 * Hier stand die Kostenseite: vier Kacheln mit Dollar und Token, darunter
 * Tabellen. Was an einem Morgen wirklich interessiert - wartet ein Update,
 * hat ein Kind etwas gemeldet, reichen Budget und Freikontingent -, stand
 * verstreut auf anderen Seiten. Jetzt steht oben, was zu tun ist
 * (admin_zu_tun()), darunter der Monat in zwei Zahlen und die Schulen. Die
 * Kosten im Einzelnen stehen unter kosten.php.
 */

// Abmelden wird von der Leiste jeder Seite hierher gepostet.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['admin_logout'])) {
    csrf_check();
    unset($_SESSION['is_admin']);
    session_regenerate_id(true);
    redirect('index.php');
}

admin_require();

$zuTun = admin_zu_tun();
// Bei offenem Update kann eine Spalte fehlen, die die Zahlen unten brauchen.
$update = $zuTun !== [] && ($zuTun[0]['knopf'] ?? '') === 'run_migrations';

$schulen = $update ? [] : qa(
    "SELECT s.id, s.name, s.kuerzel, s.active, s.max_lehrkraefte,
            (SELECT COUNT(*) FROM users u WHERE u.school_id = s.id AND u.role = ?) AS lehrkraefte,
            (SELECT COUNT(*) FROM users u WHERE u.school_id = s.id AND u.role = ?) AS kinder,
            (SELECT COALESCE(SUM(a.cost_usd), 0) FROM ai_requests a
              WHERE a.school_id = s.id AND a.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')) AS kosten
       FROM schools s
      ORDER BY s.active DESC, s.name",
    [ROLE_TEACHER, ROLE_STUDENT],
);
$meldungen = $update ? [] : meldungen_je_schule();
$kinder    = array_sum(array_column($schulen, 'kinder'));

$budget = (float) setting('monthly_cost_cap_usd', '10.00');
$monat  = cost_this_month();
$frei   = tts_tarif_frei() ? tts_freikontingent() : 0;
$zeichen = tts_zeichen_monat();

$stunde = (int) date('G');
$gruss  = $stunde < 11 ? 'Guten Morgen' : ($stunde < 18 ? 'Guten Tag' : 'Guten Abend');
$monatName = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August',
              'September', 'Oktober', 'November', 'Dezember'][(int) date('n')];

/** Ein Ring für einen Anteil - 0 bis 100. */
$ring = static fn (float $prozent, string $art = ''): string => sprintf(
    '<span class="anteil %s" style="--p:%d" aria-hidden="true"><span>%d&nbsp;%%</span></span>',
    $art, (int) min(100, round($prozent)), (int) round($prozent));

admin_head($gruss, 'index.php');
flash_render();
?>

<p class="unterzeile">
    <?= count($schulen) === 1 ? 'Eine Schule' : count($schulen) . ' Schulen' ?>,
    <?= number_format($kinder, 0, ',', '.') ?> Kinder.
    <?= $zuTun === [] ? 'Nichts wartet auf dich.'
        : (count($zuTun) === 1 ? 'Eine Sache wartet auf dich.' : count($zuTun) . ' Dinge warten auf dich.') ?>
</p>

<h2>Zu tun</h2>
<?php if ($zuTun === []): ?>
    <div class="card allesgut">
        <img src="<?= h(url('/assets/voki-mini.svg')) ?>" alt="" width="56" height="56">
        <div><strong>Alles erledigt.</strong>
            <span class="tiny muted">Keine Meldungen, kein Update, Budget und Aufnahmen im grünen Bereich.</span></div>
    </div>
<?php else: ?>
    <?= admin_zu_tun_html($zuTun) ?>
<?php endif; ?>

<?php if (!$update): ?>
<h2><?= h($monatName) ?></h2>
<div class="monatkarten">
    <a class="card monat" href="<?= h(admin_url('kosten.php')) ?>">
        <?= $budget > 0 ? $ring($monat / $budget * 100, $monat >= $budget * 0.8 ? 'gelb' : '') : '' ?>
        <span>
            <span class="tiny muted">Kosten</span>
            <span class="zahl"><?= admin_euro($monat) ?></span>
            <span class="tiny muted"><?= $budget > 0 ? 'von ' . admin_euro($budget) . ' Budget' : 'ohne Monatsbudget' ?></span>
        </span>
    </a>
    <?php if (tts_aktiv() || $zeichen > 0): ?>
    <a class="card monat" href="<?= h(admin_url('kosten.php') . '#aufnahmen') ?>">
        <?= $frei > 0 ? $ring($zeichen / $frei * 100, $zeichen >= $frei * 0.7 ? 'gelb' : '') : '' ?>
        <span>
            <span class="tiny muted">Aufnahmen</span>
            <span class="zahl"><?= number_format($zeichen, 0, ',', '.') ?></span>
            <span class="tiny muted"><?= $frei > 0
                ? 'von ' . number_format($frei, 0, ',', '.') . ' Zeichen frei'
                : 'Zeichen, ' . admin_euro(tts_kosten($zeichen)) ?></span>
        </span>
    </a>
    <?php endif; ?>
</div>

<h2>Schulen</h2>
<div class="schulkarten">
    <?php foreach ($schulen as $s): ?>
        <?php $n = $meldungen[(int) $s['id']] ?? 0; ?>
        <a class="card schulkarte<?= $s['active'] ? '' : ' aus' ?>" href="<?= h(admin_url('schule.php') . '?id=' . (int) $s['id']) ?>">
            <span class="kopf">
                <span class="wappen" style="--c:<?= h(schule_farbe((int) $s['id'])) ?>" aria-hidden="true"><?=
                    h(mb_strtoupper(mb_substr((string) $s['name'], 0, 1))) ?></span>
                <span class="name">
                    <strong><?= h($s['name']) ?></strong>
                    <span>
                        <?php if ((string) ($s['kuerzel'] ?? '') !== ''): ?><span class="pill code"><?= h($s['kuerzel']) ?></span><?php endif; ?>
                        <?php if (!$s['active']): ?><span class="pill">inaktiv</span><?php endif; ?>
                        <?php if ($n > 0): ?><span class="pill rot">&#128681; <?= $n ?></span><?php endif; ?>
                    </span>
                </span>
                <span class="pfeil" aria-hidden="true">&#8250;</span>
            </span>
            <span class="zahlen">
                <span><b><?= (int) $s['lehrkraefte'] ?><small>/<?= (int) $s['max_lehrkraefte'] ?></small></b>Lehrkräfte</span>
                <span><b><?= number_format((int) $s['kinder'], 0, ',', '.') ?></b>Kinder</span>
                <span><b><?= admin_euro((float) $s['kosten']) ?></b><?= h($monatName) ?></span>
            </span>
        </a>
    <?php endforeach; ?>
    <a class="card schulkarte neu" href="<?= h(admin_url('schools.php')) ?>">
        <span class="wappen" aria-hidden="true">+</span>
        <span><strong>Neue Schule</strong><span class="tiny muted">Name, Kürzel, wie viele Lehrkräfte</span></span>
    </a>
</div>
<?php endif; ?>

<?php admin_foot(); ?>
