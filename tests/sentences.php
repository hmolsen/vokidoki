<?php
declare(strict_types=1);

/**
 * Tests für Antwortvergleich und Satzprüfung des Lückentexts.
 *
 *   php tests/sentences.php
 *
 * Rein rechnerisch - weder Datenbank noch API werden angefasst.
 */

// Diese Datei gehört nicht ins Web - sie läuft ausschließlich auf der
// Kommandozeile.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/sentences.php';

$passed = 0;
$failed = 0;

function ok(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  \u{2713} $label\n";
    } else {
        $failed++;
        echo "  \u{2717} $label" . ($detail !== '' ? " - $detail" : '') . "\n";
    }
}

function section(string $name): void
{
    echo "\n$name\n";
}

echo "Lückentext: Antwortvergleich und Satzprüfung\n";

// ------------------------------------------------------------------ Vergleich

section('Genau richtig');

foreach ([
    ["Je m'appelle", "Je m'appelle", 'unverändert'],
    ["je m'appelle", "Je m'appelle", 'kleingeschrieben'],
    ["JE M'APPELLE", "Je m'appelle", 'großgeschrieben'],
    ["  Je   m'appelle ", "Je m'appelle", 'mit Leerzeichen drumherum'],
    ["Je m\u{2019}appelle", "Je m'appelle", 'mit typografischem Apostroph'],
    ['élève', 'élève', 'mit Akzenten'],
] as [$typed, $expected, $was]) {
    $r = answer_check($typed, $expected);
    ok("Richtig und exakt: $was", $r['correct'] && $r['exact'], json_encode($r));
}

section('Richtig, aber Schreibweise zeigen');

foreach ([
    ['je mappelle', "Je m'appelle", 'Apostroph fehlt'],
    ['eleve', 'élève', 'Akzente fehlen'],
    ['Jeg bor', 'Jeg bør', 'dänisches o durchgestrichen'],
    ['grosse', 'große', 'scharfes s ausgeschrieben'],
    ['ca va', 'ça va', 'Cedille fehlt'],
] as [$typed, $expected, $was]) {
    $r = answer_check($typed, $expected);
    ok("Zählt als richtig, Hinweis: $was", $r['correct'] && !$r['exact'], json_encode($r));
}

section('Falsch');

foreach ([
    ['etwas ganz anderes', 'élève', 'anderes Wort'],
    ['', 'élève', 'leere Eingabe'],
    ['   ', 'élève', 'nur Leerzeichen'],
    ['élèves', 'élève', 'Plural statt Singular'],
    ['elev', 'élève', 'zu kurz'],
] as [$typed, $expected, $was]) {
    $r = answer_check($typed, $expected);
    ok("Wird als falsch gewertet: $was", !$r['correct'], json_encode($r));
}

// ------------------------------------------------------------------ Satzprüfung

section('Brauchbare Sätze');

$gut = sentence_clean([
    'vocab_id' => 7,
    'native'   => 'Ich heiße Hannes.',
    'foreign'  => '{} Hannes.',
    'answer'   => "Je m'appelle",
], [7, 8]);
ok('Ein gültiger Satz kommt durch', $gut !== null);
ok('Felder werden übernommen',
   $gut !== null && $gut['answer'] === "Je m'appelle" && $gut['vocab_id'] === 7);
ok('Leerzeichen werden getrimmt',
   sentence_clean([
       'vocab_id' => 7, 'native' => '  Hallo.  ',
       'foreign'  => '  {} !  ', 'answer' => '  Salut  ',
   ], [7])['native'] === 'Hallo.');

section('Unbrauchbare Sätze werden verworfen');

