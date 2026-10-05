/*
 * Am Rechner steht das Menü fest links - im Lehrkraft-Bereich und im Admin.
 *
 * Es ist dieselbe Schublade wie am Telefon, nur offen
 * (seitenleiste_skript() in lib/html.php). Was der Quelltext nicht verrät:
 * dass sie schon im ersten Bild dasteht, statt nachzurücken, dass sie sich
 * weder durch das rechte Menü noch durch Escape schliessen lässt, und dass
 * sie beim Verkleinern des Fensters wieder zur Schublade wird.
 */

import { browser, alsLehrkraft, ok, abschnitt, schlafe } from './browser.mjs';

const zustand = `(() => {
    const m = document.getElementById('menuLinks');
    const s = m.querySelector('.schublade');
    const r = s.getBoundingClientRect();
    return {
        offen: m.open,
        fest: m.classList.contains('fest'),
        knopf: getComputedStyle(m.querySelector('summary')).display,
        schleier: getComputedStyle(m.querySelector('.schleier')).display,
        links: Math.round(r.left), breit: Math.round(r.width),
        inhalt: Math.round(document.querySelector('main').getBoundingClientRect().left),
    };
})()`;

export async function pruefe(f, aus) {
    abschnitt('Feste Seitenleiste am Rechner');

    const b = await browser({ port: 9488, breite: 1360, hoehe: 900, aus });
    try {
        await alsLehrkraft(b, f.basis, f.lehrer, f.passwort, f.kuerzel);
        const z = await b.js(zustand);
        ok('Im Lehrkraft-Bereich steht das Menü offen da', z.offen && z.fest);
        ok('Am linken Rand, ohne Menüknopf und ohne Schleier',
           z.links === 0 && z.breit > 200 && z.knopf === 'none' && z.schleier === 'none', JSON.stringify(z));
        ok('Die Seite rückt daneben, statt darunter zu liegen', z.inhalt >= z.breit, JSON.stringify(z));
        ok('Ohne Hereinfliegen',
           await b.js(`document.querySelector('#menuLinks .schublade').getAnimations().length`) === 0);

        // Das rechte Menü öffnen: Das linke bleibt.
        await b.js(`document.querySelector('#menuRechts > summary').click()`);
        await schlafe(500);
        ok('Das rechte Menü lässt die Leiste offen', await b.js(`document.getElementById('menuLinks').open`));
        await b.taste('Escape', 27);
        await schlafe(500);
        ok('Escape schliesst das rechte Menü, nicht die Leiste',
           await b.js(`!document.getElementById('menuRechts').open && document.getElementById('menuLinks').open`));

        // Schmaler: wieder eine Schublade.
        await b.groesse(900, 900);
        await schlafe(400);
        const schmal = await b.js(zustand);
        ok('Schmaler wird die Leiste wieder zur Schublade', !schmal.offen && !schmal.fest && schmal.knopf !== 'none',
           JSON.stringify(schmal));
        await b.groesse(1360, 900);
        await schlafe(400);
        ok('Und breiter wieder zur Leiste', (await b.js(zustand)).fest);

        // Der Admin: dieselbe Leiste, gegliedert.
        await b.geh(f.basis + '/admin/', 1000);
        await b.js(`(() => { const p = document.querySelector('input[type="password"]');
            if (p) { p.value = ${JSON.stringify(f.adminPasswort ?? 'test-admin')}; p.form.submit(); } })()`);
        await schlafe(1400);
        const a = await b.js(zustand);
        ok('Im Admin steht dieselbe Leiste', a.offen && a.fest && a.links === 0, JSON.stringify(a));
        const gruppen = await b.js(`[...document.querySelectorAll('#menuLinks .mueber')].map((p) => p.textContent)`);
        ok('Gegliedert nach Schulen, Qualität und Betrieb',
           gruppen.join('|') === 'Schulen|Qualität|Betrieb', gruppen.join('|'));
        ok('Jede Schule steht darin',
           await b.js(`[...document.querySelectorAll('#menuLinks a.mitem')].some((a) => a.textContent.includes('BROWSERTEST-Schule'))`));
        await b.bild('seitenleiste-admin');

        // Am Telefon: eine Schublade, die den Inhalt frei lässt.
        await b.groesse(390, 844);
        await schlafe(400);
        const h = await b.js(zustand);
        ok('Am Telefon ist es wieder eine Schublade', !h.offen && !h.fest && h.inhalt < 40, JSON.stringify(h));
    } finally {
        b.schliessen();
    }
}
