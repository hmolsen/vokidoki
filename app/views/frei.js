import {
    render, esc, $, $$, go, wireBack, showError, babing, konfetti, lobWort,
    zahlAktualisieren, VT,
} from '../core.js';
import {
    frageFrei, frageFehler, freiMerken, freiUmfang, freiHoerbar, einheit, sprache,
    einheitenDerSprache, MODUS_WAHL, MODUS_EINSETZEN, MODUS_HOEREN, MODUS_LUECKE, FEHLER_TOPF,
} from '../vorrat.js';
import { meldeKnopf, meldenVerdrahten } from '../melden.js';
import { tonKnopf, optionHtml, tonVerdrahten } from './wortton.js';
import { lueckeZeigen } from './cloze.js';
import { einsetzenZeigen } from './einsetzen.js';
import { hoerenZeigen, hoerenAnhalten } from './hoeren.js';

/*
 * Freies Üben.
 *
 * Die beiden anderen Übungen haben ein Ziel: Jede Vokabel dreimal richtig,
 * dann ist die Lerneinheit durch. Diese hier hat keines. Sie läuft, bis
 * jemand aufhört, und sie zieht aus allem, was ausgewählt wurde - auch aus
 * dem, was längst sitzt. Genau dafür ist sie da: wiederholen, nicht
 * abarbeiten.
 *
 * DESHALB RÜHRT SIE DEN LERNSTAND NICHT AN. Ein Fehler beim lockeren
 * Wiederholen soll keine Serie einreissen, die über Wochen entstanden ist,
 * und eine richtige Antwort macht hier nichts „gekonnt". Gezählt wird
 * trotzdem: Jede richtige Antwort wandert in die Serie, den Kalender und die
 * Zahl im Abzeichen. Geübt ist geübt.
 */

// Nur fürs Auswählen. Der Lückentext bringt seine eigenen Zeiten mit -
// er ist derselbe Bildschirm wie in der Lückentext-Übung, siehe cloze.js.
const WEITER_RICHTIG = 700;    // wie im Quiz: zügig weiter
const WEITER_FALSCH  = 1900;   // Zeit, die richtige Lösung zu lesen

/** Alle wie viele richtigen Antworten gelobt wird. */
const LOB_RICHTIGE = 25;

/** Und alle wie viele hintereinander. */
const LOB_FOLGE = 5;

/** Der Stand dieser Runde. Lebt nur, solange die Ansicht steht. */
let runde = null;

/*
 * Schalter im Kopf der Runde: Hören und Schreiben an oder aus.
 *
 * Im Klassenzimmer ohne Kopfhörer will man keine Sätze, die laut aus dem
 * Telefon kommen; im Bus mit Kopfhörern gerade die. Und wer im Stehen übt,
 * will nicht tippen. Also je ein Schalter, der sich merkt, wie er stand -
 * im Gerät, nicht am Konto: Es ist eine Frage der Lage, und die ist auf dem
 * Schul-Tablet eine andere als zu Hause.
 *
 * Beide stehen in dieser einen Liste: Zeichen, Name, wo er sich merkt, und
 * ob es in dieser Auswahl überhaupt etwas dafür gibt.
 */
const SCHALTER = [
    { id: 'hoeren',    modus: MODUS_HOEREN, zeichen: '\u{1F3A7}',        name: 'Hören',
      schluessel: 'vt-frei-hoeren',    da: (r) => r.hoerbar },
    { id: 'schreiben', modus: MODUS_LUECKE, zeichen: '\u{270F}\u{FE0F}', name: 'Schreiben',
      schluessel: 'vt-frei-schreiben', da: (r) => r.schreibbar },
];

function schalterAn(s) {
    try {
        return localStorage.getItem(s.schluessel) !== 'aus';
    } catch {
        return true;
    }
}

function schalterSetzen(s, an) {
    try {
        if (an) localStorage.removeItem(s.schluessel);
        else localStorage.setItem(s.schluessel, 'aus');
    } catch { /* privates Fenster: dann gilt es nur für diese Runde */ }
}

/** Was die Runde gerade ziehen darf - für frageFrei(). */
function erlaubt() {
    const an = (id) => {
        const s = SCHALTER.find((x) => x.id === id);
        return s.da(runde) && schalterAn(s);
    };
    return { hoeren: an('hoeren'), schreiben: an('schreiben') };
}

