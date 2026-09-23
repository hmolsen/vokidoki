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

const MAX_IMAGES      = 6;
const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
const ALLOWED_MEDIA   = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

switch (action()) {
    case 'analyze':
        require_post();
        $b    = json_body();
        require_cap($user, CAP_IMPORT);
        $lang = view_language($user, body_int($b, 'language_id'));

        $raw = $b['images'] ?? null;
        if (!is_array($raw) || $raw === []) {
            json_fail('Bitte mindestens ein Foto auswählen.');
        }
        if (count($raw) > MAX_IMAGES) {
            json_fail('Höchstens ' . MAX_IMAGES . ' Fotos auf einmal.');
        }

        // Die Bilderkennung dauert regelmäßig länger als die üblichen
        // 30 Sekunden Standardlaufzeit - sonst bricht PHP mitten im Aufruf ab,
        // nachdem die Anfrage bereits bezahlt wurde.
        set_time_limit(300);

        // Budget- und Ratenprüfung vor dem API-Aufruf, damit ein
        // durchgereichtes iPhone keine offene Rechnung erzeugen kann.
        $blocked = budget_block_reason($uid);
        if ($blocked !== null) {
            json_fail($blocked, 429);
        }

        $images = [];
        foreach ($raw as $i => $item) {
            if (!is_array($item)) {
                json_fail('Foto ' . ($i + 1) . ' ist unbrauchbar.');
            }
            $media = (string) ($item['media_type'] ?? '');
            $data  = (string) ($item['data'] ?? '');

            if (!in_array($media, ALLOWED_MEDIA, true)) {
                json_fail('Foto ' . ($i + 1) . ' hat ein nicht unterstütztes Format.');
            }
            if ($data === '' || strlen($data) > MAX_IMAGE_BYTES) {
                json_fail('Foto ' . ($i + 1) . ' ist zu groß oder leer.');
            }

            $bin = base64_decode($data, true);
            if ($bin === false || @getimagesizefromstring($bin) === false) {
                json_fail('Foto ' . ($i + 1) . ' ist kein gültiges Bild.');
            }

            $images[] = ['data' => $data, 'media_type' => $media];
        }

        try {
            $result = analyze_vocab_images($images, (string) $lang['name'], $user);
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
            error_log('[vokabeltrainer] Bildanalyse fehlgeschlagen: ' . scrub_secrets($e->getMessage()));
            json_fail(
                'Die Fotos konnten nicht ausgewertet werden. Bitte noch einmal versuchen.',
                502,
            );
        }

        if ($result['entries'] === []) {
            json_fail(
                'Auf den Fotos wurden keine Vokabeln gefunden. Vielleicht noch einmal '
                . 'näher heran und mit mehr Licht fotografieren?',
                422,
            );
        }

        json_out([
            'ok'      => true,
            'title'   => $result['title'],
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
            // Abstände vor Satzzeichen gleich beim Einlesen richtigstellen:
            // Im Französischen gehört vor ! ? : ; eines hin, im Deutschen
            // nicht, und das Modell trifft es nicht jedes Mal.
            $f = punctuation_fix((string) ($row['foreign'] ?? ''), $lang['code'] ?? null);
            $n = punctuation_fix((string) ($row['native'] ?? ''), 'de');
            if ($f === '' || $n === '') {
                continue;
            }
            $note    = trim((string) ($row['note'] ?? ''));
            $clean[] = [
                mb_substr($f, 0, 255),
                mb_substr($n, 0, 255),
                $note === '' ? null : mb_substr($note, 0, 255),
                // Die Wortart bestimmt das Modell; das Kind bekommt sie nicht
                // zu Gesicht und muss sie nicht prüfen.
                word_type_clean($row['word_type'] ?? null),
            ];
        }
        if ($clean === []) {
            json_fail('Keine vollständigen Vokabelpaare gefunden.');
        }

        $paare = [];
        foreach ($clean as [$f, $n, $note, $type]) {
            $paare[] = ['foreign' => $f, 'native' => $n,
                        'note' => $note, 'word_type' => $type];
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
