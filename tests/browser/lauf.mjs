/*
 * Die fünfte Suite - die einzige, die einen Browser braucht.
 *
 *   php tests/browser/fixture.php > /tmp/f.json
 *   node tests/browser/lauf.mjs /tmp/f.json [bilderordner]
 *
 * Oder in einem Zug:
 *
 *   node tests/browser/lauf.mjs --fixture [bilderordner]
 *
 * Voraussetzungen: Node (mit eingebautem WebSocket, also ab Fassung 22) und
 * ein installiertes Chrome. Beides braucht KEINE der vier PHP-Suiten - wer
 * nur die fährt, merkt von dieser Datei nichts.
 *
 * Der Bestand wird am Ende wieder weggeräumt, auch wenn eine Prüfung
 * umfällt.
 */

import { execFileSync } from 'node:child_process';
import { readFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { bilanz } from './browser.mjs';
import { pruefe as anmeldung }  from './anmeldung.mjs';
import { pruefe as navigation } from './navigation.mjs';
import { pruefe as freigabe } from './freigabe.mjs';
import { pruefe as einlesen } from './einlesen.mjs';
import { pruefe as vokabeln } from './vokabeln.mjs';
import { pruefe as fahnen }   from './fahnen.mjs';
import { pruefe as klasse }   from './klasse.mjs';
import { pruefe as suche }    from './suche.mjs';
import { pruefe as mobil }    from './mobil.mjs';
import { pruefe as stapel }   from './stapel.mjs';
import { pruefe as vorrat, pruefeKaltstart, pruefeFreigabeKommtAn } from './vorrat.mjs';
import { pruefe as menues }  from './menues.mjs';
import { pruefe as sortieren } from './sortieren.mjs';
import { pruefe as kursanlegen } from './kursanlegen.mjs';
import { pruefe as feiern } from './feiern.mjs';
import { pruefe as melden } from './melden.mjs';
import { pruefe as weiter } from './weiter.mjs';
import { pruefe as fassung } from './fassung.mjs';
import { pruefe as ocr } from './ocr.mjs';
import { pruefe as einsetzen } from './einsetzen.mjs';
import { pruefe as hoeren, pruefeOhneNetz as hoerenOhneNetz } from './hoeren.mjs';
import { pruefe as erzeugung } from './erzeugung.mjs';
import { pruefe as fortschritt } from './fortschritt.mjs';
import { pruefe as warten } from './warten.mjs';
import { pruefe as wortton } from './wortton.mjs';
import { pruefe as fehler } from './fehler.mjs';
import { pruefe as lehrkraefte } from './lehrkraefte.mjs';
import { pruefe as geraete } from './geraete.mjs';

const hier  = dirname(fileURLToPath(import.meta.url));
// Die Anwendung: dort liegen lib/ und assets/, von dort laufen die php-Aufrufe.
const wurzel = resolve(hier, '..', '..', 'app');

const [arg, bildOrdner] = process.argv.slice(2);

let f;
let selbstAngelegt = false;

if (!arg || arg === '--fixture') {
    const roh = execFileSync('php', [resolve(hier, 'fixture.php')],
                             { cwd: wurzel, encoding: 'utf8' });
    f = JSON.parse(roh);
    selbstAngelegt = true;
} else {
    f = JSON.parse(readFileSync(arg, 'utf8'));
}

const aus = bildOrdner ?? null;
if (aus) mkdirSync(aus, { recursive: true });

console.log(`Browser-Pruefungen gegen ${f.basis}`);

try {
    await anmeldung(f, aus);
    await navigation(f, aus);
    await freigabe(f, aus);
    await einlesen(f, aus);
    await vokabeln(f, aus);
    await fahnen(f, aus);
    await klasse(f, aus);
    await suche(f, aus);
    await mobil(f, aus);
    await stapel(f, aus);
    await vorrat(f, aus);
    await pruefeKaltstart(f, aus);
    await pruefeFreigabeKommtAn(f, aus);
    await menues(f, aus);
    await sortieren(f, aus, wurzel);
    await fassung(f, aus, wurzel);
    await ocr(f, aus, wurzel);
    await einsetzen(f, aus, wurzel);
    await hoeren(f, aus, wurzel);
    await hoerenOhneNetz(f, aus, wurzel);
    await erzeugung(f, aus, wurzel);
    await fortschritt(f, aus, wurzel);
    await warten(f, aus, wurzel);
    await wortton(f, aus, wurzel);
    await fehler(f, aus, wurzel);
    await lehrkraefte(f, aus);
    await geraete(f, aus);
    /*
     * Spaet: gibt in der Lerneinheit nur noch EINE Vokabel frei und raeumt
     * den Lernstand weg. Wer davor zaehlt, zaehlt sonst etwas anderes.
     */
    await feiern(f, aus, wurzel);
    await melden(f, aus, wurzel);
    // Nach den Feiern: setzt den Lernstand der ersten Vokabel von Hand und
    // nimmt der Lerneinheit am Ende die Sätze.
    await weiter(f, aus, wurzel);
    // Zuletzt: legt einen Kurs an, und die Navigation zaehlt vorher Karten.
    await kursanlegen(f, aus);
} finally {
    if (selbstAngelegt) {
        execFileSync('php', [resolve(hier, 'fixture.php'), 'weg'],
                     { cwd: wurzel, encoding: 'utf8' });
    }
}

process.exit(bilanz() === 0 ? 0 : 1);
