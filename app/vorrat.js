/*
 * Der Vorrat: alles zum Üben, im Gerät.
 *
 * Bis hierher holte die App jede Frage einzeln. Vokabel ziehen, Ablenker
 * würfeln, Antwort einschicken, nächste Frage - drei bis vier Runden übers
 * Netz je Wort, auf die ein Kind wartet. Im Schulhaus-WLAN war das spürbar,
 * und ein einziger Aussetzer mitten in der Runde wurde zu „Bist du online?".
 *
 * Jetzt andersherum: einmal alles holen, dann ohne Netz arbeiten. Die
 * Antworten sammeln sich in einer Warteschlange und gehen später am Stück
 * zurück.
 *
 * DIE FRAGE ENTSTEHT HIER, NICHT AUF DEM SERVER. Damit liegt auch die
 * richtige Antwort im Gerät, und ein neugieriges Kind kann sie nachsehen.
 * Das ist bewusst so: Es ist eine Lernhilfe, keine Klassenarbeit, und wer
 * sich die Lösung heraussucht, statt sie zu lernen, betrügt niemanden ausser
 * sich selbst.
 *
 * Gerechnet wird mit denselben Regeln wie auf dem Server - dieselbe Schwelle
 * für „gekonnt", dieselbe Serie, dieselbe Nachsicht bei Akzenten. Wo das
 * nicht ginge, stünde am Ende ein Kind vor zwei verschiedenen Wahrheiten.
 */

import { VT, api } from './core.js';

/* Ein Schlüssel je Konto: Auf einem geteilten Tablet üben zwei Kinder, und
   der Vorrat des einen geht den anderen nichts an. */
const vorratSchluessel = () => `vt-vorrat-${VT.user?.id ?? 0}`;
const warteSchluessel  = () => `vt-warte-${VT.user?.id ?? 0}`;

/** Vier Möglichkeiten je Frage - wie OPTION_COUNT in api/quiz.php. */
const ANZAHL_OPTIONEN = 4;

export const MODUS_WAHL  = 'mc';
export const MODUS_LUECKE = 'cloze';

/* Der geladene Vorrat, mit Registern darüber. Einmal je Seitenaufruf
   aufgebaut - JSON.parse über ein halbes Megabyte will man nicht je Frage. */
let vorrat = null;

// --------------------------------------------------------------- Speichern

function lesen(schluessel, ersatz) {
    try {
        const roh = localStorage.getItem(schluessel);
        return roh === null ? ersatz : JSON.parse(roh);
    } catch {
        // Kaputt oder gesperrt (privates Fenster) - dann eben ohne Vorrat.
        return ersatz;
    }
}

function schreiben(schluessel, wert) {
    try {
        localStorage.setItem(schluessel, JSON.stringify(wert));
        return true;
    } catch {
        /*
         * Voll oder gesperrt. Kein Grund, die App anzuhalten: Ohne Vorrat
         * geht alles wie früher, nur langsamer. Nur sagen muss man es
         * nicht - das Kind kann nichts dagegen tun.
         */
        return false;
    }
}

/**
 * Register über den rohen Daten.
 *
 * Das Bündel kommt als flache Listen - so ist es am kleinsten, und klein
 * zählt, weil es als Text im Gerät liegt. Zum Üben braucht es aber Zugriff
 * nach Einheit und nach Vokabel, und den baut man einmal auf statt je Frage
 * die ganze Liste zu durchlaufen.
 */
function register(daten) {
    const proEinheit = new Map();
    const proSprache = new Map();
    const vokabel    = new Map();

    for (const v of daten.vokabeln ?? []) {
        vokabel.set(v.i, v);
        if (!proEinheit.has(v.u)) proEinheit.set(v.u, []);
        proEinheit.get(v.u).push(v);
    }

    const einheit = new Map();
    for (const u of daten.einheiten ?? []) {
        einheit.set(u.i, u);
        if (!proSprache.has(u.l)) proSprache.set(u.l, []);
        proSprache.get(u.l).push(u);
    }

    // Die Vokabeln einer Sprache - der Nachschub für die Ablenker, wenn eine
    // Einheit für vier Möglichkeiten zu klein ist.
    const vokabelnDerSprache = new Map();
    for (const v of daten.vokabeln ?? []) {
        const l = einheit.get(v.u)?.l;
        if (l === undefined) continue;
        if (!vokabelnDerSprache.has(l)) vokabelnDerSprache.set(l, []);
        vokabelnDerSprache.get(l).push(v);
    }

    const saetze = new Map();
    for (const s of daten.saetze ?? []) {
        if (!saetze.has(s.v)) saetze.set(s.v, []);
        saetze.get(s.v).push(s);
    }

    // Der Lernstand als Karte, damit eine Antwort ihn an Ort und Stelle
    // fortschreiben kann.
    const stand = new Map();
    for (const p of daten.stand ?? []) {
        stand.set(`${p.v}:${p.m}`, p);
    }

    return { daten, proEinheit, proSprache, vokabel, einheit, vokabelnDerSprache,
             saetze, stand };
}