foreach ([
    [['vocab_id' => 7, 'native' => 'A', 'foreign' => 'ohne Lücke', 'answer' => 'x'],
     'keine Lücke'],
    [['vocab_id' => 7, 'native' => 'A', 'foreign' => '{} und {}', 'answer' => 'x'],
     'zwei Lücken'],
    [['vocab_id' => 7, 'native' => 'A {}', 'foreign' => '{} b', 'answer' => 'x'],
     'Lücke auch im deutschen Satz'],
    [['vocab_id' => 7, 'native' => 'A', 'foreign' => '{} b', 'answer' => ''],
     'leere Lösung'],
    [['vocab_id' => 7, 'native' => '', 'foreign' => '{} b', 'answer' => 'x'],
     'kein deutscher Satz'],
    [['vocab_id' => 99, 'native' => 'A', 'foreign' => '{} b', 'answer' => 'x'],
     'fremde Vokabelnummer'],
    [['vocab_id' => 7, 'native' => 'Ich heiße Hannes.',
      'foreign' => "{} Hannes, je m'appelle Hannes.", 'answer' => "Je m'appelle"],
     'Lösung steht daneben im Satz'],
    [['vocab_id' => 7, 'native' => 'A', 'foreign' => '{} Eleve', 'answer' => 'élève'],
     'Lösung daneben, nur ohne Akzente geschrieben'],
] as [$row, $was]) {
    ok("Verworfen: $was", sentence_clean($row, [7, 8]) === null, json_encode($row));
}

section('Gültige Sätze werden nicht fälschlich verworfen');

// Ein früherer Versuch verglich ohne Leerzeichen - da fand sich "le" in fast
// jedem französischen Satz, und die Hälfte aller Sätze flog raus.
foreach ([
    ['le chien mange {} pain', 'le', 'kurzer Artikel steht normal im Satz'],
    ['Nous avons {} maison', 'la', 'Artikel als Lösung'],
    ['Il a {} ans et elle a dix ans', 'dix', 'kurze Zahl kommt doppelt vor'],
    ['Il danse {} le jardin', 'dans', '"dans" steckt in "danse", ist aber eigenes Wort'],
    ['Je mange {} tous les jours', 'une pomme', 'Lösung kommt nicht vor'],
] as [$foreign, $answer, $was]) {
    ok("Angenommen: $was",
       sentence_clean(['vocab_id' => 7, 'native' => 'x',
                       'foreign' => $foreign, 'answer' => $answer], [7]) !== null,
       "\"$foreign\" / \"$answer\"");
}

section('Ganze Äußerungen');

// Frueher waren Fragen und Grussformeln von der Satzerzeugung ausgenommen.
// Fuer sie ist der Lueckentext aber gerade die wertvollste Uebung - die Luecke
// deckt dann einen kennzeichnenden Teil der Aeusserung ab.
foreach ([
    ['Wie heißt du?', '{} comment ?', "Tu t'appelles", 'Lücke am Anfang'],
    ['Wie heißt du?', 'Tu {} comment ?', "t'appelles", 'Lücke in der Mitte'],
    ['Gute Nacht!', '{}', 'Bonne nuit !', 'Lücke ersetzt die ganze Äußerung'],
    ['Danke, gnädige Frau!', 'Merci, {} !', 'Madame', 'Lücke am Ende'],
] as [$native, $foreign, $answer, $was]) {
    ok("Angenommen: $was",
       sentence_clean(['vocab_id' => 7, 'native' => $native,
                       'foreign' => $foreign, 'answer' => $answer], [7]) !== null,
       "\"$foreign\" / \"$answer\"");
}

section('Sonderzeichen zum Antippen');

// Die Zeichenreihe im Lückentext steht in JavaScript, der Antwortvergleich in
// PHP. Bietet die eine Seite ein Zeichen an, das die andere nicht kennt, tippt
// das Kind etwas ein, das anschließend als falsch gilt - und zwar genau dann,
// wenn es sich Mühe mit der Schreibweise gegeben hat. Deshalb wird hier über
// die Sprachgrenze hinweg geprüft.
$js = (string) file_get_contents(__DIR__ . '/../views/cloze.js');

preg_match('/const ACCENT_KEYS = \{(.*?)\n\};/s', $js, $m);
$block = $m[1] ?? '';
ok('Die Zeichenreihe steht in views/cloze.js', $block !== '');

preg_match_all('/(\w+):\s*\[(.*?)\]/s', $block, $treffer, PREG_SET_ORDER);

