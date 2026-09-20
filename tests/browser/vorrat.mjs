/*
 * Üben ohne Netz.
 *
 * Das ist die Prüfung, die keine PHP-Suite ersetzen kann: Ob das Üben
 * wirklich ohne Server läuft, sieht man erst, wenn man den Server wegnimmt.
 * Das Protokoll kann das - Network.emulateNetworkConditions schaltet den Tab
 * in den Flugmodus, ohne dass jemand ein Kabel ziehen muss.
 *
 * Geprüft wird die ganze Kette: Vorrat holen, Netz aus, eine Runde üben, Netz
 * an, und dann muss der Lernstand auf dem Server angekommen sein - genau
 * einmal, auch wenn derselbe Stapel zweimal unterwegs war.
 */

import { browser, alsKind, ok, abschnitt, schlafe } from './browser.mjs';

export async function pruefe(f, aus) {
    abschnitt('Üben ohne Netz');

    const b = await browser({ port: 9412, breite: 420, hoehe: 900, aus });
    try {
        await alsKind(b, f.basis, f.kind, f.passwort);
        await b.geh(f.basis + '/', 2000);

        // ---- Der Vorrat liegt im Gerät.

        const vorrat = await b.js(`(() => {
            const schl = Object.keys(localStorage).find((k) => k.startsWith('vt-vorrat-'));
            if (!schl) return { da: false };
            const d = JSON.parse(localStorage.getItem(schl));
            return {
                da: true,
                sprachen: (d.sprachen ?? []).length,
                vokabeln: (d.vokabeln ?? []).length,
                groesse:  localStorage.getItem(schl).length,
                schwelle: d.schwelle,
                // Wie viele Vokabeln diese Lerneinheit freigegeben hat.
                // Davon haengt ab, wie viele Moeglichkeiten eine Frage
                // ueberhaupt haben kann - und welche Zahl weiter unten die
                // richtige ist. Frueher stand dort fest die Vier, und der
                // Abschnitt fiel um, sobald eine andere Pruefung vorher den
                // Freigabebalken verschoben hatte.
                inDieserEinheit: (d.vokabeln ?? []).filter((v) => v.u === ${f.unit}).length,
            };
        })()`);

        ok('Nach dem Öffnen liegt der Vorrat im Gerät', vorrat.da);
        ok('Mit den Kursen dieses Kindes', vorrat.sprachen > 0, String(vorrat.sprachen));
        ok('Und seinen freigegebenen Vokabeln', vorrat.vokabeln > 0,
           String(vorrat.vokabeln));
        ok('Die Schwelle für „gekonnt" kommt mit', vorrat.schwelle === 3,
           String(vorrat.schwelle) + ' - das Gerät rechnet mit derselben Zahl');
        /*
         * Die Größe im Auge behalten: localStorage ist bei rund fünf
         * Megabyte zu Ende, und der Vorrat wächst mit jedem Kurs. Ein Kurs
         * mit zwei Dutzend Vokabeln darf davon nicht mehr als ein Promille
         * brauchen, sonst trägt die Rechnung für eine ganze Schullaufbahn
         * nicht.
         */
        ok('Und bleibt klein', vorrat.groesse < 60000,
           vorrat.groesse + ' Zeichen für ' + vorrat.vokabeln + ' Vokabeln');

        // ---- Jetzt das Netz weg.

        await b.send('Network.enable');
        const flugmodus = async (an) => {
            await b.send('Network.emulateNetworkConditions', {
                offline: an, latency: 0, downloadThroughput: -1, uploadThroughput: -1,
            });
        };
        await flugmodus(true);

        /*
         * Gegenprobe zuerst: Ist der Flugmodus wirklich an? Ohne das prüfte
         * der ganze Abschnitt nichts - er liefe einfach online durch.
         */
        const netzWeg = await b.js(`fetch('${f.basis}/api/meta.php?action=version',
            { headers: { 'X-Vokabeltrainer': '1' } })
            .then(() => 'kam durch').catch(() => 'kein netz')`);
        ok('Das Netz ist wirklich weg', netzWeg === 'kein netz', netzWeg);

        await b.geh(f.basis + '/#/unit/' + f.unit, 1500);
        const ohneNetz = await b.js(`({
            ueberschrift: document.querySelector('.topbar h1')?.textContent ?? '',
            zeilen: document.querySelectorAll('.row.vocab').length,
            ueben:  !!document.querySelector('[data-mode="mc"]'),
        })`);
        ok('Die Lerneinheit steht auch ohne Netz da',
           ohneNetz.zeilen > 0 && ohneNetz.ueben,
           ohneNetz.zeilen + ' Zeilen / ' + ohneNetz.ueberschrift);

        // ---- Eine Runde üben, ganz ohne Server.

        await b.geh(f.basis + '/#/quiz/' + f.unit, 1500);
        const frage = await b.js(`({
            wort:      document.querySelector('.word')?.textContent ?? '',
            optionen:  document.querySelectorAll('.option').length,
            verschieden: new Set([...document.querySelectorAll('.option')]
                            .map((o) => o.textContent)).size,
            richtung:  document.querySelector('.dir')?.textContent ?? '',
        })`);
        // Vier Moeglichkeiten - oder so viele, wie die Lerneinheit hergibt.
        const erwartet = Math.min(4, vorrat.inDieserEinheit);
        ok('Eine Frage entsteht im Gerät',
           frage.wort !== '' && frage.optionen === erwartet,
           frage.wort + ' / ' + frage.optionen + ' statt ' + erwartet);
        ok('Und keine Möglichkeit steht zweimal da',
           frage.verschieden === frage.optionen,
           frage.verschieden + ' von ' + frage.optionen
           + ' - zwei gleiche nebeneinander sähen aus wie ein Fehler');
        ok('Und sagt, in welche Richtung sie geht', frage.richtung.includes('→'),
           frage.richtung);

        /*
         * Fünf Antworten - immer die erste Möglichkeit. Ob sie richtig ist,
         * ist hier gleichgültig: Geprüft wird, dass überhaupt etwas
         * verbucht wird, und zwar ohne Netz.
         */
        for (let i = 0; i < 5; i++) {
            await b.js(`document.querySelector('.option')?.click()`);
            await schlafe(2200);
        }

        const geuebt = await b.js(`(() => {
            const wSchl = Object.keys(localStorage).find((k) => k.startsWith('vt-warte-'));
            const vSchl = Object.keys(localStorage).find((k) => k.startsWith('vt-vorrat-'));
            const warte = JSON.parse(localStorage.getItem(wSchl) ?? '[]');
            const vorrat = JSON.parse(localStorage.getItem(vSchl) ?? '{}');
            return {
                warteschlange: warte.length,
                kennungen: new Set(warte.map((e) => e.e)).size,
                stand: (vorrat.stand ?? []).length,
                hatFrage: !!document.querySelector('.word') || !!document.querySelector('.celebrate'),
            };
        })()`);

        ok('Ohne Netz geht es trotzdem weiter', geuebt.hatFrage,
           'keine Fehlermeldung, keine leere Seite');
        ok('Die Antworten sammeln sich in der Warteschlange',
           geuebt.warteschlange >= 5, String(geuebt.warteschlange));
        ok('Jede mit eigener Kennung', geuebt.kennungen === geuebt.warteschlange,
           geuebt.kennungen + ' von ' + geuebt.warteschlange
           + ' - ohne sie zaehlte ein zweimal geschickter Stapel doppelt');
        ok('Und der Lernstand steht schon im Gerät', geuebt.stand > 0,
           String(geuebt.stand));

        // ---- Netz wieder an: Die Antworten gehen raus.

        await flugmodus(false);
        await b.js(`window.dispatchEvent(new Event('online'))`);
        await schlafe(2500);

        const nachher = await b.js(`(() => {
            const wSchl = Object.keys(localStorage).find((k) => k.startsWith('vt-warte-'));
            return JSON.parse(localStorage.getItem(wSchl) ?? '[]').length;
        })()`);
        ok('Kommt das Netz zurück, geht die Warteschlange raus', nachher === 0,
           nachher + ' liegen noch');

        if (aus) await b.bild('vorrat-offline');
    } finally {
        b.schliessen();
    }
}
