<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/*
 * "Deine Geräte" - die Symbole auf Home-Bildschirmen, die ohne Anmeldung
 * in Vokidoki führen.
 *
 * Jedes solche Symbol trägt einen Geräte-Token (lib/auth.php). Wer ein
 * Handy verliert oder ein Tablet weitergibt, soll sehen, wo eines liegt,
 * und es abschalten können - selbst, ohne Lehrkraft oder Betreiber.
 *
 * Nicht jeder Token ist ein Symbol. Token entstehen bei jeder Anmeldung
 * eines Kindes und für jeden Manifest-Link, auch wenn danach nie jemand
 * "Zum Home-Bildschirm" drückt. Eine Liste aller Token zeigte Dutzende
 * Geräte, die es nie gab. Deshalb meldet die App, sobald sie als Symbol
 * läuft (display-mode: standalone), und erst dann steht das Gerät hier
 * (installed_at).
 */

/*
 * Die Betriebssysteme: Name und Zeichen (assets/geraete/).
 *
 * Der Schlüssel landet in device_tokens.system - darum kurz und fest.
 */
const GERAETE_SYSTEME = [
    'iphone'   => ['iPhone',          'apple'],
    'ipad'     => ['iPad',            'apple'],
    'android'  => ['Android',         'android'],
    'windows'  => ['Windows',         'windows'],
    'mac'      => ['Mac',             'apple'],
    'chromeos' => ['ChromeOS',        'chrome'],
    'linux'    => ['Linux',           'linux'],
    'anderes'  => ['Anderes Gerät',   'geraet'],
];

/**
 * Das Betriebssystem aus der Kennung des Browsers.
 *
 * Die Reihenfolge zählt: Android-Kennungen enthalten "Linux", ChromeOS
 * ebenfalls, und iPhones nennen "like Mac OS X".
 *
 * @param bool $beruehrbar Meldet der Browser einen Mac mit Touchscreen? Das
 *                         ist ein iPad - seit iPadOS 13 gibt es sich als Mac
 *                         aus, erkennbar nur an den Fingern.
 */
function geraet_system(string $kennung, bool $beruehrbar = false): string
{
    return match (true) {
        str_contains($kennung, 'iPad')                    => 'ipad',
        (bool) preg_match('/iPhone|iPod/', $kennung)      => 'iphone',
        str_contains($kennung, 'Android')                 => 'android',
        str_contains($kennung, 'Windows')                 => 'windows',
        str_contains($kennung, 'CrOS')                    => 'chromeos',
        str_contains($kennung, 'Macintosh')               => $beruehrbar ? 'ipad' : 'mac',
        str_contains($kennung, 'Linux')                   => 'linux',
        default                                           => 'anderes',
    };
}

/**
 * Die App läuft als Symbol vom Home-Bildschirm - den Token dieser Sitzung
 * als Gerät vermerken.
 *
 * Nur der eigene Token: Er kommt aus der Sitzung, nicht aus der Anfrage,
 * und muss dem angemeldeten Konto gehören.
 */
function geraet_installiert(int $userId, bool $beruehrbar): void
{
    $token = $_SESSION['device_token'] ?? null;
    if (!is_string($token) || $token === '') {
        return;
    }
    q('UPDATE device_tokens
          SET installed_at = COALESCE(installed_at, NOW()), system = ?, last_used_at = NOW()
        WHERE token_hash = ? AND user_id = ? AND revoked_at IS NULL',
      [geraet_system((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), $beruehrbar),
       hash('sha256', $token), $userId]);
}

/** Ein Zeitpunkt aus der Datenbank, wie ihn ein Mensch liest: "heute, 14:03", "3.10.2026". */
function geraet_zeit(?string $wann): string
{
    if ($wann === null || $wann === '') {
        return 'noch nie';
    }
    $t = strtotime($wann);
    if ($t === false) {
        return '';
    }
    return match (date('Y-m-d', $t)) {
        date('Y-m-d')                     => 'heute, ' . date('H:i', $t),
        date('Y-m-d', strtotime('-1 day')) => 'gestern, ' . date('H:i', $t),
        default                           => date('j.n.Y', $t),
    };
}

