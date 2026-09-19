<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

/*
 * Die Startseite: die eigenen Kurse.
 *
 * Hier stand vorher eine Weiterleitung auf die Klassenliste, und davor war
 * die Klassenliste die Startseite. Das bildete die Datenstruktur ab, nicht
 * die Arbeit: Eine Klasse legt man einmal im Schuljahr an, eine Lerneinheit
 * jede Woche. Bis zur Freigabe waren es drei Klicks und vier Seiten, und
 * die Kursseite war die einzige Stelle im ganzen Quelltext, die auf eine
 * Lerneinheit verlinkte.
 *
 * Jetzt liegt der Alltag vorn: je Kurs eine Karte mit den beiden Handgriffen,
 * die staendig gebraucht werden - einlesen und freigeben. Klassen, Kinder und
 * Zettel sind Verwaltung und stehen darunter.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['teacher_logout'])) {
    teacher_csrf_check();
    logout_user();
    teacher_redirect('index.php');
}

$user     = teacher_require();
$schoolId = (int) ($user['school_id'] ?? 0);

$meine = $schoolId > 0 ? courses_for_teacher((int) $user['id'], $schoolId) : [];
$alle  = $schoolId > 0 ? courses_for_school($schoolId) : [];

/*
 * Die fremden Kurse sind die, die nicht schon oben stehen. Zweimal
 * dieselbe Karte waere keine Uebersicht, sondern eine Verdopplung.
 */
$meineIds = array_flip(array_map(static fn (array $c): int => (int) $c['id'], $meine));
$fremde   = array_values(array_filter(
    $alle,
    static fn (array $c): bool => !isset($meineIds[(int) $c['id']]),
));

/** Eine Kurskarte. Zweimal gebraucht - einmal mit Knoepfen, einmal ohne. */
function kurskarte(array $c, bool $eigener): string
{
    $flagge  = flag_html($c['flag_emoji'] ?: FLAG_FALLBACK, 'cflag');
    $kursUrl = teacher_url('course.php') . '?id=' . (int) $c['id'];

    $frei   = (int) $c['released'];
    $gesamt = (int) $c['vocab'];
    if ($gesamt === 0) {
        $stand = '<span class="tiny muted">noch keine Vokabeln</span>';
    } elseif ($frei >= $gesamt) {
        $stand = sprintf('<span class="pill good">alle %d freigegeben</span>', $gesamt);
    } else {
        $stand = sprintf('<span class="pill">%d von %d freigegeben</span>', $frei, $gesamt);
    }

    $knoepfe = '';
    if ($eigener) {
        $einlesen = url('/') . '#/lang/' . (int) $c['language_id'] . '/import';
        $neueste  = (int) ($c['latest_unit'] ?? 0);

        $knoepfe = '<div class="buttonrow">'
            . sprintf(
                '<a class="btn small" href="%s" target="_blank" rel="noopener">'
                . '+ Lerneinheit</a>',
                h($einlesen),
            )
            . ($neueste > 0
                ? sprintf(
                    '<a class="btn small secondary" href="%s">Freigeben</a>',
                    h(teacher_url('unit.php') . '?id=' . $neueste),
                )
                : '<span class="btn small secondary aus" aria-disabled="true"'
                  . ' title="Erst eine Lerneinheit einlesen">Freigeben</span>')
            . '</div>';
    }

    return '<div class="card kurskarte' . ($c['active'] ? '' : ' dim') . '">'
         . '<a class="kurskopf" href="' . h($kursUrl) . '">'
         . $flagge
         . '<span><strong>' . h($c['name']) . '</strong>'
         . '<span class="tiny muted">'
         . ($c['class_name'] === null
                ? 'ohne Klasse'
                : 'Klasse ' . h($c['class_name']))
         . '</span></span></a>'
         . '<p class="kurszahlen tiny muted">'
         . sprintf('%d %s &middot; %d %s',
                   (int) $c['students'], (int) $c['students'] === 1 ? 'Kind' : 'Kinder',
                   (int) $c['units'], (int) $c['units'] === 1 ? 'Lerneinheit' : 'Lerneinheiten')
         . '</p>'
         . '<p class="kursstand">' . $stand . '</p>'
         . $knoepfe
         . '</div>';
}

teacher_head('Meine Kurse', $user);
teacher_flash_render();
?>

<?php if ($schoolId === 0): ?>
    <div class="notice">
        Dieses Konto gehört zu keiner Schule. Ohne Schule gibt es keine Kurse -
        der Betreiber kann das im Admin-Bereich zuordnen.
    </div>
<?php else: ?>

<?php if ($meine === []): ?>
    <?= teacher_leer(
        'Du unterrichtest noch keinen Kurs. Ein Kurs gehört zu einer Klasse: '
        . 'Leg zuerst die Klasse an, dann darin den Kurs &ndash; die Kinder '
        . 'der Klasse kommen gleich mit hinein.',
        sprintf('<a class="btn small" href="%s">Zu den Klassen</a>',
                h(teacher_url('classes.php'))),
    ) ?>
<?php else: ?>
    <div class="kurskarten">
        <?php foreach ($meine as $c): ?>
            <?= kurskarte($c, true) ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
/*
 * Die Kurse der Kolleginnen: zugeklappt, aber da.
 *
 * Eine Schule ist eine Vertrauensgemeinschaft - wer vertritt, muss an die
 * Unterlagen kommen. Das ist aber der Ausnahmefall, und deshalb steht er
 * nicht neben dem Alltag, sondern darunter und zu.
 */
?>
<?php if ($fremde !== []): ?>
<details class="kursealle">
    <summary>
        Alle Kurse der Schule
        <span class="tiny muted">(<?= count($fremde) ?> weitere)</span>
    </summary>
    <div class="kurskarten">
        <?php foreach ($fremde as $c): ?>
            <?= kurskarte($c, false) ?>
        <?php endforeach; ?>
    </div>
    <p class="tiny muted">
        Diese Kurse leitet jemand anderes. Öffnen und freigeben kannst du sie
        trotzdem &ndash; bei einer Vertretung ist genau das der Normalfall.
    </p>
</details>
<?php endif; ?>

<p class="verwaltung tiny muted">
    <strong>Verwaltung:</strong>
    <a href="<?= h(teacher_url('classes.php')) ?>">Klassen und Kinder</a>
    &middot; Zettel mit den Zugangsdaten druckst du in der Klasse.
</p>

<?php endif; ?>

<?php teacher_foot(); ?>
