<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/throttle.php';
require_once __DIR__ . '/courses.php';
require_once __DIR__ . '/roster.php';
require_once __DIR__ . '/passwords.php';

/*
 * Lehrkräfte verwalten Lehrkräfte - ohne E-Mail-Adressen.
 *
 * Bis hierher legte nur der Admin Konten für Lehrkräfte an. Mit jeder
 * Schule mehr hiess das: eine Mail an den Betreiber für jede neue Kollegin
 * und jedes vergessene Passwort. Jetzt legen Lehrkräfte einander selbst an
 * und geben einander ein neues Passwort - so wie sie es für die Kinder
 * ihrer Klasse schon tun. Eine E-Mail-Adresse braucht dafür niemand.
 *
 * Weil das Konten anderer Erwachsener berührt, nicht nur die von Kindern,
 * geht es nur in einer erhöhten Sitzung: Wer die Seite "Lehrkräfte" öffnet,
 * gibt sein Passwort noch einmal ein, und für fünf Minuten sind die
 * Handgriffe dort frei. Ein Gerät, das kurz unbeaufsichtigt im
 * Lehrerzimmer liegt, reicht dann nicht, um eine Kollegin auszusperren.
 *
 * Wie viele Lehrkräfte eine Schule haben darf, legt der Admin fest
 * (schools.max_lehrkraefte, Voreinstellung 50).
 */

/* So lange gilt eine erhöhte Sitzung - jeder Handgriff darin verlängert sie nicht. */
const ERHOEHT_SEKUNDEN = 300;

/* So viele Lehrkräfte hat eine neue Schule höchstens, wenn der Admin nichts anderes sagt. */
const LEHRKRAEFTE_VOREINSTELLUNG = 50;

// ---------------------------------------------------------------- Erhöhte Sitzung

/**
 * Wie viele Sekunden die erhöhte Sitzung dieses Kontos noch gilt - 0 heisst: keine.
 *
 * Sie hängt am Konto, nicht nur an der Sitzung: Meldet sich auf demselben
 * Gerät jemand anderes an, gilt sie für den nicht.
 */
function erhoeht_rest(array $user): int
{
    $e = $_SESSION['erhoeht'] ?? null;
    if (!is_array($e) || (int) ($e['user'] ?? 0) !== (int) $user['id']) {
        return 0;
    }
    return max(0, (int) $e['bis'] - time());
}

/** Gilt die erhöhte Sitzung gerade? */
function erhoeht_aktiv(array $user): bool
{
    return erhoeht_rest($user) > 0;
}

/**
 * Die erhöhte Sitzung beginnen - mit dem eigenen Passwort.
 *
 * Fehlversuche bremst dieselbe Bremse wie die Anmeldung, aber mit eigenem
 * Zähler: Wer sich hier vertippt, soll sich nicht zugleich aus der
 * gewöhnlichen Anmeldung aussperren.
 *
 * @return string|null der Grund, wenn es nicht geht
 */
function erhoeht_starten(array $user, string $passwort): ?string
{
    $schluessel = mb_substr('e' . login_schluessel((int) $user['school_id'], (string) $user['username']), 0, 64);
    $ip    = login_client_ip();
    $sperr = login_guard($schluessel, $ip);
    if ($sperr !== null) {
        return $sperr;
    }
    $hash = (string) (qv('SELECT password_hash FROM users WHERE id = ?', [(int) $user['id']]) ?? '');
    if ($hash === '' || !password_verify($passwort, $hash)) {
        login_attempt_record($schluessel, $ip);
        usleep(random_int(150_000, 400_000));
        return 'Das Passwort stimmt nicht.';
    }
    login_attempts_reset($schluessel);
    $_SESSION['erhoeht'] = ['user' => (int) $user['id'], 'bis' => time() + ERHOEHT_SEKUNDEN];
    return null;
}

/** Noch einmal fünf Minuten - nur, solange sie gilt. */
function erhoeht_verlaengern(array $user): bool
{
    if (!erhoeht_aktiv($user)) {
        return false;
    }
    $_SESSION['erhoeht']['bis'] = time() + ERHOEHT_SEKUNDEN;
    return true;
}

function erhoeht_beenden(): void
{
    unset($_SESSION['erhoeht']);
}

// ---------------------------------------------------------------- Lehrkräfte einer Schule

/** So viele Lehrkräfte darf die Schule höchstens haben. */
function lehrkraefte_grenze(int $schoolId): int
{
    return (int) (qv('SELECT max_lehrkraefte FROM schools WHERE id = ?', [$schoolId]) ?? LEHRKRAEFTE_VOREINSTELLUNG);
}

/** So viele hat sie - stillgelegte zählen mit, sie lassen sich wieder aufwecken. */
function lehrkraefte_anzahl(int $schoolId): int
{
    return (int) qv("SELECT COUNT(*) FROM users WHERE school_id = ? AND role = 'teacher'", [$schoolId]);
}

/**
 * Die Lehrkräfte der Schule, mit ihren Kursen - geteilt mit anderen
 * Lehrkräften und allein.
 *
 * @return list<array>
 */
