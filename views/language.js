import { api, render, on, go, topbar, loading, wireBack, progressBar } from '../core.js';

/** Startseite einer Sprache: Auswahl der Funktionen. */
export async function languageView(languageId) {
    render(loading());

    const { language, units } = await api('units', 'list', { query: { language_id: languageId } });

    const total = units.reduce((sum, u) => sum + u.total, 0);
    const known = units.reduce((sum, u) => sum + u.known, 0);
    const openUnits = units.filter((u) => !u.done).length;

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

        <button class="row" data-go="/lang/${language.id}/units">
            <span class="lead">\u{1F3AF}</span>
            <span class="body">
                <span class="title">Üben</span>
                <span class="tiny muted">${
                    units.length === 0
                        ? 'Noch keine Lerneinheit vorhanden'
                        : `${openUnits} von ${units.length} Lerneinheiten offen`
                }</span>
            </span>
            <span class="chev">&#8250;</span>
        </button>

        ${units.length === 0 ? `
            <div class="empty">
                <span class="big">\u{1F4D6}</span>
                Fotografiere deine erste Vokabelseite,<br>dann kann es losgehen.
            </div>` : ''}
    `);

    wireBack();
    on('[data-go]', 'click', (e) => go(e.currentTarget.dataset.go));
}
