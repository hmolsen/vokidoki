/*
 * „Wen aufnehmen?" - das Suchfeld für die Kursaufnahme.
 *
 * Eine Schule hat dreihundert Kinder, und der Kurs braucht eines davon.
 * Vorher stand dort ein <datalist>: Der Browser bietet seine Vorschläge
 * erst nach eigenem Gutdünken an, in eigener Gestalt, und auf dem Telefon
 * oft gar nicht. Was daraus geworden ist, sagt kein Quelltext - es hängt an
 * dem, was bei jedem Tastendruck passiert.
 *
 * Drei Zustände, und jeder ist ein eigener:
 *
 *   - mehrere Treffer  -> Liste darunter, keine Ergänzung
 *   - genau einer      -> keine Liste, der Rest grau hinter dem Getippten
 *   - keiner           -> ein Satz statt einer leeren Liste
 */

import { browser, alsLehrkraft, ok, abschnitt, schlafe } from './browser.mjs';

/** Tippt Zeichen für Zeichen - input-Ereignisse wie von einer Tastatur. */
const tippen = (b, text) => b.js(`(() => {
    const f = document.querySelector('[data-suche] input');
    f.value = '';
    for (const z of ${JSON.stringify(text)}) {
        f.value += z;
        f.dispatchEvent(new Event('input', { bubbles: true }));
    }
    return f.value;
})()`);

const stand = (b) => b.js(`(() => {
    const box = document.querySelector('[data-suche]');
    const liste = box.querySelector('.vorschlaege');
    const geist = box.querySelector('.geist');
    return {
        offen:    liste.hidden === false,
        eintraege: [...liste.querySelectorAll('li[role=option]')]
                      .map((e) => e.textContent.trim()),
        leertext: liste.querySelector('.leertreffer')?.textContent?.trim() ?? '',
        geist:    geist.textContent,
        grau:     geist.lastChild?.nodeType === 3 ? geist.lastChild.textContent : '',
        getippt:  geist.querySelector('i')?.textContent ?? '',
        feld:     box.querySelector('input').value,
    };
})()`);

