/*
 * Lückentext ohne Sätze: kein Festsitzen mehr.
 *
 * Ein Kind tippte auf "Lückentext", obwohl es noch keine Sätze gab, und
 * sass vor "Deine Sätze werden vorbereitet ..." - ohne Leiste, ohne Pfeil,
 * und wenn kein Lauf kam, für immer. Jetzt: Ohne Sätze ist die Zeile gar
 * nicht anklickbar; landet man trotzdem dort (ein Link, ein Lesezeichen),
 * führt ein Pfeil zurück, und das Warten hört dann auch auf.
 */

import { execFileSync } from 'node:child_process';

import { browser, alsKind, ok, abschnitt, schlafe } from './browser.mjs';

const php = (wurzel, code) =>
    execFileSync('php', ['-r', code], { cwd: wurzel, encoding: 'utf8' });

export async function pruefe(f, aus, wurzel) {
    abschnitt('Lückentext ohne Sätze: kein Festsitzen');

    const einheit = Number(php(wurzel, `require 'lib/db.php';
        $k = q1('SELECT * FROM courses WHERE id = ?', [${f.kurs}]);
        q("INSERT INTO units (language_id, course_id, title, released_position, position, sentences_status)
           VALUES (?, ?, 'Warte-Probe', 2, 96, 'pending')", [(int) $k['language_id'], (int) $k['id']]);
        $u = (int) db()->lastInsertId();
        foreach (['the lamp', 'the chair'] as $i => $w) {
            q('INSERT INTO vocab (unit_id, position, term_foreign, term_native) VALUES (?, ?, ?, ?)', [$u, $i, $w, 'de-' . $i]);
        }
        echo $u;`));

    const b = await browser({ port: 9483, breite: 390, hoehe: 844, handy: true, aus });
    try {
        await alsKind(b, f.basis, f.kind, f.passwort);
        await b.js(`(async () => { const v = await import('${f.basis}/vorrat.js'); await v.vorratAuffrischen(); })()`);
        await b.hash(`/unit/${einheit}`, 1200);

        const zeile = await b.js(`(() => {
            const r = document.querySelector('[data-mode-row="cloze"]');
            return r ? { zu: r.disabled, text: r.textContent.replace(/\\s+/g, ' ').trim() } : null;
        })()`);
        ok('Ohne Sätze ist der Lückentext nicht anklickbar', zeile?.zu === true, JSON.stringify(zeile));
        ok('Und sagt, warum', zeile?.text.includes('deine Lehrkraft kümmert sich darum'), zeile?.text);

        // Trotzdem dort gelandet - während ein Lauf läuft, der nicht fertig wird.
        php(wurzel, `require 'lib/db.php';
            q("UPDATE units SET sentences_status = 'running', sentences_started_at = NOW() WHERE id = ?", [${einheit}]);`);
        await b.js(`(async () => { const v = await import('${f.basis}/vorrat.js'); await v.vorratAuffrischen(); })()`);
        await b.hash(`/cloze/${einheit}`, 1200);
        const warte = await b.js(`({ wartet: !!document.getElementById('preparing'),
            pfeil: !!document.querySelector('.topbar [data-back]') })`);
        ok('Das Wartebild hat eine Leiste mit Pfeil zurück', warte.wartet && warte.pfeil, JSON.stringify(warte));

        await b.js(`document.querySelector('.topbar [data-back]').click()`);
        await schlafe(3500);   // länger als ein Takt des Wartens (2,5 s)
        const danach = await b.js(`({ adresse: location.hash, wartet: !!document.getElementById('preparing'),
            einheit: !!document.querySelector('[data-mode-row="cloze"]') })`);
        ok('Der Pfeil führt zurück - und das Warten zeichnet nicht darüber',
           danach.adresse === `#/unit/${einheit}` && !danach.wartet && danach.einheit, JSON.stringify(danach));
    } finally {
        b.schliessen();
        php(wurzel, `require 'lib/db.php'; q('DELETE FROM units WHERE id = ?', [${einheit}]);`);
    }
}
