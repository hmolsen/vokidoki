import {
    api, render, esc, $, go, topbar, wireBack, progressBar, showError,
} from '../core.js';

const NEXT_DELAY_CORRECT = 900;
const NEXT_DELAY_HINT    = 2400;   // Schreibweise lesen können
// Fuer eine falsche Antwort gibt es keine Wartezeit mehr: Dort entscheidet
// das Kind selbst, wann es weitergeht.

const GAP = '{}';

/*
 * Zeichen, die auf der deutschen Tastatur nur hinter einem langen Druck
 * liegen. iOS lässt sich das Tastaturlayout nicht vorschreiben - es gibt
 * dafür keine Web-Schnittstelle. Also legen wir die Zeichen selbst daneben.
 *
 * Englisch und Latein stehen bewusst nicht hier: Beide kommen mit der
 * deutschen Tastatur aus, und eine leere Reihe wäre nur im Weg.
 *
 * Der Apostroph steht bei Französisch vorn, weil fast jede zweite Lösung
 * einen braucht ("Je m'appelle", "l'école") und er auf der deutschen
 * Tastatur eine Ebene tiefer liegt.
 */
const ACCENT_KEYS = {
    fr: ["'", 'é', 'è', 'ê', 'ë', 'à', 'â', 'ç', 'î', 'ï', 'ô', 'û', 'ù', 'œ'],
    da: ['æ', 'ø', 'å'],
    no: ['æ', 'ø', 'å'],
    sv: ['å', 'ä', 'ö'],
    es: ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü'],
    it: ['à', 'è', 'é', 'ì', 'ò', 'ù'],
    nl: ['é', 'ë', 'ï', 'ö'],
    pt: ['á', 'â', 'ã', 'à', 'é', 'ê', 'í', 'ó', 'ô', 'õ', 'ú', 'ç'],
};

/**
 * Der gerade sichtbare Bildschirm.
 *
 * Er wird je Lerneinheit einmal gebaut; danach werden nur noch die Texte
 * getauscht. Der Grund ist die Tastatur: Baut man das Eingabefeld für jede
 * Vokabel neu, verliert es den Fokus, und iOS öffnet die Tastatur nicht von
 * selbst wieder - programmatischer Fokus zieht sie dort nur hoch, wenn er
 * unmittelbar aus einer Berührung kommt, und dazwischen liegt jedes Mal ein
 * Abruf beim Server. Bleibt dasselbe Feld stehen, bleibt auch die Tastatur.
 */
let zustand = null;

export async function clozeView(unitId) {
    zustand = null;

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

    // Der Hintergrundauftrag vom Einlesen ist noch unterwegs - abwarten statt
    // ein zweites Mal erzeugen zu lassen.
    if (data.preparing) {
        showPreparing(unitId);
        setTimeout(() => nextQuestion(unitId), 2500);
        return;
    }

    // Lerneinheiten von vor dem Hintergrundlauf: jetzt erzeugen.
    if (data.needs_preparation) {
        await prepare(unitId);
        return;
    }

    if (data.done) {
        zustand = null;
        showFinished(unitId, data);
        return;
    }

    // Steht der Bildschirm schon, wird nur die Karte getauscht.
    if (zustand !== null
        && zustand.unitId === String(unitId)
        && document.getElementById('answer') !== null) {
        showCard(data);
        return;
    }

    buildScreen(unitId, data);
}

/**
 * Baut den Bildschirm einmal auf.
 *
 * Feste Spalte über die sichtbare Höhe: Kopf und Fuß stehen, der Satz
 * dazwischen bekommt, was übrig bleibt. Das Eingabefeld sitzt in der Lücke
 * selbst - das spart nicht nur die Zeile, die es vorher für sich brauchte,
 * es ist auch das, was die Übung meint: Das Kind füllt die Lücke, es
 * beantwortet nicht daneben eine Frage.
 */
