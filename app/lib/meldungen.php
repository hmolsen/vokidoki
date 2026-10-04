<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/html.php';
require_once __DIR__ . '/courses.php';
require_once __DIR__ . '/tts.php';

/*
 * Meldungen: Ein Kind sagt "hier stimmt etwas nicht".
 *
 * Bis hierher ging das nur im Lückentext, nur nach einer falschen Antwort,
 * und die Meldung landete im Admin - bei jemandem, der die Klasse nicht
 * kennt. Die Lehrkraft, deren Unterlagen es waren, erfuhr nichts davon, und
 * dem Kind stand trotzdem "Deine Lehrkraft schaut sich die Aufgabe an" da.
 *
 * Jetzt geht es aus jeder Übung, und die Meldung erreicht die Lehrkräfte
 * des Kurses und den Admin. Gemeldet wird eine VOKABEL: Wenn fünf Kinder
 * über dasselbe Wort stolpern, ist das eine Sache, die zu richten ist, und
 * nicht fünf. Woran sie gestolpert sind - das Wortpaar beim Auswählen oder
 * ein bestimmter Satz im Lückentext -, steht in der Zeile mit dabei.
 *
 * Dieselben Regeln für Lehrkraft und Admin. Der einzige Unterschied ist der
 * Blickwinkel: $lehrerId schränkt auf die eigenen Kurse ein, null heisst
 * alle.
 */

/**
 * Eine Meldung aufnehmen - aus dem Ereignisstrom, siehe api/bundle.php.
 *
 * Ob das Kind die Vokabel sehen darf, hat der Aufrufer geprüft. Hier bleibt
 * die Frage, ob der Satz zu ihr gehört: Sonst liesse sich mit einer
 * erlaubten Vokabel ein beliebiger fremder Satz auf die Liste setzen.
 */
function meldung_aufnehmen(int $userId, int $vocabId, int $satzId, string $getippt,
                           string $modus = ''): bool
{
    require_once __DIR__ . '/progress.php';
    if ($satzId < 0) {
        return false;
    }
    if ($satzId > 0 && (int) qv('SELECT COUNT(*) FROM sentences WHERE id = ? AND vocab_id = ?',
                                [$satzId, $vocabId]) !== 1) {
        return false;
    }

    $getippt = mb_substr(trim($getippt), 0, 128);
    // Nur eine der Übungen - was sonst ankommt, ist kein Hinweis, sondern Lärm.
    $modus = in_array($modus, MODES, true) ? $modus : null;

    // Zweimal melden ändert nichts - der eindeutige Schlüssel fängt das ab.
    // Vermerkt wird nur der letzte Versuch.
    q(
        'INSERT INTO vocab_flags (vocab_id, sentence_id, user_id, typed, mode)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE typed = VALUES(typed), mode = VALUES(mode), created_at = NOW()',
        [$vocabId, $satzId, $userId, $getippt === '' ? null : $getippt, $modus],
    );
    return true;
}

/**
 * Der Teil der Abfrage, der für alle gleich ist.
 *
 * Eine Meldung zu einem Satz, den es nicht mehr gibt, zählt nicht: Der Satz
 * ist weg, und damit auch das, was falsch daran war. Erledigt wird sie
 * trotzdem mit, sobald die Vokabel erledigt wird.
 *
 * @return array{0: string, 1: array} SQL ab FROM und die Parameter dazu
 */
function meldungen_von(?int $lehrerId): array
{
    $sql = ' FROM vocab_flags f
             JOIN vocab v ON v.id = f.vocab_id
             JOIN units t ON t.id = v.unit_id
             LEFT JOIN sentences s ON s.id = f.sentence_id
            WHERE (f.sentence_id = 0 OR s.id IS NOT NULL)';
    if ($lehrerId === null) {
        return [$sql, []];
    }
    /*
     * "Eigener Kurs" heisst: als Lehrkraft Mitglied. Nicht courses.created_by
     * - eine Vertretung soll die Meldungen ihrer Klasse sehen, und wer den
     * Kurs einmal angelegt und ihn dann abgegeben hat, nicht mehr.
     */
    return [$sql . ' AND EXISTS (SELECT 1 FROM course_members m
                                 WHERE m.course_id = t.course_id
                                   AND m.user_id = ? AND m.member_role = ?)',
            [$lehrerId, COURSE_ROLE_TEACHER]];
}

/** Wie viele Vokabeln gemeldet sind - die Zahl am Zahnrad. */
function meldungen_zahl(?int $lehrerId): int
{
    [$von, $p] = meldungen_von($lehrerId);
    return (int) qv('SELECT COUNT(DISTINCT f.vocab_id)' . $von, $p);
}

