/*
 * Die Wahl beim Einlesen: neue Lerneinheit oder eine vorhandene erweitern.
 *
 * Bis dahin legte jedes Einlesen eine neue an - wer eine zweite Buchseite
 * derselben Lektion fotografierte, bekam "Unit 4" und "Unit 4 (2)" und
 * musste beide einzeln freigeben.
 *
 * Geprüft wird hier, was der PHP-Suite verborgen bleibt: dass die Auswahl
 * wirklich umschaltet, dass das Titelfeld dabei verschwindet und dass die
 * Beschriftung des Knopfes mitgeht.
 */

import { browser, alsLehrkraft, ok, abschnitt, schlafe } from './browser.mjs';

export async function pruefe(f, aus) {
    abschnitt('Einlesen: wohin?');

    const b = await browser({ port: 9406, breite: 900, hoehe: 1100, aus });
    try {
        await alsLehrkraft(b, f.basis, f.lehrer, f.passwort, f.kuerzel);

        /*
         * Direkt in den Prüfschritt: Fotografieren lässt sich hier nicht,
         * also wird der Entwurf von Hand in den localStorage gelegt - genau
         * das tut die Ansicht auch, wenn jemand zwischendurch weggeht.
         */
        await b.geh(f.basis + '/', 1200);
        await b.js(`localStorage.setItem('vt-draft-${f.sprache}', JSON.stringify({
            title: 'Aus dem Entwurf',
            entries: [ { foreign: 'window', native: 'Fenster' },
                       { foreign: 'door',   native: 'Tür' } ],
        }))`);
        await b.geh(f.basis + '/#/lang/' + f.sprache + '/import', 2200);

        const start = await b.js(`({
            titel:   document.querySelector('.topbar h1')?.textContent ?? '',
            wahl:    !!document.getElementById('ziel'),
            optionen: [...(document.getElementById('ziel')?.options ?? [])]
                        .map((o) => o.textContent.trim()),
            vorbelegt: document.getElementById('ziel')?.value ?? null,
            titelfeld: document.getElementById('neueEinheit')?.hidden,
            knopf:   document.getElementById('save')?.textContent?.trim() ?? '',
        })`);

        ok('Der Prüfschritt steht da', start.titel === 'Stimmt das so?', start.titel);

        /*
         * Hierher kommt eine Lehrkraft mit einem Klick von ihrer Startseite
         * ("+ Lerneinheit" auf der Kurskarte). Zurueck fuehrte von hier gar
         * nichts: Der Pfeil oben geht in die Schueleransicht, und die
         * installierte App hat keine Adresszeile.
         */
        const heim = await b.js(
            `document.querySelector('.kurszeile a.btn')?.getAttribute('href') ?? ''`);
        ok('Und trägt einen Weg zurück in die Verwaltung',
           heim.endsWith('/teacher/'), heim || '(keiner)');

        await b.js(`document.querySelector('.kurszeile a.btn').click()`);
        await schlafe(1600);
        ok('Der auf die Startseite der Lehrkraft führt',
           (await b.js(`document.querySelector('h1')?.textContent ?? ''`)) === 'Meine Kurse');
        await b.geh(f.basis + '/#/lang/' + f.sprache + '/import', 2200);
        ok('Und lässt die Wahl, wohin', start.wahl,
           'ohne sie legt jedes Einlesen eine neue Lerneinheit an');
        ok('Die vorhandene Lerneinheit steht zur Auswahl',
           start.optionen.some((o) => o.includes('Unit 1 - Browsertest')),
           start.optionen.join(' | '));
        ok('Vorbelegt ist "Neue Lerneinheit"', start.vorbelegt === '',
           JSON.stringify(start.vorbelegt));
        ok('Das Titelfeld ist dabei sichtbar', start.titelfeld === false);
        ok('Und der Knopf heisst "Lerneinheit speichern"',
           start.knopf === 'Lerneinheit speichern', start.knopf);

        // ---- Umschalten auf Anhängen.

        await b.js(`(() => {
            const s = document.getElementById('ziel');
            s.value = String(${f.unit});
            s.dispatchEvent(new Event('change', { bubbles: true }));
        })()`);
        await schlafe(400);

        const umgeschaltet = await b.js(`({
            titelfeld: document.getElementById('neueEinheit')?.hidden,
            hinweis:   document.getElementById('zielhinweis')?.hidden,
            knopf:     document.getElementById('save')?.textContent?.trim() ?? '',
        })`);

        ok('Beim Anhängen verschwindet das Titelfeld', umgeschaltet.titelfeld === true,
           'sonst tippt jemand einen Titel, der nirgends landet');
        ok('Dafür steht da, was das Anhängen bedeutet', umgeschaltet.hinweis === false);
        ok('Und der Knopf heisst jetzt "Vokabeln anhängen"',
           umgeschaltet.knopf === 'Vokabeln anhängen', umgeschaltet.knopf);
        await b.bild('einlesen-anhaengen');

        // ---- Und zurück.

        await b.js(`(() => {
            const s = document.getElementById('ziel');
            s.value = '';
            s.dispatchEvent(new Event('change', { bubbles: true }));
        })()`);
        await schlafe(400);
        ok('Zurück auf "neu" ist das Titelfeld wieder da',
           (await b.js(`document.getElementById('neueEinheit')?.hidden`)) === false);

        // ---- Wirklich anhängen.

        await b.js(`(() => {
            const s = document.getElementById('ziel');
            s.value = String(${f.unit});
            s.dispatchEvent(new Event('change', { bubbles: true }));
            document.getElementById('save').click();
        })()`);
        await schlafe(2500);

        /*
         * Und danach dort, wo es weitergeht.
         *
         * Fuer eine Lehrkraft ist das die Freigabe, nicht die
         * Schueleransicht: Eingelesen ist noch nicht aufgemacht, und in der
         * Schueleransicht saehe sie eine leere Liste - freigegeben ist ja
         * noch nichts.
         */
        const danach = await b.js(`({
            ort:   location.pathname + location.search + location.hash,
            titel: document.querySelector('h1 [data-titel]')?.textContent?.trim() ?? '',
            balken: !!document.querySelector('.releasebar'),
        })`);
        ok('Nach dem Anhängen steht man in der Freigabe der Lerneinheit',
           danach.ort.includes('/teacher/unit.php?id=' + f.unit), danach.ort);
        ok('Und es ist die vorhandene, keine neue',
           danach.titel === 'Unit 1 - Browsertest', danach.titel);
        ok('Der Freigabebalken ist gleich da', danach.balken,
           'der nächste Griff nach dem Einlesen ist immer derselbe');

        await b.js(`localStorage.clear()`);
    } finally {
        b.schliessen();
    }
}
