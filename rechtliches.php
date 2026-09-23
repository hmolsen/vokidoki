<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/html.php';
require_once __DIR__ . '/lib/version.php';
require_once __DIR__ . '/lib/thema.php';
require_once __DIR__ . '/lib/markdown.php';

/*
 * Impressum, Datenschutzerklärung, Lizenzen.
 *
 * Eine gewöhnliche Seite vom Server, kein Teil der App: Sie muss erreichbar
 * sein, bevor jemand angemeldet ist - auf der Anmeldeseite steht sie
 * genauso wie im Menü, und wer sie in einer Suchmaschine findet oder ihre
 * Adresse weitergibt, soll sie sehen. Eine Ansicht hinter dem Hash der PWA
 * wäre beides nicht.
 *
 * Ohne Anmeldung, ohne Sitzung, ohne Datenbank: Was hier steht, steht für
 * jeden gleich da, und eine Seite, die niemanden kennt, kann auch über
 * niemanden etwas verraten.
 */

$schluessel = (string) ($_GET['d'] ?? 'impressum');
$dokumente  = legal_documents();

/*
 * Die Adresse wählt aus einer festen Liste, sie benennt keine Datei. Sonst
 * stünde hier ein Weg, sich mit ?d=../config.php jede Datei des Servers
 * ausgeben zu lassen.
 */
if (!isset($dokumente[$schluessel])) {
    http_response_code(404);
    $schluessel = 'impressum';
}

$dok  = $dokumente[$schluessel];
$pfad = __DIR__ . '/' . $dok['datei'];

$inhalt = is_file($pfad)
    ? markdown_to_html((string) file_get_contents($pfad))
    : '<p>Dieses Dokument ist gerade nicht verfügbar.</p>';

/*
 * Woher man kam. Ein Kind kommt aus der App, eine Lehrkraft aus ihrem
 * Bereich, und beide wollen dorthin zurück - nicht auf eine Startseite, die
 * sie nicht gesucht haben. Geprüft wird streng: Ein Ziel aus der Adresse,
 * das irgendwohin zeigen dürfte, wäre eine Weiterleitung für Fremde.
 */
$zurueck = (string) ($_GET['z'] ?? '');
$zielOk  = $zurueck === 'teacher' ? url('/teacher/') : url('/');
$zielTxt = $zurueck === 'teacher' ? 'Zur Verwaltung' : 'Zur App';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
?><!doctype html>
<html lang="de"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($dok['titel']) ?> - Vokidoki</title>
<?= favicon_html() ?>
<link rel="stylesheet" href="<?= h(url('/style.css?v=' . app_version())) ?>">
<?= thema_kopf_skript() ?>
</head><body class="rechtsseite">

<main class="rechtsblatt">
    <div class="topbar">
        <a class="iconbtn" href="<?= h($zielOk) ?>" aria-label="<?= h($zielTxt) ?>"
           title="<?= h($zielTxt) ?>">&#8249;</a>
        <h1><?= h($dok['titel']) ?></h1>
    </div>

    <?php
    /*
     * Die drei nebeneinander, und das aktuelle markiert. Wer wegen des
     * Impressums kommt, sucht meistens gleich danach die
     * Datenschutzerklärung - dann soll er nicht erst zurückspringen
     * müssen.
     */
    ?>
    <nav class="rechtswahl" aria-label="Rechtliches">
        <?php foreach ($dokumente as $k => $d): ?>
            <a class="chip<?= $k === $schluessel ? ' on' : '' ?>"
               <?= $k === $schluessel ? 'aria-current="page"' : '' ?>
               href="<?= h(url('/rechtliches.php') . '?d=' . $k
                          . ($zurueck === 'teacher' ? '&z=teacher' : '')) ?>">
                <?= h($d['kurz']) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <article class="rechtstext">
        <?= $inhalt ?>
    </article>

    <p class="tiny muted rechtsfuss">
        <a href="<?= h($zielOk) ?>"><?= h($zielTxt) ?></a>
    </p>
</main>

</body></html>
