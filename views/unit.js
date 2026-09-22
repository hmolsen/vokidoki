import {
    VT, api, render, esc, $, go, topbar, loading, wireBack, progressBar,
    showError, clearError, lernansicht,
} from '../core.js';
import { hantel } from './frei.js';
import {
    einheit, vokabelListe, modusStand, zuruecksetzen, vorratAuffrischen,
} from '../vorrat.js';

/** Die Übungsarten - Reihenfolge und Symbole gelten für die ganze Ansicht. */
const EXERCISES = [
    { mode: 'mc',    icon: '\u{1F3AF}', title: 'Auswählen' },
    { mode: 'cloze', icon: '\u{270F}\u{FE0F}', title: 'Lückentext' },
];

/**
 * Wer benennt hier um und loescht?
 *
 * Eine Lehrkraft nicht - jedenfalls nicht von hier aus. Diese Seite ist die
 * Ansicht ihrer Klasse, und die soll genau das sein: Was ein Kind nicht
 * sieht, steht auch fuer sie nicht da. Umbenennen und Loeschen stehen im
 * Lehrkraft-Bereich, auf derselben Lerneinheit.
 *
 * Fuer ein Kind mit Einlese-Recht bleibt es: Wer seine Lerneinheiten selbst
 * anlegen darf, muss sie auch wieder loswerden koennen. Dieselbe Regel wie
 * beim Einlesen in der Kursansicht.
 *
 * Das Zuruecksetzen bleibt fuer alle: Der Lernstand gehoert dem Konto, das
 * ihn erarbeitet hat.
 */
function selbstVerwalten() {
    return VT.user.canImport && !VT.user.isTeacher;
}

