/* Gemeinsame Bausteine: API-Zugriff, kleine DOM-Helfer, Router-Navigation. */

import { menueAktivieren, themaWahlAktivieren, themaWahlHtml } from './menue.js';

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
            <h1>${esc(title)}</h1>
            ${action}
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
     * Für eine Lehrkraft geht es von hier auch wieder hinaus. Sie kommt
     * her, um zu sehen, was ihre Klasse sieht - und fand dann keinen Weg
     * zurück ausser dem Hinweis oben auf der Seite, den es nicht auf jeder
     * gibt. Einem Kind sagt der Eintrag nichts, also steht er dort nicht.
     */
    const verwaltung = VT.user.isTeacher ? `
        <hr class="mtrenner">
        <a class="mitem" href="${esc(VT.base)}/teacher/">
            <span class="micon" aria-hidden="true">&#128203;</span>
            <span>Zur Verwaltung</span>
        </a>` : '';

    return `
        <details class="menue" id="menuLinks">
            <summary class="burger" aria-label="Menü" title="Menü">
                <span aria-hidden="true">&#9776;</span>
            </summary>
            <span class="schleier" data-zu></span>
            <nav class="schublade" aria-label="Navigation">
                <a class="mitem haupt" href="#/">
                    <span class="micon" aria-hidden="true">&#127968;</span>
                    <span>Meine Kurse</span>
                </a>
                ${eintraege}
                ${verwaltung}
            </nav>
        </details>`;
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

                <hr class="mtrenner">
                ${themaWahlHtml()}
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

/** Aktiviert die Zurück-Buttons aus topbar(). */
export function wireBack(root = document) {
    on('[data-back]', 'click', (e) => go(e.currentTarget.dataset.back), root);
}

/**
 * Der Hinweis fuer die Lehrkraft: Das hier ist die Schueleransicht.
 *
 * Sie kommt aus ihrem Bereich hierher, um auszuprobieren, was ihre Klasse
 * vor sich hat - und seit die Freigabe auch fuer sie gilt, sieht sie genau
 * das. Ohne einen Satz dazu ist es aber nur eine Seite, die weniger zeigt
 * als die Verwaltung, und das sieht nach einem Fehler aus.
 *
 * Und der Weg zurueck gehoert dazu. Der Hinweis sagte bisher, wo man ist,
 * aber nicht, wie man wieder herauskommt: Die App hat keine Adresszeile,
 * wenn sie installiert ist, und ihr Zurueck fuehrt tiefer in die App statt
 * heraus. Der Knopf zeigt auf genau die Stelle der Verwaltung, an der man
 * war - auf den Kurs, auf die Lerneinheit -, nicht auf die Startseite.
 *
 * Eine echte Seitennavigation, kein Wechsel innerhalb der App: Der
 * Lehrkraft-Bereich wird vom Server gebaut und ist kein Teil der PWA.
 *
 * Fuer ein Kind steht dort nichts: Es braucht nicht erklaert zu bekommen,
 * dass es seine eigene App sieht.
 */
export function pupilHint(text = 'So sieht deine Klasse das.', zurueck = '/teacher/') {
    if (!VT.user?.isTeacher) return '';
    return `<div class="notice pupilview">
                <span>
                    <strong>Schüleransicht.</strong> ${esc(text)}
                    Freigegeben ist, was hier auftaucht &ndash; mehr sehen die
                    Kinder nicht.
                </span>
                ${teacherBack('Zurück zur Verwaltung', zurueck)}
            </div>`;
}

/**
 * Der Weg aus der App zurueck in die Verwaltung - fuer eine Lehrkraft.
 *
 * Gebraucht ueberall dort, wo sie aus ihrem Bereich in die App gekommen
 * ist: in der Schueleransicht (dort steckt er im Hinweis) und beim
 * Einlesen. Der Weg dorthin war ein Knopf auf einer Kurskarte; der Weg
 * zurueck darf nicht die Adresszeile sein, denn die installierte App hat
 * keine.
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
 * Holt die App frisch vom Server.
 *
 * In der installierten App gibt es keine Adresszeile und kein Neu-Laden - eine
 * Aktualisierung käme dort sonst erst an, wenn iOS von sich aus nachsieht.
 * Deshalb gründlich: Service Worker abmelden, Zwischenspeicher leeren, mit
 * frischer Adresse neu starten.
 */
export async function hardRefresh() {
    try {
        // Zuerst abmelden, damit die Abrufe unten am Service Worker vorbei
        // wirklich ans Netz gehen.
        if ('serviceWorker' in navigator) {
            const regs = await navigator.serviceWorker.getRegistrations();
            await Promise.all(regs.map((r) => r.unregister()));
        }
        if ('caches' in window) {
            const keys = await caches.keys();
            await Promise.all(keys.map((k) => caches.delete(k)));
        }

        /*
         * Und jetzt jede Datei ausdrücklich neu holen.
         *
         * Nur app.js trägt einen Versionsstempel in der Adresse; core.js und
         * die Ansichten werden mit blankem Pfad importiert. Ohne diesen
         * Schritt bliebe es dem Browser überlassen, ob er sie für frisch
         * genug hält - und genau daran ist das Aktualisieren bisher
         * gescheitert. cache: 'reload' geht am Zwischenspeicher vorbei und
         * legt die neue Fassung gleich dort ab.
         */
        const dateien = Array.isArray(VT.assets) ? VT.assets : [];
        await Promise.all(dateien.map(
            (pfad) => fetch(`${VT.base}/${pfad}`, { cache: 'reload' }).catch(() => {}),
        ));
    } catch (err) {
        // Auch ohne Leeren ist ein Neustart besser als gar nichts.
        console.warn('Zwischenspeicher nicht vollständig geleert:', err);
    }

    // Der Zeitstempel umgeht den Zwischenspeicher des Browsers; app.js meldet
    // den Service Worker beim nächsten Laden von selbst wieder an.
    window.location.replace(`${VT.base}/?frisch=${Date.now()}`);
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