/*
 * Einmal angemeldet, für jeden Bildschirm der Runde: Die Schalter stehen im
 * Kopf, und der wird mit jeder Aufgabe neu gezeichnet. Wird eine Übungsart
 * mitten in einer ihrer Aufgaben abgeschaltet, kommt sofort die nächste -
 * keine, die man nun nicht mehr will.
 */
document.addEventListener('change', (e) => {
    const s = SCHALTER.find((x) => e.target?.id === `schalter-${x.id}`);
    if (!s || runde === null) return;
    schalterSetzen(s, e.target.checked);
    runde.vorgemerkt = null;   // vielleicht eine, die es jetzt nicht mehr geben soll
    if (!e.target.checked && runde.art === s.modus) {
        hoerenAnhalten();
        naechste();
    }
});

/*
 * Zwei Runden auf demselben Bildschirm: das Freie Üben, und das Lernen aus
 * Fehlern - dieselben Aufgaben, dieselbe Leiste, dieselben Schalter; nur
 * woher die nächste Aufgabe kommt, ist anders (ziehen()). Adressen:
 * #/frei/... und #/fehler/..., die Auswahl unter #/frei/waehlen/ und
 * #/fehler/waehlen/.
 */
const QUELLEN = {
    frei: {
        titel: 'Freies Üben',
        pfad: 'frei',
        zeichen: () => hantel(),
        erklaerung: '',
    },
    fehler: {
        titel: 'Aus Fehlern lernen',
        pfad: 'fehler',
        zeichen: () => '<span aria-hidden="true">\u{1FA79}</span>',
        erklaerung: `Geübt werden die ${FEHLER_TOPF} Aufgaben, die dir am schwersten fallen - nach jeder
            Antwort neu ausgesucht. Was sitzt, rutscht hinaus, und das Nächste rückt nach.`,
    },
};

/*
 * Die Tastatur für den nächsten Lückentext schon offen halten.
 *
 * Ein Telefon öffnet die Tastatur nur, wenn ein Feld während eines Tipps
 * den Fokus bekommt. Der Lückentext kommt aber erst nach der Rückmeldung
 * zur vorigen Aufgabe - ein Zeitgeber später, ohne Tipp: Das Feld bekam den
 * Fokus, die Tastatur blieb zu, und das Kind musste erst hineintippen.
 *
 * Deshalb wird die nächste Aufgabe schon beim Antworten gezogen. Ist sie ein
 * Lückentext, bekommt noch im selben Tipp ein unsichtbares Feld den Fokus,
 * und die Tastatur geht auf; erscheint der Lückentext, wandert der Fokus in
 * sein Feld - von Feld zu Feld darf das ohne Tipp, die Tastatur bleibt.
 */
function tastaturHalten() {
    let halter = document.getElementById('tastaturhalter');
    if (!halter) {
        halter = document.createElement('input');
        halter.id = 'tastaturhalter';
        halter.type = 'text';
        halter.tabIndex = -1;
        halter.setAttribute('aria-hidden', 'true');
        halter.autocomplete = 'off';
        // 16 px, sonst zoomt Safari beim Fokus hinein.
        halter.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;'
            + 'opacity:0;border:0;padding:0;font-size:16px;pointer-events:none';
        document.body.append(halter);
    }
    halter.focus({ preventScroll: true });
}

function tastaturHalterWeg() {
    document.getElementById('tastaturhalter')?.remove();
}
window.addEventListener('hashchange', tastaturHalterWeg);

/**
 * Das Symbol: eine Hantel.
 *
 * Als SVG und nicht als Emoji, weil es keines gibt - das nächstliegende
 * (🏋️) ist ein Mensch, der etwas stemmt, und das ist etwas anderes als das
 * Gerät. Zwei Scheiben, eine Stange, fertig; sie nimmt die Schriftfarbe an
 * und passt damit in jede Zeile.
 */
export function hantel(klasse = 'hantel') {
    return `<svg class="${esc(klasse)}" viewBox="0 0 24 24" width="24" height="24"
                 fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" aria-hidden="true">
        <path d="M4 8v8M7 6v12M17 6v12M20 8v8M7 12h10"/>
    </svg>`;
}

