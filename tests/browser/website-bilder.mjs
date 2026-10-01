/*
 * Die Bildschirmfotos der Startseite: website/bilder/*.webp.
 *
 *     node tests/browser/website-bilder.mjs
 *
 * Kein Test, sondern ein Werkzeug wie voki-symbol.mjs. Braucht den laufenden
 * Entwicklungsserver (php -S 127.0.0.1:8123 -t . tests/router.php) und legt
 * sich seine Vorführklasse selbst an (tests/browser/demo.php) - und räumt sie
 * danach wieder weg. Nach einer sichtbaren Änderung an der App einmal laufen
 * lassen und die Bilder mit einchecken, sonst zeigt die Startseite eine App,
 * die es so nicht mehr gibt.
 *
 * Fotografiert wird im Browser, nicht nachgezeichnet: Die Startseite soll
 * genau das zeigen, was ein Kind und eine Lehrkraft sehen.
 *
 * Zwei Kniffe:
 *
 * - Vor jedem Foto wird das Fenster so hoch wie der gewünschte Ausschnitt.
 *   Chrome kann zwar auch über das Fenster hinaus fotografieren
 *   (captureBeyondViewport), ändert dafür aber kurz die Fenstergrösse - und
 *   alles, was die Seite nach dem Fenster ausrichtet, steht auf dem Foto
 *   dann falsch. Der Freigabebalken der Lehrkraft lag so mitten in einer
 *   Zeile statt zwischen zweien.
 * - Die Uhr der Übung läuft weiter: Nach einer richtigen Antwort kommt nach
 *   700 ms die nächste Frage. Das Foto mit dem grünen Knopf muss also
 *   vorher entstehen.
 */

import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { browser, alsKind, alsLehrkraft, schlafe } from './browser.mjs';

const repo  = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const ziel  = resolve(repo, 'website', 'bilder');
const basis = process.env.VOKIDOKI_BASIS ?? 'http://127.0.0.1:8123/app';

mkdirSync(ziel, { recursive: true });

const php = (code) => execFileSync('php', ['-r', code], { cwd: resolve(repo, 'app'), encoding: 'utf8' });
const d   = JSON.parse(execFileSync('php', ['tests/browser/demo.php'], { cwd: repo, encoding: 'utf8' }));

/*
 * Auf Zettel und QR-Code steht die Adresse, unter der dieser Server läuft -
 * hier 127.0.0.1. Auf der Startseite muss es die echte sein: Wer den Code
 * vom Bildschirm abfotografiert, soll bei Vokidoki landen, nicht nirgends.
 */
const ADRESSE = 'https://vokidoki.de/app/';
const qrEcht  = php(`require 'lib/qr.php'; echo qr_svg('${ADRESSE}', 4, 'Adresse der App');`);

/**
 * Adresse und QR-Code in der Seite gegen die echten tauschen - und das
 * Präfix der Vorführschule weg, das nur in der Datenbank etwas zu sagen hat.
 */
const echteAdresse = (b) => b.js(`(() => {
    const qr = ${JSON.stringify(qrEcht)};
    document.querySelectorAll('svg').forEach((s) => {
        if (s.closest('.qr, .qrslot')) s.outerHTML = qr;
    });
    const gang = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    while (gang.nextNode()) {
        gang.currentNode.nodeValue = gang.currentNode.nodeValue
            .replace(/https?:\\/\\/127\\.0\\.0\\.1:\\d+\\/app\\//g, ${JSON.stringify(ADRESSE)})
            .replace('VORFUEHRUNG ', '');
    }
})()`);
const sprache = Number(php(`require 'lib/db.php'; echo qv('SELECT language_id FROM courses WHERE id = ?', [${d.kursFr}]);`));

// Die Lösungen der Lückensätze, nach dem deutschen Satz - so weiss das Skript,
// was es eintippen muss, ohne in die Übung hineinzugreifen.
const loesungen = JSON.parse(php(`require 'lib/db.php';
    echo json_encode(array_column(qa('SELECT s.native_text, s.answer FROM sentences s
        JOIN vocab v ON v.id = s.vocab_id JOIN units t ON t.id = v.unit_id
        WHERE t.course_id = ?', [${d.kursFr}]), 'answer', 'native_text'));`));

/**
 * Ein Foto als WebP.
 *
 * @param bereich  null = das ganze Fenster; sonst ein CSS-Selektor, dessen
 *                 Rechteck (mit etwas Rand) fotografiert wird, oder
 *                 { von, bis } - vom oberen Rand des einen bis zum unteren
 *                 des anderen Elements.
 */
