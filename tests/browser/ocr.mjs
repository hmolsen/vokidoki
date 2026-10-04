/*
 * Einlesen mit Texterkennung auf dem Gerät - und die gelben Zeilen danach.
 *
 * Was hier geprüft wird, gibt es nur im Browser: Tesseract läuft als
 * WebAssembly in der Seite, die Fotos kommen aus einem <input type="file">,
 * und die Zeilen entstehen aus der Lage der Wörter auf dem Bild.
 *
 * Das Bild ist echt, nicht vorgetäuscht: Die Prüfung zeichnet eine
 * Vokabelliste in ein Canvas, leicht schief wie ein Handyfoto, speichert es
 * als Datei und legt es in die Ablage. Tesseract liest es wirklich. Die KI
 * dahinter ist der Simulator (tests/fake-anthropic.php) - er antwortet mit
 * festen Paaren, eines davon als "berichtigt" markiert, und schreibt die
 * Anfrage mit, damit sich prüfen lässt, dass nur Text ankam.
 */

import { execFileSync } from 'node:child_process';
import { writeFileSync, readFileSync, mkdtempSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';

import { browser, alsLehrkraft, ok, abschnitt, schlafe } from './browser.mjs';

const php = (wurzel, code) =>
    execFileSync('php', ['-r', code], { cwd: wurzel, encoding: 'utf8' });

export async function pruefe(f, aus, wurzel) {
    abschnitt('Einlesen: Texterkennung auf dem Gerät');

    // Eine eigene Lerneinheit, damit die anderen Abschnitte ihre Zahlen behalten.
    const einheit = Number(php(wurzel, `require 'lib/db.php';
        $k = q1('SELECT * FROM courses WHERE id = ?', [${f.kurs}]);
        q("INSERT INTO units (language_id, course_id, title, released_position, position)
           VALUES (?, ?, 'OCR-Probe', 0, 99)", [(int) $k['language_id'], (int) $k['id']]);
        echo db()->lastInsertId();`));

    const b = await browser({ port: 9419, breite: 1100, hoehe: 900, aus });
    try {
        await alsLehrkraft(b, f.basis, f.lehrer, f.passwort);
        await b.geh(f.basis + '/teacher/unit.php?id=' + einheit, 1500);

        // ---- Zeilen aus Wörtern: zwei Spalten, die Tesseract getrennt liest.
        const zeilen = await b.js(`(async () => {
            const { zeilenBauen } = await import(document.getElementById('stapel').dataset.ocr);
            const wort = (text, x0, y0) => ({ text, bbox: { x0, y0, x1: x0 + text.length * 14, y1: y0 + 30 } });
            // Block 1: die englische Spalte, Block 2: die deutsche - so liefert
            // Tesseract eine zweispaltige Seite oft.
            return zeilenBauen([
                { paragraphs: [{ lines: [
                    { words: [wort('the', 50, 100), wort('spoon', 110, 102)] },
                    { words: [wort('to', 50, 160), wort('cook', 90, 161)] },
                ] }] },
                // Dazwischen die Lautschrift - Tesseract liest sie als Salat.
                { paragraphs: [{ lines: [
                    { words: [wort('[spu:n]', 350, 101)] },
                    { words: [wort('/kuk/', 350, 160)] },
                ] }] },
                { paragraphs: [{ lines: [
                    { words: [wort('der', 600, 103), wort('Löffel', 660, 104)] },
                    { words: [wort('kochen', 600, 162)] },
                ] }] },
            ]);
        })()`);
        ok('Getrennt gelesene Spalten werden wieder zu Zeilen - ohne Lautschrift',
           JSON.stringify(zeilen) === JSON.stringify(['the spoon\tder Löffel', 'to cook\tkochen']),
           JSON.stringify(zeilen));

        /*
         * ---- Eine Bildschirmkopie: Symbole neben der Überschrift, darunter
         * eine Tabelle mit Rahmen. Tesseract.js las von sich aus alles als
         * einen Textblock; die Symbole warfen den Block um, übrig blieben
         * die Überschrift und Zeichensalat - und die KI fand "keine Vokabeln".
         * Nachgebaut nach einer dänischen Liste, mit der es so geschah.
         */
        const bildschirm = await b.js(`(async () => {
            const c = document.createElement('canvas');
            c.width = 1300; c.height = 620;
            const x = c.getContext('2d');
            x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height);
            for (const [cx, farbe] of [[88, '#29b6d8'], [188, '#e5546a']]) {
                x.fillStyle = farbe; x.beginPath(); x.arc(cx, 70, 50, 0, 7); x.fill();
                x.fillStyle = '#6b3e26'; x.beginPath(); x.arc(cx, 62, 18, 0, 7); x.fill();
                x.fillStyle = '#f2c94c'; x.fillRect(cx - 28, 88, 56, 22);
            }
            x.fillStyle = '#222'; x.font = 'bold 24px Arial'; x.fillText('Über sich selbst sprechen', 258, 78);
            const paare = [['Deutsch', 'Dänisch'], ['meine Familie', 'min familie'], ['meine Mutter', 'min mor'],
                           ['mein Vater', 'min far'], ['ein Onkel', 'en onkel'], ['eine Cousine', 'en kusine'],
                           ['ein Cousin', 'en fætter'], ['meine Eltern', 'mine forældre'], ['eine Tante', 'en tante'],
                           ['mein Kind', 'mit barn'], ['dein', 'din - dit - dine']];
            x.strokeStyle = '#222'; x.lineWidth = 1;
            paare.forEach(([d, f], i) => {
                const y = 150 + i * 38;
                x.strokeRect(28.5, y + .5, 630, 38); x.strokeRect(658.5, y + .5, 630, 38);
                x.font = (i === 0 ? 'bold ' : '') + '17px Arial';
                x.fillText(d, 34, y + 25); x.fillText(f, 664, y + 25);
            });
            const { texterkennung } = await import(document.getElementById('stapel').dataset.ocr);
            return await texterkennung([c.toDataURL('image/jpeg', 0.82)], 'da');
        })()`, 300000);
        const paarZeilen = bildschirm.split('\n').filter((z) => z.includes('\t'));
        ok('Eine Tabelle neben Symbolen wird ganz gelesen, Zeile für Zeile',
           paarZeilen.length >= 9 && bildschirm.includes('meine Mutter\tmin mor')
           && bildschirm.includes('ein Cousin\ten fætter'),
           JSON.stringify(bildschirm));

        // ---- Ein echtes Bild einer Vokabelliste.
        const bild = await b.js(`(() => {
            const c = document.createElement('canvas');
            c.width = 1400; c.height = 700;
            const x = c.getContext('2d');
            x.fillStyle = '#fbf8f0'; x.fillRect(0, 0, c.width, c.height);
            x.translate(700, 350); x.rotate(0.015); x.translate(-700, -350);
            x.fillStyle = '#222'; x.font = '34px Georgia';
            [['the spoon', 'der Löffel'], ['the plate', 'der Teller'], ['to cook', 'kochen'],
             ['Good night!', 'Gute Nacht!'], ['How are you?', 'Wie geht es dir?']]
                .forEach(([e, d], i) => { x.fillText(e, 80, 110 + i * 100); x.fillText(d, 720, 110 + i * 100); });
            return c.toDataURL('image/png').split(',')[1];
        })()`);
        const pfad = join(mkdtempSync(join(tmpdir(), 'vtocr')), 'vokabelseite.png');
        writeFileSync(pfad, Buffer.from(bild, 'base64'));

        await b.send('DOM.enable');
        const doc  = await b.send('DOM.getDocument', { depth: 1 });
        const feld = await b.send('DOM.querySelector',
            { nodeId: doc.result.root.nodeId, selector: '#bildwahl' });
        await b.send('DOM.setFileInputFiles', { files: [pfad], nodeId: feld.result.nodeId });
        await schlafe(700);

        await b.js(`document.getElementById('erkennen').click()`);

        // Tesseract lädt beim ersten Mal Engine und Sprachdaten.
        let angekommen = false;
        for (let i = 0; i < 60 && !angekommen; i++) {
            await schlafe(500);
            angekommen = await b.js(`location.search.includes('neu=')`).catch(() => false);
        }
        ok('Nach dem Lesen ist die Seite mit den neuen Vokabeln da', angekommen);
        await schlafe(1200);

        const anfrage = JSON.parse(readFileSync(
            join(php(wurzel, 'echo sys_get_temp_dir();').trim(), 'vt-fake-anthropic-last.json'), 'utf8'));
        const inhalt = anfrage.body.messages[0].content;
        const text   = typeof inhalt === 'string' ? inhalt
            : inhalt.map((blk) => blk.text ?? '').join('\n');
        ok('Bei der KI kommt kein Bild an',
           typeof inhalt === 'string' || !inhalt.some((blk) => blk.type === 'image'));
        ok('Sondern der auf dem Gerät erkannte Text, Zeile für Zeile',
           text.includes('the spoon\tder Löffel') && text.includes('How are you?\tWie geht es dir?'),
           text.slice(text.indexOf('--- Text'), text.indexOf('--- Text') + 200));

        const tabelle = await b.js(`({
            gelb:    [...document.querySelectorAll('#freigabe tr.pruefen strong[data-wort]')]
                       .map((s) => s.textContent),
            notiz:   document.querySelector('#freigabe .pruefnotiz')?.textContent.replace(/\\s+/g, ' ').trim(),
            kopf:    !!document.querySelector('.pruefhinweis-kopf'),
            farbe:   getComputedStyle(document.querySelector('#freigabe tr.pruefen td')).backgroundImage,
        })`);
        ok('Die Zeile, die die KI berichtigt hat, ist gelb markiert',
           JSON.stringify(tabelle.gelb) === JSON.stringify(['the spoon']), JSON.stringify(tabelle.gelb));
        ok('Und sagt, was berichtigt wurde',
           (tabelle.notiz ?? '').includes('Loffel → Löffel'), tabelle.notiz);
        ok('Über der Tabelle steht der Hinweis dazu', tabelle.kopf);
        ok('Das Gelb bleibt, wenn das Grün der neuen Zeilen verblasst',
           tabelle.farbe.includes('gradient'), tabelle.farbe);

        if (aus) await b.bild('ocr-gelb');

        // ---- "Passt" ohne Neuladen, Zeile für Zeile.

        // Eine zweite markierte Zeile, damit sich das Weiterspringen zeigt.
        php(wurzel, `require 'lib/db.php';
            q("UPDATE vocab SET check_note = 'Tel1er → Teller' WHERE unit_id = ? AND term_foreign = 'the plate'",
              [${einheit}]);`);
        await b.neuLaden(1500);
        await b.js(`window.__nichtNeuGeladen = true`);

        const sofort = await b.js(`(() => {
            const erster = document.querySelector('#freigabe [data-passt]');
            erster.focus();
            erster.click();
            // Gleich nach dem Klick, bevor irgendeine Antwort da sein kann.
            return {
                gelb:  document.querySelectorAll('#freigabe tr.pruefen').length,
                zahl:  document.querySelector('[data-pruefzahl]')?.textContent,
                fokus: document.activeElement?.dataset.passt ?? null,
                zweiter: document.querySelectorAll('#freigabe [data-passt]')[1]?.dataset.passt ?? null,
            };
        })()`);
        ok('"Passt" nimmt die Markierung sofort weg, ohne auf den Server zu warten',
           sofort.gelb === 1 && sofort.zahl === 'Eine Vokabel', JSON.stringify(sofort));
        ok('Und der Fokus springt zum nächsten "Passt"',
           sofort.fokus !== null && sofort.fokus === sofort.zweiter, JSON.stringify(sofort));

        await schlafe(800);
        ok('Die Seite wurde dabei nicht neu geladen',
           (await b.js('window.__nichtNeuGeladen === true')) === true);
        ok('Und der Server hat es trotzdem gespeichert',
           php(wurzel, `require 'lib/db.php'; echo json_encode(qv("SELECT check_note FROM vocab
               WHERE unit_id = ? AND term_foreign = 'the spoon'", [${einheit}]));`).trim() === 'null');

        // Mit Enter weiter - die Hand bleibt auf der Tastatur. Mit dem Zeichen
        // dazu: Ohne text löst Chrome den Knopf nicht aus.
        await b.taste('Enter', 13, '\r');
        await schlafe(800);
        ok('Mit Enter ist auch die nächste erledigt, und der Hinweis oben verschwindet',
           (await b.js(`document.querySelectorAll('#freigabe tr.pruefen').length`)) === 0
           && (await b.js(`document.querySelector('.pruefhinweis-kopf').hidden`)) === true);
        ok('Auch sie ist gespeichert',
           php(wurzel, `require 'lib/db.php'; echo (int) qv("SELECT COUNT(*) FROM vocab
               WHERE unit_id = ? AND check_note IS NOT NULL", [${einheit}]);`).trim() === '0');
    } finally {
        b.schliessen();
        php(wurzel, `require 'lib/db.php'; q('DELETE FROM units WHERE id = ?', [${einheit}]);`);
    }
}