// ------------------------------------------------------- Welche Einheiten?

/**
 * Die Auswahl vor dem Üben - nur vom Kurs aus.
 *
 * Von einer einzelnen Lerneinheit aus gibt es nichts zu wählen; dort geht es
 * direkt los. Hier dagegen ist die Frage der halbe Sinn: „Welche
 * Lerneinheiten sollen geübt werden?" ist die einzige Entscheidung, die
 * diese Übung überhaupt verlangt.
 */
export async function freiWahlView(languageId, quelle = 'frei') {
    const q     = QUELLEN[quelle] ?? QUELLEN.frei;
    const spr   = sprache(languageId);
    const units = einheitenDerSprache(languageId);

    if (spr === null) {
        render(`${kopf(q.titel, `/lang/${languageId}`)}<div id="msg"></div>`);
        wireBack();
        showError('Dieser Kurs ist noch nicht geladen. '
                  + 'Einmal mit Netz öffnen, dann geht es auch ohne.');
        return;
    }

    if (units.length === 0) {
        render(`
            ${kopf(q.titel, `/lang/${languageId}`)}
            <div class="empty">
                <span class="big">${hantel('hantel gross')}</span>
                Hier ist noch keine Lerneinheit freigegeben.
            </div>`);
        wireBack();
        return;
    }

    const zeilen = units.map((u) => {
        const umfang = freiUmfang([u.i]);
        return `
        <label class="row wahlzeile">
            <input type="checkbox" class="wahlbox" value="${u.i}" checked>
            <span class="body">
                <span class="title">${esc(u.t)}</span>
                <span class="tiny muted">${umfang.vokabeln} Vokabeln${
                    umfang.saetze > 0 ? `, ${umfang.saetze} mit Lückensatz` : ''
                }</span>
            </span>
        </label>`;
    }).join('');

    render(`
        ${kopf(q.titel, `/lang/${languageId}`)}
        <div id="msg"></div>

        <!-- Oben, nicht unter der Liste: Bei zwanzig Lerneinheiten musste man
             erst ganz hinunterrollen, um anzufangen. -->
        <button class="btn" id="los" style="margin-bottom:16px">
            ${q.zeichen()} Losüben
        </button>

        ${q.erklaerung ? `<p class="tiny muted">${q.erklaerung}</p>` : ''}
        <p class="sub">Welche Lerneinheiten sollen geübt werden?</p>

        <div class="btn-row" style="margin-bottom:12px">
            <button class="btn secondary small" id="alle">Alle</button>
            <button class="btn secondary small" id="keine">Keine</button>
        </div>

        ${zeilen}
    `);

    wireBack();

    const boxen = () => $$('.wahlbox');
    const gewaehlt = () => boxen().filter((b) => b.checked).map((b) => b.value);

    const knopfStand = () => {
        const n = gewaehlt().length;
        $('#los').disabled = n === 0;
        $('#los').innerHTML = n === 0
            ? 'Wähle mindestens eine Lerneinheit'
            : `${q.zeichen()} Losüben`;
    };

    $('#alle').addEventListener('click', () => {
        boxen().forEach((b) => { b.checked = true; }); knopfStand();
    });
    $('#keine').addEventListener('click', () => {
        boxen().forEach((b) => { b.checked = false; }); knopfStand();
    });
    boxen().forEach((b) => b.addEventListener('change', knopfStand));
    knopfStand();

    $('#los').addEventListener('click', () => {
        const wahl = gewaehlt();
        if (wahl.length === 0) return;
        go(`/${q.pfad}/${wahl.join('-')}`);
    });
}

// ------------------------------------------------------------ Die Runde

