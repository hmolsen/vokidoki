/* Gemeinsame Bausteine: API-Zugriff, kleine DOM-Helfer, Router-Navigation. */

import {
    menueAktivieren, themaWahlAktivieren, themaWahlHtml, ansichtWahlHtml,
} from './menue.js';
import { frischHolen } from './aktualisieren.js';

export const VT = window.VT;

export class ApiError extends Error {
    constructor(message, status) {
        super(message);
        this.status = status;
    }
}

/**
 * Ruft einen JSON-Endpunkt auf.
 * Der Header X-Vokabeltrainer ist der CSRF-Schutz: Ein fremdes Formular kann
 * ihn nicht setzen, und ein fetch() von fremder Herkunft scheitert am Preflight.
 */
export async function api(file, action, opts = {}) {
    const params = new URLSearchParams({ action, ...(opts.query || {}) });
    const url = `${VT.base}/api/${file}.php?${params}`;

    const headers = { 'X-Vokabeltrainer': '1' };
    if (opts.body) headers['Content-Type'] = 'application/json';

    const senden = () => fetch(url, {
        method: opts.body ? 'POST' : 'GET',
        headers,
        body: opts.body ? JSON.stringify(opts.body) : undefined,
        credentials: 'same-origin',
    });

    /*
     * Faellt der Abruf ohne Status um, einmal nachfassen.
     *
     * "Ohne Status" heisst: Es kam gar keine Antwort - die Verbindung ist
     * nicht zustandegekommen oder mittendrin weggebrochen. Das passiert
     * auch im besten Netz: Der Browser haelt Verbindungen offen und
     * benutzt sie wieder, der Server macht sie nach einer Weile zu, und
     * wenn beides im selben Augenblick geschieht, faellt genau eine
     * Anfrage um. Beim Ueben trifft das oft genug, dass es auffaellt -
     * dort gehen viele kurz hintereinander raus.
     *
     * Ein zweiter Versuch auf einer frischen Verbindung laeuft dann
     * durch. Genau einer: Ist wirklich kein Netz da, soll das Kind das
     * nach einem Wimpernschlag erfahren und nicht nach einer Minute.
     *
     * Wiederholt wird nur dieser Fall. Eine Antwort, die ankam und "nein"
     * sagte, wird nicht noch einmal geschickt - bei einem POST waere das
     * dieselbe Anderung zweimal.
     */
    let res;
    try {
        res = await senden();
    } catch {
        await new Promise((fertig) => setTimeout(fertig, 400));
        try {
            res = await senden();
        } catch {
            throw new ApiError('Keine Verbindung. Bist du online?', 0);
        }
    }

    let data;
    try {
        data = await res.json();
    } catch {
        throw new ApiError('Der Server hat unerwartet geantwortet.', res.status);
    }

    if (!data.ok) {
        if (res.status === 401) {
            VT.user = null;
            go('/login');
        }
        /*
         * Die Hinweise sind (wieder) zu bestätigen - etwa weil sich ihre
         * Fassung geändert hat, während die App offen lag. Neu laden: Die
         * Hülle bringt die Punkte zum Anhaken mit, und route() schickt dann
         * auf die Seite dafür.
         */
        if (res.status === 403 && data.einwilligung && !VT.user?.einwilligung) {
            window.location.hash = '#/einwilligung';
            window.location.reload();
        }
        throw new ApiError(data.error || 'Etwas ist schiefgelaufen.', res.status);
    }
    return data;
}

// ------------------------------------------------------------------ DOM

export function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[c]);
}

/**
 * Eine Fahne als Bild.
 *
 * Die Fahnen stehen als Emoji in der Datenbank, und auf dem Handy sehen sie
 * gut aus. Windows stellt die Regionalzeichen aber nicht als Fahnen dar,
 * sondern als die zwei Buchstaben des Länderkürzels - aus der britischen
 * Fahne wird "GB". Daran ändert keine Schriftart der Seite etwas; das Bild
 * muss mitgebracht werden, und es liegt in assets/flags.
 *
 * Ob die Datei da ist, kann der Browser vorher nicht wissen. Er versucht es
 * deshalb einfach - und wenn nichts kommt, setzt der Hörer weiter unten das
 * Emoji an ihre Stelle. Auf dem Handy sieht das dann aus wie vorher.
 */
export function flagHtml(emoji, klasse = 'flag') {
    const text = String(emoji ?? '').trim();
    if (text === '') return '';

    const name = [...text]
        .map((z) => z.codePointAt(0))
        .filter((n) => n !== 0xfe0f)      // "bitte farbig" gehört nicht zum Zeichen
        .map((n) => n.toString(16))
        .join('-');
    if (name === '') return '';

    return `<img class="${esc(klasse)}" src="${esc(VT.base)}/assets/flags/${esc(name)}.svg"`
         + ` alt="" width="24" height="24" data-emoji="${esc(text)}">`;
}

/*
 * Fehlt die Datei, tritt das Emoji an ihre Stelle.
 *
 * Ein Hörer für das ganze Dokument statt ein onerror an jedem Bild: Die
 * Fahnen entstehen an einem halben Dutzend Stellen als Zeichenkette, und
 * jede davon müsste sonst daran denken. error steigt nicht auf, deshalb in
 * der Abwärtsphase.
 */
document.addEventListener('error', (e) => {
    const bild = e.target;
    if (!(bild instanceof HTMLImageElement) || !bild.dataset.emoji) return;

    const ersatz = document.createElement('span');
    ersatz.className   = bild.className;
    ersatz.textContent = bild.dataset.emoji;
    bild.replaceWith(ersatz);
}, true);

/** Setzt den Inhalt des Views und liefert den Container zurück. */
export function render(html) {
    const app = document.getElementById('app');

    /*
     * Das Feuerwerk der Geschafft-Seite laeuft, bis jemand weiterklickt -
     * und genau das ist hier. Jeder Wechsel der Ansicht kommt durch diese
     * Zeile, ob ueber einen Knopf, das Menue oder den Zurueck-Pfeil.
     *
     * Das Konfetti ist ausdruecklich NICHT gemeint: Es soll ueber der
     * naechsten Frage weiterfliegen, und die wird hier gerade gezeichnet.
     */
    feuerwerkAus();

    app.innerHTML = html;

    /*
     * Die beiden Schubladen verdrahten - hier und nicht in jeder Ansicht.
     *
     * Die Leiste wird bei jedem Wechsel neu gezeichnet, und mit ihr die
     * Menüs. Hätte jede Ansicht das selbst zu tun, wäre es achtzehnmal
     * dieselbe Zeile, und die neunzehnte fehlte.
     */
    navAktivieren(app);

    // Ansichten, die sich an die sichtbare Höhe binden, bringen ein .screen
    // mit. Dann wird zusätzlich das Dokument selbst festgesetzt: Solange
    // html und body scrollen können, schiebt iOS beim Fokus die ganze Seite
    // nach oben, ganz gleich wie hoch der Container ist.
    const fest = app.querySelector(':scope > .screen') !== null;
    app.classList.toggle('fitted', fest);
    document.documentElement.classList.toggle('locked', fest);

    app.scrollTop = 0;
    window.scrollTo(0, 0);
    return app;
}