/**
 * Die offenen Meldungen, die dringendste zuerst.
 *
 * Dringend heisst: von den meisten Kindern gemeldet. Bei gleich vielen kommt
 * die ältere zuerst - sie wartet schon länger.
 *
 * @return list<array{vocab_id: int, kinder: int}>
 */
function meldungen_offen(?int $lehrerId): array
{
    [$von, $p] = meldungen_von($lehrerId);
    return array_map(
        static fn (array $r): array => ['vocab_id' => (int) $r['vocab_id'],
                                        'kinder'   => (int) $r['kinder']],
        qa('SELECT f.vocab_id, COUNT(DISTINCT f.user_id) AS kinder, MIN(f.created_at) AS seit'
           . $von . ' GROUP BY f.vocab_id ORDER BY kinder DESC, seit, f.vocab_id', $p),
    );
}

/**
 * Welche Meldung die Seite zeigt: die gewünschte, wenn sie noch offen ist,
 * sonst die dringendste. 0, wenn keine offen ist.
 *
 * Gewünscht wird eine nach einem misslungenen Speichern - dann soll
 * dieselbe stehen bleiben, nicht die nächste nachrücken.
 */
function meldung_vorn(array $offen, int $gewuenscht): int
{
    foreach ($offen as $o) {
        if ($o['vocab_id'] === $gewuenscht) {
            return $gewuenscht;
        }
    }
    return $offen[0]['vocab_id'] ?? 0;
}

/**
 * Eine gemeldete Vokabel mit allem, was die Lehrkraft zum Entscheiden braucht.
 *
 * 'wahl' sind die Kinder, die beim Auswählen gemeldet haben - dann ist das
 * Wortpaar gemeint. 'saetze' sind die Lückensätze, jeder mit den Kindern,
 * die an ihm hängen geblieben sind, und dem, was sie getippt hatten.
 *
 * @return array|null null, wenn es nichts (mehr) gibt oder es fremd ist
 */
function meldung_laden(int $vocabId, ?int $lehrerId): ?array
{
    // Zuerst dieselbe Schranke wie in der Liste: Gibt es eine offene
    // Meldung, und darf dieser Blickwinkel sie sehen?
    [$von, $p] = meldungen_von($lehrerId);
    if (qv('SELECT 1' . $von . ' AND f.vocab_id = ? LIMIT 1', [...$p, $vocabId]) === null) {
        return null;
    }

    $v = q1(
        'SELECT v.id, v.term_foreign, v.term_native, t.title AS unit_title,
                co.name AS course_name, l.name AS language_name, l.code
           FROM vocab v
           JOIN units t     ON t.id = v.unit_id
           JOIN courses co  ON co.id = t.course_id
           JOIN languages l ON l.id = t.language_id
          WHERE v.id = ?',
        [$vocabId],
    );
    if ($v === null) {
        return null;
    }

    $wahl   = [];
    $saetze = [];
    $kinder = [];
    /*
     * Ohne Namen. Hier stand, wer gemeldet hat ("Mats B. tippte ...") - und
     * damit, dass Mats die App benutzt und wo er gerade übt. Das darf eine
     * Lehrkraft nicht erfahren: Die Kinder bestätigen bei der ersten
     * Anmeldung, dass sie nicht sieht, ob und wie sie üben
     * (lib/einwilligung.php). Für die Korrektur zählt ohnehin nur, WAS
     * getippt wurde. Gezählt werden die Kinder weiterhin - eine Zahl
     * verrät niemanden.
     */
    foreach (qa(
        'SELECT f.sentence_id, f.typed, f.user_id, f.mode,
                s.native_text, s.foreign_text, s.answer, a.hash AS ton
           FROM vocab_flags f
           LEFT JOIN sentences s ON s.id = f.sentence_id
           LEFT JOIN sentence_audio a ON a.sentence_id = s.id
          WHERE f.vocab_id = ? AND (f.sentence_id = 0 OR s.id IS NOT NULL)
          ORDER BY f.created_at',
        [$vocabId],
    ) as $f) {
        $kinder[(int) $f['user_id']] = true;
        $wer = ['typed' => (string) ($f['typed'] ?? ''), 'modus' => $f['mode'] ?? null];

        $sid = (int) $f['sentence_id'];
        if ($sid === 0) {
            $wahl[] = $wer;
            continue;
        }
        $saetze[$sid] ??= [
            'id'      => $sid,
            'native'  => (string) $f['native_text'],
            'foreign' => (string) $f['foreign_text'],
            'answer'  => (string) $f['answer'],
            'ton'     => $f['ton'] ?? null,
            'wer'     => [],
        ];
        $saetze[$sid]['wer'][] = $wer;
    }

    return [
        'id'       => (int) $v['id'],
        'foreign'  => (string) $v['term_foreign'],
        'native'   => (string) $v['term_native'],
        'unit'     => (string) $v['unit_title'],
        'kurs'     => (string) $v['course_name'],
        'sprache'  => (string) $v['language_name'],
        'code'     => $v['code'] ?? null,
        'kinder'   => count($kinder),
        'wahl'     => $wahl,
        'saetze'   => $saetze,
    ];
}

