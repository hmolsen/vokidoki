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

import { spawn } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { browser, alsKind, alsLehrkraft, ok, abschnitt, schlafe } from './browser.mjs';

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

        // ---- Der Vergleich beim Lueckentext, gegen dieselben Faelle wie PHP.

        /*
         * Seit das Ueben ohne Netz laeuft, vergleicht das Geraet die
         * getippte Antwort selbst - die Loesung darf dafuer nicht erst beim
         * Server erfragt werden muessen. Damit gibt es die Regel zweimal, in
         * zwei Sprachen, und das ist ein Risiko: Wer hier eine Nachsicht
         * ergaenzt und dort nicht, laesst ein Kind vor zwei verschiedenen
         * Wahrheiten stehen - dieselbe Antwort zaehlt online anders als
         * offline.
         *
         * Dieselbe Sammlung prueft tests/sentences.php gegen answer_check().
         * Sie gehoert keiner der beiden Seiten; faellt eine auseinander,
         * faellt eine Suite um.
         */
        const faelle = JSON.parse(readFileSync(
            new URL('../faelle/antworten.json', import.meta.url), 'utf8'));

        const schief = await b.js(`(async () => {
            const { antwortPruefen } = await import('${f.basis}/vorrat.js');
            const faelle = ${JSON.stringify(faelle)};
            return faelle
                .map((fall) => {
                    const r = antwortPruefen(fall.getippt, fall.erwartet);
                    return (r.correct === fall.correct && r.exact === fall.exact)
                        ? null : fall.was + ': ' + JSON.stringify(r);
                })
                .filter(Boolean);
        })()`);

        ok('antwortPruefen() stimmt mit jedem Fall der Sammlung ueberein',
           Array.isArray(schief) && schief.length === 0,
           (schief ?? []).slice(0, 3).join(' | '));

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

        await flugmodus(false);
    } finally {
        b.schliessen();
    }
}

/*
 * Kaltstart: Die App oeffnet auch ohne Server.
 *
 * Das braucht einen eigenen Abschnitt, einen eigenen Server und eine eigene
 * Portnummer - und zwar aus einem Grund, der eine Stunde gekostet hat:
 * Network.emulateNetworkConditions schaltet nur die SEITE in den Flugmodus,
 * nicht den Service Worker. Der hat seinen eigenen Netzzugang und erreichte
 * den Server munter weiter. Der Abschnitt darueber lief deshalb in der
 * ersten Fassung gruen durch, auch als der Rueckfall auf den
 * zwischengespeicherten Rahmen testweise ganz entfernt war - er prueft das
 * Ueben ohne Netz, nicht das Oeffnen.
 *
 * Hier wird der Server also wirklich abgeschaltet. Dafuer ein eigener: Den
 * auf 8123 teilen sich alle anderen Pruefungen, und ein eigener Port ist
 * ausserdem eine eigene Herkunft - die Registrierung des Service Workers
 * faellt damit nicht mit der aus dem Abschnitt darueber zusammen.
 */
