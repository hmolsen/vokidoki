<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/colors.php';

/**
 * Das eigene Konto: Name, Farbe, Passwort.
 *
 * Steht für sich, weil es zwei Aufrufer hat. Die App macht daraus eine
 * Ansicht mit JSON dahinter (`api/profile.php`), der Lehrkraft-Bereich eine
 * gewöhnliche Seite mit Formularen (`teacher/konto.php`). Beide ändern
 * dieselben Spalten derselben Tabelle, und zwei Wege mit je eigenen Regeln
 * sind bald zwei verschiedene Regeln — die Mindestlänge an der einen
 * Stelle, das Löschen des Anfangspassworts an der anderen.
 *
 * Geprüft wird hier, nicht bei den Aufrufern. Wer wer ist, prüfen die:
 * Diese Funktionen bekommen eine Benutzerkennung und glauben sie.
 */

/**
 * Name und Farbe sichern.
 *
 * @return string|null Fehlertext für den Menschen, oder null, wenn es lief.
 */
function profile_save(int $userId, string $name, string $color): ?string
{
    $name = trim($name);
    if ($name === '') {
        return 'Bitte einen Namen angeben.';
    }

    q(
        'UPDATE users SET display_name = ?, color = ? WHERE id = ?',
        [mb_substr($name, 0, 64), valid_color($color), $userId],
    );
    return null;
}

/**
 * Das Passwort wechseln.
 *
 * Das bisherige wird verlangt, obwohl die Sitzung angemeldet ist. Der Grund
 * ist ein durchgereichtes Handy: Die App bleibt angemeldet, damit ein Kind
 * nicht täglich tippen muss — dann darf aber nicht jeder, der sie in die
 * Hand bekommt, das Passwort ändern und das Kind aussperren.
 *
 * @param array $user Der vollständige Datensatz, wegen password_hash.
 * @return string|null Fehlertext für den Menschen, oder null, wenn es lief.
 */
function profile_change_password(array $user, string $alt, string $neu): ?string
{
    if (!password_verify($alt, (string) $user['password_hash'])) {
        // Ein Augenblick Verzögerung, damit sich aus der Antwortzeit nicht
        // ablesen lässt, wie weit ein Versuch gekommen ist.
        usleep(random_int(200_000, 500_000));
        return 'Das bisherige Passwort stimmt nicht.';
    }
    if (mb_strlen($neu) < 6) {
        return 'Das neue Passwort braucht mindestens 6 Zeichen.';
    }
    if ($neu === $alt) {
        return 'Das ist das bisherige Passwort.';
    }

    /*
     * Und hier verschwindet das Anfangspasswort aus der Datenbank.
     *
     * Es steht dort im Klartext, damit das Anschreiben nachdruckbar bleibt -
     * eine bewusste Abwägung. Sobald jemand sein eigenes gewählt hat, ist
     * der gespeicherte Wert wertlos, und der Bestand offener Passwörter
     * schrumpft mit der Zeit, statt zu wachsen.
     */
    q(
        'UPDATE users SET password_hash = ?, initial_password = NULL WHERE id = ?',
        [password_hash($neu, PASSWORD_DEFAULT), (int) $user['id']],
    );
    return null;
}

/** Steht noch das Anfangspasswort? Dann sagt die Seite das. */
function profile_has_initial_password(array $user): bool
{
    return ($user['initial_password'] ?? null) !== null
        && $user['initial_password'] !== '';
}