async function foto(b, name, { breite, hoehe, dpr = 2, bereich = null, rand = 16, extra = 0 }) {
    // Ein Rollbalken am Rand gehört nicht auf ein Foto der Oberfläche.
    await b.send('Emulation.setScrollbarsHidden', { hidden: true });
    await b.send('Emulation.setDeviceMetricsOverride',
                 { width: breite, height: hoehe, deviceScaleFactor: dpr, mobile: breite < 700 });
    await schlafe(500);

    let clip = { x: 0, y: 0, width: breite, height: hoehe, scale: 1 };
    if (bereich !== null) {
        const r = await b.js(`(() => {
            const b = ${JSON.stringify(bereich)};
            const oben = document.querySelector(typeof b === 'string' ? b : b.von);
            const unten = document.querySelector(typeof b === 'string' ? b : b.bis);
            if (!oben || !unten) return null;
            const o = oben.getBoundingClientRect(), u = unten.getBoundingClientRect();
            return { y: o.top + scrollY, h: u.bottom - o.top };
        })()`);
        if (r === null) throw new Error(`${name}: Bereich ${JSON.stringify(bereich)} nicht gefunden`);
        const y = Math.max(0, r.y - rand);
        clip = { x: 0, y, width: breite, height: Math.ceil(r.h + 2 * rand + extra), scale: 1 };
        // Das Fenster so hoch wie nötig - siehe oben.
        await b.send('Emulation.setDeviceMetricsOverride',
                     { width: breite, height: Math.ceil(y + clip.height), deviceScaleFactor: dpr,
                       mobile: breite < 700 });
        await schlafe(500);
    }

    const r = await b.send('Page.captureScreenshot', { format: 'webp', quality: 86, clip });
    if (!r.result?.data) throw new Error(`${name}: ${JSON.stringify(r.error ?? r)}`);
    writeFileSync(resolve(ziel, name + '.webp'), Buffer.from(r.result.data, 'base64'));
    console.log('  ' + name + '.webp');
}

const TELEFON = { breite: 390, hoehe: 844 };
const RECHNER = { breite: 1280, hoehe: 820, dpr: 1.5 };

