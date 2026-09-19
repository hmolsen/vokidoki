/*
 * Ein Browser, ferngesteuert - der Teil, den alle Prüfungen teilen.
 *
 * Warum es das gibt: Die vier PHP-Suiten sehen HTML. Sie können nicht
 * sehen, ob ein Balken sich ziehen lässt, ob ein Bild wirklich ankommt oder
 * ob ein Knopf nach dem Eintippen freigegeben wird. Genau dort lagen drei
 * Fehler, die wochenlang niemandem aufgefallen wären:
 *
 *   - Der Freigabebalken verlor beim Verschieben seine Zeigerbindung und
 *     liess sich um genau eine Vokabel bewegen.
 *   - Die Fahnen standen als <img> im Quelltext - ob die Datei dahinter
 *     existiert, verrät der Quelltext nicht.
 *   - Der Zettel für die ganze Klasse wurde erst beim nächsten Laden
 *     anklickbar.
 *
 * Gesteuert wird über das DevTools-Protokoll, ohne Fremdpaket: Node bringt
 * seit Fassung 22 einen WebSocket mit, und Chrome bringt das Protokoll mit.
 * Das Projekt bekommt dadurch keine Abhängigkeit, die per composer oder npm
 * nachgezogen werden müsste - nur die Voraussetzung, dass auf DIESEM Rechner
 * Node und Chrome liegen. Deshalb ist das hier die fünfte Suite und keine
 * der vier.
 */

import { spawn } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

/** Wo Chrome liegen könnte. Der erste Treffer gewinnt. */
const CHROME_ORTE = [
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    process.env.LOCALAPPDATA + '/Google/Chrome/Application/chrome.exe',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
];

export function chromePfad() {
    const treffer = CHROME_ORTE.find((p) => p && existsSync(p));
    if (!treffer) {
        throw new Error(
            'Kein Chrome gefunden. Diese Suite braucht einen installierten '
            + 'Chrome; die vier PHP-Suiten brauchen ihn nicht.',
        );
    }
    return treffer;
}

export const schlafe = (ms) => new Promise((r) => setTimeout(r, ms));

// ------------------------------------------------------------------ Zählwerk

let fehler = 0;
let geprueft = 0;

export function ok(text, bedingung, zusatz = '') {
    geprueft++;
    if (!bedingung) fehler++;
    console.log((bedingung ? '  \u2713 ' : '  \u2717 ') + text
                + (bedingung || !zusatz ? '' : ' - ' + zusatz));
}

export function abschnitt(name) {
    console.log('\n' + name);
}

export function bilanz() {
    console.log('\n' + '-'.repeat(52));
    console.log(`${geprueft - fehler} bestanden, ${fehler} fehlgeschlagen`);
    return fehler;
}

// ------------------------------------------------------------------ Browser

/**
 * Startet einen Browser und liefert die Fernbedienung dazu.
 *
 * @param breite  Fensterbreite. MUSS vor dem ersten Laden feststehen - wer
 *                sie später umstellt, verwirft in manchen Fassungen die
 *                Adresse mitsamt Hash, und die Anwendung sieht dann aus, als
 *                hätte sie ein Weiterleitungsproblem. Sie hat keines; das
 *                hat mich eine Stunde gekostet.
 */