/** Den Vorrat aus dem Gerät holen. Null, wenn keiner da ist. */
export function vorratLaden() {
    if (vorrat !== null) return vorrat;
    const daten = lesen(vorratSchluessel(), null);
    if (daten === null || !Array.isArray(daten.vokabeln)) return null;
    vorrat = register(daten);
    return vorrat;
}

function sichern() {
    if (vorrat === null) return;
    // Der Lernstand lebt in der Karte - vor dem Schreiben zurück in die Liste.
    vorrat.daten.stand = [...vorrat.stand.values()];
    schreiben(vorratSchluessel(), vorrat.daten);
}

// ------------------------------------------------------------ Auffrischen

/**
 * Das Bündel holen und ablegen.
 *
 * Wirft nicht: Ohne Netz bleibt der alte Vorrat stehen, und mit ihm lässt
 * sich weiterüben. Genau dafür ist er da.
 *
 * Vor dem Holen geht die Warteschlange raus - sonst käme ein Lernstand
 * zurück, der die eigenen, noch nicht gemeldeten Antworten nicht kennt, und
 * überschriebe sie.
 */
export async function vorratAuffrischen() {
    await warteschlangeSenden();

    try {
        const frisch = await api('bundle', 'get');

        /*
         * Zurueckgemeldet wird, ob sich etwas GEAENDERT hat - nicht, ob der
         * Abruf durchging.
         *
         * Der Aufrufer zeichnet die Ansicht neu, wenn hier true steht.
         * Truege jeder erfolgreiche Abruf das, wuerde beim Ueben alle paar
         * Minuten die Frage neu gebaut, waehrend jemand davorsitzt - und
         * zwar ohne dass sich irgendetwas geaendert haette.
         *
         * Der Zeitstempel bleibt beim Vergleich aussen vor: Er ist bei
         * jedem Abruf ein anderer und saehe immer nach Aenderung aus.
         */
        const vorherText = vorrat === null
            ? null : JSON.stringify({ ...vorrat.daten, geholt: 0 });
        const frischText = JSON.stringify({ ...frisch, geholt: 0 });

        vorrat = register(frisch);
        schreiben(vorratSchluessel(), frisch);

        return vorherText !== frischText;
    } catch {
        return false;
    }
}

/** Wie alt ist der Vorrat, in Sekunden? Unendlich, wenn es keinen gibt. */
export function vorratAlter() {
    const v = vorratLaden();
    if (v === null) return Infinity;
    return Math.max(0, Math.floor(Date.now() / 1000) - (v.daten.geholt ?? 0));
}

/**
 * Beim Abmelden fällt der Vorrat weg - er gehört diesem Kind.
 *
 * Und der gespeicherte Seitenrahmen mit. Er trägt den Namen dieses Kindes;
 * wer sich abmeldet, soll ihn beim nächsten Start nicht wiedersehen, auch
 * nicht ohne Netz.
 */
export function vorratVergessen() {
    try {
        localStorage.removeItem(vorratSchluessel());
        localStorage.removeItem(warteSchluessel());
    } catch { /* egal */ }
    vorrat = null;

    try {
        // Der Service Worker fängt diese Adresse ab und räumt; ohne ihn
        // läuft sie ins Leere, und das ist dann auch richtig so.
        fetch(`${VT.base}/?rahmen-weg=1`, { credentials: 'same-origin' }).catch(() => {});
    } catch { /* egal */ }
}

// ----------------------------------------------------------------- Abfragen

export function sprachen() {
    return vorratLaden()?.daten.sprachen ?? [];
}

export function sprache(langId) {
    return sprachen().find((l) => l.id === Number(langId)) ?? null;
}

export function einheitenDerSprache(langId) {
    return vorratLaden()?.proSprache.get(Number(langId)) ?? [];
}

export function einheit(unitId) {
    return vorratLaden()?.einheit.get(Number(unitId)) ?? null;
}

export function vokabelnDerEinheit(unitId) {
    return vorratLaden()?.proEinheit.get(Number(unitId)) ?? [];
}

function standVon(vocabId, modus) {
    return vorratLaden()?.stand.get(`${vocabId}:${modus}`)
        ?? { v: vocabId, m: modus, s: 0, c: 0, w: 0, k: 0 };
}

/**
 * Wie weit ist diese Einheit?
 *
 * Für den Lückentext zählen nur Vokabeln mit Satz - eine ohne kann dort
 * nicht drankommen, und sie mitzuzählen hiesse, dass die Einheit nie fertig
 * wird.
 */
export function fortschritt(unitId, modus) {
    const v = vorratLaden();
    if (v === null) return { known: 0, total: 0 };

    const alle = vokabelnDerEinheit(unitId).filter(
        (w) => modus !== MODUS_LUECKE || (v.saetze.get(w.i)?.length ?? 0) > 0,
    );
    const gekonnt = alle.filter((w) => standVon(w.i, modus).k === 1).length;
    return { known: gekonnt, total: alle.length };
}

/**
 * Die Zahlen einer Lerneinheit - wie api/units.php sie liefert.
 *
 * Gezaehlt wird in Schritten, nicht in Vokabeln: Jede bringt einen Schritt
 * fuers Auswaehlen mit und einen zweiten fuers Einsetzen, sofern sie einen
 * Lueckensatz hat. Sonst stuende der Balken auf voll, waehrend im
 * Lueckentext noch alles offen ist.
 */
