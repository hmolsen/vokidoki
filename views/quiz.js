import {
    api, render, esc, $, $$, go, topbar, loading, wireBack, progressBar, showError,
} from '../core.js';

const NEXT_DELAY_CORRECT = 700;    // richtig: zügig weiter
const NEXT_DELAY_WRONG   = 1900;   // falsch: Zeit, die richtige Lösung zu lesen

export async function quizView(unitId) {
    render(loading('Frage wird vorbereitet...'));
    await nextQuestion(unitId);
}

async function nextQuestion(unitId) {
    let data;
    try {
        data = await api('quiz', 'next', { query: { unit_id: unitId } });
    } catch (err) {
        render(`
            ${topbar('Üben', { backTo: `/unit/${unitId}` })}
            <div id="msg"></div>
        `);
        wireBack();
        showError(err.message);
        return;
    }

    if (data.done) {
        showFinished(unitId, data);
        return;
    }

    const dirLabel = data.direction === 'foreign_to_native'
        ? `${esc(data.language)} → Deutsch`
        : `Deutsch → ${esc(data.language)}`;

    render(`
        ${topbar('Üben', { backTo: `/unit/${unitId}` })}

        <div class="quiz-head">
            <span class="tiny muted">${data.known} von ${data.total} gelernt</span>
            <span class="dots">${
                [0, 1, 2].map((i) => `<i class="${i < Math.min(3, data.streak) ? 'on' : ''}"></i>`).join('')
            }</span>
        </div>
        ${progressBar(data.known, data.total)}

        <div class="prompt">
            <div>
                <div class="dir">${dirLabel}</div>
                <div class="word">${esc(data.question)}</div>
            </div>
        </div>

        <div class="options" id="options">
            ${data.options.map((opt, i) => `
                <button class="option" data-index="${i}">${esc(opt)}</button>
            `).join('')}
        </div>

        <div class="verdict" id="verdict"></div>
    `);

    wireBack();

    const box = $('#options');
    let answered = false;

    $$('.option').forEach((button) => {
        button.addEventListener('click', async () => {
            if (answered) return;
            answered = true;
            box.classList.add('locked');

            const index = Number(button.dataset.index);
            let result;
            try {
                result = await api('quiz', 'answer', {
                    body: { nonce: data.nonce, index },
                });
            } catch (err) {
                answered = false;
                box.classList.remove('locked');
                showError(err.message);
                return;
            }

            const verdict = $('#verdict');
            if (result.correct) {
                button.classList.add('correct');
                verdict.className = 'verdict good';
                verdict.textContent = result.just_learned
                    ? 'Sitzt! Diese Vokabel kannst du jetzt.'
                    : 'Richtig!';
            } else {
                button.classList.add('wrong');
                $$('.option')[result.correct_index]?.classList.add('correct');
                verdict.className = 'verdict bad';
                verdict.textContent = 'Nicht ganz - so ist es richtig.';
            }

            setTimeout(
                () => nextQuestion(unitId),
                result.correct ? NEXT_DELAY_CORRECT : NEXT_DELAY_WRONG,
            );
        });
    });
}

function showFinished(unitId, data) {
    render(`
        ${topbar('Geschafft', { backTo: `/unit/${unitId}` })}
        <div class="celebrate">
            <span class="big">\u{1F389}</span>
            <h1>Lerneinheit bestanden!</h1>
            <p class="sub">Du kannst jetzt alle ${data.total} Vokabeln.</p>
        </div>
        <button class="btn" id="again">Noch einmal üben</button>
        <button class="btn ghost" data-back="/unit/${unitId}">Zur Übersicht</button>
    `);

    wireBack();

    $('#again').addEventListener('click', async () => {
        try {
            await api('units', 'reset', { body: { id: Number(unitId) } });
            go(`/quiz/${unitId}`);
        } catch (err) {
            showError(err.message);
        }
    });
}
