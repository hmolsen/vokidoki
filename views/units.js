import { api, render, esc, on, go, topbar, loading, wireBack, progressBar } from '../core.js';

/** Liste der Lerneinheiten einer Sprache. */
export async function unitsView(languageId) {
    render(loading());

    const { language, units } = await api('units', 'list', { query: { language_id: languageId } });

    const rows = units.map((u) => `
        <button class="row" data-unit="${u.id}">
            <span class="lead">${u.done ? '\u{2705}' : '\u{1F4DA}'}</span>
            <span class="body">
                <span class="title">${esc(u.title)}</span>
                <span class="tiny muted">${u.known} von ${u.total} gelernt</span>
                ${progressBar(u.known, u.total)}
            </span>
            <span class="chev">&#8250;</span>
        </button>
    `).join('');

    render(`
        ${topbar('Lerneinheiten', { backTo: `/lang/${language.id}` })}
        <div id="msg"></div>
        ${units.length === 0 ? `
            <div class="empty">
                <span class="big">\u{1F4DA}</span>
                Noch keine Lerneinheit.<br>
                Lies zuerst eine Vokabelseite ein.
            </div>
            <button class="btn" data-go="/lang/${language.id}/import">Vokabeln einlesen</button>
        ` : rows}
    `);

    wireBack();
    on('[data-unit]', 'click', (e) => go(`/unit/${e.currentTarget.dataset.unit}`));
    on('[data-go]', 'click', (e) => go(e.currentTarget.dataset.go));
}