export function einheitStatistik(unitId) {
    const wahl   = fortschritt(unitId, MODUS_WAHL);
    const luecke = fortschritt(unitId, MODUS_LUECKE);

    const stepsTotal = wahl.total + luecke.total;
    const stepsDone  = wahl.known + luecke.known;

    return {
        id:          Number(unitId),
        title:       einheit(unitId)?.t ?? '',
        total:       wahl.total,
        known:       wahl.known,
        cloze_total: luecke.total,
        cloze_known: luecke.known,
        steps_total: stepsTotal,
        steps_done:  stepsDone,
        percent:     stepsTotal > 0 ? Math.round((stepsDone / stepsTotal) * 100) : 0,
        done:        stepsTotal > 0 && stepsDone >= stepsTotal,
    };
}

/**
 * Die Vokabelliste einer Einheit, mit beiden Lernstaenden - wie
 * api/units.php ?action=get sie liefert.
 *
 * "possible" beim Lueckentext heisst: Zu dieser Vokabel gibt es einen Satz.
 * Ohne einen kann sie dort nicht drankommen, und ein Haekchen, das nie
 * kommen kann, waere eine Aufgabe ohne Loesung.
 */
export function vokabelListe(unitId) {
    const v = vorratLaden();
    if (v === null) return [];

    return vokabelnDerEinheit(unitId).map((w) => {
        const wahl   = standVon(w.i, MODUS_WAHL);
        const luecke = standVon(w.i, MODUS_LUECKE);
        return {
            id: w.i,
            term_foreign: w.f,
            term_native:  w.n,
            note: null,
            modes: {
                mc: {
                    streak: wahl.s, correct: wahl.c, wrong: wahl.w,
                    known: wahl.k === 1, possible: true,
                },
                cloze: {
                    streak: luecke.s, correct: luecke.c, wrong: luecke.w,
                    known: luecke.k === 1,
                    possible: (v.saetze.get(w.i)?.length ?? 0) > 0,
                },
            },
        };
    });
}

/**
 * Der Stand beider Uebungsarten - wie api/units.php ?action=get ihn liefert.
 *
 * Der Status der Lueckensaetze kommt aus dem Buendel: "running" heisst, dass
 * sie gerade entstehen. Ohne diese Auskunft saehe ein Kind kurz nach dem
 * Einlesen "keine Saetze" statt "wird gerade gemacht" - und suchte den
 * Fehler bei sich.
 */
export function modusStand(unitId) {
    const wahl   = fortschritt(unitId, MODUS_WAHL);
    const luecke = fortschritt(unitId, MODUS_LUECKE);
    const roh    = einheit(unitId)?.z ?? '';

    const status = roh === ''
        ? (luecke.total > 0 ? 'done' : 'pending')
        : roh;

    return {
        mc:    { known: wahl.known, total: wahl.total },
        cloze: { known: luecke.known, total: luecke.total, status, error: null },
    };
}

// ------------------------------------------------------------ Fragen bauen

const wuerfel = (liste) => liste[Math.floor(Math.random() * liste.length)];

/**
 * Die nächste Frage zum Auswählen.
 *
 * Dieselbe Regel wie in api/quiz.php: eine noch offene Vokabel zufällig
 * ziehen, die Richtung würfeln, Ablenker zuerst aus derselben Einheit und,
 * wenn die zu klein ist, aus der ganzen Sprache nachlegen.
 */
export function frageWahl(unitId) {
    const v = vorratLaden();
    if (v === null) return null;

    const stand = fortschritt(unitId, MODUS_WAHL);
    const offen = vokabelnDerEinheit(unitId)
        .filter((w) => standVon(w.i, MODUS_WAHL).k !== 1);

    if (stand.total === 0) return { leer: true };
    if (offen.length === 0) return { done: true, ...stand };

    const karte = wuerfel(offen);
    const nachVorn = Math.random() < 0.5;
    const frage    = nachVorn ? karte.f : karte.n;
    const loesung  = nachVorn ? karte.n : karte.f;
    const feld     = nachVorn ? 'n' : 'f';

    /*
     * Die Ablenker dürfen die Lösung nicht doppeln - und auch einander
     * nicht: Zwei gleiche Möglichkeiten nebeneinander sehen aus wie ein
     * Fehler, und eine davon wäre dann ja richtig.
     */
    const genommen = new Set([loesung]);
    const optionen = [loesung];

    const nachlegen = (quelle) => {
        for (const kandidat of mischen([...quelle])) {
            if (optionen.length >= ANZAHL_OPTIONEN) return;
            const wort = kandidat[feld];
            if (kandidat.i === karte.i || genommen.has(wort)) continue;
            genommen.add(wort);
            optionen.push(wort);
        }
    };
    nachlegen(vokabelnDerEinheit(unitId));
    if (optionen.length < ANZAHL_OPTIONEN) {
        nachlegen(v.vokabelnDerSprache.get(v.einheit.get(Number(unitId))?.l) ?? []);
    }

    const gemischt = mischen(optionen);
    return {
        done: false,
        vocabId:  karte.i,
        frage,
        optionen: gemischt,
        richtig:  gemischt.indexOf(loesung),
        nachVorn,
        streak:   standVon(karte.i, MODUS_WAHL).s,
        ...stand,
    };
}