export function $(selector, root = document) {
    return root.querySelector(selector);
}

export function $$(selector, root = document) {
    return Array.from(root.querySelectorAll(selector));
}

/** Klick-Handler an alle Treffer hängen. */
export function on(selector, event, handler, root = document) {
    $$(selector, root).forEach((el) => el.addEventListener(event, handler));
}

// ------------------------------------------------------------------ Navigation

export function go(path, replace = false) {
    const target = `#${path}`;
    if (location.hash === target) {
        window.dispatchEvent(new HashChangeEvent('hashchange'));
        return;
    }
    if (replace) location.replace(target);
    else location.hash = target;
}

// ------------------------------------------------------------------ Bausteine

export function topbar(title, { backTo = null, action = '', lead = '' } = {}) {
    return `
        <div class="topbar">
            ${navLinksHtml()}
            ${backTo === null ? '' : `<button class="iconbtn" data-back="${esc(backTo)}" aria-label="Zurück">&#8249;</button>`}
            ${lead}
            <h1 title="${esc(title)}">${esc(title)}</h1>
            ${action}
            ${serieHtml()}
            ${navRechtsHtml()}
        </div>`;
}

/* ------------------------------------------------------- Die beiden Menüs
 *
 * Dieselben zwei Schubladen wie im Lehrkraft-Bereich: links die Kurse,
 * rechts das eigene Konto. Dort standen sie schon, in der Kinderansicht
 * fehlten sie - Kurs wechseln hiess zurück, zurück, antippen, und die
 * Einstellungen lagen hinter einem Zahnrad, das nur auf der Startseite
 * stand.
 *
 * Woher die Kurse kommen, weiss diese Datei nicht: Sie liegen im Vorrat,
 * und vorrat.js holt sich von hier VT und api(). Ein Import in die andere
 * Richtung wäre ein Ring. Stattdessen reicht app.js beim Start eine
 * Funktion herein.
 */
let navQuelleFn = () => ({ kurse: [], aktiv: null });

/**
 * Der Kopf der linken Schublade: das Wortzeichen wie auf der Anmeldung, und
 * darunter die Schule. Dasselbe steht im Lehrkraft-Bereich (teacher_nav() in
 * teacher/_boot.php) - dort vom Server gebaut, hier im Gerät; die Gestalt
 * kommt aus style.css und ist für beide dieselbe.
 */
function menueKopfHtml(start) {
    const schule = VT.user?.school ?? '';
    return `
        <div class="mkopf">
            <a href="${esc(start)}" class="mlogo" aria-label="Vokidoki - zur Startseite">
                <img src="${esc(VT.base)}/assets/vokidoki_logo.svg" alt="Vokidoki"
                     width="768" height="256">
            </a>
            ${schule ? `<span class="mschule">${esc(schule)}</span>` : ''}
        </div>`;
}

/** app.js sagt, woher die Kursliste kommt und welcher Kurs gerade offen ist. */
export function navQuelle(fn) {
    navQuelleFn = fn;
}

function navLinksHtml() {
    if (!VT.user) return '';

    const { kurse, aktiv } = navQuelleFn();

    const eintraege = kurse.length === 0
        ? '<p class="mleer tiny muted">Hier ist noch nichts freigegeben.</p>'
        : `<div class="mgruppe">${kurse.map((k) => `
            <a class="mitem${k.id === aktiv ? ' on' : ''}" href="#/lang/${k.id}"
               ${k.id === aktiv ? 'aria-current="page"' : ''}>
                <span class="micon" aria-hidden="true">${
                    flagHtml(k.flag_emoji || '\u{1F310}', 'mflagge')
                }</span>
                <span>${esc(k.name)}</span>
            </a>`).join('')}</div>`;

    /*
     * Hier stand für eine Lehrkraft "Zur Verwaltung".
     *
     * Der Eintrag ist weg, weil der Schalter im rechten Menü dasselbe kann
     * und mehr: Er führt auf die ENTSPRECHUNG dieser Seite statt immer auf
     * die Startseite, und er zeigt nebenbei, in welcher der beiden
     * Ansichten man gerade steht. Zwei Wege für eine Bewegung sind einer
     * zu viel - und der schlechtere von beiden stand im falschen Menü:
     * Links stehen die Kurse, rechts steht, wie man die Anwendung sieht.
     */

    return `
        <details class="menue" id="menuLinks">
            <summary class="burger" aria-label="Menü" title="Menü">
                <span aria-hidden="true">&#9776;</span>
            </summary>
            <span class="schleier" data-zu></span>
            <nav class="schublade" aria-label="Navigation">
                ${menueKopfHtml('#/')}
                <a class="mitem haupt" href="#/">
                    <span class="micon" aria-hidden="true">&#127968;</span>
                    <span>Meine Kurse</span>
                </a>
                ${eintraege}
            </nav>
        </details>`;
}

/**
 * Die Entsprechung der aktuellen Seite in der Verwaltung.
 *
 * Drei Seiten haben eine: die Kursliste, ein Kurs und eine Lerneinheit.
 * Üben und Lückentext gehören zu ihrer Lerneinheit und führen dorthin -
 * es ist dieselbe Lerneinheit, nur in Betrieb. Alles andere - das eigene
 * Konto, das Einlesen - führt auf die Startseite der Verwaltung.
 *
 * Die Kennung des Kurses steht nicht in der Adresse: Dort steht die der
 * SPRACHE. Sie kommt aus der Kursliste, die app.js hereinreicht, und nur
 * eine Lehrkraft bekommt sie überhaupt mitgeliefert.
 */
function verwaltungZiel() {
    const pfad = location.hash.replace(/^#/, '') || '/';

    const einheit = pfad.match(/^\/(?:unit|quiz|cloze)\/(\d+)/);
    if (einheit) return `${VT.base}/teacher/unit.php?id=${einheit[1]}`;

    const kurs = pfad.match(/^\/lang\/(\d+)/);
    if (kurs) {
        const k = navQuelleFn().kurse.find((x) => x.id === Number(kurs[1]));
        if (k?.course_id) return `${VT.base}/teacher/course.php?id=${k.course_id}`;
    }

    return `${VT.base}/teacher/`;
}

function navRechtsHtml() {
    if (!VT.user) return '';

    /*
     * In der installierten App steht unten kein Abmelden, sondern
     * Aktualisieren. Das Symbol auf dem Home-Bildschirm gehört genau einem
     * Kind - sich dort abzumelden hilft niemandem und nimmt nur den Zugang.
     * Was dort dafür fehlt, ist ein Weg zu einer neuen Fassung: keine
     * Adresszeile, kein Neu-Laden.
     */
    const letzte = VT.standalone
        ? `<button class="mitem" type="button" data-nav-refresh>
               <span class="micon" aria-hidden="true">&#8635;</span>
               <span>App aktualisieren</span>
           </button>`
        : `<button class="mitem" type="button" data-nav-logout>
               <span class="micon" aria-hidden="true">&#9099;</span>
               <span>Abmelden</span>
           </button>`;

    return `
        <details class="menue rechts" id="menuRechts">
            <summary class="burger" aria-label="Einstellungen" title="Einstellungen">
                <span aria-hidden="true">&#9881;</span>
            </summary>
            <span class="schleier" data-zu></span>
            <nav class="schublade" aria-label="Einstellungen">
                <p class="mkopf">${esc(VT.user.name)}</p>

                <a class="mitem" href="#/konto">
                    <span class="micon" aria-hidden="true">&#128100;</span>
                    <span>Mein Profil</span>
                </a>
                <a class="mitem" href="#/konto/passwort">
                    <span class="micon" aria-hidden="true">&#128273;</span>
                    <span>Passwort ändern</span>
                </a>
                <a class="mitem" href="#/lernstatistik">
                    <span class="micon" aria-hidden="true">&#128202;</span>
                    <span>Lernstatistik</span>
                </a>

                <hr class="mtrenner">
                ${VT.user.isTeacher ? ansichtWahlHtml(esc(verwaltungZiel()))
                                      + '<hr class="mtrenner">' : ''}
                ${themaWahlHtml()}
                ${tonWahlHtml()}
                <hr class="mtrenner">

                ${rechtsItems()}

                <hr class="mtrenner">

                ${letzte}
            </nav>
        </details>`;
}

/*
 * Impressum, Datenschutz, Lizenzen.
 *
 * Als gewöhnliche Seiten vom Server, nicht als Ansichten der App: Sie
 * müssen erreichbar sein, bevor jemand angemeldet ist, und wer ihre
 * Adresse weitergibt, soll sie sehen. Eine Ansicht hinter dem Hash wäre
 * beides nicht.
 */
const RECHTSTEXTE = [
    ['impressum',   'Impressum'],
    ['datenschutz', 'Datenschutz'],
    ['lizenzen',    'Lizenzen'],
];

function rechtsItems() {
    return RECHTSTEXTE.map(([k, name]) => `
        <a class="mitem" href="${esc(VT.base)}/rechtliches.php?d=${k}">
            <span class="micon" aria-hidden="true">&#167;</span>
            <span>${esc(name)}</span>
        </a>`).join('');
}

/**
 * Dieselben drei als Zeile ganz unten.
 *
 * Gebraucht dort, wo es kein Menü gibt - auf der Anmeldeseite - und auf
 * der Startseite, weil man rechtliche Hinweise unten sucht.
 */
export function rechtsZeile() {
    return `<nav class="rechtszeile" aria-label="Rechtliches">${
        RECHTSTEXTE.map(([k, name]) =>
            `<a href="${esc(VT.base)}/rechtliches.php?d=${k}">${esc(name)}</a>`).join('')
    }</nav>`;
}

/** Was nach jedem Zeichnen zu tun ist, damit die Menüs leben. */
function navAktivieren(wurzel) {
    menueAktivieren(wurzel);
    themaWahlAktivieren(wurzel);
    tonWahlAktivieren(wurzel);

    wurzel.querySelector('[data-nav-refresh]')?.addEventListener('click', async (e) => {
        const k = e.currentTarget;
        k.disabled = true;
        k.querySelector('.micon').innerHTML = '<span class="spinner inline"></span>';
        await hardRefresh();
    });

    wurzel.querySelector('[data-nav-logout]')?.addEventListener('click', async () => {
        if (!confirm('Abmelden? Dein Homescreen-Symbol bleibt bestehen.')) return;
        try {
            await api('auth', 'logout', { body: {} });
        } catch { /* auch bei Fehler zum Login */ }
        /*
         * Und der Vorrat geht mit - er gehört diesem Kind. Auf einem
         * geteilten Tablet hätte das nächste sonst die Vokabeln des
         * vorigen im Gerät liegen. Wer das aufräumt, weiss nur vorrat.js;
         * app.js reicht es beim Start herein.
         */
        try { await abmeldeAufraeumer(); } catch { /* egal */ }
        window.location.href = `${VT.base}/`;
    });
}

let abmeldeAufraeumer = async () => {};

/** app.js sagt, was beim Abmelden noch wegzuräumen ist. */
export function navAbmelden(fn) {
    abmeldeAufraeumer = fn;
}

/* ------------------------------------------------------------- Die Serie
 *
 * Das Abzeichen links vom Zahnrad: Voki und die Zahl der Tage.
 *
 * Woher die Zahl kommt, weiss diese Datei nicht - sie liegt im Vorrat, und
 * vorrat.js holt sich von hier VT und api(). Ein Import in die andere
 * Richtung waere ein Ring, genau wie bei der Kursliste. Also reicht app.js
 * beim Start auch hier eine Funktion herein.
 */

let serieQuelleFn = () => null;

/** app.js sagt, woher der Stand der Serie kommt. */
export function serieQuelle(fn) {
    serieQuelleFn = fn;
}

/**
 * Das Abzeichen.
 *
 * Als <details class="menue">, damit es dieselbe Mechanik bekommt wie die
 * beiden Schubladen: Auf- und Zuklappen ohne Skript, Escape schliesst, ein
 * Druck daneben auch. Nur sieht es anders aus - eine Karte in der Mitte
 * statt einer Schublade am Rand.
 *
 * Und es ist ein Knopf, kein Bild: Die Regel dahinter ist nicht
 * selbsterklaerend, am wenigsten die zweite Tuer ueber die Wiederholungen.
 * Ein Kind, das sich fragt, warum die Zahl heute grau ist, soll darauf
 * tippen koennen und eine Antwort bekommen.
 */
export function serieHtml() {
    if (!VT.user) return '';

    const s = serieQuelleFn();
    if (s === null) return '';

    /*
     * Froh oder traurig entscheidet das Bild, farbig oder grau das Stilblatt.
     * Bei 'offen' ist Voki froh, aber grau: Es ist ja noch nichts verloren -
     * es fehlt nur die Farbe, und die holt man sich heute noch.
     */
    const froh  = s.lage === 'heute' || s.lage === 'offen';
    const datei = froh ? 'voki-mini.svg' : 'voki-sad-mini.svg';

    return `
        <details class="menue serie" id="menuSerie">
            <summary class="seriebtn lage-${esc(s.lage)}" title="Deine Serie"
                     aria-label="${esc(serieVorlesen(s))}">
                <img class="serievoki" src="${esc(VT.base)}/assets/${datei}"
                     alt="" width="30" height="30">
                <span class="seriezahl">${s.zahl}</span>
            </summary>
            <span class="schleier" data-zu></span>
            <nav class="seriekarte" aria-label="Deine Serie">
                ${serieKarteHtml(s)}
            </nav>
        </details>`;
}

/** Was ein Vorleseprogramm sagt - die Zahl allein waere dort sinnlos. */
function serieVorlesen(s) {
    if (s.lage === 'aus') return 'Deine Serie: noch keine. Antippen für mehr.';
    const tage = s.zahl === 1 ? '1 Tag' : `${s.zahl} Tage`;
    if (s.lage === 'heute') return `Deine Serie: ${tage}, heute schon gelernt. Antippen für mehr.`;
    if (s.lage === 'offen') return `Deine Serie: ${tage}, heute noch nicht gelernt. Antippen für mehr.`;
    return `Deine Serie: ${tage} und in Gefahr. Antippen für mehr.`;
}

/**
 * Was in der Karte steht - kurz.
 *
 * Hier stand eine ganze Seite: die Lage, die Regel, die Ausnahme, die
 * Bestmarke. Wer auf das Abzeichen tippt, will aber nur zwei Dinge wissen:
 * Wie steht es, und was fehlt heute noch? Also Voki groß - froh, wenn der
 * Tag geschafft ist, traurig, wenn nicht -, darunter, was genau noch fehlt,
 * ein Balken von der jetzigen zur besten Serie, und ein Knopf zur
 * Lernstatistik. Die Regeln stehen dort unter "Mehr erfahren".
 *
 * Und ein Kreuz zum Schließen: Nur daneben zu tippen war nicht zu erraten,
 * zumal die Karte fast den ganzen Bildschirm füllt.
 */
function serieKarteHtml(s) {
    const geschafft = s.lage === 'heute';
    const tage = (n) => (n === 1 ? '1 Tag' : `${n} Tage`);

    /*
     * Was heute noch fehlt: eine neue Vokabel - oder so viele richtige
     * Antworten, wie bis zur Schwelle fehlen. Die heutige Zahl steht im
     * Verlauf des Vorrats (serieTage()), dieselbe, die der Kalender zeigt.
     */
    const richtig = s.heuteRichtig ?? 0;
    const fehlen  = Math.max(1, (s.schwelle ?? 10) - richtig);
    const auftrag = `Lerne heute <strong>eine Vokabel</strong> - oder schaffe noch
        <strong>${fehlen} richtige ${fehlen === 1 ? 'Antwort' : 'Antworten'}</strong>.`;

    const text = {
        heute:  `<strong>Heute geschafft!</strong> Deine Serie steht bei ${tage(s.zahl)}.`,
        offen:  `${auftrag} Dann wächst deine Serie auf ${tage(s.zahl + 1)}.`,
        gefahr: `${auftrag} Sonst ist deine Serie von ${tage(s.zahl)} morgen weg.`,
        aus:    `${auftrag} Dann beginnt deine Serie.`,
    }[s.lage] ?? '';

    return `
        <button class="seriezu" type="button" data-zu aria-label="Schließen" title="Schließen">&#10005;</button>
        <img class="seriegrossvoki${geschafft ? '' : ' traurig'}"
             src="${esc(VT.base)}/assets/${geschafft ? 'voki-mini.svg' : 'voki-sad-mini.svg'}"
             alt="" width="120" height="120">
        <p class="seriezahlgross">${tage(s.zahl)}</p>
        <p class="serieauftrag">${text}</p>
        ${serieBalkenHtml(s)}
        <a class="btn" href="#/lernstatistik">Zur Lernstatistik</a>`;
}

/**
 * Die jetzige Serie neben der besten - als Balken.
 *
 * In der Karte und in der Lernstatistik derselbe, deshalb hier. Steht die
 * jetzige auf der besten, ist der Balken voll und grün: Das ist der Rekord.
 */
export function serieBalkenHtml(s) {
    const best = Math.max(s.best ?? 0, s.zahl);
    const anteil = best === 0 ? 0 : Math.round((s.zahl / best) * 100);
    const rekord = best > 0 && s.zahl >= best;
    return `
        <div class="seriebalken${rekord ? ' rekord' : ''}" role="img"
             aria-label="Jetzt ${s.zahl}, beste Serie ${best}">
            <div class="seriebalken-zahlen">
                <span>Jetzt <strong>${s.zahl}</strong></span>
                <span>${rekord ? 'Rekord!' : `Beste <strong>${best}</strong>`}</span>
            </div>
            <div class="bar"><i style="width:${anteil}%"></i></div>
        </div>`;
}

/**
 * Das Abzeichen an Ort und Stelle erneuern.
 *
 * Beim Üben wird die Leiste nicht neu gezeichnet - die Frage wechselt, der
 * Rahmen bleibt stehen. Ohne das spränge die Zahl erst beim nächsten
 * Seitenwechsel weiter, also lange nachdem das Kind sie sich verdient hat.
 *
 * @param feier true, wenn der Tag gerade eben geschafft wurde.
 */
export function serieAktualisieren(feier = false) {
    const alt = document.getElementById('menuSerie');
    if (!alt) return;

    const html = serieHtml();
    if (html === '') return;

    const huelle = document.createElement('div');
    huelle.innerHTML = html;
    const neu = huelle.firstElementChild;
    if (!neu) return;

    // Die Zahl vorher - von ihr aus zählt die große Feier hoch.
    const vorher = parseInt(alt.querySelector('.seriezahl')?.textContent ?? '', 10);
    const nachher = parseInt(neu.querySelector('.seriezahl')?.textContent ?? '', 10);
    if (feier && Number.isFinite(nachher)) {
        serieFeier(Number.isFinite(vorher) ? vorher : nachher - 1, nachher);
    }

    // War die Karte offen, bleibt sie offen - sonst klappt sie einem Kind,
    // das gerade liest, unter den Fingern weg.
    neu.open = alt.open;
    alt.replaceWith(neu);
    menueAktivieren(neu.parentElement ?? document);

    if (feier) {
        const knopf = neu.querySelector('.seriebtn');
        knopf?.classList.add('feiert');
        knopf?.addEventListener('animationend', () => knopf.classList.remove('feiert'),
                                { once: true });
    }
}

/**
 * Die große Feier, wenn der Tag geschafft ist: Voki tanzt, und die Serie
 * zählt von der alten Zahl auf die neue.
 *
 * Bis hierher wackelte nur das kleine Abzeichen oben - der Augenblick, um
 * den sich die ganze Serie dreht, ging zwischen zwei Fragen unter. Jetzt
 * liegt er groß über der Seite, die Seite verschwommen dahinter.
 *
 * Voki ist voki-mini.svg aus dem Abzeichen, nicht voki.svg: Die hat einen
 * weissen Grund eingebacken und stand als Kachel ueber der verschwommenen
 * Seite. Die kleine ist gezeichnet, also scharf in jeder Groesse, und liegt
 * ohnehin im Speicher - das Abzeichen laedt sie immer, auch ohne Netz.
 *
 * Anders als Konfetti und Feuerwerk hält diese Feier kurz an - einmal am
 * Tag ist das richtig. Ein Druck irgendwohin (oder Escape) schließt sie
 * sofort, und nach dreieinhalb Sekunden geht sie von selbst. Die nächste
 * Frage wird darunter längst gezeichnet.
 */
const SERIEFEIER_MS = 3500;

export function serieFeier(von, bis) {
    document.getElementById('seriefeier')?.remove();

    // Die Serie wächst um einen Tag. Steht vorher schon dieselbe Zahl da
    // (oder gar keine), zählt die Feier trotzdem einen Schritt.
    bis = Math.max(1, bis);
    von = Math.max(0, Math.min(von, bis - 1));

    const ruhig = RUHIG();
    const tage  = bis === 1 ? 'Tag' : 'Tage';
    const leiste = [];
    for (let n = von; n <= bis; n++) leiste.push(`<span>${n}</span>`);

    const f = document.createElement('div');
    f.id = 'seriefeier';
    f.className = 'seriefeier' + (ruhig ? ' ruhig' : '');
    f.setAttribute('role', 'status');
    f.setAttribute('aria-live', 'polite');
    f.innerHTML = `
        <div class="seriefeier-buehne">
            <p class="seriefeier-titel">Serie verlängert!</p>
            <img class="seriefeier-voki" src="${esc(VT.base)}/assets/voki-mini.svg" alt=""
                 width="220" height="220">
            <div class="seriefeier-zahl" aria-hidden="true">
                <span class="seriefeier-band" style="--schritte:${bis - von}">${leiste.join('')}</span>
            </div>
            <p class="seriefeier-text">${tage} in Folge</p>
            <span class="nurvorlesen">Serie verlängert: ${bis} ${tage} in Folge.</span>
        </div>`;

    let zu = false;
    const schliessen = () => {
        if (zu) return;
        zu = true;
        document.removeEventListener('keydown', taste);
        f.classList.add('geht');
        setTimeout(() => f.remove(), ruhig ? 0 : 320);
    };
    const taste = (e) => { if (e.key === 'Escape') schliessen(); };

    f.addEventListener('click', schliessen);
    document.addEventListener('keydown', taste);
    document.body.appendChild(f);
    setTimeout(schliessen, SERIEFEIER_MS);
}

/* ------------------------------------------------------------ Belohnung
 *
 * Drei Stufen, und jede hat ihren Anlass:
 *
 *   ein Punkt   je richtige Antwort - er waechst auf, wird gruen und faellt
 *               zurueck in die Reihe
 *   Konfetti    wenn eine Vokabel sitzt, also beim dritten Mal hintereinander
 *   Feuerwerk   wenn die ganze Lerneinheit steht
 *
 * NICHTS DAVON HAELT DEN ABLAUF AUF. Die Zeit bis zur naechsten Frage bleibt
 * dieselbe wie vorher; Konfetti und Feuerwerk haengen deshalb an <body> und
 * nicht an der Ansicht. Die naechste Frage wird darunter gezeichnet, waehrend
 * es noch fliegt - haetten sie in #app gestanden, waeren sie beim naechsten
 * render() mitten im Flug verschwunden.
 */

/**
 * Die drei Punkte auf den neuen Stand bringen.
 *
 * Bisher standen sie auf dem Stand VOR der Antwort und rueckten erst mit der
 * naechsten Frage nach. Wer zweimal richtig lag, sah zwei Punkte - und beim
 * dritten Mal, dem Augenblick, auf den es ankommt, sah er immer noch zwei.
 *
 * Der frisch hinzugekommene Punkt bekommt .frisch und damit die Animation:
 * aufwachsen, gruen werden, zurueck in die Reihe. Sie laeuft so lange wie der
 * Ton und gehoert zu ihm.
 */
export function punkteAktualisieren(wurzel, streak) {
    const punkte = $$('.dots i', wurzel);
    if (punkte.length === 0) return;

    const jetzt = Math.min(punkte.length, Math.max(0, streak));

    punkte.forEach((p, i) => {
        const anVorher = p.classList.contains('on');
        const anJetzt  = i < jetzt;

        p.classList.toggle('on', anJetzt);
        p.classList.remove('frisch');

        if (anJetzt && !anVorher) {
            // Erzwungener Umbruch, sonst faengt die Animation nicht neu an,
            // wenn derselbe Punkt kurz hintereinander zweimal drankommt.
            void p.offsetWidth;
            p.classList.add('frisch');
        }
    });
}

/**
 * Eine Zahl, die weiterspringt.
 *
 * Dieselbe Bewegung wie beim Punkt in den beiden anderen Übungen: kurz
 * aufwachsen, grün aufleuchten, zurück. Die Zahl steht sofort da - wer
 * richtig geantwortet hat, soll nicht auf die nächste Frage warten müssen,
 * um es gezählt zu sehen.
 */
export function zahlAktualisieren(el, wert) {
    if (!el) return;
    const alt = el.textContent;
    el.textContent = wert;
    if (String(wert) === alt) return;

    el.classList.remove('frisch');
    void el.offsetWidth;   // sonst faengt die Animation nicht neu an
    el.classList.add('frisch');
}

/**
 * Ein kurzes Lob. Kindgerecht, ein bis zwei Woerter, mit Ausrufezeichen.
 *
 * Mehrere, weil dasselbe Wort beim fuenften Mal nichts mehr sagt. Bewusst
 * ohne "Sitzt!" - das steht schon als Rueckmeldung unter der Frage, und
 * zweimal dasselbe Wort auf einem Bildschirm ist eine Verdopplung, keine
 * Steigerung.
 */
const LOB = [
    'Super!', 'Spitze!', 'Klasse!', 'Prima!', 'Sehr gut!', 'Stark!',
    'Toll gemacht!', 'Perfekt!', 'Genau!', 'Bravo!', 'Wunderbar!',
    'Richtig stark!', 'Weiter so!', 'Geschafft!',
];

let letztesLob = -1;

export function lobWort() {
    let i = Math.floor(Math.random() * LOB.length);
    // Nicht zweimal dasselbe hintereinander - genau das faellt auf.
    if (i === letztesLob) i = (i + 1) % LOB.length;
    letztesLob = i;
    return LOB[i];
}

/**
 * Eine Buehne an <body>, unter eigenem Namen.
 *
 * Zwei davon, und das mit Absicht: Das Konfetti der dritten richtigen
 * Antwort fliegt noch, wenn 700 ms spaeter die Geschafft-Seite kommt und das
 * Feuerwerk anfaengt. Laegen beide unter demselben Namen, schnitte das eine
 * das andere mitten im Flug ab.
 */
function buehne(id, klasse) {
    document.getElementById(id)?.remove();

    const b = document.createElement('div');
    b.id = id;
    b.className = `feier ${klasse}`;
    b.setAttribute('aria-hidden', 'true');   // das Lob steht doppelt im Verdict
    document.body.appendChild(b);
    return b;
}

/** Nach der Animation wieder weg - liegenbleiben wuerde sich aufstauen. */
function abraeumen(b, ms) {
    setTimeout(() => { if (b.isConnected) b.remove(); }, ms);
}

const RUHIG = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

/**
 * Konfetti quer ueber den Bildschirm, davor ein Lob.
 *
 * Die Schnipsel starten oben verteilt und fallen mit verschiedener
 * Geschwindigkeit und Drehung - gleiche Werte fuer alle saehen aus wie ein
 * Vorhang, nicht wie Konfetti. Die Zufallswerte stehen als CSS-Variablen am
 * Element; die Bewegung selbst macht das Stilblatt.
 */
export function konfetti(text = lobWort(), darunter = '') {
    const b = buehne('feier', 'konfetti');
    const wort = document.createElement('div');
    wort.className = 'feierwort';
    wort.textContent = text;
    b.appendChild(wort);

    /*
     * Eine zweite Zeile, kleiner: Beim freien Üben sagt sie, WOFÜR gelobt
     * wird - "5 in Folge" oder "25 Richtige!". Ohne sie wäre das Lob ein
     * Ausruf ohne Anlass; mit ihr ist es eine Auskunft, auf die man
     * hinarbeiten kann.
     */
    if (darunter !== '') {
        const zeile = document.createElement('div');
        zeile.className = 'feiergrund';
        zeile.textContent = darunter;
        b.appendChild(zeile);
    }

    if (!RUHIG()) {
        const farben = ['#AFD535', '#4f7cff', '#f5b301', '#e0662a', '#d84f9c', '#2bc4a0'];
        for (let i = 0; i < 36; i++) {
            const s = document.createElement('i');
            s.style.setProperty('--x', `${Math.random() * 100}%`);
            s.style.setProperty('--dx', `${(Math.random() - 0.5) * 240}px`);
            s.style.setProperty('--dreh', `${Math.random() * 1080 - 540}deg`);
            s.style.setProperty('--dauer', `${1.1 + Math.random() * 0.9}s`);
            s.style.setProperty('--ab', `${Math.random() * 0.25}s`);
            s.style.setProperty('--c', farben[i % farben.length]);
            s.style.setProperty('--b', `${5 + Math.random() * 6}px`);
            s.style.setProperty('--h', `${8 + Math.random() * 8}px`);
            b.appendChild(s);
        }
    }

    abraeumen(b, 2400);
}

/**
 * Feuerwerk - fuer die ganze Lerneinheit.
 *
 * Es laeuft, bis jemand weiterklickt: Die Geschafft-Seite ist kein Durchgang,
 * sondern der Augenblick, auf den zwanzig Vokabeln hingearbeitet haben. Drei
 * Raketen und Schluss waren zu Ende, bevor ein Kind aufgesehen hatte.
 *
 * Es liegt HINTER der Seite (z-index: -1), nicht darueber - deshalb "im
 * Hintergrund": Die Ueberschrift und die beiden Knoepfe bleiben lesbar, und
 * die Raketen steigen drumherum.
 *
 * Beendet wird es nicht hier, sondern in render(): Jeder Wechsel der Ansicht
 * macht es aus, ganz gleich ob ueber die beiden Knoepfe, das Menue oder den
 * Zurueck-Pfeil. Ein eigener Hoerer je Ausgang waere einer zu wenig.
 */
const FEUERWERK_FARBEN = ['#AFD535', '#4f7cff', '#f5b301', '#d84f9c', '#2bc4a0'];
const FUNKEN_JE_RAKETE = 18;

/** Abstand zwischen zwei Raketen. */
const RAKETE_ALLE = 850;

let feuerwerkUhr = null;

/** Eine einzelne Rakete: ein Ring aus Funken, der sich selbst wieder abraeumt. */
function rakete(buehne, farbe) {
    const x = 12 + Math.random() * 76;
    const y = 14 + Math.random() * 54;
    const gruppe = document.createElement('div');
    gruppe.className = 'rakete';

    let laengste = 0;
    for (let i = 0; i < FUNKEN_JE_RAKETE; i++) {
        /*
         * Der Winkel leicht verwackelt und jede Dauer eine andere - sonst
         * sind alle Funken zu jedem Zeitpunkt gleich weit draussen, und
         * daraus wird ein Ring statt einer Explosion.
         */
        const winkel = (i / FUNKEN_JE_RAKETE) * Math.PI * 2 + (Math.random() - 0.5) * 0.25;
        const weite  = 80 + Math.random() * 90;
        const dauer  = 0.85 + Math.random() * 0.5;
        laengste = Math.max(laengste, dauer);

        const f = document.createElement('i');
        f.style.setProperty('--x', `${x}%`);
        f.style.setProperty('--y', `${y}%`);
        f.style.setProperty('--dx', `${Math.cos(winkel) * weite}px`);
        f.style.setProperty('--dy', `${Math.sin(winkel) * weite}px`);
        f.style.setProperty('--ab', '0s');
        f.style.setProperty('--dauer', `${dauer}s`);
        f.style.setProperty('--c', farbe);
        gruppe.appendChild(f);
    }

    buehne.appendChild(gruppe);
    // Ohne das wuechse die Seite mit jeder Rakete um achtzehn Elemente.
    setTimeout(() => gruppe.remove(), (laengste + 0.2) * 1000);
}

export function feuerwerk() {
    feuerwerkAus();
    const b = buehne('feuerwerk', 'feuerwerk');

    if (RUHIG()) return;   // Die Buehne bleibt leer, aber sie bleibt.

    let n = 0;
    const steigen = () => {
        /*
         * Im Hintergrund still sein. Eine Uhr, die in einem versteckten Tab
         * weiterlaeuft, kostet Strom fuer etwas, das niemand sieht - und die
         * Geschafft-Seite bleibt gerne mal offen liegen.
         */
        if (document.hidden) return;
        rakete(b, FEUERWERK_FARBEN[n++ % FEUERWERK_FARBEN.length]);
    };

    steigen();
    setTimeout(steigen, 320);   // die zweite gleich hinterher, dann im Takt
    feuerwerkUhr = setInterval(steigen, RAKETE_ALLE);
}

/** Schluss damit - gerufen von render(), also bei jedem Wechsel der Ansicht. */
export function feuerwerkAus() {
    if (feuerwerkUhr !== null) {
        clearInterval(feuerwerkUhr);
        feuerwerkUhr = null;
    }
    document.getElementById('feuerwerk')?.remove();
}

/* ----------------------------------------------------------------- Der Ton
 *
 * Ein kleines Glöckchen bei jeder richtigen Antwort.
 *
 * Erzeugt statt geladen: Eine Tondatei waere eine weitere Datei, die ohne
 * Netz da sein muss und beim ersten Mal zu spaet kommt. Ein paar Sinustoene
 * mit der richtigen Huellkurve sind ein paar Zeilen und klingen sofort.
 *
 * WARUM MEHRERE TOENE JE ANSCHLAG: Hier stand einmal ein Dreieckton mit
 * einer Huellkurve von 0,28 Sekunden. Das war ein Piepser - abgeschnitten,
 * bevor er klingen konnte, und obendrein mit der Obertonreihe eines
 * Rechtecksignals, also eher Spielzeugtrompete als Glocke.
 *
 * Eine Glocke besteht aus mehreren Teiltoenen, die NICHT die ganzzahligen
 * Vielfachen des Grundtons sind - beim Glockenspiel ungefaehr 1 : 2,7 : 5,4
 * : 8,9. Und sie verklingen verschieden schnell: Die hohen sind im Anschlag
 * am lautesten und als erste weg, der Grundton traegt den Nachhall. Genau
 * dieses Auseinanderlaufen macht den Unterschied zwischen "Glocke" und
 * "Ton". Nachgebaut wird es hier mit einem Oszillator je Teilton.
 *
 * Der AudioContext entsteht erst beim ersten Antippen. Nicht aus Sparsamkeit:
 * iOS laesst Ton nur zu, wenn eine Geste dahintersteht, und ein Kontext, der
 * beim Laden der Seite entsteht, bleibt dort fuer immer stumm.
 */

/** Der Schluessel im localStorage. Voreingestellt ist an. */
export const TON_SCHLUESSEL = 'vt-ton';

export function tonAn() {
    try {
        return localStorage.getItem(TON_SCHLUESSEL) !== 'aus';
    } catch {
        return true;
    }
}

export function tonSetzen(an) {
    try {
        if (an) localStorage.removeItem(TON_SCHLUESSEL);
        else localStorage.setItem(TON_SCHLUESSEL, 'aus');
    } catch { /* privates Fenster: dann gilt es eben nur für diesen Besuch */ }
}

/**
 * Die Teiltöne eines Glöckchens.
 *
 * Je Zeile: Verhältnis zum Grundton, Anteil an der Lautstärke, und wie lange
 * dieser Teilton im Verhältnis zum Nachhall braucht. Die Verhältnisse sind
 * die eines Glockenspiels und ausdrücklich nicht ganzzahlig - wären sie es,
 * klänge es nach Orgelpfeife.
 */
const GLOCKE = [
    [1.00, 1.00, 1.00],
    [2.76, 0.42, 0.50],
    [5.40, 0.18, 0.28],
    [8.93, 0.08, 0.16],
];

/** So lange klingt der Grundton nach. */
const NACHHALL = 1.7;

let hoerer = null;
let summe  = null;

/**
 * Ein Anschlag: ein Glöckchen auf diesem Grundton.
 *
 * Der Ausklang läuft exponentiell - so verklingt alles, was angeschlagen
 * wird, und nur so klingt es natürlich. Die letzten Millisekunden gehen
 * linear auf die Null: Ein exponentieller Verlauf erreicht sie nie, und ein
 * Oszillator, der bei einem Restwert abgeschaltet wird, knackt.
 */
function anschlag(zeit, grundton, staerke) {
    for (const [teil, anteil, dauer] of GLOCKE) {
        const hz = grundton * teil;
        // Über der Hörgrenze braucht es keinen Oszillator mehr - er kostet
        // nur und kann auf schlechten Wandlern als Alias zurückfalten.
        if (hz > 16000) continue;

        const ton   = hoerer.createOscillator();
        const kurve = hoerer.createGain();
        ton.type = 'sine';
        ton.frequency.value = hz;

        const spitze = staerke * anteil;
        const aus    = zeit + NACHHALL * dauer;

        kurve.gain.setValueAtTime(0.0001, zeit);
        // Der Anschlag selbst: drei Millisekunden. Weniger knackt, mehr
        // klingt angeblasen statt angeschlagen.
        kurve.gain.exponentialRampToValueAtTime(spitze, zeit + 0.003);
        kurve.gain.exponentialRampToValueAtTime(0.0001, aus);
        kurve.gain.linearRampToValueAtTime(0, aus + 0.03);

        ton.connect(kurve).connect(summe);
        ton.start(zeit);
        ton.stop(aus + 0.04);
    }
}

/**
 * Der Klang auch bei stummgeschaltetem iPhone.
 *
 * iOS spielt Web Audio in der Kategorie "ambient": Steht der Schalter an
 * der Seite auf lautlos, bleibt das Glöckchen stumm - und auf Kindergeräten
 * steht er fast immer dort. Lautlos meint aber den Klingelton, nicht eine
 * App, die man gerade bedient; ein Video spielt ja auch.
 *
 * Seit iOS 17 sagt man es direkt: navigator.audioSession.type = 'playback'.
 * Davor gibt es nur den Umweg über ein <audio>-Element - das spielt in der
 * Kategorie "playback", und läuft es einmal, gilt die für die ganze Seite,
 * auch für Web Audio. Gespielt wird dafür eine winzige stille WAV-Datei,
 * und zwar beim ersten Antippen: Ein <audio> startet nur aus einer Geste,
 * und babing() kommt nicht immer aus einer - mancher Anschlag folgt erst auf
 * die Antwort des Servers.
 *
 * Der Preis: "playback" mischt sich nicht. Läuft nebenher Musik, hält iOS
 * sie beim ersten Glöckchen an. Dasselbe tut jedes Lernvideo.
 */
function audioSitzungSetzen() {
    try {
        if (navigator.audioSession) navigator.audioSession.type = 'playback';
    } catch { /* ältere Fassung, die das Feld kennt, aber nicht setzen lässt */ }
}

const IOS = /iP(hone|ad|od)/.test(navigator.userAgent)
    || (navigator.userAgent.includes('Macintosh') && navigator.maxTouchPoints > 1);

if (IOS && !navigator.audioSession) {
    const entsperren = () => {
        // Bei ausgeschaltetem Ton noch nicht: Sonst hielte schon das erste
        // Antippen fremde Musik an, ohne dass je ein Glöckchen käme.
        if (!tonAn()) return;
        document.removeEventListener('touchend', entsperren, true);
        try {
            // 44 Bytes Kopf und 100 Bytes Stille, 8 Bit mono, 8000 Hz.
            const n = 100;
            const b = new Uint8Array(44 + n);
            const v = new DataView(b.buffer);
            const text = (o, s) => [...s].forEach((c, i) => { b[o + i] = c.charCodeAt(0); });
            text(0, 'RIFF'); v.setUint32(4, 36 + n, true); text(8, 'WAVEfmt ');
            v.setUint32(16, 16, true); v.setUint16(20, 1, true); v.setUint16(22, 1, true);
            v.setUint32(24, 8000, true); v.setUint32(28, 8000, true);
            v.setUint16(32, 1, true); v.setUint16(34, 8, true);
            text(36, 'data'); v.setUint32(40, n, true);
            b.fill(128, 44);   // 8 Bit ist vorzeichenlos: 128 ist die Nulllinie
            const still = new Audio('data:audio/wav;base64,' + btoa(String.fromCharCode(...b)));
            still.setAttribute('playsinline', '');
            still.play().catch(() => {});
        } catch { /* dann eben nur mit Klingelton */ }
    };
    document.addEventListener('touchend', entsperren, true);
}

/**
 * Zwei Anschläge, der zweite eine Quinte höher - das ist das „Babing".
 *
 * Der Abstand ist kurz genug, dass es ein Klang bleibt und nicht zwei: Der
 * erste hat noch nicht ausgeklungen, wenn der zweite kommt, und beide
 * verklingen zusammen.
 */
export function babing() {
    if (!tonAn()) return;

    try {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return;

        if (hoerer === null) {
            // Vor dem Kontext: Er übernimmt die Kategorie beim Entstehen.
            audioSitzungSetzen();
            hoerer = new Ctx();
            /*
             * Eine gemeinsame Summe für alle Anschläge.
             *
             * Acht Oszillatoren gleichzeitig, und beim schnellen Üben kommt
             * der nächste, bevor der vorige verklungen ist. Ohne einen
             * Regler davor addiert sich das irgendwann über die Eins und
             * übersteuert - und Übersteuerung klingt nach kaputt, nicht
             * nach laut.
             */
            summe = hoerer.createGain();
            summe.gain.value = 0.55;
            summe.connect(hoerer.destination);
        }
        if (hoerer.state === 'suspended') hoerer.resume();

        // Ein Hauch Vorlauf: currentTime ist schon vorbei, wenn die Zeile
        // läuft, und ein Anschlag in der Vergangenheit wird abgeschnitten.
        const jetzt = hoerer.currentTime + 0.005;

        anschlag(jetzt,         784,  0.20);   // G5
        anschlag(jetzt + 0.075, 1175, 0.26);   // D6
    } catch {
        // Kein Ton ist kein Grund, das Üben anzuhalten.
    }
}

/** Der Schalter im rechten Menü. Nur dort - der Lehrkraft-Bereich übt nicht. */
function tonWahlHtml() {
    return `
        <button class="mitem" type="button" data-ton
                aria-pressed="${tonAn() ? 'true' : 'false'}">
            <span class="micon" aria-hidden="true">${tonAn() ? '&#128266;' : '&#128263;'}</span>
            <span>Ton bei richtiger Antwort</span>
            <span class="mschalter" aria-hidden="true">${tonAn() ? 'an' : 'aus'}</span>
        </button>`;
}

function tonWahlAktivieren(wurzel = document) {
    wurzel.querySelector('[data-ton]')?.addEventListener('click', (e) => {
        const knopf = e.currentTarget;
        const neu   = !tonAn();
        tonSetzen(neu);
        knopf.setAttribute('aria-pressed', neu ? 'true' : 'false');
        knopf.querySelector('.micon').innerHTML = neu ? '&#128266;' : '&#128263;';
        knopf.querySelector('.mschalter').textContent = neu ? 'an' : 'aus';
        // Einmal vorspielen, damit man hört, was man gerade eingeschaltet hat.
        if (neu) babing();
    });
}

/** Aktiviert die Zurück-Buttons aus topbar(). */
export function wireBack(root = document) {
    on('[data-back]', 'click', (e) => go(e.currentTarget.dataset.back), root);
}

/**
 * Der Streifen ganz oben: Das hier ist die Lernansicht.
 *
 * Eine Lehrkraft kommt aus ihrem Bereich herueber, um zu sehen, was ihre
 * Klasse vor sich hat - und seit die Freigabe auch fuer sie gilt, sieht sie
 * genau das. Ohne ein Wort dazu ist es nur eine Seite, die weniger zeigt
 * als die Verwaltung, und das sieht nach einem Fehler aus.
 *
 * Hier stand ein Hinweiskasten mit vier Zeilen Erklaerung. Der war richtig,
 * solange er die einzige Auskunft war - inzwischen steht im Zahnrad ein
 * Schalter, der dasselbe sagt UND den Weg zurueck kennt. Zwei Erklaerungen
 * fuer eine Sache sind eine zu viel, und die groessere stand ausgerechnet
 * ueber dem, weswegen man hergekommen ist. Uebrig bleibt ein Streifen: die
 * Antwort auf "wo bin ich hier", in einem Wort.
 *
 * Fuer ein Kind steht dort nichts. Es braucht nicht erklaert zu bekommen,
 * dass es seine eigene App sieht - es kennt gar keine andere.
 */
export function lernansicht() {
    if (!VT.user?.isTeacher) return '';
    return '<div class="lernansicht">Lernansicht</div>';
}

/**
 * Der Weg aus der App zurueck in die Verwaltung - fuer eine Lehrkraft.
 *
 * Gebraucht noch an einer Stelle: beim Einlesen. Der Weg dorthin war ein
 * Knopf auf einer Kurskarte, und der Weg zurueck darf nicht die Adresszeile
 * sein - die installierte App hat keine.
 *
 * In der Lernansicht selbst steht er nicht mehr: Dort fuehrt der Schalter im
 * Zahnrad zurueck, auf jeder Seite an derselben Stelle und auf die
 * Entsprechung genau dieser Seite.
 *
 * Fuer ein Kind ist er leer. Es gibt fuer es keine Verwaltung, und ein
 * Knopf, der nur eine Absage holt, ist schlimmer als keiner.
 */
export function teacherBack(text = 'Meine Kurse', pfad = '/teacher/') {
    if (!VT.user?.isTeacher) return '';
    return `<a class="btn small secondary teacherback" href="${VT.base}${esc(pfad)}">`
         + `&#8249; ${esc(text)}</a>`;
}

