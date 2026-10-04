/*
 * Die Ablage auf der Lerneinheitsseite: Seiten wählen, ordnen, entfernen.
 *
 * Bis hierher war das Einlesen eine eigene Ansicht in der App. Wer an einer
 * Lerneinheit arbeitete, musste dorthin, Dateien wählen, einen Titel
 * eintippen und die Einheit hinterher in einer Liste wiedersuchen. Jetzt
 * öffnet der Knopf den Dateidialog an Ort und Stelle.
 *
 * Alles, was hier geprüft wird, gibt es nur im Browser: ein
 * <input type="file">, das der Server nie sieht, Blob-Adressen, eine
 * Vorschau, die aus ihnen entsteht, und die Frage, ob das Gerät eine Kamera
 * in der Hand hat. Das Erkennen selbst wird NICHT ausgelöst - das kostet
 * Geld und braucht das Modell.
 */

import { writeFileSync, mkdtempSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';

import { browser, alsLehrkraft, ok, abschnitt, schlafe } from './browser.mjs';

/** Ein winziges, gültiges JPEG - mehr braucht die Vorschau nicht. */
const JPEG = Buffer.from(
    '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
    + 'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAAQABABAREA/8QAFQABAQAAAAAA'
    + 'AAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAA/AKp//9k=', 'base64');

export async function pruefe(f, aus) {
    abschnitt('Seiten in die Ablage legen');

    const ordner = mkdtempSync(join(tmpdir(), 'vtseiten'));
    const dateien = [];
    for (let i = 0; i < 10; i++) {
        const pfad = join(ordner, 'seite' + i + '.jpg');
        writeFileSync(pfad, JPEG);
        dateien.push(pfad);
    }

    const b = await browser({ port: 9411, breite: 1100, hoehe: 1000, aus });
    try {
        await alsLehrkraft(b, f.basis, f.lehrer, f.passwort, f.kuerzel);
        await b.geh(f.basis + '/teacher/unit.php?id=' + f.unit, 1500);

        /*
         * Dateien lassen sich nicht anklicken - ein Dateidialog ist ein
         * Fenster des Betriebssystems. Über das Protokoll geht es trotzdem:
         * DOM.setFileInputFiles legt sie in das Feld, als hätte jemand sie
         * gewählt, und löst dabei auch das change-Ereignis aus.
         */
        const legen = async (liste) => {
            // send() liefert die ganze Protokollnachricht, nicht nur ihr
            // Ergebnis - das Nutzbare steht unter .result.
            await b.send('DOM.enable');
            const doc  = await b.send('DOM.getDocument', { depth: 1 });
            const feld = await b.send('DOM.querySelector',
                { nodeId: doc.result.root.nodeId, selector: '#bildwahl' });
            await b.send('DOM.setFileInputFiles',
                { files: liste, nodeId: feld.result.nodeId });
            await schlafe(700);
        };

        await legen(dateien.slice(0, 3));

        const drei = await b.js(`({
            offen:  !document.getElementById('stapel').hidden,
            kacheln: document.querySelectorAll('#seiten .seite').length,
            bilder: [...document.querySelectorAll('#seiten img')]
                      .filter((i) => i.src.startsWith('blob:')).length,
            nummern: [...document.querySelectorAll('.seitennr')].map((n) => n.textContent),
            knopf:  document.querySelector('#erkennen [data-knopftext]').textContent.trim(),
            hinweis: !document.getElementById('stapelZuviel').hidden,
        })`);

        ok('Drei gewählte Seiten öffnen die Ablage', drei.offen);
        ok('Und liegen als drei Kacheln darin', drei.kacheln === 3, String(drei.kacheln));
        ok('Jede mit einer Vorschau aus dem Browser', drei.bilder === 3,
           String(drei.bilder) + ' - die Bilder liegen hier, nicht auf dem Server');
        ok('Durchnummeriert, damit sich ähnliche Buchseiten unterscheiden lassen',
           drei.nummern.join(',') === '1,2,3', drei.nummern.join(','));
        ok('Der Knopf sagt, wie viele er nimmt',
           drei.knopf === 'Vokabeln von 3 Seiten erkennen', drei.knopf);
        ok('Und von zu vielen ist noch nicht die Rede', drei.hinweis === false);

        // ---- Ordnen: der Pfeil schiebt eine Seite nach vorn.

        await b.js(`window.merk = document.querySelectorAll('#seiten img')[2].src`);
        await b.js(`document.querySelector('.seite[data-i="2"] [data-vor]').click()`);
        await schlafe(300);
        ok('Der Pfeil schiebt eine Seite nach vorn',
           (await b.js(`window.merk === document.querySelectorAll('#seiten img')[1].src`)),
           'auf einem Telefon gibt es kein Ziehen, und die Reihenfolge ist die der Vokabelliste');
        ok('Die erste Seite lässt sich nicht weiter nach vorn schieben',
           (await b.js(`document.querySelector('.seite[data-i="0"] [data-vor]').disabled`)) === true);

        // ---- Entfernen.

        await b.js(`document.querySelector('.seite[data-i="0"] [data-weg]').click()`);
        await schlafe(300);
        ok('Entfernen nimmt eine Kachel heraus',
           (await b.js(`document.querySelectorAll('#seiten .seite').length`)) === 2);
        ok('Und die Nummern rücken nach',
           (await b.js(`[...document.querySelectorAll('.seitennr')]
                          .map((n) => n.textContent).join(',')`)) === '1,2');

        // ---- Mehr als sechs.

        /*
         * Sechs ist die Grenze der Schnittstelle. Die überzähligen werden
         * nicht weggeworfen, sondern abgeblendet: Wer zehn Seiten gewählt
         * hat, hat sie gewählt - er soll selbst entscheiden, welche warten,
         * und sie notfalls nach vorn schieben.
         */
        await legen(dateien);
        const viele = await b.js(`({
            kacheln: document.querySelectorAll('#seiten .seite').length,
            grau:    document.querySelectorAll('#seiten .seite.zuviel').length,
            hinweis: document.getElementById('stapelZuviel').textContent.trim(),
            sichtbar: !document.getElementById('stapelZuviel').hidden,
            knopf:   document.querySelector('#erkennen [data-knopftext]').textContent.trim(),
            wegbar:  !!document.querySelector('.seite.zuviel [data-weg]'),
            blass:   getComputedStyle(
                        document.querySelector('.seite.zuviel .seitenbild')).opacity,
        })`);

        ok('Alle zwölf Seiten bleiben in der Ablage', viele.kacheln === 12,
           String(viele.kacheln));
        ok('Die über sechs hinaus sind abgeblendet', viele.grau === 6, String(viele.grau));
        ok('Und wirklich blass, nicht nur so benannt',
           Number(viele.blass) < 0.5, viele.blass);
        ok('Ein Satz sagt, was mit ihnen geschieht',
           viele.sichtbar && viele.hinweis.includes('Höchstens 6 Seiten'), viele.hinweis);
        ok('Der Knopf nimmt trotzdem nur sechs',
           viele.knopf === 'Vokabeln von 6 Seiten erkennen', viele.knopf);
        ok('Wegnehmen lassen sie sich trotzdem', viele.wegbar,
           'sonst hinge die siebte Seite fest');

        // ---- Die Lupe.

        await b.js(`document.querySelector('.seite[data-i="0"] [data-gross]').click()`);
        await schlafe(400);
        const lupe = await b.js(`({
            offen: document.getElementById('lupe').open,
            bild:  document.getElementById('lupeBild').src.startsWith('blob:'),
        })`);
        ok('Ein Druck auf die Vorschau macht sie gross', lupe.offen && lupe.bild,
           'Buchseiten sehen einander ähnlich');
        await b.taste('Escape', 27);
        await schlafe(300);

        await b.js(`document.getElementById('stapelWeg').click()`);
        await schlafe(300);
        ok('"Auswahl verwerfen" räumt die Ablage wieder weg',
           (await b.js(`document.getElementById('stapel').hidden`)) === true);

        // ---- Und am Telefon die Kamera statt des Codes.

        /*
         * Am Rechner führt der dritte Weg über einen QR-Code: Das Telefon
         * scannt ihn und steht auf derselben Seite. Am Telefon wäre
         * derselbe Code Unsinn - dort ist die Kamera in der Hand. Welches
         * Gerät davorsitzt, weiss nur der Browser; gefragt wird nach
         * Zeigegerät und Fingerzahl, nicht nach dem Kennzeichen der
         * Anfrage, weil ein iPad sich seit Jahren als Mac meldet.
         */
        await b.send('Emulation.setTouchEmulationEnabled',
                     { enabled: true, maxTouchPoints: 5 });
        await b.groesse(390, 844);
        await b.geh(f.basis + '/teacher/unit.php?id=' + f.unit, 1500);

        const telefon = await b.js(`({
            grob:   window.matchMedia('(pointer: coarse)').matches,
            finger: navigator.maxTouchPoints,
            qr:     !document.getElementById('perQr').hidden,
            kamera: !document.getElementById('perKamera').hidden,
            aufnahme: document.getElementById('kamerawahl').getAttribute('capture'),
        })`);

        ok('Der Browser meldet ein Telefon', telefon.grob && telefon.finger > 1,
           telefon.grob + ' / ' + telefon.finger);
        ok('Dann steht dort die Kamera', telefon.kamera === true);
        ok('Und nicht mehr der QR-Code', telefon.qr === false,
           'einen Code zu scannen, der auf dieselbe Seite führt, ist Unsinn');
        ok('Das Feld dahinter öffnet die rückwärtige Kamera',
           telefon.aufnahme === 'environment', String(telefon.aufnahme));

        if (aus) await b.bild('stapel-telefon');
    } finally {
        b.schliessen();
    }
}
