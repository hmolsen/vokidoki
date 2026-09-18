/*
 * Ein Kind eintippen, Enter - und was danach dastehen muss.
 *
 * Zwei Dinge, die der Quelltext nicht verrät, weil sie erst nach einer
 * Antwort vom Server entstehen:
 *
 *   - Die frische Zeile trägt sofort ihre Knöpfe. Vorher tauchten
 *     "Passwort" und "Zettel" erst nach dem nächsten Laden auf, und wer
 *     fünf Kinder eintippte, hatte fünf halbe Zeilen vor sich.
 *   - Der Zettel für die ganze Klasse wird brauchbar. Vorher entschied das
 *     PHP beim Ausliefern, und der Knopf erschien erst beim nächsten Laden -
 *     ausgerechnet, nachdem jemand seine Klassenliste eingetippt hatte.
 */

import { browser, alsLehrkraft, ok, abschnitt, schlafe } from './browser.mjs';

export async function pruefe(f, aus) {
    abschnitt('Kind nachtragen');

    const b = await browser({ port: 9403, breite: 1200, hoehe: 1100, aus });
    try {
        await alsLehrkraft(b, f.basis, f.lehrer, f.passwort);
        await b.geh(f.basis + '/teacher/class.php?id=' + f.leereKlasse, 1400);

        const vorher = await b.js(`({
            zettel: document.getElementById('zettelAlle')?.className ?? '',
            gesperrt: document.getElementById('zettelAlle')?.getAttribute('aria-disabled'),
            kinder: document.querySelectorAll('#kinder tr:not(.newrow):not(:first-child)').length,
        })`);

        ok('Die Klasse ist leer', vorher.kinder === 0, String(vorher.kinder));
        ok('Der Klassenzettel steht schon da, abgeblendet',
           vorher.zettel.includes('aus') && vorher.gesperrt === 'true', vorher.zettel);

        // Genau der gemeldete Weg: tippen, Enter.
        await b.js(`(() => {
            const feld = document.querySelector('input[name="student"]');
            feld.value = 'Mira Talberg';
            feld.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
        })()`);
        await schlafe(1600);

        const nachher = await b.js(`({
            neu:      document.querySelectorAll('#kinder tr.hit').length,
            passwort: !!document.querySelector('#kinder tr.hit [name="reset_password"]'),
            zettelJe: !!document.querySelector('#kinder tr.hit a[href*="print.php"]'),
            name:     document.querySelector('#kinder tr.hit strong')?.textContent ?? '',
            konto:    document.querySelector('#kinder tr.hit code')?.textContent ?? '',
            zettel:   document.getElementById('zettelAlle')?.className ?? '',
            gesperrt: document.getElementById('zettelAlle')?.getAttribute('aria-disabled'),
        })`);

        ok('Die neue Zeile steht da', nachher.neu === 1, String(nachher.neu));
        ok('Nur der Vorname und der Anfangsbuchstabe', nachher.name === 'Mira T.', nachher.name);
        ok('Mit Benutzername', nachher.konto !== '', nachher.konto);
        ok('Und sofort mit Passwort-Knopf', nachher.passwort);
        ok('Und sofort mit Zettel-Knopf', nachher.zettelJe);
        ok('Der Klassenzettel ist jetzt brauchbar',
           !nachher.zettel.includes('aus') && nachher.gesperrt === null, nachher.zettel);

        await b.bild('klasse');
    } finally {
        b.schliessen();
    }
}