/**
 * Die Geräte eines Kontos, das neueste zuerst - fertig zum Zeichnen, für
 * die App (api/profile.php) und den Lehrkraft-Bereich (teacher/konto.php).
 *
 * @return list<array{id:int, name:string, zeichen:string, angelegt:string, zuletzt:string, dieses:bool, frage:string}>
 */
function geraete_liste(int $userId): array
{
    $eigener = $_SESSION['device_token'] ?? null;
    $eigener = is_string($eigener) && $eigener !== '' ? hash('sha256', $eigener) : '';

    $liste = [];
    foreach (qa('SELECT id, token_hash, label, system, installed_at, last_used_at
                   FROM device_tokens
                  WHERE user_id = ? AND installed_at IS NOT NULL AND revoked_at IS NULL
                  ORDER BY installed_at DESC, id DESC', [$userId]) as $g) {
        $system = (string) ($g['system'] ?? '');
        if (!isset(GERAETE_SYSTEME[$system])) {
            $system = geraet_system((string) ($g['label'] ?? ''));
        }
        [$name, $zeichen] = GERAETE_SYSTEME[$system];
        $dieses = $eigener !== '' && hash_equals((string) $g['token_hash'], $eigener);
        $liste[] = [
            'id'       => (int) $g['id'],
            'name'     => $name,
            'zeichen'  => url('/assets/geraete/' . $zeichen . '.svg'),
            'angelegt' => geraet_zeit($g['installed_at']),
            'zuletzt'  => geraet_zeit($g['last_used_at']),
            'dieses'   => $dieses,
            'frage'    => geraet_weg_frage($name, $dieses),
        ];
    }
    return $liste;
}

/**
 * Ein Symbol abschalten. Danach führt es auf die Anmeldung.
 *
 * Die laufende Sitzung bleibt - auch wenn es das Gerät ist, das man gerade
 * in der Hand hat. Der Token dieser Sitzung wird aber vergessen, damit das
 * nächste "Zum Home-Bildschirm" einen frischen bekommt statt des
 * abgeschalteten (install_token()).
 *
 * @return bool ob es das Gerät gab und es diesem Konto gehörte
 */
function geraet_widerrufen(int $userId, int $id): bool
{
    $g = q1('SELECT token_hash FROM device_tokens WHERE id = ? AND user_id = ? AND revoked_at IS NULL',
            [$id, $userId]);
    if ($g === null) {
        return false;
    }
    q('UPDATE device_tokens SET revoked_at = NOW() WHERE id = ?', [$id]);

    $eigener = $_SESSION['device_token'] ?? null;
    if (is_string($eigener) && hash_equals((string) $g['token_hash'], hash('sha256', $eigener))) {
        unset($_SESSION['device_token']);
    }
    return true;
}

/*
 * Die beiden Sätze der Karte - einmal hier, weil App (views/profile.js, über
 * api/profile.php) und Lehrkraft-Bereich (teacher/konto.php) sie zeigen.
 */
const GERAETE_ERKLAERUNG = 'Auf diesen Geräten liegt Vokidoki als Symbol auf dem Home-Bildschirm. '
    . 'Wer es antippt, ist sofort angemeldet - ohne Passwort. Gehört dir ein Gerät nicht mehr '
    . 'oder ist es verloren gegangen, schalte es mit dem Mülleimer ab.';

/** Die Rückfrage vor dem Abschalten. */
function geraet_weg_frage(string $name, bool $dieses): string
{
    return sprintf(
        '„%s“%s abschalten? Das Symbol auf dem Home-Bildschirm funktioniert danach nicht mehr - '
        . 'wer es antippt, muss sich neu anmelden. Ein neues Symbol kannst du jederzeit wieder anlegen.',
        $name, $dieses ? ' (dieses Gerät)' : '');
}
