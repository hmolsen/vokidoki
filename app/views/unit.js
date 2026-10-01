import {
    VT, api, render, esc, $, go, topbar, loading, wireBack, progressBar,
    showError, clearError, lernansicht,
} from '../core.js';
import { hantel } from './frei.js';
import {
    einheit, vokabelListe, modusStand, zuruecksetzen, vorratAuffrischen,
} from '../vorrat.js';

/**
 * Die Übungsarten - in dieser Reihenfolge stehen sie da, und in dieser
 * Reihenfolge führt "weitermachen" am Ende einer Übung zur nächsten.
 *
 * Einsetzen steht zwischen den beiden anderen: Es ist die Stufe dazwischen -
 * das Wort im Satz, aber noch ohne es selbst zu schreiben.
 */
const UEBUNGEN = [
    { mode: 'mc',    icon: '\u{1F3AF}', title: 'Auswählen',  ziel: '/quiz',
      hint: 'Vier Antworten, eine ist richtig' },
    { mode: 'pick',  icon: '\u{1F9E9}', title: 'Einsetzen',  ziel: '/einsetzen',
      hint: 'Das passende Wort in die Lücke ziehen' },
    { mode: 'cloze', icon: '\u{270F}\u{FE0F}', title: 'Lückentext', ziel: '/cloze',
      hint: 'Das fehlende Wort in den Satz eintippen' },
];

/*
 * Die Übungsarten mit einem Zeichen neben jeder Vokabel.
 *
 * Einsetzen fehlt hier mit Absicht: Neben jeder Vokabel stehen zwei
 * Zellen - Zeichen und drei Punkte oder Haken -, und für eine dritte ist
 * auf dem Telefon kein Platz. Solange die Liste nicht neu gezeichnet ist,
 * zählt sie auch nicht zu "in allen Übungen geschafft".
 */
const EXERCISES = UEBUNGEN.filter((u) => u.mode !== 'pick');

const uebung = (mode) => UEBUNGEN.find((u) => u.mode === mode);

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
            ${uebungRow('mc', modes.mc)}
            ${uebungRow('pick', modes.pick)}
            ${uebungRow('cloze', modes.cloze)}
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

    wireExercises(unit.id);
    watchSentences(unit.id, modes);
    nachFreigabeSehen(unit.id, modes);


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
function wireExercises(unitId) {
    $('#exercises').addEventListener('click', async (event) => {
        // Freies Ueben hat keinen Lernstand, der zurueckzusetzen waere - es
        // geht ohne Umweg los.
        const frei = event.target.closest('[data-frei]');
        if (frei) { go(`/unit/${frei.dataset.frei}/frei`); return; }

        /*
         * Eine geschaffte Übung hat kein data-mode und ist disabled - sie
         * landet also gar nicht hier. Früher setzte ein Druck darauf still
         * den Lernstand zurück und fing von vorn an; damit war die Arbeit
         * weg, die das grüne Feld gerade noch gezeigt hatte. Wer wirklich
         * von vorn will, hat unten "Fortschritt zurücksetzen".
         */
        const row = event.target.closest('[data-mode]');
        if (!row || row.disabled) return;

        go(`${uebung(row.dataset.mode)?.ziel ?? '/quiz'}/${unitId}`);
    });
}

/** Ist eine Übungsart durch? Nur, wenn es überhaupt etwas zu üben gab. */
function geschafft(info) {
    return info.total > 0 && info.known >= info.total;
}

/**
 * Der Knopf am Ende einer Übung: wohin es von hier aus weitergeht.
 *
 * Dort stand "Noch einmal üben", und der Knopf setzte den Lernstand dieser
 * Übung zurück. Das Kind hatte gerade alles geschafft - und der
 * naheliegendste Druck warf es wieder auf null. Jetzt führt er dorthin, wo
 * noch etwas zu tun ist: zur anderen Übungsart, solange die offen ist, und
 * wenn beide durch sind, ins Freie Üben - das hält wach, ohne am Lernstand
 * zu rühren. Wer wirklich von vorn will, hat in der Lerneinheit
 * "Fortschritt zurücksetzen".
 *
 * Steht hier und nicht in quiz.js, einsetzen.js und cloze.js, weil alle
 * Enden dieselbe Regel brauchen und die Übungsarten samt Symbolen hier
 * stehen. Mit drei Übungen gilt: die nächste offene nach der geschafften,
 * in der Reihenfolge von UEBUNGEN - nach dem Lückentext wieder von vorn.
 *
 * Der Lückentext gilt als offen, wenn es Sätze gibt, die noch nicht sitzen -
 * oder wenn sie gerade entstehen; dann wartet die Übung auf sie. Gibt es
 * gar keine, führt der Weg ins Freie Üben statt vor eine leere Übung.
 *
 * @param fertig 'mc', 'pick' oder 'cloze' - die Übung, die gerade geschafft ist.
 * @returns HTML des Knopfes; verdrahtet wird er mit weiterVerdrahten().
 */
