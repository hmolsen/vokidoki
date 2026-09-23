<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Die Serie - an wie vielen Tagen hintereinander ein Kind gelernt hat.
 *
 * Die Regel steht wie bei den Vokabeln nur an einer Stelle, und sie ist in
 * zwei Haelften gebaut: streak_rechnen() rechnet aus einer Liste von Tagen,
 * ohne Datenbank und ohne Uhr, und alles darum herum besorgt nur die Tage.
 * Genau deshalb laesst sie sich pruefen - tests/faelle/serien.json haelt
 * diese Fassung und die in vorrat.js zusammen, so wie es die Fallsammlung
 * fuer die Antworten schon tut.
 *
 * WELCHER TAG GEZAEHLT WIRD, ENTSCHEIDET DAS GERAET, NICHT DER SERVER.
 * Die App uebt ohne Netz und schickt ihre Antworten spaeter am Stueck; wer
 * Montag im Zug lernt und Mittwoch wieder online ist, haette sonst zwei
 * verpasste Tage und eine tote Serie. Jede Antwort bringt darum ihren
 * eigenen Tag mit. Dass sich der stellen laesst, indem jemand die Uhr des
 * Tablets verstellt, ist bekannt und in Kauf genommen: Es ist eine
 * Lernhilfe, keine Klassenarbeit.
 */

/**
 * Ab so vielen richtigen Antworten zaehlt ein Tag auch ohne neue Vokabel.
 *
 * Ohne diese zweite Tuer koennte eine Serie aus einem Grund sterben, an dem
 * das Kind nichts aendern kann: Wer alles Freigegebene kann, bekommt vom
 * Quiz keine neue Vokabel mehr vorgelegt - es zieht nur, was noch nicht
 * gekonnt ist. Gibt die Lehrkraft eine Woche lang nichts frei, verloere
 * eine ganze Klasse am selben Tag ihre Serien, obwohl alle taeglich geuebt
 * haben. Wiederholen ist dann das Beste, was ein Kind tun kann, und dafuer
 * soll es den Tag bekommen.
 */
const STREAK_UEBUNG_MIN = 10;

/**
 * So viele Tage Abstand haelt eine Serie noch aus.
 *
 * Zwei heisst: Ein ausgelassener Tag wird verziehen, zwei nicht. Wer Montag
 * lernt, darf Dienstag aussetzen und Mittwoch weitermachen - die Serie
 * laeuft weiter. Wer Dienstag UND Mittwoch aussetzt, faengt Donnerstag bei
 * null an. Ein Tag Pause ist ein Ausflug, zwei sind ein Abbruch.
 */
const STREAK_ABSTAND_MAX = 2;

/**
 * In dieser Zeitzone beginnt ein Tag.
 *
 * Ausdruecklich hier und nicht per date_default_timezone_set(): Die
 * Zeitstempel in der Datenbank schreibt MySQL mit NOW(), also in SEINER
 * Zone, und der Admin-Bereich zeigt sie mit date() an. Stellte man PHP um
 * und MySQL nicht, stuenden dort ab sofort Uhrzeiten, die es nie gab. Die
 * Serie rechnet darum mit ihrer eigenen Zone und laesst den Rest in Ruhe -
 * sie schreibt ihre Tage ohnehin als fertiges Datum und nie mit NOW().
 */
const STREAK_ZONE = 'Europe/Berlin';

/** So weit zurueck wird gelesen. Laenger wird keine Serie. */
const STREAK_HISTORIE_TAGE = 400;

/**
 * So viele Monate zurueck laesst sich der Kalender im Konto blaettern.
 *
 * Zwoelf plus den laufenden. Weiter zurueck haelt die Tabelle auch gar
 * nicht: Was aelter ist, wird weggeraeumt (siehe streak_aufraeumen). Ein
 * Kalender, in dem man in leere Monate blaettern kann, verspricht etwas,
 * das nicht da ist.
 */
const STREAK_KALENDER_MONATE = 12;

