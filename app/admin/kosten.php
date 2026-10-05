<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

/*
 * Die Kosten im Einzelnen. Bis zur Übersicht war das die Startseite des
 * Admins; die Summen des Monats stehen jetzt dort, hier der Rest.
 */

admin_require();

$rate = (float) setting('usd_eur', '0.92');
$cap  = (float) setting('monthly_cost_cap_usd', '10.00');

$eur = static fn (float $usd): string => number_format($usd * $rate, 2, ',', '.') . ' EUR';
$usd = static fn (float $u): string => '$' . number_format($u, 4, '.', ',');

// Token ohne die Aufnahmen - dort stehen Zeichen, und die haben unten einen
// eigenen Abschnitt.
$month = q1(
    "SELECT COUNT(*) AS n, COALESCE(SUM(cost_usd), 0) AS c,
            COALESCE(SUM(CASE WHEN purpose <> 'tts' THEN input_tokens ELSE 0 END), 0) AS ti,
            COALESCE(SUM(CASE WHEN purpose <> 'tts' THEN output_tokens ELSE 0 END), 0) AS to_
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
    // Der Schluessel stammt aus der Zeit der Fotos; heute ist es Schritt 1.
    'vocab_ocr'  => 'Fehlerkorrektur',
    'sentences'  => 'Lückensätze',
    'word_types' => 'Kategorien',
    'tts'        => 'Aufnahmen',
];