/** Alle Meldungen zu einer Vokabel sind erledigt. */
function meldung_erledigen(int $vocabId): int
{
    return q('DELETE FROM vocab_flags WHERE vocab_id = ?', [$vocabId])->rowCount();
}

/**
 * Das Formular der Seite auswerten: speichern oder "stimmt so".
 *
 * Gespeichert wird nur, was zur Meldung gehört - das Wortpaar, wenn beim
 * Auswählen gemeldet wurde, und genau die gemeldeten Sätze. Ein
 * untergeschobenes Feld für einen anderen Satz wird schlicht nicht gelesen.
 *
 * Alles oder nichts: Fällt ein Satz durch die Prüfung, bleibt auch das
 * Wortpaar, wie es war, und die Meldung bleibt offen. Sonst stünde die
 * Hälfte geändert da, und die Lehrkraft wüsste nicht, welche.
 *
 * @return array{0: bool, 1: string} Erfolg und was dazu zu sagen ist
 */
function meldung_bearbeiten(?int $lehrerId, array $post): array
{
    $vocabId = (int) ($post['meldung'] ?? 0);
    $m = meldung_laden($vocabId, $lehrerId);
    if ($m === null) {
        return [false, 'Diese Meldung ist schon erledigt.'];
    }

    if (isset($post['stimmt'])) {
        meldung_erledigen($vocabId);
        return [true, sprintf('„%s" bleibt, wie es ist.', $m['foreign'])];
    }

    require_once __DIR__ . '/sentences.php';
    require_once __DIR__ . '/vocab.php';

    $eingaben = is_array($post['s'] ?? null) ? $post['s'] : [];

    db()->beginTransaction();
    try {
        foreach ($m['saetze'] as $sid => $_satz) {
            $e = $eingaben[$sid] ?? null;
            if (!is_array($e)) {
                continue;
            }
            $ok = sentence_update($sid, (string) ($e['n'] ?? ''), (string) ($e['f'] ?? ''),
                                  (string) ($e['a'] ?? ''), $m['code']);
            if ($ok === null) {
                db()->rollBack();
                return [false, 'Nicht gespeichert - der Satz braucht '
                               . SENTENCE_FORM_HINT . '.'];
            }
        }

        if ($m['wahl'] !== [] && !vocab_update($vocabId, (string) ($post['f'] ?? ''),
                                               (string) ($post['n'] ?? ''), $m['code'])) {
            db()->rollBack();
            return [false, 'Nicht gespeichert - die Vokabel braucht beide Seiten.'];
        }

        meldung_erledigen($vocabId);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }

    return [true, 'Gesichert.'];
}

/**
 * "Die Stimme liest dieses Wort falsch" - aus einer Meldung auf die
 * Ausspracheliste (lib/tts.php).
 *
 * Nur ein Wort, das in dem gemeldeten Satz auch vorkommt: Die Liste gilt
 * für alle Schulen, und über eine Meldung soll nicht irgendein Wort auf
 * sie geraten. Die Meldung bleibt offen - erst die neue Aufnahme anhören,
 * dann "Stimmt so".
 *
 * @return array{0: bool, 1: string, 2: ?array{0: string, 1: string}}
 *         Erfolg, Text, und was danach neu zu sprechen ist (Sprache, Wort)
 */