/**
 * Die Zustaende, in denen nicht die Abfrage schuld ist, sondern das Schema.
 *
 * 42S02 heisst "Tabelle gibt es nicht", 42S22 "Spalte gibt es nicht".
 */
const STREAK_SCHEMA_FEHLT = ['42S02', '42S22'];

/**
 * Die Serie haelt still, solange ihr Schema noch nicht da ist.
 *
 * DAS IST KEIN VORSICHTSHALBER-FANGEN, SONDERN EIN BEKANNTES FENSTER. Der
 * Code geht per FTP sofort live, die Schemaaenderung laeuft aber erst, wenn
 * jemand den Selbsttest im Admin-Bereich aufruft - so ist es hier
 * ausdruecklich gewollt, eine Schemaaenderung ist eine Entscheidung. Zwischen
 * beidem liegen Minuten bis Tage, und in dieser Zeit gibt es learn_days und
 * users.streak_best noch nicht.
 *
 * Ohne das faengt in genau diesem Fenster JEDE angemeldete Seite und JEDE
 * beantwortete Vokabel einen Fehler - wegen eines Abzeichens. Eine Serie ist
 * die harmloseste Sache in dieser Anwendung; sie darf nie der Grund sein,
 * warum ein Kind nicht ueben kann. Also: Fehlt das Schema, gibt es eben keine
 * Serie, und zwar lautlos. Jeder andere Fehler fliegt weiter.
 *
 * Gemerkt wird es fuer die Dauer der Anfrage: Sonst fragte jede einzelne
 * Antwort eines Stapels noch einmal nach.
 */
function streak_stillhalten(?Throwable $fehler = null): bool
{
    static $aus = false;

    if ($fehler === null) {
        return $aus;
    }
    if (!$fehler instanceof PDOException
        || !in_array((string) ($fehler->errorInfo[0] ?? ''), STREAK_SCHEMA_FEHLT, true)) {
        throw $fehler;
    }

    /*
     * Und jetzt nachsehen, ob wirklich das Schema fehlt.
     *
     * Der Zustandscode allein reicht nicht: "Spalte gibt es nicht" sagt auch
     * ein Schreibfehler in einem Spaltennamen, und der gehoerte dann zu den
     * Fehlern, die niemand je zu sehen bekaeme - die Serie waere einfach
     * stumm, und keiner wuesste warum. Diese eine Abfrage laeuft nur im
     * Fehlerfall und nur einmal je Anfrage.
     */
    if (!streak_schema_fehlt()) {
        throw $fehler;
    }

    $aus = true;
    return true;
}

/** Fehlt die Tabelle oder die Spalte wirklich noch? */
function streak_schema_fehlt(): bool
{
    $da = (int) qv(
        "SELECT (SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'learn_days')
              + (SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                    AND COLUMN_NAME = 'streak_best')"
    );

    return $da < 2;
}

/** Der heutige Tag, in der Zone oben. */
function streak_heute(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(STREAK_ZONE)))->format('Y-m-d');
}

/** Abstand zweier Tage in Tagen. Negativ, wenn der zweite vor dem ersten liegt. */
function streak_abstand(string $a, string $b): int
{
    $zone = new DateTimeZone(STREAK_ZONE);
    $von  = new DateTimeImmutable($a . ' 00:00:00', $zone);
    $bis  = new DateTimeImmutable($b . ' 00:00:00', $zone);

    return (int) $von->diff($bis)->format('%r%a');
}

/**
 * Der Tag, den eine Antwort mitbringt - auf ein glaubhaftes Mass gestutzt.
 *
 * Ein Tag in der Zukunft waere eine falsch gestellte Uhr oder der Versuch,
 * sich Vorrat anzulegen; ein Tag von vor Monaten kaeme aus einer
 * Warteschlange, die so lange niemand losgeworden ist. Beides wird nicht
 * abgelehnt, sondern auf heute gezogen: Die Antwort selbst war ja richtig,
 * und sie deswegen wegzuwerfen waere die haertere Strafe fuer das kleinere
 * Problem.
 */
