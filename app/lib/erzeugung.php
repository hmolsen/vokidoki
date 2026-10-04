<?php
declare(strict_types=1);

require_once __DIR__ . '/html.php';
require_once __DIR__ . '/sentences.php';
require_once __DIR__ . '/tts.php';

/*
 * Was im Hintergrund entsteht - Lückensätze und Aufnahmen -, wie es die
 * Kursseite der Lehrkraft zeigt: zwei schmale Spalten, ein drehender Ring,
 * solange es entsteht, danach ein Haken.
 *
 * Bis hierher sah man davon nur etwas auf der Seite der Lerneinheit, und
 * dort mit einem Neuladen alle zehn Sekunden. Wer nach dem Freigeben
 * zurück in den Kurs ging, sah gar nichts - und wusste nicht, ob die Klasse
 * schon üben kann. Die Kursseite bekommt den Stand jetzt vom Server
 * geschickt (teacher/erzeugung.php), ohne neu zu laden.
 *
 * Der Stand wird hier gerechnet UND hier zu HTML: Die Seite zeigt ihn beim
 * ersten Aufbau, der Strom schickt dieselben Zellen fertig mit. So steht die
 * Darstellung an einer Stelle, nicht einmal in PHP und einmal in teacher.js.
 */

/**
 * Wie weit Sätze und Aufnahmen einer Lerneinheit sind.
 *
 * Je eines von:
 *   laeuft  entsteht gerade
 *   wartet  (nur Aufnahmen) kommt gleich - erst entstehen die Sätze
 *   fertig  alles da
 *   fehlt   nicht alles da, und gerade läuft nichts (Budget, Freikontingent)
 *   fehler  (nur Sätze) der letzte Lauf ist gescheitert
 *   leer    nichts zu tun: noch nichts freigegeben, noch kein Satz
 *   keine   (nur Aufnahmen) diese Sprache wird nicht gesprochen, oder die
 *           Aufnahmen sind abgeschaltet
 *
 * Ob gesprochen wird, sagt die Sperre von tts_nachtragen(): Sie hängt an der
 * Datenbankverbindung des Laufs und fällt mit ihm, auch wenn er abstürzt.
 * Ein eigener Zustand in der Tabelle - wie bei den Sätzen - bliebe nach
 * einem Absturz auf "läuft" stehen.
 *
 * @param array $unit Zeile aus units, mit released_count (course_units_list())
 * @return array{saetze:array{s:string,n:int,fehler:?string}, ton:array{s:string,n:int}}
 */
function erzeugung_stand(array $unit): array
{
    $id     = (int) $unit['id'];
    $frei   = (int) ($unit['released_count'] ?? 0);
    $status = (string) ($unit['sentences_status'] ?? '');
    $frisch = $unit['sentences_started_at'] !== null
           && time() - strtotime((string) $unit['sentences_started_at']) <= SENTENCE_STALE_AFTER;

    $fehlen = $frei > 0 ? vocab_without_sentences($id) : 0;
    $saetze = match (true) {
        $status === SENTENCE_RUNNING && $frisch => 'laeuft',
        $frei === 0                             => 'leer',
        $fehlen === 0                           => 'fertig',
        $status === SENTENCE_FAILED             => 'fehler',
        default                                 => 'fehlt',
    };

    $stimme = tts_stimme(tts_sprachcode($id));
    $offen  = 0;
    if ($stimme === null || !tts_aktiv()) {
        $ton = 'keine';
    } elseif ((int) (qv('SELECT IS_USED_LOCK(?) IS NOT NULL', ['vt-tts-' . $id]) ?? 0) === 1) {
        $ton = 'laeuft';
    } elseif ($saetze === 'laeuft') {
        $ton = 'wartet';
    } elseif ($frei === 0) {
        // Gesprochen werden die Sätze und die freigegebenen Vokabeln (fürs
        // Auswählen) - ohne Freigabe ist beides noch nicht dran.
        $ton = 'leer';
    } else {
        $offen = count(tts_offen($id, $stimme));
        $ton   = $offen === 0 ? 'fertig' : 'fehlt';
    }

    return [
        'saetze' => ['s' => $saetze, 'n' => $fehlen,
                     'fehler' => $saetze === 'fehler' ? ($unit['sentences_error'] ?? null) : null],
        'ton'    => ['s' => $ton, 'n' => $offen],
    ];
}