function meldung_aussprache(?int $lehrerId, array $post): array
{
    $vocabId = (int) ($post['meldung'] ?? 0);
    $sid     = (int) ($post['aussprache'] ?? 0);
    $m       = meldung_laden($vocabId, $lehrerId);
    $satz    = $m['saetze'][$sid] ?? null;
    if ($m === null || $satz === null || tts_stimme($m['code']) === null) {
        return [false, 'Diese Meldung ist schon erledigt.', null];
    }

    $wort = trim((string) ($post['aw'][$sid] ?? ''));
    $text = tts_satztext($satz['foreign'], $satz['answer']);
    if ($wort === '' || preg_match('/(?<![\p{L}\p{N}])' . preg_quote($wort, '/') . '(?![\p{L}\p{N}])/iu', $text) !== 1) {
        return [false, 'Das Wort muss so im Satz stehen.', null];
    }

    $fehler = tts_alias_setzen((string) $m['code'], $wort, (string) ($post['aa'][$sid] ?? ''));
    if ($fehler !== null) {
        return [false, $fehler, null];
    }
    return [true, sprintf('„%s" steht jetzt auf der Ausspracheliste.', $wort),
            [(string) $m['code'], $wort]];
}

/**
 * Die Meldung als Karte mit Formular.
 *
 * Steht hier und nicht in den beiden Seiten, weil Lehrkraft und Admin
 * dieselbe Karte sehen - nur das Feld gegen fremde Formulare heisst an
 * beiden Stellen anders.
 */
