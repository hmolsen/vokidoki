/*
 * "Es gibt eine neue Fassung" - auch im Lehrkraft-Bereich.
 *
 * Das Band stand lange nur in der App. Die Verwaltung hat inzwischen ein
 * eigenes Symbol auf dem Home-Bildschirm und liegt dort wochenlang im
 * Hintergrund; ohne Band erfuhr sie von keiner Aktualisierung.
 *
 * Geprüft wird echt, nicht nachgestellt: Die Seite ist offen, dann ändert
 * sich auf dem Server eine Datei (hier nur ihr Datum - der Stempel ist das
 * jüngste Änderungsdatum der Oberfläche), und beim nächsten Blick auf die
 * Seite muss das Band kommen. Ausgerechnet admin.css, weil sie bis hierher
 * den Stempel gar nicht bewegte.
 */

import { utimesSync } from 'node:fs';
import { resolve } from 'node:path';
import { browser, alsLehrkraft, ok, abschnitt, schlafe } from './browser.mjs';

export async function pruefe(f, aus, wurzel) {
    abschnitt('Neue Fassung im Lehrkraft-Bereich');

    const b = await browser({ port: 9418, breite: 1100, hoehe: 800, aus });
    try {
        await alsLehrkraft(b, f.basis, f.lehrer, f.passwort);
        await b.geh(f.basis + '/teacher/index.php', 1500);

        await b.js(`window.dispatchEvent(new Event('focus'))`);
        await schlafe(900);
        ok('Ohne Änderung kein Band',
           (await b.js(`!!document.getElementById('update-bar')`)) === false);

        // Eine Datei der Verwaltung ändert sich auf dem Server.
        const datei = resolve(wurzel, 'admin/admin.css');
        const jetzt = new Date(Date.now() + 5000);
        utimesSync(datei, jetzt, jetzt);

        await b.js(`window.dispatchEvent(new Event('focus'))`);
        await schlafe(1200);
        const band = await b.js(`(() => {
            const bar = document.getElementById('update-bar');
            return bar ? { text: bar.textContent.replace(/\\s+/g, ' ').trim(),
                           da: bar.classList.contains('show') } : null;
        })()`);
        ok('Dann fährt das Band herein', band?.da === true, JSON.stringify(band));
        ok('Mit "Aktualisieren"',
           (band?.text ?? '').includes('Es gibt eine neue Fassung') && band.text.includes('Aktualisieren'));

        if (aus) await b.bild('fassung-lehrkraft');

        await b.js(`document.getElementById('update-go').click()`);
        await schlafe(2500);
        const danach = await b.js(`({
            seite: location.pathname,
            band:  !!document.getElementById('update-bar'),
        })`);
        ok('"Aktualisieren" lädt dieselbe Seite neu',
           danach.seite.endsWith('/teacher/index.php'), danach.seite);
        await b.js(`window.dispatchEvent(new Event('focus'))`);
        await schlafe(900);
        ok('Und danach ist das Band weg',
           (await b.js(`!!document.getElementById('update-bar')`)) === false);
    } finally {
        b.schliessen();
    }
}
