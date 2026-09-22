/*
 * Melden - vom Knopf in der Übung bis zur Zahl am Zahnrad.
 *
 * Die PHP-Suiten prüfen den Strom und die Seite der Lehrkraft je für sich.
 * Ob beides zusammenhängt, zeigt erst der ganze Weg: Das Kind drückt, sagt
 * ja, die Warteschlange geht raus, und bei der Lehrkraft steht eine Zahl.
 */

import { execFileSync } from 'node:child_process';
import { browser, alsKind, alsLehrkraft, ok, abschnitt, schlafe } from './browser.mjs';

const php = (wurzel, code) =>
    execFileSync('php', ['-r', code], { cwd: wurzel, encoding: 'utf8' });

const FRAGE = 'Diese Vokabel deiner Lehrkraft melden?';

export async function pruefe(f, aus, wurzel) {
    abschnitt('Melden aus der Übung');

    const ausDerEinheit = "FROM vocab_flags f JOIN vocab v ON v.id = f.vocab_id"
                        + " WHERE v.unit_id = " + Number(f.unit);
    const zaehle = (was) => Number(php(wurzel,
        "require 'lib/db.php'; echo (int) qv('SELECT " + was + " " + ausDerEinheit + "');"));

    // Sauber anfangen, und Sätze muss es geben - sonst gibt es keinen Lückentext.
    php(wurzel, "require 'lib/db.php';"
        + "q('DELETE f " + ausDerEinheit + "');"
        + "foreach (qa('SELECT v.id, v.term_foreign, v.term_native FROM vocab v"
        + "             WHERE v.unit_id = ? AND NOT EXISTS"
        + "               (SELECT 1 FROM sentences s WHERE s.vocab_id = v.id)',"
        + "            [" + Number(f.unit) + "]) as $v) {"
        + "  q('INSERT INTO sentences (vocab_id, native_text, foreign_text, answer)"
        + "     VALUES (?,?,?,?)',"
        + "    [$v['id'], 'Satz zu ' . $v['term_native'], 'Hier fehlt {} im Satz.',"
        + "     $v['term_foreign']]);"
        + "}");

    const b = await browser({ port: 9419, breite: 390, hoehe: 840, aus });
    try {
        await alsKind(b, f.basis, f.kind, f.passwort);

        // ---- Auswählen
        await b.hash('/quiz/' + f.unit, 2500);
        ok('Beim Auswählen steht der Knopf in der Fragekarte',
           await b.js(`!!document.querySelector('.prompt [data-melden]')`));
        await b.bild('melden-auswaehlen');

        /*
         * Die Rückfrage ist ein Teil der Seite, kein Kästchen des Browsers.
         * Ein confirm() würde hier mitgezählt - und headless sofort mit
         * "Abbrechen" beantwortet, sodass die Prüfung sonst blind wäre.
         */
        await b.js(`window.__browserbox = 0;
                    window.confirm = () => { window.__browserbox++; return false; }`);
        await b.js(`document.querySelector('[data-melden]').click()`);
        await schlafe(200);
        const frage = await b.js(`(() => {
            const d = document.querySelector('dialog.rueckfrage');
            return { offen: !!d?.open, text: d?.querySelector('.rueckfrage-text')?.textContent ?? '' };
        })()`);
        ok('Er fragt vorher nach - in der Seite', frage.offen && frage.text === FRAGE,
           JSON.stringify(frage));
        await b.bild('melden-rueckfrage');

        // Wer die Rückfrage verneint, hat nichts gemeldet.
        await b.js(`document.querySelector('dialog.rueckfrage [data-nein]').click()`);
        await schlafe(200);
        ok('Wer abbricht, meldet nichts',
           await b.js(`!document.querySelector('[data-melden]').disabled`));
        ok('Und die Rückfrage ist wieder weg',
           await b.js(`!document.querySelector('dialog.rueckfrage')`));

        await b.js(`document.querySelector('[data-melden]').click()`);
        await schlafe(200);
        await b.js(`document.querySelector('dialog.rueckfrage [data-ja]').click()`);
        await schlafe(200);
        ok('Nach dem Ja zeigt er einen Haken', await b.js(`(() => {
            const k = document.querySelector('[data-melden]');
            return k.disabled && k.textContent === '✓';
        })()`));

        // ---- Lückentext: schon vor der Antwort, und mit dem Getippten
        await b.hash('/cloze/' + f.unit, 2500);
        ok('Im Lückentext steht er neben Prüfen - schon vor der ersten Antwort',
           await b.js(`(() => {
               const k = document.querySelector('.cloze-actions [data-melden]');
               return !!k && !k.hidden && !k.disabled;
           })()`));
        await b.js(`(() => {
            document.querySelector('#answer').value = 'getippt';
            document.querySelector('[data-melden]').click();
        })()`);
        await schlafe(200);
        await b.js(`document.querySelector('dialog.rueckfrage [data-ja]').click()`);
        ok('Ein Kästchen des Browsers ist nie aufgegangen',
           (await b.js('window.__browserbox')) === 0);
        await b.bild('melden-lueckentext');

        // Die Warteschlange sammelt ein paar Sekunden, dann geht sie raus.
        await schlafe(5500);
        ok('Beide Meldungen kommen beim Server an', zaehle('COUNT(*)') === 2,
           zaehle('COUNT(*)') + ' Zeilen');
        ok('Die aus dem Lückentext mit dem, was im Feld stand',
           php(wurzel, "require 'lib/db.php'; echo qv('SELECT f.typed " + ausDerEinheit
                       + " AND f.sentence_id > 0');") === 'getippt');
    } finally {
        b.schliessen();
    }

    const vokabeln = zaehle('COUNT(DISTINCT f.vocab_id)');

    const l = await browser({ port: 9420, breite: 390, hoehe: 840, aus });
    try {
        await alsLehrkraft(l, f.basis, f.lehrer, f.passwort);
        await l.geh(f.basis + '/teacher/', 1200);

        ok('Am Zahnrad steht rot die Zahl der gemeldeten Vokabeln',
           (await l.js(`document.querySelector('#menuRechts > .burger .zaehler')?.textContent`))
               === String(vokabeln), `${vokabeln} erwartet`);
        // --bad im hellen Thema: #d8402f.
        ok('Die Zahl ist rot', (await l.js(`getComputedStyle(
            document.querySelector('#menuRechts > .burger .zaehler')).backgroundColor`))
            === 'rgb(216, 64, 47)');
        await l.bild('melden-zahnrad');

        await l.geh(f.basis + '/teacher/meldungen.php', 1200);
        ok('Die Seite zeigt eine Meldung', await l.js(`!!document.querySelector('form.meldung')`));
        await l.bild('melden-karte');

        // Eine nach der anderen - "Stimmt so" bringt die nächste.
        for (let i = 0; i < vokabeln; i++) {
            await l.js(`document.querySelector('form.meldung button[name=stimmt]').click()`);
            await schlafe(1200);
        }
        ok('Nach der letzten ist nichts mehr offen',
           await l.js(`document.body.textContent.includes('Keine offenen Meldungen')`));
        ok('Und das Zahnrad trägt keine Zahl mehr',
           await l.js(`!document.querySelector('.burger .zaehler')`));
        ok('In der Datenbank ist alles erledigt', zaehle('COUNT(*)') === 0);
    } finally {
        l.schliessen();
    }
}