export async function pruefeKaltstart(f, aus) {
    abschnitt('Kaltstart ohne Server');

    const wurzel = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
    const port   = 8131;
    const basis  = `http://127.0.0.1:${port}`;

    const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', wurzel],
                         { cwd: wurzel, stdio: 'ignore' });
    let laeuft = true;
    const serverWeg = () => { if (laeuft) { laeuft = false; server.kill(); } };

    // Dem Server einen Augenblick geben, sonst laeuft der erste Abruf ins Leere.
    await schlafe(900);

    const b = await browser({ port: 9413, breite: 420, hoehe: 900, aus });
    try {
        await alsKind(b, basis, f.kind, f.passwort);
        await b.geh(basis + '/', 2200);

        const bereit = await b.js(`navigator.serviceWorker.ready
            .then(() => 'da').catch(() => 'keiner')`);
        ok('Ein Service Worker uebernimmt die Seite', bereit === 'da', bereit);

        // Noch einmal laden: Beim ersten Mal lief der Abruf am Service
        // Worker vorbei, er uebernimmt erst danach.
        await b.neuLaden(2000);

        ok('Der Seitenrahmen liegt im Zwischenspeicher',
           (await b.js(`caches.open('vokabeltrainer-v5')
                .then((c) => c.match('./?rahmen')).then((r) => !!r)`)) === true,
           'ohne ihn laedt ohne Netz nicht einmal die erste Seite');
        ok('Und der Vorrat auch',
           (await b.js(`Object.keys(localStorage).some((k) => k.startsWith('vt-vorrat-'))`))
           === true);

        // ---- Und jetzt ist der Server weg. Wirklich weg.

        serverWeg();
        await schlafe(1200);
        const tot = await b.js(`fetch('${basis}/api/meta.php?action=version',
            { headers: { 'X-Vokabeltrainer': '1' } })
            .then(() => 'kam durch').catch(() => 'nichts mehr da')`);
        ok('Der Server ist wirklich aus', tot === 'nichts mehr da', tot);

        await b.neuLaden(3000);
        const kalt = await b.js(`({
            titel:   document.title,
            kacheln: document.querySelectorAll('.tile[data-lang]').length,
            angemeldet: !!window.VT?.user,
        })`);

        ok('Die App oeffnet trotzdem',
           kalt.angemeldet && !kalt.titel.includes('Keine Verbindung'),
           kalt.titel);
        ok('Und die Kurse stehen da', kalt.kacheln > 0,
           kalt.kacheln + ' Kacheln - der Vorrat traegt sie');

        await b.geh(basis + '/#/quiz/' + f.unit, 2000);
        ok('Auch Ueben laeuft ohne Server',
           (await b.js(`document.querySelectorAll('.option').length`)) > 0,
           'vom Kaltstart bis zur ersten Frage ohne eine einzige Anfrage');

        if (aus) await b.bild('kaltstart');
    } finally {
        b.schliessen();
        serverWeg();
    }
}

/*
 * Die Freigabe kommt sofort in der Kinderansicht an.
 *
 * Der Fehler, den das hier festnagelt: Eine Lehrkraft gibt Vokabeln frei und
 * drückt „So sieht es die Klasse" - und sah dort ihren eigenen Vorrat von
 * vor vier Minuten, also die Freigabe von vorhin. Das sieht aus, als hätte
 * das Freigeben nicht gewirkt.
 *
 * Der Grund war eine Sparsamkeit: Der Vorrat wurde nur aufgefrischt, wenn er
 * älter als fünf Minuten war. Beim Kaltstart wird jetzt immer nachgesehen -
 * gewartet wird darauf nicht, aber wenn sich etwas geändert hat, zeichnet
 * die Ansicht gleich noch einmal.
 */
export async function pruefeFreigabeKommtAn(f, aus) {
    abschnitt('Freigabe kommt sofort an');

    const b = await browser({ port: 9418, breite: 1100, hoehe: 900, aus });
    try {
        await alsLehrkraft(b, f.basis, f.lehrer, f.passwort);

        const zaehle = async () => {
            await b.geh(f.basis + '/#/unit/' + f.unit, 2500);
            return b.js(`document.querySelectorAll('.row.vocab').length`);
        };

        /*
         * Erst alles zumachen, dann in der Kinderansicht nachsehen - damit
         * der Vorrat im Gerät einen Stand hat, der gleich veraltet.
         */
        const setzen = async (bis) => {
            await b.geh(f.basis + '/teacher/unit.php?id=' + f.unit, 1500);
            await b.js(`(() => {
                const form = document.getElementById('releaseform');
                const feld = document.createElement('input');
                feld.type = 'hidden'; feld.name = 'release'; feld.value = '${bis}';
                form.appendChild(feld);
                form.submit();
            })()`);
            await schlafe(2200);
        };

        await setzen(0);
        ok('Zugemacht sieht die Klasse nichts', (await zaehle()) === 0,
           String(await b.js(`document.querySelectorAll('.row.vocab').length`)));

        await setzen(3);
        ok('Und nach dem Freigeben sofort drei',
           (await zaehle()) === 3,
           await b.js(`document.querySelectorAll('.row.vocab').length`)
           + ' - der Vorrat war vier Minuten alt und galt als frisch genug');

        await setzen(5);
        ok('Eine zweite Freigabe kommt genauso an', (await zaehle()) === 5,
           String(await b.js(`document.querySelectorAll('.row.vocab').length`)));

        if (aus) await b.bild('freigabe-kommt-an');
    } finally {
        b.schliessen();
    }
}
