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
    $code   = strtolower(trim((string) $code));
    $stimme = TTS_STIMMEN[$code] ?? null;
    return $stimme === null ? null : ['lang' => $stimme[0], 'name' => $stimme[1], 'code' => $code];
}

/** Der ganze Satz, wie er gesprochen wird: die Lücke mit der Lösung gefüllt. */
function tts_satztext(string $foreign, string $answer): string
{
    return trim(str_replace('{}', $answer, $foreign));
}

/**
 * Eine Vokabel, wie sie gesprochen wird - fürs Auswählen.
 *
 * Ohne das, was in Klammern steht: "a knife (pl. knives)" oder "en moster
 * (Schwester der Mutter)" sind Hinweise zum Lesen, nicht zum Sprechen, und
 * oft deutsch. Schrägstriche trennen Varianten ("en hund / en kat") - die
 * Stimme macht dort eine Pause statt "Schrägstrich" zu sagen.
 */
function tts_worttext(string $foreign): string
{
    $t = preg_replace('/\([^)]*\)|\[[^\]]*\]/u', ' ', $foreign) ?? $foreign;
    $t = preg_replace('/\s*\/\s*/u', ', ', $t) ?? $t;
    $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
    return trim($t, " \t,;");
}

/**
 * Kurzzeichen für das, was gesprochen wird, und die Stimme. Ändert sich
 * eines davon, passt die Aufnahme nicht mehr - etwa wenn im Admin ein Satz
 * verbessert wird oder ein Wort in die Ausspracheliste kommt.
 *
 * Gerechnet über die Sprechfassung (tts_sprechfassung()), nicht über den
 * Satz allein: Sonst hielte tts_offen() eine Aufnahme, die "kat." noch als
 * "katalog" las, für aktuell. Sätze, an denen die Sprechfassung nichts
 * ändert - eine Frage, ein Ausruf -, behalten ihr altes Kurzzeichen und
 * werden nicht noch einmal gesprochen.
 */
function tts_hash(string $text, string $voice, string $code = ''): string
{
    $f = tts_sprechfassung($text, $code);
    return $f['merkmal'] === null
        ? tts_hash_alt($text, $voice)
        : substr(sha1($voice . "\n" . TTS_SPRECHFASSUNG . "\n" . $f['merkmal']), 0, 12);
}

/** Das Kurzzeichen, wie es vor der Sprechfassung gerechnet wurde - nur Stimme und Satz. */
function tts_hash_alt(string $text, string $voice): string
{
    return substr(sha1($voice . "\n" . $text), 0, 12);
}

/**
 * Passt diese Aufnahme noch zum Satz? Für das Bündel (api/bundle.php).
 *
 * Auch die alte Fassung desselben Satzes passt, bis die neue gesprochen
 * ist: Ein "katalog" am Satzende ist ärgerlich, aber kein Grund, den Satz
 * bis dahin aus "Hören" zu nehmen. Ein geänderter Satz dagegen passt nicht -
 * dann sagte die Aufnahme etwas anderes, als dasteht.
 */
function tts_passt(string $hash, string $text, array $stimme): bool
{
    return $hash === tts_hash($text, $stimme['name'], $stimme['code'] ?? '')
        || $hash === tts_hash_alt($text, $stimme['name']);
}

/*
 * Die Fassung der Regeln in tts_sprechfassung(). Ändern sich die Regeln,
 * eins hochzählen - dann gilt jede betroffene Aufnahme als veraltet.
 */
const TTS_SPRECHFASSUNG = 'v2';

/**
 * Was an Azure geht: der Satz, so vorbereitet, dass die Stimme ihn liest
 * wie ein Mensch.
 *
 * 1. Kein Punkt am Ende. Azure löst vor dem Sprechen Abkürzungen auf, und
 *    im Dänischen ist "mia." die übliche für "milliard", "kat." die für
 *    "katalog": "Mit navn er Mia." hiess "... milliard", "Jeg har en kat."
 *    hiess "... katalog". Stattdessen sagt <s> der Stimme, dass hier ein
 *    Satz endet - die Stimme senkt sich trotzdem. Fragezeichen und
 *    Ausrufezeichen bleiben: Sie kürzen nichts ab.
 * 2. Die Ausspracheliste (tts_aliase): Was danach noch falsch klingt,
 *    sprechen die Lehrkräfte und der Admin einzeln vor - als <sub alias>.
 *
 * merkmal ist null, wenn nichts davon gegriffen hat; sonst das, woran sich
 * die Aufnahme festmacht (tts_hash()).
 *
 * @return array{ssml:string, merkmal:?string}
 */
