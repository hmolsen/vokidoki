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
         * fest. Der Weg steht jetzt im Zahnrad, als Schalter mit zwei
         * Stellungen, und er zeigt auf die Entsprechung DIESER Seite.
         */
        const streifen = await b.js(`(() => {
            const app = document.getElementById('app');
            const k = app.firstElementChild;
            const ist = k?.classList.contains('lernansicht') ?? false;
            const r = ist ? k.getBoundingClientRect() : null;
            return { da: ist, text: (k?.textContent ?? '').trim(),
                     hoehe: r ? Math.round(r.height) : null,
                     oben: r ? Math.round(r.top) : null,
                     kasten: !!document.querySelector('.notice.pupilview') };
        })()`);
        ok('Ein Streifen ganz oben sagt der Lehrkraft, wo sie ist', streifen.da);
        ok('Er heisst „Lernansicht"', streifen.text === 'Lernansicht', streifen.text);
        ok('Er steht wirklich ganz oben und ist schmal',
           streifen.oben === 0 && streifen.hoehe > 12 && streifen.hoehe < 34,
           streifen.oben + ' px von oben, ' + streifen.hoehe + ' px hoch');
        ok('Und der alte Hinweiskasten ist weg', !streifen.kasten,
           'zwei Erklärungen für eine Sache sind eine zu viel');

        const schalter = await b.js(`(async () => {
            const m = document.getElementById('menuRechts');
            m.querySelector('summary').click();
            await new Promise((r) => setTimeout(r, 350));
            const w = m.querySelector('.ansichtwahl');
            const hin = w?.querySelector('a.ansichtknopf');
            const hier = w?.querySelector('.ansichtknopf.on');
            return {
                da: !!w,
                ziel: hin?.getAttribute('href') ?? '',
                hin: (hin?.textContent ?? '').replace(/\\s+/g, ' ').trim(),
                hier: (hier?.textContent ?? '').replace(/\\s+/g, ' ').trim(),
            };
        })()`);
        ok('Im Zahnrad steht der Schalter für die Ansicht', schalter.da);
        ok('Er zeigt, dass man in der Lernansicht steht',
           schalter.hier.includes('Lernansicht'), schalter.hier);
        ok('Und führt in die Verwaltung', schalter.hin.includes('Verwaltung'), schalter.hin);
        ok('Und zwar auf genau diesen Kurs',
           schalter.ziel.includes('/teacher/course.php?id=' + f.kurs), schalter.ziel);

        await b.js(`document.querySelector('.ansichtwahl a.ansichtknopf').click()`);
        await schlafe(1800);
        const zurueck = await b.js(`({
            ort:   location.pathname + location.search,
            titel: document.querySelector('h1')?.textContent ?? '',
        })`);
        ok('Und ein Druck darauf führt wirklich dorthin',
           zurueck.ort.includes('course.php?id=' + f.kurs), zurueck.ort);
        ok('Nämlich auf die Kursseite', zurueck.titel !== '', zurueck.titel);

        /*
         * Und dort steht der Schalter wieder, nur andersherum gestellt -
         * dieselbe Stelle, dieselbe Bauart, die andere Stellung aktiv.
         */
        const drueben = await b.js(`(async () => {
            const m = document.getElementById('menuRechts');
            m.querySelector('summary').click();
            await new Promise((r) => setTimeout(r, 350));
            const w = m.querySelector('.ansichtwahl');
            return {
                hier: (w?.querySelector('.ansichtknopf.on')?.textContent ?? '')
                        .replace(/\\s+/g, ' ').trim(),
                ziel: w?.querySelector('a.ansichtknopf')?.getAttribute('href') ?? '',
            };
        })()`);
        ok('In der Verwaltung steht derselbe Schalter',
           drueben.hier.includes('Verwaltung'), drueben.hier);
        ok('Und er führt zurück auf genau diesen Kurs',
           drueben.ziel.includes('#/lang/' + f.sprache), drueben.ziel);
        ok('Der alte Knopf neben der Überschrift ist weg',
           (await b.js(`!document.body.textContent.includes('So sieht es die Klasse')`)) === true);

        /*
         * Und sonst steht dort nichts, was ein Kind nicht auch sieht.
         *
         * "Vokabeln einlesen" hing an canImport, und das hat eine Lehrkraft.
         * Die Probe zeigte ihr damit eine Seite, die es so gar nicht gibt -
         * und der Knopf fuehrte ausgerechnet dorthin, wo sie ohnehin ueber
         * ihren Bereich hinkommt.
         */
        await b.geh(f.basis + '/#/lang/' + f.sprache, 2200);
        const sichtbar = await b.js(`(() => {
            const text = document.getElementById('app')?.textContent ?? '';
            return {
                einlesen: text.includes('Vokabeln einlesen'),
                banner:   !!document.querySelector('.lernansicht'),
                zeilen:   [...document.querySelectorAll('[data-go]')]
                            .map((e) => e.dataset.go),
            };
        })()`);
        ok('Kein Einlesen in der Lernansicht', !sichtbar.einlesen,
           'sie soll genau so aussehen, wie ein Kind sie hat');
        ok('Der Streifen bleibt als einziger Unterschied', sichtbar.banner);
        /*
         * Übrig bleiben darf nur, was ein Kind auch hat. Freies Üben und
         * Aus Fehlern lernen sind für alle da - das Einlesen nicht, und genau
         * darum ging es hier.
         */
        ok('Und es steht keine Zeile mehr da, die ein Kind nicht hat',
           sichtbar.zeilen.every((z) => z.startsWith('/frei/') || z.startsWith('/fehler/')),
           sichtbar.zeilen.join(', ') || '(keine)');

        // In einer Lerneinheit zeigt er auf die Lerneinheit, nicht auf den Kurs.
        await b.geh(f.basis + '/#/unit/' + f.unit, 2200);
        const inEinheit = await b.js(`(() => {
            const text = document.getElementById('app')?.textContent ?? '';
            return {
                hinweis:    !!document.querySelector('.lernansicht'),
                umbenennen: !!document.getElementById('rename'),
                loeschen:   !!document.getElementById('delete'),
                ruecksetzen: !!document.getElementById('reset'),
                verwalten:  text.includes('Verwalten'),
            };
        })()`);
        ok('Auch die Lerneinheit trägt den Streifen', inEinheit.hinweis);
        const zielEinheit = await b.js(`(async () => {
            const m = document.getElementById('menuRechts');
            m.querySelector('summary').click();
            await new Promise((r) => setTimeout(r, 350));
            return m.querySelector('.ansichtwahl a.ansichtknopf')
                    ?.getAttribute('href') ?? '';
        })()`);
        ok('Und der Schalter zeigt auf genau diese Lerneinheit',
           zielEinheit.includes('/teacher/unit.php?id=' + f.unit),
           'nicht auf die Startseite - man war ja irgendwo');
        await b.js(`document.getElementById('menuRechts').querySelector('summary').click()`);
        await schlafe(350);
        ok('Umbenennen steht dort nicht mehr', !inEinheit.umbenennen,
           'das gehört in den Lehrkraft-Bereich, auf dieselbe Lerneinheit');
        ok('Löschen auch nicht', !inEinheit.loeschen);
        ok('Das Zurücksetzen bleibt', inEinheit.ruecksetzen,
           'der Lernstand gehört dem Konto, das ihn erarbeitet hat');

        // Und dort, wo sie hingehören, sind sie.
        await b.geh(f.basis + '/teacher/unit.php?id=' + f.unit, 1400);
        const dort = await b.js(`({
            umbenennen: !!document.querySelector('[name="rename_unit"]'),
            loeschen:   !!document.querySelector('[name="delete_unit"]'),
            zu:         document.querySelector('details.card')?.open === false,
        })`);
        ok('Im Lehrkraft-Bereich lässt sie sich umbenennen', dort.umbenennen);
        ok('Und löschen', dort.loeschen);
        ok('Das Löschen liegt zugeklappt', dort.zu,
           'nichts, worüber man stolpert');
    } finally {
        b.schliessen();
    }
}