/** Detailansicht einer Lerneinheit: Fortschritt, Wortliste, Aktionen. */
export async function unitView(unitId) {
    // Aus dem Vorrat: kein Ladepunkt, kein Warten, und es geht ohne Netz.
    const roh = einheit(unitId);
    if (roh === null) {
        render(`${topbar('Lerneinheit', { backTo: '/' })}<div id="msg"></div>`);
        wireBack();
        showError('Diese Lerneinheit ist noch nicht geladen. '
                  + 'Einmal mit Netz öffnen, dann geht es auch ohne.');
        return;
    }

    const unit  = { id: roh.i, title: roh.t, language_id: roh.l };
    const vocab = vokabelListe(unitId);
    const modes = modusStand(unitId);

    // Beide Übungsarten zusammen - eine Vokabel ist erst durch, wenn sie in
    // jeder Form sitzt, in der sie überhaupt geübt werden kann.
    const summe = (mode, feld) => vocab.reduce((s, v) => s + v.modes[mode][feld], 0);
    const correct = summe('mc', 'correct') + summe('cloze', 'correct');
    const wrong   = summe('mc', 'wrong')   + summe('cloze', 'wrong');
    const asked   = correct + wrong;
    const quota   = asked > 0 ? Math.round((correct / asked) * 100) : null;

    const komplett = vocab.length > 0 && vocab.every(
        (v) => EXERCISES.every((e) => !v.modes[e.mode].possible || v.modes[e.mode].known),
    );

    const list = vocab.map((v) => `
        <div class="row vocab">
            <span class="body">
                <span class="title">${esc(v.term_foreign)}</span>
                <span class="tiny muted">${esc(v.term_native)}${
                    v.note ? ` &middot; ${esc(v.note)}` : ''
                }</span>
            </span>
            <span class="marks">
                ${EXERCISES.map((e) => mark(e, v.modes[e.mode])).join('')}
            </span>
        </div>
    `).join('');

    render(`
        ${lernansicht()}
        ${topbar(unit.title, { backTo: `/lang/${unit.language_id}` })}
        <div id="msg"></div>

        ${komplett ? '<div class="notice good">Diese Lerneinheit hast du in beiden Übungen geschafft!</div>' : ''}

        <h2>Üben</h2>
        <div id="exercises">
            ${exerciseRow(EXERCISES[0].mode, EXERCISES[0].icon, EXERCISES[0].title,
                'Vier Antworten, eine ist richtig', modes.mc)}
            ${clozeRow(modes.cloze)}
            <!--
                Freies Ueben: alles, was freigegeben ist, ohne Ziel und ohne
                Ende. Es ruehrt den Lernstand nicht an - deshalb steht hier
                auch kein Fortschritt, sondern nur, was es tut.
            -->
            <button class="row" data-frei="${unit.id}">
                <span class="lead">${hantel('hantel lead')}</span>
                <span class="body">
                    <span class="title">Freies Üben</span>
                    <span class="tiny muted">Alle Vokabeln dieser Lerneinheit, so lange du magst</span>
                </span>
                <span class="chev">&#8250;</span>
            </button>
        </div>

        ${quota === null ? '' : `
            <p class="tiny muted center" style="margin:-2px 0 4px">
                Zusammen ${correct} richtig, ${wrong} falsch &middot; ${quota}% Trefferquote
            </p>`}

        <h2>Alle Vokabeln (${vocab.length})</h2>
        <p class="legend tiny muted">
            ${EXERCISES.map((e) => `${e.icon} ${esc(e.title)}`).join(' &nbsp;&middot;&nbsp; ')}
        </p>
        ${list}

        <h2>Verwalten</h2>
        <div class="btn-row" style="margin-bottom:10px">
            ${selbstVerwalten()
                ? '<button class="btn secondary small" id="rename">Umbenennen</button>' : ''}
            <button class="btn secondary small" id="reset">Fortschritt zurücksetzen</button>
        </div>
        ${selbstVerwalten()
            ? '<button class="btn ghost" id="delete">Lerneinheit löschen</button>' : ''}
    `);

    wireBack();

    wireExercises(unit.id, modes);
    watchSentences(unit.id, modes);


    /*
     * Die Hoerer gibt es nur, wenn die Knoepfe da sind - siehe
     * selbstVerwalten(). Fuer ein Kind in einer Klasse waeren es Knoepfe,
     * die nur eine Absage holen; die API lehnt beides ohnehin ab.
     *
     * Das Zuruecksetzen bleibt: Der Lernstand gehoert dem Kind.
     */
    const rename = $('#rename');
    if (rename) rename.addEventListener('click', async () => {
        const title = prompt('Neuer Titel der Lerneinheit:', unit.title);
        if (title === null) return;
        clearError();
        try {
            await api('units', 'rename', { body: { id: unit.id, title: title.trim() } });
            // Umbenennen aendert, was im Vorrat steht - also einmal nachholen,
            // sonst stuende der alte Titel bis zum naechsten Start da.
            await vorratAuffrischen();
            go(`/unit/${unit.id}`);
        } catch (err) {
            showError(err.message);
        }
    });

    $('#reset').addEventListener('click', () => {
        if (!confirm('Allen Lernfortschritt dieser Einheit zurücksetzen?')) return;
        // Beide Uebungsarten, und auch ohne Netz.
        zuruecksetzen(unit.id);
        go(`/unit/${unit.id}`);
    });

    const del = $('#delete');
    if (del) del.addEventListener('click', async () => {
        if (!confirm(`"${unit.title}" mit allen Vokabeln endgültig löschen?`)) return;
        try {
            await api('units', 'delete', { body: { id: unit.id } });
            await vorratAuffrischen();
            go(`/lang/${unit.language_id}/units`);
        } catch (err) {
            showError(err.message);
        }
    });
}

/**
 * Ein einziger Klick-Handler auf dem Behälter.
 *
 * Delegation statt Handler je Zeile: Die Lückentext-Zeile wird nachgezeichnet,
 * sobald ihre Sätze fertig sind - mit Handlern an den Zeilen selbst haetten
 * wir danach zwei auf der unveränderten Nachbarzeile.
 */
function wireExercises(unitId, modes) {
    $('#exercises').addEventListener('click', async (event) => {
        // Freies Ueben hat keinen Lernstand, der zurueckzusetzen waere - es
        // geht ohne Umweg los.
        const frei = event.target.closest('[data-frei]');
        if (frei) { go(`/unit/${frei.dataset.frei}/frei`); return; }

        const row = event.target.closest('[data-mode]');
        if (!row || row.disabled) return;

        const mode = row.dataset.mode;
        const info = modes[mode];

        // Eine bestandene Übung braucht einen frischen Lernstand, sonst wären
        // sofort wieder alle Vokabeln als gekonnt markiert. Zurückgesetzt wird
        // nur diese Übungsart - die andere behält ihren Fortschritt.
        if (info.total > 0 && info.known >= info.total) {
            // Auch ohne Netz: Der Vorrat vergisst sofort, der Server erfaehrt
            // es im selben Strom wie die Antworten - und in derselben
            // Reihenfolge, also vor dem, was danach geuebt wird.
            zuruecksetzen(unitId, mode);
        }
        go(`${mode === 'cloze' ? '/cloze' : '/quiz'}/${unitId}`);
    });
}

