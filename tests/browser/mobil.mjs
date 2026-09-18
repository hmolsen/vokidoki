/*
 * Am Telefon.
 *
 * Eine Lehrkraft steht mit dem Telefon in der Hand vor der Klasse.
 * Unterhalb von 720 px wird aus jeder Tabellenzeile eine Karte. Ob das
 * hält, sagt kein Quelltext - es hängt an gerechnetem Layout. Geprüft wird
 * die eine Frage, die alles entscheidet: Scrollt die Seite seitwärts?
 */

import { browser, alsLehrkraft, ok, abschnitt, schlafe } from './browser.mjs';

const SEITEN = (f) => [
    ['Klassen',      '/teacher/classes.php'],
    ['Klasse',       '/teacher/class.php?id=' + f.klasse],
    ['Kurs',         '/teacher/course.php?id=' + f.kurs],
    ['Lerneinheit',  '/teacher/unit.php?id=' + f.unit],
];

export async function pruefe(f, aus) {
    abschnitt('Am Telefon (390 px)');

    const b = await browser({ port: 9404, breite: 390, hoehe: 844, handy: true, aus });
    try {
        await alsLehrkraft(b, f.basis, f.lehrer, f.passwort);

        for (const [name, pfad] of SEITEN(f)) {
            await b.geh(f.basis + pfad, 1200);

            /*
             * table.release ist bewusst ausgenommen - dort vergleicht man
             * Vokabeln zeilenweise, und der Balken braucht durchgehende
             * Zeilen. Geprüft wird das zwei Abschnitte weiter unten; hier
             * würde es als Fehler erscheinen, obwohl es die Absicht ist.
             */
            const lage = await b.js(`(() => {
                const zeilen = [...document.querySelectorAll('table.data:not(.release) tr')];
                return {
                    quer:    document.documentElement.scrollWidth > window.innerWidth + 1,
                    breit:   document.documentElement.scrollWidth,
                    fenster: window.innerWidth,
                    tabellen: document.querySelectorAll('table.data:not(.release)').length,
                    karten:  zeilen.filter((t) => getComputedStyle(t).display === 'block').length,
                    kopf:    zeilen.filter((t) => t.querySelector('th')
                                               && getComputedStyle(t).display !== 'none').length,
                };
            })()`);

            ok(`${name}: nichts scrollt seitwärts`, !lage.quer,
               lage.breit + ' px in einem ' + lage.fenster + ' px breiten Fenster');

            if (lage.tabellen > 0) {
                ok(`${name}: aus Zeilen werden Karten`, lage.karten > 0, String(lage.karten));
                ok(`${name}: die Kopfzeile ist weg`, lage.kopf === 0,
                   lage.kopf + ' sichtbare Kopfzeilen - sie gehören am Telefon nicht dorthin');
            }

            await b.bild('mobil-' + name.toLowerCase());
        }

        // Die Freigabetabelle nimmt das Kartenlayout bewusst NICHT an: Dort
        // vergleicht man Vokabeln zeilenweise, und der Balken braucht
        // durchgehende Zeilen.
        await b.geh(f.basis + '/teacher/unit.php?id=' + f.unit, 1200);
        const balken = await b.js(`({
            tabelle: getComputedStyle(document.getElementById('freigabe')).display,
            bar: !!document.querySelector('.releasebar'),
        })`);
        ok('Die Freigabetabelle bleibt eine Tabelle', balken.tabelle === 'table', balken.tabelle);
        ok('Und der Balken ist auch am Telefon da', balken.bar);
    } finally {
        b.schliessen();
    }
}
