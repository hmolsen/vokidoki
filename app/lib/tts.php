<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/cost.php';
require_once __DIR__ . '/keyvault.php';

/*
 * Die Aufnahmen für "Hören": jeder Lückensatz einmal ganz gesprochen.
 *
 * Gesprochen wird einmal, gleich nachdem die Sätze entstanden sind, und
 * dann als Datei abgelegt - nicht bei jedem Anhören. So bezahlt man einen
 * Satz einmal, und das Gerät kann ihn ohne Netz abspielen.
 *
 * Die Stimmen kommen von Microsoft (Azure Speech). Im Vergleich mit Piper,
 * Kokoro und der Stimme des Geräts klangen sie mit Abstand am natürlichsten,
 * und sie klingen auf jedem Gerät gleich. Was dorthin geht, ist nur der
 * Satz, den das Modell geschrieben hat - nichts über ein Kind.
 *
 * Der Schlüssel steht im Keyvault (keyvault_tts_key()), wie der für
 * Anthropic. Fehlt er oder antwortet Azure nicht, fehlen eben Aufnahmen:
 * Geübt wird "Hören" nur mit Sätzen, die eine haben, und alles andere läuft
 * weiter wie bisher.
 */

/**
 * Die Stimme je Sprache: [xml:lang, Name bei Azure].
 *
 * Latein fehlt mit Absicht - dafür gibt es keine Stimme, und damit keine
 * Übung "Hören" in Lateinkursen. Dasselbe gilt für jede Sprache, die hier
 * nicht steht.
 */
const TTS_STIMMEN = [
    'en' => ['en-GB', 'en-GB-SoniaNeural'],
    'fr' => ['fr-FR', 'fr-FR-DeniseNeural'],
    'es' => ['es-ES', 'es-ES-ElviraNeural'],
    'it' => ['it-IT', 'it-IT-ElsaNeural'],
    'pt' => ['pt-PT', 'pt-PT-RaquelNeural'],
    'nl' => ['nl-NL', 'nl-NL-ColetteNeural'],
    'da' => ['da-DK', 'da-DK-ChristelNeural'],
    'sv' => ['sv-SE', 'sv-SE-SofieNeural'],
    'nb' => ['nb-NO', 'nb-NO-PernilleNeural'],
    'no' => ['nb-NO', 'nb-NO-PernilleNeural'],
    'pl' => ['pl-PL', 'pl-PL-ZofiaNeural'],
    'cs' => ['cs-CZ', 'cs-CZ-VlastaNeural'],
    'ru' => ['ru-RU', 'ru-RU-SvetlanaNeural'],
    'tr' => ['tr-TR', 'tr-TR-EmelNeural'],
    'el' => ['el-GR', 'el-GR-AthinaNeural'],
    'de' => ['de-DE', 'de-DE-KatjaNeural'],
    'zh' => ['zh-CN', 'zh-CN-XiaoxiaoNeural'],
    'ja' => ['ja-JP', 'ja-JP-NanamiNeural'],
    'ar' => ['ar-SA', 'ar-SA-ZariyahNeural'],
];

/** Die Regionen, die im Admin zur Wahl stehen - alle in Europa. */
const TTS_REGIONEN = [
    'germanywestcentral' => 'Deutschland, Frankfurt',
    'westeurope'         => 'Westeuropa, Niederlande',
    'northeurope'        => 'Nordeuropa, Irland',
    'francecentral'      => 'Frankreich, Paris',
    'swedencentral'      => 'Schweden',
    'switzerlandnorth'   => 'Schweiz, Zürich',
];

/** So viele Sätze gehen gleichzeitig an Azure - genug für Tempo, wenig genug für das Ratenlimit. */
const TTS_PARALLEL = 4;

/** So oft wird ein Satz versucht, wenn Azure bremst (HTTP 429). */
const TTS_VERSUCHE = 4;

/**
 * So lange darf ein Lauf auf Azure warten, in Sekunden. Der Knopf im Admin
 * hat fünf Minuten; was danach fehlt, holt der nächste Lauf nach.
 */
const TTS_ZEITRAHMEN = 240;

/** MP3, mono, 48 kbit/s: rund 15 KB je Satz und für Sprache mehr als genug. */
const TTS_FORMAT = 'audio-24khz-48kbitrate-mono-mp3';

/** Die Stimme für einen Sprachcode, oder null - dann gibt es kein "Hören". */
function tts_stimme(?string $code): ?array
{
    $stimme = TTS_STIMMEN[strtolower(trim((string) $code))] ?? null;
    return $stimme === null ? null : ['lang' => $stimme[0], 'name' => $stimme[1]];
}