function meldung_html(array $m, int $offen, string $csrfFeld): string
{
    // Wie oft, aus welcher Übung, und was getippt wurde - aber nicht, von
    // wem (siehe meldung_laden()).
    $wer = static function (array $liste, bool $mitGetipptem): string {
        if (!$mitGetipptem) {
            return sprintf('<p class="wer tiny muted">%s gemeldet</p>',
                count($liste) === 1 ? 'Einmal' : count($liste) . '-mal');
        }
        $zeilen = '';
        foreach ($liste as $w) {
            $wo = $w['modus'] !== null ? MELDUNG_UEBUNG[$w['modus']] . ': ' : '';
            $zeilen .= '<li>' . $wo . ($w['typed'] !== ''
                    ? 'getippt &bdquo;' . h($w['typed']) . '&ldquo;'
                    : 'ohne Eingabe gemeldet') . '</li>';
        }
        return '<ul class="wer tiny muted">' . $zeilen . '</ul>';
    };

    ob_start();
    ?>
<p class="tiny muted"><?= $offen === 1
    ? 'Das ist die letzte offene Meldung.'
    : 'Noch ' . $offen . ' offene Meldungen - die meistgemeldete steht vorn.' ?></p>

<form method="post" class="card meldung kontoform">
    <?= $csrfFeld ?>
    <input type="hidden" name="meldung" value="<?= (int) $m['id'] ?>">

    <p class="wo tiny muted"><?= h($m['kurs']) ?> &middot; <?= h($m['unit']) ?></p>
    <h2>&bdquo;<?= h($m['foreign']) ?>&ldquo; &ndash; <?= h($m['native']) ?></h2>
    <p><strong><?= $m['kinder'] === 1 ? '1 Kind hat' : $m['kinder'] . ' Kinder haben' ?></strong>
        diese Vokabel gemeldet.</p>

    <?php if ($m['wahl'] !== []): ?>
        <div class="stelle">
            <h3><?= h(MELDUNG_UEBUNG['mc']) ?></h3>
            <?= $wer($m['wahl'], false) ?>
            <label for="mf"><?= h($m['sprache']) ?></label>
            <input type="text" id="mf" name="f" value="<?= h($m['foreign']) ?>"
                   maxlength="255" required>
            <label for="mn">Deutsch</label>
            <input type="text" id="mn" name="n" value="<?= h($m['native']) ?>"
                   maxlength="255" required>
        </div>
    <?php endif; ?>

    <?php foreach ($m['saetze'] as $s): ?>
        <?php $id = (int) $s['id']; ?>
        <div class="stelle">
            <h3><?= h(meldung_uebungen($s['wer'])) ?></h3>
            <?= $wer($s['wer'], true) ?>
            <?php if ($s['ton'] !== null): ?>
                <?php
                /*
                 * Anhören, wie es die Kinder hören - gerade wenn beim Hören
                 * gemeldet wurde: Spricht die Stimme ein Wort falsch, sieht
                 * man das dem Satz nicht an.
                 */
                $ton = url('/api/audio.php?s=' . $id . '&h=' . rawurlencode((string) $s['ton']));
                ?>
                <div class="hoerprobe">
                    <button type="button" class="btn secondary small" data-hoerprobe="<?= h($ton) ?>"
                            data-tempo="1">&#128266; Anhören</button>
                    <button type="button" class="btn secondary small" data-hoerprobe="<?= h($ton) ?>"
                            data-tempo="<?= MELDUNG_LANGSAM ?>">&#128034; Langsam</button>
                </div>
                <?php
                /*
                 * Liest die Stimme ein Wort falsch, kommt es auf die
                 * Ausspracheliste - für alle Schulen (meldung_aussprache()).
                 * Zugeklappt: Meist ist am Satz etwas falsch, nicht an der
                 * Stimme.
                 */
                $woerter = array_values(array_unique(preg_split('/[^\p{L}\p{N}\'’-]+/u',
                    tts_satztext($s['foreign'], $s['answer']), -1, PREG_SPLIT_NO_EMPTY) ?: []));
                ?>
                <details class="aussprache">
                    <summary class="tiny">Die Stimme liest ein Wort falsch?</summary>
                    <div class="ausspracheform">
                        <label for="aw<?= $id ?>">Wort im Satz</label>
                        <input type="text" id="aw<?= $id ?>" name="aw[<?= $id ?>]" list="awl<?= $id ?>"
                               maxlength="64" autocomplete="off">
                        <datalist id="awl<?= $id ?>"><?php foreach ($woerter as $w): ?><option value="<?= h($w) ?>"><?php endforeach; ?></datalist>
                        <label for="aa<?= $id ?>">So soll es klingen</label>
                        <input type="text" id="aa<?= $id ?>" name="aa[<?= $id ?>]" maxlength="128"
                               autocomplete="off" placeholder="so geschrieben, wie man es spricht">
                        <button class="btn secondary small" name="aussprache" value="<?= $id ?>" formnovalidate>
                            Auf die Ausspracheliste</button>
                        <p class="tiny muted">Gilt für alle Sätze mit diesem Wort, an allen Schulen.</p>
                    </div>
                </details>
            <?php endif; ?>
            <label for="sn<?= $id ?>">Deutscher Satz</label>
            <input type="text" id="sn<?= $id ?>" name="s[<?= $id ?>][n]"
                   value="<?= h($s['native']) ?>" maxlength="255" required>
            <label for="sf<?= $id ?>"><?= h($m['sprache']) ?> (<code>{}</code> ist die Lücke)</label>
            <input type="text" id="sf<?= $id ?>" name="s[<?= $id ?>][f]"
                   value="<?= h($s['foreign']) ?>" maxlength="255" required>
            <label for="sa<?= $id ?>">Lösung</label>
            <input type="text" id="sa<?= $id ?>" name="s[<?= $id ?>][a]"
                   value="<?= h($s['answer']) ?>" maxlength="128" required>
        </div>
    <?php endforeach; ?>

    <div class="buttonrow">
        <button class="btn" name="sichern" value="1">Ändern</button>
        <button class="btn secondary" name="stimmt" value="1" formnovalidate>Stimmt so</button>
    </div>
</form>
<script>
// Die Knöpfe zum Anhören. Hier und nicht in teacher.js: Der Admin sieht
// dieselbe Karte und lädt teacher.js nicht.
(() => {
    let ton = null;
    document.querySelectorAll('[data-hoerprobe]').forEach((k) => k.addEventListener('click', () => {
        ton?.pause();
        ton = new Audio(k.dataset.hoerprobe);
        ton.preservesPitch = true;
        ton.playbackRate = Number(k.dataset.tempo) || 1;
        ton.play().catch(() => { k.disabled = true; k.title = 'Die Aufnahme lässt sich nicht abspielen.'; });
    }));
})();
</script>
    <?php
    return (string) ob_get_clean();
}

/*
 * Die Übungen, wie sie in der Karte heissen - die Schlüssel sind MODES
 * (lib/progress.php).
 */
const MELDUNG_UEBUNG = [
    'mc'     => 'Beim Auswählen',
    'pick'   => 'Beim Einsetzen',
    'cloze'  => 'Im Lückentext',
    'listen' => 'Beim Hören',
];

/* So langsam wie der Schildkrötenknopf beim Hören (LANGSAM in views/hoeren.js). */
const MELDUNG_LANGSAM = 0.7;

/**
 * Die Überschrift über einem gemeldeten Satz: aus welchen Übungen.
 *
 * Meldungen von vor der Zeit, als die Übung mitkam, wissen es nicht -
 * sie standen alle unter "Im Lückentext", auch die aus dem Einsetzen.
 */
function meldung_uebungen(array $wer): string
{
    $modi = array_values(array_unique(array_filter(array_column($wer, 'modus'))));
    if ($modi === []) {
        return 'In einem Satz';
    }
    return implode(' · ', array_map(static fn (string $m): string => MELDUNG_UEBUNG[$m], $modi));
}
