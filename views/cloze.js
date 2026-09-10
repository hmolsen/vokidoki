import {
    api, render, esc, $, go, topbar, wireBack, progressBar, showError,
} from '../core.js';

const NEXT_DELAY_CORRECT = 900;
const NEXT_DELAY_HINT    = 2400;   // Schreibweise lesen können
const NEXT_DELAY_WRONG   = 2600;

export async function clozeView(unitId) {
    render(`
        ${topbar('Lückentext', { backTo: `/unit/${unitId}` })}
        <div id="msg"></div>
        <div class="empty" style="padding-top:16vh">
            <div class="spinner"></div>
            <strong>Einen Moment...</strong>
        </div>
    `);
    wireBack();
    await nextQuestion(unitId);
}

async function nextQuestion(unitId) {
    let data;
    try {
        data = await api('cloze', 'next', { query: { unit_id: unitId } });
    } catch (err) {
        showFailure(unitId, err.message);
        return;
    }

    // Beim ersten Mal gibt es für diese Lerneinheit noch keine Sätze.
    if (data.needs_preparation) {
        await prepare(unitId);
        return;
    }

    if (data.done) {
        showFinished(unitId, data);
        return;
    }

    render(`
        ${topbar('Lückentext', { backTo: `/unit/${unitId}` })}

        <div class="quiz-head">
            <span class="tiny muted">${data.known} von ${data.total} gelernt</span>
            <span class="dots">${
                [0, 1, 2].map((i) => `<i class="${i < Math.min(3, data.streak) ? 'on' : ''}"></i>`).join('')
            }</span>
        </div>
        ${progressBar(data.known, data.total)}

        <div class="cloze">
            <p class="cloze-native">${esc(data.native)}</p>
            <p class="cloze-foreign">${gapSentence(data.foreign)}</p>
        </div>

        <form id="form" autocomplete="off">
            <input type="text" id="answer" class="cloze-input"
                   ${data.lang ? `lang="${esc(data.lang)}"` : ''}
                   placeholder="Was fehlt?"
                   maxlength="128"
                   autocomplete="off" autocorrect="off"
                   autocapitalize="off" spellcheck="false"
                   enterkeyhint="done">
            <button class="btn" type="submit" id="check">Prüfen</button>
        </form>

        <div class="verdict" id="verdict"></div>
        <div id="msg"></div>
    `);

    wireBack();

    const form  = $('#form');
    const input = $('#answer');
    let answered = false;

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (answered) return;

        const text = input.value.trim();
        if (text === '') {
            input.focus();
            return;
        }

        answered = true;
        input.readOnly = true;
        $('#check').disabled = true;

        let result;
        try {
            result = await api('cloze', 'answer', {
                body: { nonce: data.nonce, text },
            });
        } catch (err) {
            answered = false;
            input.readOnly = false;
            $('#check').disabled = false;
            showError(err.message);
            return;
        }

        const verdict = $('#verdict');
        let delay;

        if (result.correct && result.exact) {
            input.classList.add('correct');
            verdict.className = 'verdict good';
            verdict.textContent = result.just_learned
                ? 'Sitzt! Diese Vokabel kannst du jetzt.'
                : 'Richtig!';
            delay = NEXT_DELAY_CORRECT;
        } else if (result.correct) {
            // Zählt als richtig, aber die Schreibweise soll das Kind sehen.
            input.classList.add('almost');
            verdict.className = 'verdict good';
            verdict.innerHTML = `Fast! So schreibt man es:<br><strong>${esc(result.answer)}</strong>`;
            delay = NEXT_DELAY_HINT;
        } else {
            input.classList.add('wrong');
            verdict.className = 'verdict bad';
            verdict.innerHTML = `Nicht ganz. Richtig ist:<br><strong>${esc(result.answer)}</strong>`;
            delay = NEXT_DELAY_WRONG;
        }

        setTimeout(() => nextQuestion(unitId), delay);
    });

    input.focus();
}

/** Ersetzt den Platzhalter {} durch eine sichtbare Lücke. */
function gapSentence(text) {
    return esc(text).replace('{}', '<span class="gap"></span>');
}

async function prepare(unitId) {
    render(`
        <div class="empty" style="padding-top:18vh">
            <div class="spinner"></div>
            <strong>Deine Sätze werden vorbereitet...</strong>
            <p class="tiny muted">
                Das dauert einmalig etwa zwanzig Sekunden.<br>
                Danach geht es immer sofort los.
            </p>
        </div>
    `);

    try {
        await api('cloze', 'prepare', { body: { unit_id: Number(unitId) } });
    } catch (err) {
        showFailure(unitId, err.message);
        return;
    }

    await nextQuestion(unitId);
}

function showFailure(unitId, message) {
    render(`
        ${topbar('Lückentext', { backTo: `/unit/${unitId}` })}
        <div id="msg"></div>
        <button class="btn secondary" id="retry">Noch einmal versuchen</button>
    `);
    wireBack();
    showError(message);
    $('#retry').addEventListener('click', () => nextQuestion(unitId));
}

function showFinished(unitId, data) {
    render(`
        ${topbar('Geschafft', { backTo: `/unit/${unitId}` })}
        <div class="celebrate">
            <span class="big">\u{1F389}</span>
            <h1>Lückentext bestanden!</h1>
            <p class="sub">Du hast alle ${data.total} Vokabeln richtig eingesetzt.</p>
        </div>
        <div id="msg"></div>
        <button class="btn" id="again">Noch einmal üben</button>
        <button class="btn ghost" data-back="/unit/${unitId}">Zur Übersicht</button>
    `);

    wireBack();

    $('#again').addEventListener('click', async () => {
        try {
            // Nur diese Übungsart zurücksetzen - Multiple Choice bleibt stehen.
            await api('units', 'reset', { body: { id: Number(unitId), mode: 'cloze' } });
            go(`/cloze/${unitId}`);
        } catch (err) {
            showError(err.message);
        }
    });
}