function streak_tag_pruefen(mixed $roh, ?string $heute = null): string
{
    $heute = $heute ?? streak_heute();

    if (!is_string($roh) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $roh) !== 1) {
        return $heute;
    }

    // Kein 2026-02-31: checkdate() fragt den Kalender, das Muster oben nicht.
    [$j, $m, $t] = array_map('intval', explode('-', $roh));
    if (!checkdate($m, $t, $j)) {
        return $heute;
    }

    $abstand = streak_abstand($roh, $heute);
    if ($abstand < 0 || $abstand > 14) {
        return $heute;
    }

    return $roh;
}

/**
 * Eine Antwort auf den Tag schreiben, an dem sie gegeben wurde.
 *
 * ON DUPLICATE KEY, anders als bei record_answer(): Dort gab es einen Grund
 * auszuweichen - die Indexlage wechselte gerade. Hier ist die Tabelle neu,
 * ihr Schluessel steht von Anfang an, und zwei Geraete, die im selben
 * Augenblick ihre Warteschlange loswerden, sollen sich nicht gegenseitig
 * ueberschreiben. Gezaehlt wird additiv, nicht gesetzt.
 */
function streak_verbuchen(int $userId, string $tag, bool $richtig, bool $neuGekonnt): void
{
    $gelernt = $neuGekonnt ? 1 : 0;
    $richtigN = $richtig ? 1 : 0;

    if ($gelernt === 0 && $richtigN === 0) {
        return;   // eine falsche Antwort bringt den Tag nicht weiter
    }
    if (streak_stillhalten()) {
        return;
    }

    try {
        q(
            'INSERT INTO learn_days (user_id, `day`, learned, correct)
                  VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE learned = learned + ?, correct = correct + ?',
            [$userId, $tag, $gelernt, $richtigN, $gelernt, $richtigN],
        );
    } catch (Throwable $e) {
        streak_stillhalten($e);
    }

    streak_aufraeumen();
}

/**
 * Zaehlt dieser Tag fuer die Serie?
 *
 * Entweder eine Vokabel neu gekonnt - das ist die eigentliche Regel - oder
 * ordentlich wiederholt, siehe STREAK_UEBUNG_MIN.
 */
function streak_tag_zaehlt(int $gelernt, int $richtig): bool
{
    return $gelernt >= 1 || $richtig >= STREAK_UEBUNG_MIN;
}

/**
 * Die Serie aus einer Liste von Tagen - ohne Datenbank, ohne Uhr.
 *
 * Herein kommt eine Liste aus ['day' => 'Y-m-d', 'learned' => n,
 * 'correct' => n] in beliebiger Reihenfolge, heraus kommen zwei Werte:
 *
 *   kette    Laenge der Serie an ihrem letzten zaehlenden Tag
 *   letzter  dieser Tag selbst, oder null
 *
 * Was davon auf dem Bildschirm steht, entscheidet streak_anzeige() - auf
 * beiden Seiten aus genau diesen zwei Werten. Das ist der Grund fuer die
 * Trennung: Die App kann die Kette gar nicht nachrechnen, sie kennt nur die
 * letzten dreissig Tage. Wissen muss sie nur, ob die Serie heute noch
 * steht, und dafuer reichen die zwei Werte und ein Blick auf den Kalender.
 */
function streak_rechnen(array $tage, string $heute): array
{
    $zaehlend = [];
    foreach ($tage as $t) {
        $tag = (string) ($t['day'] ?? '');
        if ($tag === '' || streak_abstand($tag, $heute) < 0) {
            continue;   // was in der Zukunft liegt, zaehlt heute noch nicht
        }
        if (streak_tag_zaehlt((int) ($t['learned'] ?? 0), (int) ($t['correct'] ?? 0))) {
            $zaehlend[] = $tag;
        }
    }

    if ($zaehlend === []) {
        return ['kette' => 0, 'letzter' => null];
    }

    rsort($zaehlend);   // der juengste zuerst

    $kette = 1;
    for ($i = 1, $n = count($zaehlend); $i < $n; $i++) {
        if (streak_abstand($zaehlend[$i], $zaehlend[$i - 1]) > STREAK_ABSTAND_MAX) {
            break;
        }
        $kette++;
    }

    return ['kette' => $kette, 'letzter' => $zaehlend[0]];
}

