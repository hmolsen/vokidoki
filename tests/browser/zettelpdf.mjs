/*
 * Die Zettel als PDF - aus der Verwaltung als App auf dem Home-Bildschirm.
 *
 * Gemeldet: Auf dem iPhone liess sich der Zettel aus der App heraus nicht
 * drucken - kein Druckdialog, und einen Teilen-Knopf hat die App nicht.
 * Jetzt holt teacher.js das PDF und gibt es an das Teilen-Menü des Geräts.
 *
 * Chrome ist hier keine App auf dem Home-Bildschirm. Die Prüfung gibt sich
 * deshalb vor dem Laden als eine aus (navigator.standalone) und fängt ab,
 * was an das Teilen-Menü ginge.
 */

import { browser, alsLehrkraft, ok, abschnitt, schlafe } from './browser.mjs';

export async function pruefe(f, aus) {
    abschnitt('Zettel als PDF in der App');

    const b = await browser({ port: 9490, breite: 390, hoehe: 844, handy: true, aus });
    try {
        await b.send('Page.addScriptToEvaluateOnNewDocument', { source: `
            Object.defineProperty(navigator, 'standalone', { get: () => true });
            window.__geteilt = null;
            navigator.canShare = () => true;
            navigator.share = async (daten) => {
                const d = daten.files[0];
                const kopf = new Uint8Array(await d.slice(0, 5).arrayBuffer());
                window.__geteilt = { name: d.name, typ: d.type, groesse: d.size,
                                     kopf: String.fromCharCode(...kopf) };
            };` });

        await alsLehrkraft(b, f.basis, f.lehrer, f.passwort, f.kuerzel);
        await b.geh(f.basis + '/teacher/class.php?id=' + f.klasse, 1500);

        // Ein neues Passwort - danach gibt es für dieses Kind einen Zettel.
        await b.js(`(() => { window.confirm = () => true;
            document.querySelector('button[name="reset_password"]').click(); })()`);
        await schlafe(1800);
        const link = await b.js(`document.querySelector('a[data-zettel][href*="user="]')?.getAttribute('href') ?? ''`);
        ok('Der Zettel-Knopf führt zum PDF', link.includes('pdf=1'), link);

        await b.js(`document.querySelector('a[data-zettel][href*="user="]').click()`);
        await schlafe(2500);
        const fenster = await b.js(`(() => {
            const d = document.querySelector('dialog.zettelteilen');
            const k = d?.querySelector('[data-teilen]');
            return { offen: !!d?.open, bereit: !!k && !k.disabled, text: d?.querySelector('.zt-text')?.textContent ?? '' };
        })()`);
        ok('In der App öffnet sich das Fenster statt eines neuen Tabs', fenster.offen, JSON.stringify(fenster));
        ok('Das PDF ist fertig, der Knopf drückbar', fenster.bereit && fenster.text.includes('fertig'), fenster.text);
        await b.bild('zettel-teilen');

        await b.js(`document.querySelector('dialog.zettelteilen [data-teilen]').click()`);
        await schlafe(800);
        const geteilt = await b.js(`window.__geteilt`);
        ok('An das Teilen-Menü geht ein PDF', geteilt?.typ === 'application/pdf' && geteilt?.kopf === '%PDF-',
           JSON.stringify(geteilt));
        ok('Mit sprechendem Dateinamen', /^Zugangsdaten-.+\.pdf$/.test(geteilt?.name ?? ''), geteilt?.name);
        ok('Danach ist das Fenster zu', await b.js(`!document.querySelector('dialog.zettelteilen')`));
    } finally {
        b.schliessen();
    }
}
