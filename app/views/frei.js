import {
    render, esc, $, $$, go, wireBack, showError, babing, konfetti, lobWort,
    zahlAktualisieren, VT,
} from '../core.js';
import {
    frageFrei, freiMerken, freiUmfang, einheit, sprache,
    einheitenDerSprache, MODUS_WAHL,
} from '../vorrat.js';
import { meldeKnopf, meldenVerdrahten } from '../melden.js';
import { lueckeZeigen } from './cloze.js';

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
export async function freiWahlView(languageId) {
    const spr   = sprache(languageId);
    const units = einheitenDerSprache(languageId);

    if (spr === null) {
        render(`${kopf('Freies Üben', `/lang/${languageId}`)}<div id="msg"></div>`);
        wireBack();
        showError('Dieser Kurs ist noch nicht geladen. '
                  + 'Einmal mit Netz öffnen, dann geht es auch ohne.');
        return;
    }

    if (units.length === 0) {
        render(`
            ${kopf('Freies Üben', `/lang/${languageId}`)}
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
        ${kopf('Freies Üben', `/lang/${languageId}`)}
        <div id="msg"></div>

        <p class="sub">Welche Lerneinheiten sollen geübt werden?</p>

        <div class="btn-row" style="margin-bottom:12px">
            <button class="btn secondary small" id="alle">Alle</button>
            <button class="btn secondary small" id="keine">Keine</button>
        </div>

        ${zeilen}

        <button class="btn" id="los" style="margin-top:14px">
            ${hantel()} Losüben
        </button>
    `);

    wireBack();

    const boxen = () => $$('.wahlbox');
    const gewaehlt = () => boxen().filter((b) => b.checked).map((b) => b.value);

    const knopfStand = () => {
        const n = gewaehlt().length;
        $('#los').disabled = n === 0;
        $('#los').innerHTML = n === 0
            ? 'Wähle mindestens eine Lerneinheit'
            : `${hantel()} Losüben`;
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
        go(`/frei/${wahl.join('-')}`);
    });
}

// ------------------------------------------------------------ Die Runde

/** Eine eigene Leiste statt topbar(): Hier zählt die Runde, nicht der Weg. */
function kopf(titel, zurueck) {
    return `
        <div class="topbar">
            <button class="iconbtn" data-back="${esc(zurueck)}" aria-label="Zurück">&#8249;</button>
            <h1 title="${esc(titel)}">${esc(titel)}</h1>
        </div>`;
}

/**
 * Eine Runde.
 *
 * `zurueck` bringt die Lerneinheit mit, wenn die Runde dort gestartet
 * wurde. Ohne führt der Pfeil in den Kurs - dort liegt die Auswahl, über
 * die man sonst hierher kommt.
 */
export async function freiView(roh, zurueck = null) {
    const unitIds = String(roh).split('-').map(Number).filter((n) => n > 0);
    const erste   = einheit(unitIds[0]);
    zurueck ??= erste === null ? '/' : `/lang/${erste.l}`;

    if (freiUmfang(unitIds).vokabeln === 0) {
        render(`${kopf('Freies Üben', zurueck)}<div id="msg"></div>`);
        wireBack();
        showError('Hier gibt es noch nichts zu üben. '
                  + 'Einmal mit Netz öffnen, dann geht es auch ohne.');
        return;
    }

    runde = { richtig: 0, falsch: 0, folge: 0, beste: 0, unitIds, zurueck,
              adresse: location.hash };
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
    freiMerken(vocabId, richtig);
    if (richtig) meilenstein();
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

function naechste() {
    const a = frageFrei(runde.unitIds);

    if (a === null || a.leer) {
        render(`${kopf('Freies Üben', runde.zurueck)}<div id="msg"></div>`);
        wireBack();
        showError('Hier gibt es nichts zu üben.');
        return;
    }

    if (a.art === MODUS_WAHL) zeigeWahl(a); else zeigeLuecke(a);
}

// ----------------------------------------------------------- Auswählen

function zeigeWahl(a) {
    const richtung = a.nachVorn
        ? `${esc(a.language)} → Deutsch`
        : `Deutsch → ${esc(a.language)}`;

    render(`
        ${kopf('Freies Üben', runde.zurueck)}
        ${zaehlerLeiste()}

        <div class="prompt">
            <div>
                <div class="dir">${richtung}</div>
                <div class="word">${esc(a.frage)}</div>
            </div>
            ${meldeKnopf(true)}
        </div>

        <div class="options" id="options">
            ${a.optionen.map((o, i) => `
                <button class="option" data-index="${i}">${esc(o)}</button>
            `).join('')}
        </div>

        <div class="verdict" id="verdict"></div>
    `);

    verdrahten();
    meldenVerdrahten($('[data-melden]'), () => ({ vocabId: a.vocabId }));

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
        kopf: () => kopf('Freies Üben', runde.zurueck) + zaehlerLeiste(),
        punkte: false,
        zeigen: () => {},
        merken: (data, richtig) => {
            zaehlen(data.vocabId, richtig);
            return {};
        },
        weiter: weiterWennNochHier,
    }, a);
}

/** Was jede Aufgabe braucht. */
function verdrahten() {
    wireBack();
}
