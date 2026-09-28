/*
 * Baut den Schriftzug fuer das Symbol der Verwaltung:
 * app/assets/verwaltung-schrift.png.
 *
 *     node tests/browser/verwaltung-schrift.mjs
 *
 * Ein Werkzeug wie voki-symbol.mjs, und aus demselben Grund: icon.php
 * zeichnet mit GD, und GD kann keine woff2-Schrift setzen - eine TTF liegt
 * nicht bei, und eine zweite Schriftdatei nur fuer ein Wort waere Ballast.
 * Also setzt Chrome das Wort einmal in Fredoka, weiss auf durchsichtigem
 * Grund, eng um die Buchstaben geschnitten; icon.php legt es auf den grauen
 * Balken. Nur neu bauen, wenn sich das Wort oder die Schrift aendert.
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { browser, schlafe } from './browser.mjs';

const wurzel = resolve(dirname(fileURLToPath(import.meta.url)), '../../app');
const schrift = readFileSync(resolve(wurzel, 'assets/fonts/fredoka.woff2')).toString('base64');

const WORT  = 'Verwaltung';
const HOEHE = 160;   // Schriftgroesse in px - gross genug fuer 1024er Symbole

const b = await browser({ port: 9452, breite: 1400, hoehe: 400 });
try {
    await b.send('Emulation.setDeviceMetricsOverride',
                 { width: 1400, height: 400, deviceScaleFactor: 1, mobile: false });
    await b.send('Emulation.setDefaultBackgroundColorOverride',
                 { color: { r: 0, g: 0, b: 0, a: 0 } });

    await b.js(`(() => {
        const stil = document.createElement('style');
        stil.textContent = "@font-face { font-family: F; font-weight: 600;"
            + " src: url(data:font/woff2;base64,${schrift}) format('woff2'); }"
            + " html, body { margin: 0; background: transparent; }"
            + " #w { display: inline-block; padding: 0 4px; font: 600 ${HOEHE}px/1.32 F;"
            + " color: #fff; white-space: nowrap; }";
        document.head.append(stil);
        document.body.innerHTML = '<span id="w">${WORT}</span>';
    })()`);
    await b.js('document.fonts.ready.then(() => true)');
    await schlafe(300);

    const r = await b.js(`(() => {
        const r = document.getElementById('w').getBoundingClientRect();
        return { x: r.x, y: r.y, w: r.width, h: r.height };
    })()`);

    const foto = await b.send('Page.captureScreenshot', {
        format: 'png',
        clip: { x: r.x, y: r.y, width: Math.ceil(r.w), height: Math.ceil(r.h), scale: 1 },
    });
    if (!foto.result?.data) {
        throw new Error('Foto misslungen: ' + JSON.stringify(foto.error ?? foto));
    }
    writeFileSync(resolve(wurzel, 'assets/verwaltung-schrift.png'),
                  Buffer.from(foto.result.data, 'base64'));
    console.log(`assets/verwaltung-schrift.png (${Math.ceil(r.w)} x ${Math.ceil(r.h)} px) geschrieben.`);
} finally {
    b.schliessen();
}
