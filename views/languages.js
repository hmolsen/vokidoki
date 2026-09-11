import {
    VT, api, render, esc, $, $$, on, go, topbar, loading, showError, clearError, withBusy,
    hardRefresh,
} from '../core.js';

/* Die Sprachen, die hier gebraucht werden. Alles andere lässt sich
   im Formular darunter frei eintragen. */
const PRESETS = [
    { flag: '\u{1F1EC}\u{1F1E7}', name: 'Englisch',    code: 'en' },
    { flag: '\u{1F1EB}\u{1F1F7}', name: 'Französisch', code: 'fr' },
    { flag: '\u{1F3DB}\u{FE0F}',  name: 'Latein',      code: 'la' },
    { flag: '\u{1F1E9}\u{1F1F0}', name: 'Dänisch',     code: 'da' },
];

export async function languagesView() {
    render(topbar(VT.user.appName, { action: cornerButton() }) + loading('Sprachen werden geladen...'));

    const { languages } = await api('languages', 'list');

    const tiles = languages.map((lang) => `
        <button class="tile" data-lang="${lang.id}">
            <span class="flag">${esc(lang.flag_emoji || '\u{1F310}')}</span>
            <span class="name">${esc(lang.name)}</span>
            <span class="meta">${lang.vocab_count} Vokabeln</span>
        </button>
    `).join('');

    render(`
        ${topbar(VT.user.appName, { action: cornerButton() })}
        <div id="msg"></div>
        ${languages.length === 0 ? `
            <div class="empty">
                <span class="big">\u{1F310}</span>
                Noch keine Sprache angelegt.<br>Leg unten deine erste an.
            </div>` : ''}
        <div class="grid">
            ${tiles}
            <button class="tile add" id="add">
                <span class="flag">+</span>
                <span class="name">Sprache</span>
            </button>
        </div>
        ${installHint()}
    `);

    on('[data-lang]', 'click', (e) => go(`/lang/${e.currentTarget.dataset.lang}`));
    $('#add').addEventListener('click', showAddForm);
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
    return VT.standalone
        ? '<button class="iconbtn" id="refresh" aria-label="App aktualisieren">&#8635;</button>'
        : '<button class="iconbtn" id="logout" aria-label="Abmelden">&#9099;</button>';
}

function wireCornerButton() {
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
        } catch { /* auch bei Fehler zum Login */ }
        window.location.href = `${VT.base}/`;
    });
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
            <span class="flag">${p.flag}</span>
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
