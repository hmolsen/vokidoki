<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/ai.php';

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
        $lang = own_language($uid, body_int($b, 'language_id'));

        $raw = $b['images'] ?? null;
        if (!is_array($raw) || $raw === []) {
            json_fail('Bitte mindestens ein Foto auswaehlen.');
        }
        if (count($raw) > MAX_IMAGES) {
            json_fail('Hoechstens ' . MAX_IMAGES . ' Fotos auf einmal.');
        }

        // Budget- und Ratenpruefung vor dem API-Aufruf, damit ein
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
                json_fail('Foto ' . ($i + 1) . ' hat ein nicht unterstuetztes Format.');
            }
            if ($data === '' || strlen($data) > MAX_IMAGE_BYTES) {
                json_fail('Foto ' . ($i + 1) . ' ist zu gross oder leer.');
            }

            $bin = base64_decode($data, true);
            if ($bin === false || @getimagesizefromstring($bin) === false) {
                json_fail('Foto ' . ($i + 1) . ' ist kein gueltiges Bild.');
            }

            $images[] = ['data' => $data, 'media_type' => $media];
        }

        try {
            $result = analyze_vocab_images($images, (string) $lang['name'], $user);
        } catch (Throwable $e) {
            error_log('[vokabeltrainer] Bildanalyse fehlgeschlagen: ' . $e->getMessage());
            json_fail(
                'Die Fotos konnten nicht ausgewertet werden. Bitte noch einmal versuchen.',
                502,
            );
        }

        if ($result['entries'] === []) {
            json_fail(
                'Auf den Fotos wurden keine Vokabeln gefunden. Vielleicht noch einmal '
                . 'naeher heran und mit mehr Licht fotografieren?',
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
        $lang  = own_language($uid, body_int($b, 'language_id'));
        $title = body_str($b, 'title', 128);
        if ($title === '') {
            json_fail('Bitte einen Titel fuer die Lerneinheit angeben.');
        }

        $rows = $b['entries'] ?? null;
        if (!is_array($rows) || $rows === []) {
            json_fail('Es gibt nichts zu speichern.');
        }
        if (count($rows) > 500) {
            json_fail('Eine Lerneinheit kann hoechstens 500 Vokabeln haben.');
        }

        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $f = trim((string) ($row['foreign'] ?? ''));
            $n = trim((string) ($row['native'] ?? ''));
            if ($f === '' || $n === '') {
                continue;
            }
            $note    = trim((string) ($row['note'] ?? ''));
            $clean[] = [
                mb_substr($f, 0, 255),
                mb_substr($n, 0, 255),
                $note === '' ? null : mb_substr($note, 0, 255),
            ];
        }
        if ($clean === []) {
            json_fail('Keine vollstaendigen Vokabelpaare gefunden.');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            q(
                'INSERT INTO units (user_id, language_id, title) VALUES (?, ?, ?)',
                [$uid, (int) $lang['id'], $title],
            );
            $unitId = (int) $pdo->lastInsertId();

            $st = $pdo->prepare(
                'INSERT INTO vocab (unit_id, term_foreign, term_native, note, position)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($clean as $i => [$f, $n, $note]) {
                $st->execute([$unitId, $f, $n, $note, $i]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        json_out(['ok' => true, 'unit_id' => $unitId, 'count' => count($clean)]);

    default:
        json_fail('Unbekannte Aktion.', 404);
}
