<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/progress.php';
require_once __DIR__ . '/../lib/streak.php';
require_once __DIR__ . '/../lib/meldungen.php';
require_once __DIR__ . '/../lib/tts.php';

/*
 * Alles auf einmal - und alles auf einmal zurück.
 *
 * Bis hierher holte die App jede Frage einzeln: Vokabel ziehen, Ablenker
 * würfeln, Antwort einschicken, nächste Frage. Beim Üben sind das drei bis
 * vier Aufrufe je Wort, und jeder davon ist eine Runde übers Netz, auf die
 * ein Kind wartet. Im Schulhaus-WLAN war das spürbar, und ein einziger
 * Aussetzer mitten in der Runde wurde zu „Bist du online?".
 *
 * Also andersherum: einmal alles holen, was dieses Kind zum Üben braucht,
 * und dann ohne Netz arbeiten. Zurück gehen nur die Antworten, als Strom
 * von Ereignissen.
 *
 * DASS ES EIN EREIGNISSTROM IST, IST DER GANZE TRICK. record_answer() ist
 * eine reine Funktion aus (bisheriger Stand, richtig/falsch): Wer dieselben
 * Antworten in derselben Reihenfolge nachspielt, bekommt denselben Stand
 * heraus. Es gibt deshalb nichts zusammenzuführen - kein "wessen Zahl
 * gilt", kein Abgleich zweier Zustände. Zwei Geräte, die abwechselnd üben,
 * ergeben genau das, was auch online herausgekommen wäre.
 *
 * Was NICHT hierher gehört: das Einlesen, das Verwalten, das Freigeben. Das
 * sind Entscheidungen, keine Übungen, und die trifft man am Schreibtisch.
 *
 * Und was das Kind dabei sieht: Die richtige Antwort liegt jetzt im
 * Browser. Das ist bewusst so - es ist eine Lernhilfe, keine Klassenarbeit,
 * und wer sich die Antwort heraussucht, statt sie zu lernen, betrügt
 * niemanden ausser sich selbst.
 */

require_api_request();
$user = require_user();
$uid  = (int) $user['id'];

/** Mehr als das nimmt ein Stapel nicht an - ein Nachmittag Üben ist weniger. */
const MAX_EVENTS = 500;

/** So lange bleibt eine Quittung liegen. Was so lange nicht ankam, kommt nicht mehr. */
const RECEIPT_KEEP_DAYS = 14;