export function weiterKnopf(unitId, fertig) {
    const stand = modusStand(unitId);
    const ab    = UEBUNGEN.findIndex((u) => u.mode === fertig);

    for (let schritt = 1; schritt < UEBUNGEN.length; schritt++) {
        const andere   = UEBUNGEN[(ab + schritt) % UEBUNGEN.length];
        const info     = stand[andere.mode];
        const moeglich = info.total > 0 || info.status === 'running';
        if (moeglich && !geschafft(info)) {
            return `<button class="btn" id="weiter" data-ziel="${andere.ziel}/${esc(unitId)}">`
                 + `Mit ${andere.icon} ${esc(andere.title)} weitermachen</button>`;
        }
    }

    return `<button class="btn" id="weiter" data-ziel="/unit/${esc(unitId)}/frei">`
         + `${hantel('hantel')} Freies Üben</button>`;
}

/** Den Knopf aus weiterKnopf() anschliessen. */
export function weiterVerdrahten() {
    $('#weiter')?.addEventListener('click', (e) => go(e.currentTarget.dataset.ziel));
}

/*
 * Gibt die Lehrkraft weitere Vokabeln frei, wird eine geschaffte Übung von
 * selbst wieder anklickbar: "Geschafft" heisst "alles Freigegebene gekonnt",
 * und mit neuen Vokabeln stimmt das nicht mehr.
 *
 * Das Gerät erfährt von einer Freigabe aber nur, wenn der Vorrat
 * aufgefrischt wird - beim Start und nach einer Weile im Hintergrund. Ein
 * Kind, das gerade vor seiner grünen Übung sitzt, sähe die neuen Vokabeln
 * sonst erst morgen. Deshalb hier einmal nachsehen, sobald eine Übung
 * geschafft dasteht, und neu zeichnen, wenn etwas kam. Ohne Netz bleibt es
 * beim Stand im Gerät.
 */
function nachFreigabeSehen(unitId, modes) {
    if (!geschafft(modes.mc) && !geschafft(modes.pick) && !geschafft(modes.cloze)) return;

    vorratAuffrischen().then((frisch) => {
        if (frisch && location.hash === `#/unit/${unitId}`) unitView(unitId);
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
        const data = modusStand(unitId);

        if (data.cloze.status === 'running') {
            setTimeout(tick, 2500);
            return;
        }

        // Nur die Zeilen tauschen; der Handler sitzt am Behälter und bleibt.
        // Einsetzen hängt an denselben Sätzen - es wird mit frei.
        modes.cloze = data.cloze;
        modes.pick  = data.pick;
        row.outerHTML = uebungRow('cloze', data.cloze);
        const einsetzen = document.querySelector('[data-mode-row="pick"]');
        if (einsetzen) einsetzen.outerHTML = uebungRow('pick', data.pick);
    };

    setTimeout(tick, 2000);
}

/** Die Zeile einer Übungsart - Lückentext und Einsetzen wechseln ihren Zustand im Betrieb. */
function uebungRow(mode, info) {
    const u = uebung(mode);
    return exerciseRow(u.mode, u.icon, u.title, u.hint, info);
}

/** Eine Übungsart als Zeile mit eigenem Fortschritt. */
function exerciseRow(mode, icon, title, hint, info) {
    const fertig = geschafft(info);
    const wartet = info.status === 'running';
    const kaputt = info.status === 'failed';
    const zu     = wartet || fertig;

    /*
     * Solange die Sätze entstehen: Spinner statt Symbol, Zeile nicht
     * anklickbar. Geschafft: auch nicht anklickbar, aber das Symbol bleibt -
     * Zielscheibe und Stift sagen, welche Übung es war, ein Haken hätte bei
     * beiden gleich ausgesehen. Dass sie durch ist, zeigt das Grün.
     */
    const lead = wartet ? '<span class="spinner inline"></span>' : icon;

    let text;
    if (wartet) {
        text = 'Deine Sätze werden vorbereitet...';
    } else if (fertig) {
        text = `Geschafft - alle ${info.total} gelernt`;
    } else if (kaputt) {
        text = info.error || 'Die Sätze konnten nicht erzeugt werden.';
    } else if (info.total > 0) {
        text = `${info.known} von ${info.total} gelernt`;
    } else {
        text = hint;
    }

    return `
        <button class="row${fertig ? ' geschafft' : ''}" data-mode-row="${mode}"
                ${zu ? 'disabled' : `data-mode="${mode}"`}>
            <span class="lead">${lead}</span>
            <span class="body">
                <span class="title">${esc(title)}</span>
                <span class="tiny ${kaputt ? 'warn' : 'muted'}">${esc(text)}</span>
                ${!wartet && info.total > 0 ? progressBar(info.known, info.total) : ''}
            </span>
            <span class="chev">${zu ? '' : '&#8250;'}</span>
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
