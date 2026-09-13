<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Die Anmeldebremse.
 *
 * Ein Anfangspasswort aus zwei Wörtern hat rund elftausend Kombinationen.
 * Das reicht gegen jemanden, der rät, und nicht gegen jemanden, der
 * durchprobiert - zumal die Benutzernamen einer Klasse absehbar sind. Ohne
 * eine Bremse wäre die kurze Form nicht vertretbar.
 *
 * Zwei Zähler, die verschieden hart zuschlagen:
 *
 * Das KONTO wird gesperrt. Fünf Fehlversuche in einer Viertelstunde, dann
 * ist Schluss, bis die Zeit abgelaufen ist oder eine Lehrkraft aufschliesst.
 * Das ist die eigentliche Verteidigung: Zwanzig Versuche in der Stunde
 * bedeuten für elftausend Kombinationen mehrere Wochen.
 *
 * Die ADRESSE wird nur verlangsamt, nie gesperrt - und das ist wichtig. Eine
 * ganze Schule sitzt hinter einer einzigen öffentlichen Adresse. Würde die
 * gesperrt, sperrte der erste vertippte Fünftklässler seine 27 Mitschüler
 * mit aus. Die Verzögerung greift erst nach dreissig Fehlversuchen und
 * deckelt bei fünf Sekunden: für eine Klasse kaum spürbar, für jemanden,
 * der ein Passwort gegen alle Konten der Reihe nach probiert, entscheidend.
 */

/** Zeitfenster, über das gezählt wird. */
const LOGIN_WINDOW_SECONDS = 900;

/** So viele Fehlversuche verträgt ein Konto, danach ist es gesperrt. */
const LOGIN_ACCOUNT_LIMIT = 5;

/** Bis hierhin bleibt ein Konto unverzögert. */
const LOGIN_ACCOUNT_FREE = 2;

/** Bis hierhin bleibt eine Adresse unverzögert - eine Klasse passt hinein. */
const LOGIN_IP_FREE = 30;

/** Länger als das wartet niemand, auch der Angreifer nicht. */
const LOGIN_DELAY_CAP_MS = 5000;

/**
 * Wie lange diese Anzahl Fehlversuche kostet.
 *
 * Bewusst ohne Datenbank und ohne Uhr, damit sich die Kurve prüfen lässt,
 * ohne dafür Fehlversuche erzeugen zu müssen.
 */
function login_delay_ms(int $count, int $free): int
{
    $über = $count - $free;
    if ($über <= 0) {
        return 0;
    }
    return (int) min(LOGIN_DELAY_CAP_MS, 250 * (2 ** min($über, 10)));
}

/**
 * Was diese Zählerstände bedeuten.
 *
 * Ebenfalls rein rechnerisch. Die Sperre entscheidet allein der Kontozähler;
 * die Adresse trägt nur zur Wartezeit bei.
 *
 * @return array{locked: bool, delay_ms: int}
 */
function login_penalty(int $accountCount, int $ipCount): array
{
    return [
        'locked'   => $accountCount >= LOGIN_ACCOUNT_LIMIT,
        'delay_ms' => max(
            login_delay_ms($accountCount, LOGIN_ACCOUNT_FREE),
            login_delay_ms($ipCount, LOGIN_IP_FREE),
        ),
    ];
}

/** Die Adresse des Aufrufers, so gut sie zu ermitteln ist. */
function login_client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/**
 * Fehlversuche im Fenster.
 *
 * @return array{account: int, ip: int}
 */
