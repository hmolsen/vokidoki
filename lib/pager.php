<?php
declare(strict_types=1);

require_once __DIR__ . '/html.php';

/**
 * Seitenblätterung für die Admin-Tabellen.
 *
 * Bewusst ohne Seiteneffekte und getrennt von admin/_boot.php: Das startet
 * beim Laden eine Sitzung, und die Tests sollen die Rechnung prüfen können,
 * ohne einen Admin-Bereich hochzufahren.
 */

/** Markierung für eine Auslassung in der Seitenliste. */
const PAGER_GAP = 0;

/**
 * Welche Seitenzahlen werden angezeigt?
 *
 * Erste und letzte Seite immer, dazu ein Fenster um die aktuelle. Was
 * dazwischen wegfällt, wird zu PAGER_GAP zusammengefasst - ausser es fehlt
 * genau eine Seite, dann steht die Zahl da statt eines Punktes. Drei Punkte
 * für eine einzige ausgelassene Seite sähen albern aus und wären sogar
 * breiter als die Zahl selbst.
 *
 * @return int[] Seitenzahlen, dazwischen PAGER_GAP
 */
function pager_pages(int $current, int $total, int $window = 1): array
{
    $zeigen = [1, $total];
    for ($i = $current - $window; $i <= $current + $window; $i++) {
        $zeigen[] = $i;
    }

    $zeigen = array_values(array_unique(array_filter(
        $zeigen,
        static fn (int $n): bool => $n >= 1 && $n <= $total,
    )));
    sort($zeigen);

    $raus   = [];
    $vorher = 0;
    foreach ($zeigen as $n) {
        if ($vorher > 0 && $n - $vorher > 1) {
            $raus[] = $n - $vorher === 2 ? $vorher + 1 : PAGER_GAP;
        }
        $raus[] = $n;
        $vorher = $n;
    }

    return $raus;
}

/**
 * Die Blätterleiste als HTML. $link liefert zu einer Seitenzahl die Adresse.
 *
 * Bei einer einzigen Seite kommt nichts zurück - eine Leiste, die nirgendwo
 * hinführt, ist nur Zierrat.
 */
function pager(int $current, int $total, callable $link, string $unit = 'Seite'): string
{
    if ($total < 2) {
        return '';
    }

    $current = max(1, min($current, $total));

    // Die Pfeile bleiben am Rand stehen, statt zu verschwinden - sonst
    // rutscht die ganze Reihe beim Blättern zur Seite.
    $step = static function (bool $moeglich, int $ziel, string $zeichen, string $titel)
                            use ($link): string {
        return $moeglich
            ? sprintf('<a class="pg pg-step" href="%s" aria-label="%s">%s</a>',
                      h($link($ziel)), h($titel), $zeichen)
            : sprintf('<span class="pg pg-step off" aria-hidden="true">%s</span>', $zeichen);
    };

    $teile  = $step($current > 1, $current - 1, '&lsaquo;', 'Eine Seite zurück');

    foreach (pager_pages($current, $total) as $n) {
        if ($n === PAGER_GAP) {
            $teile .= '<span class="pg pg-gap" aria-hidden="true">&hellip;</span>';
            continue;
        }
        $teile .= $n === $current
            ? sprintf('<span class="pg on" aria-current="page">%d</span>', $n)
            : sprintf('<a class="pg" href="%s">%d</a>', h($link($n)), $n);
    }

    $teile .= $step($current < $total, $current + 1, '&rsaquo;', 'Eine Seite weiter');

    return sprintf(
        '<nav class="pager" aria-label="Seitenauswahl">'
        . '<span class="pg-info">%s %d von %d</span>%s</nav>',
        h($unit), $current, $total, $teile,
    );
}
