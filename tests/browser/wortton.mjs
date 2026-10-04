/*
 * Die Aussprache beim Auswählen (views/wortton.js): ein Lautsprecher neben
 * dem Wort in der Fremdsprache - bei der Frage oder bei jeder Möglichkeit.
 * Die Aufnahmen spricht hier der Azure-Simulator.
 */

import { execFileSync } from 'node:child_process';

import { browser, alsKind, ok, abschnitt, schlafe } from './browser.mjs';

const php = (wurzel, code) =>
    execFileSync('php', ['-r', code], { cwd: wurzel, encoding: 'utf8' });

export async function pruefe(f, aus, wurzel) {
    abschnitt('Auswählen: die Aussprache der Vokabeln');

    const einheit = Number(php(wurzel, `require 'lib/db.php'; require 'lib/tts.php'; require 'lib/courses.php';
        $k = q1('SELECT * FROM courses WHERE id = ?', [${f.kurs}]);
        q("INSERT INTO units (language_id, course_id, title, released_position, position)
           VALUES (?, ?, 'Ton-Probe', 4, 95)", [(int) $k['language_id'], (int) $k['id']]);
        $u = (int) db()->lastInsertId();
        foreach ([['the lamp', 'die Lampe'], ['the chair', 'der Stuhl'], ['the door', 'die Tür'], ['the window', 'das Fenster']] as $i => [$w, $d]) {
            q('INSERT INTO vocab (unit_id, position, term_foreign, term_native) VALUES (?, ?, ?, ?)', [$u, $i, $w, $d]);
        }
        tts_nachtragen($u, course_billing_user((int) $k['id']));
        echo $u;`));

    const b = await browser({ port: 9484, breite: 390, hoehe: 844, handy: true, aus });
    try {
        await alsKind(b, f.basis, f.kind, f.passwort, f.kuerzel);
        await b.js(`(async () => { const v = await import('${f.basis}/vorrat.js'); await v.vorratAuffrischen(); })()`);

        const gesehen = {};
        for (let i = 0; i < 30 && !(gesehen.vorn && gesehen.optionen); i++) {
            await b.hash('/', 200);
            await b.hash(`/quiz/${einheit}`, 800);
            const r = await b.js(`({
                vorn: document.querySelectorAll('.wortzeile .tonknopf').length,
                optionen: document.querySelectorAll('.optzeile .tonknopf').length,
                anzahl: document.querySelectorAll('.option').length,
                deutsch: document.querySelector('.dir')?.textContent.startsWith('Deutsch'),
            })`);
            if (r.deutsch) gesehen.optionen ??= r; else gesehen.vorn ??= r;
        }
        ok('Fragt die Fremdsprache, steht der Lautsprecher neben dem Wort',
           gesehen.vorn?.vorn === 1 && gesehen.vorn?.optionen === 0, JSON.stringify(gesehen.vorn));
        ok('Fragt Deutsch, steht neben jeder Möglichkeit einer',
           gesehen.optionen?.optionen === gesehen.optionen?.anzahl && gesehen.optionen?.vorn === 0,
           JSON.stringify(gesehen.optionen));

        // Ein Druck auf den Lautsprecher spielt - und wählt nichts aus.
        const druck = await b.js(`(async () => {
            const k = document.querySelector('.tonknopf');
            k.click();
            await new Promise((r) => setTimeout(r, 300));
            return { gesperrt: document.getElementById('options').classList.contains('locked'),
                     urteil: document.getElementById('verdict').textContent,
                     adresse: k.dataset.ton };
        })()`);
        ok('Ein Druck auf den Lautsprecher wählt keine Antwort',
           !druck.gesperrt && druck.urteil === '', JSON.stringify(druck));
        ok('Und spielt die Aufnahme der Vokabel (api/audio.php?w=)',
           /api\/audio\.php\?w=\d+&h=/.test(druck.adresse ?? ''), druck.adresse);

        // Eine Sprache ohne Stimme: kein Lautsprecher.
        const sprache = Number(php(wurzel, `require 'lib/db.php'; echo (int) qv('SELECT language_id FROM units WHERE id = ?', [${einheit}]);`));
        const code = php(wurzel, `require 'lib/db.php'; echo qv('SELECT code FROM languages WHERE id = ?', [${sprache}]);`);
        php(wurzel, `require 'lib/db.php'; q("UPDATE languages SET code = 'la' WHERE id = ?", [${sprache}]);`);
        await b.js(`(async () => { const v = await import('${f.basis}/vorrat.js'); await v.vorratAuffrischen(); })()`);
        await b.hash('/', 200);
        await b.hash(`/quiz/${einheit}`, 800);
        ok('In Latein gibt es keinen Lautsprecher',
           await b.js(`document.querySelectorAll('.tonknopf').length === 0`));
        php(wurzel, `require 'lib/db.php'; q('UPDATE languages SET code = ? WHERE id = ?', ['${code}', ${sprache}]);`);
        await schlafe(100);
    } finally {
        b.schliessen();
        php(wurzel, `require 'lib/db.php'; q('DELETE FROM units WHERE id = ?', [${einheit}]);`);
    }
}