function tts_sprechfassung(string $text, string $code = ''): array
{
    $x = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

    $satz    = $text;
    $geaendert = false;
    // Ein Punkt, keine Auslassungspunkte: "Jeg hedder ..." soll in der Schwebe bleiben.
    if (preg_match('/(?<![.…])\.\s*$/u', $satz) === 1) {
        $satz = rtrim(preg_replace('/\.\s*$/u', '', $satz));
        $geaendert = true;
    }

    $treffer = [];
    $aliase  = $code === '' ? [] : tts_aliase_fuer($code);
    if ($aliase !== []) {
        // Längere zuerst - "en kat" vor "kat", falls beide auf der Liste stehen.
        uksort($aliase, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $muster = '/(?<![\p{L}\p{N}])(' . implode('|', array_map(
            static fn (string $w): string => preg_quote($w, '/'), array_keys($aliase))) . ')(?![\p{L}\p{N}])/iu';
        $teile = preg_split($muster, $satz, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$satz];
        $ssml  = '';
        foreach ($teile as $i => $teil) {
            if ($i % 2 === 0) {
                $ssml .= $x($teil);
                continue;
            }
            $alias = $aliase[mb_strtolower($teil)] ?? null;
            if ($alias === null) {
                $ssml .= $x($teil);
                continue;
            }
            $ssml .= '<sub alias="' . $x($alias) . '">' . $x($teil) . '</sub>';
            $treffer[] = mb_strtolower($teil) . '=' . $alias;
        }
    } else {
        $ssml = $x($satz);
    }

    $merkmal = null;
    if ($geaendert || $treffer !== []) {
        sort($treffer);
        $merkmal = $satz . "\n" . implode("\n", $treffer);
    }
    return ['ssml' => '<s>' . $ssml . '</s>', 'merkmal' => $merkmal];
}

/**
 * Die Ausspracheliste einer Sprache: Wort (klein) => wie es klingen soll.
 *
 * Eine Liste für alle Schulen - wie ein Wort klingt, hängt nicht an der
 * Klasse. Je Anfrage einmal gelesen.
 *
 * @return array<string, string>
 */
function tts_aliase_fuer(string $code): array
{
    $cache = &tts_aliase_cache();
    if (!table_exists_tts_aliase()) {
        return [];
    }
    return $cache[$code] ??= array_column(
        qa('SELECT LOWER(wort) AS wort, aussprache FROM tts_aliase WHERE sprache = ?', [$code]),
        'aussprache', 'wort');
}

/** Vor der Schemaänderung gibt es die Tabelle noch nicht - dann eben ohne Liste. */
function table_exists_tts_aliase(): bool
{
    static $da = null;
    return $da ??= qv("SELECT COUNT(*) FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tts_aliase'") > 0;
}

/** Die Liste neu lesen - nach einer Änderung, im selben Lauf, der dann neu spricht. */
function tts_aliase_vergessen(): void
{
    $cache = &tts_aliase_cache();
    $cache = [];
}

/** @return array<string, array<string, string>> */
function &tts_aliase_cache(): array
{
    static $cache = [];
    return $cache;
}

/**
 * Der Pfad der Datei unter daten/storage - mit dem Kurzzeichen im Namen.
 * Ein Satz heisst nach seiner Nummer, eine Vokabel bekommt ein "w" davor.
 */
function tts_datei(int|string $kennung, string $hash): string
{
    return 'audio/' . $kennung . '-' . $hash . '.mp3';
}

function tts_aktiv(): bool
{
    return setting('tts_enabled') === '1';
}

/**
 * Was in einer Lerneinheit noch eine passende Aufnahme braucht: die Sätze
 * fürs Hören und die freigegebenen Vokabeln fürs Auswählen.
 *
 * id ist "s12" für einen Satz und "w45" für eine Vokabel - eindeutig in
 * einem Lauf (tts_anfragen() führt die Antworten danach); nr ist die
 * Nummer in ihrer Tabelle.
 *
 * @return list<array{id:string, art:string, nr:int, text:string, hash:string, alte_datei:?string}>
 */
function tts_offen(int $unitId, array $stimme): array
{
    $offen = [];
    foreach (qa(
        'SELECT v.id, v.term_foreign, a.hash AS alt, a.file AS alte_datei
           FROM vocab v
           JOIN units u ON u.id = v.unit_id
           LEFT JOIN vocab_audio a ON a.vocab_id = v.id
          WHERE v.unit_id = ? AND v.position < u.released_position
          ORDER BY v.position',
        [$unitId],
    ) as $w) {
        $text = tts_worttext((string) $w['term_foreign']);
        $hash = tts_hash($text, $stimme['name'], $stimme['code'] ?? '');
        if ($text === '' || $w['alt'] === $hash) {
            continue;
        }
        $offen[] = ['id' => 'w' . $w['id'], 'art' => 'wort', 'nr' => (int) $w['id'], 'text' => $text,
                    'hash' => $hash, 'alte_datei' => $w['alte_datei']];
    }
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
        $hash = tts_hash($text, $stimme['name'], $stimme['code'] ?? '');
        if ($text === '' || $s['alt'] === $hash) {
            continue;
        }
        $offen[] = ['id' => 's' . $s['id'], 'art' => 'satz', 'nr' => (int) $s['id'], 'text' => $text,
                    'hash' => $hash, 'alte_datei' => $s['alte_datei']];
    }
    return $offen;
}

