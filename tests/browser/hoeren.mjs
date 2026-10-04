/*
 * Hören: den Satz anhören und aus Wortknöpfen nachlegen - antippen oder
 * ziehen.
 *
 * Eine eigene Lerneinheit mit vier englischen Sätzen, gesprochen gegen
 * tests/fake-azure-tts.php. Am Ende einmal mit einem Sprachkürzel ohne
 * Stimme: Dort gibt es die Übung gar nicht (Latein).
 */

import { execFileSync, spawn } from 'node:child_process';
import { resolve } from 'node:path';
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

        // ---- Freies Üben: alle Übungsarten, und ein Schalter für Hören.
        await b.hash(`/unit/${einheit}/frei`, 1200);
        const frei = await b.js(`(async () => {
            const v = await import('${f.basis}/vorrat.js');
            const zaehle = (mitHoeren) => {
                const n = {};
                for (let i = 0; i < 300; i++) { const a = v.frageFrei([${einheit}], { hoeren: mitHoeren }); n[a.art] = (n[a.art] ?? 0) + 1; }
                return n;
            };
            const s = document.getElementById('schalter-hoeren');
            return { schalter: !!s, an: s?.checked ?? null, mit: zaehle(true), ohne: zaehle(false) };
        })()`);
        ok('Freies Üben zieht aus allen vier Übungsarten',
           ['mc', 'pick', 'cloze', 'listen'].every((m) => (frei.mit[m] ?? 0) > 30), JSON.stringify(frei.mit));
        ok('Im Kopf steht ein Schalter für Hören, eingeschaltet', frei.schalter && frei.an === true,
           JSON.stringify(frei));
        ok('Abgeschaltet kommt kein Hören mehr', !('listen' in frei.ohne), JSON.stringify(frei.ohne));
        await b.js(`document.getElementById('schalter-hoeren').click()`);
        await schlafe(300);
        await b.hash('/', 300);
        await b.hash(`/unit/${einheit}/frei`, 1000);
        ok('Der Schalter merkt sich, wie er stand',
           (await b.js(`document.getElementById('schalter-hoeren')?.checked`)) === false
           && (await b.js(`!document.getElementById('satzlinie')`)) === true);
        await b.js(`document.getElementById('schalter-hoeren').click()`);   // wieder an, für später

        // ---- Schreiben: derselbe Schalter für den Lückentext.
        await b.hash('/', 300);
        await b.hash(`/unit/${einheit}/frei`, 1000);
        const schreiben = await b.js(`(async () => {
            const v = await import('${f.basis}/vorrat.js');
            const s = document.getElementById('schalter-schreiben');
            let luecke = 0;
            for (let i = 0; i < 300; i++) if (v.frageFrei([${einheit}], { schreiben: false }).art === 'cloze') luecke++;
            return { da: !!s, an: s?.checked ?? null, luecke };
        })()`);
        ok('Daneben ein Schalter für Schreiben, eingeschaltet', schreiben.da && schreiben.an === true,
           JSON.stringify(schreiben));
        ok('Abgeschaltet kommt kein Lückentext mehr', schreiben.luecke === 0, JSON.stringify(schreiben));

        /*
         * Die Tastatur: Folgt auf eine andere Aufgabe ein Lückentext, hält
         * schon beim Antworten ein unsichtbares Feld den Fokus (im Tipp - nur
         * dann öffnet ein Telefon die Tastatur), und erscheint die Lücke,
         * wandert er in ihr Feld. Geantwortet wird irgendwie; es geht nur um
         * den Übergang.
         */
        const antworteIrgendwie = () => b.js(`(() => {
            if (document.getElementById('satzlinie')) {
                // Alle Kärtchen legen: Mit nur einem blieb "Prüfen" zu, und die
                // Schleife hing bei mehreren Wörtern vierzigmal an derselben Aufgabe.
                document.querySelectorAll('#woerter .wort').forEach((w) => w.click());
                document.getElementById('pruefen')?.click();
                return 'hoeren';
            }
            if (document.getElementById('luecke')) { document.querySelector('#woerter .wort')?.click(); return 'einsetzen'; }
            if (document.getElementById('answer')) {
                document.getElementById('answer').value = 'xx';
                document.getElementById('check').click();
                return 'luecke';
            }
            document.querySelector('.option')?.click();
            return 'wahl';
        })()`);
        const fokus = () => b.js(`({ id: document.activeElement?.id ?? '', halter: !!document.getElementById('tastaturhalter'),
            luecke: !!document.getElementById('answer'), weiter: (document.getElementById('weiter')?.offsetParent ?? null) !== null })`);
        let uebergang = null;
        for (let i = 0; i < 40 && uebergang === null; i++) {
            const art = await antworteIrgendwie();
            const sofort = await fokus();
            if (art !== 'luecke' && sofort.id === 'tastaturhalter') {
                await schlafe(2200);
                let danach = await fokus();
                if (danach.weiter) { await b.js(`document.getElementById('weiter').click()`); await schlafe(400); danach = await fokus(); }
                uebergang = { art, sofort, danach };
                break;
            }
            await schlafe(2200);
            if ((await fokus()).weiter) { await b.js(`document.getElementById('weiter').click()`); await schlafe(400); }
            if (art === 'luecke' && (await b.js(`document.getElementById('check')?.textContent`)) === 'Weiter') {
                await b.js(`document.getElementById('check').click()`); await schlafe(400);
            }
        }
        ok('Folgt ein Lückentext, hält schon beim Antworten ein Feld die Tastatur offen',
           uebergang !== null && uebergang.sofort.id === 'tastaturhalter', JSON.stringify(uebergang));
        ok('Und erscheint er, ist sein Feld im Fokus - die Tastatur ist schon da',
           uebergang?.danach.luecke && uebergang.danach.id === 'answer' && !uebergang.danach.halter,
           JSON.stringify(uebergang));

        // Mitten im Lückentext abgeschaltet: gleich die nächste, die keine ist.
        if (await b.js(`!!document.getElementById('answer')`)) {
            await b.js(`document.getElementById('schalter-schreiben').click()`);
            await schlafe(500);
            ok('Abgeschaltet mitten im Lückentext kommt gleich eine andere Aufgabe',
               await b.js(`!document.getElementById('answer')`));
            await b.js(`document.getElementById('schalter-schreiben').click()`);   // wieder an
        }

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