function lehrkraefte_der_schule(int $schoolId): array
{
    return qa(
        "SELECT u.id, u.username, u.display_name, u.color, u.active, u.initial_password,
                (SELECT COUNT(*) FROM course_members m WHERE m.user_id = u.id AND m.member_role = 'teacher') AS kurse
           FROM users u
          WHERE u.school_id = ? AND u.role = 'teacher'
          ORDER BY u.display_name, u.username",
        [$schoolId],
    );
}

/**
 * Eine Lehrkraft anlegen - mit Anfangspasswort, wie ein Kind.
 *
 * Der Benutzername ist am besten das Kürzel der Lehrkraft ("mue"): An einer
 * Schule kennt es jede, und es ist kurz. Eindeutig muss er nur in der
 * eigenen Schule sein.
 *
 * @return array|string das neue Konto (mit initial_password) oder der Grund, warum nicht
 */
function lehrkraft_anlegen(int $schoolId, string $name, string $benutzername): array|string
{
    $name         = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    $benutzername = strtolower(trim($benutzername));

    if ($name === '') {
        return 'Die Lehrkraft braucht einen Namen.';
    }
    if (preg_match('/^[a-z0-9._-]{2,64}$/', $benutzername) !== 1) {
        return 'Benutzername: 2 bis 64 Zeichen, nur Kleinbuchstaben, Ziffern, Punkt, Strich.';
    }
    if (q1('SELECT id FROM users WHERE school_id = ? AND username = ?', [$schoolId, $benutzername]) !== null) {
        return sprintf('„%s" gibt es an dieser Schule schon.', $benutzername);
    }
    if (lehrkraefte_anzahl($schoolId) >= lehrkraefte_grenze($schoolId)) {
        return sprintf('Diese Schule hat schon %d Lehrkräfte - mehr sind nicht vorgesehen. '
            . 'Der Betreiber kann die Grenze anheben.', lehrkraefte_grenze($schoolId));
    }
    $passwort = password_generate();
    if ($passwort === null) {
        return 'Es liess sich kein Anfangspasswort bilden.';
    }

    q("INSERT INTO users (school_id, username, display_name, role, password_hash, initial_password, color, can_import)
       VALUES (?, ?, ?, 'teacher', ?, ?, ?, 1)",
      [$schoolId, $benutzername, mb_substr($name, 0, 64), password_hash($passwort, PASSWORD_DEFAULT),
       $passwort, roster_color_for($benutzername)]);
    return q1('SELECT * FROM users WHERE id = ?', [(int) db()->lastInsertId()]);
}

/**
 * Was beim Löschen dieser Lehrkraft geschieht: aus wie vielen Kursen sie
 * nur herausgenommen wird (dort gibt es weitere Lehrkräfte), und wie viele
 * sie allein führt - die übernimmt, wer löscht.
 *
 * @return array{geteilt: int, allein: list<array{id:int,name:string}>}
 */
function lehrkraft_loeschen_vorschau(int $lehrkraftId): array
{
    $geteilt = 0;
    $allein  = [];
    foreach (qa(
        "SELECT co.id, co.name,
                (SELECT COUNT(*) FROM course_members a
                  WHERE a.course_id = co.id AND a.member_role = 'teacher' AND a.user_id <> m.user_id) AS andere
           FROM course_members m
           JOIN courses co ON co.id = m.course_id
          WHERE m.user_id = ? AND m.member_role = 'teacher'
          ORDER BY co.name",
        [$lehrkraftId],
    ) as $k) {
        if ((int) $k['andere'] > 0) {
            $geteilt++;
        } else {
            $allein[] = ['id' => (int) $k['id'], 'name' => (string) $k['name']];
        }
    }
    return ['geteilt' => $geteilt, 'allein' => $allein];
}

/**
 * Eine Lehrkraft löschen. Kurse, die sie allein geführt hat, übernimmt
 * $uebernehmer - sonst stünde ein Kurs ohne Lehrkraft da, den niemand mehr
 * freigeben kann. Aus allen übrigen wird sie nur herausgenommen.
 *
 * Kurse, Lerneinheiten und der Lernstand der Kinder bleiben; mit dem Konto
 * gehen seine Mitgliedschaften und Geräte (Fremdschlüssel).
 *
 * @return int wie viele Kurse $uebernehmer übernommen hat
 */
function lehrkraft_loeschen(int $lehrkraftId, int $uebernehmerId): int
{
    $vorschau = lehrkraft_loeschen_vorschau($lehrkraftId);
    db()->beginTransaction();
    try {
        foreach ($vorschau['allein'] as $k) {
            q("INSERT INTO course_members (course_id, user_id, member_role) VALUES (?, ?, 'teacher')
               ON DUPLICATE KEY UPDATE member_role = 'teacher'",
              [$k['id'], $uebernehmerId]);
        }
        q("DELETE FROM users WHERE id = ? AND role = 'teacher'", [$lehrkraftId]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    return count($vorschau['allein']);
}