function buildScreen(unitId, data) {
    render(`
        <div class="screen">
            <div class="screen-top">
                ${topbar('Lückentext', {
                    backTo: `/unit/${unitId}`,
                    action: '<div class="topbar-progress" id="progress"></div>',
                })}
            </div>

            <form id="form" class="screen-body" autocomplete="off">
                <div class="screen-mid">
                    <p class="cloze-native" id="native"></p>

                    <!--
                        Die beiden Hälften des Satzes stehen in eigenen
                        Elementen, damit das Eingabefeld dazwischen nie
                        angefasst werden muss. Würde es beim Wechsel bewegt
                        oder neu gebaut, verlöre es den Fokus - und die
                        Tastatur ginge zu.
                    -->
                    <p class="cloze-foreign">
                        <span id="gap-before"></span
                        ><input type="text" id="answer" class="cloze-input"
                                ${data.lang ? `lang="${esc(data.lang)}"` : ''}
                                maxlength="128" size="1"
                                aria-label="Was fehlt?"
                                autocomplete="off" autocorrect="off"
                                autocapitalize="off" spellcheck="false"
                                enterkeyhint="done"
                        ><span id="gap-after"></span>
                    </p>

                    <div class="cloze-dots"><span class="dots" id="dots"></span></div>
                    <div class="verdict" id="verdict"></div>
                </div>

                <div class="screen-bottom">
                    <div class="cloze-actions">
                        <button class="btn cloze-check" type="submit" id="check">Prüfen</button>
                        <!--
                            Erscheint nur nach einer falschen Antwort. Genau
                            dann ist der Verdacht berechtigt, dass nicht das
                            Kind danebenlag, sondern der Satz.
                        -->
                        <button type="button" class="btn flagbtn" id="flag" hidden
                                aria-label="Diese Aufgabe melden"
                                title="Stimmt hier etwas nicht?">\u{2691}</button>
                    </div>

                    <p class="flag-done" id="flag-done" hidden></p>

                    ${accentRow(data.lang)}
                    <div id="msg"></div>
                </div>
            </form>
        </div>
    `);

    wireBack();

    const input = $('#answer');
    const check = $('#check');

    zustand = {
        unitId: String(unitId),
        data,
        input,
        check,
        answered: false,
        wartet: false,
        weiter: false,
    };

    input.addEventListener('input', () => fitField(input));

    // Wie bei den Zeichentasten: Ohne das nimmt der Knopf dem Feld den Fokus,
    // und die Tastatur klappt bei jedem Prüfen zu.
    check.addEventListener('mousedown', (event) => event.preventDefault());
    $('#flag').addEventListener('mousedown', (event) => event.preventDefault());

    $('#form').addEventListener('submit', (event) => {
        event.preventDefault();
        onSubmit(unitId);
    });

    $('#flag').addEventListener('click', () => reportSentence());

    wireAccents(input, () => zustand.answered);
    showCard(data);
}

/** Tauscht die Aufgabe aus, ohne das Eingabefeld anzufassen. */
function showCard(data) {
    const { input, check } = zustand;

    zustand.data     = data;
    zustand.answered = false;
    zustand.wartet   = false;
    zustand.weiter   = false;

    $('#native').textContent = data.native;

    const [vor, nach] = String(data.foreign).split(GAP);
    $('#gap-before').textContent = vor ?? '';
    $('#gap-after').textContent  = nach ?? '';

    input.value = '';
    input.readOnly = false;
    input.classList.remove('correct', 'almost', 'wrong');
    fitField(input);

    check.disabled = false;
    check.classList.remove('good', 'bad');
    check.textContent = 'Prüfen';

    // Die Meldemöglichkeit gehört zur Aufgabe, nicht zum Bildschirm - bei
    // jeder neuen Vokabel fängt sie wieder bei null an.
    const flagge = $('#flag');
    flagge.hidden = true;
    flagge.disabled = false;
    flagge.classList.remove('done');
    flagge.textContent = '\u{2691}';
    $('#flag-done').hidden = true;

    const verdict = $('#verdict');
    verdict.className = 'verdict';
    verdict.textContent = '';

    $('#dots').innerHTML = [0, 1, 2]
        .map((i) => `<i class="${i < Math.min(3, data.streak) ? 'on' : ''}"></i>`)
        .join('');

    const fortschritt = $('#progress');
    fortschritt.innerHTML = `
        ${progressBar(data.known, data.total)}
        <span class="tiny muted">${data.known}/${data.total}</span>`;
    fortschritt.title = `${data.known} von ${data.total} gelernt`;

    input.focus();
}

async function onSubmit(unitId) {
    const z = zustand;
    const { input, check } = z;

    /** Genau einmal weiterschalten - egal ob durch Klick oder Zeitablauf. */
    const naechste = () => {
        if (z.weiter) return;
        z.weiter = true;
        nextQuestion(unitId);
    };

    // Nach einer falschen Antwort ist der Knopf der Weiter-Knopf. Nach einer
    // richtigen schaltet er nur vorzeitig weiter, statt die Rückmeldung
    // abzuwarten.
    if (z.wartet) {
        naechste();
        return;
    }
    if (z.answered) return;

    const text = input.value.trim();
    if (text === '') {
        input.focus();
        return;
    }

    z.answered = true;
    input.readOnly = true;
    check.disabled = true;

    let result;
    try {
        result = await api('cloze', 'answer', { body: { nonce: z.data.nonce, text } });
    } catch (err) {
        z.answered = false;
        input.readOnly = false;
        check.disabled = false;
        showError(err.message);
        return;
    }

    const verdict = $('#verdict');
    check.disabled = false;
    z.wartet = true;

    if (result.correct && result.exact) {
        input.classList.add('correct');
        check.classList.add('good');
        check.textContent = result.just_learned ? 'Sitzt!' : 'Richtig!';
        verdict.className = 'verdict good';
        verdict.textContent = result.just_learned
            ? 'Diese Vokabel kannst du jetzt.'
            : 'Weiter so!';
        setTimeout(naechste, NEXT_DELAY_CORRECT);
    } else if (result.correct) {
        // Zählt als richtig, aber die Schreibweise soll das Kind sehen.
        input.classList.add('almost');
        check.classList.add('good');
        check.textContent = 'Fast richtig!';
        verdict.className = 'verdict good';
        verdict.innerHTML = `So schreibt man es:<br><strong>${esc(result.answer)}</strong>`;
        setTimeout(naechste, NEXT_DELAY_HINT);
    } else {
        /*
         * Falsch: kein Zeitablauf. Das Kind soll die richtige Lösung in Ruhe
         * lesen können und selbst entscheiden, wann es weitergeht - genau die
         * Stelle, an der Lernen passiert.
         */
        input.classList.add('wrong');
        check.classList.add('bad');
        check.textContent = 'Weiter';
        verdict.className = 'verdict bad';
        verdict.innerHTML = `Nicht ganz. Richtig ist:<br><strong>${esc(result.answer)}</strong>`;

        // Vielleicht lag ja gar nicht das Kind daneben, sondern der Satz.
        z.sentenceId = result.sentence_id;
        z.typed = text;
        $('#flag').hidden = false;
    }
}

