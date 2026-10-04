/*
 * Aus Fehlern lernen: die schwächsten Aufgaben, nach jeder Antwort neu.
 *
 * Eine Aufgabe ist eine Vokabel in einer Übung. Fünfzehn Vokabeln mit Satz
 * und Aufnahme sind sechzig Aufgaben; zehn davon saßen bisher immer. Im
 * Topf (fehlerTopf() in vorrat.js) stehen die fünfzig schwächsten - also
 * genau alle anderen. Und der Topf bewegt sich: Was sitzt, rutscht hinaus.
 */

import { execFileSync } from 'node:child_process';

import { browser, alsKind, ok, abschnitt, schlafe } from './browser.mjs';

const php = (wurzel, code) =>
    execFileSync('php', ['-r', code], { cwd: wurzel, encoding: 'utf8' });

export async function pruefe(f, aus, wurzel) {
    abschnitt('Aus Fehlern lernen');

    const d = JSON.parse(php(wurzel, `require 'lib/db.php'; require 'lib/tts.php';
        $k = q1('SELECT c.*, l.code FROM courses c JOIN languages l ON l.id = c.language_id WHERE c.id = ?', [${f.kurs}]);
        q("INSERT INTO units (language_id, course_id, title, released_position, position, sentences_status)
           VALUES (?, ?, 'Fehler-Probe', 15, 94, 'done')", [(int) $k['language_id'], (int) $k['id']]);
        $u = (int) db()->lastInsertId();
        $kind = (int) qv('SELECT id FROM users WHERE username = ?', ['${f.kind}']);
        $stimme = tts_stimme((string) $k['code']);
        $v = [];
        for ($i = 0; $i < 15; $i++) {
            q('INSERT INTO vocab (unit_id, position, term_foreign, term_native) VALUES (?, ?, ?, ?)',
              [$u, $i, 'word' . $i, 'Wort' . $i]);
            $v[$i] = (int) db()->lastInsertId();
            q('INSERT INTO sentences (vocab_id, native_text, foreign_text, answer) VALUES (?, ?, ?, ?)',
              [$v[$i], 'Satz ' . $i, 'This is {} now.', 'word' . $i]);
            $s = (int) db()->lastInsertId();
            q("INSERT INTO sentence_audio (sentence_id, voice, hash, file, bytes) VALUES (?, ?, ?, 'audio/probe.mp3', 1)",
              [$s, $stimme['name'], tts_hash(tts_satztext('This is {} now.', 'word' . $i), $stimme['name'], $stimme['code'])]);
        }
        // Zehn Aufgaben saßen immer (Auswählen bei word0..word9), eine ging immer daneben.
        for ($i = 0; $i < 10; $i++) {
            q("INSERT INTO progress (user_id, vocab_id, mode, streak, correct_count, wrong_count) VALUES (?, ?, 'mc', 0, 5, 0)",
              [$kind, $v[$i]]);
        }
        q("INSERT INTO progress (user_id, vocab_id, mode, streak, correct_count, wrong_count) VALUES (?, ?, 'cloze', 0, 0, 5)",
          [$kind, $v[10]]);
        echo json_encode(['unit' => $u, 'v' => $v]);`));

    const b = await browser({ port: 9485, breite: 390, hoehe: 844, handy: true, aus });
    try {
        await alsKind(b, f.basis, f.kind, f.passwort);
        const topf = () => b.js(`(async () => {
            const v = await import('${f.basis}/vorrat.js');
            return v.fehlerTopf([${d.unit}]).map((a) => a.karte.f + ':' + a.art);
        })()`);
        await b.js(`(async () => { const v = await import('${f.basis}/vorrat.js'); await v.vorratAuffrischen(); })()`);

        const t = await topf();
        ok('Im Topf stehen die 50 schwächsten Aufgaben', t.length === 50, String(t.length));
        ok('Die schwächste vorn - die, die immer danebenging', t[0] === 'word10:cloze', t[0]);
        ok('Was immer saß, ist nicht dabei',
           !t.some((a) => /^word[0-9]:mc$/.test(a)), JSON.stringify(t.filter((a) => a.endsWith(':mc'))));

        const gezogen = await b.js(`(async () => {
            const v = await import('${f.basis}/vorrat.js');
            const n = {}; let zweimal = 0, zuletzt = '';
            for (let i = 0; i < 400; i++) {
                const a = v.frageFehler([${d.unit}], {}, zuletzt);
                const schl = a.vocabId + ':' + a.art;
                if (schl === zuletzt) zweimal++;
                zuletzt = schl;
                n[schl] = (n[schl] ?? 0) + 1;
            }
            return { verschieden: Object.keys(n).length, zweimal,
                     sicher: Object.keys(n).filter((k) => [${d.v.slice(0, 10).join(',')}].includes(Number(k.split(':')[0])) && k.endsWith(':mc')).length };
        })()`);
        ok('Gezogen wird nur aus dem Topf', gezogen.sicher === 0, JSON.stringify(gezogen));
        ok('Und nie zweimal dieselbe hintereinander', gezogen.zweimal === 0, JSON.stringify(gezogen));

        // Der Topf bewegt sich: Die schwächste wird dreimal richtig beantwortet ...
        await b.js(`(async () => {
            const v = await import('${f.basis}/vorrat.js');
            for (let i = 0; i < 30; i++) v.freiMerken(${d.v[10]}, true, 'cloze');
            // ... und eine andere geht einmal schief: 1 von 2, schwächer als 30 von 35.
            v.freiMerken(${d.v[12]}, false, 'mc');
            v.freiMerken(${d.v[12]}, true, 'mc');
            for (const id of [${d.v.slice(11).join(',')}]) v.freiMerken(id, true, 'pick');
        })()`);
        const t2 = await topf();
        ok('Nach jeder Antwort neu: Vorn steht jetzt die schwächere, die frühere rückt nach',
           t2[0] === 'word12:mc' && t2[1] === 'word10:cloze', JSON.stringify(t2.slice(0, 3)));
        ok('Und was immer richtig war, rutscht hinaus - dafür rückt anderes nach',
           t2.length === 50 && !t2.includes('word11:pick') && t2.some((a) => /^word[0-9]:mc$/.test(a)),
           JSON.stringify(t2.slice(-6)));

        // ---- Die Oberfläche: in der Lerneinheit und im Kurs.
        await b.hash(`/unit/${d.unit}`, 1200);
        ok('Die Lerneinheit führt hinein, unter dem Freien Üben',
           await b.js(`(() => { const r = document.querySelector('[data-fehler]');
                const frei = document.querySelector('[data-frei]');
                return !!r && !!frei && (frei.compareDocumentPosition(r) & Node.DOCUMENT_POSITION_FOLLOWING) > 0
                    && r.textContent.includes('Aus Fehlern lernen'); })()`));
        await b.js(`document.querySelector('[data-fehler]').click()`);
        await schlafe(1200);
        const runde = await b.js(`({ adresse: location.hash, titel: document.querySelector('.topbar h1')?.textContent,
            leiste: !!document.querySelector('.freikopf'), aufgabe: !!document.querySelector('.option, #woerter, #answer, #satzlinie') })`);
        ok('Die Runde: eigener Titel, dieselbe Leiste, eine Aufgabe',
           runde.adresse === `#/unit/${d.unit}/fehler` && runde.titel === 'Aus Fehlern lernen'
           && runde.leiste && runde.aufgabe, JSON.stringify(runde));

        await b.hash(`/lang/${f.sprache}`, 1200);
        await b.js(`document.querySelector('[data-go^="/fehler/waehlen/"]').click()`);
        await schlafe(900);
        const wahl = await b.js(`({ titel: document.querySelector('.topbar h1')?.textContent,
            boxen: document.querySelectorAll('.wahlbox').length,
            erklaert: document.body.textContent.includes('50 Aufgaben') })`);
        ok('Im Kurs: dieselbe Auswahl der Lerneinheiten, mit Erklärung',
           wahl.titel === 'Aus Fehlern lernen' && wahl.boxen >= 1 && wahl.erklaert, JSON.stringify(wahl));
        await b.js(`document.getElementById('los').click()`);
        await schlafe(1200);
        ok('"Losüben" startet die Runde', (await b.js(`location.hash`)).startsWith('#/fehler/'));
    } finally {
        b.schliessen();
        php(wurzel, `require 'lib/db.php'; q('DELETE FROM units WHERE id = ?', [${d.unit}]);`);
    }
}