/**
 * Der Stand aller Lerneinheiten eines Kurses, mit den fertigen Zellen.
 *
 * @return array{einheiten: array<int, array{saetze:array{s:string,html:string}, ton:array{s:string,html:string}}>,
 *               laeuft: bool}
 */
function erzeugung_kurs(int $courseId): array
{
    $einheiten = [];
    $laeuft    = false;
    foreach (course_units_list($courseId) as $u) {
        $st = erzeugung_stand($u);
        foreach (['saetze', 'ton'] as $art) {
            $laeuft = $laeuft || in_array($st[$art]['s'], ['laeuft', 'wartet'], true);
        }
        $einheiten[(int) $u['id']] = [
            'saetze' => ['s' => $st['saetze']['s'],
                         'html' => erzeugung_zelle_html('saetze', $st['saetze'], (int) $u['id'])],
            'ton'    => ['s' => $st['ton']['s'],
                         'html' => erzeugung_zelle_html('ton', $st['ton'], (int) $u['id'])],
        ];
    }
    return ['einheiten' => $einheiten, 'laeuft' => $laeuft];
}

/**
 * Der Inhalt einer der beiden Zellen.
 *
 * Fehlt etwas, ist das Ausrufezeichen ein Knopf: Ein Druck holt nach, was
 * fehlt (teacher/course.php, "nachholen"). Es kam vor, dass alles
 * freigegeben war und trotzdem Sätze oder Aufnahmen fehlten - ein Lauf war
 * am Budget oder am Freikontingent hängengeblieben -, und dann gab es keine
 * Freigabe mehr, die es noch einmal versucht hätte.
 *
 * @param int $unitId die Lerneinheit - für den Knopf
 */
function erzeugung_zelle_html(string $art, array $st, int $unitId = 0): string
{
    $was = $art === 'saetze' ? 'Lückensätze' : 'Aufnahmen';
    $n   = (int) ($st['n'] ?? 0);

    [$klasse, $zeichen, $text] = match ($st['s']) {
        'laeuft' => ['laeuft', '', $was . ' entstehen gerade'],
        'wartet' => ['laeuft wartet', '', 'Aufnahmen kommen gleich nach den Lückensätzen'],
        'fertig' => ['fertig', '&#10003;', $was . ' sind fertig'],
        'fehlt'  => ['fehlt', '!', $art === 'saetze'
                        ? sprintf('%d %s ohne Lückensatz - antippen, um sie jetzt zu erzeugen',
                                  $n, $n === 1 ? 'Vokabel' : 'Vokabeln')
                        : sprintf('%d %s - antippen, um sie jetzt zu erzeugen',
                                  $n, $n === 1 ? 'Aufnahme fehlt' : 'Aufnahmen fehlen')],
        'fehler' => ['fehler', '!', 'Lückensätze: ' . ((string) ($st['fehler'] ?? '') ?: 'fehlgeschlagen')
                                    . ' - antippen, um es noch einmal zu versuchen'],
        'keine'  => ['keine', '&ndash;', 'Für diese Sprache gibt es keine Aufnahmen'],
        default  => ['', '', ''],
    };
    if ($klasse === '') {
        return '';
    }
    if (in_array($st['s'], ['fehlt', 'fehler'], true) && $unitId > 0) {
        return sprintf('<button class="erz %s" form="nachholen" name="nachholen" value="%d:%s"'
                       . ' title="%s" aria-label="%s">%s</button>',
                       $klasse, $unitId, $art, h($text), h($text), $zeichen);
    }
    return sprintf('<span class="erz %s" role="img" title="%s" aria-label="%s">%s</span>',
                   $klasse, h($text), h($text), $zeichen);
}
