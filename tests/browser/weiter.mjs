/*
 * Wohin der Knopf am Ende einer Übung führt.
 *
 * Dort stand "Noch einmal üben", und er setzte den Lernstand der gerade
 * geschafften Übung zurück - der naheliegendste Druck warf ein Kind, das
 * eben alles konnte, auf null. Jetzt führt er weiter: zur anderen Übungsart,
 * solange die offen ist, sonst ins Freie Üben.
 *
 * Jeder Fall wird in der Datenbank hergestellt und dann im Gerät nachgeladen
 * - das Ende einer Übung dreimal von Hand zu erspielen hiesse, jedesmal
 * dieselbe Strecke zu prüfen, die feiern.mjs schon prüft.
 */

import { execFileSync } from 'node:child_process';
import { browser, alsKind, ok, abschnitt, schlafe } from './browser.mjs';

const php = (wurzel, code) =>
    execFileSync('php', ['-r', code], { cwd: wurzel, encoding: 'utf8' });

export async function pruefe(f, aus, wurzel) {
    abschnitt('Weiter nach einer geschafften Übung');

    const vorher = php(wurzel, "require 'lib/db.php';"
        + "echo qv('SELECT released_position FROM units WHERE id = ?', [" + f.unit + "]);");

    /*
     * Eine einzige Vokabel frei, mit einem Satz. Dann entscheidet ihr
     * Lernstand allein, welche Übung geschafft ist.
     */
    const vokabel = Number(php(wurzel, "require 'lib/db.php';"
        + "q('UPDATE units SET released_position = 1 WHERE id = ?', [" + f.unit + "]);"
        + "$v = (int) qv('SELECT id FROM vocab WHERE unit_id = ? AND position = 0', [" + f.unit + "]);"
        + "if ((int) qv('SELECT COUNT(*) FROM sentences WHERE vocab_id = ?', [$v]) === 0) {"
        + "  q(\"INSERT INTO sentences (vocab_id, native_text, foreign_text, answer)"
        + "     VALUES (?, 'Ein Satz.', 'Hier {} fehlt.', 'x')\", [$v]); }"
        // Und die Aufnahmen dazu - sonst gaebe es kein Hören, zu dem es ginge.
        + "require_once 'lib/tts.php'; require_once 'lib/courses.php';"
        + "tts_nachtragen(" + f.unit + ", course_billing_user((int) qv('SELECT course_id FROM units WHERE id = ?', [" + f.unit + "])));"
        + "echo $v;"));

    /** Den Lernstand setzen: welche Übungsarten die Vokabel schon kann. */
    const lernstand = (modi) => php(wurzel, "require 'lib/db.php';"
        + "$u = (int) qv('SELECT id FROM users WHERE username = ?', ['" + f.kind + "']);"
        + "q('DELETE FROM progress WHERE user_id = ? AND vocab_id = ?', [$u, " + vokabel + "]);"
        + modi.map((m) => "q('INSERT INTO progress (user_id, vocab_id, mode, streak,"
            + " correct_count, known_at) VALUES (?, ?, ?, 3, 3, NOW())',"
            + " [$u, " + vokabel + ", '" + m + "']);").join(''));

    const b = await browser({ port: 9419, breite: 390, hoehe: 840, aus });
    try {
        await alsKind(b, f.basis, f.kind, f.passwort);

        /** Den Stand ins Gerät holen, die Übung öffnen, den Knopf ansehen. */
        const endeVon = async (uebung) => {
            await b.js(`(async () => {
                const v = await import('${f.basis}/vorrat.js');
                await v.warteschlangeSenden();
                await v.vorratAuffrischen();
            })()`);
            await b.hash('/', 600);
            await b.hash(`/${uebung}/${f.unit}`, 1500);
            return b.js(`(() => {
                const k = document.getElementById('weiter');
                return {
                    ende: document.querySelector('.celebrate h1')?.textContent ?? '',
                    text: k?.textContent.trim().replace(/\\s+/g, ' ') ?? '',
                    hantel: !!k?.querySelector('svg.hantel'),
                    nochmal: !!document.getElementById('again'),
                };
            })()`);
        };

        const klick = async () => {
            await b.js(`document.getElementById('weiter').click()`);
            await schlafe(900);
            return b.js('location.hash');
        };

        // ---- Auswählen geschafft: weiter zum Einsetzen, der nächsten Stufe.
        lernstand(['mc']);
        let e = await endeVon('quiz');
        ok('Nach dem Auswählen steht die Geschafft-Seite', e.ende === 'Auswählen geschafft!', e.ende);
        ok('Der Knopf führt zum Einsetzen',
           e.text === 'Mit \u{1F9E9} Einsetzen weitermachen', e.text);
        ok('"Noch einmal üben" gibt es nicht mehr', !e.nochmal);
        if (aus) await b.bild('weiter-einsetzen');
        ok('Ein Druck öffnet das Einsetzen', (await klick()) === `#/einsetzen/${f.unit}`);
        const mcStand = Number(php(wurzel, "require 'lib/db.php';"
            + "echo (int) qv(\"SELECT COUNT(*) FROM progress WHERE vocab_id = ? AND mode = 'mc'"
            + "  AND known_at IS NOT NULL\", [" + vokabel + "]);"));
        ok('Und das Auswählen bleibt geschafft', mcStand === 1,
           'der alte Knopf setzte es an dieser Stelle zurück');

        // ---- Einsetzen auch geschafft: weiter zum Lückentext.
        lernstand(['mc', 'pick']);
        e = await endeVon('einsetzen');
        ok('Nach dem Einsetzen steht die Geschafft-Seite', e.ende === 'Einsetzen geschafft!', e.ende);
        ok('Der Knopf führt zum Lückentext',
           e.text === 'Mit \u{270F}\u{FE0F} Lückentext weitermachen', e.text);
        ok('Ein Druck öffnet den Lückentext', (await klick()) === `#/cloze/${f.unit}`);

        // ---- Die drei ersten geschafft: weiter zum Hören, der letzten Stufe.
        lernstand(['mc', 'pick', 'cloze']);
        e = await endeVon('cloze');
        ok('Nach dem Lückentext führt der Knopf zum Hören',
           e.text === 'Mit \u{1F3A7} Hören weitermachen', e.text);
        ok('Ein Druck öffnet das Hören', (await klick()) === `#/hoeren/${f.unit}`);

        // ---- Lückentext und Hören geschafft: wieder von vorn, beim Auswählen.
        lernstand(['cloze', 'listen']);
        e = await endeVon('hoeren');
        ok('Nach dem Hören steht die Geschafft-Seite', e.ende === 'Hören geschafft!', e.ende);
        ok('Und der Knopf führt wieder von vorn, zum Auswählen',
           e.text === 'Mit \u{1F3AF} Auswählen weitermachen', e.text);
        ok('Ein Druck öffnet das Auswählen', (await klick()) === `#/quiz/${f.unit}`);

        // ---- Alles ausser Einsetzen geschafft.
        lernstand(['mc', 'cloze', 'listen']);
        e = await endeVon('cloze');
        ok('Ist nur noch das Einsetzen offen, führt der Lückentext dorthin',
           e.text === 'Mit \u{1F9E9} Einsetzen weitermachen', e.text);

        // ---- Alle vier geschafft.
        lernstand(['mc', 'pick', 'cloze', 'listen']);
        for (const uebung of ['quiz', 'einsetzen', 'cloze', 'hoeren']) {
            e = await endeVon(uebung);
            ok(`Sind alle vier geschafft, führt ${uebung} ins Freie Üben`,
               e.text === 'Freies Üben' && e.hantel, JSON.stringify(e));
        }
        if (aus) await b.bild('weiter-frei');
        ok('Ein Druck öffnet das Freie Üben', (await klick()) === `#/unit/${f.unit}/frei`);

        // ---- Ohne Lückensätze gibt es keinen Lückentext, zu dem es ginge.
        php(wurzel, "require 'lib/db.php';"
            + "q('DELETE s FROM sentences s JOIN vocab v ON v.id = s.vocab_id"
            + "   WHERE v.unit_id = ?', [" + f.unit + "]);");
        lernstand(['mc']);
        e = await endeVon('quiz');
        ok('Ohne Sätze führt das Auswählen ins Freie Üben statt vor eine leere Übung',
           e.text === 'Freies Üben', e.text);
    } finally {
        await b.schliessen();
        php(wurzel, "require 'lib/db.php';"
            + "q('UPDATE units SET released_position = ? WHERE id = ?', [" + Number(vorher)
            + ", " + f.unit + "]);");
    }
}
