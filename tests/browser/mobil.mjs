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
    // Der Assistent hat keine Tabelle - hier zaehlt nur, dass nichts
    // seitwaerts laeuft und die Kacheln untereinander passen.
    ['Neuer Kurs',   '/teacher/neu.php'],
    ['Sprachwahl',   '/teacher/neu.php?klasse=' + f.klasse],
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

        /*
         * Die Freigabetabelle nimmt das Kartenlayout bewusst NICHT an: Dort
         * vergleicht man Vokabeln zeilenweise, und der Balken braucht
         * durchgehende Zeilen.
         *
         * Nur griff die Ausnahme lange nicht ganz: Zwei der Kartenregeln
         * sind zweiklassig (`table.data td[data-label]::before` und
         * `table.data td:first-child`) und wiegen schwerer als
         * `table.release td`. Vor jedem Wort stand deshalb noch einmal
         * "FREMDSPRACHE". Am Quelltext sah die Ausnahme richtig aus;
         * gerechnet wurde etwas anderes - das sieht nur ein Browser.
         */
        await b.geh(f.basis + '/teacher/unit.php?id=' + f.unit, 1400);
        const tab = await b.js(`(() => {
            const t = document.getElementById('freigabe');
            const zeile = t.querySelector('tbody tr[data-pos]');
            /*
             * Die zweite Zelle, nicht die erste: Die erste ist in den
             * Kartenregeln die Kartenueberschrift und traegt darum ohnehin
             * nie ein Label (table.data td:first-child::before). Wer dort
             * misst, misst nichts.
             */
            const erste  = zeile.children[0];
            const zweite = zeile.children[1];
            return {
                tabelle: getComputedStyle(t).display,
                spalten: zeile.children.length,
                kopf:    [...t.querySelectorAll('thead th')].map((e) => e.textContent.trim()),
                fahnen:  t.querySelectorAll('thead img.kopfflagge').length,
                fahnenDa: [...t.querySelectorAll('thead img.kopfflagge')]
                             .every((i) => i.naturalWidth > 0),
                knoepfe: (() => {
                    const b = [...zeile.querySelectorAll('td.actions .iconaction')]
                        .filter((e) => getComputedStyle(e).display !== 'none');
                    return {
                        zahl:   b.length,
                        zeilen: new Set(b.map((e) => Math.round(
                            e.getBoundingClientRect().top))).size,
                    };
                })(),
                zeiger:  matchMedia('(hover: hover)').matches,
                kopfda:  getComputedStyle(t.querySelector('thead th')).display !== 'none',
                zelle:   getComputedStyle(erste).display,
                zelle2:  getComputedStyle(zweite).display,
                label:   getComputedStyle(zweite, '::before').content,
                bar:     !!document.querySelector('.releasebar'),
                /*
                 * Am Telefon wird jede Tabellenzeile zum Block - und das
                 * schlaegt das eingebaute [hidden] { display: none }. Die
                 * Anlegezeile stand dadurch am Telefon dauerhaft offen,
                 * mit zwei leeren Feldern mitten in der Vokabelliste.
                 */
                anlegezeile: getComputedStyle(
                    document.getElementById('handzeile')).display,
            };
        })()`);

        ok('Die Freigabetabelle bleibt eine Tabelle', tab.tabelle === 'table', tab.tabelle);
        ok('Mit drei Spalten', tab.spalten === 3,
           tab.spalten + ' - Nummer und Satzzahl kosteten die Breite der Woerter');
        ok('Im Kopf steht die Sprache, nicht das Wort "Fremdsprache"',
           tab.kopf.join('|') === 'Englisch|Deutsch|', tab.kopf.join('|'));
        ok('Und vor beiden steht eine Fahne', tab.fahnen === 2, String(tab.fahnen));
        ok('Die auch wirklich ankommt', tab.fahnenDa,
           'ein <img> mit kaputtem src sieht im Quelltext genauso aus');

        /*
         * Stift und Muelleimer nebeneinander, nicht untereinander. Als
         * farbige Emoji sind sie breiter als ein Zeichen aus der
         * Textschrift; bei 86 px brach der zweite um.
         */
        ok('Stift und Mülleimer stehen in einer Zeile',
           tab.knoepfe.zahl === 2 && tab.knoepfe.zeilen === 1,
           tab.knoepfe.zahl + ' Knöpfe auf ' + tab.knoepfe.zeilen + ' Zeilen');
        ok('Der Kopf steht auch am Telefon da', tab.kopfda,
           'die Kartenregel blendet ihn aus - hier soll er bleiben');
        ok('Die Zellen sind Zellen, keine Karten',
           tab.zelle === 'table-cell' && tab.zelle2 === 'table-cell',
           tab.zelle + ' / ' + tab.zelle2);
        ok('Und keine traegt die Spaltenueberschrift noch einmal',
           tab.label === 'none', tab.label);
        ok('Und der Balken ist auch am Telefon da', tab.bar);
        ok('Die Anlegezeile bleibt zugeklappt, auch am Telefon',
           tab.anlegezeile === 'none', tab.anlegezeile
           + ' - display: block aus der Kartenregel schlaegt sonst [hidden]');

        // ---- Der Kopf bleibt beim Rollen stehen, unter der Leiste.

        await b.js(`window.scrollTo(0, 900)`);
        await schlafe(400);
        const kleben = await b.js(`(() => {
            const th = document.querySelector('#freigabe thead th');
            const leiste = document.querySelector('.adminbar');
            return {
                gerollt: Math.round(window.scrollY),
                kopf:    Math.round(th.getBoundingClientRect().top),
                leiste:  Math.round(leiste.getBoundingClientRect().bottom),
                mass:    getComputedStyle(document.documentElement)
                            .getPropertyValue('--barhoehe').trim(),
            };
        })()`);

        ok('Die Seite laesst sich weit genug rollen', kleben.gerollt > 300,
           kleben.gerollt + ' px');
        ok('Der Tabellenkopf bleibt dabei stehen',
           kleben.kopf >= 0 && kleben.kopf < 200,
           kleben.kopf + ' px - mit overflow: hidden klebt nichts');
        ok('Und zwar unter der Leiste, nicht hinter ihr',
           kleben.kopf >= kleben.leiste - 2,
           kleben.kopf + ' px gegen Leistenunterkante ' + kleben.leiste);
        ok('Deren Hoehe ist gemessen, nicht geschaetzt',
           /^\d+px$/.test(kleben.mass), kleben.mass || '(leer)');
        await b.js(`window.scrollTo(0, 0)`);

        // ---- Auch beim Aendern und auch auf schmaleren Telefonen.

        for (const [breite, hoehe] of [[390, 844], [375, 812], [320, 568]]) {
            await b.groesse(breite, hoehe);
            await b.geh(f.basis + '/teacher/unit.php?id=' + f.unit, 1400);

            /*
             * Zwei Fragen, nicht eine.
             *
             * "Scrollt die Seite seitwaerts" allein genuegt hier nicht:
             * table.data traegt overflow: hidden (fuer die runden Ecken),
             * und was zu breit ist, wird deshalb abgeschnitten statt
             * hinausgeschoben. Die Seite bleibt ruhig, die Knoepfe sind
             * trotzdem weg. Also auch die Tabelle selbst messen:
             * scrollWidth meldet den Inhalt, auch wenn er abgeschnitten
             * wird.
             */
            const messen = `(() => {
                const t = document.getElementById('freigabe');
                const z = t.querySelector('tbody tr[data-pos]');
                return {
                    spalten: [...z.children]
                        .map((c) => Math.round(c.getBoundingClientRect().width)).join('/'),
                    quer:    document.documentElement.scrollWidth > window.innerWidth + 1,
                    breit:   document.documentElement.scrollWidth,
                    fenster: window.innerWidth,
                    inhalt:  t.scrollWidth,
                    platz:   t.clientWidth,
                    zuviel:  t.scrollWidth > t.clientWidth + 1,
                    offen:   !!document.querySelector('#freigabe tr.bearbeiten'),
                };
            })()`;

            const ruhe = await b.js(messen);
            ok(`Bei ${breite} px scrollt nichts seitwaerts`, !ruhe.quer,
               ruhe.breit + ' px in einem ' + ruhe.fenster + ' px breiten Fenster');
            ok(`Und bei ${breite} px wird auch nichts abgeschnitten`, !ruhe.zuviel,
               ruhe.inhalt + ' px Inhalt in einer ' + ruhe.platz + ' px breiten Tabelle');

            /*
             * Und beim Aendern: Dann stehen in derselben Zeile zwei
             * Eingabefelder statt zweier Woerter. Nimmt die Tabelle ihre
             * Breite vom Inhalt, rutschen die Spalten dabei unter dem
             * Finger weg - deshalb verteilt sie sie am Telefon fest.
             */
            await b.js(`document.querySelector('#freigabe tr[data-pos] [data-edit]').click()`);
            await schlafe(400);
            const beim = await b.js(messen);
            ok(`Beim Aendern bleibt es bei ${breite} px dabei`,
               beim.offen && !beim.quer && !beim.zuviel,
               beim.inhalt + ' px Inhalt in einer ' + beim.platz + ' px breiten Tabelle');
            ok(`Und die Spalten stehen bei ${breite} px still`,
               beim.spalten === ruhe.spalten,
               ruhe.spalten + ' wird zu ' + beim.spalten);

            await b.bild('mobil-freigabe-' + breite);
        }
        await b.groesse(390, 844);
    } finally {
        b.schliessen();
    }
}
