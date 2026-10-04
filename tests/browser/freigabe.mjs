/*
 * Der Freigabebalken - die Prüfung, die einen echten Fehler gefunden hat.
 *
 * Der Balken war einmal eine Tabellenzeile und wurde beim Ziehen per
 * insertBefore zwischen die Vokabeln gesetzt. Ein Element in der DOM
 * umzuhängen nimmt ihm die Zeigerbindung: Nach dem ersten Schritt kamen von
 * dreissig pointermove noch drei am Griff an, und der Balken liess sich um
 * genau eine Vokabel bewegen. Im Quelltext sah alles richtig aus.
 *
 * Deshalb wird hier nicht das Markup geprüft, sondern gezogen.
 */

import { browser, alsLehrkraft, ok, abschnitt, schlafe } from './browser.mjs';

export async function pruefe(f, aus) {
    abschnitt('Freigabebalken');

    // Hoch genug, dass die ganze Liste hineinpasst - das Mitrollen am
    // Fensterrand wird eigens geprüft und soll hier nicht dazwischenfunken.
    const b = await browser({ port: 9401, breite: 1200, hoehe: 1500, aus });
    try {
        await alsLehrkraft(b, f.basis, f.lehrer, f.passwort, f.kuerzel);

        // Das Formular darf nicht wirklich abschicken - sonst navigiert die
        // Seite weg und wir sehen nichts mehr. Statt dessen merken wir uns,
        // WAS es abgeschickt hätte.
        await b.send('Page.addScriptToEvaluateOnNewDocument', { source: `
            window.__abgeschickt = null;
            HTMLFormElement.prototype.submit = function () {
                window.__abgeschickt = Object.fromEntries(new FormData(this).entries());
            };
        ` });
        await b.geh(f.basis + '/teacher/unit.php?id=' + f.unit, 1400);

        const aufbau = await b.js(`(() => {
            const bar = document.querySelector('.releasebar');
            const t = document.getElementById('freigabe');
            return {
                da:        !!bar,
                inHuelle:  !!(bar && bar.parentElement.classList.contains('releasewrap')),
                nichtImTr: !!(bar && bar.closest('table') === null),
                zeilen:    t ? t.querySelectorAll('tr[data-pos]').length : 0,
                blase:     bar?.querySelector('.bubble')?.textContent ?? '',
                knoepfeWeg: document.querySelectorAll('.js-hide').length === 0,
            };
        })()`);

        ok('Der Balken ist da', aufbau.da);
        ok('Er liegt über der Tabelle, nicht in ihr', aufbau.inHuelle && aufbau.nichtImTr,
           'als <tr> verliert er beim Verschieben die Zeigerbindung');
        ok(`${f.vokabeln} Vokabelzeilen`, aufbau.zeilen === f.vokabeln, String(aufbau.zeilen));
        ok('Die Blase nennt den Stand',
           aufbau.blase === `${f.frei} von ${f.vokabeln} freigegeben`, aufbau.blase);
        ok('Die Knöpfe je Zeile sind weg', aufbau.knoepfeWeg);

        // ---- Ein Zug über viele Zeilen, in kleinen Schritten wie eine Maus.

        const lage = await b.js(`(() => {
            const g = document.querySelector('.releasebar .grip').getBoundingClientRect();
            const z = [...document.querySelectorAll('#freigabe tr[data-pos]')]
                        .map((t) => t.getBoundingClientRect().bottom);
            return { x: g.x + g.width / 2, y: g.y + g.height / 2, unten: z };
        })()`);

        const ziel = lage.unten[17];              // 18 freigegeben
        await b.maus('mousePressed', lage.x, lage.y);
        for (let y = lage.y; y < ziel; y += 5) {
            await b.maus('mouseMoved', lage.x, y);
        }
        await b.maus('mouseMoved', lage.x, ziel);
        await schlafe(80);

        const waehrend = await b.js(`({
            frei:  document.querySelectorAll('#freigabe tr.released').length,
            blase: document.querySelector('.releasebar .bubble').textContent,
            gesendet: window.__abgeschickt,
        })`);

        ok('Auch in kleinen Schritten läuft der Balken durch', waehrend.frei === 18,
           waehrend.frei + ' statt 18 - er blieb nach dem ersten Schritt stehen');
        ok('Die Zahl zählt beim Ziehen mit',
           waehrend.blase === `18 von ${f.vokabeln} freigegeben`, waehrend.blase);
        ok('Beim Ziehen wird noch nichts gespeichert', waehrend.gesendet === null);

        await b.maus('mouseReleased', lage.x, ziel);
        await schlafe(80);

        const danach = await b.js('window.__abgeschickt');
        ok('Erst das Loslassen speichert', danach !== null);
        ok('Und zwar genau den gezogenen Stand', String(danach?.release) === '18',
           'release=' + danach?.release);

        // ---- Und jetzt schnell.

        /*
         * Kleine Schritte sind der gutmütige Fall: Der Balken bleibt unter
         * dem Zeiger, also treffen die Ereignisse ihn auch ohne
         * Zeigerbindung. Wer zügig zieht, überholt ihn - und dann entscheidet
         * die Bindung, ob der Zug ankommt oder auf einer Tabellenzeile
         * landet. Genau daran ist die erste Fassung gescheitert.
         */
        await b.js('window.__abgeschickt = null');
        const zweit = await b.js(`(() => {
            const g = document.querySelector('.releasebar .grip').getBoundingClientRect();
            const z = [...document.querySelectorAll('#freigabe tr[data-pos]')]
                        .map((t) => t.getBoundingClientRect().bottom);
            return { x: g.x + g.width / 2, y: g.y + g.height / 2, ziel: z[3] };
        })()`);

        await b.maus('mousePressed', zweit.x, zweit.y);
        // Drei grosse Sprünge nach oben, so wie eine Hand es tut.
        const weg = zweit.y - zweit.ziel;
        for (const anteil of [0.34, 0.67, 1]) {
            await b.maus('mouseMoved', zweit.x, zweit.y - weg * anteil);
            await schlafe(20);
        }
        await schlafe(60);
        const schnellFrei = await b.js(
            `document.querySelectorAll('#freigabe tr.released').length`);
        await b.maus('mouseReleased', zweit.x, zweit.ziel);
        await schlafe(120);
        const schnellDanach = await b.js('window.__abgeschickt');

        ok('Auch ein zügiger Zug kommt an', schnellFrei === 4,
           schnellFrei + ' statt 4 - der Zeiger hat den Balken überholt');
        ok('Und wird beim Loslassen gespeichert', String(schnellDanach?.release) === '4',
           'release=' + schnellDanach?.release);

        await b.bild('freigabe');
    } finally {
        b.schliessen();
    }
}
