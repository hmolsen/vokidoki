/*
 * Voki liest - die Decke über der Seite, solange die KI Fotos liest.
 *
 * Das Lesen dauert zehn bis zwanzig Sekunden. Vorher stand dort nur ein
 * Kreisel im Knopf, und die Seite darum blieb bedienbar: Wer in der Zeit
 * ein Foto aus dem Stapel nahm oder die Seite wechselte, bekam eine halb
 * gespeicherte Lerneinheit. Die Decke nimmt die Seite ganz aus dem Spiel -
 * die Maus trifft nur noch sie, und alles darunter ist inert, damit auch
 * Tab und Enter nichts mehr auslösen.
 *
 * Eigenes Modul ohne Abhängigkeiten, weil es an zwei Stellen gebraucht
 * wird: in der App (views/import.js) und im Lehrkraft-Bereich, dessen
 * teacher.js kein Modul ist und es mit import() nachlädt.
 */

const BILD = new URL('./assets/voki-liest.svg', import.meta.url).href;

/**
 * Deckt die Seite ab und zeigt Voki beim Lesen.
 * Gibt { text(neu), weg() } zurück: den Satz darunter ändern, die Decke abnehmen.
 */
export function vokiLiest(satz, klein = 'Das dauert meist zehn bis zwanzig Sekunden.') {
    const decke = document.createElement('div');
    decke.className = 'lesevoki';
    decke.setAttribute('role', 'status');
    decke.setAttribute('aria-live', 'polite');
    decke.innerHTML = `
        <div class="lesevoki-karte">
            <img src="${BILD}" alt="" width="180" height="180">
            <strong class="lesevoki-satz"></strong>
            <p class="lesevoki-klein tiny muted"></p>
        </div>`;
    decke.querySelector('.lesevoki-satz').textContent = satz;
    decke.querySelector('.lesevoki-klein').textContent = klein;

    const still = [...document.body.children].filter((el) => !el.inert);
    still.forEach((el) => { el.inert = true; });
    document.activeElement?.blur?.();
    document.body.append(decke);

    return {
        text(neu, kleinNeu) {
            decke.querySelector('.lesevoki-satz').textContent = neu;
            if (kleinNeu !== undefined) decke.querySelector('.lesevoki-klein').textContent = kleinNeu;
        },
        weg() {
            decke.remove();
            still.forEach((el) => { el.inert = false; });
        },
    };
}
