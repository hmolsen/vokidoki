/*
 * Texterkennung im Browser - die Fotos verlassen das Gerät nicht.
 *
 * Bis hierher gingen die Fotos der Buchseiten an die KI. Das war bequem,
 * aber es war eine Kopie der ganzen Seite bei einem fremden Anbieter - und
 * genau das untersagen die Schulbuchverlage (schulbuchkopie.de, "Urheberrecht
 * und KI"). Jetzt liest Tesseract die Seite hier, im Browser der Lehrkraft,
 * und weitergegeben wird nur der erkannte Text.
 *
 * Tesseract.js liegt unter ocr/, nicht auf einem fremden Server: Die Seite
 * soll ohne Dritte auskommen, und eine Schule soll nicht erklären müssen,
 * warum beim Einlesen ein CDN in den USA gefragt wird. Geladen wird es erst
 * beim ersten Einlesen - wer nie einliest, lädt es nie. Die Sprachdateien
 * hebt Tesseract selbst im Browser auf (IndexedDB), beim zweiten Mal kommen
 * sie von dort.
 *
 * Ein Modul ohne Abhängigkeiten, wie lesevoki.js: Die Einleseansicht der
 * App importiert es, die Lerneinheit im Lehrkraft-Bereich lädt es nach.
 */

const ORDNER = new URL('./ocr/', import.meta.url).href;

/*
 * Sprachkürzel der App -> Sprachdatei von Tesseract. Gelesen wird immer
 * mit Deutsch dazu: Auf einer Vokabelseite steht die Hälfte auf Deutsch,
 * und ohne "deu" wird aus "Löffel" gern "Loffel".
 *
 * Eine Sprache ohne eigene Datei liest Englisch mit. Für Sprachen mit
 * lateinischer Schrift geht das leidlich, und die KI danach berichtigt,
 * was dabei schiefgeht - sichtbar markiert.
 */
const SPRACHDATEI = { en: 'eng', de: 'deu', fr: 'fra', la: 'lat', da: 'dan', es: 'spa', it: 'ita' };

let geladen = null;

/** Lädt tesseract.min.js einmal - es legt window.Tesseract an. */
function tesseractLaden() {
    if (window.Tesseract) return Promise.resolve(window.Tesseract);
    geladen ??= new Promise((ok, fehler) => {
        const s = document.createElement('script');
        s.src = ORDNER + 'tesseract.min.js';
        s.onload = () => (window.Tesseract ? ok(window.Tesseract)
            : fehler(new Error('Die Texterkennung liess sich nicht starten.')));
        s.onerror = () => {
            geladen = null;
            fehler(new Error('Die Texterkennung liess sich nicht laden. Ist das Netz da?'));
        };
        document.head.append(s);
    });
    return geladen;
}

export function sprachenFuer(sprachcode) {
    const fremd = SPRACHDATEI[String(sprachcode || '').toLowerCase()] ?? 'eng';
    return fremd === 'deu' ? ['deu'] : ['deu', fremd];
}

/**
 * Liest die Bilder und gibt den Text zeilenweise zurück, Spalten durch
 * Tabulator getrennt, jede Seite mit einer Kopfzeile "Seite n".
 *
 * bilder:     Data-URLs, Blobs oder Canvas - alles, was Tesseract nimmt
 * sprachcode: das Kürzel der Sprache ('en', 'fr', ...)
 * fortschritt(seite, seiten, anteil): für die Anzeige, anteil 0..1
 */
export async function texterkennung(bilder, sprachcode, { fortschritt } = {}) {
    const Tesseract = await tesseractLaden();

    let seite = 0;
    const worker = await Tesseract.createWorker(sprachenFuer(sprachcode), Tesseract.OEM.LSTM_ONLY, {
        workerPath: ORDNER + 'worker.min.js',
        corePath:   ORDNER,
        langPath:   ORDNER + 'sprachen',
        logger: (m) => {
            if (m.status === 'recognizing text' && fortschritt) {
                fortschritt(seite + 1, bilder.length, m.progress ?? 0);
            }
        },
    });

    try {
        /*
         * Leerzeichen zwischen Wörtern erhalten - daran erkennt zeilenBauen()
         * die Spalten, wenn Tesseract eine Zeile nicht selbst getrennt hat.
         *
         * Und die Seite selbst gliedern lassen (AUTO). Tesseract.js liest von
         * sich aus alles als einen einzigen Textblock (SINGLE_BLOCK) - steht
         * dann etwas anderes als Text auf der Seite, geht der Block verloren.
         * Eine Bildschirmkopie mit zwei Symbolen neben der Überschrift ergab
         * so nur "76 Uber sich selbst sprechen / OO.": die Tabelle darunter
         * fehlte ganz, und die KI fand "keine Vokabeln". Die Zeilen setzt
         * ohnehin zeilenBauen() nach der Lage wieder zusammen.
         */
        await worker.setParameters({
            preserve_interword_spaces: '1',
            tessedit_pageseg_mode: Tesseract.PSM.AUTO,
        });

        const teile = [];
        for (seite = 0; seite < bilder.length; seite++) {
            const { data } = await worker.recognize(bilder[seite], {}, { blocks: true });
            teile.push(`Seite ${seite + 1}`, ...zeilenBauen(data.blocks ?? []));
        }
        return teile.join('\n');
    } finally {
        await worker.terminate();
    }
}

