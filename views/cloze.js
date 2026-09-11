import {
    api, render, esc, $, go, topbar, wireBack, progressBar, showError,
} from '../core.js';

const NEXT_DELAY_CORRECT = 900;
const NEXT_DELAY_HINT    = 2400;   // Schreibweise lesen können
const NEXT_DELAY_WRONG   = 2600;

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
        showFinished(unitId, data);
        return;
    }

    /*
     * Feste Spalte über die sichtbare Höhe: Kopf und Fuß stehen, der Satz
     * dazwischen bekommt, was übrig bleibt.
     *
     * Das Eingabefeld sitzt in der Lücke selbst. Das spart nicht nur die
     * Zeile, die es vorher für sich brauchte - es ist auch das, was die
     * Übung eigentlich meint: Das Kind füllt die Lücke, es beantwortet
     * nicht daneben eine Frage.
     */
    render(`
        <div class="screen">
            <div class="screen-top">
                ${topbar('Lückentext', {
                    backTo: `/unit/${unitId}`,
                    action: `
                        <div class="topbar-progress" title="${data.known} von ${data.total} gelernt">
                            ${progressBar(data.known, data.total)}
                            <span class="tiny muted">${data.known}/${data.total}</span>
                        </div>`,
                })}
            </div>

            <form id="form" class="screen-body" autocomplete="off">
                <div class="screen-mid">
                    <p class="cloze-native">${esc(data.native)}</p>
                    <p class="cloze-foreign">${gapField(data.foreign, data.lang)}</p>

                    <div class="cloze-dots">
                        <span class="dots">${
                            [0, 1, 2].map((i) =>
                                `<i class="${i < Math.min(3, data.streak) ? 'on' : ''}"></i>`).join('')
                        }</span>
                    </div>

                    <div class="verdict" id="verdict"></div>
                </div>

                <div class="screen-bottom">
                    <button class="btn small cloze-check" type="submit" id="check">Prüfen</button>
                    ${accentRow(data.lang)}
                    <div id="msg"></div>
                </div>
            </form>
        </div>
    `);

    wireBack();

    const form  = $('#form');
    const input = $('#answer');
    let answered = false;

    fitField(input);
    input.addEventListener('input', () => fitField(input));

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

    wireAccents(input, () => answered);
    input.focus();
}

/**
 * Die Zeichenreihe zum Eingabefeld. Ohne passende Sprache bleibt sie weg -
 * dann ist dort schlicht nichts.
 */
function accentRow(lang) {
    const keys = ACCENT_KEYS[String(lang || '').toLowerCase()];
    if (!keys) return '';

    return `
        <div class="accents" id="accents" role="group" aria-label="Sonderzeichen"
             style="--cols:${accentColumns(keys.length)}">
            ${keys.map((ch) => `
                <button type="button" class="accent" data-ch="${esc(ch)}"
                        tabindex="-1">${esc(ch)}</button>`).join('')}
        </div>`;
}

/**
 * Wie viele Tasten je Reihe?
 *
 * Ein festes Raster statt freiem Umbruch: Sonst steht in der zweiten Reihe
 * ein Rest, der nicht unter der ersten ausgerichtet ist. Gesucht ist die
 * breiteste Aufteilung, die glatt aufgeht - bei 14 Zeichen also zweimal
 * sieben. Geht keine auf, bleiben es sieben; im Raster fluchten die Tasten
 * der letzten Reihe dann immer noch mit denen darüber.
 */
function accentColumns(count) {
    if (count <= 7) return count;

    for (let spalten = 7; spalten >= 4; spalten--) {
        if (count % spalten === 0) return spalten;
    }
    return 7;
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
        input.focus();
    });
}

/**
 * Setzt das Eingabefeld an die Stelle der Lücke.
 *
 * Die Breite ist bewusst fest und verrät nichts über die Länge der Lösung -
 * sie wächst erst mit dem, was das Kind selbst tippt.
 */
function gapField(text, lang) {
    const feld = `<input type="text" id="answer" class="cloze-input"
                         ${lang ? `lang="${esc(lang)}"` : ''}
                         maxlength="128" size="1"
                         aria-label="Was fehlt?"
                         autocomplete="off" autocorrect="off"
                         autocapitalize="off" spellcheck="false"
                         enterkeyhint="done">`;

    return esc(text).replace('{}', feld);
}

/** Breite nach dem, was drinsteht - zwischen einer leeren Lücke und der Zeile. */
function fitField(input) {
    const zeichen = Math.min(Math.max(input.value.length + 1, 7), 20);
    input.style.width = `${zeichen}ch`;
}

/** Wartebild, während die Sätze entstehen. */
function showPreparing(unitId) {
    if (document.getElementById('preparing')) return;   // schon zu sehen

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
