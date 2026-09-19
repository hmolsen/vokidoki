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

        /*
         * Und von dort wieder heraus.
         *
         * Der Hinweis sagte, wo man ist, aber nicht, wie man zurueckkommt.
         * Die installierte App hat keine Adresszeile, und ihr Zurueck fuehrt
         * tiefer hinein statt heraus - wer nur ausprobieren wollte, sass
         * fest. Der Knopf zeigt auf genau die Stelle, an der man war.
         */
        const hinweis = await b.js(`(() => {
            const k = document.querySelector('.notice.pupilview');
            const a = k?.querySelector('a.btn');
            return {
                da:   !!k,
                text: a?.textContent?.trim().replace(/\s+/g, ' ') ?? '',
                ziel: a?.getAttribute('href') ?? '',
            };
        })()`);

        ok('Die Schüleransicht sagt der Lehrkraft, was sie da sieht', hinweis.da);
        ok('Und trägt einen Weg zurück in die Verwaltung',
           hinweis.text.includes('Zurück zur Verwaltung'), hinweis.text);
        ok('Der auf genau diesen Kurs zeigt',
           hinweis.ziel.includes('/teacher/course.php?id=' + f.kurs), hinweis.ziel);

        await b.js(`document.querySelector('.notice.pupilview a.btn').click()`);
        await schlafe(1800);
        const zurueck = await b.js(`({
            ort:   location.pathname + location.search,
            titel: document.querySelector('h1')?.textContent ?? '',
        })`);
        ok('Und ein Druck darauf führt wirklich dorthin',
           zurueck.ort.includes('course.php?id=' + f.kurs), zurueck.ort);
        ok('Nämlich auf die Kursseite', zurueck.titel !== '', zurueck.titel);

        // In einer Lerneinheit zeigt er auf die Lerneinheit, nicht auf den Kurs.
        await b.geh(f.basis + '/#/unit/' + f.unit, 2200);
        ok('In der Lerneinheit zeigt er auf die Lerneinheit',
           (await b.js(`document.querySelector('.notice.pupilview a.btn')
                          ?.getAttribute('href') ?? ''`))
               .includes('/teacher/unit.php?id=' + f.unit),
           'nicht auf die Startseite - man war ja irgendwo');
    } finally {
        b.schliessen();
    }
}