/**
 * Fragt nach, bis die Sätze fertig sind, und schaltet die Zeile dann frei -
 * ohne dass das Kind neu laden muss.
 */
function watchSentences(unitId, modes) {
    if (modes.cloze.status !== 'running') return;

    const tick = async () => {
        const row = document.querySelector('[data-mode-row="cloze"]');
        // Ansicht gewechselt: Der Router hat den Inhalt ersetzt, also aufhören.
        if (!row) return;

        // Der Vorrat weiss es, sobald er aufgefrischt ist - und er bringt
        // die frischen Saetze gleich mit.
        if (!await vorratAuffrischen()) {
            setTimeout(tick, 6000);   // Aussetzer überbrücken, nicht aufgeben
            return;
        }
        const data = { cloze: modusStand(unitId).cloze };

        if (data.cloze.status === 'running') {
            setTimeout(tick, 2500);
            return;
        }

        // Nur die Zeile tauschen; der Handler sitzt am Behälter und bleibt.
        modes.cloze = data.cloze;
        row.outerHTML = clozeRow(data.cloze);
    };

    setTimeout(tick, 2000);
}

/** Die Lückentext-Zeile - sie wechselt ihren Zustand im laufenden Betrieb. */
function clozeRow(info) {
    return exerciseRow(EXERCISES[1].mode, EXERCISES[1].icon, EXERCISES[1].title,
        'Das fehlende Wort in den Satz eintippen', info);
}

/** Eine Übungsart als Zeile mit eigenem Fortschritt. */
function exerciseRow(mode, icon, title, hint, info) {
    const fertig = info.total > 0 && info.known >= info.total;
    const wartet = info.status === 'running';
    const kaputt = info.status === 'failed';

    // Solange die Sätze entstehen: Spinner statt Symbol, Zeile nicht anklickbar.
    const lead = wartet
        ? '<span class="spinner inline"></span>'
        : (fertig ? '\u{2705}' : icon);

    let text;
    if (wartet) {
        text = 'Deine Sätze werden vorbereitet...';
    } else if (kaputt) {
        text = info.error || 'Die Sätze konnten nicht erzeugt werden.';
    } else if (info.total > 0) {
        text = `${info.known} von ${info.total} gelernt`;
    } else {
        text = hint;
    }

    return `
        <button class="row" data-mode-row="${mode}"
                ${wartet ? 'disabled' : `data-mode="${mode}"`}>
            <span class="lead">${lead}</span>
            <span class="body">
                <span class="title">${esc(title)}</span>
                <span class="tiny ${kaputt ? 'warn' : 'muted'}">${esc(text)}</span>
                ${!wartet && info.total > 0 ? progressBar(info.known, info.total) : ''}
            </span>
            <span class="chev">${wartet ? '' : '&#8250;'}</span>
        </button>`;
}

/**
 * Der Stand einer Vokabel in einer Übungsart: Haken, drei Punkte oder Strich.
 * Der Strich heißt "noch kein Lückensatz" - etwa weil das Modell für diese
 * Vokabel keinen brauchbaren erzeugen konnte. Im Admin lässt sich nachtragen.
 */
function mark(exercise, info) {
    // Symbol und Stand jeweils in einer Zelle fester Breite: Drei Punkte sind
    // breiter als ein Haken, sonst tanzten die Symbole von Zeile zu Zeile.
    const zelle = (inhalt, titel) => `
        <span class="mark" title="${esc(titel)}">
            <span class="mark-icon">${exercise.icon}</span>
            <span class="mark-state">${inhalt}</span>
        </span>`;

    if (!info.possible) {
        return zelle('<span class="mark-off">&ndash;</span>',
            `${exercise.title}: noch kein Lückensatz`);
    }

    if (info.known) {
        return zelle('<span class="mark-done">\u{2713}</span>',
            `${exercise.title}: gekonnt`);
    }

    return zelle(dots(info.streak),
        `${exercise.title}: ${Math.min(3, info.streak)} von 3 hintereinander`);
}

/** Drei Punkte zeigen, wie oft die Vokabel schon hintereinander saß. */
function dots(streak) {
    const filled = Math.min(3, Math.max(0, streak));
    return `<span class="dots">${
        [0, 1, 2].map((i) => `<i class="${i < filled ? 'on' : ''}"></i>`).join('')
    }</span>`;
}
