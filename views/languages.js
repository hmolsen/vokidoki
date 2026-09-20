import {
    VT, api, render, esc, $, $$, on, go, topbar, loading, showError, clearError, withBusy,
    hardRefresh, flagHtml,
} from '../core.js';
import {
    sprachen, einheitenDerSprache, vokabelnDerEinheit, vorratVergessen,
} from '../vorrat.js';

/* Die Sprachen, die hier gebraucht werden. Alles andere lässt sich
   im Formular darunter frei eintragen. */
const PRESETS = [
    { flag: '\u{1F1EC}\u{1F1E7}', name: 'Englisch',    code: 'en' },
    { flag: '\u{1F1EB}\u{1F1F7}', name: 'Französisch', code: 'fr' },
    { flag: '\u{1F3DB}\u{FE0F}',  name: 'Latein',      code: 'la' },
    { flag: '\u{1F1E9}\u{1F1F0}', name: 'Dänisch',     code: 'da' },
];

export async function languagesView() {
    /*
     * Die Kacheln kommen aus dem Vorrat, nicht vom Server.
     *
     * Kein Ladepunkt mehr: Wer die App oeffnet, sieht seine Kurse sofort -
     * auch im Zug. Der Vorrat wird beim Start im Hintergrund aufgefrischt;
     * kommt dabei etwas Neues, zeichnet app.js diese Ansicht noch einmal.
     */
    const languages = sprachen();

    const tiles = languages.map((lang) => {
        const woerter = einheitenDerSprache(lang.id)
            .reduce((summe, u) => summe + vokabelnDerEinheit(u.i).length, 0);
        return `
        <button class="tile" data-lang="${lang.id}">
            ${flagHtml(lang.flag_emoji || '\u{1F310}')}
            <span class="name">${esc(lang.name)}</span>
            <span class="meta">${woerter} Vokabeln</span>
        </button>`;
    }).join('');

    render(`
        ${topbar(VT.user.appName, { action: cornerButton() })}
        <div id="msg"></div>
        ${teacherLink()}
        ${languages.length === 0 ? `
            <div class="empty">
                <span class="big">\u{1F310}</span>
                ${darfAnlegen()
                    ? 'Noch keine Sprache angelegt.<br>Leg unten deine erste an.'
                    : VT.user.isTeacher
                        ? 'Noch kein Kurs, in dem du drin bist.<br>Leg ihn in der Verwaltung an.'
                        : 'Hier ist noch nichts für dich freigegeben.<br>Deine Lehrkraft macht den ersten Kurs auf.'}
            </div>` : ''}
        <div class="grid">
            ${tiles}
            ${darfAnlegen() ? `
                <button class="tile add" id="add">
                    <span class="flag">+</span>
                    <span class="name">Sprache</span>
                </button>` : ''}
        </div>
        ${installHint()}
    `);

    on('[data-lang]', 'click', (e) => go(`/lang/${e.currentTarget.dataset.lang}`));
    const add = $('#add');
    if (add) add.addEventListener('click', showAddForm);
    wireCornerButton();
}

/**
 * In der installierten App steht dort kein Abmelden, sondern Aktualisieren.
 *
 * Das Symbol gehört zu genau einem Kind - sich dort abzumelden hilft niemandem
 * und nimmt nur den Zugang. Was in der App dagegen fehlt, ist ein Weg, eine
 * neue Fassung zu holen: keine Adresszeile, kein Neu-Laden.
 */
function cornerButton() {
    // Das eigene Konto steht immer daneben - Name, Farbe und vor allem das
    // Passwort waren bisher nur ueber den Betreiber zu aendern.
    const konto = '<button class="iconbtn" id="konto" aria-label="Mein Konto">&#9881;</button>';

    return konto + (VT.standalone
        ? '<button class="iconbtn" id="refresh" aria-label="App aktualisieren">&#8635;</button>'
        : '<button class="iconbtn" id="logout" aria-label="Abmelden">&#9099;</button>');
}

function wireCornerButton() {
    const konto = $('#konto');
    if (konto) konto.addEventListener('click', () => go('/konto'));

    const refresh = $('#refresh');
    if (refresh) {
        refresh.addEventListener('click', async () => {
            refresh.disabled = true;
            refresh.innerHTML = '<span class="spinner inline"></span>';
            await hardRefresh();
        });
        return;
    }

    const btn = $('#logout');
    if (!btn) return;
    btn.addEventListener('click', async () => {
        if (!confirm('Abmelden? Dein Homescreen-Symbol bleibt bestehen.')) return;
        try {
            await api('auth', 'logout', { body: {} });
            /*
             * Und der Vorrat geht mit. Er gehoert diesem Kind: Auf einem
             * geteilten Tablet haette das naechste sonst die Vokabeln des
             * vorigen im Geraet liegen.
             */
            vorratVergessen();
        } catch { /* auch bei Fehler zum Login */ }
        window.location.href = `${VT.base}/`;
    });
}

