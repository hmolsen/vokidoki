/*
 * Der Gesamtfortschritt im Kurs: über alle Übungen, in Punkten.
 *
 * Jede Übung, in der eine Vokabel drankommen kann, bringt ihr drei Punkte -
 * einen je richtige Antwort in Folge, alle drei, wenn sie dort gekonnt ist.
 * Zwölf je Vokabel mit allen vier Übungen (einheitStatistik() in vorrat.js).
 * Vorher zählten nur Auswählen und Lückentext, und nur "gekonnt": Wer beim
 * Hören alles konnte, sah 0 %.
 *
 * Gerechnet wird im Gerät - deshalb hier und nicht in tests/e2e.php.
 */

import { execFileSync } from 'node:child_process';

import { browser, alsKind, ok, abschnitt } from './browser.mjs';

const php = (wurzel, code) =>
    execFileSync('php', ['-r', code], { cwd: wurzel, encoding: 'utf8' });

export async function pruefe(f, aus, wurzel) {
    abschnitt('Gesamtfortschritt: alle Übungen, in Punkten');

    /*
     * Zwei Vokabeln mit Satz und Aufnahme - also je vier Übungen, 24 Punkte.
     * Der Lernstand:
     *   A: Hören gekonnt (3), Auswählen einmal richtig (1)
     *   B: Lückentext zweimal richtig (2), Einsetzen gekonnt, danach
     *      aber falsch - gekonnt bleibt gekonnt (3)
     * Zusammen 9 von 24: 37,5 %, abgerundet 37.
     */
    const ids = JSON.parse(php(wurzel, `require 'lib/db.php'; require 'lib/tts.php';
        $k = q1('SELECT c.*, l.code FROM courses c JOIN languages l ON l.id = c.language_id WHERE c.id = ?', [${f.kurs}]);
        q("INSERT INTO units (language_id, course_id, title, released_position, position, sentences_status)
           VALUES (?, ?, 'Punkte-Probe', 2, 97, 'done')", [(int) $k['language_id'], (int) $k['id']]);
        $u = (int) db()->lastInsertId();
        $kind = (int) qv('SELECT id FROM users WHERE username = ?', ['${f.kind}']);
        $stimme = tts_stimme((string) $k['code']);
        $v = [];
        foreach ([['to run', 'rennen', 'runs', 'He {} fast.'], ['to swim', 'schwimmen', 'swims', 'She {} well.']] as $i => [$fr, $de, $a, $sf]) {
            q('INSERT INTO vocab (unit_id, position, term_foreign, term_native) VALUES (?, ?, ?, ?)', [$u, $i, $fr, $de]);
            $v[$i] = (int) db()->lastInsertId();
            q('INSERT INTO sentences (vocab_id, native_text, foreign_text, answer) VALUES (?, ?, ?, ?)',
              [$v[$i], 'Satz', $sf, $a]);
            $s = (int) db()->lastInsertId();
            q("INSERT INTO sentence_audio (sentence_id, voice, hash, file, bytes) VALUES (?, ?, ?, 'audio/probe.mp3', 1)",
              [$s, $stimme['name'], tts_hash(tts_satztext($sf, $a), $stimme['name'], $stimme['code'])]);
        }
        $stand = static fn (int $vid, string $m, int $serie, bool $gekonnt) => q(
            'INSERT INTO progress (user_id, vocab_id, mode, streak, correct_count, wrong_count, known_at)
             VALUES (?, ?, ?, ?, ?, 0, ?)', [$kind, $vid, $m, $serie, $serie, $gekonnt ? date('Y-m-d H:i:s') : null]);
        $stand($v[0], 'listen', 3, true);
        $stand($v[0], 'mc', 1, false);
        $stand($v[1], 'cloze', 2, false);
        $stand($v[1], 'pick', 0, true);
        echo json_encode(['unit' => $u, 'stimme' => $stimme !== null]);`));

    const b = await browser({ port: 9482, breite: 390, hoehe: 844, handy: true, aus });
    try {
        await alsKind(b, f.basis, f.kind, f.passwort);
        const statistik = () => b.js(`(async () => {
            const v = await import('${f.basis}/vorrat.js');
            await v.vorratAuffrischen();
            const s = v.einheitStatistik(${ids.unit});
            return { punkte: s.punkte, moeglich: s.moeglich, percent: s.percent, done: s.done };
        })()`);

        const s = await statistik();
        ok('Vier Übungen je Vokabel, drei Punkte je Übung: 24 für zwei Vokabeln',
           ids.stimme && s.moeglich === 24, JSON.stringify(s));
        ok('Gekonnt zählt drei, jede richtige in Folge einen - auch beim Hören',
           s.punkte === 9, JSON.stringify(s));
        ok('Abgerundet: 37 %, nicht 38', s.percent === 37, JSON.stringify(s));

        await b.hash(`/lang/${f.sprache}`, 1500);
        const zeile = await b.js(`(() => {
            const r = document.querySelector('.einheitzeile[data-unit="${ids.unit}"]');
            return r ? r.querySelector('.ez-p').textContent.replace(/\\s+/g, ' ').trim() : null;
        })()`);
        ok('Die Zeile der Lerneinheit im Kurs zeigt es', zeile === '37 %', zeile);

        /*
         * Die Lerneinheiten: eine Karte, kein Bild je Zeile, und der Anteil
         * steht rechts untereinander - egal wie lang der Titel ist.
         */
        const liste = await b.js(`(() => {
            const zeilen = [...document.querySelectorAll('.einheitenliste .einheitzeile')];
            const rechts = zeilen.map((z) => Math.round(z.querySelector('.ez-p').getBoundingClientRect().right));
            return { anzahl: zeilen.length, rechts: [...new Set(rechts)].length,
                     bilder: document.querySelectorAll('.einheitenliste .lead').length };
        })()`);
        ok('Die Lerneinheiten stehen in einer Karte, ohne Bild in jeder Zeile',
           liste.anzahl >= 2 && liste.bilder === 0, JSON.stringify(liste));
        ok('Und der Anteil steht rechts genau untereinander', liste.rechts === 1, JSON.stringify(liste));

        // "Insgesamt gelernt" klappt auf: jede Übung mit Zeichen, Anteil und Balken.
        const gesamt = await b.js(`(async () => {
            const d = document.querySelector('details.gesamt');
            const zu = { offen: d.open, sichtbar: d.querySelector('.ue').checkVisibility() };
            d.querySelector('summary').click();
            await new Promise((r) => setTimeout(r, 100));
            const ue = [...d.querySelectorAll('.ue')].map((u) => ({
                name: u.querySelector('.ue-n').textContent, p: u.querySelector('.ue-p').textContent.replace(/\\s/g, ' '),
                zeichen: u.querySelector('.ue-z').textContent !== '', balken: !!u.querySelector('.bar') }));
            return { zu, offen: d.open, ue };
        })()`);
        ok('Zu Anfang ist der Fortschritt zugeklappt', gesamt.zu.offen === false && !gesamt.zu.sichtbar,
           JSON.stringify(gesamt.zu));
        ok('Ein Druck klappt ihn auf: alle vier Übungen mit Zeichen, Anteil und Balken',
           gesamt.offen && ['Auswählen', 'Einsetzen', 'Lückentext', 'Hören'].every((n) => gesamt.ue.some((u) => u.name === n))
           && gesamt.ue.every((u) => u.zeichen && u.balken && /\d+ %/.test(u.p)), JSON.stringify(gesamt));

        // Ohne Aufnahmen gibt es kein Hören - dann neun Punkte je Vokabel,
        // und die Punkte vom Hören fallen mit heraus.
        php(wurzel, `require 'lib/db.php';
            q('DELETE a FROM sentence_audio a JOIN sentences s ON s.id = a.sentence_id
                JOIN vocab v ON v.id = s.vocab_id WHERE v.unit_id = ?', [${ids.unit}]);`);
        const ohne = await statistik();
        ok('Ohne Hören: neun Punkte je Vokabel', ohne.moeglich === 18 && ohne.punkte === 6,
           JSON.stringify(ohne));

        // Alles gekonnt - erst dann 100 % und fertig.
        php(wurzel, `require 'lib/db.php';
            $kind = (int) qv('SELECT id FROM users WHERE username = ?', ['${f.kind}']);
            foreach (qa('SELECT id FROM vocab WHERE unit_id = ?', [${ids.unit}]) as $v) {
                foreach (['mc', 'pick', 'cloze'] as $m) {
                    q('INSERT INTO progress (user_id, vocab_id, mode, streak, known_at) VALUES (?, ?, ?, 3, NOW())
                       ON DUPLICATE KEY UPDATE streak = 3, known_at = NOW()', [$kind, (int) $v['id'], $m]);
                }
            }`);
        const alles = await statistik();
        ok('Alles gekonnt: 100 % und fertig', alles.percent === 100 && alles.done === true,
           JSON.stringify(alles));
    } finally {
        b.schliessen();
        php(wurzel, `require 'lib/db.php'; q('DELETE FROM units WHERE id = ?', [${ids.unit}]);`);
    }
}
