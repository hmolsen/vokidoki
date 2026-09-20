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
 * die staendig gebraucht werden - einlesen und freigeben -, und als letzte
 * Kachel der Weg zu einem neuen Kurs. Klassen, Kinder und Zettel sind
 * Verwaltung und stehen darunter.
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
        $neueste = (int) ($c['latest_unit'] ?? 0);

        /*
         * "+ Lerneinheit" legt eine leere an und fuehrt auf ihre Seite.
         *
         * Hier stand ein Link in die Einleseansicht der App - in einem
         * neuen Tab, weil man von dort nicht zurueckfand. Seit auf der
         * Lerneinheitsseite alle drei Wege stehen (von Hand, aus Dateien,
         * mit dem Telefon), fuehrt der kurze Weg genau dorthin. Derselbe
         * Knopf wie in der Lerneinheitentabelle des Kurses.
         */
        $knoepfe = '<div class="buttonrow">'
            . sprintf(
                '<form method="post" action="%s">%s'
                . '<input type="hidden" name="course_id" value="%d">'
                . '<button class="btn small" name="add_unit" value="1">'
                . '+ Lerneinheit</button></form>',
                h(teacher_url('course.php')),
                teacher_csrf_field(),
                (int) $c['id'],
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
        'Du unterrichtest noch keinen Kurs. Zwei Fragen, dann steht er: '
        . 'für welche Klasse, für welche Sprache. Die Kinder der Klasse '
        . 'kommen gleich mit hinein.',
        sprintf('<a class="btn small" href="%s">Kurs anlegen</a>',
                h(teacher_url('neu.php'))),
    ) ?>
<?php else: ?>
    <?php
    /*
     * Die Kurse, und als letzte Kachel der Weg zu einem neuen.
     *
     * Anlegen gehoert zur Liste - so wie in jeder Tabelle die letzte Zeile
     * die neue ist. Vorher lag der Weg dorthin drei Seiten tief in der
     * Verwaltung: Klassen, Klasse, Anlegezeile.
     */
    ?>
    <div class="kurskarten">
        <?php foreach ($meine as $c): ?>
            <?= kurskarte($c, true) ?>
        <?php endforeach; ?>
        <a class="card wahlkarte neuerkurs" href="<?= h(teacher_url('neu.php')) ?>">
            <span class="cflag plus">+</span>
            <span class="wahltext">
                <strong>Neuer Kurs</strong>
                <span class="tiny muted">Klasse wählen, Sprache wählen, fertig</span>
            </span>
        </a>
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

<?php
/*
 * Die Verwaltung als Karte, nicht als Fussnote.
 *
 * Sie stand als grauer Satz unter den Kursen, mit dem Weg dorthin als
 * unterstrichenem Wort mittendrin. Was man zweimal im Jahr braucht, gehoert
 * nach unten - aber es gehoert aussehen wie etwas, das man anfassen kann.
 * Also dieselbe Karte wie oben, und der Weg als Knopf.
 */
?>
<div class="card verwaltung">
    <span class="verwaltungtext">
        <strong>Verwaltung</strong>
        <span class="tiny muted">
            Klassen anlegen, Kinder eintragen, Zettel mit den Zugangsdaten
            drucken. Zum Unterrichten brauchst du das nur beim ersten Mal.
        </span>
    </span>
    <a class="btn small secondary" href="<?= h(teacher_url('classes.php')) ?>">
        Klassen und Kinder
    </a>
</div>

<?php endif; ?>

<?php teacher_foot(); ?>