export function loading(text = 'Einen Moment...') {
    return `<div class="empty"><div class="spinner"></div>${esc(text)}</div>`;
}

export function notice(text, kind = '') {
    return `<div class="notice ${kind}">${esc(text)}</div>`;
}

export function progressBar(known, total) {
    const pct = total > 0 ? Math.round((known / total) * 100) : 0;
    const done = total > 0 && known >= total;
    return `<div class="bar ${done ? 'done' : ''}"><i style="width:${pct}%"></i></div>`;
}

/** Zeigt einen Fehler oben im aktuellen View an. */
/**
 * Eine Meldung in den Kasten oben.
 *
 * Mit einer Art, weil nicht jede Rueckmeldung eine Absage ist: Ein
 * geaendertes Passwort ist eine gute Nachricht und soll nicht rot
 * erscheinen.
 */
export function showError(message, kind = '', root = document) {
    let box = $('#msg', root);

    /*
     * Und wenn keiner da ist, wird einer angelegt.
     *
     * Hier stand sonst ein alert(). Dieselbe Stoerung sah dadurch
     * verschieden aus, je nachdem, wann sie auftrat: Faellt der Abruf um,
     * waehrend die Ansicht schon steht, gab es einen roten Kasten - faellt
     * er beim Laden um, stand da noch der Ladepunkt ohne #msg, und es
     * wurde ein Popup, das man wegdruecken muss. Zwei Gestalten fuer
     * dieselbe Nachricht, und die haesslichere ausgerechnet im
     * haeufigeren Fall.
     */
    if (!box) {
        const app = (root.getElementById ? root : document).getElementById('app');
        if (!app) return;
        box = document.createElement('div');
        box.id = 'msg';
        app.prepend(box);
    }

    box.innerHTML = notice(message, kind);
    box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

export function clearError(root = document) {
    const box = $('#msg', root);
    if (box) box.innerHTML = '';
}

/**
 * Holt die App frisch vom Server - für "App aktualisieren" im Menü.
 * Das Wie steht in aktualisieren.js, zusammen mit dem Band, das dasselbe tut.
 */
export function hardRefresh() {
    return frischHolen({
        base:   VT.base,
        assets: Array.isArray(VT.assets) ? VT.assets : [],
        ziel:   `${VT.base}/?frisch=${Date.now()}`,
    });
}
/** Button während eines Requests sperren und beschriften. */
export async function withBusy(button, label, fn) {
    const original = button.innerHTML;
    button.disabled = true;
    button.innerHTML = esc(label);
    try {
        return await fn();
    } finally {
        button.disabled = false;
        button.innerHTML = original;
    }
}
