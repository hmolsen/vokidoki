/*
 * Lerneinheiten ziehen - und die Sofortsuche im Admin.
 *
 * Beides gibt es nur im Browser: Ein Zug mit der Maus besteht aus einem
 * halben Dutzend Ereignissen, die keine PHP-Suite auslösen kann, und ein
 * Filter, der beim Tippen Zeilen versteckt, hinterlässt auf dem Server
 * überhaupt keine Spur.
 */

import { execFileSync } from 'node:child_process';
import { browser, alsLehrkraft, ok, abschnitt, schlafe } from './browser.mjs';

const php = (wurzel, code) =>
    execFileSync('php', ['-r', code], { cwd: wurzel, encoding: 'utf8' });

export async function pruefe(f, aus, wurzel) {
    abschnitt('Lerneinheiten sortieren');

    const b = await browser({ port: 9416, breite: 1100, hoehe: 900, aus });
    try {
        await alsLehrkraft(b, f.basis, f.lehrer, f.passwort);

        /*
         * Zwei weitere Lerneinheiten anlegen - mit einer einzigen lässt
         * sich nichts umsortieren, und der Abschnitt prüfte dann nichts.
         * Über den Knopf der Seite, damit auch gleich feststeht, wo eine
         * neue landet.
         */
        for (let i = 0; i < 2; i++) {
            await b.geh(f.basis + '/teacher/course.php?id=' + f.kurs, 1400);
            // Seit die Anlegezeile nach dem Namen fragt, ist das Feld Pflicht.
            await b.js(`document.getElementById('neueEinheitTitel').value = 'Sortierprobe ${i + 2}'`);
            await b.js(`document.querySelector('[name="add_unit"]').click()`);
            await schlafe(1600);
        }

        await b.geh(f.basis + '/teacher/course.php?id=' + f.kurs, 1600);

        const vorher = await b.js(`({
            titel:   [...document.querySelectorAll('#einheiten tr[data-unit] .rowmain')]
                       .map((a) => a.textContent.trim()),
            /* Die Kennungen, nicht die Titel: Zwei Lerneinheiten koennen
               gleich heissen, und ein Vergleich darueber
               saehe eine Vertauschung gar nicht. */
            ids:     [...document.querySelectorAll('#einheiten tr[data-unit]')]
                       .map((tr) => tr.dataset.unit),
            griffe:  document.querySelectorAll('#einheiten .anfasser').length,
            pfeile:  document.querySelectorAll('#einheiten [form="sortierform"]').length,
            ziehbar: document.getElementById('einheiten').classList.contains('sortierbar'),
        })`);

        ok('Drei Lerneinheiten stehen da', vorher.titel.length === 3,
           vorher.titel.join(', '));
        /*
         * Eine neu angelegte kommt ans Ende. Vorher sortierten sie sich
         * nach dem Anlegedatum, neueste zuerst - man legte eine an und
         * suchte sie dann oben, wo man sie nicht vermutet hätte.
         */
        ok('Die zuletzt angelegte steht unten',
           vorher.titel[2] === 'Sortierprobe 3', vorher.titel.join(', '));
        ok('Jede Zeile hat einen Griff', vorher.griffe === 3, String(vorher.griffe));
        ok('Am Rechner verschwinden die Pfeile', vorher.pfeile === 0,
           vorher.pfeile + ' - sie sind der Weg ohne Skript');
        ok('Und die Tabelle ist ziehbar', vorher.ziehbar);

        /*
         * Alle Zeilen gleich hoch.
         *
         * Die Blase "noch keine Vokabeln" hiess einmal .pill.leer - und
         * .leer gehoert schon dem gestrichelten Kasten fuer Leerzustaende,
         * mit 18 px Polster und einem Rahmen. Sie erbte das und war doppelt
         * so hoch wie ihre Nachbarn. Im Quelltext sah man es nicht, in der
         * Tabelle sofort.
         */
        const hoehen = await b.js(`[...document.querySelectorAll('#einheiten tr[data-unit]')]
            .map((tr) => Math.round(tr.getBoundingClientRect().height))`);
        ok('Alle Zeilen sind gleich hoch', new Set(hoehen).size === 1,
           hoehen.join(', ') + ' - zwei Bedeutungen fuer einen Klassennamen');

        // ---- Der Zug selbst.

        /*
         * Ein Zug ist kein Klick: dragstart, dragover, dragend. Chrome
         * löst das über die Maus nicht aus, wenn man sie nur bewegt -
         * dafür bräuchte es Input.dispatchDragEvent mit echten Daten.
         * Hier werden die Ereignisse deshalb selbst ausgelöst, samt
         * dataTransfer; geprüft wird, was die Seite daraus macht.
         */
        await b.js(`(() => {
            const zeilen = [...document.querySelectorAll('#einheiten tr[data-unit]')];
            const dt = new DataTransfer();
            const letzte = zeilen[2];
            const erste  = zeilen[0];

            letzte.dispatchEvent(new DragEvent('dragstart',
                { bubbles: true, dataTransfer: dt }));

            const kasten = erste.getBoundingClientRect();
            erste.dispatchEvent(new DragEvent('dragover', {
                bubbles: true, dataTransfer: dt,
                clientY: kasten.top + 2,
            }));

            letzte.dispatchEvent(new DragEvent('dragend',
                { bubbles: true, dataTransfer: dt }));
        })()`);
        await schlafe(400);

        const gezogen = await b.js(`[...document.querySelectorAll('#einheiten tr[data-unit]')]
            .map((tr) => tr.dataset.unit)`);
        ok('Der Zug stellt die Zeile nach oben',
           gezogen[0] === vorher.ids[2] && gezogen.length === 3,
           gezogen.join(', ') + ' - vorher ' + vorher.ids.join(', '));

        // ---- Und es hält auch nach dem Neuladen.

        await schlafe(1200);   // der Sofortspeicher wartet eine halbe Sekunde
        await b.neuLaden(1800);

        const nachher = await b.js(`[...document.querySelectorAll('#einheiten tr[data-unit]')]
            .map((tr) => tr.dataset.unit)`);
        ok('Und die Reihenfolge ist gespeichert',
           nachher.join('|') === gezogen.join('|'),
           nachher.join(', ') + ' gegen ' + gezogen.join(', '));

        if (aus) await b.bild('sortieren');

        // ---- Dieselbe Reihenfolge sieht die Klasse.

        /*
         * Die beiden neuen sind leer, und leere Lerneinheiten zeigt die App
         * nicht (unit_visible_sql()). Erst so, dann mit je einer
         * freigegebenen Vokabel - sonst prüfte der Vergleich eine
         * Reihenfolge aus einer einzigen Zeile.
         */
        await b.geh(f.basis + '/#/lang/' + f.sprache, 2200);
        const ohneLeere = await b.js(`[...document.querySelectorAll('.row[data-unit]')]
            .map((el) => el.dataset.unit)`);
        ok('Leere Lerneinheiten sieht die Klasse nicht',
           ohneLeere.length === 1 && ohneLeere[0] === String(f.unit),
           ohneLeere.join(', '));

        const neue = nachher.filter((id) => id !== String(f.unit)).map(Number);
        php(wurzel, `require 'lib/db.php';
            foreach ([${neue.join(',')}] as $u) {
                q("INSERT INTO vocab (unit_id, term_foreign, term_native, position)
                   VALUES (?, 'probe', 'Probe', 0)", [$u]);
                q('UPDATE units SET released_position = 1 WHERE id = ?', [$u]);
            }`);

        await b.geh(f.basis + '/#/lang/' + f.sprache, 2200);
        await b.neuLaden(2200);
        const inDerApp = await b.js(`[...document.querySelectorAll('.row[data-unit]')]
            .map((el) => el.dataset.unit)`);
        ok('Die Klasse sieht sie in derselben Reihenfolge',
           inDerApp.join('|') === nachher.join('|'),
           inDerApp.join(', ') + ' gegen ' + nachher.join(', '));

        // Die Probevokabeln wieder weg - die Abschnitte danach üben mit
        // genau den Wörtern der Vorlage und zählen mit ihnen.
        php(wurzel, `require 'lib/db.php';
            foreach ([${neue.join(',')}] as $u) {
                q('DELETE FROM vocab WHERE unit_id = ?', [$u]);
                q('UPDATE units SET released_position = 0 WHERE id = ?', [$u]);
            }`);
    } finally {
        b.schliessen();
    }

    // ---- Die Sofortsuche im Admin.

    abschnitt('Sofort filtern im Admin');

    const a = await browser({ port: 9417, breite: 1300, hoehe: 900, aus });
    try {
        await a.geh(f.basis + '/admin/', 1500);
        await a.js(`(() => {
            const feld = document.querySelector('input[type="password"]');
            if (feld) {
                feld.value = ${JSON.stringify(f.adminPasswort ?? 'test-admin')};
                feld.form.submit();
            }
        })()`);
        await schlafe(1600);

        await a.geh(f.basis + '/admin/sentences.php', 1800);

        const start = await a.js(`(() => {
            const feld = document.querySelector('[data-filter-ziel="satzliste"]');
            const zeilen = [...document.querySelectorAll('#satzliste tr[data-suchtext]')];
            return {
                feld:   !!feld,
                zeilen: zeilen.length,
                erstes: zeilen[0]?.dataset.suchtext.split(' ')[0] ?? '',
            };
        })()`);

        ok('Die Satzliste hat ein Suchfeld', start.feld);
        if (start.zeilen === 0) {
            ok('Es gibt Saetze zum Filtern', false, 'keine Zeilen - Abschnitt uebersprungen');
        } else {
            ok('Und Zeilen darin', start.zeilen > 0, String(start.zeilen));

            /*
             * Tippen filtert - ohne die Seite neu zu laden. Die Marke
             * beweist genau das: Sie überlebt kein Neuladen.
             */
            await a.js(`window.__steht = 'ja'`);
            await a.js(`(() => {
                const feld = document.querySelector('[data-filter-ziel="satzliste"]');
                feld.value = 'zzz-gibt-es-nicht';
                feld.dispatchEvent(new Event('input', { bubbles: true }));
            })()`);
            await schlafe(300);

            const leer = await a.js(`({
                sichtbar: [...document.querySelectorAll('#satzliste tr[data-suchtext]')]
                            .filter((tr) => !tr.hidden).length,
                geladen:  window.__steht !== 'ja',
                zaehler:  document.getElementById('satzzaehler')?.textContent ?? '',
            })`);
            ok('Ein Wort ohne Treffer leert die Liste', leer.sichtbar === 0,
               String(leer.sichtbar));
            ok('Ohne dass die Seite neu geladen hat', !leer.geladen,
               'das war der ganze Punkt - vorher war der Bildschirm bei jedem Buchstaben weg');
            ok('Der Zaehler sagt, wie viele von wie vielen',
               leer.zaehler.includes('von ' + start.zeilen),
               leer.zaehler + ' - sonst haelt man eine gefilterte Liste fuer die ganze');

            await a.js(`(() => {
                const feld = document.querySelector('[data-filter-ziel="satzliste"]');
                feld.value = '';
                feld.dispatchEvent(new Event('input', { bubbles: true }));
            })()`);
            await schlafe(300);
            ok('Und ein leeres Feld bringt alles zurueck',
               (await a.js(`[...document.querySelectorAll('#satzliste tr[data-suchtext]')]
                   .filter((tr) => !tr.hidden).length`)) === start.zeilen,
               'versteckt, nicht geloescht');

            if (aus) await a.bild('admin-sofortfilter');
        }
    } finally {
        a.schliessen();
    }
}