export async function browser({ port = 9400, breite = 1200, hoehe = 1000,
                                handy = false, aus = null } = {}) {
    const profil = mkdtempSync(join(tmpdir(), 'vtbrowser-'));
    const proc = spawn(chromePfad(), [
        '--headless=new',
        `--remote-debugging-port=${port}`,
        `--user-data-dir=${profil}`,
        '--no-first-run', '--no-default-browser-check', '--disable-gpu',
        'about:blank',
    ], { stdio: 'ignore' });

    for (let i = 0; i < 150; i++) {
        try {
            if ((await fetch(`http://127.0.0.1:${port}/json/version`)).ok) break;
        } catch { /* noch nicht da */ }
        await schlafe(100);
    }

    const ziel = await (await fetch(
        `http://127.0.0.1:${port}/json/new?about:blank`, { method: 'PUT' },
    )).json();

    const ws = new WebSocket(ziel.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });

    let id = 0;
    const offen = new Map();
    ws.onmessage = (m) => {
        const d = JSON.parse(m.data);
        if (d.id !== undefined) { offen.get(d.id)?.(d); offen.delete(d.id); }
    };

    const send = (method, params = {}) => {
        const i = ++id;
        ws.send(JSON.stringify({ id: i, method, params }));
        return new Promise((res) => offen.set(i, res));
    };

    await send('Page.enable');
    await send('Runtime.enable');
    await send('Emulation.setDeviceMetricsOverride',
               { width: breite, height: hoehe, deviceScaleFactor: 2, mobile: handy });

    /** JavaScript in der Seite auswerten. Wirft, wenn die Seite wirft. */
    const js = async (ausdruck) => {
        const antwort = await send('Runtime.evaluate', {
            expression: ausdruck, returnByValue: true, awaitPromise: true,
        });
        const r = antwort.result;
        if (r.exceptionDetails) {
            throw new Error('In der Seite: '
                + (r.exceptionDetails.exception?.description
                   ?? r.exceptionDetails.text));
        }
        return r.result.value;
    };

    /*
     * Eine Adresse ansteuern.
     *
     * Achtung: Unterscheidet sich das Ziel nur im Hash, ist das KEINE neue
     * Seite - das Dokument wird nicht neu geladen, und was vorher im
     * Speicher stand (etwa eine noch nicht bekannte Anmeldung), bleibt
     * stehen. Deshalb gibt es daneben hash().
     */
    const geh = async (url, warte = 1200) => {
        await send('Page.navigate', { url });
        await schlafe(warte);
    };

    const neuLaden = async (warte = 1500) => {
        await send('Page.reload', { ignoreCache: true });
        await schlafe(warte);
    };

    /** Nur den Hash setzen - löst in der App route() aus. */
    const hash = async (pfad, warte = 1200) => {
        await js(`location.hash = ${JSON.stringify(pfad)}`);
        await schlafe(warte);
    };

    const maus = (type, x, y) => send('Input.dispatchMouseEvent', {
        type, x, y, button: 'left',
        buttons: type === 'mouseReleased' ? 0 : 1,
        clickCount: 1, pointerType: 'mouse',
    });

    const taste = async (key, code) => {
        await send('Input.dispatchKeyEvent', { type: 'rawKeyDown', key, windowsVirtualKeyCode: code });
        await send('Input.dispatchKeyEvent', { type: 'keyUp', key, windowsVirtualKeyCode: code });
    };

    /*
     * Ein Bildschirmfoto - nur wenn ein Ordner dafuer angegeben wurde.
     *
     * send() liefert die ganze Antwort des Protokolls, nicht ihren Inhalt:
     * Die Bilddaten stehen unter .result.data, nicht unter .data. Ohne den
     * Zwischenschritt kam hier immer undefined an - gemerkt hat das lange
     * niemand, weil ohne Ordner gar nicht fotografiert wird.
     */
    const bild = async (name) => {
        if (!aus) return;
        const r = await send('Page.captureScreenshot',
                             { format: 'png', captureBeyondViewport: true });
        const daten = r.result?.data;
        if (!daten) {
            throw new Error('Bildschirmfoto "' + name + '" misslungen: '
                            + JSON.stringify(r.error ?? r));
        }
        writeFileSync(join(aus, name + '.png'), Buffer.from(daten, 'base64'));
    };

    const groesse = (w, h) => send('Emulation.setDeviceMetricsOverride',
                                   { width: w, height: h, deviceScaleFactor: 2, mobile: w < 700 });

    const schliessen = () => {
        try { ws.close(); } catch { /* egal */ }
        proc.kill();
        try { rmSync(profil, { recursive: true, force: true }); } catch { /* egal */ }
    };

    return { send, js, geh, neuLaden, hash, maus, taste, bild, groesse, schliessen };
}

// ------------------------------------------------------------------ Anmelden

/** Als Lehrkraft in den Lehrkraft-Bereich - über das Formular, wie ein Mensch. */
export async function alsLehrkraft(b, basis, nutzer, passwort) {
    await b.geh(basis + '/teacher/', 900);
    await b.js(`(() => {
        const f = document.querySelector('form');
        f.querySelector('[name=username]').value = ${JSON.stringify(nutzer)};
        f.querySelector('[name=password]').value = ${JSON.stringify(passwort)};
        f.querySelector('button').click();
    })()`);
    await schlafe(1300);
}

/**
 * In die App - über dieselbe Schnittstelle, die die App selbst benutzt.
 *
 * Danach wird HART neu geladen: Die Anmeldung setzt ein Cookie, aber die
 * laufende Seite kennt VT.user noch als null, und route() schickt einen
 * dann auf die Anmeldeseite.
 */
export async function alsKind(b, basis, nutzer, passwort) {
    await b.geh(basis + '/', 1200);
    await b.js(`fetch('${basis}/api/auth.php?action=login', {
        method: 'POST',
        headers: { 'X-Vokabeltrainer': '1', 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: ${JSON.stringify(nutzer)},
                               password: ${JSON.stringify(passwort)} }),
        credentials: 'same-origin',
    }).then((r) => r.json())`);
    await b.neuLaden();
}
