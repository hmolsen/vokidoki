import {
    render, esc, $, $$, topbar, wireBack, progressBar, showError,
    babing, serieAktualisieren, punkteAktualisieren, konfetti, feuerwerk,
} from '../core.js';
import {
    frageWahl, antwortMerken, MODUS_WAHL, sprache, einheit,
} from '../vorrat.js';
import { weiterKnopf, weiterVerdrahten } from './unit.js';
import { meldeKnopf, meldenVerdrahten } from '../melden.js';
import { tonKnopf, optionHtml, tonVerdrahten } from './wortton.js';

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
                <div class="wortzeile"><div class="word">${esc(data.frage)}</div>${tonKnopf(data.frageTon)}</div>
            </div>
            ${meldeKnopf(true)}
        </div>

        <div class="options" id="options">
            ${data.optionen.map((opt, i) => optionHtml(opt, i, data.optionToene?.[i])).join('')}
        </div>

        <div class="verdict" id="verdict"></div>
    `);

    wireBack();
    tonVerdrahten();

    // Beim Auswählen ist das Wortpaar gemeint - einen Satz gibt es hier nicht.
    meldenVerdrahten($('[data-melden]'), () => ({ vocabId: data.vocabId, modus: MODUS_WAHL }));

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
                babing();

                /*
                 * Der Punkt springt sofort an, nicht erst mit der naechsten
                 * Frage. Wer zweimal richtig lag, sah sonst zwei Punkte -
                 * und beim dritten Mal, auf das es ankommt, immer noch zwei.
                 */
                punkteAktualisieren(document, result.streak);

                // Und wenn sie damit sitzt: Konfetti. Es haengt an <body>
                // und fliegt ueber der naechsten Frage weiter - die Zeit bis
                // dahin aendert sich dadurch nicht.
                if (result.newly_learned) konfetti();
                // Die Leiste wird beim Üben nicht neu gezeichnet - das
                // Abzeichen muss sich also selbst melden. Gefeiert wird
                // genau einmal: in dem Augenblick, in dem der Tag steht.
                serieAktualisieren(result.tag_geschafft);
            } else {
                button.classList.add('wrong');
                $$('.option')[result.correct_index]?.classList.add('correct');
                verdict.className = 'verdict bad';
                verdict.textContent = 'Nicht ganz - so ist es richtig.';
                // Die Serie ist hin - das sollen die Punkte auch zeigen.
                punkteAktualisieren(document, 0);
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
            <h1>Auswählen geschafft!</h1>
            <p class="sub">Du kannst jetzt alle ${data.total} Vokabeln.</p>
        </div>
        ${weiterKnopf(unitId, MODUS_WAHL)}
        <button class="btn ghost" data-back="/unit/${unitId}">Zur Übersicht</button>
    `);

    /*
     * Erst zeichnen, dann anzuenden: render() macht ein laufendes Feuerwerk
     * aus - so hoert es bei jedem Wechsel der Ansicht von selbst auf. Stuende
     * der Aufruf davor, loeschte die eigene Seite ihn sofort wieder.
     */
    feuerwerk();

    wireBack();

    // Hier stand "Noch einmal üben", und es setzte den Lernstand zurück -
    // siehe weiterKnopf() in unit.js.
    weiterVerdrahten();
}