/** Der ganze Satz, wie er gesprochen wird: die Lücke mit der Lösung gefüllt. */
function tts_satztext(string $foreign, string $answer): string
{
    return trim(str_replace('{}', $answer, $foreign));
}

/**
 * Kurzzeichen für Text und Stimme. Ändert sich eines davon, passt die
 * Aufnahme nicht mehr - etwa wenn im Admin ein Satz verbessert wird.
 */
function tts_hash(string $text, string $voice): string
{
    return substr(sha1($voice . "\n" . $text), 0, 12);
}

/** Der Pfad der Datei unter daten/storage - mit dem Kurzzeichen im Namen. */
function tts_datei(int $sentenceId, string $hash): string
{
    return 'audio/' . $sentenceId . '-' . $hash . '.mp3';
}

function tts_aktiv(): bool
{
    return setting('tts_enabled') === '1';
}

/**
 * Die Sätze einer Lerneinheit, denen eine passende Aufnahme fehlt.
 *
 * @return list<array{id:int, text:string, hash:string, alte_datei:?string}>
 */
function tts_offen(int $unitId, array $stimme): array
{
    $offen = [];
    foreach (qa(
        'SELECT s.id, s.foreign_text, s.answer, a.hash AS alt, a.file AS alte_datei
           FROM sentences s
           JOIN vocab v ON v.id = s.vocab_id
           LEFT JOIN sentence_audio a ON a.sentence_id = s.id
          WHERE v.unit_id = ?
          ORDER BY s.id',
        [$unitId],
    ) as $s) {
        $text = tts_satztext((string) $s['foreign_text'], (string) $s['answer']);
        $hash = tts_hash($text, $stimme['name']);
        if ($text === '' || $s['alt'] === $hash) {
            continue;
        }
        $offen[] = ['id' => (int) $s['id'], 'text' => $text, 'hash' => $hash,
                    'alte_datei' => $s['alte_datei']];
    }
    return $offen;
}

/** Wie viele Sätze einer Lerneinheit noch keine passende Aufnahme haben. */
function tts_fehlend(int $unitId): int
{
    $stimme = tts_stimme(tts_sprachcode($unitId));
    return $stimme === null ? 0 : count(tts_offen($unitId, $stimme));
}

function tts_sprachcode(int $unitId): ?string
{
    $code = qv('SELECT l.code FROM units u JOIN languages l ON l.id = u.language_id WHERE u.id = ?',
               [$unitId]);
    return $code === null ? null : (string) $code;
}

/**
 * Die fehlenden Aufnahmen einer Lerneinheit erzeugen.
 *
 * Auf Rechnung von $user (course_billing_user()), wie die Sätze. Im
 * Kostenprotokoll steht ein Eintrag je Lauf, nicht je Satz: Hundert Zeilen
 * für eine Lerneinheit sagten nichts, und sie liefen in das Stundenlimit
 * fürs Einlesen.
 *
 * @return array{erzeugt:int, offen:int, fehler:?string}
 */
function tts_nachtragen(int $unitId, array $user): array
{
    $stimme = tts_stimme(tts_sprachcode($unitId));
    if ($stimme === null || !tts_aktiv()) {
        return ['erzeugt' => 0, 'offen' => 0, 'fehler' => null];
    }

    $offen = tts_offen($unitId, $stimme);
    if ($offen === []) {
        return ['erzeugt' => 0, 'offen' => 0, 'fehler' => null];
    }

    /*
     * Ein Lauf je Lerneinheit zur Zeit. Zwei Freigaben kurz nacheinander,
     * oder eine Freigabe und der Knopf im Admin, schickten sonst dieselben
     * Sätze zweimal an Azure - doppelt bezahlt oder doppelt vom
     * Freikontingent. Die Sperre hält die Datenbank (GET_LOCK), sie fällt mit
     * der Verbindung, auch wenn ein Lauf abbricht.
     */
    $sperre = 'vt-tts-' . $unitId;
    if ((int) qv('SELECT GET_LOCK(?, 0)', [$sperre]) !== 1) {
        return ['erzeugt' => 0, 'offen' => count($offen),
                'fehler' => 'Für diese Lerneinheit entstehen die Aufnahmen gerade schon.'];
    }
    try {
        return tts_nachtragen_gesperrt($unitId, $user, $stimme, $offen);
    } finally {
        try {
            qv('SELECT RELEASE_LOCK(?)', [$sperre]);
        } catch (Throwable) {
            // Die Verbindung kann nach einem langen Lauf neu sein - dann ist die Sperre ohnehin weg.
        }
    }
}

