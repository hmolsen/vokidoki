import {
    api, render, esc, $, go, topbar, loading, wireBack, progressBar,
    showError, clearError,
} from '../core.js';

/** Detailansicht einer Lerneinheit: Fortschritt, Wortliste, Aktionen. */
export async function unitView(unitId) {
    render(loading());

    const { unit, vocab } = await api('units', 'get', { query: { id: unitId } });

    const known   = vocab.filter((v) => v.known).length;
    const correct = vocab.reduce((sum, v) => sum + v.correct, 0);
    const wrong   = vocab.reduce((sum, v) => sum + v.wrong, 0);
    const asked   = correct + wrong;
    const quota   = asked > 0 ? Math.round((correct / asked) * 100) : null;
    const done    = vocab.length > 0 && known >= vocab.length;

    const list = vocab.map((v) => `
        <div class="row" style="cursor:default">
            <span class="lead">${v.known ? '\u{2705}' : dots(v.streak)}</span>
            <span class="body">
                <span class="title">${esc(v.term_foreign)}</span>
                <span class="tiny muted">${esc(v.term_native)}${
                    v.note ? ` &middot; ${esc(v.note)}` : ''
                }</span>
            </span>
        </div>
    `).join('');

    render(`
        ${topbar(unit.title, { backTo: `/lang/${unit.language_id}/units` })}
        <div id="msg"></div>

        <div class="card">
            ${done ? '<div class="notice good">Diese Lerneinheit hast du geschafft!</div>' : ''}
            <div class="tiny muted">Gelernt</div>
            <strong>${known} von ${vocab.length} Vokabeln</strong>
            ${progressBar(known, vocab.length)}
            ${quota === null ? '' : `
                <p class="tiny muted" style="margin:10px 0 0">
                    ${correct} richtig, ${wrong} falsch &middot; ${quota}% Trefferquote
                </p>`}
        </div>

        <button class="btn" id="practice">
            ${done ? 'Noch einmal üben' : 'Üben'}
        </button>

        <h2>Alle Vokabeln (${vocab.length})</h2>
        ${list}

        <h2>Verwalten</h2>
        <div class="btn-row" style="margin-bottom:10px">
            <button class="btn secondary small" id="rename">Umbenennen</button>
            <button class="btn secondary small" id="reset">Fortschritt zurücksetzen</button>
        </div>
        <button class="btn ghost" id="delete">Lerneinheit löschen</button>
    `);

    wireBack();

    $('#practice').addEventListener('click', async () => {
        // "Noch einmal üben" braucht einen frischen Lernstand, sonst wären
        // sofort wieder alle Vokabeln als gekonnt markiert.
        if (done) {
            try {
                await api('units', 'reset', { body: { id: unit.id } });
            } catch (err) {
                showError(err.message);
                return;
            }
        }
        go(`/quiz/${unit.id}`);
    });

    $('#rename').addEventListener('click', async () => {
        const title = prompt('Neuer Titel der Lerneinheit:', unit.title);
        if (title === null) return;
        clearError();
        try {
            await api('units', 'rename', { body: { id: unit.id, title: title.trim() } });
            go(`/unit/${unit.id}`);
        } catch (err) {
            showError(err.message);
        }
    });

    $('#reset').addEventListener('click', async () => {
        if (!confirm('Allen Lernfortschritt dieser Einheit zurücksetzen?')) return;
        try {
            await api('units', 'reset', { body: { id: unit.id } });
            go(`/unit/${unit.id}`);
        } catch (err) {
            showError(err.message);
        }
    });

    $('#delete').addEventListener('click', async () => {
        if (!confirm(`"${unit.title}" mit allen Vokabeln endgültig löschen?`)) return;
        try {
            await api('units', 'delete', { body: { id: unit.id } });
            go(`/lang/${unit.language_id}/units`);
        } catch (err) {
            showError(err.message);
        }
    });
}

/** Drei Punkte zeigen, wie oft die Vokabel schon hintereinander saß. */
function dots(streak) {
    const filled = Math.min(3, Math.max(0, streak));
    return `<span class="dots">${
        [0, 1, 2].map((i) => `<i class="${i < filled ? 'on' : ''}"></i>`).join('')
    }</span>`;
}