$reihen = [];
foreach ($treffer as $t) {
    preg_match_all('/\x27([^\x27]*)\x27|"([^"]*)"/u', $t[2], $z, PREG_SET_ORDER);
    $reihen[$t[1]] = array_map(static fn (array $e): string => $e[2] ?? '' ?: $e[1], $z);
}

ok('Französisch bringt eine Reihe mit', count($reihen['fr'] ?? []) > 0);
ok('Dänisch auch', ($reihen['da'] ?? []) === ['æ', 'ø', 'å'], json_encode($reihen['da'] ?? []));
ok('Englisch und Latein nicht - die deutsche Tastatur reicht dort',
   !isset($reihen['en']) && !isset($reihen['la']));
ok('Der Apostroph steht bei Französisch vorn',
   ($reihen['fr'][0] ?? '') === "'", json_encode($reihen['fr'][0] ?? null));

foreach ($reihen as $sprache => $zeichen) {
    ok("Keine Dublette in der Reihe für $sprache",
       count($zeichen) === count(array_unique($zeichen)), json_encode($zeichen));
}

// Eine Konstante, die niemand benutzt, würde alles oben klaglos bestehen.
// Diese drei Punkte sind es, an denen die Reihe still kaputtgehen kann.
ok('Die Reihe wird ins Formular eingebaut',
   str_contains($js, '${accentRow(data.lang)}'));
ok('Und verdrahtet - sonst passiert beim Antippen nichts',
   preg_match('/wireAccents\(input,/', $js) === 1);
ok('Die Knöpfe sind type="button" - sonst schicken sie das Formular ab',
   preg_match('/<button type="button" class="accent"/', $js) === 1);
ok('mousedown wird abgefangen - sonst klappt die Tastatur bei jedem Zeichen zu',
   preg_match('/mousedown[^\n]*preventDefault/', $js) === 1);
ok('Die Schreibmarke wandert hinter das eingefügte Zeichen',
   str_contains($js, 'setSelectionRange'));

// Freier Umbruch liess in der zweiten Reihe einen Rest stehen, der nicht
// unter der ersten ausgerichtet war - das sah schlicht unaufgeräumt aus.
$css = (string) file_get_contents(__DIR__ . '/../style.css');

ok('Die Tasten stehen in einem Raster, nicht im freien Umbruch',
   preg_match('/\.accents\s*\{[^}]*display:\s*grid/s', $css) === 1
   && preg_match('/\.accents\s*\{[^}]*flex-wrap/s', $css) !== 1);
ok('Die Spaltenzahl kommt aus dem Code, steht also nicht fest',
   str_contains($js, '--cols:${accentColumns(keys.length)}')
   && preg_match('/grid-template-columns:\s*repeat\(var\(--cols/', $css) === 1);
ok('Die Tasten sind so groß wie die übrigen Bedienelemente',
   preg_match('/^\.accent \{[^}]*min-height:\s*(\d+)px/ms', $css, $m) === 1
   && (int) $m[1] >= 44, $m[1] ?? 'keine Höhe');
ok('Und benutzen die Schrift der App',
   preg_match('/^\.accent \{[^}]*font:\s*inherit/ms', $css) === 1);

// Jede Sprache soll glatt aufgehen - sonst bleibt doch ein Rest stehen.
$spalten = static function (int $n): int {
    if ($n <= 7) return $n;
    for ($s = 7; $s >= 4; $s--) {
        if ($n % $s === 0) return $s;
    }
    return 7;
};
$krumm = [];
foreach ($reihen as $sprache => $zeichen) {
    $c = $spalten(count($zeichen));
    if (count($zeichen) % $c !== 0) {
        $krumm[] = $sprache . ':' . count($zeichen) . ' in ' . $c;
    }
}
ok('Jede Zeichenreihe füllt ihre Reihen vollständig',
   $krumm === [], implode(', ', $krumm));

// Der eigentliche Punkt: Jedes angebotene Zeichen muss sich auf schlichte
// Buchstaben zurückführen lassen, sonst kann der Vergleich es nicht einordnen.
$unbekannt = [];
foreach ($reihen as $sprache => $zeichen) {
    foreach ($zeichen as $ch) {
        if (preg_match('/^[a-z\x27]+$/', answer_fold($ch)) !== 1) {
            $unbekannt[] = "$sprache:$ch->" . answer_fold($ch);
        }
    }
}
ok('Jedes angebotene Zeichen kennt der Antwortvergleich',
   $unbekannt === [], implode(', ', $unbekannt));