/** Die nächste Lückentextaufgabe. */
export function frageLuecke(unitId) {
    const v = vorratLaden();
    if (v === null) return null;

    const stand = fortschritt(unitId, MODUS_LUECKE);
    if (stand.total === 0) return { leer: true };

    const offen = vokabelnDerEinheit(unitId).filter(
        (w) => (v.saetze.get(w.i)?.length ?? 0) > 0
            && standVon(w.i, MODUS_LUECKE).k !== 1,
    );
    if (offen.length === 0) return { done: true, ...stand };

    const karte = wuerfel(offen);
    const satz  = wuerfel(v.saetze.get(karte.i));
    // Der Sprachcode steuert die Akzentreihe unter dem Feld und das
    // lang-Attribut - ohne ihn bietet ein Telefon die falschen Zeichen an.
    const spr = sprache(einheit(unitId)?.l);

    return {
        done: false,
        vocabId:   karte.i,
        satzId:    satz.i,
        native:    satz.n,
        foreign:   satz.f,
        loesung:   satz.a,
        language:  spr?.name ?? '',
        lang:      spr?.code ?? '',
        streak:    standVon(karte.i, MODUS_LUECKE).s,
        ...stand,
    };
}

/** Fisher-Yates - eine Kopie, damit der Aufrufer seine Liste behält. */
function mischen(liste) {
    const a = [...liste];
    for (let i = a.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [a[i], a[j]] = [a[j], a[i]];
    }
    return a;
}

// ------------------------------------------------------- Antwort vergleichen

/*
 * Der Vergleich beim Lückentext - Spiegel von answer_check() in
 * lib/sentences.php.
 *
 * Zwei Fassungen derselben Regel in zwei Sprachen sind ein Risiko, und es
 * ist hier nicht zu vermeiden: Der Vergleich muss im Gerät stattfinden,
 * sonst wäre das Üben wieder ans Netz gebunden. Dagegen steht eine
 * gemeinsame Fallsammlung (tests/faelle/antworten.json), die beide Seiten
 * prüfen - fällt eine der beiden auseinander, fällt eine Suite um.
 */
const DIAKRITIKA = {
    'á': 'a', 'à': 'a', 'â': 'a', 'ä': 'a', 'ã': 'a', 'å': 'a', 'æ': 'ae',
    'é': 'e', 'è': 'e', 'ê': 'e', 'ë': 'e',
    'í': 'i', 'ì': 'i', 'î': 'i', 'ï': 'i',
    'ó': 'o', 'ò': 'o', 'ô': 'o', 'ö': 'o', 'õ': 'o', 'ø': 'o', 'œ': 'oe',
    'ú': 'u', 'ù': 'u', 'û': 'u', 'ü': 'u',
    'ç': 'c', 'ñ': 'n', 'ß': 'ss', 'ý': 'y', 'ÿ': 'y',
};

