/*
 * Vokabeln von Hand: hinzufügen, ändern, löschen.
 *
 * Das Heikle ist nicht das Formular, sondern das Nebeneinander: In
 * derselben Tabelle liegt der Freigabebalken, und ein Klick auf eine Zeile
 * setzt die Freigabe. Ein Klick auf „Löschen" darf das nicht nebenbei tun.
 *
 * Und: `[hidden]` allein genügt nicht, wenn eine Regel `display` setzt -
 * der Sichern-Knopf stand deshalb erst in jeder Zeile, obwohl er nur beim
 * Bearbeiten auftauchen soll. Das sieht man nur im Browser.
 */

import { browser, alsLehrkraft, ok, abschnitt, schlafe } from './browser.mjs';

export async function pruefe(f, aus) {
    abschnitt('Vokabeln von Hand');

    const b = await browser({ port: 9407, breite: 1300, hoehe: 1400, aus });
    try {
        await alsLehrkraft(b, f.basis, f.lehrer, f.passwort);
        await b.geh(f.basis + '/teacher/unit.php?id=' + f.unit, 1500);

        // ---- Der Ruhezustand.

        const ruhe = await b.js(`(() => {
            const sichtbar = (s) => [...document.querySelectorAll(s)]
                .filter((e) => getComputedStyle(e).display !== 'none').length;
            return {
                zeilen:   document.querySelectorAll('#freigabe tr[data-pos]').length,
                anlegen:  !!document.querySelector('#freigabe tr.newrow input[name="new_f"]'),
                aendern:  sichtbar('#freigabe [data-edit]'),
                sichern:  sichtbar('#freigabe [data-save]'),
                felder:   sichtbar('#freigabe input[name="edit_f"]'),
                balken:   !!document.querySelector('.releasebar'),
            };
        })()`);

        ok('Die Vokabeltabelle hat eine Anlegezeile', ruhe.anlegen,
           'so wie jede andere Tabelle auch');
        ok('Je Zeile steht "Ändern" da', ruhe.aendern === ruhe.zeilen,
           ruhe.aendern + ' von ' + ruhe.zeilen);
        ok('"Sichern" steht noch nirgends', ruhe.sichern === 0,
           ruhe.sichern + ' sichtbar - [hidden] wird von display: inline-flex geschlagen');
        ok('Und die Eingabefelder auch nicht', ruhe.felder === 0, String(ruhe.felder));
        ok('Der Freigabebalken ist trotzdem da', ruhe.balken);

        // ---- Bearbeiten und abbrechen.

        await b.js(`document.querySelector('#freigabe tr[data-pos] [data-edit]').click()`);
        await schlafe(400);

        const offen = await b.js(`(() => {
            const z = document.querySelector('#freigabe tr.bearbeiten');
            const sichtbar = (e) => e && getComputedStyle(e).display !== 'none';
            return {
                zeile:   !!z,
                feld:    sichtbar(z?.querySelector('input[name="edit_f"]')),
                wort:    sichtbar(z?.querySelector('[data-wort]')),
                sichern: sichtbar(z?.querySelector('[data-save]')),
                stift:   sichtbar(z?.querySelector('[data-edit]')),
                wert:    z?.querySelector('input[name="edit_f"]')?.value ?? '',
            };
        })()`);

        ok('Ein Druck auf "Ändern" macht die Zeile zum Formular', offen.zeile && offen.feld);
        ok('Der Text weicht dem Feld', offen.wort === false);
        ok('Das Feld trägt das bisherige Wort', offen.wert === 'apple', offen.wert);
        ok('"Sichern" erscheint, "Ändern" verschwindet',
           offen.sichern === true && offen.stift === false);

        await b.taste('Escape', 27);
        await schlafe(300);
        ok('Escape bricht ab, ohne zu speichern',
           (await b.js(`!document.querySelector('#freigabe tr.bearbeiten')`)));

        // ---- Der Zusammenstoss mit dem Freigabebalken.

        const freiVorher = await b.js(
            `document.querySelectorAll('#freigabe tr.released').length`);

        // Ein Klick auf "Ändern" darf die Freigabe NICHT verschieben.
        await b.js(`document.querySelectorAll('#freigabe tr[data-pos] [data-edit]')[9].click()`);
        await schlafe(500);
        ok('Ein Klick auf "Ändern" verschiebt die Freigabe nicht',
           (await b.js(`document.querySelectorAll('#freigabe tr.released').length`)) === freiVorher,
           'sonst gibt "Ändern" nebenbei zehn Vokabeln frei');
        await b.taste('Escape', 27);
        await schlafe(300);

        // Ein Klick auf die Zeile selbst soll sie weiterhin setzen.
        await b.js(`document.querySelectorAll('#freigabe tr[data-pos]')[2]
                      .querySelector('td.num').click()`);
        await schlafe(900);
        ok('Ein Klick auf die Zeile setzt die Freigabe weiterhin',
           (await b.js(`location.search`)).includes('id=' + f.unit));

        await b.bild('vokabeln');

        // ---- Hinzufuegen.

        await b.geh(f.basis + '/teacher/unit.php?id=' + f.unit, 1500);
        const vorher = await b.js(
            `document.querySelectorAll('#freigabe tr[data-pos]').length`);

        await b.js(`(() => {
            document.querySelector('input[name="new_f"]').value = 'zebra';
            document.querySelector('input[name="new_n"]').value = 'Zebra';
            document.querySelector('[name="add_vocab"]').click();
        })()`);
        await schlafe(2500);

        const nachher = await b.js(`({
            zeilen: document.querySelectorAll('#freigabe tr[data-pos]').length,
            letzte: [...document.querySelectorAll('#freigabe tr[data-pos] td:nth-child(2)')]
                      .pop()?.textContent?.trim() ?? '',
            meldung: document.querySelector('.notice')?.textContent?.trim() ?? '',
        })`);

        ok('Eine Vokabel von Hand kommt dazu', nachher.zeilen === vorher + 1,
           nachher.zeilen + ' statt ' + (vorher + 1));
        ok('Und steht hinten dran', nachher.letzte.includes('zebra'), nachher.letzte);
        ok('Die Seite sagt es', nachher.meldung.includes('zebra'), nachher.meldung);
    } finally {
        b.schliessen();
    }
}