// Der Anlass: "sœur" steht in jeder ersten Französisch-Lektion, und die
// Ligatur fehlte in der Tabelle.
ok('Die Ligatur œ wird auf oe zurückgeführt', answer_fold('sœur') === 'soeur',
   answer_fold('sœur'));
$r = answer_check('soeur', 'sœur');
ok('"soeur" zählt als richtig, mit Hinweis auf die Schreibweise',
   $r['correct'] && !$r['exact'], json_encode($r));
ok('"sœur" ist genau richtig',
   answer_check('sœur', 'sœur')['exact'], 'Ligatur schlägt bei exakter Eingabe fehl');

section('Platz für die Tastatur im Lückentext');

// Die Tastatur verkleinert auf dem iPhone nur den sichtbaren Ausschnitt, die
// Seite bleibt so hoch wie zuvor. iOS scrollt dann zum Eingabefeld, und der
// Satz wandert aus dem Bild, waehrend unten graue Flaeche stehen bleibt.
$core = (string) file_get_contents(__DIR__ . '/../core.js');
$appjs = (string) file_get_contents(__DIR__ . '/../app.js');

// Die Beobachtung sitzt in app.js, nicht in core.js: app.js ist die einzige
// Datei, die ihren Versionsstempel in der Adresse traegt und nach einem
// Update verlaesslich frisch ankommt. Ein neuer Name, den app.js aus core.js
// holt, reisst die ganze App mit, solange dort noch die alte Fassung liegt.
ok('Die sichtbare Hoehe wird nachgehalten',
   str_contains($appjs, 'visualViewport') && str_contains($appjs, '--vvh'));
ok('Und eine offene Tastatur wird erkannt',
   str_contains($appjs, 'keyboard-open'));
ok('Ohne dass core.js dafuer einen neuen Namen herausgeben muss',
   !str_contains($core, 'trackViewport'));
ok('Die Huelle bindet sich daran, statt ihre Grundhoehe zu behalten',
   preg_match('/\.app\.fitted\s*\{[^}]*height:\s*var\(--vvh/s', $css) === 1);
ok('Nur wenn die Ansicht das auch anbietet',
   str_contains($core, "':scope > .screen'"));

ok('Der Lückentext ist eine solche Ansicht',
   preg_match('/<div class="screen">/', $js) === 1);
ok('Mit festem Kopf, wachsender Mitte und festem Fuß',
   str_contains($js, 'screen-top') && str_contains($js, 'screen-mid')
   && str_contains($js, 'screen-bottom'));
ok('Die Mitte nimmt den uebrigen Platz und scrollt notfalls in sich',
   preg_match('/\.screen-mid\s*\{[^}]*flex:\s*1/s', $css) === 1
   && preg_match('/\.screen-mid\s*\{[^}]*overflow-y:\s*auto/s', $css) === 1);

// Was ueber der Tastatur keinen Platz mehr hat, tritt zurueck.
ok('Bei offener Tastatur tritt der Fortschritt zurueck',
   preg_match('/body\.keyboard-open[^{]*\.topbar-progress[^{]*\{[^}]*display:\s*none/s', $css) === 1);
ok('Der Fortschritt steht neben dem Titel, nicht in einer eigenen Zeile',
   str_contains($js, 'class="topbar-progress"')
   && preg_match('/\.topbar-progress\s*\{[^}]*display:\s*flex/s', $css) === 1);
ok('Der Pruefen-Knopf nimmt nicht die ganze Breite',
   str_contains($js, 'class="btn small cloze-check"'));

// Der erste Anlauf reichte nicht: Solange html und body scrollen koennen,
// schiebt iOS beim Fokus die ganze Seite nach oben - der Container mag so
// hoch sein, wie er will.
ok('Das Dokument selbst wird festgesetzt',
   str_contains($core, "'locked'")
   && preg_match('/html\.locked[^{]*\{[^}]*overflow:\s*hidden/s', $css) === 1);