$perModel = qa(
    "SELECT model, COUNT(*) AS n, COALESCE(SUM(cost_usd), 0) AS c,
            COALESCE(SUM(input_tokens), 0) AS ti, COALESCE(SUM(output_tokens), 0) AS tokens_out
       FROM ai_requests
      WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01') AND purpose <> 'tts'
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

admin_head('Kosten', 'kosten.php');
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
    <div class="notice">Das Monatsbudget ist aufgebraucht - das Einlesen ist gesperrt,
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

<?php
/*
 * Die Aufnahmen (Azure Speech) - gezählt in Zeichen, nicht in Euro.
 *
 * Im kostenlosen Tarif zählt nur, wie viel vom Freikontingent übrig ist:
 * Ist es aufgebraucht, nimmt Azure bis zum Monatsende nichts mehr an, und
 * neue Lerneinheiten bekommen ihre Aufnahmen erst im nächsten Monat. Die
 * Linie zeigt, wie der Monat dorthin unterwegs ist.
 */
$ttsTage   = tts_zeichen_je_tag();
$ttsMonate = tts_zeichen_je_monat();
?>
<?php if (tts_aktiv() || array_sum($ttsMonate) > 0): ?>
<?php
$ttsFrei   = tts_tarif_frei();
$ttsGrenze = $ttsFrei ? tts_freikontingent() : 0;
$ttsSumme  = array_sum($ttsTage);
$monatTage = (int) date('t');
$heute     = (int) date('j');
$zahl      = static fn (int $n): string => number_format($n, 0, ',', '.');

// Die Linie: aufgelaufene Zeichen je Tag, bis heute.
$b = 700; $hoehe = 210; $links = 64; $rechts = 16; $oben = 14; $unten = 26;
$innenB = $b - $links - $rechts;
$innenH = $hoehe - $oben - $unten;
$ymax   = max(1000, (int) ceil(max($ttsGrenze * 1.1, $ttsSumme * 1.15)));
$x = static fn (float $tag): float => $links + ($tag - 1) / max(1, $monatTage - 1) * $innenB;
$y = static fn (float $z): float => $oben + $innenH - $z / $ymax * $innenH;
$punkte = [];
$lauf = 0;
for ($t = 1; $t <= $heute; $t++) {
    $lauf += $ttsTage[$t] ?? 0;
    $punkte[] = sprintf('%.1f,%.1f', $x($t), $y($lauf));
}
$flaeche = sprintf('%.1f,%.1f ', $x(1), $y(0)) . implode(' ', $punkte)
         . sprintf(' %.1f,%.1f', $x($heute), $y(0));
?>
<h2 id="aufnahmen">Aufnahmen: Zeichen diesen Monat</h2>
<div class="card">
    <p style="margin:0 0 6px">
        <strong><?= $zahl($ttsSumme) ?></strong> Zeichen
        <?php if ($ttsFrei && $ttsGrenze > 0): ?>
            von <?= $zahl($ttsGrenze) ?> frei
            (<?= (int) round($ttsSumme / $ttsGrenze * 100) ?>&nbsp;%)
            &ndash; <?= $ttsSumme >= $ttsGrenze * TTS_KONTINGENT_RAND
                ? '<strong>aufgebraucht</strong>, weiter ab dem 1.'
                : 'noch ' . $zahl(max(0, $ttsGrenze - $ttsSumme)) . ' übrig' ?>
        <?php else: ?>
            &ndash; Standardtarif, <?= $eur(tts_kosten($ttsSumme)) ?>
        <?php endif; ?>
    </p>
    <svg class="zeichenkurve" viewBox="0 0 <?= $b ?> <?= $hoehe ?>" role="img"
         aria-label="Aufgelaufene Zeichen in diesem Monat, Tag für Tag">
        <line class="achse" x1="<?= $links ?>" y1="<?= $y(0) ?>" x2="<?= $b - $rechts ?>" y2="<?= $y(0) ?>"/>
        <?php if ($ttsGrenze > 0): ?>
            <line class="grenze" x1="<?= $links ?>" y1="<?= $y($ttsGrenze) ?>"
                  x2="<?= $b - $rechts ?>" y2="<?= $y($ttsGrenze) ?>"/>
            <text class="grenztext" x="<?= $b - $rechts ?>" y="<?= $y($ttsGrenze) - 5 ?>"
                  text-anchor="end">Freikontingent <?= $zahl($ttsGrenze) ?></text>
        <?php endif; ?>
        <text x="<?= $links - 8 ?>" y="<?= $y(0) + 4 ?>" text-anchor="end">0</text>
        <text x="<?= $links - 8 ?>" y="<?= $y($ymax) + 10 ?>" text-anchor="end"><?= $zahl($ymax) ?></text>
        <polygon class="flaeche" points="<?= h($flaeche) ?>"/>
        <polyline class="linie" points="<?= h(implode(' ', $punkte)) ?>"/>
        <circle class="heute" cx="<?= $x($heute) ?>" cy="<?= $y($lauf) ?>" r="4"/>
        <?php foreach ([1, 10, 20, $monatTage] as $t): ?>
            <text x="<?= $x($t) ?>" y="<?= $hoehe - 6 ?>" text-anchor="middle"><?= $t ?>.</text>
        <?php endforeach; ?>
    </svg>
    <table class="data" style="margin-top:12px">
        <tr><th>Monat</th><th class="num">Zeichen</th><?php if ($ttsFrei && $ttsGrenze > 0): ?><th class="num">vom Freikontingent</th><?php endif; ?></tr>
        <?php foreach (array_reverse($ttsMonate, true) as $m => $z): ?>
            <tr>
                <td><?= h(date('m/Y', strtotime($m . '-01'))) ?></td>
                <td class="num"><?= $zahl($z) ?></td>
                <?php if ($ttsFrei && $ttsGrenze > 0): ?>
                    <td class="num"><?= (int) round($z / $ttsGrenze * 100) ?>&nbsp;%</td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
    </table>
    <p class="tiny muted" style="margin-bottom:0">
        Gezählt wird der gesprochene Satz &ndash; Azure zählt selbst nach und kann
        leicht abweichen. Deshalb hören die Läufe kurz vor der Grenze auf.
        Tarif und Kontingent stehen unter
        <a href="<?= h(admin_url('settings.php')) ?>">Einstellungen</a>.
    </p>
</div>
<?php endif; ?>

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
    <tr><th>Konto</th><th>Schule</th><th class="num">Anfragen</th><th class="num">Seiten</th>
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
    <tr><th>Zeitpunkt</th><th>Konto</th><th>Wofür</th><th>Modell</th><th class="num">Seiten</th>
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
