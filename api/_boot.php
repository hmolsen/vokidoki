<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/json.php';
require_once __DIR__ . '/../lib/auth.php';

json_boot();

function action(): string
{
    $a = $_GET['action'] ?? '';
    return is_string($a) ? $a : '';
}

/**
 * Schutz gegen Cross-Site-Requests: Ein fremdes Formular kann keinen eigenen
 * Header setzen, und ein fetch() von fremder Herkunft scheitert am Preflight,
 * weil wir keine CORS-Header senden. Zusammen mit SameSite=Lax reicht das hier.
 */
function require_api_request(): void
{
    if (($_SERVER['HTTP_X_VOKABELTRAINER'] ?? '') !== '1') {
        json_fail('Ungültiger Aufruf.', 403);
    }
}

/** Endpunkte, die Daten ändern, akzeptieren nur POST. */
function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_fail('POST erwartet.', 405);
    }
}

/** Sprache des angemeldeten Kindes laden oder abbrechen. */
function own_language(int $userId, int $languageId): array
{
    $row = q1('SELECT * FROM languages WHERE id = ? AND user_id = ?', [$languageId, $userId]);
    if ($row === null) {
        json_fail('Sprache nicht gefunden.', 404);
    }
    return $row;
}

/** Lerneinheit des angemeldeten Kindes laden oder abbrechen. */
function own_unit(int $userId, int $unitId): array
{
    $row = q1('SELECT * FROM units WHERE id = ? AND user_id = ?', [$unitId, $userId]);
    if ($row === null) {
        json_fail('Lerneinheit nicht gefunden.', 404);
    }
    return $row;
}
