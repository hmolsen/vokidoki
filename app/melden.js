import { vokabelMelden } from './vorrat.js';

/*
 * Der Meldeknopf - in jeder Übung derselbe.
 *
 * Er stand einmal nur im Lückentext, und auch dort erst nach einer falschen
 * Antwort. Aber ein schiefes Wortpaar fällt beim Auswählen genauso auf,
 * und wer die Lösung richtig hatte und den Satz trotzdem merkwürdig fand,
 * hatte keinen Weg, das zu sagen. Jetzt steht er bei jeder Aufgabe, vom
 * ersten Augenblick an.
 *
 * Die Rückfrage ist nicht Zierde: Der Knopf ist klein und liegt neben den
 * Antworten, und eine Meldung landet bei der Lehrkraft. Ein Daumen, der
 * danebenrutscht, soll dort nichts auslösen.
 *
 * Sie ist ein Teil der Seite, kein confirm(). Das Kästchen des Browsers
 * trägt die Adresse der Seite als Überschrift, sieht auf jedem Gerät anders
 * aus und hat Knöpfe mit "OK" und "Abbrechen" - für ein Kind eine Frage vom
 * Computer, nicht von der App.
 */

const FRAGE = 'Diese Vokabel deiner Lehrkraft melden?';
const FAHNE = '\u{2691}';

/** Der Knopf als HTML. `ecke` setzt ihn oben rechts in die Fragekarte. */
export function meldeKnopf(ecke = false) {
    return `<button type="button" class="btn flagbtn${ecke ? ' ecke' : ''}" data-melden
                    aria-label="Diese Vokabel melden"
                    title="Stimmt hier etwas nicht?">${FAHNE}</button>`;
}

/**
 * Die Rückfrage - ein <dialog>, der sich selbst wieder wegräumt.
 *
 * showModal() legt ihn über alles, fängt den Fokus und schliesst mit
 * Escape; ein Druck auf den abgedunkelten Rand daneben zählt als Nein.
 * Er hängt an <body> und nicht in der Ansicht: Beim Auswählen schaltet die
 * Übung nach einer Antwort von selbst weiter, und render() ersetzte ihn
 * sonst mitten in der Frage.
 *
 * @returns {Promise<boolean>} ob gemeldet werden soll
 */
function nachfragen() {
    return new Promise((fertig) => {
        const d = document.createElement('dialog');
        d.className = 'rueckfrage';
        d.innerHTML = `
            <form method="dialog">
                <p class="rueckfrage-zeichen" aria-hidden="true">${FAHNE}</p>
                <p class="rueckfrage-text">${FRAGE}</p>
                <div class="rueckfrage-knoepfe">
                    <button class="btn secondary" value="nein" data-nein>Abbrechen</button>
                    <button class="btn" value="ja" data-ja>Melden</button>
                </div>
            </form>`;

        d.addEventListener('click', (event) => {
            if (event.target === d) d.close('nein');
        });
        d.addEventListener('close', () => {
            fertig(d.returnValue === 'ja');
            d.remove();
        });

        document.body.append(d);
        d.showModal();
        d.querySelector('[data-ja]').focus();
    });
}

/**
 * Den Knopf auf eine Aufgabe setzen - und zurück auf null.
 *
 * Zurück auf null, weil der Lückentext seinen Bildschirm stehen lässt und
 * nur die Aufgabe tauscht: Ohne das stünde der Haken der letzten Meldung
 * noch bei der nächsten Vokabel. Und über onclick statt addEventListener,
 * damit dort nicht bei jeder Aufgabe ein Hörer mehr hängt.
 *
 * aufgabe() wird erst beim Druck gefragt, nicht beim Verdrahten - so geht
 * beim Lückentext mit, was bis dahin im Feld steht.
 */
export function meldenVerdrahten(knopf, aufgabe) {
    if (!knopf) return;

    knopf.disabled = false;
    knopf.classList.remove('done');
    knopf.textContent = FAHNE;
    knopf.title = 'Stimmt hier etwas nicht?';

    // Wie bei den Zeichentasten: Ohne das nimmt der Knopf dem Feld den
    // Fokus, und die Tastatur klappt zu.
    knopf.onmousedown = (event) => event.preventDefault();

    knopf.onclick = async () => {
        const a = aufgabe();
        if (!(await nachfragen())) return;

        vokabelMelden(a.vocabId, a.satzId ?? 0, a.getippt ?? '');

        knopf.disabled = true;
        knopf.classList.add('done');
        knopf.textContent = '\u{2713}';
        knopf.title = 'Gemeldet - danke!';
    };
}