export async function pruefe(f, aus) {
    abschnitt('Wen aufnehmen?');

    const b = await browser({ port: 9410, breite: 1200, hoehe: 1000, aus });
    try {
        await alsLehrkraft(b, f.basis, f.lehrer, f.passwort, f.kuerzel);
        await b.geh(f.basis + '/teacher/course.php?id=' + f.kurs, 1500);

        const start = await b.js(`({
            feld:  !!document.querySelector('[data-suche] input[name="member_name"]'),
            liste: !!document.getElementById('kandidaten'),
            eigen: document.querySelector('[data-suche] input')?.getAttribute('list'),
        })`);
        ok('Das Suchfeld steht da', start.feld);
        ok('Die Namen stehen als <datalist> im HTML', start.liste,
           'das ist der Weg ohne JavaScript');
        ok('Mit Skript übernimmt das Feld die Liste selbst', start.eigen === null,
           'sonst stünden zwei Vorschlagslisten übereinander');

        // ---- Mehrere Treffer: die Liste.

        await tippen(b, 'Mar');
        await schlafe(250);
        const viele = await stand(b);
        ok('„Mar" öffnet die Liste', viele.offen);
        ok('Und zeigt beide Treffer',
           viele.eintraege.length === 2
           && viele.eintraege.every((e) => e.startsWith('Mar')),
           viele.eintraege.join(', '));
        ok('Mit der Klasse in Klammern dahinter',
           viele.eintraege.every((e) => e.includes('(7b)')),
           viele.eintraege.join(', '));
        ok('Ergänzt wird dabei nichts', viele.geist === '',
           'bei zwei Möglichkeiten wäre jede Ergänzung geraten');

        /*
         * Und sie ist auch wirklich zu sehen.
         *
         * table.data trägt overflow: hidden für die runden Ecken, und das
         * schneidet jedes Kind mit position: absolute ab - das Feld steht
         * ausgerechnet in der letzten Zeile, die Liste wäre zu drei
         * Vierteln weg. Im DOM steht sie trotzdem; nur ein Blick auf den
         * Punkt sagt, ob dort etwas zu sehen ist.
         */
        const sichtbar = await b.js(`(() => {
            const li = document.querySelector('[data-suche] .vorschlaege li');
            const r  = li.getBoundingClientRect();
            const getroffen = document.elementFromPoint(
                Math.round(r.left + r.width / 2), Math.round(r.top + r.height / 2));
            return {
                hoehe:  Math.round(r.height),
                unten:  Math.round(r.bottom),
                fenster: window.innerHeight,
                trifft: li.contains(getroffen) || li === getroffen,
                lage:   getComputedStyle(
                    document.querySelector('[data-suche] .vorschlaege')).position,
            };
        })()`);
        ok('Die Liste liegt frei und wird nicht abgeschnitten', sichtbar.trifft,
           'im DOM steht sie auch dann, wenn die Tabelle sie wegschneidet');
        ok('Dafür hängt sie an keinem Vorfahren', sichtbar.lage === 'fixed',
           sichtbar.lage + ' - mit absolute schneidet die Tabelle sie ab');
        ok('Und steht im Fenster', sichtbar.unten <= sichtbar.fenster,
           sichtbar.unten + ' von ' + sichtbar.fenster);

        // ---- Genau einer: die graue Ergaenzung.

        await tippen(b, 'Mart');
        await schlafe(250);
        const einer = await stand(b);
        ok('„Mart" lässt genau einen übrig, und die Liste verschwindet',
           !einer.offen, einer.eintraege.join(', '));
        ok('Der Rest des Namens steht dahinter',
           einer.geist === 'Marta W.', einer.geist);
        ok('Das Getippte davon ist der getippte Teil',
           einer.getippt === 'Mart', einer.getippt);
        ok('Und ergänzt wird nur der Rest', einer.grau === 'a W.', einer.grau);
        ok('Im Feld selbst steht weiterhin nur das Getippte',
           einer.feld === 'Mart', einer.feld);

        const farben = await b.js(`(() => {
            const g = document.querySelector('[data-suche] .geist');
            const i = g.querySelector('i');
            return {
                grau:      getComputedStyle(g).color,
                text:      getComputedStyle(document.querySelector('[data-suche] input')).color,
                unsichtbar: getComputedStyle(i).visibility,
            };
        })()`);
        ok('Das Getippte im Grauen ist unsichtbar',
           farben.unsichtbar === 'hidden',
           'sonst stünden die Buchstaben doppelt übereinander');
        ok('Und die Ergänzung ist blasser als das Getippte',
           farben.grau !== farben.text, farben.grau + ' gegen ' + farben.text);

        await b.bild('suche');

        // ---- Und die Klasse ist selbst ein Suchwort.

        /*
         * Wer den Namen nur halb kennt, aber die Klasse, kommt ueber sie
         * ans Ziel - "Marta W." gibt es an einer Schule zweimal, "Marta W.
         * (7b)" nicht.
         */
        await tippen(b, '7b');
        await schlafe(250);
        const nachKlasse = await stand(b);
        ok('Nach der Klasse lässt sich suchen',
           nachKlasse.offen && nachKlasse.eintraege.length === 3,
           nachKlasse.eintraege.join(', '));
        ok('Und alle drei stehen in ihr',
           nachKlasse.eintraege.every((e) => e.includes('(7b)')),
           nachKlasse.eintraege.join(', '));

        await tippen(b, 'Lehrkraft');
        await schlafe(250);
        const lehrkraft = await stand(b);
        ok('„Lehrkraft" findet die Lehrkräfte', lehrkraft.eintraege.length >= 1,
           lehrkraft.eintraege.join(', '));
        ok('Und sie sind als solche gekennzeichnet',
           lehrkraft.eintraege.every((e) => e.includes('(Lehrkraft)')),
           lehrkraft.eintraege.join(', '));

        // ---- Nach dem Benutzernamen: Er steht auf dem Zettel und ist eindeutig.
        const benutzer = await b.js(`document.querySelector('#kandidaten option[value="Marta W."]')?.dataset.benutzer ?? ''`);
        await tippen(b, benutzer.slice(0, -1));
        await schlafe(250);
        const perName = await stand(b);
        ok('Auch der Benutzername findet - und wird zu Ende geschrieben',
           benutzer !== '' && perName.geist.endsWith(benutzer.slice(-1)) && !perName.offen,
           JSON.stringify({ benutzer, perName }));
        await tippen(b, 'Mar');
        await schlafe(250);
        ok('In der Liste steht er hinter Name und Klasse',
           (await stand(b)).eintraege.some((e) => e.includes(`· ${benutzer}`)),
           (await stand(b)).eintraege.join(', '));

        // ---- Niemand: ein Satz statt einer leeren Liste.

        await tippen(b, 'Zwiebelfisch');
        await schlafe(250);
        const keiner = await stand(b);
        ok('Ohne Treffer sagt die Liste das', keiner.leertext.includes('Niemand gefunden'),
           keiner.leertext);
        ok('Und ergänzt nichts', keiner.geist === '');

        // ---- Enter nimmt den Vorschlag.

        await tippen(b, 'Mart');
        await schlafe(250);
        await b.js(`document.querySelector('[data-suche] input').focus()`);
        await b.taste('Enter', 13, '\r');
        await schlafe(1800);

        const drin = await b.js(`(() => {
            const namen = [...document.querySelectorAll('#mitglieder td:first-child')]
                .map((e) => e.textContent.trim());
            return { namen, meldung: document.querySelector('.notice')?.textContent ?? '' };
        })()`);
        ok('Enter nimmt den Vorschlag auf',
           drin.namen.includes('Marta W.'), drin.namen.join(', '));
        ok('Und die Seite sagt es', drin.meldung.includes('Marta W.'), drin.meldung);

        /*
         * In der Tabelle steht die Klasse, nicht die Rolle: "Kind" in
         * jeder Zeile sagte nichts. Lehrkraefte stehen oben.
         */
        const tabelle = await b.js(`(() => {
            const zeilen = [...document.querySelectorAll('#mitglieder tr')]
                .filter((tr) => tr.querySelector('td'));
            return {
                kopf:   [...document.querySelectorAll('#mitglieder th')]
                          .map((e) => e.textContent.trim()),
                erste:  zeilen[0]?.children[2]?.textContent.trim() ?? '',
                klassen: zeilen.map((tr) => tr.children[2]?.textContent.trim() ?? ''),
            };
        })()`);
        ok('Die Tabelle nennt die Klasse statt der Rolle',
           tabelle.kopf.includes('Klasse') && !tabelle.kopf.includes('Rolle'),
           tabelle.kopf.join(' | '));
        ok('Die Lehrkraft steht oben', tabelle.erste === 'Lehrkraft', tabelle.erste);
        ok('Und bei den Kindern steht ihre Klasse',
           tabelle.klassen.filter((k) => k === '8c').length >= 3,
           tabelle.klassen.join(', '));
        ok('Auch bei der frisch Aufgenommenen',
           tabelle.klassen.includes('7b'), tabelle.klassen.join(', '));
    } finally {
        b.schliessen();
    }
}