/**
 * Aus Wörtern mit Lage wieder die Zeilen der Liste bauen.
 *
 * Tesseract liest eine zweispaltige Vokabelseite gern spaltenweise: erst
 * alle englischen Wörter, dann alle deutschen. Die Paare wären dann nur
 * noch über die Reihenfolge zu erraten. Deshalb wird hier nach der Höhe auf
 * der Seite neu zusammengesetzt: Was auf gleicher Höhe steht, ist eine
 * Zeile; ein breiter Abstand dazwischen ist ein Spaltenwechsel (Tabulator).
 *
 * Exportiert für die Browser-Prüfung, die es mit erfundenen Wörtern füttert.
 */
export function zeilenBauen(blocks) {
    // Die Zeilen von Tesseract, jeweils an breiten Lücken in Stücke geteilt.
    const stuecke = [];
    const hoehen  = [];
    for (const block of blocks) {
        for (const absatz of block.paragraphs ?? []) {
            for (const zeile of absatz.lines ?? []) {
                const woerter = (zeile.words ?? [])
                    .filter((w) => (w.text ?? '').trim() !== '')
                    .sort((a, b) => a.bbox.x0 - b.bbox.x0);
                woerter.forEach((w) => hoehen.push(w.bbox.y1 - w.bbox.y0));
                if (woerter.length) stuecke.push(woerter);
            }
        }
    }
    if (!stuecke.length) return [];

    hoehen.sort((a, b) => a - b);
    const h = Math.max(1, hoehen[Math.floor(hoehen.length / 2)]);

    const teile = [];
    for (const woerter of stuecke) {
        let jetzt = [woerter[0]];
        for (let i = 1; i < woerter.length; i++) {
            // Ein Wortabstand ist etwa ein halbes Zeichen hoch, ein
            // Spaltenwechsel mehrere - das Anderthalbfache trennt sicher.
            if (woerter[i].bbox.x0 - woerter[i - 1].bbox.x1 > 1.5 * h) {
                teile.push(jetzt);
                jetzt = [];
            }
            jetzt.push(woerter[i]);
        }
        teile.push(jetzt);
    }

    const stueck = teile.map((ws) => ({
        text: ws.map((w) => w.text.trim()).join(' '),
        x0: Math.min(...ws.map((w) => w.bbox.x0)),
        y0: Math.min(...ws.map((w) => w.bbox.y0)),
        y1: Math.max(...ws.map((w) => w.bbox.y1)),
    })).sort((a, b) => (a.y0 + a.y1) - (b.y0 + b.y1));

    // Gleiche Höhe = gleiche Zeile: Mitte des Stücks innerhalb der Zeile.
    const reihen = [];
    for (const s of stueck) {
        const mitte = (s.y0 + s.y1) / 2;
        const reihe = reihen.find((r) => Math.abs(mitte - r.mitte) < 0.6 * h);
        if (reihe) {
            reihe.stuecke.push(s);
            reihe.mitte = (reihe.mitte * (reihe.stuecke.length - 1) + mitte) / reihe.stuecke.length;
        } else {
            reihen.push({ mitte, stuecke: [s] });
        }
    }

    return reihen
        .sort((a, b) => a.mitte - b.mitte)
        .map((r) => r.stuecke
            .sort((a, b) => a.x0 - b.x0)
            .map((s) => s.text)
            .filter((text) => !istLautschrift(text))
            .join('\t'))
        .filter((zeile) => zeile !== '');
}

/*
 * Lautschrift erst gar nicht weitergeben.
 *
 * Tesseract kennt die Zeichen der IPA nicht und liest "[ˈæpl]" als
 * Zeichensalat. Die KI hielt den dann für einen Lesefehler, "berichtigte"
 * ihn und markierte die Zeile - die Lehrkraft prüfte gelbe Zeilen, an
 * deren Wörtern nichts falsch war. Eine Spalte, die ganz in eckigen
 * Klammern oder Schrägstrichen steht, ist Lautschrift und fällt weg.
 * Übrig gebliebene Reste erkennt die KI selbst (vocab_prompt()).
 */
export function istLautschrift(text) {
    return /^\s*[[/].*[\]/]\s*$/.test(text);
}