/**
 * Was das Abzeichen zeigt - aus Kette, letztem Tag und heute.
 *
 * Vier Lagen, und jede hat ihr Bild:
 *
 *   heute   Heute schon gelernt. Zahl gruen, Voki froh UND farbig.
 *   offen   Gestern gelernt, heute noch nicht. Zahl grau, Voki froh, aber grau.
 *   gefahr  Ein Tag ausgelassen. Die Zahl steht noch, aber Voki ist traurig
 *           und grau - das ist die Warnung: Heute nichts mehr, und sie ist weg.
 *   aus     Zwei Tage ausgelassen, oder noch nie gelernt. Null, Voki traurig.
 *
 * Farbe hat also nur, wer heute schon gelernt hat. Sie ist die Belohnung,
 * nicht die Grundeinstellung.
 *
 * Dieselbe Rechnung steht in vorrat.js, und sie muss dort stehen: Die App
 * zeigt das Abzeichen auch ohne Netz, und eine installierte App liegt
 * wochenlang im Hintergrund - zwischen dem letzten Abruf und dem Blick auf
 * den Bildschirm kann Mitternacht liegen.
 */
function streak_anzeige(int $kette, ?string $letzter, string $heute): array
{
    if ($letzter === null || $kette <= 0) {
        return ['zahl' => 0, 'lage' => 'aus', 'abstand' => null];
    }

    $abstand = streak_abstand($letzter, $heute);
    if ($abstand < 0) {
        $abstand = 0;   // die Uhr des Geraets laeuft vor; dann eben heute
    }
    if ($abstand > STREAK_ABSTAND_MAX) {
        return ['zahl' => 0, 'lage' => 'aus', 'abstand' => $abstand];
    }

    return [
        'zahl'    => $kette,
        'lage'    => $abstand === 0 ? 'heute' : ($abstand === 1 ? 'offen' : 'gefahr'),
        'abstand' => $abstand,
    ];
}

/**
 * Der ganze Stand eines Kindes - fuer die Huelle, das Buendel und das Konto.
 *
 * Die Bestmarke wird hier nachgezogen und nicht beim Verbuchen: Dort ist
 * eine Antwort eine von vielen im Stapel, und die Serie je Antwort
 * auszurechnen waere eine Abfrage je Antwort. Hier steht sie ohnehin schon
 * da, und geschrieben wird nur, wenn wirklich ein Rekord gefallen ist -
 * hoechstens einmal am Tag.
 */
function streak_stand(int $userId, bool $mitKalender = true): array
{
    if (streak_stillhalten()) {
        return streak_leer();
    }

    try {
        return streak_stand_lesen($userId, $mitKalender);
    } catch (Throwable $e) {
        streak_stillhalten($e);
        return streak_leer();
    }
}

/**
 * Der Teil, der wirklich in die Datenbank greift - siehe streak_stand().
 *
 * $mitKalender entscheidet ueber die Groesse der Antwort. Die Huelle
 * (index.php) braucht nur das Abzeichen und baekme sonst bei jedem
 * Seitenaufruf ein Jahr Kalender mit - sie wird nie zwischengespeichert.
 * Das Buendel holt ihn, denn dort gehoert er hin: Es liegt im Geraet, und
 * das Konto soll seinen Kalender auch ohne Netz zeigen.
 */
