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


/*
 * Nur Kinder, deren Passwort diese Lehrkraft gerade vergeben hat - siehe
 * teacher_druckbar(). Hier stand für die übrigen "selbst geändert - bei
 * Bedarf neu setzen", und damit verriet der Zettel, wer sich schon
 * angemeldet hat. Lehrkräfte bekommen ohnehin keinen Zettel.
 */
$druckbar = teacher_druckbar();
$kinder   = array_values(array_filter(
    $kinder,
    static fn (array $k): bool => $k['role'] !== 'teacher'
        && isset($druckbar[(int) $k['id']])
        && (string) ($k['initial_password'] ?? '') !== '',
));

$schule   = q1('SELECT name, kuerzel FROM schools WHERE id = ?', [$schoolId]);
$kuerzel  = (string) ($schule['kuerzel'] ?? '');
// Die Vorlage der Lehrkraft, die druckt - sonst die des Betreibers.
$vorlage  = letter_template($user);
// Mit Schema und Host: Der Zettel verlaesst die Anwendung, und ein QR-Code
// mit einem blossen Pfad darin ist kein Link, sondern eine Zeichenkette.
$adresse  = public_url('/');

/*
 * Der QR-Code bringt Schulkürzel und Benutzernamen mit (views/login.js
 * liest ?schule= und ?name=): Wer ihn abfotografiert, tippt nur noch das
 * Passwort. Das Passwort selbst steht nicht darin - ein abfotografierter
 * Zettel soll kein Schlüssel sein.
 */
$qrFuer = static fn (array $k): ?string => qr_svg(
    public_url('/?' . http_build_query(['schule' => $kuerzel, 'name' => (string) $k['username']])),
    4, 'Anmeldung in der App');

/**
 * Der Brief als Absätze.
 *
 * Die Vorlage ist reiner Text (lib/letter.php) und soll es bleiben. Damit
 * er auf dem Zettel trotzdem gegliedert aussieht, wird aus einem Absatz,
 * der nur aus einer kurzen Zeile ohne Satzzeichen am Ende besteht, eine
 * Zwischenüberschrift - so wie "Information für die Erziehungsberechtigten".
 * Wer die Vorlage umschreibt, bekommt das von selbst, ohne Markup zu lernen.
 */
function brief_html(string $text): string
{
    $html = '';
    foreach (preg_split('/\n\s*\n/', trim(str_replace("\r\n", "\n", $text))) ?: [] as $absatz) {
        $absatz = trim($absatz);
        if ($absatz === '') {
            continue;
        }
        $eineZeile = !str_contains($absatz, "\n");
        if ($eineZeile && mb_strlen($absatz) <= 70 && preg_match('/[.,:;!?)]$/u', $absatz) !== 1) {
            $html .= '<h2>' . h($absatz) . '</h2>';
            continue;
        }
        $html .= '<p>' . nl2br(h($absatz), false) . '</p>';
    }
    return $html;
}
?><!doctype html>
<html lang="de"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Zugangsdaten <?= h($klasse['name']) ?></title>
<?= favicon_html() ?>
<style>
/*
 * Im Stil der App: dieselben Schriften (vom eigenen Server), Voki und das
 * Wortzeichen. Der Zettel ist das Erste, was ein Kind und seine Eltern von
 * Vokidoki sehen - er soll aussehen wie das, wozu er einlädt.
 */
@font-face {
    font-family: 'Fredoka';
    src: url('<?= h(url('/assets/fonts/fredoka.woff2')) ?>') format('woff2');
    font-weight: 300 700;
}
@font-face {
    font-family: 'Nunito';
    src: url('<?= h(url('/assets/fonts/nunito.woff2')) ?>') format('woff2');
    font-weight: 200 1000;
}

:root {
    color-scheme: light;
    --tinte: #171a21;
    --leise: #5b6270;
    --linie: #dfe3ec;
    --blau:  #4f7cff;
    --gruen: #afd535;
    --gruen-tief: #5f7d10;
    --flaeche: #f4f7ff;
}
* { box-sizing: border-box; }

