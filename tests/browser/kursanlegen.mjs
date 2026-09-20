/*
 * Einen Kurs anlegen - der ganze Weg, geklickt.
 *
 * Zwei Fragen, zwei Seiten: für welche Klasse, für welche Sprache. Dass
 * das zusammen funktioniert, sagt kein Quelltext - hier hängt es an
 * Kacheln, die Links sind, an Kacheln, die Absendeknöpfe sind, und an
 * einer Weiterleitung am Ende. Geprüft wird deshalb der Weg selbst: von
 * der Startseite bis zur fertigen Kursseite, ohne eine Adresse von Hand
 * einzutippen.
 *
 * Und der Rückweg gleich mit. „Von der Kursansicht erreicht man alles
 * andere, und von allem anderen kommt man direkt wieder zurück" ist ein
 * Satz über Wege, nicht über Markup.
 *
 * Läuft als letzte Prüfung: Sie legt einen Kurs an, und die Navigation
 * zählt vorher Karten.
 */

import { browser, alsLehrkraft, ok, abschnitt, schlafe } from './browser.mjs';

export async function pruefe(f, aus) {
    abschnitt('Einen Kurs anlegen');

    const b = await browser({ port: 9409, breite: 1200, hoehe: 1100, aus });
    try {
        await alsLehrkraft(b, f.basis, f.lehrer, f.passwort);

        // ---- Von der Startseite in den Assistenten.

        const start = await b.js(`(() => {
            const k = [...document.querySelectorAll('.kurskarten > *')].pop();
            return {
                ort:    location.pathname,
                letzte: k?.textContent?.trim().replace(/\\s+/g, ' ') ?? '',
                ziel:   k?.getAttribute('href') ?? '',
            };
        })()`);

        ok('Die letzte Kachel der Startseite führt zu einem neuen Kurs',
           start.letzte.includes('Neuer Kurs'), start.letzte);
        ok('Und zwar in den Assistenten', start.ziel.includes('neu.php'), start.ziel);

        await b.geh(f.basis + start.ziel.replace(/^.*\/teacher\//, '/teacher/'), 1300);

        // ---- Schritt 1: Für welche Klasse?

        const eins = await b.js(`(() => {
            const kacheln = [...document.querySelectorAll('.wahlkarte')];
            return {
                titel:   document.querySelector('h1')?.textContent ?? '',
                schritt: document.querySelector('.schritt')?.textContent?.trim() ?? '',
                anzahl:  kacheln.length,
                ohne:    kacheln.some((k) => k.textContent.includes('Kurs ohne Klasse')),
                neue:    !!document.querySelector('[name="neue_klasse"]'),
                sprache: !!document.querySelector('select[data-picker]'),
                ziel:    kacheln.find((k) => (k.getAttribute('href') ?? '')
                             .includes('klasse=${f.leereKlasse}'))?.getAttribute('href') ?? '',
            };
        })()`);

        ok('Der Assistent fragt zuerst nach der Klasse',
           eins.titel === 'Für welche Klasse?', eins.titel);
        ok('Und sagt, wo man steht', eins.schritt.startsWith('Schritt 1 von 2'), eins.schritt);
        ok('Die Klassen stehen als Kacheln da', eins.anzahl >= 2, String(eins.anzahl));
        ok('„Kurs ohne Klasse" ist eine davon', eins.ohne,
           'nicht als Kleingedrucktes darunter - beides ist ein gültiger Anfang');
        ok('Eine Klasse lässt sich hier anlegen', eins.neue,
           'sonst ist der erste Schritt für eine neue Lehrkraft eine Sackgasse');
        ok('Nach der Sprache wird hier noch nicht gefragt', !eins.sprache,
           'eine Frage je Seite');
        ok('Die leere Klasse ist anklickbar', eins.ziel !== '', eins.ziel);

        await b.geh(f.basis + eins.ziel.replace(/^.*\/teacher\//, '/teacher/'), 1300);

        // ---- Schritt 2: Für welche Sprache?

        const zwei = await b.js(`(() => {
            const kacheln = [...document.querySelectorAll('button.wahlkarte')];
            return {
                titel:   document.querySelector('h1')?.textContent ?? '',
                schritt: document.querySelector('.schritt')?.textContent?.trim() ?? '',
                namen:   kacheln.map((k) => k.value),
                ueber:   document.querySelector('h1')?.textContent?.trim() ?? '',
                frei:    !!document.querySelector('select[data-picker]'),
            };
        })()`);

        ok('Schritt 2 fragt nach der Sprache',
           zwei.titel === 'Für welche Sprache?', zwei.titel);
        ok('Und sagt, wo man steht', zwei.schritt.startsWith('Schritt 2 von 2'), zwei.schritt);
        ok('Die fünf Schulsprachen stehen als Kacheln da',
           zwei.namen.length === 5, zwei.namen.join(', '));
        ok('Latein ist eine davon', zwei.namen.includes('Latein'), zwei.namen.join(', '));
        ok('Die gewählte Klasse steht auf den Kacheln',
           zwei.namen.length === 5 && zwei.ueber === 'Für welche Sprache?',
           zwei.ueber);
        ok('Und alle übrigen Sprachen stehen darunter', zwei.frei);

        await b.bild('kurs-schritt2');

        /*
         * Latein, nicht Englisch: Die Fixtureklasse hat schon einen
         * Englischkurs, und zwei Kurse desselben Namens gibt es an einer
         * Schule nicht. Der Assistent sagte das auch - aber hier soll der
         * Regelfall geprüft werden, nicht der Fehlerfall.
         */
        await b.js(`[...document.querySelectorAll('button.wahlkarte')]
                      .find((k) => k.value === 'Latein').click()`);
        await schlafe(2000);

        // ---- Und der Kurs steht.

        const kurs = await b.js(`({
            ort:     location.pathname + location.search,
            titel:   document.querySelector('h1')?.textContent ?? '',
            meldung: document.querySelector('.notice')?.textContent?.trim()
                        .replace(/\\s+/g, ' ') ?? '',
            pfad:    !!document.querySelector('.crumbs'),
            klasse:  !![...document.querySelectorAll('.adminbar a')]
                        .find((a) => (a.getAttribute('href') ?? '').includes('class.php')),
            zurKlasse: [...document.querySelectorAll('a.btn')]
                        .find((a) => a.textContent.includes('verwalten'))
                        ?.getAttribute('href') ?? '',
        })`);

        ok('Danach steht man auf der Kursseite', kurs.ort.includes('course.php?id='), kurs.ort);
        ok('Der Kurs heisst nach Sprache und Klasse',
           kurs.titel.startsWith('Latein - '), kurs.titel);
        ok('Und die Seite sagt, dass die Kinder schon drin sind',
           kurs.meldung.includes('Die Kinder der Klasse'), kurs.meldung);

        ok('Einen Pfad gibt es nicht mehr', !kurs.pfad,
           'drei Knöpfe mit Kursnamen darin brauchen am Telefon zwei Zeilen');
        ok('Und die Klasse steht auch nicht in der Leiste', !kurs.klasse);
        ok('Sie ist von hier aus trotzdem erreichbar', kurs.zurKlasse !== '',
           'von der Hauptansicht aus muss alles erreichbar sein');

        /*
         * Und frisch angelegt gibt es nichts nachzutragen: Die Kinder der
         * Klasse sind gerade mitgekommen. Der Knopf steht trotzdem da -
         * abgeblendet, damit man ihn beim naechsten Mal nicht sucht.
         */
        const nachtragen = await b.js(`(() => {
            const k = document.querySelector('[name="sync_class"]');
            return {
                da:       !!k,
                gesperrt: k?.disabled ?? null,
                text:     k?.textContent?.trim().replace(/\\s+/g, ' ') ?? '',
            };
        })()`);
        ok('Das Nachtragen steht da', nachtragen.da);
        ok('Und ist abgeblendet, weil niemand fehlt', nachtragen.gesperrt === true,
           String(nachtragen.gesperrt));
        ok('Der Knopf sagt das auch',
           nachtragen.text.startsWith('Alle Kinder aus Klasse'), nachtragen.text);

        // ---- Und von dort direkt wieder zurück.

        await b.geh(f.basis + kurs.zurKlasse.replace(/^.*\/teacher\//, '/teacher/'), 1300);

        const inKlasse = await b.js(`(() => {
            const zurueck = [...document.querySelectorAll('a.btn')]
                .find((a) => a.textContent.includes('Zurück zum Kurs'));
            return {
                titel:   document.querySelector('h1')?.textContent ?? '',
                zurueck: zurueck?.getAttribute('href') ?? '',
                imMenue: [...document.querySelectorAll('#menuLinks .mitem')]
                            .some((e) => e.textContent.includes('Latein - ')),
            };
        })()`);

        ok('Von der Kursseite kommt man in die Klasse',
           inKlasse.titel.startsWith('Klasse '), inKlasse.titel);
        ok('Der neue Kurs steht im Menü links', inKlasse.imMenue,
           'von jeder Seite aus derselbe Griff zum Wechseln');
        ok('Und ein Knopf führt direkt zurück', inKlasse.zurueck !== '',
           'sonst landet man über die Klassenliste wieder ganz oben');

        await b.geh(f.basis + inKlasse.zurueck.replace(/^.*\/teacher\//, '/teacher/'), 1300);
        ok('Der Weg zurück führt wirklich dorthin',
           (await b.js(`document.querySelector('h1')?.textContent ?? ''`))
               .startsWith('Latein - '));

        // ---- Und die Startseite kennt ihn.

        await b.geh(f.basis + '/teacher/', 1300);
        ok('Der neue Kurs steht auf der Startseite',
           (await b.js(`[...document.querySelectorAll('.kurskopf strong')]
                          .some((e) => e.textContent.startsWith('Latein - '))`)));
    } finally {
        b.schliessen();
    }
}
