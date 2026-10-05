/*
 * Eine Vokabel von Hand dazu - und dann freigeben.
 *
 * Gemeldet: War schon alles freigegeben und kam von Hand eine Vokabel
 * dazu, liess sich der Freigabebalken nicht bis zu ihr ziehen, und "Alles
 * freigeben" blieb grau. Der Balken kannte nur die Zeilen vom Laden der
 * Seite, und der Knopf hatte die alte Zahl. Dasselbe in einer leeren
 * Lerneinheit: Dort gab es weder Balken noch Knopf.
 */

import { execFileSync } from 'node:child_process';

import { browser, alsLehrkraft, ok, abschnitt, schlafe } from './browser.mjs';

const php = (wurzel, code) =>
    execFileSync('php', ['-r', code], { cwd: wurzel, encoding: 'utf8' });

/** Eine eigene Lerneinheit im Kurs der Prüfung, mit diesen Wörtern, alle frei. */
const einheit = (wurzel, f, titel, woerter) => Number(php(wurzel, `require 'lib/db.php';
    $k = q1('SELECT * FROM courses WHERE id = ?', [${f.kurs}]);
    q("INSERT INTO units (language_id, course_id, title, released_position, position) VALUES (?, ?, ?, ?, 97)",
      [(int) $k['language_id'], (int) $k['id'], ${JSON.stringify(titel)}, ${woerter.length}]);
    $u = (int) db()->lastInsertId();
    foreach (${JSON.stringify(woerter)} as $i => $w) {
        q('INSERT INTO vocab (unit_id, position, term_foreign, term_native) VALUES (?, ?, ?, ?)', [$u, $i, $w, 'de-' . $w]);
    }
    echo $u;`));

const frei = (wurzel, unit) => Number(php(wurzel,
    `require 'lib/db.php'; echo (int) qv('SELECT released_position FROM units WHERE id = ?', [${unit}]);`));

/** Von Hand anlegen, über die Zeile unter der Tabelle - wie ein Mensch. */
async function vonHand(b, fremd, deutsch) {
    await b.js(`(() => {
        document.getElementById('handzeile').hidden = false;
        document.querySelector('#handzeile input[name="new_f"]').value = ${JSON.stringify(fremd)};
        document.querySelector('#handzeile input[name="new_n"]').value = ${JSON.stringify(deutsch)};
        document.querySelector('#handzeile [name="add_vocab"]').click();
    })()`);
    await schlafe(1300);
}

const stand = (b) => b.js(`(() => {
    const k = document.querySelector('.mengenknopf.auf');
    const g = document.querySelector('.releasebar .grip');
    const bar = document.querySelector('.releasebar');
    return { knopfDa: !!k && !k.closest('tr').hidden, knopfAn: !!k && !k.disabled, knopfWert: k?.value,
             max: g?.getAttribute('aria-valuemax'), balken: !!bar && !bar.hidden };
})()`);

export async function pruefe(f, aus, wurzel) {
    abschnitt('Von Hand dazu, dann freigeben');

    const voll = einheit(wurzel, f, 'Alles-frei-Probe', ['owl', 'fox']);
    const leer = einheit(wurzel, f, 'Leer-Probe', []);

    const b = await browser({ port: 9489, breite: 1000, hoehe: 1000, aus });
    try {
        await alsLehrkraft(b, f.basis, f.lehrer, f.passwort, f.kuerzel);

        // ---- Alles war frei, eine kommt dazu.
        await b.geh(f.basis + '/teacher/unit.php?id=' + voll, 1500);
        const vorher = await stand(b);
        ok('Vorher ist "Alles freigeben" grau - es ist schon alles frei', vorher.knopfDa && !vorher.knopfAn);

        await vonHand(b, 'bee', 'Biene');
        const nachher = await stand(b);
        ok('Nach der neuen Vokabel ist "Alles freigeben" wieder drückbar', nachher.knopfAn, JSON.stringify(nachher));
        ok('Und zählt sie mit', nachher.knopfWert === '3', JSON.stringify(nachher));
        ok('Der Balken reicht bis zur neuen Zeile', nachher.max === '3', JSON.stringify(nachher));

        // Den Balken ans Ende ziehen - mit der Tastatur: Ende, Enter.
        await b.js(`(() => { const g = document.querySelector('.releasebar .grip');
            g.focus();
            g.dispatchEvent(new KeyboardEvent('keydown', { key: 'End', bubbles: true }));
            g.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true })); })()`);
        await schlafe(1500);
        ok('Und lässt sich bis dorthin ziehen und speichern', frei(wurzel, voll) === 3, String(frei(wurzel, voll)));

        // ---- Eine leere Lerneinheit, die erste Vokabel von Hand.
        await b.geh(f.basis + '/teacher/unit.php?id=' + leer + '&vonhand=1', 1500);
        const leerVorher = await stand(b);
        ok('In der leeren Lerneinheit gibt es noch keinen Balken', !leerVorher.balken && !leerVorher.knopfDa,
           JSON.stringify(leerVorher));
        await vonHand(b, 'ant', 'Ameise');
        const leerNachher = await stand(b);
        ok('Nach der ersten Vokabel stehen Balken und "Alles freigeben" da',
           leerNachher.balken && leerNachher.knopfDa && leerNachher.knopfAn && leerNachher.knopfWert === '1',
           JSON.stringify(leerNachher));
        await b.js(`(() => { window.confirm = () => true;
            document.querySelector('.mengenknopf.auf').click(); })()`);
        await schlafe(1500);
        ok('"Alles freigeben" gibt sie frei', frei(wurzel, leer) === 1, String(frei(wurzel, leer)));
    } finally {
        b.schliessen();
        php(wurzel, `require 'lib/db.php'; q('DELETE FROM units WHERE id IN (?, ?)', [${voll}, ${leer}]);`);
    }
}
