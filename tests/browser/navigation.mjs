/*
 * Der Weg zur Arbeit.
 *
 * Vorher: Anmelden führte auf die Klassenliste, und bis zur Freigabe waren
 * es drei Klicks und vier Seiten. Eine Klasse legt man einmal im Schuljahr
 * an, eine Lerneinheit jede Woche - die Reihenfolge stimmte nicht.
 *
 * Und wer zwei Kurse hat, musste zum Wechseln hoch zur Schule und durch
 * eine andere Klasse wieder hinunter.
 */

import { browser, alsLehrkraft, ok, abschnitt, schlafe } from './browser.mjs';

export async function pruefe(f, aus) {
    abschnitt('Navigation');

    const b = await browser({ port: 9405, breite: 1200, hoehe: 1000, aus });
    try {
        // ---- Die Anmeldung führt auf die eigenen Kurse.

        await alsLehrkraft(b, f.basis, f.lehrer, f.passwort);

        const start = await b.js(`({
            ort:    location.pathname,
            titel:  document.querySelector('h1')?.textContent ?? '',
            karten: document.querySelectorAll('.kurskarte').length,
            namen:  [...document.querySelectorAll('.kurskopf strong')].map((e) => e.textContent),
        })`);

        ok('Die Anmeldung führt auf die Startseite', start.ort.endsWith('/teacher/index.php')
           || start.ort.endsWith('/teacher/'), start.ort);
        ok('Und die heisst "Meine Kurse"', start.titel === 'Meine Kurse', start.titel);
        ok('Sie zeigt die eigenen Kurse als Karten', start.karten === 2,
           start.karten + ' Karten: ' + start.namen.join(', '));

        // ---- Von dort ist die Freigabe EIN Klick.

        const wege = await b.js(`(() => {
            const karte = [...document.querySelectorAll('.kurskarte')]
                .find((k) => k.textContent.includes('Englisch'));
            const knoepfe = [...karte.querySelectorAll('.buttonrow > *')];
            return {
                beschriftung: knoepfe.map((k) => k.textContent.trim()),
                freigeben: knoepfe.find((k) => k.textContent.includes('Freigeben'))?.getAttribute('href') ?? '',
                einlesen:  knoepfe.find((k) => k.textContent.includes('Lerneinheit'))?.getAttribute('href') ?? '',
                kopf:      karte.querySelector('.kurskopf')?.getAttribute('href') ?? '',
            };
        })()`);

        ok('Auf der Karte stehen Einlesen und Freigeben',
           wege.beschriftung.join(' | ').includes('Lerneinheit')
           && wege.beschriftung.join(' | ').includes('Freigeben'),
           wege.beschriftung.join(' | '));
        ok('Freigeben führt direkt in eine Lerneinheit',
           wege.freigeben.includes('unit.php?id=' + f.unit), wege.freigeben);
        ok('Einlesen führt in die Einleseansicht dieses Kurses',
           wege.einlesen.includes('/lang/' + f.sprache + '/import'), wege.einlesen);
        ok('Der Kartenkopf führt in den Kurs',
           wege.kopf.includes('course.php?id=' + f.kurs), wege.kopf);

        // Und der Klick tut es auch wirklich.
        await b.js(`[...document.querySelectorAll('.kurskarte .buttonrow a')]
                      .find((a) => a.textContent.includes('Freigeben')).click()`);
        await schlafe(1400);
        const drin = await b.js(`({
            ort: location.search,
            h1:  document.querySelector('h1')?.textContent ?? '',
            balken: !!document.querySelector('.releasebar'),
        })`);
        ok('Ein Klick, und die Freigabe steht da',
           drin.ort.includes('id=' + f.unit) && drin.balken, drin.ort + ' / ' + drin.h1);

        /*
         * Und einer wieder zurueck.
         *
         * Hierher kommt man mit einem Klick von der Startseite. Zurueck fuehrte
         * nur der Name der Schule im Pfad, und der liest sich nicht wie
         * "zurueck" - er liest sich wie der Name der Schule.
         */
        const raus = await b.js(`[...document.querySelectorAll('.titelzeile a.btn')]
            .find((a) => a.textContent.includes('Meine Kurse'))?.getAttribute('href') ?? ''`);
        ok('Neben der Überschrift steht der Weg zurück', raus !== '',
           'sonst führt aus der Freigabe nur der Schulname heraus');

        await b.js(`[...document.querySelectorAll('.titelzeile a.btn')]
                      .find((a) => a.textContent.includes('Meine Kurse')).click()`);
        await schlafe(1400);
        ok('Und ein Druck darauf führt auf die Startseite',
           (await b.js(`document.querySelector('h1')?.textContent ?? ''`)) === 'Meine Kurse');

        // ---- Der Kurswechsler.

        for (const [name, pfad] of [['Kurs', '/teacher/course.php?id=' + f.kurs],
                                    ['Lerneinheit', '/teacher/unit.php?id=' + f.unit]]) {
            await b.geh(f.basis + pfad, 1300);

            const menue = await b.js(`(() => {
                const d = document.querySelector('details.crumbmenu');
                if (!d) return null;
                return {
                    offen: d.open,
                    ziele: [...d.querySelectorAll('a')].map((a) => a.textContent.trim()),
                    hier:  d.querySelector('a.on')?.textContent.trim() ?? '',
                };
            })()`);

            ok(`${name}: der Kurskrumen klappt auf`, menue !== null,
               'ohne ihn führt der Wechsel wieder über die Schule');
            ok(`${name}: zugeklappt, bis jemand darauf drückt`, menue?.offen === false);
            ok(`${name}: beide eigenen Kurse stehen darin`,
               (menue?.ziele ?? []).some((z) => z.includes('Englisch'))
               && (menue?.ziele ?? []).some((z) => z.includes('Französisch')),
               (menue?.ziele ?? []).join(' | '));
            ok(`${name}: und der Weg zu allen Kursen der Schule`,
               (menue?.ziele ?? []).some((z) => z.includes('Alle Kurse')));
        }

        // Aufklappen und wechseln - ohne Skript, nur <details>.
        await b.geh(f.basis + '/teacher/course.php?id=' + f.kurs, 1300);
        await b.js(`document.querySelector('details.crumbmenu > summary').click()`);
        await schlafe(400);
        ok('Ein Druck klappt ihn auf',
           await b.js(`document.querySelector('details.crumbmenu').open`));
        await b.bild('kurswechsler');

        await b.js(`[...document.querySelectorAll('details.crumbmenu a')]
                      .find((a) => a.textContent.includes('Französisch')).click()`);
        await schlafe(1400);
        const gewechselt = await b.js(`({
            ort: location.search,
            h1:  document.querySelector('h1')?.textContent ?? '',
        })`);
        ok('Und ein Klick wechselt den Kurs',
           gewechselt.ort.includes('id=' + f.kurs2), gewechselt.ort + ' / ' + gewechselt.h1);

        // ---- Die Wurzel ist die Startseite, nicht mehr die Klassenliste.

        const wurzel = await b.js(
            `document.querySelector('.adminbar .crumb')?.getAttribute('href') ?? ''`);
        ok('Der Name der Schule führt auf die eigenen Kurse',
           wurzel.endsWith('/teacher/index.php'), wurzel);
    } finally {
        b.schliessen();
    }
}
