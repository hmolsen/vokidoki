<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/courses.php';
require_once __DIR__ . '/../lib/punctuation.php';
require_once __DIR__ . '/../lib/ai.php';
require_once __DIR__ . '/../lib/wordtypes.php';
require_once __DIR__ . '/../lib/sentences.php';
require_once __DIR__ . '/../lib/vocab.php';

require_api_request();
$user = require_user();
$uid  = (int) $user['id'];

/*
 * Die Fotos kommen nicht mehr hierher - ocr.js liest sie im Browser. Was
 * ankommt, ist Text, und die Seitenzahl nur fürs Kostenprotokoll.
 *
 * MAX_IMAGES bleibt die Grenze je Einlesen (views/bilder.js zeigt sie an).
 * MAX_TEXT_CHARS: Sechs dicht bedruckte Seiten sind etwa 15 000 Zeichen;
 * das Doppelte lässt Luft, verhindert aber, dass jemand ein Buch schickt.
 */
const MAX_IMAGES     = 6;
const MAX_TEXT_CHARS = 30000;

switch (action()) {
    case 'analyze':
        require_post();
        $b    = json_body();
        require_cap($user, CAP_IMPORT);
        $lang = view_language($user, body_int($b, 'language_id'));

        $text   = trim((string) ($b['text'] ?? ''));
        $seiten = max(1, (int) ($b['pages'] ?? 1));
        if ($text === '' || !preg_match('/\p{L}{2,}/u', $text)) {
            json_fail('Auf den Fotos war kein Text zu erkennen. Vielleicht noch einmal '
                      . 'näher heran und mit mehr Licht fotografieren?', 422);
        }
        if ($seiten > MAX_IMAGES) {
            json_fail('Höchstens ' . MAX_IMAGES . ' Fotos auf einmal.');
        }
        if (mb_strlen($text) > MAX_TEXT_CHARS) {
            json_fail('Das ist zu viel Text auf einmal. Bitte weniger Seiten einlesen.');
        }

        // Das Ordnen dauert bei mehreren Seiten länger als die üblichen
        // 30 Sekunden Standardlaufzeit - sonst bricht PHP mitten im Aufruf
        // ab, nachdem die Anfrage bereits bezahlt wurde.
        set_time_limit(300);

        // Budget- und Ratenprüfung vor dem API-Aufruf, damit ein
        // durchgereichtes iPhone keine offene Rechnung erzeugen kann.
        $blocked = budget_block_reason($uid);
        if ($blocked !== null) {
            json_fail($blocked, 429);
        }

        try {
            $result = analyze_vocab_text($text, $seiten, (string) $lang['name'], $user);
        } catch (KeyvaultException $e) {
            // Eigene Meldung, damit im Fehlerfall klar ist, wo es klemmt -
            // am Keyvault und nicht an den Fotos.
            error_log('[vokabeltrainer] Keyvault: ' . scrub_secrets($e->getMessage()));
            json_fail(
                'Der Schlüsseldienst ist gerade nicht erreichbar. '
                . 'Bitte später noch einmal versuchen.',
                503,
            );
        } catch (Throwable $e) {
            error_log('[vokabeltrainer] Ordnen der Vokabeln fehlgeschlagen: '
                      . scrub_secrets($e->getMessage()));
            json_fail(
                'Die Vokabeln konnten nicht geordnet werden. Bitte noch einmal versuchen.',
                502,
            );
        }

        if ($result['entries'] === []) {
            json_fail(
                'Im erkannten Text wurden keine Vokabeln gefunden. Vielleicht noch einmal '
                . 'näher heran und mit mehr Licht fotografieren?',
                422,
            );
        }

        json_out([
            'ok'      => true,
            'entries' => $result['entries'],
        ]);

    case 'save':
        require_post();
        $b     = json_body();
        require_cap($user, CAP_IMPORT);
        $lang  = edit_language($user, body_int($b, 'language_id'));

        /*
         * Zwei Ziele: eine neue Lerneinheit, oder eine vorhandene erweitern.
         *
         * Bisher gab es nur das erste, und zwar ohne Wahl - jedes Einlesen
         * legte eine neue an. Wer eine zweite Buchseite derselben Lektion
         * fotografierte, bekam "Unit 4" und "Unit 4 (2)" und musste beide
         * einzeln freigeben.
         *
         * Geprueft wird ueber load_unit_for_edit() (CAP_IMPORT und
         * Kursmitgliedschaft) UND dass die Einheit wirklich zu der Sprache
         * gehoert, die mitgeschickt wurde. Sonst liesse sich ueber eine
         * untergeschobene unit_id in eine fremde Lerneinheit schreiben.
         */
        $anhaengen = isset($b['unit_id']) ? body_int($b, 'unit_id') : 0;
        $ziel      = null;

        if ($anhaengen > 0) {
            $ziel = edit_unit($user, $anhaengen);
            if ((int) $ziel['language_id'] !== (int) $lang['id']) {
                json_fail('Diese Lerneinheit gehört zu einem anderen Kurs.', 403);
            }
        }

        $title = body_str($b, 'title', 128);
        if ($ziel === null && $title === '') {
            json_fail('Bitte einen Titel für die Lerneinheit angeben.');
        }

        $rows = $b['entries'] ?? null;
        if (!is_array($rows) || $rows === []) {
            json_fail('Es gibt nichts zu speichern.');
        }
        if (count($rows) > 500) {
            json_fail('Eine Lerneinheit kann höchstens 500 Vokabeln haben.');
        }

        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            // Abstände vor Satzzeichen gleich beim Einlesen richtigstellen -
            // das Modell trifft es nicht jedes Mal (punctuation_fix()).
            $f = punctuation_fix((string) ($row['foreign'] ?? ''), $lang['code'] ?? null);
            $n = punctuation_fix((string) ($row['native'] ?? ''), 'de');
            if ($f === '' || $n === '') {
                continue;
            }
            $note    = trim((string) ($row['note'] ?? ''));
            $pruefen = trim((string) ($row['correction'] ?? ''));
            $clean[] = [
                mb_substr($f, 0, 255),
                mb_substr($n, 0, 255),
                $note === '' ? null : mb_substr($note, 0, 255),
                // Die Wortart bestimmt das Modell; das Kind bekommt sie nicht
                // zu Gesicht und muss sie nicht prüfen.
                word_type_clean($row['word_type'] ?? null),
                // Was die KI berichtigt hat, solange es beim Prüfen niemand
                // angefasst hat - views/import.js lässt es dann weg.
                $pruefen === '' ? null : mb_substr($pruefen, 0, 255),
            ];
        }
        if ($clean === []) {
            json_fail('Keine vollständigen Vokabelpaare gefunden.');
        }

        $paare = [];
        foreach ($clean as [$f, $n, $note, $type, $pruefen]) {
            $paare[] = ['foreign' => $f, 'native' => $n,
                        'note' => $note, 'word_type' => $type, 'correction' => $pruefen];
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($ziel !== null) {
                $unitId = (int) $ziel['id'];
                /*
                 * Anhaengen ruehrt released_position NICHT an. Die neuen
                 * Woerter sind fuer die Klasse damit zunaechst unsichtbar -
                 * genau wie eine frisch eingelesene Einheit einer Lehrkraft.
                 * Wer sie zeigen will, schiebt den Balken.
                 */
                vocab_append($unitId, $paare, $lang['code'] ?? null);
            } else {
/*
                 * Die Lerneinheit gehoert dem Kurs.
                 *
                 * Hier stand einmal "kein Kurs? dann eben course_id = NULL" -
                 * ein Rest aus der Zeit, als eine Lerneinheit einem Kind
                 * gehoerte statt einem Kurs. Das Ergebnis waere eine
                 * Lerneinheit, die NIEMAND sieht, auch die Lehrkraft nicht,
                 * die sie gerade eingelesen hat: Sichtbarkeit laeuft ueber
                 * die Kursmitgliedschaft.
                 *
                 * Im heutigen Modell kann es nicht mehr dazu kommen - eine
                 * Sprache entsteht immer zusammen mit ihrem Kurs, und mit dem
                 * letzten Kurs verschwindet sie wieder. Kaeme es doch dazu,
                 * ist eine klare Absage besser als stilles Verschwinden.
                 */
                $kurs = course_for_language((int) $lang['id']);
                if ($kurs === null) {
                    $pdo->rollBack();
                    json_fail('Zu dieser Sprache gibt es keinen Kurs. '
                              . 'Bitte im Lehrkraft-Bereich einen anlegen.', 409);
                }
                $kursId = (int) $kurs['id'];
                q(
                    'INSERT INTO units (language_id, course_id, title,
                                        released_position, position)
                     VALUES (?, ?, ?, ?, ?)',
                    [(int) $lang['id'], $kursId, $title,
                     initial_released_position($user),
                     // Ans Ende der Liste, nicht an den Anfang.
                     unit_next_position($kursId)],
                );
                $unitId = (int) $pdo->lastInsertId();
                vocab_append($unitId, $paare, $lang['code'] ?? null);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        /*
         * Sätze entstehen nur für Freigegebenes - und eine Lehrkraft gibt
         * erst später frei. Für sie ist hier also nichts zu tun, und ein
         * Lauf, der nichts zu tun hat, darf nicht angestossen werden: Die
         * Einheit stünde sonst gleich nach dem Einlesen auf "fehlgeschlagen".
         *
         * Wer für sich selbst einliest, hat sich damit freigegeben; dort
         * geht es wie bisher sofort los.
         */
        $zuTun = vocab_without_sentences($unitId);

        if ($zuTun === 0) {
            json_out([
                'ok'      => true,
                'unit_id' => $unitId,
                'count'   => count($clean),
            ]);
        }

        // Vor der Antwort auf "läuft" setzen: Das Kind landet gleich in der
        // Lerneinheit und soll dort sofort den Spinner sehen, nicht erst beim
        // zweiten Abfragen. Bei einer frisch angelegten Einheit kann der
        // Anspruch nicht scheitern - der Aufruf hält die Reihenfolge trotzdem
        // ein, damit hier nicht als Einziges am Riegel vorbeigearbeitet wird.
        sentence_claim($unitId);

        // Antwort sofort raus, dann im selben Vorgang die Lückensätze bauen.
        // Das Kind sieht seine Lerneinheit, ohne zwanzig Sekunden zu warten;
        // der Knopf für den Lückentext bleibt so lange ein Spinner.
        json_out_and_continue([
            'ok'      => true,
            'unit_id' => $unitId,
            'count'   => count($clean),
        ]);

        set_time_limit(900);
        generate_sentences_tracked($unitId);
        exit;

    default:
        json_fail('Unbekannte Aktion.', 404);
}
