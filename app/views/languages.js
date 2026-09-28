import {
    VT, api, render, esc, $, on, go, topbar, showError, clearError, withBusy,
    flagHtml, lernansicht,
    rechtsZeile,
} from '../core.js';
import { sprachen, einheitenDerSprache, vokabelnDerEinheit } from '../vorrat.js';
import { installHinweis } from '../installieren.js';

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
        ${lernansicht()}
        ${topbar(VT.user.appName)}
        <div id="msg"></div>
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
        <div id="installHinweis" hidden></div>
        ${rechtsZeile()}
    `);

    installHinweis($('#installHinweis'), {
        name:   VT.user.appName,
        symbol: `${VT.base}/icon.php?u=${VT.user.id}&s=120`,
        wohin:  'Es öffnet deine Kurse.',
    });

    on('[data-lang]', 'click', (e) => go(`/lang/${e.currentTarget.dataset.lang}`));
    const add = $('#add');
    if (add) add.addEventListener('click', showAddForm);
}

/**
 * Wer darf hier eine Sprache anlegen?
 *
 * Eine Lehrkraft nicht - für sie heisst das Ding Kurs, gehört zu einer
 * Klasse und entsteht in der Verwaltung. Eine hier angelegte Sprache
 * bekäme einen Kurs ohne Klasse und mit dem falschen Namen; zwei Wege zum
 * selben Ergebnis, von denen einer schlechter ist, sind ein Weg zu viel.
 *
 * Für ein Kind mit Einlese-Recht bleibt es: Wem die Lehrkraft das Einlesen
 * erlaubt hat, der geht genau hier entlang - einen Lehrkraft-Bereich hat es
 * nicht.
 */
function darfAnlegen() {
    return VT.user.canImport && !VT.user.isTeacher;
}

/*
 * Hier stand fuer eine Lehrkraft eine Zeile "Verwaltung", ueber ihren
 * eigenen Kursen.
 *
 * Sie ist weg: Der Schalter im Zahnrad kann dasselbe und mehr - er fuehrt
 * auf die Entsprechung DIESER Seite statt immer auf die Startseite, und er
 * steht auf jeder Seite an derselben Stelle. Eine zweite Tuer daneben, die
 * nur von einer Seite aus aufgeht, kostete den Platz ueber genau dem,
 * weswegen man hergekommen ist: den eigenen Kursen.
 */


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
