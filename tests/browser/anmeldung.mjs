/*
 * Wo man nach der Anmeldung steht.
 *
 * Es gibt zwei Anmeldungen: die des Lehrkraft-Bereichs (ein Formular, das
 * der Server baut) und die der App (eine Ansicht der PWA). Die zweite
 * kannte nur ein Ziel - die Kachelansicht. Eine Lehrkraft landete damit in
 * der Ansicht ihrer Klasse.
 *
 * Dass es jetzt anders ist, haengt an zwei Stellen zugleich: api/auth.php
 * liefert das Ziel, views/login.js folgt ihm. Beide fuer sich lassen sich
 * in PHP pruefen - dass sie zusammen greifen, nur hier.
 */

import { browser, ok, abschnitt, schlafe } from './browser.mjs';

export async function pruefe(f, aus) {
    abschnitt('Nach der Anmeldung');

    const b = await browser({ port: 9408, breite: 1200, hoehe: 1000, aus });
    try {
        await b.geh(f.basis + '/', 1500);

        const vorher = await b.js(`({
            ort:      location.pathname + location.hash,
            formular: !!document.getElementById('form'),
            titel:    document.querySelector('h1')?.textContent ?? '',
        })`);
        ok('Ohne Anmeldung zeigt die App die Anmeldung',
           vorher.formular && vorher.ort.includes('/login'), JSON.stringify(vorher));

        await b.js(`(() => {
            document.getElementById('username').value = ${JSON.stringify(f.lehrer)};
            document.getElementById('password').value = ${JSON.stringify(f.passwort)};
            document.getElementById('form')
                .dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        })()`);
        await schlafe(3000);

        const danach = await b.js(`({
            ort:    location.pathname,
            titel:  document.querySelector('h1')?.textContent ?? '',
            karten: document.querySelectorAll('.kurskarte').length,
        })`);

        ok('Die Lehrkraft landet in der Verwaltung',
           danach.ort.includes('/teacher/'), danach.ort);
        ok('Und zwar bei ihren Kursen', danach.titel === 'Meine Kurse', danach.titel);
        ok('Die Karten stehen da', danach.karten >= 1, String(danach.karten));

        await b.bild('anmeldung-lehrkraft');

        /*
         * Die Kinderansicht bleibt ihr offen - sie ist der Weg zu "So sieht
         * es die Klasse". Weitergeleitet wird nach der Anmeldung, nicht bei
         * jedem Aufruf der App.
         */
        await b.geh(f.basis + '/#/lang/' + f.sprache, 2000);
        const probe = await b.js(`({
            ort:   location.pathname + location.hash,
            titel: document.querySelector('.topbar h1')?.textContent ?? '',
        })`);
        ok('Die Ansicht der Klasse bleibt erreichbar',
           probe.ort.includes('/lang/' + f.sprache), probe.ort);
        ok('Und zeigt den Kurs', probe.titel !== '', probe.titel);
    } finally {
        b.schliessen();
    }
}
