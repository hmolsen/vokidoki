/*
 * Die beiden Menüs in der Kinderansicht - und die Farbwahl darin.
 *
 * Sie standen nur im Lehrkraft-Bereich. Ein Kind musste zum Kurswechseln
 * zurück, zurück, antippen, und die Einstellungen lagen hinter einem
 * Zahnrad, das es nur auf der Startseite gab.
 *
 * Was hier geprüft wird, kann keine PHP-Suite sehen: Die Leiste entsteht in
 * JavaScript, die Schublade fliegt herein, und ob „Dunkel" wirklich dunkel
 * ist, sagt erst der gerechnete Stil.
 */

import { browser, alsKind, alsLehrkraft, ok, abschnitt, schlafe } from './browser.mjs';

export async function pruefe(f, aus) {
    abschnitt('Menüs in der Kinderansicht');

    const b = await browser({ port: 9414, breite: 420, hoehe: 900, aus });
    try {
        await alsKind(b, f.basis, f.kind, f.passwort);
        await b.geh(f.basis + '/', 2000);

        const start = await b.js(`({
            burger:  !!document.querySelector('.topbar #menuLinks'),
            zahnrad: !!document.querySelector('.topbar #menuRechts'),
            kurse:   [...document.querySelectorAll('#menuLinks .mgruppe .mitem')]
                       .map((a) => a.textContent.trim()),
            haupt:   (document.querySelector('#menuLinks .mitem.haupt')?.textContent ?? '')
                       .replace(/\\s+/g, ' ').trim(),
            rechts:  [...document.querySelectorAll('#menuRechts .mitem')]
                       .map((a) => a.textContent.replace(/\\s+/g, ' ').trim()),
            verwaltung: !!document.querySelector('#menuLinks [href$="/teacher/"]'),
        })`);

        ok('Die Leiste trägt beide Schubladen', start.burger && start.zahnrad);
        ok('Links steht „Meine Kurse"', start.haupt.endsWith('Meine Kurse'), start.haupt);
        ok('Und darunter die eigenen Kurse', start.kurse.length > 0,
           start.kurse.join(', '));
        ok('Rechts Profil, Passwort und Abmelden',
           start.rechts.some((t) => t.includes('Profil'))
           && start.rechts.some((t) => t.includes('Passwort'))
           && start.rechts.some((t) => t.includes('Abmelden')),
           start.rechts.join(' | '));
        ok('Einem Kind wird kein Weg in die Verwaltung angeboten',
           start.verwaltung === false,
           'es käme ohnehin nicht hinein, und die Zeile sagte ihm nichts');

        // ---- Und in jeder Ansicht, nicht nur auf der Startseite.

        await b.geh(f.basis + '/#/quiz/' + f.unit, 1800);
        const beimUeben = await b.js(`({
            burger:  !!document.querySelector('#menuLinks'),
            zahnrad: !!document.querySelector('#menuRechts'),
            zurueck: !!document.querySelector('.topbar [data-back]'),
            markiert: document.querySelector('#menuLinks .mitem.on')?.textContent.trim() ?? '',
        })`);

        ok('Mitten im Üben stehen sie auch da',
           beimUeben.burger && beimUeben.zahnrad,
           'dort will man die Farben umstellen, nicht auf der Startseite');
        ok('Der Zurück-Knopf bleibt daneben', beimUeben.zurueck,
           'die Schublade ersetzt ihn nicht - sie springt, er geht eine Ebene hoch');
        /*
         * Und der Kurs, in dem man steckt, steht markiert - auch hier, wo
         * die Adresse nur die Lerneinheit nennt. Ein Menü, das das vergisst,
         * markiert ausgerechnet dort nichts, wo man am tiefsten drin ist.
         */
        ok('Der offene Kurs ist im Menü markiert', beimUeben.markiert !== '',
           'die Adresse nennt hier nur die Lerneinheit');

        /*
         * Das Abzeichen erneuert sich mitten im Ueben an Ort und Stelle -
         * die Leiste wird dabei nicht neu gezeichnet. Wurden die Menues
         * daneben dadurch ein zweites Mal verdrahtet, hingen an ihrem Knopf
         * zwei Hoerer: aufklappen und sofort wieder zu. Nach ein paar
         * richtigen Antworten ging das Menue gar nicht mehr auf.
         */
        await b.js(`(async () => {
            const c = await import('${f.basis}/core.js');
            for (let i = 0; i < 5; i++) c.serieAktualisieren(false);
        })()`);
        await schlafe(400);
        await b.js(`document.querySelector('#menuLinks > summary').click()`);
        await schlafe(500);

        ok('Das Menue geht auch nach mehreren Erneuerungen des Abzeichens auf',
           (await b.js(`document.querySelector('#menuLinks').open`)) === true,
           'zwei Hoerer an einem Knopf klappen sofort wieder zu');
        ok('Und es steht genau ein Abzeichen in der Leiste',
           (await b.js(`document.querySelectorAll('.seriebtn').length`)) === 1);
        await b.js(`document.querySelector('#menuLinks .schleier')?.click()`);
        await schlafe(400);

        /*
         * Die grosse Feier, wenn der Tag geschafft ist: Voki tanzt ueber der
         * verschwommenen Seite, und die Zahl zaehlt von der alten auf die
         * neue. Ohne Anlass (feier = false, bei jeder Antwort) bleibt sie weg.
         */
        ok('Ohne geschafften Tag keine grosse Feier',
           (await b.js(`!!document.getElementById('seriefeier')`)) === false);
        const feier = await b.js(`(async () => {
            const c = await import('${f.basis}/core.js');
            const vorher = parseInt(document.querySelector('.seriezahl').textContent, 10);
            c.serieAktualisieren(true);
            const el = document.getElementById('seriefeier');
            const band = [...(el?.querySelectorAll('.seriefeier-band > span') ?? [])].map((s) => s.textContent);
            const voki = el?.querySelector('.seriefeier-voki');
            return {
                da:     !!el,
                vorher,
                band,
                blur:   el ? getComputedStyle(el).backdropFilter : '',
                tanz:   voki ? getComputedStyle(voki).animationName : '',
                bild:   voki?.getAttribute('src') ?? '',
                text:   el?.textContent.replace(/\\s+/g, ' ').trim() ?? '',
            };
        })()`);
        ok('Ist der Tag geschafft, liegt die Feier ueber der Seite', feier.da);
        ok('Die Seite dahinter ist verschwommen', feier.blur.includes('blur'), feier.blur);
        ok('Voki tanzt - der kleine, ohne weissen Grund', feier.tanz === 'vokiTanz'
           && feier.bild.endsWith('voki-mini.svg'), feier.tanz + ' / ' + feier.bild);
        ok('Die Zahl zaehlt von der bisherigen weiter',
           feier.band.length >= 2 && Number(feier.band[feier.band.length - 1]) > Number(feier.band[0]),
           JSON.stringify(feier));
        ok('Und sagt, was gefeiert wird', feier.text.includes('Serie verlängert'), feier.text);
        await b.js(`document.getElementById('seriefeier').click()`);
        await schlafe(500);
        ok('Ein Druck schliesst sie',
           (await b.js(`!!document.getElementById('seriefeier')`)) === false);


        // ---- Die Schublade fliegt herein.

        const fliegt = await b.js(`(() => {
            const d = document.getElementById('menuLinks');
            d.querySelector('summary').click();
            const an = d.querySelector('.schublade').getAnimations();
            return { name: an[0]?.animationName ?? '', laeuft: an[0]?.playState ?? '' };
        })()`);
        ok('Sie fliegt herein, statt dazustehen',
           fliegt.name === 'schubladeLinks' && fliegt.laeuft === 'running',
           fliegt.name + ' / ' + fliegt.laeuft);

        await schlafe(500);

        // ---- Kurswechsel aus der Schublade heraus.

        await b.js(`document.querySelector('#menuLinks .mgruppe .mitem').click()`);
        await schlafe(1500);
        ok('Ein Druck darin wechselt den Kurs',
           (await b.js(`location.hash`)).includes('/lang/'),
           await b.js(`location.hash`));

        // ---- Hell, dunkel, automatisch.

        await b.js(`document.querySelector('#menuRechts summary').click()`);
        await schlafe(500);

        const wahl = await b.js(`({
            knoepfe: [...document.querySelectorAll('[data-thema]')].map((k) => k.dataset.thema),
            an: document.querySelector('[data-thema].on')?.dataset.thema ?? '',
        })`);
        ok('Drei Knöpfe für die Farben', wahl.knoepfe.join(',') === 'hell,dunkel,auto',
           wahl.knoepfe.join(','));
        ok('Und „Automatisch" ist die Voreinstellung', wahl.an === 'auto', wahl.an);

        const hellFarbe = await b.js(`getComputedStyle(document.body).backgroundColor`);

        await b.js(`document.querySelector('[data-thema="dunkel"]').click()`);
        await schlafe(600);
        const dunkel = await b.js(`({
            attribut: document.documentElement.dataset.theme ?? '',
            gemerkt:  localStorage.getItem('vt-thema'),
            bg:       getComputedStyle(document.body).backgroundColor,
            an:       document.querySelector('[data-thema].on')?.dataset.thema ?? '',
        })`);

        ok('„Dunkel" schaltet sofort um', dunkel.attribut === 'dark'
           && dunkel.bg !== hellFarbe, dunkel.bg + ' gegen ' + hellFarbe);
        ok('Und wird gemerkt', dunkel.gemerkt === 'dunkel', String(dunkel.gemerkt));
        ok('Der Knopf zeigt, was gilt', dunkel.an === 'dunkel', dunkel.an);

        if (aus) await b.bild('app-menue-dunkel');

        /*
         * Und nach dem Neuladen ist es immer noch dunkel - ohne Aufblitzen.
         * Das Kopfskript im <head> setzt es, bevor das erste Bild steht; ein
         * Modul liefe erst danach, und dann sähe man eine halbe Sekunde die
         * helle Seite.
         */
        await b.neuLaden(2000);
        const nachher = await b.js(`({
            attribut: document.documentElement.dataset.theme ?? '',
            bg:       getComputedStyle(document.body).backgroundColor,
        })`);
        ok('Nach dem Neuladen ist es immer noch dunkel',
           nachher.attribut === 'dark' && nachher.bg === dunkel.bg,
           nachher.attribut + ' / ' + nachher.bg);

        // Wieder aufräumen, damit die Bildschirmfotos anderer Abschnitte
        // nicht plötzlich dunkel sind.
        await b.js(`localStorage.removeItem('vt-thema')`);
    } finally {
        b.schliessen();
    }

    // ---- Und dieselbe Wahl im Lehrkraft-Bereich.

    const t = await browser({ port: 9415, breite: 900, hoehe: 900, aus });
    try {
        await alsLehrkraft(t, f.basis, f.lehrer, f.passwort);
        await t.geh(f.basis + '/teacher/', 1500);

        await t.js(`document.querySelector('#menuRechts summary').click()`);
        await schlafe(500);
        ok('Auch die Lehrkraft findet die Farben im Einstellungsmenü',
           (await t.js(`document.querySelectorAll('#menuRechts [data-thema]').length`)) === 3);

        await t.js(`document.querySelector('[data-thema="dunkel"]').click()`);
        await schlafe(600);
        ok('Und sie wirkt dort genauso',
           (await t.js(`document.documentElement.dataset.theme`)) === 'dark');

        if (aus) await t.bild('teacher-menue-dunkel');
        await t.js(`localStorage.removeItem('vt-thema')`);
    } finally {
        t.schliessen();
    }
}
