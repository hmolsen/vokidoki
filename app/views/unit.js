import {
    VT, api, render, esc, $, go, topbar, loading, wireBack, progressBar,
    showError, clearError, lernansicht,
} from '../core.js';
import { hantel } from './frei.js';
import {
    einheit, vokabelListe, modusStand, zuruecksetzen, vorratAuffrischen, hatStimme,
    hoerenVorladen,
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
    // Zuletzt: Hier steht nichts mehr auf dem Bildschirm, was hilft - nur der Klang.
    { mode: 'listen', icon: '\u{1F3A7}', title: 'Hören', ziel: '/hoeren',
      hint: 'Den Satz anhören und die Wörter in die richtige Reihenfolge legen' },
];

/*
 * Die Übungsarten neben jeder Vokabel - alle, auch Einsetzen.
 *
 * Einsetzen fehlte hier eine Weile: Je Übung standen Zeichen und drei
 * Punkte nebeneinander, rund 50 px, und für eine dritte war am Telefon kein
 * Platz. Jetzt stehen die Zeichen einmal oben über der Liste und je Zeile
 * nur ein Ring aus drei Teilen - 22 px je Übung, auch für weitere, die noch
 * kommen. Die Namen stehen in der aufgeklappten Zeile.
 */
const EXERCISES = UEBUNGEN;

/**
 * Die Übungen dieser Lerneinheit: Hören nur, wenn es für die Sprache eine
 * Stimme gibt. In Lateinkursen gibt es die Übung nicht - keine Zeile, kein
 * Ring, und sie zählt nicht zu "in allen Übungen geschafft".
 */