/** Eine eigene Leiste statt topbar(): Hier zählt die Runde, nicht der Weg. */
function kopf(titel, zurueck) {
    // Ein Schalter nur, wenn es in dieser Auswahl überhaupt etwas dafür gibt.
    const schalter = runde === null ? [] : SCHALTER.filter((s) => s.da(runde)).map((s) => `
            <label class="hoerschalter" title="${esc(s.name)} an oder aus">
                <input type="checkbox" role="switch" id="schalter-${s.id}"${schalterAn(s) ? ' checked' : ''}>
                <span class="hoerschalter-bahn" aria-hidden="true"></span>
                <span aria-hidden="true">${s.zeichen}</span>
                <span class="nurvorlesen">${esc(s.name)}</span>
            </label>`);
    return `
        <div class="topbar">
            <button class="iconbtn" data-back="${esc(zurueck)}" aria-label="Zurück">&#8249;</button>
            <h1 title="${esc(titel)}">${esc(titel)}</h1>
            ${schalter.length ? `<span class="schalterreihe">${schalter.join('')}</span>` : ''}
        </div>`;
}

/**
 * Eine Runde.
 *
 * `zurueck` bringt die Lerneinheit mit, wenn die Runde dort gestartet
 * wurde. Ohne führt der Pfeil in den Kurs - dort liegt die Auswahl, über
 * die man sonst hierher kommt.
 */
export async function freiView(roh, zurueck = null, quelle = 'frei') {
    const q       = QUELLEN[quelle] ?? QUELLEN.frei;
    const unitIds = String(roh).split('-').map(Number).filter((n) => n > 0);
    const erste   = einheit(unitIds[0]);
    zurueck ??= erste === null ? '/' : `/lang/${erste.l}`;

    if (freiUmfang(unitIds).vokabeln === 0) {
        render(`${kopf(q.titel, zurueck)}<div id="msg"></div>`);
        wireBack();
        showError('Hier gibt es noch nichts zu üben. '
                  + 'Einmal mit Netz öffnen, dann geht es auch ohne.');
        return;
    }

    runde = { richtig: 0, falsch: 0, folge: 0, beste: 0, unitIds, zurueck,
              adresse: location.hash, hoerbar: freiHoerbar(unitIds),
              schreibbar: freiUmfang(unitIds).saetze > 0, art: null, vorgemerkt: null,
              quelle: q === QUELLEN.fehler ? 'fehler' : 'frei', titel: q.titel, zuletzt: '' };
    naechste();
}

/**
 * Die Serie dieser Runde - sie steht in jeder Aufgabe an derselben Stelle.
 *
 * Vorn, wie viele gerade hintereinander richtig sind, dahinter das Beste
 * dieser Runde, dazwischen der Weg vom einen zum anderen. Solange die
 * laufende Serie das Beste IST, zählen beide Zahlen gemeinsam hoch und der
 * Balken steht voll und grün - auch am Anfang, bei null und null. Nach
 * einem Fehler fällt die vordere Zahl auf null, die hintere bleibt, und der
 * Balken zeigt, wie weit es bis zum Einholen noch ist.
 *
 * Aussen stehen die beiden Zahlen der ganzen Runde: links der Voki mit
 * allen richtigen Antworten, rechts die Trefferquote. Eine Tür hinaus gibt
 * es nicht mehr - sie tat dasselbe wie der Zurück-Pfeil daneben.
 */
function zaehlerLeiste() {
    const { anteil, rekord } = serienStand();

    return `
        <div class="freikopf">
            <span class="freizahl aussen" title="Richtige Antworten in dieser Runde">
                <b><img class="freivoki" src="${esc(VT.base)}/assets/voki-mini.svg"
                        alt="" width="18" height="18"><span id="z-richtig">${runde.richtig}</span></b>
                <span class="freiname">Richtige</span>
            </span>
            <span class="freizahl" title="Richtig hintereinander">
                <b id="z-folge">${runde.folge}</b>
                <span class="freiname">in Folge</span>
            </span>
            <div class="bar freibalken${rekord ? ' done' : ''}" id="z-balken">
                <i style="width:${anteil}%"></i>
            </div>
            <span class="freizahl" title="Beste Serie in dieser Runde">
                <b id="z-beste">${runde.beste}</b>
                <span class="freiname">Rekord</span>
            </span>
            <span class="freizahl aussen" title="Trefferquote in dieser Runde">
                <b id="z-quote">${quote()}%</b>
                <span class="freiname">Treffer</span>
            </span>
        </div>`;
}