/**
 * Meldet, dass mit dieser Aufgabe etwas nicht stimmt.
 *
 * Das Getippte geht mit: Im Admin entscheidet meist genau das, ob der Satz
 * schief war oder die erwartete Antwort - ohne diese Angabe bliebe nur die
 * Vermutung.
 */
async function reportSentence() {
    const z = zustand;
    const flagge = $('#flag');
    const fertig = $('#flag-done');

    if (!z || !z.sentenceId || flagge.disabled) return;

    flagge.disabled = true;

    try {
        await api('cloze', 'flag', {
            body: { sentence_id: Number(z.sentenceId), text: z.typed ?? '' },
        });
    } catch (err) {
        flagge.disabled = false;
        showError(err.message);
        return;
    }

    flagge.classList.add('done');
    flagge.textContent = '\u{2713}';
    fertig.textContent = 'Danke! Papa schaut sich die Aufgabe an.';
    fertig.hidden = false;
}

/**
 * Die Zeichenreihe zum Eingabefeld. Ohne passende Sprache bleibt sie weg -
 * dann ist dort schlicht nichts.
 */
function accentRow(lang) {
    const keys = ACCENT_KEYS[String(lang || '').toLowerCase()];
    if (!keys) return '';

    return `
        <div class="accents" id="accents" role="group" aria-label="Sonderzeichen">
            ${keys.map((ch) => `
                <button type="button" class="accent" data-ch="${esc(ch)}"
                        tabindex="-1">${esc(ch)}</button>`).join('')}
        </div>`;
}

/** Setzt ein Zeichen an der Schreibmarke ein, ohne den Fokus zu verlieren. */
function wireAccents(input, istBeantwortet) {
    const reihe = $('#accents');
    if (!reihe) return;

    // Der entscheidende Teil: Ohne das hier nimmt der Knopf dem Feld den Fokus,
    // die Tastatur klappt bei jedem Zeichen zu und wieder auf.
    reihe.addEventListener('mousedown', (event) => event.preventDefault());

    reihe.addEventListener('click', (event) => {
        const taste = event.target.closest('.accent');
        if (!taste || istBeantwortet() || input.readOnly) return;

        const zeichen = taste.dataset.ch;
        const von = input.selectionStart ?? input.value.length;
        const bis = input.selectionEnd ?? von;

        input.value = input.value.slice(0, von) + zeichen + input.value.slice(bis);

        // Schreibmarke hinter das eingefügte Zeichen - sonst tippt das Kind
        // weiter am Anfang des Feldes.
        const danach = von + zeichen.length;
        input.setSelectionRange(danach, danach);
        fitField(input);
        input.focus();
    });
}

/**
 * Breite nach dem, was drinsteht.
 *
 * Die Untergrenze verrät nichts über die Länge der Lösung - das Feld wächst
 * erst mit dem, was das Kind selbst tippt.
 */
function fitField(input) {
    const zeichen = Math.min(Math.max(input.value.length + 1, 7), 20);
    input.style.width = `${zeichen}ch`;
}

/** Wartebild, während die Sätze entstehen. */
function showPreparing(unitId) {
    if (document.getElementById('preparing')) return;   // schon zu sehen

    zustand = null;
    render(`
        <div class="empty" id="preparing" style="padding-top:18vh">
            <div class="spinner"></div>
            <strong>Deine Sätze werden vorbereitet...</strong>
            <p class="tiny muted">
                Das dauert einmalig etwa eine halbe Minute.<br>
                Danach geht es immer sofort los.
            </p>
        </div>
    `);
}

async function prepare(unitId) {
    showPreparing(unitId);

    try {
        await api('cloze', 'prepare', { body: { unit_id: Number(unitId) } });
    } catch (err) {
        showFailure(unitId, err.message);
        return;
    }

    await nextQuestion(unitId);
}

function showFailure(unitId, message) {
    zustand = null;
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
