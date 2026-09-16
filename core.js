/* Gemeinsame Bausteine: API-Zugriff, kleine DOM-Helfer, Router-Navigation. */

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

    let res;
    try {
        res = await fetch(url, {
            method: opts.body ? 'POST' : 'GET',
            headers,
            body: opts.body ? JSON.stringify(opts.body) : undefined,
            credentials: 'same-origin',
        });
    } catch {
        throw new ApiError('Keine Verbindung. Bist du online?', 0);
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
            ${backTo === null ? '' : `<button class="iconbtn" data-back="${esc(backTo)}" aria-label="Zurück">&#8249;</button>`}
            ${lead}
            <h1>${esc(title)}</h1>
            ${action}
        </div>`;
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
 * Fuer ein Kind steht dort nichts: Es braucht nicht erklaert zu bekommen,
 * dass es seine eigene App sieht.
 */
export function pupilHint(text = 'So sieht deine Klasse das.') {
    if (!VT.user?.isTeacher) return '';
    return `<div class="notice pupilview">
                <strong>Schüleransicht.</strong> ${esc(text)}
                Freigegeben ist, was hier auftaucht &ndash; mehr sehen die
                Kinder nicht.
            </div>`;
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
    const box = $('#msg', root);
    if (box) {
        box.innerHTML = notice(message, kind);
        box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    } else {
        alert(message);
    }
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
