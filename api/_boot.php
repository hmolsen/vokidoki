<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/json.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/access.php';

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

/*
 * Dünne Hüllen um lib/access.php.
 *
 * Die Regel steht dort, hier steht nur, was im Fehlerfall passiert: eine
 * JSON-Antwort. Der Lehrkraft-Bereich bekommt später eigene Hüllen um
 * dieselben Regeln, die stattdessen weiterleiten.
 *
 * Bewusst überall 404, auch beim Änderungsversuch: Ein 403 verriete, dass es
 * die Lerneinheit gibt. Wer sie nicht sehen darf, soll nicht erfahren, dass
 * sie existiert.
 */

/** Sprache zum Ansehen, oder abbrechen. */
function view_language(array $user, int $languageId): array
{
    $row = load_language_for_view($user, $languageId);
    if ($row === null) {
        json_fail('Sprache nicht gefunden.', 404);
    }
    return $row;
}

/** Sprache zum Ändern, oder abbrechen. */
function edit_language(array $user, int $languageId): array
{
    $row = load_language_for_edit($user, $languageId);
    if ($row === null) {
        json_fail('Sprache nicht gefunden.', 404);
    }
    return $row;
}

/** Lerneinheit zum Ansehen und Üben, oder abbrechen. */
function view_unit(array $user, int $unitId): array
{
    $row = load_unit_for_view($user, $unitId);
    if ($row === null) {
        json_fail('Lerneinheit nicht gefunden.', 404);
    }
    return $row;
}

/** Lerneinheit zum Ändern, oder abbrechen. */
function edit_unit(array $user, int $unitId): array
{
    $row = load_unit_for_edit($user, $unitId);
    if ($row === null) {
        json_fail('Lerneinheit nicht gefunden.', 404);
    }
    return $row;
}

/** Lückensatz zum Ansehen, oder abbrechen. */
function view_sentence(array $user, int $sentenceId): array
{
    $row = load_sentence_for_view($user, $sentenceId);
    if ($row === null) {
        json_fail('Diesen Satz gibt es nicht.', 404);
    }
    return $row;
}

/** Eine Fähigkeit verlangen, sonst abbrechen. */
function require_cap(array $user, string $cap): void
{
    if (!user_can($user, $cap)) {
        json_fail('Dafür fehlt dir die Berechtigung.', 403);
    }
}