/** Anteil der richtigen Antworten in Prozent - 0, solange keine gegeben ist. */
function quote() {
    const gesamt = runde.richtig + runde.falsch;
    return gesamt === 0 ? 0 : Math.round((runde.richtig / gesamt) * 100);
}

/** Wie voll der Balken steht, und ob er grün ist. */
function serienStand() {
    const rekord = runde.folge >= runde.beste;
    return {
        rekord,
        anteil: rekord ? 100 : Math.round((runde.folge / runde.beste) * 100),
    };
}

/** Die Leiste nach einer Antwort nachziehen - mit der kleinen Bewegung. */
function zaehlerNachziehen() {
    const { anteil, rekord } = serienStand();

    zahlAktualisieren($('#z-richtig'), runde.richtig);
    zahlAktualisieren($('#z-folge'), runde.folge);
    zahlAktualisieren($('#z-beste'), runde.beste);
    zahlAktualisieren($('#z-quote'), `${quote()}%`);

    const balken = $('#z-balken');
    if (!balken) return;
    balken.classList.toggle('done', rekord);
    balken.firstElementChild.style.width = `${anteil}%`;
}

/**
 * Das Lob an den Meilensteinen.
 *
 * Zwei Anlässe, und wenn beide zusammenfallen, gewinnt der seltenere: Wer
 * bei der 25. richtigen Antwort auch noch fünf in Folge hat, soll die 25
 * lesen - die kommt einmal, die fünf alle paar Minuten.
 */
function meilenstein() {
    if (runde.richtig > 0 && runde.richtig % LOB_RICHTIGE === 0) {
        konfetti(lobWort(), `${runde.richtig} Richtige!`);
        return;
    }
    if (runde.folge > 0 && runde.folge % LOB_FOLGE === 0) {
        konfetti(lobWort(), `${runde.folge} in Folge`);
    }
}

/** Nach einer Antwort: zählen und loben. */
function zaehlen(vocabId, richtig) {
    if (richtig) {
        runde.richtig++;
        runde.folge++;
        runde.beste = Math.max(runde.beste, runde.folge);
        babing();
    } else {
        runde.falsch++;
        runde.folge = 0;
    }

    zaehlerNachziehen();
    freiMerken(vocabId, richtig, runde.art);
    if (richtig) meilenstein();

    // Die nächste Aufgabe schon jetzt, noch im Tipp - siehe tastaturHalten().
    // Folgt Lückentext auf Lückentext, hält dessen eigenes Feld die Tastatur.
    runde.vorgemerkt = ziehen();
    if (runde.vorgemerkt?.art === MODUS_LUECKE && runde.art !== MODUS_LUECKE) {
        tastaturHalten();
    }
}

/*
 * Nur weiterschalten, wenn die Runde noch laeuft UND die Adresse noch
 * dieselbe ist wie beim Start.
 *
 * Der Zurueck-Pfeil und das Menue wechseln die Adresse, ohne dass diese
 * Ansicht davon erfaehrt. Der Zeitgeber liefe trotzdem ab und zeichnete die
 * naechste Aufgabe ueber die Seite, auf der man gerade gelandet ist.
 * Verglichen wird die ganze Adresse und nicht ein Anfang wie "#/frei/":
 * Von einer Lerneinheit aus heisst sie #/unit/12/frei.
 */
function weiterWennNochHier() {
    if (runde === null || location.hash !== runde.adresse) return;
    naechste();
}

/** Beim Auswählen: zählen, und nach einer Pause weiter. */
function verbuchen(vocabId, richtig, weiter) {
    zaehlen(vocabId, richtig);
    setTimeout(weiterWennNochHier, weiter);
}

/**
 * Die nächste Aufgabe ziehen - frei aus allem, oder beim Lernen aus Fehlern
 * aus den schwächsten (frageFehler()), nur nicht gleich dieselbe noch einmal.
 */
function ziehen() {
    return runde.quelle === 'fehler'
        ? frageFehler(runde.unitIds, erlaubt(), runde.zuletzt)
        : frageFrei(runde.unitIds, erlaubt());
}

