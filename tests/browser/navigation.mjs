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
                einlesen:  knoepfe.find((k) => k.textContent.includes('Lerneinheit'))?.tagName ?? '',
                einleseZiel: knoepfe.find((k) => k.textContent.includes('Lerneinheit'))
                                ?.getAttribute('action') ?? '',
                neuerTab:  !!karte.querySelector('[target="_blank"]'),
                kopf:      karte.querySelector('.kurskopf')?.getAttribute('href') ?? '',
            };
        })()`);

        ok('Auf der Karte stehen Einlesen und Freigeben',
           wege.beschriftung.join(' | ').includes('Lerneinheit')
           && wege.beschriftung.join(' | ').includes('Freigeben'),
           wege.beschriftung.join(' | '));
        ok('Freigeben führt direkt in eine Lerneinheit',
           wege.freigeben.includes('unit.php?id=' + f.unit), wege.freigeben);
        /*
         * "+ Lerneinheit" ist kein Link mehr, sondern ein Formular: Es legt
         * eine leere Lerneinheit an und führt auf ihre Seite. Dort stehen
         * alle drei Wege, sie zu füllen - vorher führte der Knopf in die
         * Einleseansicht der App, und zwar in einem neuen Tab, weil man von
         * dort nicht zurückfand.
         */
        ok('"+ Lerneinheit" legt eine an, statt in die App zu führen',
           wege.einlesen === 'FORM' && wege.einleseZiel.includes('course.php'),
           wege.einlesen + ' → ' + wege.einleseZiel);
        ok('Und öffnet dafür keinen zweiten Tab', wege.neuerTab === false);
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
         * "So sieht es die Klasse" bleibt im selben Fenster.
         *
         * Der Knopf stand auf target="_blank" - damals war das der einzige
         * Weg zurueck: Tab zu. Seit die Ansicht selbst einen Knopf zurueck
         * traegt, ist der zweite Tab keine Hilfe mehr. Ob er einen oeffnet,
         * sieht man hier daran, dass DIESES Fenster stehenbliebe.
         */
        await b.js(`[...document.querySelectorAll('.titelzeile a.btn')]
                      .find((a) => a.textContent.includes('So sieht es die Klasse')).click()`);
        await schlafe(2200);
        const probe = await b.js(`({
            ort:    location.pathname + location.hash,
            banner: !!document.querySelector('.notice.pupilview'),
        })`);
        ok('Die Schüleransicht öffnet im selben Fenster',
           probe.ort.includes('/unit/' + f.unit) && !probe.ort.includes('unit.php'),
           probe.ort + ' - mit target=_blank stünde hier noch die Freigabe');
        ok('Und sie ist die Schüleransicht', probe.banner);

        await b.js(`document.querySelector('.notice.pupilview a.btn').click()`);
        await schlafe(1800);
        ok('Und der Knopf darin führt in dieselbe Lerneinheit zurück',
           (await b.js(`location.pathname + location.search`))
               .includes('unit.php?id=' + f.unit));

        /*
         * Und einer wieder zurueck - in den Kurs.
         *
         * Die Ueberschrift lautet "Englisch - 8c > Unit 1", und der Kurs
         * davor ist ein Knopf. Vorher stand hier "Meine Kurse"; eine Ebene
         * hoeher will man von hier aus oefter als ganz nach oben, und ganz
         * nach oben fuehrt das Haeuschen im Pfad.
         */
        const kopf = await b.js(`(() => {
            const a = document.querySelector('h1 a.kursknopf');
            return {
                ziel: a?.getAttribute('href') ?? '',
                text: a?.textContent?.trim() ?? '',
                name: document.querySelector('h1 [data-titel]')?.textContent?.trim() ?? '',
                stift: !!document.querySelector('h1 [data-rename]'),
                meineKurse: (document.querySelector('.titelzeile')?.textContent ?? '')
                    .includes('Meine Kurse'),
            };
        })()`);
        ok('Die Überschrift trägt den Kurs als Knopf zurück',
           kopf.ziel.includes('course.php?id=' + f.kurs), kopf.ziel);
        ok('Und danach den Namen der Lerneinheit',
           kopf.name === 'Unit 1 - Browsertest', kopf.name);
        ok('Mit einem Stift zum Umbenennen daneben', kopf.stift);
        ok('„Meine Kurse" steht nicht mehr daneben', !kopf.meineKurse,
           'dafür gibt es das Häuschen im Pfad');

        await b.js(`document.querySelector('h1 a.kursknopf').click()`);
        await schlafe(1400);
        ok('Und ein Druck darauf führt in den Kurs',
           (await b.js(`location.search`)).includes('id=' + f.kurs));
        await b.geh(f.basis + '/teacher/unit.php?id=' + f.unit, 1400);

        // ---- Das Menue links: Haus, die eigenen Kurse, alles andere.

        /*
         * Hier stand ein Pfad aus Knoepfen - Haus > Kurs > Lerneinheit -,
         * und am Rechner war das richtig. Auf einem Telefon nicht: Drei
         * Knoepfe mit Kursnamen darin brauchen zwei Zeilen, und die Leiste
         * war damit so hoch wie der halbe Bildschirm.
         */
        for (const [name, pfad] of [['Kurs', '/teacher/course.php?id=' + f.kurs],
                                    ['Lerneinheit', '/teacher/unit.php?id=' + f.unit]]) {
            await b.geh(f.basis + pfad, 1300);

            const menue = await b.js(`(() => {
                const d = document.getElementById('menuLinks');
                if (!d) return null;
                return {
                    offen:  d.open,
                    pfad:   !!document.querySelector('.crumbs'),
                    ziele:  [...d.querySelectorAll('.mitem')].map((a) => a.textContent.trim()),
                    hier:   d.querySelector('.mitem.on')?.textContent.trim() ?? '',
                    breite: Math.round(
                        document.querySelector('.adminbar').getBoundingClientRect().height),
                };
            })()`);

            ok(`${name}: oben links steht ein Menü`, menue !== null);
            ok(`${name}: und kein Pfad mehr`, menue?.pfad === false,
               'drei Knöpfe mit Kursnamen darin brauchen am Telefon zwei Zeilen');
            ok(`${name}: zugeklappt, bis jemand darauf drückt`, menue?.offen === false);
            ok(`${name}: beide eigenen Kurse stehen darin`,
               (menue?.ziele ?? []).some((z) => z.includes('Englisch'))
               && (menue?.ziele ?? []).some((z) => z.includes('Französisch')),
               (menue?.ziele ?? []).join(' | '));
            ok(`${name}: und der Weg zu allen Kursen der Schule`,
               (menue?.ziele ?? []).some((z) => z.includes('Alle Kurse')));
            ok(`${name}: sowie zur Klassenverwaltung`,
               (menue?.ziele ?? []).some((z) => z.includes('Klassen und Kinder')));
            ok(`${name}: die Leiste bleibt eine Zeile`, (menue?.breite ?? 999) < 70,
               menue?.breite + ' px hoch');
        }

        // Aufklappen und wechseln - ohne Skript, nur <details>.
        await b.geh(f.basis + '/teacher/course.php?id=' + f.kurs, 1300);
        /*
         * Aufklappen UND messen im selben Augenblick.
         *
         * Die Animation dauert gut eine Viertelsekunde; wer danach
         * nachsieht, findet nichts mehr und haelt das fuer "keine
         * Animation". Genau das ist mir passiert.
         */
        const fliegt = await b.js(`(() => {
            const d = document.getElementById('menuLinks');
            d.querySelector('summary').click();
            const el = d.querySelector('.schublade');
            const an = el.getAnimations();
            return {
                name:   an[0]?.animationName ?? '',
                laeuft: an[0]?.playState ?? '',
                weg:    getComputedStyle(el).transform,
            };
        })()`);
        ok('Die Leiste fliegt herein, statt dazustehen',
           fliegt.name === 'schubladeLinks', fliegt.name || '(keine Animation)');
        ok('Und die Animation läuft auch wirklich', fliegt.laeuft === 'running',
           fliegt.laeuft);
        ok('Im ersten Augenblick steht sie noch draussen',
           fliegt.weg !== 'none' && fliegt.weg.includes('-'), fliegt.weg);

        await schlafe(400);

        const auf = await b.js(`(() => {
            const d = document.getElementById('menuLinks');
            const el = d.querySelector('.schublade');
            const s = el.getBoundingClientRect();
            return {
                offen:    d.open,
                links:    Math.round(s.left),
                breite:   Math.round(s.width),
                schleier: !!d.querySelector('.schleier'),
                markiert: d.querySelector('.mitem.on')?.textContent.trim() ?? '',
            };
        })()`);
        ok('Ein Druck klappt es auf', auf.offen);
        ok('Und danach liegt sie am linken Rand', auf.links === 0 && auf.breite > 200,
           auf.links + ' / ' + auf.breite);
        ok('Und legt einen Schleier über die Seite', auf.schleier);
        ok('Der aktuelle Kurs ist darin markiert',
           auf.markiert.includes('Englisch'), auf.markiert);
        await b.bild('menue');

        await b.js(`[...document.querySelectorAll('#menuLinks .mitem')]
                      .find((a) => a.textContent.includes('Französisch')).click()`);
        await schlafe(1400);
        const gewechselt = await b.js(`({
            ort: location.search,
            h1:  document.querySelector('h1')?.textContent ?? '',
        })`);
        ok('Und ein Klick wechselt den Kurs',
           gewechselt.ort.includes('id=' + f.kurs2), gewechselt.ort + ' / ' + gewechselt.h1);

        // ---- Rechts das eigene Konto.

        const fliegtRechts = await b.js(`(() => {
            const d = document.getElementById('menuRechts');
            d.querySelector('summary').click();
            return d.querySelector('.schublade').getAnimations()[0]?.animationName ?? '';
        })()`);
        ok('Rechts fliegt sie von rechts herein', fliegtRechts === 'schubladeRechts',
           fliegtRechts || '(keine Animation)');

        await schlafe(400);
        const rechts = await b.js(`(() => {
            const d = document.getElementById('menuRechts');
            const s = d.querySelector('.schublade').getBoundingClientRect();
            return {
                offen:  d.open,
                links:  !document.getElementById('menuLinks').open,
                rand:   Math.round(window.innerWidth - s.right),
                ziele:  [...d.querySelectorAll('.mitem')].map((a) => a.textContent.trim()),
            };
        })()`);
        ok('Rechts öffnet das eigene Konto', rechts.offen);
        ok('Und das linke Menü schliesst sich dabei', rechts.links,
           'zwei offene Schubladen wären zwei Navigationen');
        ok('Die Leiste liegt am rechten Rand', rechts.rand === 0, String(rechts.rand));
        ok('Darin stehen Profil, Passwort und Abmelden',
           rechts.ziele.some((z) => z.includes('Profil'))
           && rechts.ziele.some((z) => z.includes('Passwort'))
           && rechts.ziele.some((z) => z.includes('Abmelden')),
           rechts.ziele.join(' | '));

        // Escape schliesst - und ein Druck auf den Schleier auch.
        await b.taste('Escape', 27);
        await schlafe(300);
        ok('Escape schliesst das Menü',
           (await b.js(`document.getElementById('menuRechts').open`)) === false);

        await b.js(`document.querySelector('#menuLinks > summary').click()`);
        await schlafe(300);
        await b.js(`document.querySelector('#menuLinks .schleier').click()`);
        await schlafe(300);
        ok('Und ein Druck daneben ebenso',
           (await b.js(`document.getElementById('menuLinks').open`)) === false);

        // ---- Die Wurzel ist die Startseite, nicht mehr die Klassenliste.

        await b.geh(f.basis + '/teacher/course.php?id=' + f.kurs, 1300);
        const wurzel = await b.js(
            `document.querySelector('#menuLinks .mitem.haupt')?.getAttribute('href') ?? ''`);
        ok('Das Häuschen im Menü führt auf die eigenen Kurse',
           wurzel.endsWith('/teacher/index.php'), wurzel);
    } finally {
        b.schliessen();
    }
}
