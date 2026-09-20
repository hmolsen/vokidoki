import {
    render, esc, $, $$, go, topbar, wireBack, progressBar, showError,
} from '../core.js';
import {
    frageWahl, antwortMerken, zuruecksetzen, MODUS_WAHL, sprache, einheit,
} from '../vorrat.js';

const NEXT_DELAY_CORRECT = 700;    // richtig: zügig weiter
const NEXT_DELAY_WRONG   = 1900;   // falsch: Zeit, die richtige Lösung zu lesen

export async function quizView(unitId) {
    nextQuestion(unitId);
}

/*
 * Die Frage entsteht im Gerät, nicht auf dem Server.
 *
 * Vorher waren es drei bis vier Runden übers Netz je Wort - Frage holen,
 * Antwort schicken, nächste Frage -, und auf jede davon wartete ein Kind.
 * Ein Aussetzer mittendrin wurde zu „Bist du online?". Jetzt liegt alles
 * im Vorrat, und das Üben braucht gar kein Netz mehr; die Antworten gehen
 * später am Stück zurück.
 */
function nextQuestion(unitId) {
    const data = frageWahl(unitId);

    if (data === null) {
        render(`
            ${topbar('Üben', { backTo: `/unit/${unitId}` })}
            <div id="msg"></div>
        `);
        wireBack();
        showError('Die Vokabeln sind noch nicht da. Einmal mit Netz öffnen, '
                  + 'dann geht es auch ohne.');
        return;
    }

    if (data.leer) {
        render(`
            ${topbar('Üben', { backTo: `/unit/${unitId}` })}
            <div id="msg"></div>
        `);
        wireBack();
        showError('Diese Lektion ist noch nicht freigegeben. '
                  + 'Deine Lehrkraft macht sie auf, wenn sie dran ist.');
        return;
    }

    if (data.done) {
        showFinished(unitId, data);
        return;
    }

    const sprachName = sprache(einheit(unitId)?.l)?.name ?? '';
    const dirLabel = data.nachVorn
        ? `${esc(sprachName)} → Deutsch`
        : `Deutsch → ${esc(sprachName)}`;

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
                <div class="word">${esc(data.frage)}</div>
            </div>
        </div>

        <div class="options" id="options">
            ${data.optionen.map((opt, i) => `
                <button class="option" data-index="${i}">${esc(opt)}</button>
            `).join('')}
        </div>

        <div class="verdict" id="verdict"></div>
    `);

    wireBack();

    const box = $('#options');
    let answered = false;

    $$('.option').forEach((button) => {
        button.addEventListener('click', () => {
            if (answered) return;
            answered = true;
            box.classList.add('locked');

            const index   = Number(button.dataset.index);
            const richtig = index === data.richtig;
            const result  = { ...antwortMerken(data.vocabId, MODUS_WAHL, richtig),
                              correct: richtig, correct_index: data.richtig };

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

    $('#again').addEventListener('click', () => {
        // Auch das geht ohne Netz: Der Vorrat vergisst den Stand sofort, und
        // der Server erfaehrt es im selben Strom wie die Antworten - in der
        // richtigen Reihenfolge, also vor dem, was danach geuebt wird.
        zuruecksetzen(unitId, MODUS_WAHL);
        go(`/quiz/${unitId}`);
    });
}