/** Der Lauf selbst - unter der Sperre aus tts_nachtragen(). */
function tts_nachtragen_gesperrt(int $unitId, array $user, array $stimme, array $offen): array
{
    $blockiert = budget_block_reason((int) $user['id']);
    if ($blockiert !== null) {
        return ['erzeugt' => 0, 'offen' => count($offen), 'fehler' => $blockiert];
    }

    /*
     * Im kostenlosen Tarif nur so viel, wie vom Freikontingent übrig ist.
     *
     * Ist es aufgebraucht, lehnt Azure jede Anfrage ab - lieber vorher
     * aufhören und es sagen, als hundertmal anzufragen und hundert Fehler
     * ins Protokoll zu schreiben. Was nicht mehr passt, kommt im nächsten
     * Monat, beim nächsten Lauf.
     */
    $alleOffen = count($offen);
    if (tts_tarif_frei()) {
        $rest = (int) floor(tts_freikontingent() * TTS_KONTINGENT_RAND) - tts_zeichen_monat();
        $passt = [];
        foreach ($offen as $s) {
            $rest -= mb_strlen($s['text']);
            if ($rest < 0) {
                break;
            }
            $passt[] = $s;
        }
        if ($passt === []) {
            return ['erzeugt' => 0, 'offen' => $alleOffen,
                    'fehler' => sprintf('Das Freikontingent dieses Monats (%s Zeichen) ist aufgebraucht '
                        . '- weiter ab dem 1.', number_format(tts_freikontingent(), 0, ',', '.'))];
        }
        $offen = $passt;
    }

    try {
        $schluessel = keyvault_tts_key();
    } catch (KeyvaultException $e) {
        error_log('[vokabeltrainer] Aufnahmen: ' . $e->getMessage());
        return ['erzeugt' => 0, 'offen' => count($offen),
                'fehler' => 'Für die Aufnahmen fehlt der Schlüssel im Keyvault.'];
    }

    $start     = microtime(true);
    $antworten = tts_anfragen($schluessel, $offen, $stimme);
    db_ensure();

    $erzeugt = 0;
    $zeichen = 0;
    $fehler  = null;
    foreach ($offen as $s) {
        $mp3 = $antworten[$s['id']] ?? null;
        if (!is_string($mp3)) {
            $fehler ??= is_array($mp3) ? $mp3['fehler'] : 'keine Antwort';
            continue;
        }
        $datei = tts_datei($s['id'], $s['hash']);
        $pfad  = storage_path($datei);
        if (!is_dir(dirname($pfad))) {
            @mkdir(dirname($pfad), 0775, true);
        }
        if (@file_put_contents($pfad, $mp3) === false) {
            $fehler ??= 'Die Aufnahme liess sich nicht speichern.';
            continue;
        }
        q('INSERT INTO sentence_audio (sentence_id, voice, hash, file, bytes)
           VALUES (?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE voice = VALUES(voice), hash = VALUES(hash),
                                   file = VALUES(file), bytes = VALUES(bytes),
                                   created_at = NOW()',
          [$s['id'], $stimme['name'], $s['hash'], $datei, strlen($mp3)]);
        // Die alte Fassung dieses Satzes wird nicht mehr gebraucht.
        if (($s['alte_datei'] ?? null) !== null && $s['alte_datei'] !== $datei) {
            @unlink(storage_path((string) $s['alte_datei']));
        }
        $erzeugt++;
        $zeichen += mb_strlen($s['text']);
    }

    ai_log([
        'user_id'      => (int) $user['id'],
        'user_label'   => (string) ($user['display_name'] ?? ''),
        'model'        => $stimme['name'],
        'purpose'      => 'tts',
        // Azure rechnet nach Zeichen; sie stehen dort, wo sonst die Token stehen.
        'input_tokens' => $zeichen,
        'entry_count'  => $erzeugt,
        'cost_usd'     => tts_kosten($zeichen),
        'duration_ms'  => (int) ((microtime(true) - $start) * 1000),
        'status'       => $erzeugt === 0 ? 'error' : 'ok',
        'error'        => $fehler === null ? null : mb_substr($fehler, 0, 2000),
    ]);

    // Ab und zu die Dateien gelöschter Sätze wegräumen.
    if (random_int(1, 20) === 1) {
        tts_waisen_entfernen();
    }

    if ($fehler === null && count($offen) < $alleOffen) {
        $fehler = 'Das Freikontingent dieses Monats reicht nicht für alle - der Rest kommt ab dem 1.';
    }

    return ['erzeugt' => $erzeugt, 'offen' => $alleOffen - $erzeugt, 'fehler' => $fehler];
}

/**
 * Was so viele Zeichen kosten - im kostenlosen Tarif nichts, sonst zum
 * Preis aus dem Admin (Einstellungen). Stünde hier im Tarif F0 der Preis
 * von S0, zählten die Aufnahmen gegen das Monatsbudget, ohne je etwas zu
 * kosten, und sperrten am Ende das Einlesen.
 */
function tts_kosten(int $zeichen): float
{
    return tts_tarif_frei() ? 0.0 : $zeichen * (float) setting('tts_price_per_million') / 1_000_000;
}

/** Der kostenlose Tarif F0 - mit Freikontingent statt Preis. */
function tts_tarif_frei(): bool
{
    return setting('tts_tarif') !== 'S0';
}

/** So viele Zeichen sind im Tarif F0 je Monat frei. */
function tts_freikontingent(): int
{
    return max(0, (int) setting('tts_free_chars'));
}

/**
 * Zeichen dieses Kalendermonats - wie das Kostenprotokoll sie zählt.
 *
 * Azure rechnet das Kontingent selbst nach; das hier ist eine Schätzung von
 * unserer Seite (gezählt wird der gesprochene Satz, ohne die SSML darum).
 * Sie liegt eher knapp darunter - deshalb hält ein Lauf etwas Abstand.
 */
function tts_zeichen_monat(): int
{
    return (int) qv(
        "SELECT COALESCE(SUM(input_tokens), 0) FROM ai_requests
          WHERE purpose = 'tts' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')",
    );
}

/** Zeichen je Tag dieses Monats: [Tag => Zeichen], Tage ohne fehlen. */
function tts_zeichen_je_tag(): array
{
    $tage = [];
    foreach (qa(
        "SELECT DAY(created_at) AS t, SUM(input_tokens) AS z FROM ai_requests
          WHERE purpose = 'tts' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
          GROUP BY DAY(created_at)",
    ) as $r) {
        $tage[(int) $r['t']] = (int) $r['z'];
    }
    return $tage;
}

/** Zeichen der letzten Monate: ['2026-10' => Zeichen], ältester zuerst. */
function tts_zeichen_je_monat(int $monate = 6): array
{
    $liste = [];
    for ($i = $monate - 1; $i >= 0; $i--) {
        $liste[date('Y-m', strtotime(date('Y-m-01') . " -$i month"))] = 0;
    }
    foreach (qa(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, SUM(input_tokens) AS z FROM ai_requests
          WHERE purpose = 'tts' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01') - INTERVAL ? MONTH
          GROUP BY m",
        [$monate - 1],
    ) as $r) {
        if (array_key_exists($r['m'], $liste)) {
            $liste[$r['m']] = (int) $r['z'];
        }
    }
    return $liste;
}

/** Ein Rand unter dem Freikontingent - unsere Zählung ist nur eine Schätzung. */
const TTS_KONTINGENT_RAND = 0.98;

/** Wohin die Anfragen gehen. Für die Tests lässt sich ein Ersatz eintragen. */
function tts_endpunkt(): string
{
    $basis = rtrim((string) cfg('azure_tts_base_url', ''), '/');
    if ($basis === '') {
        $region = preg_replace('/[^a-z0-9]/', '', strtolower(setting('tts_region'))) ?: 'germanywestcentral';
        $basis  = 'https://' . $region . '.tts.speech.microsoft.com';
    }
    return $basis . '/cognitiveservices/v1';
}

/**
 * Die Sätze an Azure schicken, ein paar gleichzeitig.
 *
 * Nacheinander dauerte eine Lerneinheit mit sechzig Vokabeln und drei
 * Sätzen gut zwei Minuten - im Hintergrund zwar, aber so lange stünde
 * "Hören" für die Klasse leer da.
 *
 * DRÜCKT AZURE AUF DIE BREMSE (HTTP 429), WIRD GEWARTET, NICHT AUFGEGEBEN.
 * Im kostenlosen Tarif sind es nur rund zwanzig Anfragen je Minute; beim
 * ersten Nachtragen auf vokidoki.de kamen 206 Aufnahmen durch, dann lehnte
 * Azure ab, und die übrigen 55 standen als Fehler da. Jetzt: so lange
 * warten, wie Azure im Kopf Retry-After sagt, denselben Satz noch einmal
 * schicken - und ab da nur noch einen zur Zeit. Was nach TTS_ZEITRAHMEN
 * nicht durch ist, fehlt eben und kommt beim nächsten Lauf.
 *
 * @param list<array{id:int, text:string}> $saetze
 * @return array<int, string|array{fehler:string}> MP3 je Satz, oder der Fehler
 */
function tts_anfragen(string $schluessel, array $saetze, array $stimme): array
{
    $ergebnis = [];
    $multi    = curl_multi_init();
    $laufend  = [];
    $warte    = $saetze;
    $versuche = [];
    $parallel = TTS_PARALLEL;
    $nichtVor = 0.0;                                   // Pause, die Azure verlangt hat
    $schluss  = microtime(true) + TTS_ZEITRAHMEN;

    $starten = static function (array $s) use ($multi, &$laufend, $schluessel, $stimme): void {
        $ssml = sprintf(
            "<speak version='1.0' xml:lang='%s'><voice name='%s'>%s</voice></speak>",
            $stimme['lang'], $stimme['name'],
            htmlspecialchars($s['text'], ENT_XML1 | ENT_QUOTES, 'UTF-8'),
        );
        $ch = curl_init(tts_endpunkt());
        $eintrag = ['ch' => $ch, 'satz' => $s, 'warten' => null];
        $laufend[(int) $ch] = &$eintrag;
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $ssml,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Ocp-Apim-Subscription-Key: ' . $schluessel,
                'Content-Type: application/ssml+xml',
                'X-Microsoft-OutputFormat: ' . TTS_FORMAT,
                'User-Agent: vokidoki',
            ],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 30,
            // Retry-After mitlesen - wie lange Azure Ruhe haben will.
            CURLOPT_HEADERFUNCTION => static function ($ch, string $zeile) use (&$eintrag): int {
                if (preg_match('/^Retry-After:\s*(\d+)/i', $zeile, $m) === 1) {
                    $eintrag['warten'] = (int) $m[1];
                }
                return strlen($zeile);
            },
        ]);
        curl_multi_add_handle($multi, $ch);
        unset($eintrag);
    };

    while (true) {
        while ($warte !== [] && count($laufend) < $parallel && microtime(true) >= $nichtVor) {
            $starten(array_shift($warte));
        }

        if ($laufend === []) {
            if ($warte === []) {
                break;
            }
            // Alle warten auf das Ende der Pause - oder die Zeit ist um.
            if ($nichtVor >= $schluss) {
                foreach ($warte as $s) {
                    $ergebnis[$s['id']] = ['fehler' => 'Azure antwortet mit HTTP 429 (zu viele Anfragen).'];
                }
                break;
            }
            usleep((int) (max(0.05, $nichtVor - microtime(true)) * 1_000_000));
            continue;
        }

        curl_multi_exec($multi, $aktiv);
        curl_multi_select($multi, 0.2);
        while (($info = curl_multi_info_read($multi)) !== false) {
            $ch      = $info['handle'];
            $eintrag = $laufend[(int) $ch];
            unset($laufend[(int) $ch]);
            $s = $eintrag['satz'];
            $id = $s['id'];

            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $body   = curl_multi_getcontent($ch);
            $versuche[$id] = ($versuche[$id] ?? 0) + 1;

            if ($info['result'] !== CURLE_OK) {
                $ergebnis[$id] = ['fehler' => 'Azure nicht erreichbar: ' . curl_error($ch)];
            } elseif (($status === 429 || $status === 503) && $versuche[$id] < TTS_VERSUCHE
                      && microtime(true) < $schluss) {
                // Bremse: warten, wie verlangt (sonst wachsend), und einzeln weiter.
                $warten   = $eintrag['warten'] ?? 2 ** $versuche[$id];
                $nichtVor = max($nichtVor, microtime(true) + min(30, max(1, $warten)));
                $parallel = 1;
                array_unshift($warte, $s);
            } elseif ($status !== 200 || !is_string($body) || strlen($body) < 100) {
                $ergebnis[$id] = ['fehler' => 'Azure antwortet mit HTTP ' . $status
                    . ($status === 429 ? ' (zu viele Anfragen).' : '.')];
            } else {
                $ergebnis[$id] = $body;
            }
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
    }

    curl_multi_close($multi);
    return $ergebnis;
}

/** Dateien, zu denen kein Satz mehr gehört - etwa nach dem Löschen eines Kurses. */
function tts_waisen_entfernen(): int
{
    $bekannt = array_flip(array_column(qa('SELECT file FROM sentence_audio'), 'file'));
    $weg = 0;
    foreach (glob(storage_path('audio/*.mp3')) ?: [] as $pfad) {
        if (!isset($bekannt['audio/' . basename($pfad)]) && @unlink($pfad)) {
            $weg++;
        }
    }
    return $weg;
}
