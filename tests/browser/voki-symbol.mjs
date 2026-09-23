/*
 * Baut das App-Symbol aus dem frohen Voki: assets/voki-icon.svg und
 * assets/voki-icon.png.
 *
 *     node tests/browser/voki-symbol.mjs
 *
 * Kein Test, sondern ein Werkzeug - es steht hier, weil es denselben
 * Chrome braucht wie die Browser-Pruefungen. Einmal laufen lassen, wenn
 * sich assets/voki-mini.svg aendert, und beide Dateien mit einchecken.
 *
 * Warum zwei Dateien: icon.php zeichnet mit GD, und GD liest kein SVG. Es
 * bekommt deshalb ein fertiges PNG mit durchsichtigem Grund und legt es auf
 * die Farbe des Kontos. Das SVG ist die Vorlage dafuer - und zugleich das
 * Favicon und die Vorschau im Profil, wo der Browser selbst zeichnet.
 *
 * Der weisse Rand: Voki ist gruen, die Sterne gelb, und die Farbe des
 * Symbols waehlt das Kind - auf Gruen oder Gelb verschwaenden beide. Unter
 * die Figur kommt deshalb eine zweite Fassung derselben Pfade, ganz weiss
 * und mit breitem weissem Strich. Die ragt um den halben Strich ueber jede
 * Form hinaus, auch um jeden Stern einzeln, und die runden Ecken des
 * Strichs halten den Rand weich statt eckig. Ein Filter mit feMorphology
 * haette dasselbe versucht, aber mit eckigem Kern - die Sternspitzen
 * wurden damit zu Klötzchen.
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { browser } from './browser.mjs';

const wurzel = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const quelle = readFileSync(resolve(wurzel, 'assets/voki-mini.svg'), 'utf8');

// Randbreite in Einheiten der Vorlage (viewBox ~1500 breit). Sichtbar ist die
// Haelfte: bei 180 px Symbolgroesse knapp 3 px, beim Favicon ein Pixel.
const STRICH = 56;
const GROESSE = 1024;

const inhalt = quelle.replace(/^[\s\S]*?<svg[^>]*>/, '').replace(/<\/svg>\s*$/, '');
const umriss = inhalt.replace(/\sfill="[^"]*"/g, '');

const b = await browser({ port: 9451, breite: GROESSE, hoehe: GROESSE });
try {
    await b.send('Emulation.setDeviceMetricsOverride',
                 { width: GROESSE, height: GROESSE, deviceScaleFactor: 1, mobile: false });

    // Die engste Box um die Figur, einschliesslich Rand - damit Voki das
    // Symbol so weit fuellt, wie es geht, und mittig sitzt.
    const box = await b.js(`(() => {
        document.body.innerHTML = ${JSON.stringify(
            `<svg xmlns="http://www.w3.org/2000/svg"><g id="g">${inhalt}</g></svg>`)};
        const r = document.getElementById('g').getBBox();
        return { x: r.x, y: r.y, w: r.width, h: r.height };
    })()`);

    const rand  = STRICH / 2 + 4;
    const kante = Math.max(box.w, box.h) + 2 * rand;
    const x     = box.x + box.w / 2 - kante / 2;
    const y     = box.y + box.h / 2 - kante / 2;
    const zahl  = (n) => Number(n.toFixed(1));

    const svg = '<svg xmlns="http://www.w3.org/2000/svg" '
        + `viewBox="${zahl(x)} ${zahl(y)} ${zahl(kante)} ${zahl(kante)}">`
        + `<g fill="#fff" stroke="#fff" stroke-width="${STRICH}" `
        + 'stroke-linejoin="round" stroke-linecap="round">' + umriss + '</g>'
        + inhalt + '</svg>\n';
    writeFileSync(resolve(wurzel, 'assets/voki-icon.svg'), svg);

    // Durchsichtig fotografieren: ohne diese Zeile legt Chrome Weiss darunter,
    // und der weisse Rand waere vom Grund nicht mehr zu unterscheiden.
    await b.send('Emulation.setDefaultBackgroundColorOverride',
                 { color: { r: 0, g: 0, b: 0, a: 0 } });
    await b.js(`(() => {
        document.documentElement.style.cssText = 'margin:0;background:transparent';
        document.body.style.cssText = 'margin:0;background:transparent';
        document.body.innerHTML = ${JSON.stringify(svg)};
        const s = document.body.firstElementChild;
        s.setAttribute('width', ${GROESSE});
        s.setAttribute('height', ${GROESSE});
        s.style.display = 'block';
    })()`);

    const foto = await b.send('Page.captureScreenshot', {
        format: 'png',
        clip: { x: 0, y: 0, width: GROESSE, height: GROESSE, scale: 1 },
    });
    if (!foto.result?.data) {
        throw new Error('Foto misslungen: ' + JSON.stringify(foto.error ?? foto));
    }
    writeFileSync(resolve(wurzel, 'assets/voki-icon.png'), Buffer.from(foto.result.data, 'base64'));

    console.log(`assets/voki-icon.svg und assets/voki-icon.png (${GROESSE} px) geschrieben.`);
} finally {
    b.schliessen();
}
