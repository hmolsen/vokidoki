import {
    VT, api, render, esc, $, $$, on, go, topbar, loading, showError, clearError, withBusy,
} from '../core.js';

/* Die Sprachen, die hier gebraucht werden. Alles andere lässt sich
   im Formular darunter frei eintragen. */
const PRESETS = [
    { flag: '\u{1F1EC}\u{1F1E7}', name: 'Englisch' },
    { flag: '\u{1F1EB}\u{1F1F7}', name: 'Französisch' },
    { flag: '\u{1F3DB}\u{FE0F}',  name: 'Latein' },
    { flag: '\u{1F1E9}\u{1F1F0}', name: 'Dänisch' },
];

export async function languagesView() {
    render(topbar(VT.user.appName, { action: logoutButton() }) + loading('Sprachen werden geladen...'));

    const { languages } = await api('languages', 'list');

    const tiles = languages.map((lang) => `
        <button class="tile" data-lang="${lang.id}">
            <span class="flag">${esc(lang.flag_emoji || '\u{1F310}')}</span>
            <span class="name">${esc(lang.name)}</span>
            <span class="meta">${lang.vocab_count} Vokabeln</span>
        </button>
    `).join('');

    render(`
        ${topbar(VT.user.appName, { action: logoutButton() })}
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
    wireLogout();
}

function logoutButton() {
    return '<button class="iconbtn" id="logout" aria-label="Abmelden">&#9099;</button>';
}

function wireLogout() {
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
        create(p.name, p.flag, e.currentTarget);
    });
    $('#save').addEventListener('click', (e) => {
        create($('#name').value.trim(), $('#flag').value.trim() || '\u{1F310}', e.currentTarget);
    });
}

async function create(name, flag, button) {
    clearError();
    if (!name) {
        showError('Bitte einen Namen für die Sprache angeben.');
        return;
    }
    try {
        await withBusy(button, 'Wird angelegt...', async () => {
            const data = await api('languages', 'create', { body: { name, flag } });
            go(`/lang/${data.id}`);
        });
    } catch (err) {
        showError(err.message);
    }
}
