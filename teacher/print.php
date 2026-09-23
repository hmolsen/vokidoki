<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/letter.php';
require_once __DIR__ . '/../lib/qr.php';

/*
 * Die Zettel zum Ausschneiden - eine Seite je Kind.
 *
 * Kein PDF, sondern eine Druckseite. Ein PDF hiesse entweder eine Bibliothek
 * (die per FTP nicht auf den Server kommt) oder ein selbstgebauter Erzeuger
 * (viel Arbeit fuer ein schlechteres Ergebnis). Der Druckdialog des Browsers
 * kann "als PDF sichern", und damit hat die Lehrkraft beides.
 */

$user     = teacher_require();
$schoolId = (int) ($user['school_id'] ?? 0);

$classId = (int) ($_GET['class'] ?? 0);
$userId  = (int) ($_GET['user'] ?? 0);

$klasse = $classId > 0 ? class_in_school($classId, $schoolId) : null;

if ($klasse === null) {
    teacher_flash('Diese Klasse gibt es nicht.', 'bad');
    teacher_redirect('classes.php');
}

$kinder = class_members_list($classId);

/*
 * Ein einzelnes Blatt - nach einem zurueckgesetzten Passwort soll nicht die
 * ganze Klasse noch einmal aus dem Drucker kommen. Gefiltert wird aus der
 * Klassenliste heraus, damit die Zugehoerigkeit schon geprueft ist.
 */
if ($userId > 0) {
    $kinder = array_values(array_filter(
        $kinder,
        static fn (array $k): bool => (int) $k['id'] === $userId,
    ));
}

// Lehrkraefte bekommen keinen Zettel mit Anfangspasswort.
$kinder = array_values(array_filter(
    $kinder,
    static fn (array $k): bool => $k['role'] !== 'teacher',
));

$schule   = q1('SELECT name FROM schools WHERE id = ?', [$schoolId]);
$vorlage  = letter_template();
// Mit Schema und Host: Der Zettel verlaesst die Anwendung, und ein QR-Code
// mit einem blossen Pfad darin ist kein Link, sondern eine Zeichenkette.
$adresse  = public_url('/');
$qrSvg    = qr_svg($adresse, 4, 'Adresse der App');
?><!doctype html>
<html lang="de"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Zugangsdaten <?= h($klasse['name']) ?></title>
<?= favicon_html() ?>
<style>
:root { color-scheme: light; }
* { box-sizing: border-box; }

body {
    margin: 0;
    background: #e9ebf0;
    color: #171a21;
    font: 16px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}

