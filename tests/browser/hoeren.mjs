/*
 * Hören: den Satz anhören und aus Wortknöpfen nachlegen - antippen oder
 * ziehen.
 *
 * Eine eigene Lerneinheit mit vier englischen Sätzen, gesprochen gegen
 * tests/fake-azure-tts.php. Am Ende einmal mit einem Sprachkürzel ohne
 * Stimme: Dort gibt es die Übung gar nicht (Latein).
 */

import { execFileSync } from 'node:child_process';
import { browser, alsKind, ok, abschnitt, schlafe } from './browser.mjs';

const php = (wurzel, code) =>
    execFileSync('php', ['-r', code], { cwd: wurzel, encoding: 'utf8' });

// Deutsch -> die Wörter des englischen Satzes in ihrer Reihenfolge.
const SAETZE = {
    'Die Katze schläft.':       ['The', 'cat', 'sleeps'],
    'Der Hund bellt laut.':     ['The', 'dog', 'barks', 'loudly'],
    'Der Vogel singt.':         ['The', 'bird', 'sings'],
    'Er schwimmt jeden Tag.':   ['He', 'swims', 'every', 'day'],
};

export async function pruefe(f, aus, wurzel) {
    abschnitt('Hören: Sätze nachlegen');

    const einheit = Number(php(wurzel, `require 'lib/db.php'; require_once 'lib/tts.php'; require_once 'lib/courses.php';
        $k = q1('SELECT * FROM courses WHERE id = ?', [${f.kurs}]);
        q("INSERT INTO units (language_id, course_id, title, released_position, position)
           VALUES (?, ?, 'Hören-Probe', 4, 97)", [(int) $k['language_id'], (int) $k['id']]);
        $u = (int) db()->lastInsertId();
        $woerter = [['the cat', 'die Katze', 'cat', 'Die Katze schläft.', 'The {} sleeps.'],
                    ['the dog', 'der Hund', 'dog', 'Der Hund bellt laut.', 'The {} barks loudly.'],
                    ['the bird', 'der Vogel', 'bird', 'Der Vogel singt.', 'The {} sings.'],
                    ['to swim', 'schwimmen', 'swims', 'Er schwimmt jeden Tag.', 'He {} every day.']];
        foreach ($woerter as $i => [$fr, $de, $a, $sn, $sf]) {
            q('INSERT INTO vocab (unit_id, position, term_foreign, term_native) VALUES (?, ?, ?, ?)',
              [$u, $i, $fr, $de]);
            q('INSERT INTO sentences (vocab_id, native_text, foreign_text, answer) VALUES (?, ?, ?, ?)',
              [(int) db()->lastInsertId(), $sn, $sf, $a]);
        }
        tts_nachtragen($u, course_billing_user((int) $k['id']));
        echo $u;`));
    const kindId = Number(php(wurzel, `require 'lib/db.php';
        echo (int) qv('SELECT id FROM users WHERE username = ?', ['${f.kind}']);`));
    const sprache = Number(php(wurzel, `require 'lib/db.php';
        echo (int) qv('SELECT language_id FROM courses WHERE id = ?', [${f.kurs}]);`));

    const b = await browser({ port: 9423, breite: 390, hoehe: 844, handy: true, aus });
    try {
        await alsKind(b, f.basis, f.kind, f.passwort);
        const auffrischen = () => b.js(`(async () => { const v = await import('${f.basis}/vorrat.js'); await v.vorratAuffrischen(); })()`);
        await auffrischen();

        // ---- In der Lerneinheit: Hören als vierte Übung, mit eigenem Ring.
        await b.hash(`/unit/${einheit}`, 1200);
        const ansicht = await b.js(`({
            reihen: [...document.querySelectorAll('#exercises [data-mode-row]')].map((r) => r.dataset.modeRow),
            text:   document.querySelector('[data-mode-row="listen"] .tiny')?.textContent ?? '',
            kopf:   [...document.querySelectorAll('.vocabkopf .ringzeichen')].map((z) => z.getAttribute('title')),
        })`);
        ok('Hören steht als letzte Übung da',
           JSON.stringify(ansicht.reihen) === JSON.stringify(['mc', 'pick', 'cloze', 'listen']),
           JSON.stringify(ansicht.reihen));
        ok('Mit allen vier Sätzen', ansicht.text === '0 von 4 gelernt', ansicht.text);
        ok('Und neben den Vokabeln mit eigenem Ring', ansicht.kopf.at(-1) === 'Hören', JSON.stringify(ansicht.kopf));

        await b.js(`document.querySelector('[data-mode="listen"]').click()`);
        await schlafe(1200);
        ok('Ein Druck öffnet die Übung', (await b.js('location.hash')) === `#/hoeren/${einheit}`);

        /** Was gerade auf dem Bildschirm steht. */
        const aufgabe = () => b.js(`({
            satz:    document.getElementById('native')?.textContent ?? '',
            unten:   [...document.querySelectorAll('#woerter .wort:not(.benutzt)')].map((k) => k.textContent.trim()),
            alle:    [...document.querySelectorAll('#woerter .wort')].map((k) => k.textContent.trim()),
            gelegt:  [...document.querySelectorAll('#satzlinie .wort')].map((k) => k.textContent.trim()),
            klassen: [...document.querySelectorAll('#satzlinie .wort')].map((k) => k.className),
            pruefen: !(document.getElementById('pruefen')?.disabled ?? true),
            weiter:  (document.getElementById('weiter')?.offsetParent ?? null) !== null,
            verdict: document.getElementById('verdict')?.className ?? '',
            native:  (document.getElementById('native')?.offsetParent ?? null) !== null,
            src:     document.getElementById('abspielen')?.dataset.src ?? '',
        })`);
        const tippe = (wort, oben = false) => b.js(`[...document.querySelectorAll('${oben ? '#satzlinie' : '#woerter'} .wort${oben ? '' : ':not(.benutzt)'}')]
            .find((k) => k.textContent.trim() === ${JSON.stringify(wort)}).click()`);

        let a = await aufgabe();
        let ziel = SAETZE[a.satz];
        ok('Unter der Zeile liegen die Wörter des Satzes und zwei, die nicht hineingehören',
           ziel && ziel.every((w) => a.alle.includes(w)) && a.alle.length === ziel.length + 2,
           JSON.stringify({ satz: a.satz, alle: a.alle }));
        ok('Die Übersetzung verrät den Satz nicht vorher', !a.native);
        ok('"Prüfen" geht erst, wenn etwas liegt', !a.pruefen);

        // ---- Die Aufnahme ist da und lässt sich laden.
        const ton = await b.js(`(async () => {
            const r = await fetch(${JSON.stringify(a.src)});
            const dauer = await new Promise((fertig) => {
                const t = new Audio(${JSON.stringify(a.src)});
                t.onloadedmetadata = () => fertig(t.duration);
                t.onerror = () => fertig(-1);
            });
            return { status: r.status, typ: r.headers.get('content-type'), dauer };
        })()`);
        ok('Die Aufnahme kommt als MP3 und lässt sich abspielen',
           ton.status === 200 && ton.typ === 'audio/mpeg' && ton.dauer > 0, JSON.stringify(ton));
        if (aus) await b.bild('hoeren');

        // ---- Antippen, zurücknehmen, richtig legen.
        await tippe(ziel[1]);
        await tippe(ziel[0]);
        a = await aufgabe();
        ok('Antippen legt die Wörter hinten an', JSON.stringify(a.gelegt) === JSON.stringify([ziel[1], ziel[0]])
           && a.pruefen, JSON.stringify(a.gelegt));
        ok('Und unten bleibt eine Lücke, damit nichts springt',
           a.unten.length === a.alle.length - 2 && a.alle.length === ziel.length + 2);
        await tippe(ziel[1], true);
        a = await aufgabe();
        ok('Ein gelegtes Wort antippen nimmt es zurück', JSON.stringify(a.gelegt) === JSON.stringify([ziel[0]]),
           JSON.stringify(a.gelegt));
        for (const w of ziel.slice(1)) await tippe(w);
        await b.js(`document.getElementById('pruefen').click()`);
        await schlafe(300);
        a = await aufgabe();
        ok('Richtig gelegt: grün, mit der Übersetzung darunter',
           a.klassen.every((k) => k.includes('good')) && a.verdict.includes('good') && a.native,
           JSON.stringify(a));
        await schlafe(1800);

        // ---- Falsch gelegt.
        a = await aufgabe();
        ziel = SAETZE[a.satz];
        for (const w of [...ziel].reverse()) await tippe(w);
        await b.js(`document.getElementById('pruefen').click()`);
        await schlafe(300);
        a = await aufgabe();
        const richtigText = await b.js(`document.getElementById('verdict').textContent`);
        ok('Falsch gelegt: rot, und der richtige Satz steht da',
           a.klassen.every((k) => k.includes('bad')) && a.verdict.includes('bad')
           && richtigText.includes(ziel.join(' ')), richtigText);
        ok('Es geht erst mit "Weiter" weiter', a.weiter);
        await b.js(`document.getElementById('weiter').click()`);
        await schlafe(400);

        // ---- Ziehen, mit Finger-Ereignissen wie auf dem Telefon.
        a = await aufgabe();
        ziel = SAETZE[a.satz];
        const finger = (type, x, y) => b.send('Input.dispatchTouchEvent', {
            type, touchPoints: type === 'touchEnd' ? [] : [{ x, y, id: 1 }],
        });
        const ziehe = async (von, nach) => {
            await finger('touchStart', von.x, von.y);
            for (let i = 1; i <= 8; i++) {
                await finger('touchMove', von.x + (nach.x - von.x) * i / 8, von.y + (nach.y - von.y) * i / 8);
                await schlafe(20);
            }
            await finger('touchEnd', nach.x, nach.y);
            await schlafe(200);
        };
        const mitte = (sel, wort) => b.js(`(() => {
            const k = [...document.querySelectorAll(${JSON.stringify(sel)})]
                .find((x) => x.textContent.trim() === ${JSON.stringify(wort)}).getBoundingClientRect();
            return { x: k.left + k.width / 2, y: k.top + k.height / 2, links: k.left - 4, rechts: k.right + 4 };
        })()`);
        const linie = () => b.js(`(() => { const r = document.getElementById('satzlinie').getBoundingClientRect();
            return { x: r.left + 30, y: r.top + r.height / 2, unten: r.bottom + 160 }; })()`);

        await ziehe(await mitte('#woerter .wort:not(.benutzt)', ziel[1]), await linie());
        a = await aufgabe();
        ok('In die Zeile gezogen, liegt das Wort dort', JSON.stringify(a.gelegt) === JSON.stringify([ziel[1]]),
           JSON.stringify(a.gelegt));
        // Das erste Wort vor das schon liegende ziehen - an die Stelle, an der man loslässt.
        const davor = await mitte('#satzlinie .wort', ziel[1]);
        await ziehe(await mitte('#woerter .wort:not(.benutzt)', ziel[0]), { x: davor.links, y: davor.y });
        a = await aufgabe();
        ok('Gezogen landet es dort, wo man loslässt - auch vorn',
           JSON.stringify(a.gelegt) === JSON.stringify([ziel[0], ziel[1]]), JSON.stringify(a.gelegt));
        // Ein gelegtes Wort aus der Zeile hinaus ziehen.
        const raus = await mitte('#satzlinie .wort', ziel[0]);
        await ziehe(raus, { x: raus.x, y: (await linie()).unten });
        a = await aufgabe();
        ok('Aus der Zeile gezogen, geht es zurück nach unten',
           JSON.stringify(a.gelegt) === JSON.stringify([ziel[1]]) && a.unten.includes(ziel[0]),
           JSON.stringify(a));
        ok('Und kein Abbild bleibt hängen', await b.js(`!document.querySelector('.wort-geist')`));

        // ---- Landet auf dem Server als eigene Übung.
        await b.js(`(async () => { const v = await import('${f.basis}/vorrat.js'); await v.warteschlangeSenden(); })()`);
        await schlafe(800);
        const stand = JSON.parse(php(wurzel, `require 'lib/db.php';
            echo json_encode(qa("SELECT p.mode, p.correct_count, p.wrong_count FROM progress p
                JOIN vocab v ON v.id = p.vocab_id WHERE v.unit_id = ? AND p.user_id = ?", [${einheit}, ${kindId}]));`));
        ok('Der Server führt den Lernstand als eigene Übung "listen"',
           stand.length > 0 && stand.every((p) => p.mode === 'listen')
           && stand.reduce((s, p) => s + Number(p.correct_count), 0) === 1
           && stand.reduce((s, p) => s + Number(p.wrong_count), 0) === 1,
           JSON.stringify(stand));

        // ---- Eine Sprache ohne Stimme: keine Übung, kein Ring.
        php(wurzel, `require 'lib/db.php'; q("UPDATE languages SET code = 'la' WHERE id = ?", [${sprache}]);`);
        await auffrischen();
        await b.hash('/', 400);
        await b.hash(`/unit/${einheit}`, 1200);
        const latein = await b.js(`({
            reihen: [...document.querySelectorAll('#exercises [data-mode-row]')].map((r) => r.dataset.modeRow),
            ringe:  document.querySelector('.vocabzeile .ringe')?.querySelectorAll('.ring').length,
        })`);
        ok('Ohne Stimme (Latein) gibt es kein Hören - keine Zeile, kein Ring',
           !latein.reihen.includes('listen') && latein.ringe === 3, JSON.stringify(latein));
    } finally {
        b.schliessen();
        php(wurzel, `require 'lib/db.php';
            q("UPDATE languages SET code = 'en' WHERE id = ?", [${sprache}]);
            q('DELETE FROM units WHERE id = ?', [${einheit}]);`);
    }
}