switch (action()) {
    case 'get':
        /*
         * Die Kurse dieses Kontos - über die Mitgliedschaft, nicht über
         * "wer hat es angelegt": Angelegt hat die Lehrkraft, gelernt wird
         * von der Klasse.
         */
        $sprachen = qa(
            'SELECT l.id, l.name, l.flag_emoji, l.code,
                    co.id AS course_id, co.name AS course_name
               FROM languages l
               JOIN courses co       ON co.language_id = l.id
               JOIN course_members m ON m.course_id = co.id
              WHERE m.user_id = ?
              ORDER BY l.name',
            [$uid],
        );

        /*
         * Zwei Kacheln "Englisch" nebeneinander bekommen den Kursnamen -
         * dieselbe Regel wie in api/languages.php, und aus demselben Grund:
         * Wer Englisch in der 5B und in der 6A hat, muss sie unterscheiden
         * können - und legt ein Kind selbst eine Sprache an, heisst sein
         * Kurs nach ihm.
         */
        $wieOft = [];
        foreach ($sprachen as $r) {
            $wieOft[$r['name']] = ($wieOft[$r['name']] ?? 0) + 1;
        }

        $sprachIds = [];
        foreach ($sprachen as &$r) {
            if (($wieOft[$r['name']] ?? 0) > 1 && ($r['course_name'] ?? '') !== '') {
                $r['name'] = $r['course_name'];
            }
            unset($r['course_name']);
            $r['id'] = (int) $r['id'];
            /*
             * Die Kennung des Kurses - nur fuer eine Lehrkraft. Sie braucht
             * sie fuer den Weg zurueck: Der Hinweis "So sieht deine Klasse
             * das" traegt einen Knopf in die Verwaltung, und der soll auf
             * genau diesen Kurs zeigen. Einem Kind sagt die Zahl nichts.
             */
            $r['course_id'] = user_is_teacher($user) ? (int) $r['course_id'] : null;
            // Ob es eine Stimme gibt - ohne sie (Latein) gibt es kein "Hören".
            $r['h'] = tts_stimme($r['code'] ?? null) === null ? 0 : 1;
            $sprachIds[] = $r['id'];
        }
        unset($r);

        if ($sprachIds === []) {
            json_out(bundle_leer());
        }

        $platz = implode(',', array_fill(0, count($sprachIds), '?'));

        /*
         * Nur Freigegebenes, und zwar für alle - auch für eine Lehrkraft.
         * In der App soll sie genau das sehen, was ihre Klasse sieht; alles
         * zu sehen ist Sache des Lehrkraft-Bereichs.
         */
        $einheiten = qa(
            "SELECT u.id, u.language_id, u.title, u.released_position,
                    u.sentences_status
               FROM units u
               JOIN courses co ON co.id = u.course_id
               JOIN course_members m ON m.course_id = co.id AND m.user_id = ?
              WHERE u.language_id IN ($platz) AND " . unit_visible_sql('u') . "
              ORDER BY u.position, u.id",
            array_merge([$uid], $sprachIds),
        );

        $einheitIds = array_map(static fn (array $u): int => (int) $u['id'], $einheiten);

        $vokabeln = [];
        $saetze   = [];
        if ($einheitIds !== []) {
            $ep = implode(',', array_fill(0, count($einheitIds), '?'));

            /*
             * Kurze Schlüssel, weil das hier in den Speicher des Geräts
             * geht und als Text dort liegt: "term_foreign" je Vokabel
             * dreihundertmal ist ein Kilobyte Feldname.
             */
            foreach (qa(
                "SELECT v.id, v.unit_id, v.term_foreign, v.term_native, v.word_type
                   FROM vocab v
                   JOIN units u ON u.id = v.unit_id
                  WHERE v.unit_id IN ($ep) AND v.position < u.released_position
                  ORDER BY v.unit_id, v.position",
                $einheitIds,
            ) as $v) {
                $vokabeln[] = [
                    'i' => (int) $v['id'],
                    'u' => (int) $v['unit_id'],
                    'f' => (string) $v['term_foreign'],
                    'n' => (string) $v['term_native'],
                    // Die Wortart - "Einsetzen" nimmt die falschen Wörter
                    // aus derselben (vorrat.js, einsetzAblenker()).
                    't' => (string) ($v['word_type'] ?? ''),
                ];
            }

            foreach (qa(
                "SELECT s.vocab_id, s.id, s.native_text, s.foreign_text, s.answer,
                        a.hash AS audio, l.code AS sprache
                   FROM sentences s
                   JOIN vocab v ON v.id = s.vocab_id
                   JOIN units u ON u.id = v.unit_id
                   JOIN languages l ON l.id = u.language_id
                   LEFT JOIN sentence_audio a ON a.sentence_id = s.id
                  WHERE v.unit_id IN ($ep) AND v.position < u.released_position
                  ORDER BY s.vocab_id, s.id",
                $einheitIds,
            ) as $s) {
                $satz = [
                    'i' => (int) $s['id'],
                    'v' => (int) $s['vocab_id'],
                    'n' => (string) $s['native_text'],
                    'f' => (string) $s['foreign_text'],
                    'a' => (string) $s['answer'],
                ];
                /*
                 * Die Aufnahme, wenn sie noch zum Satz passt - ihr Kurzzeichen
                 * steht in der Adresse (api/audio.php?h=). Wurde der Satz im
                 * Admin verbessert, passt sie nicht mehr, und der Satz zählt
                 * für "Hören" erst wieder, wenn die neue da ist.
                 */
                $stimme = tts_stimme($s['sprache'] ?? null);
                if ($stimme !== null && $s['audio'] !== null
                    && $s['audio'] === tts_hash(tts_satztext($satz['f'], $satz['a']), $stimme['name'])) {
                    $satz['h'] = (string) $s['audio'];
                }
                $saetze[] = $satz;
            }
        }

        /*
         * Der eigene Lernstand. Nur der eigene - ein Kind sieht seinen
         * Stand, und niemand sonst sieht ihn hier.
         */
        $stand = [];
        foreach (qa(
            'SELECT p.vocab_id, p.mode, p.streak, p.correct_count, p.wrong_count,
                    p.known_at IS NOT NULL AS gekonnt
               FROM progress p
               JOIN vocab v ON v.id = p.vocab_id
               JOIN units u ON u.id = v.unit_id
               JOIN courses co ON co.id = u.course_id
               JOIN course_members m ON m.course_id = co.id AND m.user_id = ?
              WHERE p.user_id = ?',
            [$uid, $uid],
        ) as $p) {
            $stand[] = [
                'v' => (int) $p['vocab_id'],
                'm' => (string) $p['mode'],
                's' => (int) $p['streak'],
                'c' => (int) $p['correct_count'],
                'w' => (int) $p['wrong_count'],
                'k' => (int) $p['gekonnt'],
            ];
        }

        $daten = [
            'ok'        => true,
            'geholt'    => time(),
            'schwelle'  => KNOWN_THRESHOLD,
            'sprachen'  => $sprachen,
            'einheiten' => array_map(static fn (array $u): array => [
                'i' => (int) $u['id'],
                'l' => (int) $u['language_id'],
                't' => (string) $u['title'],
                // Ob die Lueckensaetze gerade entstehen. Ohne das saehe ein
                // Kind kurz nach dem Einlesen "keine Saetze" statt "wird
                // gerade gemacht" - und suchte den Fehler bei sich.
                'z' => (string) ($u['sentences_status'] ?? ''),
            ], $einheiten),
            'vokabeln'  => $vokabeln,
            'saetze'    => $saetze,
            'stand'     => $stand,
            'serie'     => streak_stand($uid),
        ];

        json_out($daten);

    case 'push':
        require_post();
        $b   = json_body();
        $roh = $b['ereignisse'] ?? null;

        if (!is_array($roh)) {
            json_fail('Es fehlt die Liste der Antworten.');
        }
        if (count($roh) > MAX_EVENTS) {
            json_fail('Höchstens ' . MAX_EVENTS . ' Antworten auf einmal.');
        }

        /*
         * Welche Vokabeln dieses Konto überhaupt beantworten darf.
         *
         * Einmal geholt statt je Ereignis geprüft: Ein Stapel von
         * zweihundert Antworten wären sonst zweihundert Abfragen. Geprüft
         * wird trotzdem jede einzelne - eine untergeschobene Kennung darf
         * keinen Lernstand an einer fremden Vokabel anlegen.
         */
        $erlaubt = [];
        foreach (qa(
            'SELECT v.id
               FROM vocab v
               JOIN units u ON u.id = v.unit_id
               JOIN courses co ON co.id = u.course_id
               JOIN course_members m ON m.course_id = co.id AND m.user_id = ?
              WHERE v.position < u.released_position',
            [$uid],
        ) as $v) {
            $erlaubt[(int) $v['id']] = true;
        }

        /*
         * Und die Einheiten, die dieses Konto zuruecksetzen darf - dieselbe
         * Frage wie bei den Vokabeln, nur eine Ebene hoeher. "Noch einmal
         * ueben" gehoert in denselben Strom wie die Antworten: Wer es ohne
         * Netz drueckt und danach weiteruebt, haette sonst einen Stapel
         * Antworten auf einen Stand, den der Server noch gar nicht geloescht
         * hat - und die Reihenfolge waere dahin.
         */
        $erlaubteEinheiten = [];
        foreach (qa(
            'SELECT u.id
               FROM units u
               JOIN courses co ON co.id = u.course_id
               JOIN course_members m ON m.course_id = co.id AND m.user_id = ?',
            [$uid],
        ) as $u) {
            $erlaubteEinheiten[(int) $u['id']] = true;
        }

        $genommen = 0;
        $doppelt  = 0;
        $fremd    = 0;

        foreach ($roh as $e) {
            if (!is_array($e)) {
                continue;
            }
            $kennung = trim((string) ($e['e'] ?? ''));
            $art     = (string) ($e['k'] ?? 'antwort');
            $vocabId = (int) ($e['v'] ?? 0);
            $modus   = (string) ($e['m'] ?? '');
            $richtig = !empty($e['r']);
            /*
             * Der Tag, an dem geantwortet wurde - vom Geraet, nicht von
             * hier. Wer Montag im Zug uebt und Mittwoch wieder online ist,
             * soll den Montag bekommen. streak_tag_pruefen() stutzt ihn auf
             * ein glaubhaftes Mass; aeltere Fassungen der App schicken gar
             * keinen, dann gilt heute.
             */
            $tag     = streak_tag_pruefen($e['d'] ?? null);

            if ($kennung === '' || mb_strlen($kennung) > 36) {
                continue;
            }

            if ($art === 'reset') {
                $unitId = (int) ($e['u'] ?? 0);
                if ($modus !== '' && !in_array($modus, MODES, true)) {
                    continue;
                }
                if (!isset($erlaubteEinheiten[$unitId])) {
                    $fremd++;
                    continue;
                }
                if (!bundle_quittung($uid, $kennung)) {
                    $doppelt++;
                    continue;
                }
                reset_unit_progress($unitId, $uid, $modus === '' ? null : $modus);
                $genommen++;
                continue;
            }

            /*
             * Freies Ueben: zaehlt den Tag, ruehrt den Lernstand nicht an.
             *
             * Das ist der ganze Unterschied zu einer gewoehnlichen Antwort.
             * Geuebt wird dort alles, auch was laengst sitzt, und ein Fehler
             * beim lockeren Wiederholen soll keine Serie einreissen, die
             * ueber Wochen entstanden ist. "Gekonnt" bleibt die Aussage der
             * strukturierten Uebung.
             *
             * Die Vokabel kommt trotzdem mit: An ihr prueft dieselbe
             * Schranke wie sonst, ob dieses Konto ueberhaupt antworten darf.
             */
            if ($art === 'frei') {
                if (!isset($erlaubt[$vocabId])) {
                    $fremd++;
                    continue;
                }
                if (!bundle_quittung($uid, $kennung)) {
                    $doppelt++;
                    continue;
                }
                streak_verbuchen($uid, $tag, $richtig, false);
                $genommen++;
                continue;
            }

            /*
             * "Stimmt hier etwas nicht?" - aus jeder Uebung.
             *
             * Im selben Strom wie die Antworten, weil ohne Netz geuebt wird:
             * Wer im Zug meldet, soll nicht "Bist du online?" lesen, und die
             * Meldung soll nicht verloren sein, bloss weil gerade keines da
             * war. Dieselbe Schranke wie bei einer Antwort - melden kann
             * man nur, was man auch ueben darf.
             */
            if ($art === 'melden') {
                if (!isset($erlaubt[$vocabId])) {
                    $fremd++;
                    continue;
                }
                if (!bundle_quittung($uid, $kennung)) {
                    $doppelt++;
                    continue;
                }
                if (meldung_aufnehmen($uid, $vocabId, (int) ($e['s'] ?? 0),
                                      mb_substr((string) ($e['t'] ?? ''), 0, 128))) {
                    $genommen++;
                } else {
                    $fremd++;
                }
                continue;
            }

            if (!in_array($modus, MODES, true)) {
                continue;
            }
            if (!isset($erlaubt[$vocabId])) {
                $fremd++;
                continue;
            }

            /*
             * Die Quittung zuerst. Ist die Kennung schon da, war dieser
             * Stapel bereits hier - dann wurde die Antwort unterwegs
             * verloren, nicht die Anfrage, und noch einmal zu zählen wäre
             * schlimmer, als gar nicht zu zählen.
             */
            if (!bundle_quittung($uid, $kennung)) {
                $doppelt++;
                continue;
            }

            record_answer($uid, $vocabId, $modus, $richtig, $tag);
            $genommen++;
        }

        // Alte Quittungen wegräumen - selten, damit es nicht jeden Stapel kostet.
        if (random_int(1, 50) === 1) {
            q('DELETE FROM answer_receipts WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
              [RECEIPT_KEEP_DAYS]);
        }

        /*
         * Und der frische Stand zurück - das Gerät hat ihn zwar selbst
         * mitgerechnet, aber die Zahl vom Server ist die, die gilt. Sie
         * enthält auch, was ein zweites Gerät beigetragen hat.
         */
        $stand = [];
        foreach (qa(
            'SELECT p.vocab_id, p.mode, p.streak, p.correct_count, p.wrong_count,
                    p.known_at IS NOT NULL AS gekonnt
               FROM progress p
              WHERE p.user_id = ?',
            [$uid],
        ) as $p) {
            $stand[] = [
                'v' => (int) $p['vocab_id'],
                'm' => (string) $p['mode'],
                's' => (int) $p['streak'],
                'c' => (int) $p['correct_count'],
                'w' => (int) $p['wrong_count'],
                'k' => (int) $p['gekonnt'],
            ];
        }

        json_out([
            'ok'       => true,
            'genommen' => $genommen,
            'doppelt'  => $doppelt,
            'fremd'    => $fremd,
            'stand'    => $stand,
            // Die Serie rechnet das Geraet zwar selbst mit, aber die Zahl
            // vom Server ist die, die gilt - sie kennt auch den Nachmittag
            // auf dem Tablet der Schule.
            'serie'    => streak_stand($uid),
        ]);

    default:
        json_fail('Unbekannte Aktion.', 404);
}

/**
 * Die Quittung für ein Ereignis - true, wenn es neu ist.
 *
 * INSERT IGNORE statt einer Abfrage davor: Zwei Stapel, die gleichzeitig
 * ankommen, kämen sonst beide durch. Der eindeutige Schlüssel entscheidet,
 * und rowCount() sagt, wer gewonnen hat.
 */
function bundle_quittung(int $uid, string $kennung): bool
{
    return q('INSERT IGNORE INTO answer_receipts (user_id, event_id) VALUES (?, ?)',
             [$uid, $kennung])->rowCount() === 1;
}

/** Ein Konto ohne Kurs bekommt ein leeres Bündel, keinen Fehler. */
function bundle_leer(): array
{
    return [
        'ok'        => true,
        'geholt'    => time(),
        'schwelle'  => KNOWN_THRESHOLD,
        'sprachen'  => [],
        'einheiten' => [],
        'vokabeln'  => [],
        'saetze'    => [],
        'stand'     => [],
        'serie'     => streak_leer(),
    ];
}
