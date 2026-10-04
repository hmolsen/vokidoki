/*
 * Die Verwaltungssitzung auf der Seite "Lehrkräfte" - das gelbe Band.
 *
 * Was der Server liefert, prüft e2e.php. Hier das, was erst im Browser
 * geschieht: Die Uhr zählt herunter, und läuft sie ab, verschwindet das Band
 * - und wer gerade auf der Lehrkräfte-Seite steht, landet auf der
 * Startseite, statt vor einer Liste zu sitzen, deren Knöpfe nichts mehr tun.
 */

import { browser, alsLehrkraft, ok, abschnitt, schlafe } from './browser.mjs';

export async function pruefe(f, aus) {
    abschnitt('Lehrkräfte: die Verwaltungssitzung');

    const b = await browser({ port: 9486, breite: 1200, hoehe: 900, aus });
    try {
        await alsLehrkraft(b, f.basis, f.lehrer, f.passwort, f.kuerzel);

        const menue = await b.js(`(() => {
            const a = document.querySelector('a[href$="lehrkraefte.php"]');
            return { da: !!a, strich: a?.previousElementSibling?.matches('hr.mtrenner') ?? false };
        })()`);
        ok('Im Menü steht "Lehrkräfte"', menue.da);
        ok('Unter einem eigenen Strich', menue.strich);

        const erhoehen = async () => {
            await b.geh(f.basis + '/teacher/lehrkraefte.php', 900);
            // Nur mit Passwortfeld: Vorgestellt ist bloss die Uhr der Seite,
            // auf dem Server kann die Sitzung noch laufen.
            await b.js(`(() => {
                const feld = document.querySelector('input[name=password]');
                if (!feld) return;
                feld.value = ${JSON.stringify(f.passwort)};
                document.querySelector('button[name=erhoehen]').click();
            })()`);
            await schlafe(1300);
        };
        await erhoehen();

        const uhr = () => b.js(`document.querySelector('#erhoeht .erhoeht-uhr')?.textContent ?? ''`);
        const erste = await uhr();
        await schlafe(2200);
        const zweite = await uhr();
        ok('Das gelbe Band zeigt die Zeit als m:ss', /^[45]:\d\d$/.test(erste), erste);
        ok('Und zählt herunter', zweite !== erste && /^4:\d\d$/.test(zweite), `${erste} -> ${zweite}`);
        ok('Die Liste der Lehrkräfte steht da', await b.js(`!!document.getElementById('lehrkraefte')`));
        const farbe = await b.js(`getComputedStyle(document.getElementById('erhoeht')).backgroundColor`);
        ok('Das Band ist gelb', farbe === 'rgb(255, 244, 194)', farbe);
        await b.bild('lehrkraefte');

        // Beenden: Band weg, und von der Lehrkräfte-Seite nach Hause.
        await b.js(`document.querySelector('button[name=erhoeht_beenden]').click()`);
        await schlafe(1300);
        const nachBeenden = await b.js(`({ wo: location.pathname, band: !!document.getElementById('erhoeht') })`);
        ok('Beenden nimmt das Band weg', !nachBeenden.band);
        ok('Und führt von der Lehrkräfte-Seite nach Hause',
           nachBeenden.wo.endsWith('/teacher/index.php') || nachBeenden.wo.endsWith('/teacher/'), nachBeenden.wo);

        // Ablaufen - ohne fünf Minuten zu warten: die Uhr der Seite vorstellen.
        await erhoehen();
        await b.js(`(() => { const echt = Date.now; Date.now = () => echt() + 600000; })()`);
        await schlafe(2500);
        const nachAblauf = await b.js(`({ wo: location.pathname })`);
        ok('Abgelaufen geht die Lehrkräfte-Seite nach Hause',
           nachAblauf.wo.endsWith('/teacher/index.php'), nachAblauf.wo);

        // Auf jeder anderen Seite bleibt man stehen, wo man ist.
        await erhoehen();
        await b.geh(f.basis + '/teacher/konto.php', 900);
        await b.js(`(() => { const echt = Date.now; Date.now = () => echt() + 600000; })()`);
        await schlafe(2500);
        const anderswo = await b.js(`({ wo: location.pathname, band: !!document.getElementById('erhoeht') })`);
        ok('Abgelaufen verschwindet das Band - anderswo bleibt man stehen', !anderswo.band && anderswo.wo.endsWith('/teacher/konto.php'),
           anderswo.wo);
    } finally {
        b.schliessen();
    }
}
