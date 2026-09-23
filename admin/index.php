<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

// Abmelden wird von der Kopfzeile jeder Seite hierher gepostet.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['admin_logout'])) {
    csrf_check();
    unset($_SESSION['is_admin']);
    session_regenerate_id(true);
    redirect('index.php');
}

admin_require();

$rate = (float) setting('usd_eur', '0.92');
$cap  = (float) setting('monthly_cost_cap_usd', '10.00');

$eur = static fn (float $usd): string => number_format($usd * $rate, 2, ',', '.') . ' EUR';
$usd = static fn (float $u): string => '$' . number_format($u, 4, '.', ',');

$month = q1(
    "SELECT COUNT(*) AS n, COALESCE(SUM(cost_usd), 0) AS c,
            COALESCE(SUM(input_tokens), 0) AS ti, COALESCE(SUM(output_tokens), 0) AS to_
       FROM ai_requests
      WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
);
$all = q1('SELECT COUNT(*) AS n, COALESCE(SUM(cost_usd), 0) AS c FROM ai_requests');

$monthCost = (float) $month['c'];
$remaining = max(0.0, $cap - $monthCost);

// Letzte 14 Tage - fehlende Tage mit 0 auffüllen, damit die Achse nicht springt.
$daily = [];
for ($i = 13; $i >= 0; $i--) {
    $daily[date('Y-m-d', strtotime("-$i days"))] = 0.0;
}
foreach (qa(
    "SELECT DATE(created_at) AS d, SUM(cost_usd) AS c
       FROM ai_requests
      WHERE created_at >= (CURDATE() - INTERVAL 13 DAY)
      GROUP BY DATE(created_at)"
) as $row) {
    $daily[$row['d']] = (float) $row['c'];
}
$maxDaily = max(0.000001, max($daily));

/*
 * Nach Konto, nicht nach Kind.
 *
 * Eingelesen wird inzwischen fast nur von Lehrkraeften, und Saetze und
 * Kategorien gehen auf die Rechnung dessen, der fuer den Kurs geradesteht
 * (course_billing_user()). Die Schule steht dabei: "Frau Meier" gibt es an
 * zwei Schulen, und das Limit gilt je Schule.
 */
$perUser = qa(
    "SELECT COALESCE(u.display_name, a.user_label, 'gelöscht') AS name,
            s.name AS school,
            COUNT(*) AS n, COALESCE(SUM(a.cost_usd), 0) AS c,
            COALESCE(SUM(a.image_count), 0) AS imgs,
            COALESCE(SUM(a.entry_count), 0)  AS entries
       FROM ai_requests a
       LEFT JOIN users u   ON u.id = a.user_id
       LEFT JOIN schools s ON s.id = a.school_id
      WHERE a.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
      GROUP BY name, school
      ORDER BY c DESC"
);

// Wofuer eine Anfrage war. Unbekanntes bleibt, wie es gespeichert ist.
const ZWECK = [
    'vocab_ocr'  => 'Einlesen',
    'sentences'  => 'Lückensätze',
    'word_types' => 'Kategorien',
];

$perModel = qa(
    "SELECT model, COUNT(*) AS n, COALESCE(SUM(cost_usd), 0) AS c,
            COALESCE(SUM(input_tokens), 0) AS ti, COALESCE(SUM(output_tokens), 0) AS tokens_out
       FROM ai_requests
      WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
      GROUP BY model
      ORDER BY c DESC"
);

$recent = qa(
    "SELECT a.*, u.display_name
       FROM ai_requests a
       LEFT JOIN users u ON u.id = a.user_id
      ORDER BY a.created_at DESC
      LIMIT 30"
);

admin_head('Kosten', 'index.php');
flash_render();
?>

<div class="stats">
    <div class="stat">
        <div class="k">Diesen Monat</div>
        <div class="v"><?= $eur($monthCost) ?></div>
        <div class="n"><?= (int) $month['n'] ?> Anfragen &middot; <?= $usd($monthCost) ?></div>
    </div>
    <div class="stat">
        <div class="k">Budget übrig</div>
        <div class="v"><?= $cap > 0 ? $eur($remaining) : '&#8734;' ?></div>
        <div class="n"><?= $cap > 0
            ? 'Limit ' . $eur($cap) . ' pro Monat'
            : 'Kein Monatslimit gesetzt' ?></div>
    </div>
    <div class="stat">
        <div class="k">Insgesamt</div>
        <div class="v"><?= $eur((float) $all['c']) ?></div>
        <div class="n"><?= (int) $all['n'] ?> Anfragen seit Beginn</div>
    </div>
    <div class="stat">
        <div class="k">Token diesen Monat</div>
        <div class="v"><?= number_format((int) $month['ti'] + (int) $month['to_'], 0, ',', '.') ?></div>
        <div class="n"><?= number_format((int) $month['ti'], 0, ',', '.') ?> ein,
            <?= number_format((int) $month['to_'], 0, ',', '.') ?> aus</div>
    </div>
</div>

<?php if ($cap > 0 && $remaining <= 0): ?>
    <div class="notice">Das Monatsbudget ist aufgebraucht - die Bilderkennung ist gesperrt,
        bis das Limit unter <a href="<?= h(admin_url('settings.php')) ?>">Einstellungen</a>
        erhöht wird oder der Monat wechselt.</div>
<?php endif; ?>

<h2>Letzte 14 Tage</h2>
<div class="chart">
    <?php foreach ($daily as $day => $cost): ?>
        <div class="col" title="<?= h($day) ?>: <?= $eur($cost) ?>">
            <i style="height: <?= round(($cost / $maxDaily) * 100, 1) ?>%"></i>
            <span><?= h(date('d.m.', strtotime($day))) ?></span>
        </div>
    <?php endforeach; ?>