function naechste() {
    const a = runde.vorgemerkt ?? ziehen();
    runde.vorgemerkt = null;
    if (a?.art !== MODUS_LUECKE) tastaturHalterWeg();

    if (a === null || a.leer) {
        render(`${kopf(runde.titel, runde.zurueck)}<div id="msg"></div>`);
        wireBack();
        showError('Hier gibt es nichts zu üben.');
        return;
    }

    runde.art = a.art;
    runde.zuletzt = `${a.vocabId}:${a.art}`;
    if (a.art === MODUS_WAHL) zeigeWahl(a);
    else if (a.art === MODUS_EINSETZEN) einsetzenZeigen(freiArt(), a);
    else if (a.art === MODUS_HOEREN) hoerenZeigen(freiArt(), a);
    else zeigeLuecke(a);
}

/*
 * Einsetzen und Hören im Freien Üben: derselbe Bildschirm wie in ihrer
 * Übung (einsetzenZeigen(), hoerenZeigen()), mit dem Kopf dieser Runde und
 * ohne die Punkte des Lernstands - die Antwort zählt für die Runde und die
 * Serie, nicht für "gekonnt".
 */
function freiArt() {
    return {
        schluessel: 'frei',
        kopf: () => kopf(runde.titel, runde.zurueck) + zaehlerLeiste(),
        punkte: false,
        zeigen: () => {},
        merken: (data, richtig) => {
            zaehlen(data.vocabId, richtig);
            return {};
        },
        weiter: weiterWennNochHier,
    };
}

// ----------------------------------------------------------- Auswählen

function zeigeWahl(a) {
    const richtung = a.nachVorn
        ? `${esc(a.language)} → Deutsch`
        : `Deutsch → ${esc(a.language)}`;

    render(`
        ${kopf(runde.titel, runde.zurueck)}
        ${zaehlerLeiste()}

        <div class="prompt">
            <div>
                <div class="dir">${richtung}</div>
                <div class="wortzeile"><div class="word">${esc(a.frage)}</div>${tonKnopf(a.frageTon)}</div>
            </div>
            ${meldeKnopf(true)}
        </div>

        <div class="options" id="options">
            ${a.optionen.map((o, i) => optionHtml(o, i, a.optionToene?.[i])).join('')}
        </div>

        <div class="verdict" id="verdict"></div>
    `);

    verdrahten();
    tonVerdrahten();
    meldenVerdrahten($('[data-melden]'), () => ({ vocabId: a.vocabId, modus: MODUS_WAHL }));

    let beantwortet = false;
    $$('.option').forEach((knopf) => {
        knopf.addEventListener('click', () => {
            if (beantwortet) return;
            beantwortet = true;
            $('#options').classList.add('locked');

            const richtig = Number(knopf.dataset.index) === a.richtig;
            const verdict = $('#verdict');

            if (richtig) {
                knopf.classList.add('correct');
                verdict.className = 'verdict good';
                verdict.textContent = 'Richtig!';
            } else {
                knopf.classList.add('wrong');
                $$('.option')[a.richtig]?.classList.add('correct');
                verdict.className = 'verdict bad';
                verdict.textContent = 'Nicht ganz - so ist es richtig.';
            }

            verbuchen(a.vocabId, richtig, richtig ? WEITER_RICHTIG : WEITER_FALSCH);
        });
    });
}

// ---------------------------------------------------------- Lückentext

/*
 * Derselbe Bildschirm wie in der Lückentext-Übung - Feld in der Lücke,
 * Zeichenreihe, der Knopf, der Richtig und Falsch trägt, und nach einem
 * Fehler "Weiter" statt eines Zeitablaufs. Anders ist nur, was darüber
 * steht und wohin die Antwort geht: in die Serie dieser Runde, nicht in
 * den Lernstand.
 */
function zeigeLuecke(a) {
    lueckeZeigen({
        schluessel: 'frei',
        kopf: () => kopf(runde.titel, runde.zurueck) + zaehlerLeiste(),
        punkte: false,
        zeigen: () => {},
        merken: (data, richtig) => {
            zaehlen(data.vocabId, richtig);
            return {};
        },
        weiter: weiterWennNochHier,
    }, a);
    // Der Fokus ist jetzt im Feld der Lücke (lueckeZeigen()) - der Halter hat ausgedient.
    tastaturHalterWeg();
}

/** Was jede Aufgabe braucht. */
function verdrahten() {
    wireBack();
}
