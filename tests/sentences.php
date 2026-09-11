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

section('Kategorien ohne Lückensatz');

ok('Aussage, Frage und Interjektion werden übersprungen',
   SENTENCE_SKIP_TYPES === ['aussage', 'frage', 'interjektion'],
   implode(', ', SENTENCE_SKIP_TYPES));

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
