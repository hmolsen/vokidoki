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

    // Absichtlich niedriger als die Tabelle lang ist: Der klebende
    // Tabellenkopf laesst sich nur an einer Seite pruefen, die rollt.
    const b = await browser({ port: 9407, breite: 1300, hoehe: 900, aus });
    try {
        await alsLehrkraft(b, f.basis, f.lehrer, f.passwort, f.kuerzel);
        await b.geh(f.basis + '/teacher/unit.php?id=' + f.unit, 1500);

        // ---- Der Ruhezustand.

        const ruhe = await b.js(`(() => {
            const sichtbar = (s) => [...document.querySelectorAll(s)]
                .filter((e) => getComputedStyle(e).display !== 'none').length;
            return {
                zeilen:   document.querySelectorAll('#freigabe tr[data-pos]').length,
                anlegen:  !!document.querySelector('#handzeile input[name="new_f"]'),
                versteckt: document.getElementById('handzeile')?.hidden,
                erweitern: document.querySelectorAll('.erweiternkarte').length,
                sichtbareWege: sichtbar('.erweiternkarte'),
                qr:       !document.getElementById('perQr').hidden,
                kamera:   !document.getElementById('perKamera').hidden,
                ablage:   document.getElementById('stapel')?.hidden,
                vonHand:  !!document.getElementById('vonHand'),
                aendern:  sichtbar('#freigabe [data-edit]'),
                sichern:  sichtbar('#freigabe [data-save]'),
                felder:   sichtbar('#freigabe input[name="edit_f"]'),
                balken:   !!document.querySelector('.releasebar'),
            };
        })()`);

        /*
         * Die Anlegezeile steht am Fuss der Tabelle - dort, wo die neue
         * Vokabel gleich stehen wird -, aber erst, wenn jemand sie will.
         * Zwei leere Felder sind kein Inhalt.
         */
        ok('Die Anlegezeile steht am Fuss der Tabelle', ruhe.anlegen);
        ok('Zugeklappt, bis jemand sie will', ruhe.versteckt === true,
           String(ruhe.versteckt));
        /*
         * Vier Karten im HTML, drei sichtbar: Der dritte Weg - mit dem
         * Telefon - steht zweimal da, weil er zwei ist. Am Rechner ein
         * QR-Code, der das Telefon hierherführt; am Telefon die Kamera.
         * Welches Gerät davorsitzt, weiss nur der Browser, und deshalb
         * lässt sich genau das auch nur hier prüfen.
         */
        ok('Dafür gibt es drei Wege, die Lerneinheit zu erweitern',
           ruhe.sichtbareWege === 3, String(ruhe.sichtbareWege));
        ok('Und einer davon steht in zwei Fassungen im HTML',
           ruhe.erweitern === 4, String(ruhe.erweitern));
        ok('Am Rechner zeigt er den QR-Code', ruhe.qr === true);
        ok('Und nicht die Kamera', ruhe.kamera === false,
           'hier ist keine, die sich öffnen liesse');
        ok('Die Ablage für Fotos steht leer daneben', ruhe.ablage === true,
           'sie füllt sich erst, wenn jemand Dateien wählt');
        ok('Von Hand ist einer davon', ruhe.vonHand);
        ok('Je Zeile steht "Ändern" da', ruhe.aendern === ruhe.zeilen,
           ruhe.aendern + ' von ' + ruhe.zeilen);
        ok('"Sichern" steht noch nirgends', ruhe.sichern === 0,
           ruhe.sichern + ' sichtbar - [hidden] wird von display: inline-flex geschlagen');
        ok('Und die Eingabefelder auch nicht', ruhe.felder === 0, String(ruhe.felder));
        ok('Der Freigabebalken ist trotzdem da', ruhe.balken);

        /*
         * Der Kopf bleibt beim Rollen stehen - auch am Rechner.
         *
         * Das ist nicht dasselbe wie am Telefon, und deshalb steht es hier
         * noch einmal: Dort setzt die Kartenregel overflow: visible, hier
         * gilt das overflow von table.data. Steht es auf hidden, wird die
         * Tabelle selbst zum Bezug des Klebens - der Kopf sitzt dann um die
         * Leistenhoehe versetzt zwischen der ersten und der zweiten Zeile
         * und rollt mit weg.
         */
        await b.js(`window.scrollTo(0, 0)`);
        await schlafe(300);
        const ruhig = await b.js(`(() => {
            const th = document.querySelector('#freigabe thead th');
            const t  = document.getElementById('freigabe');
            const m  = document.querySelector('#freigabe thead tr.mengen td');
            return {
                kopf:    Math.round(th.getBoundingClientRect().top),
                tabelle: Math.round(t.getBoundingClientRect().top),
                mengen:  m ? Math.round(m.getBoundingClientRect().bottom) : null,
                mengenOben: m ? Math.round(m.getBoundingClientRect().top) : null,
            };
        })()`);
        /*
         * Oben in der Tabelle steht jetzt "Nichts freigeben", und erst
         * darunter die Spaltenzeile. Geprueft wird deshalb gegen die
         * Unterkante dieses Knopfes - der Fehler, um den es hier geht,
         * bleibt derselbe: Mit overflow: hidden wird die Tabelle selbst zum
         * Bezug des Klebens, und der Kopf sitzt dann um die Leistenhoehe
         * versetzt mitten in der Liste.
         */
        ok('Der Mengen-Knopf sitzt ganz oben in der Tabelle',
           ruhig.mengenOben !== null && Math.abs(ruhig.mengenOben - ruhig.tabelle) <= 2,
           ruhig.mengenOben + ' gegen ' + ruhig.tabelle);
        ok('Und die Spaltenzeile ungerollt direkt darunter',
           Math.abs(ruhig.kopf - ruhig.mengen) <= 2,
           ruhig.kopf + ' gegen ' + ruhig.mengen
           + ' - mit overflow: hidden rutscht sie mitten hinein');

        await b.js(`window.scrollTo(0, 600)`);
        await schlafe(400);
        const geklebt = await b.js(`(() => {
            const th = document.querySelector('#freigabe thead th');
            const leiste = document.querySelector('.adminbar');
            return {
                gerollt: Math.round(window.scrollY),
                kopf:    Math.round(th.getBoundingClientRect().top),
                leiste:  Math.round(leiste.getBoundingClientRect().bottom),
            };
        })()`);
        ok('Die Seite laesst sich weit genug rollen', geklebt.gerollt > 300,
           geklebt.gerollt + ' px');
        ok('Und dann bleibt der Kopf unter der Leiste stehen',
           geklebt.kopf >= geklebt.leiste - 2 && geklebt.kopf < 200,
           geklebt.kopf + ' px gegen Leistenunterkante ' + geklebt.leiste);
        await b.js(`window.scrollTo(0, 0)`);
        await schlafe(300);

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
                      .querySelector('td').click()`);
        await schlafe(900);
        ok('Ein Klick auf die Zeile setzt die Freigabe weiterhin',
           (await b.js(`location.search`)).includes('id=' + f.unit));

        await b.bild('vokabeln');

        // ---- Hinzufuegen.

        await b.geh(f.basis + '/teacher/unit.php?id=' + f.unit, 1500);
        const vorher = await b.js(
            `document.querySelectorAll('#freigabe tr[data-pos]').length`);

        /*
         * Wort, Tab, Wort, Enter - und die naechste Zeile steht da.
         *
         * Vorher lud die Seite nach jeder Vokabel neu; bei zehn Woertern
         * sind das zehn Ladevorgaenge und zehnmal die Tabelle von oben.
         * Ob es diesmal ohne geht, sieht man nur hier: Die Kennung des
         * Dokuments bleibt dieselbe, wenn nichts neu geladen wurde.
         */
        await b.js(`(() => {
            document.getElementById('vonHand').click();
            window.__marke = 'steht';
        })()`);
        await schlafe(400);
        ok('„Von Hand" blendet die Zeile ein, ohne zu laden',
           (await b.js(`document.getElementById('handzeile').hidden === false
                        && window.__marke === 'steht'`)));

        await b.js(`(() => {
            const f = document.querySelector('#handzeile input[name="new_f"]');
            const n = document.querySelector('#handzeile input[name="new_n"]');
            f.value = 'zebra';
            n.value = 'Zebra';
            n.focus();
            n.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
        })()`);
        await schlafe(2500);

        const nachher = await b.js(`({
            zeilen:  document.querySelectorAll('#freigabe tr[data-pos]').length,
            letzte:  [...document.querySelectorAll('#freigabe tr[data-pos] td:first-child')]
                       .pop()?.textContent?.trim() ?? '',
            frisch:  document.querySelectorAll('#freigabe tr.frisch').length,
            knoepfe: document.querySelectorAll('#freigabe tr.frisch .iconaction').length,
            eigenes: !!document.querySelector('#freigabe tr.frisch [data-edit]')
                && !!document.getElementById('vokabel' + (document.querySelector(
                       '#freigabe tr.frisch [data-edit]')?.dataset.edit ?? 'x')),
            geladen: window.__marke !== 'steht',
            leer:    document.querySelector('#handzeile input[name="new_f"]').value === '',
            fokus:   document.activeElement?.name ?? '',
            /*
             * Hier stand ein Knopf "Saetze nachtragen (N)", dessen Zahl beim
             * Tippen mitwuchs. Er ist weg: Lueckensaetze entstehen beim
             * Freigeben, und eine frisch getippte Vokabel steht hinter der
             * Marke - sie wird noch nicht geuebt und braucht keinen.
             */
            nachtragen: !!document.querySelector('[name="catch_up"]'),
        })`);

        ok('Eine Vokabel von Hand kommt dazu', nachher.zeilen === vorher + 1,
           nachher.zeilen + ' statt ' + (vorher + 1));
        ok('Und steht hinten dran', nachher.letzte.includes('zebra'), nachher.letzte);
        ok('Ohne dass die Seite neu geladen hat', !nachher.geladen,
           'zehn Wörter wären sonst zehn Ladevorgänge');
        ok('Die neue Zeile ist als frisch markiert', nachher.frisch === 1,
           String(nachher.frisch));
        ok('Und sieht aus wie jede andere: Stift, Haken, Mülleimer',
           nachher.knoepfe === 3, String(nachher.knoepfe));
        ok('Mit eigenem Formular dahinter', nachher.eigenes,
           'sonst holt „Ändern" nur eine Absage');
        ok('Die Felder sind wieder leer', nachher.leer);
        ok('Und der Finger steht schon im ersten', nachher.fokus === 'new_f',
           nachher.fokus);

        /*
         * Und die Antwort zieht keine Arbeit mehr hinter sich her.
         *
         * Sie tat es lange: erst antworten, dann den Lueckensatz zur neuen
         * Vokabel erzeugen. Das haelt nur, wenn die Antwort den Browser
         * wirklich verlaesst, bevor der Vorgang endet - und legt der
         * Webserver eine Komprimierung darueber, wartet er doch bis zum
         * Schluss. Es las sich dann als "die Antwort kam nicht an", obwohl
         * die Vokabel drinstand.
         *
         * Danach stand hier ein Knopf "Saetze nachtragen (N)". Auch der ist
         * weg: Der Satz entsteht beim Freigeben, und eine frisch getippte
         * Vokabel steht hinter der Marke - sie wird noch nicht geuebt.
         */
        ok('Es gibt nichts nachzutragen', nachher.nachtragen === false,
           'ein Wort hinter der Freigabemarke braucht keinen Lueckensatz');

        /*
         * Und dasselbe Wort ein zweites Mal: Es kommt nicht dazu, und die
         * Seite sagt warum - in einer Zeile unter der Tabelle, nicht in
         * einem alert(), das den Zug anhielte.
         */
        await b.js(`(() => {
            const f = document.querySelector('#handzeile input[name="new_f"]');
            const n = document.querySelector('#handzeile input[name="new_n"]');
            f.value = 'zebra';
            n.value = 'Zebra';
            n.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
        })()`);
        await schlafe(1600);

        const doppelt = await b.js(`({
            zeilen:  document.querySelectorAll('#freigabe tr[data-pos]').length,
            meldung: document.getElementById('handfehler')?.hidden === false
                       ? document.getElementById('handfehler').textContent.trim() : '',
        })`);
        ok('Dieselbe Vokabel kommt kein zweites Mal dazu',
           doppelt.zeilen === vorher + 1, doppelt.zeilen + ' statt ' + (vorher + 1));
        ok('Und die Zeile darunter sagt, warum',
           doppelt.meldung.includes('steht schon'), doppelt.meldung || '(nichts)');
    } finally {
        b.schliessen();
    }
}