function streak_stand_lesen(int $userId, bool $mitKalender = true): array
{
    $heute = streak_heute();
    $zone  = new DateTimeZone(STREAK_ZONE);
    $von   = (new DateTimeImmutable($heute, $zone))
        ->modify('-' . STREAK_HISTORIE_TAGE . ' days')->format('Y-m-d');

    $tage = qa(
        'SELECT `day`, learned, correct
           FROM learn_days
          WHERE user_id = ? AND `day` >= ? AND `day` <= ?
          ORDER BY `day` DESC',
        [$userId, $von, $heute],
    );

    $serie = streak_rechnen($tage, $heute);
    $best  = (int) qv('SELECT streak_best FROM users WHERE id = ?', [$userId]);

    // Die Bestmarke gilt fuer die Serie, wie sie heute dasteht - eine tote
    // Kette von vorletzter Woche ist kein Rekord von heute.
    $jetzt = streak_anzeige($serie['kette'], $serie['letzter'], $heute)['zahl'];
    if ($jetzt > $best) {
        q('UPDATE users SET streak_best = ? WHERE id = ?', [$jetzt, $userId]);
        $best = $jetzt;
    }

    /*
     * Der Kalender: vom Ersten des Monats vor zwoelf Monaten bis heute.
     * Nicht "die letzten 400 Tage" - geblaettert wird in Monaten, und ein
     * halber Monat am Rand waere eine Luecke ohne Grund.
     */
    $kalender = [];
    if ($mitKalender) {
        $grenze = (new DateTimeImmutable($heute, $zone))
            ->modify('first day of this month')
            ->modify('-' . STREAK_KALENDER_MONATE . ' months')->format('Y-m-d');

        foreach ($tage as $t) {
            if ((string) $t['day'] < $grenze) {
                break;   // absteigend sortiert - ab hier ist alles aelter
            }
            $kalender[] = [
                'd' => (string) $t['day'],
                'l' => (int) $t['learned'],
                'c' => (int) $t['correct'],
            ];
        }
    }

    return [
        'kette'    => $serie['kette'],
        'letzter'  => $serie['letzter'],
        'best'     => $best,
        'schwelle' => STREAK_UEBUNG_MIN,
        'monate'   => STREAK_KALENDER_MONATE,
        /*
         * Bis hierher und nicht weiter zurueck: Vor dem Tag, an dem das
         * Konto entstand, gab es nichts zu ueben. Ein Kalender, der in
         * Monate blaettern laesst, in denen es das Kind noch gar nicht gab,
         * sieht aus wie ein Fehler.
         */
        'seit'     => (string) (qv('SELECT DATE(created_at) FROM users WHERE id = ?',
                                   [$userId]) ?? $heute),
        'heute'    => $heute,
        'tage'     => $kalender,
    ];
}

/** Ein leerer Stand - fuer ein Konto, das noch nie geuebt hat. */
function streak_leer(): array
{
    $heute = streak_heute();
    return [
        'kette'    => 0,
        'letzter'  => null,
        'best'     => 0,
        'schwelle' => STREAK_UEBUNG_MIN,
        'monate'   => STREAK_KALENDER_MONATE,
        'seit'     => $heute,
        'heute'    => $heute,
        'tage'     => [],
    ];
}

/**
 * Was aelter ist als der Kalender, wird weggeraeumt.
 *
 * Eine Zeile je Kind und Tag waechst langsam, aber sie waechst - und
 * gelesen wird davon nie etwas, das aelter ist als die Historie der Serie.
 * Selten und nebenbei, wie bei den Quittungen in api/bundle.php: Eine
 * eigene Aufraeumaufgabe waere eine Abhaengigkeit, die dieses Projekt nicht
 * hat (kein Cron, Aktualisierung per FTP).
 */
function streak_aufraeumen(): void
{
    if (random_int(1, 200) !== 1) {
        return;
    }

    try {
        q('DELETE FROM learn_days WHERE `day` < ?', [
            (new DateTimeImmutable(streak_heute(), new DateTimeZone(STREAK_ZONE)))
                ->modify('-' . STREAK_HISTORIE_TAGE . ' days')->format('Y-m-d'),
        ]);
    } catch (Throwable $e) {
        streak_stillhalten($e);
    }
}