ok('Die Ansicht ist fixiert, damit ein Scrollen sie nicht mitnimmt',
   preg_match('/\.app\.fitted\s*\{[^}]*position:\s*fixed/s', $css) === 1);
ok('Und wird zurueckgeholt, falls iOS doch gescrollt hat',
   str_contains($appjs, 'offsetTop') && str_contains($appjs, '--vvtop')
   && preg_match('/\.app\.fitted\s*\{[^}]*top:\s*var\(--vvtop/s', $css) === 1);

/*
 * Zweite, davon unabhaengige Absicherung: Das Eingabefeld sitzt in der Luecke
 * selbst. iOS scrollt beim Fokus darauf - und damit unweigerlich auf den
 * Satz, denn das Feld steht mitten darin. Das ist der Teil, der auch dann
 * traegt, wenn die Hoehenrechnung auf einem Geraet danebenliegt. Nebenbei
 * spart es die Zeile, die das Feld vorher fuer sich brauchte.
 */
ok('Das Eingabefeld sitzt in der Luecke des Satzes',
   preg_match('/function gapField\(/', $js) === 1
   && str_contains($js, "replace('{}', feld)"));
ok('Und wird nicht mehr daneben gesetzt',
   !str_contains($js, 'class="cloze-entry"') && !str_contains($css, '.cloze-entry'));
ok('Es sieht aus wie die Luecke, nicht wie ein Kasten',
   preg_match('/input\.cloze-input\s*\{[^}]*border-bottom:\s*3px/s', $css) === 1
   && preg_match('/input\.cloze-input\s*\{[^}]*background:\s*transparent/s', $css) === 1);

// Die Breite darf nichts ueber die Laenge der Loesung verraten - sie waechst
// erst mit dem, was das Kind selbst tippt.
ok('Die Breite verraet die Loesung nicht',
   str_contains($js, 'function fitField') && str_contains($js, 'input.value.length'));

// Die Karte um den Satz ist weg: Rahmen, Schatten und Polsterung kosteten
// ueber der Tastatur rund 90px, und der Satz stand in einem scrollenden Kasten.
ok('Der Satz steht frei, ohne Karte drumherum',
   preg_match('/^\.cloze \{/m', $css) !== 1);

section('Abstände vor Satzzeichen');

require_once __DIR__ . '/../lib/punctuation.php';

// Der Anlass: "Salut !" sah nach einem Fehler des Modells aus, ist aber
// korrektes Französisch - vor ! ? : ; steht dort ein Leerzeichen, in den
// anderen Schulsprachen nicht. Pauschales Putzen wäre also kein Fix gewesen,
// sondern ein neuer Fehler.
foreach ([
    ['Salut!',           'fr',  'Salut !',        'fehlendes Leerzeichen kommt hinzu'],
    ['Salut  !',         'fr',  'Salut !',        'doppelter Abstand wird einer'],
    ['Salut !',          'fr',  'Salut !',        'richtig Gesetztes bleibt unberührt'],
    ['Comment?',         'fr',  'Comment ?',      'Fragezeichen ebenso'],
    ['Oui: bien',        'fr',  'Oui : bien',     'Doppelpunkt ebenso'],
    ['Bonne nuit .',     'fr',  'Bonne nuit.',    'vor dem Punkt steht auch im Französischen keines'],
    ['Merci , Madame!',  'fr',  'Merci, Madame !', 'Komma eng, Ausrufezeichen weit'],
    ['Gute Nacht !',     'de',  'Gute Nacht!',    'im Deutschen fällt es weg'],
    ['Hej !',            'da',  'Hej!',           'im Dänischen auch'],
    ['Hello !',          'en',  'Hello!',         'im Englischen auch'],
    ['Salve !',          'la',  'Salve!',         'im Lateinischen auch'],
    ['Salut !',          null,  'Salut!',         'ohne Kürzel gilt die enge Schreibweise'],
] as [$roh, $sprache, $soll, $was]) {
    ok($was, punctuation_fix($roh, $sprache) === $soll,
       sprintf('"%s" (%s) ergab "%s", erwartet "%s"',
               $roh, $sprache ?? '-', punctuation_fix($roh, $sprache), $soll));
}