function normalisieren(text) {
    let t = String(text ?? '');
    // Typografische Apostrophe auf das schlichte ' - welches davon eine
    // Handytastatur liefert, ist nicht vorhersagbar.
    t = t.replace(/[’ʼ‘`´]/g, "'");
    t = t.replace(/[\s   ]+/g, ' ');
    // Der Abstand vor einem Satzzeichen zählt nicht mit: Ältere französische
    // Sätze haben dort noch einen, und manches Kind tippt ihn.
    t = t.replace(/ +([.,;:!?])/g, '$1');
    return t.trim().toLowerCase();
}

function falten(text) {
    return [...normalisieren(text)].map((z) => DIAKRITIKA[z] ?? z).join('');
}

// Ohne Satzzeichen: Ein vergessenes Fragezeichen ist "Fast!", nicht falsch.
const vereinfachen = (text) => falten(text).replace(/['\- .,;:!?¿¡]/g, '');

/** @return {{correct: boolean, exact: boolean}} */
export function antwortPruefen(getippt, erwartet) {
    if (String(getippt ?? '').trim() === '') {
        return { correct: false, exact: false };
    }
    if (normalisieren(getippt) === normalisieren(erwartet)) {
        return { correct: true, exact: true };
    }
    // Fehlende Akzente, Apostrophe und Satzzeichen verzeihen - auf einer
    // Handytastatur sind sie mühsam, und der Sinn der Übung ist die Vokabel.
    if (vereinfachen(getippt) === vereinfachen(erwartet)) {
        return { correct: true, exact: false };
    }
    return { correct: false, exact: false };
}

// ------------------------------------------------------- Antworten merken

/** Wie viele richtige Antworten hintereinander etwas „gekonnt" machen. */
function schwelle() {
    return vorratLaden()?.daten.schwelle ?? 3;
}

/**
 * Eine Antwort verbuchen - im Gerät, sofort, und in der Warteschlange.
 *
 * Gerechnet wird genau wie record_answer() auf dem Server: Eine richtige
 * Antwort verlängert die Serie, eine falsche setzt sie auf null, und ab der
 * Schwelle gilt die Vokabel als gekonnt. Weil der Server dieselbe Rechnung
 * auf denselben Ereignissen macht, kommt dort dasselbe heraus - auch wenn
 * ein zweites Gerät dazwischenfunkt.
 */
export function antwortMerken(vocabId, modus, richtig) {
    const v = vorratLaden();
    if (v === null) return { streak: 0, just_learned: false, known: 0, total: 0 };

    const schl = `${vocabId}:${modus}`;
    const p = v.stand.get(schl) ?? { v: vocabId, m: modus, s: 0, c: 0, w: 0, k: 0 };

    if (richtig) {
        p.s += 1;
        p.c += 1;
    } else {
        p.s = 0;   // "dreimal hintereinander" - ein Fehler setzt zurück
        p.w += 1;
    }
    const jetztGekonnt = p.s >= schwelle();
    /*
     * Und jetzt zum ERSTEN Mal gekonnt - nicht dasselbe. jetztGekonnt steht
     * ab der dritten richtigen Antwort bei jeder weiteren wieder da; für die
     * Serie zählt der Übergang. Genau wie record_answer() es auf dem Server
     * an known_at abliest, liest die App es hier an p.k ab.
     */
    const neuGekonnt = jetztGekonnt && p.k !== 1;

    // Einmal gekonnt bleibt gekonnt, solange die Serie hält - wie auf dem Server.
    p.k = jetztGekonnt ? 1 : 0;

    v.stand.set(schl, p);
    sichern();

    const tagGeschafft = serieVerbuchen(richtig, neuGekonnt);

    warteschlangeAnhaengen({
        e: kennung(), v: Number(vocabId), m: modus, r: richtig ? 1 : 0,
        // Der Tag, an dem geantwortet wurde. Er geht mit, weil der Stapel
        // Tage später ankommen kann - siehe lib/streak.php.
        d: heute(),
    });

    return {
        streak: p.s,
        just_learned: jetztGekonnt,
        newly_learned: neuGekonnt,
        tag_geschafft: tagGeschafft,
    };
}

/** „Noch einmal üben" - der Lernstand dieser Einheit auf null. */
export function zuruecksetzen(unitId, modus = null) {
    const v = vorratLaden();
    if (v === null) return;

    for (const w of vokabelnDerEinheit(unitId)) {
        for (const m of [MODUS_WAHL, MODUS_LUECKE]) {
            if (modus !== null && m !== modus) continue;
            v.stand.delete(`${w.i}:${m}`);
        }
    }
    sichern();
    warteschlangeAnhaengen({
        e: kennung(), k: 'reset', u: Number(unitId), m: modus ?? '',
    });
}

// ------------------------------------------------------------------ Die Serie

/*
 * Die Serie im Gerät - Spiegel von lib/streak.php.
 *
 * Zwei Fassungen derselben Regel, und wie beim Vergleich der Lückenantwort
 * ist es hier nicht zu vermeiden: Das Abzeichen steht in der Leiste jeder
 * Seite und muss auch ohne Netz stimmen. Dagegen steht dieselbe Sicherung
 * wie dort - eine gemeinsame Fallsammlung (tests/faelle/serien.json), die
 * beide Seiten prüfen.
 *
 * Nachgerechnet wird dabei bewusst NICHT die ganze Kette: Das Gerät kennt
 * nur die letzten dreissig Tage, der Server alle. Vom Server kommen deshalb
 * zwei Zahlen - wie lang die Kette an ihrem letzten Lerntag war und wann
 * dieser Tag war -, und daraus fällt hier heraus, was heute auf dem
 * Bildschirm steht. Das reicht auch nach einer Woche im Hintergrund.
 */

/** Ab so vielen richtigen Antworten zählt ein Tag auch ohne neue Vokabel. */
const SERIE_UEBUNG_MIN = 10;

/** So viele Tage Abstand hält eine Serie noch aus. Zwei: einer wird verziehen. */
const SERIE_ABSTAND_MAX = 2;

/** Der heutige Tag, wie das Gerät ihn sieht - als 'JJJJ-MM-TT'. */
export function heute() {
    const d = new Date();
    const zwei = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${zwei(d.getMonth() + 1)}-${zwei(d.getDate())}`;
}

/*
 * Abstand zweier Tage in Tagen.
 *
 * Über Date.UTC und nicht über die lokale Zeit: In der Nacht der
 * Zeitumstellung hat ein Tag 23 oder 25 Stunden, und eine Division durch
 * 24 Stunden ergäbe dann 0,96 oder 1,04 Tage. Einmal abgerundet wäre aus
 * "gestern" ein "heute" geworden - und die Serie eines Kindes hinge daran,
 * ob im Oktober die Uhr umgestellt wurde. In UTC hat jeder Tag 24 Stunden.
 */
export function tagAbstand(a, b) {
    const zahl = (s) => {
        const [j, m, t] = String(s).split('-').map(Number);
        return Date.UTC(j, m - 1, t);
    };
    return Math.round((zahl(b) - zahl(a)) / 86400000);
}

/** Zählt dieser Tag für die Serie? */
export function tagZaehlt(gelernt, richtig) {
    return gelernt >= 1 || richtig >= SERIE_UEBUNG_MIN;
}

/**
 * Was das Abzeichen zeigt - aus Kette, letztem Lerntag und heute.
 *
 *   heute   Heute schon gelernt. Zahl grün, Voki froh und farbig.
 *   offen   Gestern gelernt, heute noch nicht. Zahl grau, Voki froh, aber grau.
 *   gefahr  Ein Tag ausgelassen. Die Zahl steht noch, aber Voki ist traurig
 *           und grau - heute nichts mehr, und sie ist weg.
 *   aus     Zwei Tage ausgelassen, oder noch nie gelernt. Null, Voki traurig.
 */
export function serieAnzeige(kette, letzter, tag = heute()) {
    if (!letzter || kette <= 0) {
        return { zahl: 0, lage: 'aus', abstand: null };
    }

    let abstand = tagAbstand(letzter, tag);
    if (abstand < 0) abstand = 0;          // Uhr läuft vor; dann eben heute
    if (abstand > SERIE_ABSTAND_MAX) {
        return { zahl: 0, lage: 'aus', abstand };
    }

    return {
        zahl:    kette,
        lage:    abstand === 0 ? 'heute' : (abstand === 1 ? 'offen' : 'gefahr'),
        abstand,
    };
}

/** Der Stand, wie er im Vorrat liegt - oder ein leerer, solange keiner da ist. */
export function serie() {
    const v = vorratLaden();
    const s = v?.daten.serie ?? VT.serie ?? null;
    return {
        kette: 0, letzter: null, best: 0, schwelle: SERIE_UEBUNG_MIN,
        monate: 12, seit: heute(), heute: heute(), tage: [],
        ...(s ?? {}),
    };
}

/** Was heute auf dem Abzeichen steht. */
export function serieHeute() {
    const s = serie();
    return { ...serieAnzeige(s.kette, s.letzter, heute()), best: s.best, schwelle: s.schwelle };
}

/** Die letzten dreissig Tage, für den Kalender im Konto. */
export function serieTage() {
    return serie().tage ?? [];
}

/**
 * Eine Antwort auf den heutigen Tag schreiben - und sehen, ob er dadurch zählt.
 *
 * Gibt zurück, ob der Tag GERADE EBEN geschafft wurde. Genau darauf wartet
 * die kleine Feier oben in der Leiste: Sie soll einmal laufen, in dem
 * Augenblick, in dem die Zahl weiterspringt, und nicht bei jeder weiteren
 * richtigen Antwort des Tages.
 */
function serieVerbuchen(richtig, neuGekonnt) {
    const v = vorratLaden();
    if (v === null) return false;

    const tag = heute();

    /*
     * Über serie() und nicht über v.daten.serie: Ein Vorrat, der noch aus
     * der Zeit vor der Serie im Gerät liegt, hat den Eintrag nicht - und
     * dann gilt der Stand, den die Hülle mitgebracht hat. Ohne das fiele
     * eine lange Serie beim ersten Üben nach einer Aktualisierung auf 1
     * zurück, bis der Server sie ein paar Sekunden später richtigstellt.
     */
    const s = { ...serie() };
    const vorher = serieAnzeige(s.kette, s.letzter, tag);

    // Eigene Kopie der Tage: serie() kann die Liste aus VT liefern, und die
    // gehört der Hülle. Hier wird gleich darin gezählt.
    const tage = (s.tage ?? []).map((t) => ({ ...t }));
    let eintrag = tage.find((t) => t.d === tag);
    if (!eintrag) {
        eintrag = { d: tag, l: 0, c: 0 };
        tage.unshift(eintrag);
    }
    if (neuGekonnt) eintrag.l += 1;
    if (richtig)    eintrag.c += 1;

    const zaehltJetzt = tagZaehlt(eintrag.l, eintrag.c);
    const geschafft   = zaehltJetzt && vorher.lage !== 'heute';

    if (geschafft) {
        /*
         * Die Kette wächst um eins - ausser sie war gerissen, dann fängt
         * sie bei eins an. Welcher Fall gilt, steht in der Lage von vorhin:
         * 'aus' heisst gerissen, alles andere heisst, sie lief noch.
         */
        s.kette   = vorher.lage === 'aus' ? 1 : s.kette + 1;
        s.letzter = tag;
        s.best    = Math.max(s.best ?? 0, s.kette);
    }

    /*
     * Die letzten gut dreizehn Monate behalten - so weit laesst sich der
     * Kalender im Konto blaettern. Frueher standen hier dreissig Tage; seit
     * der Kalender in Monaten blaettert, waere der Vormonat schon leer.
     * Nach oben begrenzt bleibt es trotzdem: Wer ein Jahr offline uebt,
     * soll den Vorrat nicht unbegrenzt wachsen lassen.
     */
    s.tage = tage
        .filter((t) => tagAbstand(t.d, tag) <= 400)
        .sort((a, b) => (a.d < b.d ? 1 : -1))
        .slice(0, 400);
    v.daten.serie = s;
    sichern();

    return geschafft;
}

// --------------------------------------------------------------- Freies Üben

/*
 * Freies Üben rührt den Lernstand NICHT an.
 *
 * Das ist der ganze Unterschied zu den beiden anderen Übungen, und er ist
 * Absicht: Geübt wird hier alles, auch was längst sitzt, und ein Fehler beim
 * lockeren Wiederholen soll keine Serie einreissen, die über Wochen
 * entstanden ist. „Gekonnt" bleibt die Aussage der strukturierten Übung.
 *
 * Gezählt wird trotzdem: Jede richtige Antwort geht als Tag-Ereignis an den
 * Server und wandert damit in die Serie, den Kalender und die Zahl im
 * Abzeichen. Geübt ist geübt.
 */

/** Alle freigegebenen Vokabeln dieser Lerneinheiten, in einer Liste. */
export function vokabelnDerEinheiten(unitIds) {
    const v = vorratLaden();
    if (v === null) return [];

    const raus = [];
    for (const id of unitIds) {
        for (const w of v.proEinheit.get(Number(id)) ?? []) raus.push(w);
    }
    return raus;
}

/** Wie viele davon einen Lückensatz haben - ohne sie gibt es nur Auswählen. */
export function freiUmfang(unitIds) {
    const v = vorratLaden();
    const alle = vokabelnDerEinheiten(unitIds);
    return {
        vokabeln: alle.length,
        saetze: v === null ? 0
            : alle.filter((w) => (v.saetze.get(w.i)?.length ?? 0) > 0).length,
    };
}

/**
 * Die nächste Aufgabe beim freien Üben.
 *
 * Gewürfelt wird zweierlei: welche Vokabel und welche Übungsart. Der
 * Lückentext kommt nur für Vokabeln in Frage, zu denen es einen Satz gibt -
 * sonst fiele die Hälfte der Aufgaben aus.
 *
 * Es gibt kein „fertig": Die Runde läuft, bis jemand aufhört. Deshalb auch
 * kein Filter auf „noch nicht gekonnt" - der liesse sie nach zwanzig
 * Antworten leerlaufen.
 */
export function frageFrei(unitIds) {
    const v = vorratLaden();
    if (v === null) return null;

    const alle = vokabelnDerEinheiten(unitIds);
    if (alle.length === 0) return { leer: true };

    const karte = wuerfel(alle);
    const saetze = v.saetze.get(karte.i) ?? [];

    /*
     * Drei von fünf Aufgaben als Lückentext, wenn es Sätze gibt: Er ist die
     * schwerere Übung, und wer frei übt, hat das Auswählen meist hinter
     * sich. Eine einzelne Vokabel ohne Satz bekommt trotzdem ihre Frage.
     */
    if (saetze.length > 0 && Math.random() < 0.6) {
        const satz = wuerfel(saetze);
        const spr  = sprache(v.einheit.get(karte.u)?.l);
        return {
            art:      MODUS_LUECKE,
            vocabId:  karte.i,
            satzId:   satz.i,
            native:   satz.n,
            foreign:  satz.f,
            loesung:  satz.a,
            language: spr?.name ?? '',
            lang:     spr?.code ?? '',
        };
    }

    const nachVorn = Math.random() < 0.5;
    const frage    = nachVorn ? karte.f : karte.n;
    const loesung  = nachVorn ? karte.n : karte.f;
    const feld     = nachVorn ? 'n' : 'f';

    // Die Ablenker aus demselben Vorrat wie die Frage - und wenn der zu
    // klein ist, aus der ganzen Sprache. Dieselbe Regel wie beim Quiz.
    const genommen = new Set([loesung]);
    const optionen = [loesung];
    const nachlegen = (quelle) => {
        for (const kandidat of mischen([...quelle])) {
            if (optionen.length >= ANZAHL_OPTIONEN) return;
            const wort = kandidat[feld];
            if (kandidat.i === karte.i || genommen.has(wort)) continue;
            genommen.add(wort);
            optionen.push(wort);
        }
    };
    nachlegen(alle);
    if (optionen.length < ANZAHL_OPTIONEN) {
        nachlegen(v.vokabelnDerSprache.get(v.einheit.get(karte.u)?.l) ?? []);
    }

    const gemischt = mischen(optionen);
    const spr = sprache(v.einheit.get(karte.u)?.l);
    return {
        art:      MODUS_WAHL,
        vocabId:  karte.i,
        frage,
        optionen: gemischt,
        richtig:  gemischt.indexOf(loesung),
        nachVorn,
        language: spr?.name ?? '',
    };
}

/**
 * Eine Antwort beim freien Üben verbuchen.
 *
 * Nur der Tag, kein Lernstand. Das Ereignis trägt trotzdem die Vokabel mit:
 * Der Server prüft an ihr, ob dieses Konto überhaupt antworten darf -
 * dieselbe Schranke wie bei jeder anderen Antwort.
 */
export function freiMerken(vocabId, richtig) {
    const tagGeschafft = serieVerbuchen(richtig, false);

    warteschlangeAnhaengen({
        e: kennung(), k: 'frei', v: Number(vocabId), r: richtig ? 1 : 0, d: heute(),
    });

    return { tag_geschafft: tagGeschafft };
}

/**
 * "Stimmt hier etwas nicht?" - eine Vokabel der Lehrkraft melden.
 *
 * In die Warteschlange wie eine Antwort, nicht als eigener Abruf: Geübt
 * wird auch ohne Netz, und eine Meldung aus dem Zug soll ankommen, sobald
 * wieder eines da ist, statt mit "Bist du online?" verloren zu gehen.
 *
 * satzId ist der Lückensatz, an dem es auffiel, oder 0 beim Auswählen -
 * dann ist das Wortpaar selbst gemeint. Das Getippte kommt mit, weil es
 * beim Lückentext meist entscheidet, ob der Satz oder die Lösung schief war.
 */
export function vokabelMelden(vocabId, satzId = 0, getippt = '') {
    warteschlangeAnhaengen({
        e: kennung(), k: 'melden', v: Number(vocabId), s: Number(satzId) || 0,
        t: String(getippt).slice(0, 128),
    });
}

// ----------------------------------------------------------- Warteschlange

/**
 * Eine Kennung je Antwort.
 *
 * Sie ist der Grund, warum ein zweimal geschickter Stapel nicht doppelt
 * zählt: Der Server merkt sich, was er schon gesehen hat. Der häufigste
 * Fall beim Nachreichen ist nämlich nicht die verlorene Anfrage, sondern
 * die verlorene Antwort.
 */
function kennung() {
    if (crypto.randomUUID) return crypto.randomUUID();
    // Ältere Browser: gut genug, weil die Kennung nur je Konto eindeutig
    // sein muss und nicht geheim ist.
    return 'x' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
}

function warteschlangeAnhaengen(ereignis) {
    const schlange = lesen(warteSchluessel(), []);
    schlange.push(ereignis);
    schreiben(warteSchluessel(), schlange);
    spaeterSenden();
}

export function warteschlangeLaenge() {
    return lesen(warteSchluessel(), []).length;
}

let sendeUhr = null;

/*
 * Nicht nach jeder Antwort einzeln senden: Beim Üben kommt alle paar
 * Sekunden eine, und daraus würden wieder so viele Runden übers Netz, wie
 * es vorher waren. Ein paar Sekunden sammeln, dann am Stück.
 */
function spaeterSenden() {
    if (sendeUhr !== null) clearTimeout(sendeUhr);
    sendeUhr = setTimeout(() => { sendeUhr = null; warteschlangeSenden(); }, 4000);
}

let sendetGerade = false;

/**
 * Die Warteschlange zum Server.
 *
 * Erst nach einer erfolgreichen Antwort wird gelöscht, und zwar nur das,
 * was auch geschickt wurde - in der Zwischenzeit kann eine neue Antwort
 * dazugekommen sein.
 *
 * Wirft nicht. Geht es nicht, bleibt alles liegen und kommt beim nächsten
 * Versuch mit.
 */
export async function warteschlangeSenden() {
    if (sendetGerade) return false;

    const schlange = lesen(warteSchluessel(), []);
    if (schlange.length === 0) return true;

    sendetGerade = true;
    try {
        const antwort = await api('bundle', 'push', { body: { ereignisse: schlange } });

        // Nur die geschickten wegnehmen. Was während des Sendens dazukam,
        // bleibt stehen.
        const rest = lesen(warteSchluessel(), []).slice(schlange.length);
        schreiben(warteSchluessel(), rest);

        // Der Stand vom Server gilt - er kennt auch, was ein zweites Gerät
        // beigetragen hat.
        const v = vorratLaden();
        if (v !== null && Array.isArray(antwort.stand)) {
            v.stand = new Map(antwort.stand.map((p) => [`${p.v}:${p.m}`, p]));
            // Und die Serie mit: Der Server kennt auch den Nachmittag auf
            // dem Tablet der Schule, das Gerät hier nur sich selbst.
            if (antwort.serie) v.daten.serie = antwort.serie;
            sichern();
        }
        return true;
    } catch {
        return false;
    } finally {
        sendetGerade = false;
    }
}

/* Kommt das Netz zurück, geht liegengebliebenes gleich raus. */
window.addEventListener('online', () => { warteschlangeSenden(); });

/*
 * Und wenn die Seite weggeht. sendBeacon ueberlebt das Schliessen des Tabs,
 * fetch nicht - aber es kann keine eigenen Kopfzeilen setzen, und der
 * X-Vokabeltrainer-Kopf ist hier der CSRF-Schutz. Also der Versuch mit
 * keepalive, und was nicht ankommt, geht beim naechsten Start mit.
 */
window.addEventListener('pagehide', () => {
    const schlange = lesen(warteSchluessel(), []);
    if (schlange.length === 0) return;
    try {
        fetch(`${VT.base}/api/bundle.php?action=push`, {
            method: 'POST',
            headers: { 'X-Vokabeltrainer': '1', 'Content-Type': 'application/json' },
            body: JSON.stringify({ ereignisse: schlange }),
            credentials: 'same-origin',
            keepalive: true,
        }).catch(() => {});
    } catch { /* egal */ }
});
