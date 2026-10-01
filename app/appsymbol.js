/*
 * Das App-Symbol als Vorschau - und die Farbwahl dazu.
 *
 * Für zwei Seiten, die dasselbe zeigen: das Konto in der App (views/
 * profile.js) und "Mein Konto" im Lehrkraft-Bereich (teacher/konto.php, über
 * teacher.js nachgeladen). Dort war es einmal der kleine Farbwähler des
 * Admins und ganz ohne Vorschau; jetzt ist es dieselbe Karte wie bei den
 * Kindern, nur mit dem Symbol der Verwaltung.
 *
 * Nachgebaut und nicht als Bild von icon.php geholt: Es soll sich beim
 * Tippen auf eine Farbe sofort ändern, vor dem Speichern - und icon.php
 * zeichnet nur die gespeicherte. Nachgebaut heisst aber: DIESELBEN Regeln
 * wie dort. Der Verlauf dunkelt nach unten auf 68 % ab; Voki und der Balken
 * der Verwaltung stehen in style.css (.appsymbol). Eine Prüfung in
 * tests/e2e.php hält die Stellen zusammen.
 */

export const SYMBOL_DUNKEL = 0.68;

/** Den Verlauf des Symbols setzen. */
export function symbolFaerben(el, farbe) {
    if (!el || !/^#[0-9a-f]{6}$/i.test(farbe)) return;
    const [r, g, b] = [1, 3, 5].map((i) => parseInt(farbe.slice(i, i + 2), 16));
    const dunkel = (c) => Math.round(c * SYMBOL_DUNKEL);

    el.style.setProperty('--oben', farbe);
    el.style.setProperty('--unten', `rgb(${dunkel(r)}, ${dunkel(g)}, ${dunkel(b)})`);
}

/**
 * Die Farbwahl lebendig machen: Ein Tipp auf eine Farbe wirkt sofort - in
 * der Seite, auf dem Symbol und auf dem Knopf. Das Feld bleibt dabei offen,
 * damit sich mehrere Farben nacheinander ausprobieren lassen.
 */
export function farbwahlVerdrahten(wurzel = document) {
    const symbol = wurzel.querySelector('#appsymbol');
    const punkt  = wurzel.querySelector('#farbpunkt');
    const jetzt  = wurzel.querySelector('input[name="color"]:checked')?.value;
    if (jetzt) symbolFaerben(symbol, jetzt);

    wurzel.querySelectorAll('input[name="color"]').forEach((feld) => {
        feld.addEventListener('change', () => {
            document.body.style.setProperty('--accent', feld.value);
            symbolFaerben(symbol, feld.value);
            punkt?.style.setProperty('--c', feld.value);
        });
    });
}
