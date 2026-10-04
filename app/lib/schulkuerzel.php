<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/*
 * Das Schulkürzel: die Schule als eigener Bereich bei der Anmeldung.
 *
 * Bis hierher waren Benutzernamen über alle Schulen hinweg eindeutig - ein
 * Kind tippte seinen Namen ein, ohne eine Schule zu wählen. Mit jeder neuen
 * Schule wurde es enger: Die zweite "lilli.m" hiess "lilli.m2", die dritte
 * "lilli.m3", und kein Kind konnte erraten, welche Ziffer seine war.
 *
 * Jetzt meldet man sich mit drei Angaben an: Schulkürzel, Benutzername,
 * Passwort. Benutzernamen sind nur noch innerhalb ihrer Schule eindeutig
 * (uq_users_school_username). Das Kürzel ist kurz und tippbar ("opsk" für
 * die Otfried-Preußler-Schule Kleinwelsdorf) und verrät, anders als eine
 * Liste zum Auswählen, keiner Besucherin der Seite, welche Schulen es gibt.
 *
 * Ohne Kürzel kann sich an einer Schule niemand anmelden. Bestehende Schulen
 * bekommen ihres nach dem Update im Selbsttest; bis dahin weist der Admin
 * darauf hin (admin_head()).
 */

/* Zwei bis zwölf Kleinbuchstaben oder Ziffern - kurz genug zum Tippen. */
const SCHULKUERZEL_MUSTER = '/^[a-z0-9]{2,12}$/';

/** Was eingetippt wurde, so, wie es gespeichert ist: klein, ohne Leerzeichen. */
function schulkuerzel_normal(string $kuerzel): string
{
    return strtolower(preg_replace('/\s+/u', '', $kuerzel) ?? '');
}

/**
 * Taugt dieses Kürzel? Gibt den Grund zurück, oder null.
 *
 * @param int $ausser die Schule, der es schon gehört (beim Ändern)
 */
function schulkuerzel_pruefen(string $kuerzel, int $ausser = 0): ?string
{
    if (preg_match(SCHULKUERZEL_MUSTER, $kuerzel) !== 1) {
        return 'Das Kürzel braucht 2 bis 12 Zeichen, nur Kleinbuchstaben und Ziffern.';
    }
    if (qv('SELECT id FROM schools WHERE kuerzel = ? AND id <> ?', [$kuerzel, $ausser]) !== null) {
        return 'Dieses Kürzel hat schon eine andere Schule.';
    }
    return null;
}

/**
 * Ein Vorschlag aus dem Namen: die Anfangsbuchstaben, ohne Umlaute.
 * "Otfried-Preußler-Schule Kleinwelsdorf" wird "opsk". Nur ein Vorschlag -
 * gespeichert wird, was der Admin bestätigt.
 */
function schulkuerzel_vorschlag(string $name): string
{
    $name   = strtr(mb_strtolower($name), ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 's']);
    $woerter = preg_split('/[^a-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $kurz   = implode('', array_map(static fn (string $w): string => $w[0], $woerter));
    $kurz   = substr($kurz, 0, 12);
    if (strlen($kurz) < 2) {
        $kurz = substr(implode('', $woerter), 0, 4);
    }
    $vorschlag = $kurz;
    for ($n = 2; schulkuerzel_pruefen($vorschlag) !== null && $n < 100; $n++) {
        $vorschlag = substr($kurz, 0, 10) . $n;
    }
    return $vorschlag;
}

/** Die aktive Schule zu einem Kürzel, oder null. */
function schule_per_kuerzel(string $kuerzel): ?array
{
    $kuerzel = schulkuerzel_normal($kuerzel);
    if ($kuerzel === '') {
        return null;
    }
    return q1('SELECT * FROM schools WHERE kuerzel = ? AND active = 1', [$kuerzel]);
}

/** Die Schulen, an denen sich noch niemand anmelden kann. */
function schulen_ohne_kuerzel(): array
{
    return qa("SELECT * FROM schools WHERE kuerzel IS NULL OR kuerzel = '' ORDER BY name");
}

/** Das Kürzel der Schule eines Kontos - für Zettel, QR-Code und Anzeige. */
function schulkuerzel_von(?int $schoolId): string
{
    return $schoolId === null ? '' : (string) (qv('SELECT kuerzel FROM schools WHERE id = ?', [$schoolId]) ?? '');
}

/**
 * Das Konto zu Schulkürzel und Benutzername - für beide Anmeldungen (App
 * und Lehrkraft-Bereich), damit sie dieselbe Regel haben.
 *
 * schluessel ist das, woran die Anmeldebremse (lib/throttle.php) zählt.
 * Er hängt an der Schule, nicht am Kürzel: Ändert der Admin das Kürzel,
 * bleibt eine Sperre bestehen. Für eine unbekannte Schule zählt das
 * Eingetippte - die Bremse greift auch dort, und an ihr lässt sich nicht
 * ablesen, welche Schulen es gibt.
 *
 * @return array{konto: ?array, schluessel: string}
 */
function konto_zur_anmeldung(string $kuerzel, string $username): array
{
    $username = strtolower(trim($username));
    $schule   = schule_per_kuerzel($kuerzel);
    if ($schule === null) {
        return ['konto' => null,
                'schluessel' => mb_substr('?' . schulkuerzel_normal($kuerzel) . '/' . $username, 0, 64)];
    }
    return [
        'konto'      => q1('SELECT * FROM users WHERE school_id = ? AND username = ? AND active = 1',
                           [(int) $schule['id'], $username]),
        'schluessel' => login_schluessel((int) $schule['id'], $username),
    ];
}

/** Der Schlüssel der Anmeldebremse für ein Konto - auch zum Aufschliessen. */
function login_schluessel(int $schoolId, string $username): string
{
    return mb_substr('s' . $schoolId . '/' . strtolower($username), 0, 64);
}