/*
 * Hören ohne Netz: Die Aufnahmen kommen aus dem Speicher des Service
 * Workers.
 *
 * Wie beim Kaltstart (vorrat.mjs) mit eigenem Server, der wirklich
 * abgeschaltet wird - Network.emulateNetworkConditions schaltet den Service
 * Worker nicht mit ab und hätte hier nichts geprüft.
 */
export async function pruefeOhneNetz(f, aus, wurzel) {
    abschnitt('Hören ohne Netz');

    const projekt = resolve(wurzel, '..');
    const port    = 8132;
    const basis   = `http://127.0.0.1:${port}` + new URL(f.basis).pathname.replace(/\/$/, '');

    const einheit = Number(php(wurzel, `require 'lib/db.php'; require_once 'lib/tts.php'; require_once 'lib/courses.php';
        $k = q1('SELECT * FROM courses WHERE id = ?', [${f.kurs}]);
        q("INSERT INTO units (language_id, course_id, title, released_position, position)
           VALUES (?, ?, 'Hören ohne Netz', 2, 96)", [(int) $k['language_id'], (int) $k['id']]);
        $u = (int) db()->lastInsertId();
        foreach ([['the train', 'der Zug', 'train', 'Der Zug ist spät.', 'The {} is late.'],
                  ['the station', 'der Bahnhof', 'station', 'Der Bahnhof ist groß.', 'The {} is big.']] as $i => [$fr, $de, $a, $sn, $sf]) {
            q('INSERT INTO vocab (unit_id, position, term_foreign, term_native) VALUES (?, ?, ?, ?)', [$u, $i, $fr, $de]);
            q('INSERT INTO sentences (vocab_id, native_text, foreign_text, answer) VALUES (?, ?, ?, ?)',
              [(int) db()->lastInsertId(), $sn, $sf, $a]);
        }
        tts_nachtragen($u, course_billing_user((int) $k['id']));
        echo $u;`));

    const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', projekt, resolve(projekt, 'tests', 'router.php')],
                         { cwd: projekt, stdio: 'ignore' });
    let laeuft = true;
    const serverWeg = () => { if (laeuft) { laeuft = false; server.kill(); } };
    await schlafe(900);

    const b = await browser({ port: 9424, breite: 390, hoehe: 844, handy: true, aus });
    try {
        await alsKind(b, basis, f.kind, f.passwort);
        await b.geh(basis + '/', 2200);
        await b.js(`(async () => { const v = await import('${basis}/vorrat.js'); await v.vorratAuffrischen(); })()`);

        // Die Lerneinheit öffnen legt ihre Aufnahmen ab - schon beim ersten
        // Start, bevor der Service Worker die Seite steuert.
        await b.hash(`/unit/${einheit}`, 2500);
        const abgelegt = await b.js(`caches.open('vokabeltrainer-hoeren')
            .then((c) => c.keys()).then((k) => k.map((r) => { const p = new URL(r.url).searchParams;
                return p.get('s') ? 's' + p.get('s') : 'w' + p.get('w'); }))`);
        // Die beiden Sätze fürs Hören - und die beiden Vokabeln fürs Auswählen.
        ok('Beim Öffnen der Lerneinheit liegen ihre Aufnahmen schon im Speicher',
           abgelegt.filter((a) => a.startsWith('s')).length === 2
           && abgelegt.filter((a) => a.startsWith('w')).length === 2, JSON.stringify(abgelegt));

        // Jetzt steuert er sie: einmal neu aufrufen - gewöhnlich, nicht mit neuLaden():
        // Das lädt hart neu, und ein hartes Neuladen geht am Service Worker vorbei.
        await b.geh(basis + '/', 2000);
        await b.js(`new Promise((fertig) => navigator.serviceWorker.controller ? fertig(true)
            : navigator.serviceWorker.addEventListener('controllerchange', () => fertig(true)))`);

        serverWeg();
        await schlafe(1200);
        ok('Der Server ist wirklich aus',
           (await b.js(`fetch('${basis}/api/meta.php?action=version').then(() => 'da').catch(() => 'weg')`)) === 'weg');

        await b.hash(`/hoeren/${einheit}`, 1500);
        const offline = await b.js(`(async () => {
            const src = document.getElementById('abspielen')?.dataset.src ?? '';
            const ganz = await fetch(src);
            const stueck = await fetch(src, { headers: { Range: 'bytes=0-1' } });
            const dauer = await new Promise((fertig) => {
                const t = new Audio(src);
                t.onloadedmetadata = () => fertig(t.duration);
                t.onerror = () => fertig(-1);
            });
            return { ganz: ganz.status, stueck: stueck.status, bereich: stueck.headers.get('content-range'),
                     laenge: (await stueck.arrayBuffer()).byteLength, dauer,
                     knoepfe: document.querySelectorAll('#woerter .wort').length };
        })()`);
        ok('Ohne Server kommt die Übung, mit ihren Wörtern', offline.knoepfe > 0, JSON.stringify(offline));
        ok('Und die Aufnahme aus dem Speicher', offline.ganz === 200 && offline.dauer > 0, JSON.stringify(offline));
        ok('Auch in Stücken, wie Safari sie holt',
           offline.stueck === 206 && offline.laenge === 2 && /^bytes 0-1\/\d+$/.test(offline.bereich ?? ''),
           JSON.stringify(offline));
    } finally {
        b.schliessen();
        serverWeg();
        php(wurzel, `require 'lib/db.php'; q('DELETE FROM units WHERE id = ?', [${einheit}]);`);
    }
}