/* Die Leiste ist nur zum Anschauen da und verschwindet beim Drucken. */
.bar {
    position: sticky; top: 0; z-index: 2;
    display: flex; gap: 14px; align-items: center; flex-wrap: wrap;
    padding: 12px 20px;
    background: #fff;
    border-bottom: 1px solid #dde0e8;
}
.bar a, .bar button {
    font: inherit; font-size: .95rem;
    color: #4f7cff; background: none; border: 0; padding: 0; cursor: pointer;
    text-decoration: none;
}
.bar .grow { margin-left: auto; color: #5d6470; font-size: .85rem; }

.blatt {
    width: 210mm; min-height: 297mm;
    margin: 20px auto;
    padding: 26mm 22mm;
    background: #fff;
    box-shadow: 0 1px 2px rgba(16,20,30,.08), 0 8px 24px rgba(16,20,30,.10);
}

.kopf { display: flex; gap: 18mm; align-items: flex-start; }
.kopf .qr { flex: none; width: 34mm; }
.kopf .qr svg { width: 100%; height: auto; display: block; }
.kopf .qr .bu { font-size: .7rem; color: #5d6470; text-align: center; margin-top: 4px; }

.kopf .wer { flex: 1 1 auto; }
.kopf h1 { font-size: 1.5rem; margin: 0 0 2px; }
.kopf .meta { color: #5d6470; font-size: .9rem; margin: 0 0 14px; }

/*
 * Zugangsdaten in einem eigenen Kasten und in einer Schrift mit festem
 * Zeichenabstand: Ein Kind tippt das ab, und dabei muss die Null vom O zu
 * unterscheiden sein und das Leerzeichen im Passwort sichtbar bleiben.
 */
.zugang {
    border: 1px solid #dde0e8; border-radius: 8px;
    padding: 10px 14px;
    display: grid; grid-template-columns: auto 1fr; gap: 4px 14px;
    align-items: baseline;
}
.zugang dt { color: #5d6470; font-size: .82rem; }
.zugang dd {
    margin: 0;
    font-family: ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace;
    font-size: 1.12rem; font-weight: 600;
    letter-spacing: .01em;
}

.brief { margin-top: 12mm; white-space: pre-wrap; }

.fuss {
    margin-top: 14mm; padding-top: 4mm;
    border-top: 1px solid #dde0e8;
    color: #5d6470; font-size: .78rem;
}

.leer { padding: 40px 20px; text-align: center; color: #5d6470; }

@media print {
    body { background: #fff; }
    .bar { display: none; }
    .blatt {
        width: auto; min-height: 0;
        margin: 0; padding: 0;
        box-shadow: none;
        /* Nach jedem Kind eine neue Seite, aber keine leere am Schluss. */
        break-after: page; page-break-after: always;
    }
    .blatt:last-child { break-after: auto; page-break-after: auto; }
}

@page { size: A4; margin: 18mm; }
</style>
</head>
<body>

<div class="bar">
    <button onclick="window.print()">Drucken</button>
    <a href="<?= h(teacher_url('class.php') . '?id=' . $classId) ?>">zurück zur Klasse</a>
    <span class="grow">
        <?= count($kinder) ?> Zettel, einer je Kind.
        Im Druckdialog lässt sich das auch als PDF sichern.
    </span>
</div>

<?php if ($kinder === []): ?>
    <p class="leer">Für diese Auswahl gibt es nichts zu drucken.</p>
<?php else: ?>
<?php foreach ($kinder as $k): ?>
    <?php
    $passwort = (string) ($k['initial_password'] ?? '');
    $brief    = letter_render($vorlage, [
        'name'         => (string) $k['display_name'],
        'benutzername' => (string) $k['username'],
        'passwort'     => $passwort !== '' ? $passwort : '(selbst gewählt)',
        'klasse'       => (string) $klasse['name'],
        'schule'       => (string) ($schule['name'] ?? ''),
        'url'          => $adresse,
    ]);
    ?>
    <section class="blatt">
        <div class="kopf">
            <?php if ($qrSvg !== null): ?>
            <div class="qr">
                <?= $qrSvg ?>
                <div class="bu">Code scannen</div>
            </div>
            <?php endif; ?>

            <div class="wer">
                <h1><?= h($k['display_name']) ?></h1>
                <p class="meta">
                    <?= h($klasse['name']) ?>
                    <?php if (($schule['name'] ?? '') !== ''): ?>
                        &middot; <?= h($schule['name']) ?>
                    <?php endif; ?>
                </p>

                <dl class="zugang">
                    <dt>Adresse</dt><dd><?= h($adresse) ?></dd>
                    <dt>Benutzername</dt><dd><?= h($k['username']) ?></dd>
                    <dt>Passwort</dt>
                    <dd>
                        <?php if ($passwort !== ''): ?>
                            <?= h($passwort) ?>
                        <?php else: ?>
                            <span style="font-weight:400;font-size:.9rem">
                                selbst geändert - bei Bedarf neu setzen
                            </span>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>
        </div>

        <div class="brief"><?= h($brief) ?></div>

        <p class="fuss">
            Dieser Zettel enthält ein Passwort - bitte nicht offen liegen lassen.
        </p>
    </section>
<?php endforeach; ?>
<?php endif; ?>

</body></html>