try {
    // ------------------------------------------------------------ Kinder
    const k = await browser({ port: 9471, breite: 390, hoehe: 844, handy: true });
    try {
        await alsKind(k, basis, d.kind, d.passwort);

        await k.hash(`/lang/${sprache}`, 1500);
        await foto(k, 'kind-kurs', TELEFON);

        await k.hash(`/unit/${d.unit2}`, 1500);
        await foto(k, 'kind-einheit', TELEFON);

        // Auswählen: die richtige Antwort antippen, fotografieren, bevor es weitergeht.
        await k.hash(`/quiz/${d.unit2}`, 1500);
        await k.js(`(async () => {
            const v = await import('${basis}/vorrat.js');
            const wort = document.querySelector('.prompt .word').textContent.trim();
            const w = v.vokabelnDerEinheit(${d.unit2}).find((x) => x.f === wort || x.n === wort);
            const loesung = w.f === wort ? w.n : w.f;
            [...document.querySelectorAll('.option')]
                .find((o) => o.textContent.trim() === loesung).click();
        })()`);
        await schlafe(120);
        await k.send('Emulation.setDeviceMetricsOverride',
                     { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
        const antwort = await k.send('Page.captureScreenshot', { format: 'webp', quality: 86 });
        writeFileSync(resolve(ziel, 'kind-auswaehlen.webp'), Buffer.from(antwort.result.data, 'base64'));
        console.log('  kind-auswaehlen.webp');

        // Lückentext: die Lösung tippen und prüfen lassen.
        await k.hash('/', 600);
        await k.hash(`/cloze/${d.unit2}`, 2000);
        await k.js(`(() => {
            const loesungen = ${JSON.stringify(loesungen)};
            const deutsch = [...document.querySelectorAll('.cloze-native, .native, p, div')]
                .map((e) => e.textContent.trim())
                .find((t) => loesungen[t] !== undefined);
            const feld = document.getElementById('answer');
            feld.value = loesungen[deutsch];
            feld.dispatchEvent(new Event('input', { bubbles: true }));
            document.getElementById('check').click();
        })()`);
        await schlafe(250);
        await k.send('Emulation.setDeviceMetricsOverride',
                     { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
        const luecke = await k.send('Page.captureScreenshot', { format: 'webp', quality: 86 });
        writeFileSync(resolve(ziel, 'kind-lueckentext.webp'), Buffer.from(luecke.result.data, 'base64'));
        console.log('  kind-lueckentext.webp');

        await k.hash('/', 600);
        await k.hash('/konto', 1500);
        await foto(k, 'kind-serie', { ...TELEFON, bereich: { von: 'h2.section', bis: '.card' } });
    } finally {
        await k.schliessen();
    }

    // ------------------------------------------------------------ Lehrkraft
    const l = await browser({ port: 9472, breite: 1280, hoehe: 820 });
    try {
        await alsLehrkraft(l, basis, d.lehrer, d.passwort);

        await l.geh(`${basis}/teacher/`, 1500);
        await foto(l, 'lehrer-kurse', RECHNER);

        await l.geh(`${basis}/teacher/unit.php?id=${d.unit2}`, 2000);
        await foto(l, 'lehrer-freigabe', { ...RECHNER, bereich: { von: 'h1', bis: '.releasebar' }, rand: 24,
                                          extra: 150 });

        // Der QR-Code, mit dem das Telefon ohne Anmeldung ins Einlesen kommt.
        await l.js(`document.getElementById('perQr').click()`);
        await schlafe(1500);
        await echteAdresse(l);
        await foto(l, 'lehrer-qr', RECHNER);
        await l.js(`document.getElementById('handoff').close()`);

        await l.geh(`${basis}/teacher/class.php?id=${d.klasse}`, 1500);
        await foto(l, 'lehrer-klasse', { ...RECHNER, hoehe: 900 });

        /*
         * Zettel gibt es nur für frisch vergebene Passwörter - danach sieht
         * die Lehrkraft nicht mehr, wer seines geändert hat. Also für die
         * Vorführklasse neue vergeben; sie wird am Ende ohnehin weggeräumt.
         */
        await l.js(`window.confirm = () => true; document.querySelector('[name="reset_all"]').click()`);
        await schlafe(1500);
        await l.geh(`${basis}/teacher/print.php?class=${d.klasse}`, 1500);
        await echteAdresse(l);
        await foto(l, 'lehrer-zettel', { breite: 900, hoehe: 900, dpr: 1.5,
                                         bereich: { von: 'section.blatt .kopf', bis: 'section.blatt .fuss' },
                                         rand: 40 });

        await l.geh(`${basis}/teacher/meldungen.php`, 1500);
        await foto(l, 'lehrer-meldung', { ...RECHNER, bereich: { von: 'h1', bis: 'main .card, .meldung' }, rand: 24 });
    } finally {
        await l.schliessen();
    }

    // Einlesen am Telefon: der Prüfschritt nach dem Foto der Vokabelliste -
    // mit einer Zeile, die die KI berichtigt hat und die gelb markiert ist.
    const t = await browser({ port: 9473, breite: 390, hoehe: 844, handy: true });
    try {
        await alsLehrkraft(t, basis, d.lehrer, d.passwort);
        await t.geh(`${basis}/`, 1200);
        await t.js(`localStorage.setItem('vt-draft-${sprache}', JSON.stringify({
            title: 'Unité 4 – Mes loisirs',
            entries: [
                { foreign: 'le loisir', native: 'die Freizeit', word_type: 'substantiv' },
                { foreign: 'jouer au foot', native: 'Fußball spielen', word_type: 'verb' },
                { foreign: 'la plage', native: 'der Strand', word_type: 'substantiv',
                  correction: 'der Stand → der Strand' },
                { foreign: 'nager', native: 'schwimmen', word_type: 'verb' },
                { foreign: 'le vélo', native: 'das Fahrrad', word_type: 'substantiv' },
                { foreign: 'Qu\\'est-ce que tu fais ?', native: 'Was machst du?', word_type: 'frage' },
                { foreign: 'souvent', native: 'oft', word_type: 'adverb' },
            ],
        }))`);
        await t.geh(`${basis}/#/lang/${sprache}/import`, 2200);
        await foto(t, 'lehrer-einlesen', TELEFON);
        await t.js(`localStorage.removeItem('vt-draft-${sprache}')`);
    } finally {
        await t.schliessen();
    }
} finally {
    execFileSync('php', ['tests/browser/demo.php', 'weg'], { cwd: repo });
}

console.log(`Fertig: ${ziel}`);