/**
 * Wer darf hier eine Sprache anlegen?
 *
 * Eine Lehrkraft nicht - für sie heisst das Ding Kurs, gehört zu einer
 * Klasse und entsteht in der Verwaltung. Eine hier angelegte Sprache
 * bekäme einen Kurs ohne Klasse und mit dem falschen Namen; zwei Wege zum
 * selben Ergebnis, von denen einer schlechter ist, sind ein Weg zu viel.
 *
 * Für ein Kind mit Einlese-Recht bleibt es: In einer Familie ist genau das
 * der Weg, und es gibt dort niemanden, der Kurse verwaltet.
 */
function darfAnlegen() {
    return VT.user.canImport && !VT.user.isTeacher;
}

/**
 * Für Lehrkräfte der Weg in die Verwaltung.
 *
 * Sie soll nicht wissen müssen, dass es unter /teacher/ etwas gibt - sie
 * meldet sich normal an und findet den Weg dort, wo sie ohnehin ist. Eine
 * echte Seitennavigation statt eines Wechsels innerhalb der App: Der
 * Lehrkraft-Bereich wird vom Server gebaut und ist kein Teil der PWA.
 *
 * Nur in der Browser-Ansicht. Die installierte App gehört einem Gerät und
 * einem Zweck - dort zu verwalten, ginge auf einem Handy-Bildschirm ohnehin
 * schlecht.
 */
function teacherLink() {
    if (!VT.user.isTeacher || VT.standalone) return '';
    return `
        <a class="row" href="${VT.base}/teacher/">
            <span class="lead">\u{1F5C2}\u{FE0F}</span>
            <span class="body">
                <span class="title">Verwaltung</span>
                <span class="tiny muted">Klassen, Kurse, Zugangsdaten und Freigaben</span>
            </span>
            <span class="chev">&#8250;</span>
        </a>`;
}

/**
 * iOS-Nutzern erklären, wie das eigene App-Symbol entsteht. Nur in Safari
 * sinnvoll - in der installierten App wäre der Hinweis sinnlos.
 */
function installHint() {
    const isIOS = /iPhone|iPad|iPod/.test(navigator.userAgent);
    if (VT.standalone || !isIOS) return '';
    return `
        <div class="install">
            <span class="big">\u{1F4F2}</span>
            <div>
                <strong>${esc(VT.user.appName)} auf den Home-Bildschirm</strong><br>
                Unten auf <strong>Teilen</strong> tippen, dann
                <strong>Zum Home-Bildschirm</strong>. Danach bist du dort
                immer direkt angemeldet.
            </div>
        </div>`;
}

function showAddForm() {
    const presets = PRESETS.map((p, i) => `
        <button class="tile" data-preset="${i}">
            ${flagHtml(p.flag)}
            <span class="name">${esc(p.name)}</span>
        </button>
    `).join('');

    render(`
        ${topbar('Sprache hinzufügen', { backTo: '/' })}
        <div id="msg"></div>
        <p class="sub">Welche Sprache lernst du?</p>
        <div class="grid">${presets}</div>

        <h2>Andere Sprache</h2>
        <div class="card">
            <label for="name">Name der Sprache</label>
            <input type="text" id="name" placeholder="z. B. Schwedisch" maxlength="64">
            <label for="flag">Symbol</label>
            <input type="text" id="flag" placeholder="z. B. \u{1F1F8}\u{1F1EA}" maxlength="8">
            <button class="btn" id="save">Anlegen</button>
        </div>
    `);

    $$('[data-back]').forEach((el) => el.addEventListener('click', () => go('/')));
    on('[data-preset]', 'click', (e) => {
        const p = PRESETS[Number(e.currentTarget.dataset.preset)];
        create(p.name, p.flag, e.currentTarget, p.code);
    });
    $('#save').addEventListener('click', (e) => {
        create($('#name').value.trim(), $('#flag').value.trim() || '\u{1F310}', e.currentTarget);
    });
}

async function create(name, flag, button, code = '') {
    clearError();
    if (!name) {
        showError('Bitte einen Namen für die Sprache angeben.');
        return;
    }
    try {
        await withBusy(button, 'Wird angelegt...', async () => {
            const data = await api('languages', 'create', { body: { name, flag, code } });
            go(`/lang/${data.id}`);
        });
    } catch (err) {
        showError(err.message);
    }
}
