/*
 * "Deine Geräte" unter "Mein Konto" - in der App und im Lehrkraft-Bereich.
 *
 * Als Symbol vom Home-Bildschirm lässt sich hier nichts starten. Die Meldung,
 * die die App dann schickt (installieren.js), schickt die Prüfung deshalb
 * selbst - mit derselben Anfrage. Was der Server daraus macht, prüft
 * e2e.php; hier: dass die Karte steht, der Mülleimer nachfragt und das
 * Gerät danach ohne Neuladen verschwindet.
 */

import { browser, alsKind, alsLehrkraft, ok, abschnitt, schlafe } from './browser.mjs';

const melden = (basis) => `fetch('${basis}/api/profile.php?action=installiert', {
    method: 'POST',
    headers: { 'X-Vokabeltrainer': '1', 'Content-Type': 'application/json' },
    body: JSON.stringify({ beruehrbar: false }),
    credentials: 'same-origin',
}).then((r) => r.status)`;

export async function pruefe(f, aus) {
    abschnitt('Deine Geräte');

    const b = await browser({ port: 9487, breite: 420, hoehe: 900, aus });
    try {
        await alsKind(b, f.basis, f.kind, f.passwort, f.kuerzel);
        ok('Die Meldung kommt an', await b.js(melden(f.basis)) === 200);

        await b.geh(f.basis + '/#/konto', 1500);
        const karte = await b.js(`({
            titel: [...document.querySelectorAll('h2.section')].map((h) => h.textContent),
            geraete: document.querySelectorAll('#geraete [data-geraet]').length,
            dieses: document.querySelector('#geraete .geraetdieses')?.textContent ?? '',
            zeichen: getComputedStyle(document.querySelector('#geraete .geraetzeichen')).maskImage,
            text: document.querySelector('#geraete .geraettext .muted')?.textContent ?? '',
        })`);
        ok('Unten steht "Deine Geräte"', karte.titel.at(-1) === 'Deine Geräte', karte.titel.join(' | '));
        ok('Mit diesem Gerät darin', karte.geraete >= 1 && karte.dieses === 'dieses Gerät');
        ok('Mit dem Zeichen des Systems', karte.zeichen.includes('/assets/geraete/'), karte.zeichen);
        ok('Und wann es angelegt und zuletzt benutzt wurde',
           /angelegt heute, \d\d:\d\d · zuletzt benutzt heute/.test(karte.text), karte.text);
        await b.bild('geraete-app');

        // Der Mülleimer fragt - erst Abbrechen, dann Bestätigen.
        const vorher = karte.geraete;
        await b.js(`(() => {
            window.__fragen = [];
            window.confirm = (t) => { window.__fragen.push(t); return window.__fragen.length > 1; };
            document.querySelector('#geraete .geraetweg').click();
        })()`);
        await schlafe(600);
        ok('Abbrechen lässt das Gerät stehen',
           await b.js(`document.querySelectorAll('#geraete [data-geraet]').length`) === vorher);
        await b.js(`document.querySelector('#geraete .geraetweg').click()`);
        await schlafe(1200);
        const frage = await b.js(`window.__fragen[0] ?? ''`);
        ok('Die Rückfrage erklärt, dass das Symbol nicht mehr geht',
           frage.includes('funktioniert danach nicht mehr') && frage.includes('jederzeit wieder anlegen'), frage);
        ok('Bestätigt verschwindet das Gerät ohne Neuladen',
           await b.js(`document.querySelectorAll('#geraete [data-geraet]').length`) === vorher - 1);

        // Der Lehrkraft-Bereich: dieselbe Karte, als Formular.
        await alsLehrkraft(b, f.basis, f.lehrer, f.passwort, f.kuerzel);
        await b.groesse(1200, 1000);
        ok('Auch die Verwaltung meldet', await b.js(melden(f.basis)) === 200);
        await b.geh(f.basis + '/teacher/konto.php#geraete', 1200);
        const lk = await b.js(`({
            da: !!document.querySelector('#geraete + .card [data-geraet]'),
            frage: document.querySelector('.geraetweg[data-confirm]')?.dataset.confirm ?? '',
        })`);
        ok('Unter "Mein Konto" der Lehrkraft stehen die Geräte', lk.da);
        ok('Und der Mülleimer fragt vorher', lk.frage.includes('funktioniert danach nicht mehr'), lk.frage);
        await b.bild('geraete-lehrkraft');
    } finally {
        b.schliessen();
    }
}