</div>
<p class="tiny muted">Höchster Tageswert: <?= $eur($maxDaily) ?></p>

<h2>Nach Schule (dieser Monat)</h2>
<?php $proSchule = cost_this_month_by_school(); ?>
<?php if ($proSchule === []): ?>
    <p class="muted">Es gibt noch keine Schule.</p>
<?php else: ?>
<table class="data">
    <tr><th>Schule</th><th class="num">Anfragen</th><th class="num">Kosten</th>
        <th class="num">Eigenes Limit</th><th></th></tr>
    <?php foreach ($proSchule as $s): ?>
        <?php
        $eigen  = $s['monthly_cost_cap_usd'] === null ? null : (float) $s['monthly_cost_cap_usd'];
        $kosten = (float) $s['cost_usd'];
        $voll   = $eigen !== null && $eigen > 0 && $kosten >= $eigen;
        ?>
        <tr<?= $voll ? ' class="dim"' : '' ?>>
            <td><?= h($s['name']) ?></td>
            <td class="num"><?= (int) $s['requests'] ?></td>
            <td class="num"><?= $eur($kosten) ?></td>
            <td class="num">
                <?= $eigen === null ? '<span class="muted">&ndash;</span>' : $usd($eigen) ?>
            </td>
            <td class="tiny">
                <?= $voll ? '<strong>aufgebraucht</strong>' : '' ?>
            </td>
        </tr>
    <?php endforeach; ?>
</table>
<p class="tiny muted">
    Ohne eigenes Limit gilt nur das Monatsbudget des Betreibers. Ein eigenes
    Limit begrenzt zusätzlich, was eine einzelne Schule verbrauchen kann -
    damit eine verrechnete Schule nicht die anderen mit aussperrt.
</p>
<?php endif; ?>

<?php $ohnePreis = models_without_price(); ?>
<?php if ($ohnePreis !== []): ?>
<div class="notice bad">
    <strong>Kein Preis hinterlegt für:</strong> <?= h(implode(', ', $ohnePreis)) ?>.
    Gerechnet wird solange mit dem teuersten bekannten Preis - die Beträge oben
    sind also zu hoch, nicht zu niedrig. Bitte unter Einstellungen ergänzen.
</div>
<?php endif; ?>

<h2>Nach Konto (dieser Monat)</h2>
<?php if ($perUser === []): ?>
    <p class="muted">In diesem Monat gab es noch keine Anfragen.</p>
<?php else: ?>
<table class="data">
    <tr><th>Konto</th><th>Schule</th><th class="num">Anfragen</th><th class="num">Fotos</th>
        <th class="num">Vokabeln</th><th class="num">Kosten</th></tr>
    <?php foreach ($perUser as $r): ?>
        <tr>
            <td><?= h($r['name']) ?></td>
            <td><?= $r['school'] === null ? '<span class="muted">&ndash;</span>' : h($r['school']) ?></td>
            <td class="num"><?= (int) $r['n'] ?></td>
            <td class="num"><?= (int) $r['imgs'] ?></td>
            <td class="num"><?= (int) $r['entries'] ?></td>
            <td class="num"><?= $eur((float) $r['c']) ?></td>
        </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>

<h2>Nach Modell (dieser Monat)</h2>
<?php if ($perModel === []): ?>
    <p class="muted">Noch keine Daten.</p>
<?php else: ?>
<table class="data">
    <tr><th>Modell</th><th class="num">Anfragen</th><th class="num">Token ein</th>
        <th class="num">Token aus</th><th class="num">Kosten</th></tr>
    <?php foreach ($perModel as $r): ?>
        <tr>
            <td><?= h($r['model']) ?></td>
            <td class="num"><?= (int) $r['n'] ?></td>
            <td class="num"><?= number_format((int) $r['ti'], 0, ',', '.') ?></td>
            <td class="num"><?= number_format((int) $r['tokens_out'], 0, ',', '.') ?></td>
            <td class="num"><?= $eur((float) $r['c']) ?></td>
        </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>

<h2>Letzte Anfragen</h2>
<?php if ($recent === []): ?>
    <p class="muted">Noch keine Anfragen protokolliert.</p>
<?php else: ?>
<table class="data">
    <tr><th>Zeitpunkt</th><th>Konto</th><th>Wofür</th><th>Modell</th><th class="num">Fotos</th>
        <th class="num">Vokabeln</th><th class="num">Dauer</th><th class="num">Kosten</th><th>Status</th></tr>
    <?php foreach ($recent as $r): ?>
        <tr class="<?= $r['status'] === 'ok' ? '' : 'dim' ?>">
            <td><?= h(date('d.m.Y H:i', strtotime((string) $r['created_at']))) ?></td>
            <td><?= h($r['display_name'] ?? $r['user_label']) ?></td>
            <td><?= h(ZWECK[$r['purpose']] ?? (string) $r['purpose']) ?></td>
            <td><?= h($r['model']) ?></td>
            <td class="num"><?= (int) $r['image_count'] ?></td>
            <td class="num"><?= (int) $r['entry_count'] ?></td>
            <td class="num"><?= $r['duration_ms'] > 0 ? round($r['duration_ms'] / 1000, 1) . ' s' : '-' ?></td>
            <td class="num"><?= $eur((float) $r['cost_usd']) ?></td>
            <td title="<?= h((string) ($r['error'] ?? '')) ?>">
                <?= $r['status'] === 'ok' ? 'ok' : h($r['status']) ?>
            </td>
        </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>

<?php admin_foot(); ?>