// Eine Uhrzeit ist kein Satzzeichen.
ok('Der Doppelpunkt zwischen Ziffern bleibt eng',
   punctuation_fix('Il est 10:30', 'fr') === 'Il est 10:30',
   punctuation_fix('Il est 10:30', 'fr'));

ok('Die Lücke bleibt unberührt',
   punctuation_fix('Merci, {} !', 'fr') === 'Merci, {} !',
   punctuation_fix('Merci, {} !', 'fr'));

ok('Geschützte Leerzeichen werden mitgenommen',
   punctuation_fix("Salut\u{00A0}!", 'fr') === 'Salut !',
   punctuation_fix("Salut\u{00A0}!", 'fr'));

// Ein zweiter Durchlauf darf nichts mehr verändern - sonst wanderte der Text
// bei jedem Klick auf den Knopf weiter.
$einmal = punctuation_fix('Salut!', 'fr');
ok('Zweimal angewandt kommt dasselbe heraus',
   punctuation_fix($einmal, 'fr') === $einmal, $einmal);

section('Abstand vor Satzzeichen gilt nicht als Fehler');

// Was das Kind tippt, soll an dieser Stelle nie darüber entscheiden, ob die
// Antwort zählt - und auch keinen Schreibweise-Hinweis auslösen.
foreach ([
    ['Salut!',                  'Salut !',                  'Leerzeichen fehlt'],
    ['Salut !',                 'Salut!',                   'Leerzeichen zu viel'],
    ['Salut  !',                'Salut !',                  'zwei Leerzeichen'],
    ["Comment tu t'appelles?",  "Comment tu t'appelles ?",  'ganze Frage'],
    ['Gute Nacht !',            'Gute Nacht!',              'andersherum'],
] as [$getippt, $erwartet, $was]) {
    $r = answer_check($getippt, $erwartet);
    ok("Zählt voll als richtig: $was", $r['correct'] && $r['exact'], json_encode($r));
}

// Es bleibt aber ein Vergleich - der Abstand entschuldigt nichts anderes.
ok('Ein anderes Wort bleibt falsch',
   !answer_check('Bonjour !', 'Salut !')['correct']);

section('Wiederverbindung zur Datenbank');

// Der echte Fehlerfall vom Server: Während des minutenlangen KI-Aufrufs
// schliesst MySQL die untätige Verbindung. Hier wird sie absichtlich
// abgeschossen - genau das tut wait_timeout auch.
require_once __DIR__ . '/../lib/db.php';

$vorher = (int) qv('SELECT CONNECTION_ID()');
ok('Verbindung steht', $vorher > 0);

$c      = cfg('db');
$zweite = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s', $c['host'], (int) $c['port'], $c['name']),
    $c['user'], $c['pass'],
);
$zweite->exec('KILL ' . $vorher);
usleep(300000);

$tot = false;
try {
    db()->query('SELECT 1');
} catch (Throwable $e) {
    $tot = true;
}
ok('Die Verbindung ist wirklich tot', $tot, 'Abschuss hat nicht gewirkt');

db_ensure();
$nachher = (int) qv('SELECT CONNECTION_ID()');
ok('db_ensure() baut neu auf', $nachher > 0 && $nachher !== $vorher,
   "vorher $vorher, nachher $nachher");
ok('Und es lässt sich wieder abfragen', (int) qv('SELECT COUNT(*) FROM users') >= 0);

$id = (int) qv('SELECT CONNECTION_ID()');
db_ensure();
ok('Gesunde Verbindung bleibt bestehen', (int) qv('SELECT CONNECTION_ID()') === $id);

ok('wait_timeout ist grosszügig gesetzt',
   (int) qv('SELECT @@SESSION.wait_timeout') >= 600,
   (string) qv('SELECT @@SESSION.wait_timeout'));

echo "\n" . str_repeat('-', 52) . "\n";
printf("%d bestanden, %d fehlgeschlagen\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