function uebungenFuer(unitId) {
    const mitStimme = hatStimme(einheit(unitId)?.l);
    return EXERCISES.filter((u) => u.mode !== 'listen' || mitStimme);
}

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
    const uebungen = uebungenFuer(unitId);

    // Alle Übungsarten zusammen - eine Vokabel ist erst durch, wenn sie in
    // jeder Form sitzt, in der sie überhaupt geübt werden kann.
    const summe = (feld) => vocab.reduce(
        (s, v) => s + uebungen.reduce((t, e) => t + v.modes[e.mode][feld], 0), 0);
    const correct = summe('correct');
    const wrong   = summe('wrong');
    const asked   = correct + wrong;
    const quota   = asked > 0 ? Math.round((correct / asked) * 100) : null;

    const komplett = vocab.length > 0 && vocab.every(
        (v) => uebungen.every((e) => !v.modes[e.mode].possible || v.modes[e.mode].known),
    );

    /*
     * Je Vokabel eine Zeile mit einem Ring je Übung; ein Druck klappt sie
     * auf und zeigt die Übungen mit Namen und den drei Punkten. Als
     * <details>, damit das ohne eine Zeile Skript geht - und mit der
     * Tastatur.
     */
    const list = vocab.map((v) => `
        <details class="vocabzeile">
            <summary class="row vocab">
                <span class="body">
                    <span class="title">${esc(v.term_foreign)}</span>
                    <span class="tiny muted">${esc(v.term_native)}${
                        v.note ? ` &middot; ${esc(v.note)}` : ''
                    }</span>
                </span>
                <span class="ringe">
                    ${uebungen.map((e) => ring(e, v.modes[e.mode])).join('')}
                </span>
            </summary>
            <div class="vocabdetail">
                ${uebungen.map((e) => mark(e, v.modes[e.mode])).join('')}
            </div>
        </details>
    `).join('');

    render(`
        ${lernansicht()}
        ${topbar(unit.title, { backTo: `/lang/${unit.language_id}` })}
        <div id="msg"></div>

        ${komplett ? '<div class="notice good">Diese Lerneinheit hast du in allen Übungen geschafft!</div>' : ''}

        <h2>Üben</h2>
        <div id="exercises">
            ${uebungRow('mc', modes.mc)}
            ${uebungRow('pick', modes.pick)}
            ${uebungRow('cloze', modes.cloze)}
            ${modes.listen.stimme ? uebungRow('listen', modes.listen) : ''}
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
        <div class="vocabliste">
            <div class="vocabkopf">
                <span class="tiny muted">Antippen für Einzelheiten</span>
                <span class="ringe">
                    ${uebungen.map((e) => `<span class="ringzeichen" title="${esc(e.title)}"
                        aria-label="${esc(e.title)}">${e.icon}</span>`).join('')}
                </span>
            </div>
            ${list}
        </div>

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

    kopfUnterLeiste();
    // Die Aufnahmen schon holen, solange Netz da ist - im Zug ist es zu spät.
    // Auch ohne Hören: Die Aussprache der Vokabeln braucht das Auswählen.
    if (modes.listen.stimme) hoerenVorladen(unit.id);
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
    const reihe = uebungenFuer(unitId);
    const ab    = reihe.findIndex((u) => u.mode === fertig);

    for (let schritt = 1; schritt < reihe.length; schritt++) {
        const andere   = reihe[(ab + schritt) % reihe.length];
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
    if (!['mc', 'pick', 'cloze', 'listen'].some((m) => geschafft(modes[m]))) return;

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
        modes.cloze  = data.cloze;
        modes.pick   = data.pick;
        modes.listen = data.listen;
        row.outerHTML = uebungRow('cloze', data.cloze);
        const einsetzen = document.querySelector('[data-mode-row="pick"]');
        if (einsetzen) einsetzen.outerHTML = uebungRow('pick', data.pick);
        const hoeren = document.querySelector('[data-mode-row="listen"]');
        if (hoeren) hoeren.outerHTML = uebungRow('listen', data.listen);
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
    // Hören: Die Sätze sind da, ihre Aufnahmen noch nicht.
    const aufnahmen = info.status === 'audio';
    const kaputt = info.status === 'failed';
    /*
     * Ohne Sätze nicht anklickbar - auch wenn gerade keiner entsteht. Die
     * Zeile führte sonst in "Deine Sätze werden vorbereitet ...", und dort
     * wartete das Kind auf einen Lauf, den es gar nicht gab.
     */
    const ohneSaetze = mode !== 'mc' && info.total === 0;
    const zu     = wartet || fertig || aufnahmen || ohneSaetze;

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
    } else if (aufnahmen) {
        text = 'Die Aufnahmen kommen noch.';
    } else if (fertig) {
        text = `Geschafft - alle ${info.total} gelernt`;
    } else if (kaputt) {
        text = info.error || 'Die Sätze konnten nicht erzeugt werden.';
    } else if (ohneSaetze) {
        text = 'Noch keine Sätze - deine Lehrkraft kümmert sich darum.';
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
 * Die Kopfzeile der Vokabelliste hängt beim Rollen unter der Leiste oben.
 *
 * Wo die Leiste endet, hängt davon ab, ob der Streifen "Lernansicht" über
 * ihr steht und wie hoch der Sicherheitsabstand des Geräts ist - also
 * gemessen statt geschätzt: ihr eigenes top (sticky) plus ihre Höhe. Neu
 * bei jeder Größenänderung, weil der Titel darin umbrechen kann.
 */
function kopfUnterLeiste() {
    const leiste = document.querySelector('.topbar');
    const liste  = document.querySelector('.vocabliste');
    if (!leiste || !liste) return;
    const messen = () => {
        const oben = parseFloat(getComputedStyle(leiste).top) || 0;
        liste.style.setProperty('--leiste', `${Math.round(oben + leiste.offsetHeight)}px`);
    };
    messen();
    new ResizeObserver(messen).observe(leiste);
}

/**
 * Der Stand einer Vokabel in einer Übungsart als Ring aus drei Teilen.
 *
 * Jeder Teil ist ein Treffer in Folge - dasselbe wie die drei Punkte, nur
 * rund und schmal. Gekonnt ist eine volle grüne Scheibe mit Haken; was noch
 * fehlt, springt so ins Auge. Ein Strich heißt: noch kein Lückensatz.
 */
function ring(exercise, info) {
    if (!info.possible) {
        return `<span class="ring aus" title="${esc(exercise.title)}: noch kein Lückensatz"
                      aria-label="${esc(exercise.title)}: noch kein Lückensatz">&ndash;</span>`;
    }
    if (info.known) {
        return `<span class="ring fertig" title="${esc(exercise.title)}: gekonnt"
                      aria-label="${esc(exercise.title)}: gekonnt">\u{2713}</span>`;
    }
    const n = Math.min(3, Math.max(0, info.streak));
    return `<span class="ring" style="--n:${n}" title="${esc(exercise.title)}: ${n} von 3 hintereinander"
                  aria-label="${esc(exercise.title)}: ${n} von 3 hintereinander"></span>`;
}

/**
 * Der Stand einer Vokabel in einer Übungsart: Haken, drei Punkte oder Strich.
 * Steht in der aufgeklappten Zeile, mit dem Namen der Übung davor.
 * Der Strich heißt "noch kein Lückensatz" - etwa weil das Modell für diese
 * Vokabel keinen brauchbaren erzeugen konnte. Im Admin lässt sich nachtragen.
 */
function mark(exercise, info) {
    // Symbol und Stand jeweils in einer Zelle fester Breite: Drei Punkte sind
    // breiter als ein Haken, sonst tanzten die Symbole von Zeile zu Zeile.
    const zelle = (inhalt, titel) => `
        <span class="mark" title="${esc(titel)}">
            <span class="mark-name"><span class="mark-icon">${exercise.icon}</span>
                ${esc(exercise.title)}</span>
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
