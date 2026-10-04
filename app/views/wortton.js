/*
 * Die Aussprache beim Auswählen: ein Lautsprecher neben dem Wort in der
 * Fremdsprache.
 *
 * Steht die Frage in der Fremdsprache, sitzt er neben ihr; stehen die
 * Möglichkeiten in der Fremdsprache, neben jeder davon - der Knopf der
 * Möglichkeit rückt dafür ein Stück zusammen. Die Aufnahmen entstehen beim
 * Freigeben, mit denen der Sätze (lib/tts.php); welche es gibt, sagt
 * wahlAufgabe() in vorrat.js. Gebraucht vom Auswählen (quiz.js) und vom
 * Freien Üben (frei.js) - beide zeigen dieselbe Frage.
 */

import { esc } from '../core.js';

/** Der Lautsprecher als HTML - oder nichts, wenn es keine Aufnahme gibt. */
export function tonKnopf(adresse) {
    if (!adresse) return '';
    return `<button type="button" class="tonknopf" data-ton="${esc(adresse)}"
                    aria-label="Anhören" title="Anhören">\u{1F50A}</button>`;
}

/** Eine Möglichkeit, mit Lautsprecher daneben, wenn es einen gibt. */
export function optionHtml(text, index, adresse) {
    const knopf = `<button class="option" data-index="${index}">${esc(text)}</button>`;
    return adresse ? `<div class="optzeile">${knopf}${tonKnopf(adresse)}</div>` : knopf;
}

let laeuft = null;

/**
 * Die Lautsprecher in `wurzel` anschliessen. Ein Druck spielt die Aufnahme
 * und wählt nichts aus - er sitzt neben der Möglichkeit, nicht in ihr.
 */
export function tonVerdrahten(wurzel = document) {
    wurzel.querySelectorAll('.tonknopf[data-ton]').forEach((knopf) => {
        knopf.addEventListener('click', (e) => {
            e.stopPropagation();
            laeuft?.pause();
            laeuft = new Audio(knopf.dataset.ton);
            knopf.classList.add('spielt');
            laeuft.addEventListener('ended', () => knopf.classList.remove('spielt'), { once: true });
            laeuft.play().catch(() => knopf.classList.remove('spielt'));
        });
    });
}
