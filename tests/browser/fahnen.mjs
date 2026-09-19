/*
 * Fahnen sind Bilder - und ob ein Bild ankommt, sagt der Quelltext nicht.
 *
 * Windows stellt die Regionalzeichen nicht als Fahne dar, sondern als die
 * zwei Buchstaben des Länderkürzels: aus der britischen Fahne wird "GB".
 * Deshalb liegt zu jedem Sinnbild eine SVG-Datei bei. Ein <img> mit einem
 * kaputten src sieht im HTML aber genauso aus wie eines, das lädt -
 * unterscheiden lässt sich das nur an naturalWidth.
 */

import { browser, alsLehrkraft, ok, abschnitt, schlafe } from './browser.mjs';

export async function pruefe(f, aus) {
    abschnitt('Fahnen');

    const b = await browser({ port: 9402, breite: 1200, hoehe: 1000, aus });
    try {
        await alsLehrkraft(b, f.basis, f.lehrer, f.passwort);
        await b.geh(f.basis + '/teacher/class.php?id=' + f.klasse, 1400);

        const kurs = await b.js(`(() => {
            const i = document.querySelector('#kurse img.cflag');
            return { da: !!i, breit: i?.naturalWidth ?? 0,
                     quelle: (i?.currentSrc ?? '').split('/').pop(),
                     alsText: document.querySelectorAll('#kurse span.cflag:not(.plus)').length };
        })()`);

        ok('Die Kurszeile zeigt eine Fahne als Bild', kurs.da, 'sie steht als Text da');
        ok('Und das Bild ist wirklich angekommen', kurs.breit > 0,
           'naturalWidth ' + kurs.breit + ' - die Datei fehlt');
        ok('Es ist die britische', kurs.quelle === '1f1ec-1f1e7.svg', kurs.quelle);

        /*
         * Das Auswahlfeld steht jetzt im Kursassistenten, nicht mehr in der
         * Anlegezeile der Klasse: Ein Kurs entsteht in zwei Schritten, und
         * die Sprache ist der zweite.
         */
        await b.geh(f.basis + '/teacher/neu.php?klasse=' + f.klasse, 1400);
        await b.js(`document.querySelector('.pickbtn').click()`);
        await schlafe(600);

        const liste = await b.js(`({
            bilder: document.querySelectorAll('.picklist img.pickflag').length,
            text:   document.querySelectorAll('.picklist span.pickflag').length,
            breit:  document.querySelector('.picklist img.pickflag')?.naturalWidth ?? 0,
        })`);

        ok('Die aufgeklappte Sprachliste zeigt Fahnen als Bild', liste.bilder > 50,
           liste.bilder + ' Bilder, ' + liste.text + ' als Text');
        ok('Auch dort kommen die Bilder an', liste.breit > 0, 'naturalWidth ' + liste.breit);

        // Eine andere Sprache wählen - dann wird der Knopf neu gesetzt.
        await b.js(`(() => {
            const s = document.querySelector('.picksearch');
            s.value = 'danisch';
            s.dispatchEvent(new Event('input', { bubbles: true }));
        })()`);
        await schlafe(400);
        await b.js(`document.querySelector('.picklist li[role=option]').click()`);
        await schlafe(400);

        const nach = await b.js(`({
            quelle: (document.querySelector('.pickbtn img.pickflag')?.src ?? '').split('/').pop(),
            name:   document.querySelector('.picklabel')?.textContent ?? '',
            wert:   document.querySelector('select[data-picker]')?.value ?? '',
        })`);

        ok('Nach dem Auswählen steht die neue Fahne am Knopf',
           nach.quelle === '1f1e9-1f1f0.svg', nach.quelle);
        ok('Der Name steht daneben', nach.name === 'Dänisch', nach.name);
        ok('Und das versteckte Feld trägt ihn auch', nach.wert === 'Dänisch', nach.wert);

        await b.bild('fahnen');
    } finally {
        b.schliessen();
    }
}