/** Wie viele Aufnahmen einer Lerneinheit noch fehlen - Sätze und Vokabeln. */
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
        $wort  = ($s['art'] ?? 'satz') === 'wort';
        $datei = tts_datei($wort ? 'w' . $s['nr'] : $s['nr'], $s['hash']);
        $pfad  = storage_path($datei);
        if (!is_dir(dirname($pfad))) {
            @mkdir(dirname($pfad), 0775, true);
        }
        if (@file_put_contents($pfad, $mp3) === false) {
            $fehler ??= 'Die Aufnahme liess sich nicht speichern.';
            continue;
        }
        q(($wort ? 'INSERT INTO vocab_audio (vocab_id' : 'INSERT INTO sentence_audio (sentence_id')
          . ', voice, hash, file, bytes)
           VALUES (?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE voice = VALUES(voice), hash = VALUES(hash),
                                   file = VALUES(file), bytes = VALUES(bytes),
                                   created_at = NOW()',
          [$s['nr'], $stimme['name'], $s['hash'], $datei, strlen($mp3)]);
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
            tts_sprechfassung($s['text'], $stimme['code'] ?? '')['ssml'],
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
    $bekannt = array_flip(array_column(qa('SELECT file FROM sentence_audio
                                           UNION ALL SELECT file FROM vocab_audio'), 'file'));
    $weg = 0;
    foreach (glob(storage_path('audio/*.mp3')) ?: [] as $pfad) {
        if (!isset($bekannt['audio/' . basename($pfad)]) && @unlink($pfad)) {
            $weg++;
        }
    }
    return $weg;
}

// ---------------------------------------------------------------- Ausspracheliste

/*
 * Wörter, die die Stimme falsch liest, und wie sie klingen sollen.
 *
 * Eine Liste für alle Schulen und Konten: Wie "fx" im Dänischen klingt,
 * hängt nicht an einer Klasse, und wer es einmal richtigstellt, stellt es
 * für alle richtig. Gepflegt im Admin (admin/aussprache.php); dazu kommt
 * ein Wort auch aus einer Meldung, wenn eine Lehrkraft beim Anhören merkt,
 * dass die Stimme danebenliegt (lib/meldungen.php).
 *
 * Wirkt über tts_sprechfassung(): Das Wort geht als <sub alias> an Azure,
 * und weil die Liste in das Kurzzeichen eingeht, gilt jede Aufnahme mit
 * dem Wort danach als veraltet - tts_alias_nachsprechen() spricht sie neu.
 */

/** @return list<array{id:int, sprache:string, wort:string, aussprache:string, created_at:string}> */
function tts_alias_liste(): array
{
    if (!table_exists_tts_aliase()) {
        return [];
    }
    return array_map(static fn (array $r): array => ['id' => (int) $r['id']] + $r,
        qa('SELECT id, sprache, wort, aussprache, created_at FROM tts_aliase ORDER BY sprache, wort'));
}

/**
 * Ein Wort aufnehmen oder ändern. Gibt einen Fehlertext zurück, oder null.
 *
 * Gibt es das Wort in der Sprache schon, wird seine Aussprache ersetzt -
 * zwei Einträge für dasselbe Wort wären zwei Antworten auf eine Frage.
 */
function tts_alias_setzen(string $sprache, string $wort, string $aussprache, int $id = 0): ?string
{
    $sprache    = strtolower(trim($sprache));
    $wort       = trim(preg_replace('/\s+/u', ' ', $wort) ?? '');
    $aussprache = trim(preg_replace('/\s+/u', ' ', $aussprache) ?? '');

    if (tts_stimme($sprache) === null) {
        return 'Für diese Sprache gibt es keine Stimme.';
    }
    if ($wort === '' || $aussprache === '') {
        return 'Es braucht das Wort und wie es klingen soll.';
    }
    if (mb_strlen($wort) > 64 || mb_strlen($aussprache) > 128) {
        return 'Das ist zu lang für ein Wort.';
    }
    if (mb_strtolower($wort) === mb_strtolower($aussprache)) {
        return 'Die Aussprache ist dieselbe wie das Wort - so ändert sich nichts.';
    }

    $vorher = $id > 0 ? q1('SELECT * FROM tts_aliase WHERE id = ?', [$id]) : null;
    if ($vorher !== null) {
        q('UPDATE tts_aliase SET sprache = ?, wort = ?, aussprache = ? WHERE id = ?',
          [$sprache, $wort, $aussprache, $id]);
    } else {
        q('INSERT INTO tts_aliase (sprache, wort, aussprache) VALUES (?, ?, ?)
           ON DUPLICATE KEY UPDATE aussprache = VALUES(aussprache), wort = VALUES(wort)',
          [$sprache, $wort, $aussprache]);
    }
    tts_aliase_vergessen();
    return null;
}

/** Einen Eintrag löschen - die Sprache und das Wort, damit neu gesprochen werden kann. */
function tts_alias_loeschen(int $id): ?array
{
    $zeile = q1('SELECT sprache, wort FROM tts_aliase WHERE id = ?', [$id]);
    if ($zeile !== null) {
        q('DELETE FROM tts_aliase WHERE id = ?', [$id]);
        tts_aliase_vergessen();
    }
    return $zeile;
}

/**
 * Die Sätze mit diesem Wort neu sprechen - in jeder Lerneinheit, in der es
 * vorkommt, auf Rechnung des jeweiligen Kurses.
 *
 * Läuft nach der Antwort (die Seiten leiten zuerst weiter); was nicht
 * durchkommt, holt die nächste Freigabe nach. Gibt zurück, wie viele
 * Aufnahmen entstanden.
 */
function tts_alias_nachsprechen(string $sprache, string $wort): int
{
    require_once __DIR__ . '/courses.php';

    $muster = '%' . addcslashes($wort, '\\%_') . '%';
    $einheiten = qa(
        'SELECT DISTINCT u.id, u.course_id
           FROM sentences s
           JOIN vocab v     ON v.id = s.vocab_id
           JOIN units u     ON u.id = v.unit_id
           JOIN languages l ON l.id = u.language_id
          WHERE l.code = ? AND (s.foreign_text LIKE ? OR s.answer LIKE ? OR v.term_foreign LIKE ?)',
        [$sprache, $muster, $muster, $muster],
    );

    $erzeugt = 0;
    foreach ($einheiten as $u) {
        $zahler = $u['course_id'] === null ? null : course_billing_user((int) $u['course_id']);
        if ($zahler === null) {
            continue;
        }
        $erzeugt += tts_nachtragen((int) $u['id'], $zahler)['erzeugt'];
    }
    return $erzeugt;
}
