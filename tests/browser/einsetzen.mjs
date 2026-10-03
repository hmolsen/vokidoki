/*
 * Einsetzen: das Wort aus drei Knöpfen in die Lücke - antippen oder ziehen.
 *
 * Alles hier gibt es nur im Browser: Die Aufgabe entsteht im Gerät, das
 * Ziehen besteht aus Pointer-Events, und ob ein Wort über der Lücke
 * losgelassen wurde, weiss nur die Seite.
 *
 * Eine eigene Lerneinheit mit drei Verben und drei Substantiven, jedes mit
 * einem Satz. So lässt sich nachzählen, dass die falschen Wörter aus
 * derselben Wortart kommen wie die Lösung.
 */

import { execFileSync } from 'node:child_process';
import { browser, alsKind, ok, abschnitt, schlafe } from './browser.mjs';

const php = (wurzel, code) =>
    execFileSync('php', ['-r', code], { cwd: wurzel, encoding: 'utf8' });

// Die Lösung im Satz ist gebeugt - so kommen die Wörter auch als Knöpfe.
const VERBEN      = ['runs', 'jumps', 'swims'];
const SUBSTANTIVE = ['cat', 'dog', 'bird'];

export async function pruefe(f, aus, wurzel) {
    abschnitt('Einsetzen: Wörter in die Lücke');

    const einheit = Number(php(wurzel, `require 'lib/db.php';
        $k = q1('SELECT * FROM courses WHERE id = ?', [${f.kurs}]);
        q("INSERT INTO units (language_id, course_id, title, released_position, position)
           VALUES (?, ?, 'Einsetzen-Probe', 6, 98)", [(int) $k['language_id'], (int) $k['id']]);
        $u = (int) db()->lastInsertId();
        $woerter = [['to run', 'rennen', 'runs', 'verb', 'Er rennt.', 'He {} fast.'],
                    ['to jump', 'springen', 'jumps', 'verb', 'Sie springt.', 'She {} high.'],
                    ['to swim', 'schwimmen', 'swims', 'verb', 'Er schwimmt.', 'He {} well.'],
                    ['the cat', 'die Katze', 'cat', 'substantiv', 'Die Katze schläft.', 'The {} sleeps.'],
                    ['the dog', 'der Hund', 'dog', 'substantiv', 'Der Hund bellt.', 'The {} barks.'],
                    ['the bird', 'der Vogel', 'bird', 'substantiv', 'Der Vogel singt.', 'The {} sings.']];
        foreach ($woerter as $i => [$fr, $de, $a, $wt, $sn, $sf]) {
            q('INSERT INTO vocab (unit_id, position, term_foreign, term_native, word_type)
               VALUES (?, ?, ?, ?, ?)', [$u, $i, $fr, $de, $wt]);
            q('INSERT INTO sentences (vocab_id, native_text, foreign_text, answer) VALUES (?, ?, ?, ?)',
              [(int) db()->lastInsertId(), $sn, $sf, $a]);
        }
        echo $u;`));

    const kindId = Number(php(wurzel, `require 'lib/db.php';
        echo (int) qv('SELECT id FROM users WHERE username = ?', ['${f.kind}']);`));
    const korrektHeute = () => Number(php(wurzel, `require 'lib/db.php';
        echo (int) qv('SELECT COALESCE(SUM(correct), 0) FROM learn_days WHERE user_id = ? AND day = CURDATE()',
                      [${kindId}]);`));

    const b = await browser({ port: 9420, breite: 390, hoehe: 844, handy: true, aus });
    try {
        await alsKind(b, f.basis, f.kind, f.passwort);
        await b.js(`(async () => { const v = await import('${f.basis}/vorrat.js'); await v.vorratAuffrischen(); })()`);

        // ---- In der Lerneinheit: zwischen Auswählen und Lückentext.
        await b.hash(`/unit/${einheit}`, 1200);
        const ansicht = await b.js(`({
            reihen: [...document.querySelectorAll('#exercises [data-mode-row]')].map((r) => r.dataset.modeRow),
            titel:  document.querySelector('[data-mode-row="pick"] .title')?.textContent,
            kopf:    [...document.querySelectorAll('.vocabkopf .ringzeichen')].map((z) => z.getAttribute('title')),
            ringe:   document.querySelector('.vocabzeile .ringe')?.querySelectorAll('.ring').length,
        })`);
        ok('"Einsetzen" steht zwischen Auswählen und Lückentext',
           JSON.stringify(ansicht.reihen) === JSON.stringify(['mc', 'pick', 'cloze']),
           JSON.stringify(ansicht.reihen));
        ok('Und heisst so', ansicht.titel === 'Einsetzen', ansicht.titel);
        ok('Neben den Vokabeln steht es auch - je Übung ein Ring, die Zeichen oben',
           ansicht.ringe === 3
           && JSON.stringify(ansicht.kopf) === JSON.stringify(['Auswählen', 'Einsetzen', 'Lückentext']),
           `${ansicht.ringe} Ringe, Kopf: ${JSON.stringify(ansicht.kopf)}`);

        // Jedes Zeichen steht genau über seinem Ring - auch zwei Zeilen tiefer.
        const spalten = await b.js(`(() => {
            const mitte = (el) => { const r = el.getBoundingClientRect(); return Math.round(r.left + r.width / 2); };
            const kopf = [...document.querySelectorAll('.vocabkopf .ringzeichen')].map(mitte);
            const zeile = [...document.querySelectorAll('.vocabzeile')].at(-1);
            return { kopf, ringe: [...zeile.querySelectorAll('.ring')].map(mitte) };
        })()`);
        ok('Jedes Zeichen steht über seinem Ring',
           spalten.kopf.length === 3 && spalten.kopf.every((x, i) => Math.abs(x - spalten.ringe[i]) <= 1),
           JSON.stringify(spalten));

        // Ein Druck klappt die Zeile auf und nennt die Übungen beim Namen.
        const detail = await b.js(`(() => {
            const z = document.querySelector('.vocabzeile');
            z.querySelector('summary').click();
            return { offen: z.open, namen: [...z.querySelectorAll('.vocabdetail .mark-name')].map((n) => n.textContent.trim()) };
        })()`);
        ok('Ein Druck auf die Zeile zeigt die Übungen mit Namen',
           detail.offen && detail.namen.length === 3 && detail.namen.some((n) => n.includes('Einsetzen')),
           JSON.stringify(detail));

        // Die Leiste bleibt stehen, wenn die Seite rollt - wie im Lehrkraft-Bereich.
        await b.groesse(390, 480);
        await b.js('window.scrollTo(0, 600)');
        await schlafe(300);
        const leiste = await b.js(`({ oben: Math.round(document.querySelector('.topbar').getBoundingClientRect().top),
                                      gerollt: Math.round(window.scrollY) })`);
        ok('Die Leiste oben bleibt beim Rollen stehen', leiste.gerollt > 100 && leiste.oben === 0,
           JSON.stringify(leiste));
        await b.groesse(390, 844);
        await b.js('window.scrollTo(0, 0)');

        await b.js(`document.querySelector('[data-mode="pick"]').click()`);
        await schlafe(1000);
        ok('Ein Druck öffnet die Übung', (await b.js('location.hash')) === `#/einsetzen/${einheit}`);

        /** Was gerade auf dem Bildschirm steht. */
        const aufgabe = () => b.js(`(async () => {
            const v = await import('${f.basis}/vorrat.js');
            return {
                satz:     document.getElementById('native')?.textContent ?? '',
                woerter:  [...document.querySelectorAll('#woerter .wort')].map((k) => k.textContent.trim()),
                luecke:   document.getElementById('luecke')?.textContent ?? '',
                klasse:   document.getElementById('luecke')?.className ?? '',
                // Sichtbar, nicht nur ohne hidden: .btn setzt display und schlug das
                // Attribut einmal - der Knopf stand von Anfang an da.
                weiter:   (document.getElementById('weiter')?.offsetParent ?? null) !== null,
            };
        })()`);
        const loesungVon = (a) => {
            const alle = [...VERBEN, ...SUBSTANTIVE];
            // Die Lösung ist das Wort, dessen Satz auf Deutsch dasteht.
            const zuSatz = { 'Er rennt.': 'runs', 'Sie springt.': 'jumps', 'Er schwimmt.': 'swims',
                             'Die Katze schläft.': 'cat', 'Der Hund bellt.': 'dog', 'Der Vogel singt.': 'bird' };
            return alle.includes(zuSatz[a.satz]) ? zuSatz[a.satz] : null;
        };

        let a = await aufgabe();
        const loesung = loesungVon(a);
        const gruppe  = VERBEN.includes(loesung) ? VERBEN : SUBSTANTIVE;
        ok('Drei Wörter stehen zur Wahl, alle verschieden',
           a.woerter.length === 3 && new Set(a.woerter).size === 3, JSON.stringify(a.woerter));
        ok('Eines davon ist die Lösung', a.woerter.includes(loesung), `${loesung} in ${a.woerter}`);
        ok('Die anderen sind dieselbe Wortart',
           a.woerter.every((w) => gruppe.includes(w)), JSON.stringify(a.woerter));
        ok('Die Lücke ist leer und verrät nichts', a.luecke === '');
        ok('"Weiter" steht erst nach einer falschen Wahl da', a.weiter === false);
        if (aus) await b.bild('einsetzen');

        // ---- Antippen.
        const vorherKorrekt = korrektHeute();
        await b.js(`[...document.querySelectorAll('#woerter .wort')]
            .find((k) => k.textContent.trim() === ${JSON.stringify(loesung)}).click()`);
        await schlafe(200);
        a = await aufgabe();
        ok('Antippen setzt das Wort in die Lücke - richtig, grün',
           a.luecke === loesung && a.klasse.includes('correct'), JSON.stringify(a));
        await schlafe(1200);
        a = await aufgabe();
        ok('Danach kommt von selbst die nächste Aufgabe', a.luecke === '' && a.woerter.length === 3,
           JSON.stringify(a));

        // ---- Ziehen, mit Finger-Ereignissen wie auf dem Telefon.
        const ziel = loesungVon(a);
        const lage = await b.js(`(() => {
            const k = [...document.querySelectorAll('#woerter .wort')]
                .find((x) => x.textContent.trim() === ${JSON.stringify(ziel)}).getBoundingClientRect();
            const l = document.getElementById('luecke').getBoundingClientRect();
            return { kx: k.left + k.width / 2, ky: k.top + k.height / 2,
                     lx: l.left + l.width / 2, ly: l.top + l.height / 2 };
        })()`);
        const finger = (type, x, y) => b.send('Input.dispatchTouchEvent', {
            type, touchPoints: type === 'touchEnd' ? [] : [{ x, y, id: 1 }],
        });
        await finger('touchStart', lage.kx, lage.ky);
        for (let i = 1; i <= 8; i++) {
            await finger('touchMove', lage.kx + (lage.lx - lage.kx) * i / 8,
                                      lage.ky + (lage.ly - lage.ky) * i / 8);
            await schlafe(25);
        }
        const unterwegs = await b.js(`({
            geist: !!document.querySelector('.wort-geist'),
            ueber: document.getElementById('luecke').classList.contains('ueber'),
        })`);
        ok('Beim Ziehen folgt ein Abbild dem Finger, und die Lücke leuchtet auf',
           unterwegs.geist && unterwegs.ueber, JSON.stringify(unterwegs));
        await finger('touchEnd', lage.lx, lage.ly);
        await schlafe(200);
        a = await aufgabe();
        ok('In die Lücke gezogen zählt es als Wahl',
           a.luecke === ziel && a.klasse.includes('correct'), JSON.stringify(a));
        ok('Und das Abbild ist wieder weg', (await b.js(`!document.querySelector('.wort-geist')`)));
        await schlafe(1200);

        // ---- Daneben losgelassen: nichts passiert.
        a = await aufgabe();
        const lage2 = await b.js(`(() => {
            const k = document.querySelector('#woerter .wort').getBoundingClientRect();
            return { kx: k.left + k.width / 2, ky: k.top + k.height / 2 };
        })()`);
        await finger('touchStart', lage2.kx, lage2.ky);
        await finger('touchMove', lage2.kx + 40, lage2.ky - 60);
        await finger('touchEnd', lage2.kx + 40, lage2.ky - 60);
        await schlafe(200);
        a = await aufgabe();
        ok('Daneben losgelassen bleibt die Lücke leer', a.luecke === '' && !a.klasse.includes('wrong'),
           JSON.stringify(a));

        // ---- Falsch gewählt.
        const falsch = a.woerter.find((w) => w !== loesungVon(a));
        await b.js(`[...document.querySelectorAll('#woerter .wort')]
            .find((k) => k.textContent.trim() === ${JSON.stringify(falsch)}).click()`);
        await schlafe(300);
        a = await aufgabe();
        const richtigGruen = await b.js(`[...document.querySelectorAll('#woerter .wort.good')]
            .map((k) => k.textContent.trim())`);
        ok('Ein falsches Wort steht rot in der Lücke', a.klasse.includes('wrong') && a.luecke === falsch);
        ok('Das richtige leuchtet grün, und es geht erst mit "Weiter" weiter',
           richtigGruen.length === 1 && a.weiter, JSON.stringify({ richtigGruen, a }));
        await schlafe(1200);
        ok('Ohne "Weiter" bleibt die Aufgabe stehen', (await aufgabe()).luecke === falsch);
        await b.js(`document.getElementById('weiter').click()`);
        await schlafe(400);
        ok('"Weiter" bringt die nächste', (await aufgabe()).luecke === '');

        // ---- Zählt für die Serie und landet als eigene Übung auf dem Server.
        await b.js(`(async () => { const v = await import('${f.basis}/vorrat.js'); await v.warteschlangeSenden(); })()`);
        await schlafe(800);
        const stand = JSON.parse(php(wurzel, `require 'lib/db.php';
            echo json_encode(qa("SELECT p.mode, p.correct_count, p.wrong_count FROM progress p
                JOIN vocab v ON v.id = p.vocab_id WHERE v.unit_id = ? AND p.user_id = ?", [${einheit}, ${kindId}]));`));
        ok('Der Server führt den Lernstand als eigene Übung "pick"',
           stand.length > 0 && stand.every((p) => p.mode === 'pick')
           && stand.reduce((s, p) => s + Number(p.correct_count), 0) === 2
           && stand.reduce((s, p) => s + Number(p.wrong_count), 0) === 1,
           JSON.stringify(stand));
        ok('Und die richtigen Antworten zählen für die Serie',
           korrektHeute() === vorherKorrekt + 2, `${vorherKorrekt} -> ${korrektHeute()}`);
    } finally {
        b.schliessen();
        php(wurzel, `require 'lib/db.php'; q('DELETE FROM units WHERE id = ?', [${einheit}]);`);
    }
}