function login_attempts_count(string $username, string $ip): array
{
    /*
     * Gerechnet wird durchgehend mit der Uhr der Datenbank. Die Zeitstempel
     * entstehen dort (DEFAULT CURRENT_TIMESTAMP), und ob PHP und MySQL
     * dieselbe Zeitzone haben, ist auf einem geteilten Server nichts, worauf
     * man sich verlassen sollte.
     */
    // Die Zahlen stehen im SQL statt als Parameter: Es sind Konstanten aus
    // dieser Datei, und PDO bindet hier ohne Emulation als Zeichenkette -
    // "INTERVAL '900' SECOND" ist ein Syntaxfehler.
    $fenster = (int) LOGIN_WINDOW_SECONDS;

    return [
        'account' => (int) qv(
            "SELECT COUNT(*) FROM login_attempts
              WHERE username = ? AND created_at >= NOW() - INTERVAL $fenster SECOND",
            [$username],
        ),
        'ip' => (int) qv(
            "SELECT COUNT(*) FROM login_attempts
              WHERE ip = ? AND created_at >= NOW() - INTERVAL $fenster SECOND",
            [$ip],
        ),
    ];
}

/**
 * Wie viele Sekunden eine Sperre noch dauert - oder null, wenn keine besteht.
 *
 * Das Fenster wandert mit: Gesperrt ist, wer im Rückblick auf eine
 * Viertelstunde zu viele Fehlversuche hat. Frei wird das Konto also, sobald
 * der fünftneueste dieser Versuche aus dem Fenster gelaufen ist.
 */
function login_lock_seconds_left(string $username): ?int
{
    $fenster = (int) LOGIN_WINDOW_SECONDS;
    $limit   = (int) LOGIN_ACCOUNT_LIMIT;

    $zeilen = qa(
        "SELECT TIMESTAMPDIFF(SECOND, NOW(), created_at + INTERVAL $fenster SECOND) AS rest
           FROM login_attempts
          WHERE username = ? AND created_at >= NOW() - INTERVAL $fenster SECOND
          ORDER BY created_at DESC
          LIMIT $limit",
        [$username],
    );

    if (count($zeilen) < LOGIN_ACCOUNT_LIMIT) {
        return null;
    }

    return max(1, (int) $zeilen[LOGIN_ACCOUNT_LIMIT - 1]['rest']);
}

/** Einen Fehlversuch vermerken. */
function login_attempt_record(string $username, string $ip): void
{
    q('INSERT INTO login_attempts (username, ip) VALUES (?, ?)', [$username, $ip]);

    /*
     * Aufräumen, aber nicht bei jedem Aufruf: Die Tabelle wächst nur mit
     * Fehlversuchen, und ein gelegentlicher Durchlauf hält sie klein genug.
     * Bei jedem Versuch zu löschen hiesse, dem Angreifer die Arbeit
     * abzunehmen, die Datenbank zu beschäftigen.
     */
    if (random_int(1, 50) === 1) {
        q('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 1 DAY');
    }
}

/**
 * Die Zähler eines Kontos löschen.
 *
 * Nach einer erfolgreichen Anmeldung - wer sein Passwort kennt, soll nicht
 * an den Fehlversuchen von gestern hängenbleiben. Und wenn eine Lehrkraft
 * ein gesperrtes Konto wieder aufschliesst.
 */
function login_attempts_reset(string $username): void
{
    q('DELETE FROM login_attempts WHERE username = ?', [$username]);
}

/**
 * Vor dem Passwortvergleich: bremsen, und sagen, ob überhaupt weitergehen darf.
 *
 * Gibt eine Meldung zurück, wenn das Konto gesperrt ist, sonst null. Die
 * Wartezeit ist bis dahin schon abgesessen.
 */
function login_guard(string $username, string $ip): ?string
{
    $z = login_attempts_count($username, $ip);
    $p = login_penalty($z['account'], $z['ip']);

    if ($p['delay_ms'] > 0) {
        usleep($p['delay_ms'] * 1000);
    }

    if (!$p['locked']) {
        return null;
    }

    $rest    = login_lock_seconds_left($username) ?? LOGIN_WINDOW_SECONDS;
    $minuten = max(1, (int) ceil($rest / 60));

    return sprintf(
        'Zu viele Fehlversuche. Bitte in %d Minute%s noch einmal versuchen '
        . '- oder die Lehrkraft fragen.',
        $minuten,
        $minuten === 1 ? '' : 'n',
    );
}
