import { api, render, esc, on, go, topbar, loading, wireBack, progressBar } from '../core.js';

/**
 * Startseite einer Sprache: einlesen und üben auf einer Ebene.
 *
 * Früher stand hier ein Punkt "Üben", hinter dem sich die Liste der
 * Lerneinheiten verbarg - als einziger Eintrag einer eigenen Seite. Ein
 * Menüpunkt, der nur zu einer Liste führt, ist ein Klick ohne Entscheidung;
 * die Liste steht jetzt direkt hier.
 */
export async function languageView(languageId) {
    render(loading());

    const { language, units } = await api('units', 'list', { query: { language_id: languageId } });

    const total = units.reduce((sum, u) => sum + u.total, 0);
    const known = units.reduce((sum, u) => sum + u.known, 0);

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
        ${topbar(`${language.flag} ${language.name}`, { backTo: '/' })}
        <div id="msg"></div>

        ${units.length > 0 ? `
            <div class="card">
                <div class="tiny muted">Insgesamt gelernt</div>
                <strong>${known} von ${total} Vokabeln</strong>
                ${progressBar(known, total)}
            </div>` : ''}

        <button class="row" data-go="/lang/${language.id}/import">
            <span class="lead">\u{1F4F7}</span>
            <span class="body">
                <span class="title">Vokabeln einlesen</span>
                <span class="tiny muted">Buchseite fotografieren und automatisch erfassen</span>
            </span>
            <span class="chev">&#8250;</span>
        </button>

        ${units.length === 0 ? `
            <div class="empty">
                <span class="big">\u{1F4D6}</span>
                Fotografiere deine erste Vokabelseite,<br>dann kann es losgehen.
            </div>
        ` : `
            <h2 class="section">Lerneinheiten</h2>
            ${rows}
        `}
    `);

    wireBack();
    on('[data-unit]', 'click', (e) => go(`/unit/${e.currentTarget.dataset.unit}`));
    on('[data-go]', 'click', (e) => go(e.currentTarget.dataset.go));
}