body {
    margin: 0;
    background: #e9ebf0;
    color: var(--tinte);
    font: 15px/1.5 'Nunito', -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

/* Die Leiste ist nur zum Anschauen da und verschwindet beim Drucken. */
.bar {
    position: sticky; top: 0; z-index: 2;
    display: flex; gap: 14px; align-items: center; flex-wrap: wrap;
    padding: 12px 20px;
    background: #fff;
    border-bottom: 1px solid var(--linie);
}
.bar a, .bar button {
    font: inherit; font-size: .95rem; font-weight: 700;
    color: var(--blau); background: none; border: 0; padding: 0; cursor: pointer;
    text-decoration: none;
}
.bar .grow { margin-left: auto; color: var(--leise); font-size: .85rem; }

.blatt {
    position: relative;
    width: 210mm; min-height: 297mm;
    margin: 20px auto;
    padding: 16mm 18mm 14mm;
    background: #fff;
    box-shadow: 0 1px 2px rgba(16,20,30,.08), 0 8px 24px rgba(16,20,30,.10);
    overflow: hidden;
}
/* Ein grüner Streifen oben - Vokis Farbe, auch ausgedruckt gleich zu erkennen. */
.blatt::before {
    content: ""; position: absolute; left: 0; right: 0; top: 0; height: 5mm;
    background: linear-gradient(90deg, var(--gruen), #d7ec8a);
}

.kopf { display: flex; align-items: center; justify-content: space-between; margin-bottom: 7mm; }
.kopf .logo { height: 15mm; width: auto; }
.kopf .voki { height: 24mm; width: auto; margin-right: -2mm; }

.fuer { font-family: 'Fredoka', sans-serif; margin: 0 0 4mm; }
.fuer small { display: block; font-size: .85rem; font-weight: 500; color: var(--leise); }
.fuer strong { display: block; font-size: 1.9rem; font-weight: 600; line-height: 1.1; }
.fuer span { font-size: .95rem; font-weight: 500; color: var(--leise); }

/* Zugang: QR-Code und die drei Angaben in einer Karte. */
.zugang {
    display: flex; gap: 8mm; align-items: center;
    padding: 5mm 6mm;
    border-radius: 5mm;
    background: var(--flaeche);
    border: 1.5px solid #d6e0ff;
}
.zugang .qr { flex: none; width: 32mm; text-align: center; }
.zugang .qr svg { width: 100%; height: auto; display: block; background: #fff; padding: 2mm; border-radius: 3mm; }
.zugang .qr .bu { font-size: .72rem; color: var(--leise); margin-top: 1.5mm; }

/*
 * Zugangsdaten in einer Schrift mit festem Zeichenabstand: Ein Kind tippt
 * das ab, und dabei muss die Null vom O zu unterscheiden sein.
 */
.zugang dl { margin: 0; flex: 1 1 auto; display: grid; grid-template-columns: auto 1fr; gap: 2.5mm 6mm; align-items: baseline; }
.zugang dt { color: var(--leise); font-size: .82rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
.zugang dd {
    margin: 0;
    font-family: ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace;
    font-size: 1.15rem; font-weight: 700;
    overflow-wrap: anywhere;
}
.zugang dd.pw { color: var(--blau); font-size: 1.3rem; }

.brief { margin-top: 7mm; font-size: .98rem; }
.brief p { margin: 0 0 3mm; }
/* Zwischenüberschriften aus der Vorlage - siehe brief_html(). */
.brief h2 {
    font-family: 'Fredoka', sans-serif; font-weight: 600; font-size: 1.12rem;
    margin: 6mm 0 2.5mm; padding-top: 4mm;
    border-top: 1.5px dashed var(--linie);
    color: var(--gruen-tief);
}

.fuss {
    margin-top: 6mm; padding-top: 3mm;
    border-top: 1px solid var(--linie);
    display: flex; justify-content: space-between; gap: 6mm;
    color: var(--leise); font-size: .75rem;
}

.leer { max-width: 560px; margin: 40px auto; padding: 24px; text-align: center; color: var(--leise);
        background: #fff; border-radius: 14px; }

@media print {
    body { background: #fff; }
    .bar { display: none; }
    .blatt {
        width: auto; min-height: 0;
        margin: 0; padding: 12mm 0 0;
        box-shadow: none;
        /* Nach jedem Kind eine neue Seite, aber keine leere am Schluss. */
        break-after: page; page-break-after: always;
    }
    .blatt:last-child { break-after: auto; page-break-after: auto; }
}

@page { size: A4; margin: 12mm 16mm; }
</style>
</head>
<body>

<div class="bar">
    <button onclick="window.print()">Drucken</button>
    <a href="<?= h(teacher_url('class.php') . '?id=' . $classId) ?>">zurück zur Klasse</a>
    <a href="<?= h(teacher_url('konto.php') . '#vorlage') ?>">Text anpassen</a>
    <span class="grow">
        <?= count($kinder) ?> Zettel, einer je Kind.
        Im Druckdialog lässt sich das auch als PDF sichern.
    </span>
</div>

<?php if ($kinder === []): ?>
    <p class="leer">
        <strong>Hier gibt es gerade nichts zu drucken.</strong><br>
        Zettel gibt es direkt nach dem Anlegen eines Kontos oder nach einem
        neuen Passwort &ndash; danach nicht mehr, damit niemand an der Liste
        ablesen kann, wer sich schon angemeldet hat. Für einen verlorenen
        Zettel in der Klasse beim Kind &bdquo;Neues Passwort&ldquo; wählen.
    </p>
<?php else: ?>
<?php foreach ($kinder as $k): ?>
    <?php
    $passwort = (string) $k['initial_password'];
    $brief    = letter_render($vorlage, [
        'name'         => (string) $k['display_name'],
        'kuerzel'      => $kuerzel,
        'benutzername' => (string) $k['username'],
        'passwort'     => $passwort,
        'klasse'       => (string) $klasse['name'],
        'schule'       => (string) ($schule['name'] ?? ''),
        'url'          => $adresse,
        'datenschutz'  => public_url('/rechtliches.php?d=datenschutz'),
        'impressum'    => public_url('/rechtliches.php?d=impressum'),
    ]);
    ?>
    <section class="blatt">
        <div class="kopf">
            <img class="logo" src="<?= h(url('/assets/vokidoki_logo.svg')) ?>" alt="Vokidoki">
            <img class="voki" src="<?= h(url('/assets/voki-icon.svg')) ?>" alt="">
        </div>

        <h1 class="fuer">
            <small>Dein Zugang zu Vokidoki</small>
            <strong><?= h($k['display_name']) ?></strong>
            <span>Klasse <?= h($klasse['name']) ?><?= ($schule['name'] ?? '') !== ''
                ? ' &middot; ' . h($schule['name']) : '' ?></span>
        </h1>

        <div class="zugang">
            <?php $qrSvg = $qrFuer($k); ?>
            <?php if ($qrSvg !== null): ?>
            <div class="qr">
                <?= $qrSvg ?>
                <div class="bu">Code scannen</div>
            </div>
            <?php endif; ?>
            <dl>
                <dt>Adresse</dt><dd><?= h($adresse) ?></dd>
                <dt>Schulkürzel</dt><dd><?= h($kuerzel) ?></dd>
                <dt>Benutzername</dt><dd><?= h($k['username']) ?></dd>
                <dt>Passwort</dt><dd class="pw"><?= h($passwort) ?></dd>
            </dl>
        </div>

        <div class="brief"><?= brief_html($brief) ?></div>

        <p class="fuss">
            <span>Dieser Zettel enthält ein Passwort &ndash; bitte gut aufheben und nicht offen liegen lassen.</span>
            <span>vokidoki.de</span>
        </p>
    </section>
<?php endforeach; ?>
<?php endif; ?>

</body></html>
