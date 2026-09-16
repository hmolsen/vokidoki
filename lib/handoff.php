<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Der Sprung vom Rechner ans Telefon.
 *
 * Eingelesen wird mit der Kamera, also am Telefon. Verwaltet wird am
 * Rechner. Dazwischen lag bisher eine Anmeldung: Adresse abtippen,
 * Benutzername, Passwort - für einen Vorgang, der danach zwanzig Sekunden
 * dauert. Ein QR-Code mit einer Marke darin überspringt das.
 *
 * DAS IST EIN PASSWORT IN BILDFORM, und entsprechend eng ist es gefasst:
 *
 * - **Einmal.** Eingelöst wird über ein bedingtes UPDATE; wer als zweiter
 *   kommt, bekommt nichts. Dieselbe Bauart wie sentence_claim().
 * - **Kurz.** HANDOFF_TTL Minuten. Lang genug, um das Telefon aus der
 *   Tasche zu holen, zu kurz, um als abfotografierter Schlüssel zu taugen.
 * - **Nur für sich selbst.** Erzeugen kann eine Marke nur eine angemeldete
 *   Lehrkraft, und nur auf das eigene Konto.
 * - **Nicht auf Papier.** Der Code steht in einem Fenster auf dem eigenen
 *   Bildschirm. Gedruckt wird er nirgends - der Zettel für die Kinder
 *   trägt Benutzername und Passwort, nicht dies hier.
 *
 * Was er NICHT ist: eine eingeschränkte Sitzung. Wer die Marke einlöst, ist
 * angemeldet wie nach einer Eingabe von Benutzername und Passwort. Das ist
 * Absicht - zum Einlesen gehört die ganze App - und es ist der Grund für
 * die kurze Frist.
 *
 * Seiteneffektfrei bis auf die Datenbank: keine Sitzung, keine Ausgabe.
 * Wer daraus eine Anmeldung macht, ist index.php.
 */

/** Wie lange eine Marke gilt, in Minuten. */
const HANDOFF_TTL = 10;

/** Länger als das lebt keine Marke, auch nicht ungenutzt. */
const HANDOFF_KEEP_DAYS = 1;

/**
 * Eine Marke erzeugen. Liefert den Klartext - gespeichert wird nur der Hash.
 *
 * $target ist der Weg, an den die Marke führt: ein Bruchstück wie
 * "/lang/12/import". Es wandert durch die Adresse und wird beim Einlösen
 * geprüft, nicht blind weitergereicht.
 */
function handoff_create(int $userId, string $target = ''): string
{
    handoff_cleanup();

    $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

    q(
        'INSERT INTO login_handoffs (user_id, token_hash, target, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . HANDOFF_TTL . ' MINUTE))',
        [$userId, hash('sha256', $token), mb_substr($target, 0, 255)],
    );

    return $token;
}

/**
 * Eine Marke einlösen. Liefert ['user' => …, 'target' => …] oder null.
 *
 * Das Entwerten steht VOR dem Laden des Kontos und ist selbst die Prüfung:
 * Ein UPDATE, das zugleich verlangt, dass die Marke noch ungenutzt und
 * nicht abgelaufen ist. Nur wer damit genau eine Zeile trifft, hat sie
 * gehabt. Zwei gleichzeitige Versuche - der Code wurde zweimal gescannt -
 * können sich so nicht beide durchsetzen.
 */
function handoff_redeem(string $token): ?array
{
    if ($token === '' || strlen($token) > 128) {
        return null;
    }

    $hash = hash('sha256', $token);

    $betroffen = q(
        'UPDATE login_handoffs
            SET used_at = NOW()
          WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()',
        [$hash],
    )->rowCount();

    if ($betroffen !== 1) {
        return null;
    }

    $row = q1(
        'SELECT h.target, u.*
           FROM login_handoffs h
           JOIN users u ON u.id = h.user_id
          WHERE h.token_hash = ? AND u.active = 1',
        [$hash],
    );
    if ($row === null) {
        return null;
    }

    $target = (string) $row['target'];
    unset($row['target']);

    return ['user' => $row, 'target' => $target];
}

/**
 * Abgelaufene und verbrauchte Marken wegräumen.
 *
 * Bei jedem Erzeugen ein paar Zeilen - das genügt, weil Marken nur beim
 * Erzeugen entstehen. Eine eigene Aufräumaufgabe wäre eine Abhängigkeit
 * mehr für eine Tabelle, die selten mehr als eine Handvoll Zeilen hat.
 */
function handoff_cleanup(): void
{
    q('DELETE FROM login_handoffs
        WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . HANDOFF_KEEP_DAYS . ' DAY)
           OR (used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL 1 HOUR))');
}

/**
 * Ist dieses Ziel eines, an das eine Marke führen darf?
 *
 * Erlaubt sind die Wege der App, und zwar als Bruchstück ohne Schema und
 * ohne Host: "/lang/12/import". Damit kann eine manipulierte Adresse
 * niemanden auf eine fremde Seite schicken, und im Zweifel geht es zur
 * Startseite.
 */
function handoff_target_ok(string $target): bool
{
    return $target !== ''
        && preg_match('#^/[A-Za-z0-9/_-]{1,120}$#', $target) === 1
        && !str_contains($target, '//');
}
