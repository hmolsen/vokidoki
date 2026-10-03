/*
 * Hören: ein Satz wird vorgespielt, und das Kind legt ihn aus Wortknöpfen
 * nach.
 *
 * Es sind die Lückensätze, ganz gesprochen (lib/tts.php). Unter der Zeile
 * liegen die Wörter des Satzes und zwei oder drei, die nicht hineingehören.
 * Ein Wort antippen legt es hinten an; ziehen legt es an die Stelle, an der
 * man loslässt. Ein gelegtes Wort antippen nimmt es zurück, ziehen schiebt
 * es um. Mit "Prüfen" zählt die Reihenfolge.
 *
 * Wie bei den anderen Übungen entsteht die Aufgabe im Gerät (frageHoeren()
 * in vorrat.js) und wird dort verbucht - sie zählt für die Serie. Die
 * Aufnahme kommt übers Netz oder, einmal gehört, aus dem Speicher des
 * Service Workers.
 */

import {
    render, esc, $, topbar, wireBack, progressBar, showError,
    babing, serieAktualisieren, punkteAktualisieren, konfetti, feuerwerk,
} from '../core.js';
import {
    frageHoeren, hoerPruefen, antwortMerken, MODUS_HOEREN, einheit, vorratAuffrischen,
    hoerenVorladen,
} from '../vorrat.js';
import { meldeKnopf, meldenVerdrahten } from '../melden.js';
import { weiterKnopf, weiterVerdrahten } from './unit.js';

const NEXT_DELAY_CORRECT = 1600;

/* Ab so vielen Pixeln Bewegung ist ein Druck ein Ziehen und kein Antippen. */
const ZIEH_SCHWELLE = 6;

/* Langsam heisst: dieselbe Aufnahme, langsamer abgespielt - bei gleicher Tonhöhe. */
const LANGSAM = 0.7;

/* Der Bildschirm, solange er steht - wird je Aufgabe nur neu befüllt. */
let zustand = null;

export async function hoerenView(unitId) {
    zustand?.ton?.pause();
    zustand = null;
    // Wer die Übung verlässt, nimmt den Satz nicht mit - sonst spräche er
    // auf der nächsten Seite weiter.
    window.addEventListener('hashchange', () => zustand?.ton?.pause(), { once: true });
    naechste(unitId);
    // Wer direkt hierher kommt, ohne die Lerneinheit: die übrigen Sätze nachholen.
    hoerenVorladen(unitId);
}

function naechste(unitId) {
    const data = frageHoeren(unitId);

    if (data === null) {
        zustand = null;
        render(`${topbar('Hören', { backTo: `/unit/${unitId}` })}<div id="msg"></div>`);
        wireBack();
        showError('Die Vokabeln sind noch nicht da. Einmal mit Netz öffnen, dann geht es auch ohne.');
        return;
    }

    // Erst entstehen die Sätze, dann ihre Aufnahmen - so lange wird gewartet.
    if (data.leer && (einheit(unitId)?.z ?? '') === 'running') {
        wartebild(unitId, 'Deine Sätze werden vorbereitet...');
        setTimeout(async () => { await vorratAuffrischen(); naechste(unitId); }, 2500);
        return;
    }

    if (data.leer) {
        zustand = null;
        render(`
            ${topbar('Hören', { backTo: `/unit/${unitId}` })}
            <div class="empty">
                <span class="big">\u{1F3A7}</span>
                Für diese Lektion gibt es noch keine Aufnahmen.<br>
                Sie kommen, sobald die Sätze gesprochen sind.
            </div>`);
        wireBack();
        return;
    }

    if (data.done) {
        zustand = null;
        geschafft(unitId, data);
        return;
    }

    if (zustand === null || !document.getElementById('satzlinie')) {
        aufbauen(unitId);
    }
    zeigen(data);
}

function wartebild(unitId, text) {
    if (document.getElementById('preparing')) return;
    zustand = null;
    render(`
        ${topbar('Hören', { backTo: `/unit/${unitId}` })}
        <div class="empty" id="preparing" style="padding-top:18vh">
            <div class="spinner"></div>
            <strong>${esc(text)}</strong>
        </div>`);
    wireBack();
}

/** Baut den Bildschirm einmal auf; danach wird nur neu befüllt. */
function aufbauen(unitId) {
    render(`
        <div class="screen">
            <div class="screen-top">
                ${topbar('Hören', {
                    backTo: `/unit/${unitId}`,
                    action: '<div class="topbar-progress" id="progress"></div>',
                })}
            </div>

            <div class="screen-body">
                <div class="screen-mid">
                    <div class="hoerknoepfe">
                        <button class="hoerknopf" type="button" id="abspielen"
                                aria-label="Satz anhören">\u{1F50A}</button>
                        <button class="hoerknopf langsam" type="button" id="langsam"
                                aria-label="Langsam anhören" title="Langsam">\u{1F422}</button>
                    </div>
                    <div class="satzlinie" id="satzlinie" aria-live="polite"
                         aria-label="Deine Reihenfolge"></div>
                    <p class="hoer-native" id="native" hidden></p>
                    <div class="cloze-dots"><span class="dots" id="dots"></span></div>
                    <div class="verdict" id="verdict"></div>
                </div>

                <div class="screen-bottom">
                    <div class="wortwahl hoerwoerter" id="woerter" role="group"
                         aria-label="Wörter für den Satz"></div>
                    <div class="cloze-actions">
                        <button class="btn cloze-check" type="button" id="pruefen" disabled>Prüfen</button>
                        <button class="btn cloze-check" type="button" id="weiter" hidden>Weiter</button>
                        ${meldeKnopf()}
                    </div>
                    <div id="msg"></div>
                </div>
            </div>
        </div>
    `);
    wireBack();

    zustand = { unitId, data: null, gelegt: [], beantwortet: false, weiterGeschaltet: false, ton: null };

    $('#abspielen').addEventListener('click', () => abspielen(1));
    $('#langsam').addEventListener('click', () => abspielen(LANGSAM));
    $('#pruefen').addEventListener('click', () => pruefen());
    $('#weiter').addEventListener('click', () => weiterSchalten());
    ziehenVerdrahten();
}

/** Eine neue Aufgabe in den stehenden Bildschirm. */
function zeigen(data) {
    const z = zustand;
    z.ton?.pause();
    z.data = data;
    z.gelegt = [];
    z.beantwortet = false;
    z.weiterGeschaltet = false;

    z.ton = new Audio(data.audio);
    // Die Adresse steht auch am Knopf - zum Nachsehen, welche Aufnahme dran ist.
    $('#abspielen').dataset.src = data.audio;
    z.ton.preload = 'auto';
    z.ton.preservesPitch = true;
    z.ton.addEventListener('playing', () => tonZeigen(true));
    z.ton.addEventListener('pause', () => tonZeigen(false));
    z.ton.addEventListener('ended', () => tonZeigen(false));
    z.ton.addEventListener('error', () => {
        tonZeigen(false);
        showError('Die Aufnahme ist gerade nicht da. Mit Netz geht es.');
    });

    $('#woerter').innerHTML = data.knoepfe.map((k) => `
        <button type="button" class="wort" data-k="${k.i}"
                ${data.lang ? `lang="${esc(data.lang)}"` : ''}>${esc(k.w)}</button>`).join('');

    $('#native').hidden = true;
    $('#native').textContent = data.native;
    $('#weiter').hidden = true;
    $('#pruefen').hidden = false;
    const verdict = $('#verdict');
    verdict.className = 'verdict';
    verdict.textContent = '';

    $('#dots').innerHTML = [0, 1, 2]
        .map((i) => `<i class="${i < Math.min(3, data.streak) ? 'on' : ''}"></i>`).join('');

    const fortschritt = $('#progress');
    fortschritt.innerHTML = `
        ${progressBar(data.known, data.total)}
        <span class="tiny muted">${data.known}/${data.total}</span>`;
    fortschritt.title = `${data.known} von ${data.total} gelernt`;

    meldenVerdrahten(document.querySelector('[data-melden]'), () => ({
        vocabId: data.vocabId,
        satzId:  data.satzId,
        getippt: gelegteWoerter().join(' '),
    }));

    linieZeichnen();
    // Gleich vorspielen - die Übung heisst Hören. Ein Browser, der das ohne
    // Fingertipp nicht erlaubt, lehnt ab; dann ist der Knopf da.
    abspielen(1);
}

function abspielen(tempo) {
    const ton = zustand?.ton;
    if (!ton) return;
    ton.pause();
    ton.currentTime = 0;
    ton.playbackRate = tempo;
    ton.play().catch(() => tonZeigen(false));
}

function tonZeigen(laeuft) {
    $('#abspielen')?.classList.toggle('laeuft', laeuft && zustand?.ton?.playbackRate === 1);
    $('#langsam')?.classList.toggle('laeuft', laeuft && zustand?.ton?.playbackRate !== 1);
}

const gelegteWoerter = () => zustand.gelegt.map((k) => zustand.data.knoepfe[k].w);

/** Die gelegten Wörter neu zeichnen, und unten die benutzten ausblenden. */
function linieZeichnen() {
    const z = zustand;
    const lang = z.data.lang ? ` lang="${esc(z.data.lang)}"` : '';
    $('#satzlinie').innerHTML = z.gelegt.map((k, pos) => `
        <button type="button" class="wort gelegt" data-k="${k}" data-pos="${pos}"${lang}
                ${z.beantwortet ? 'disabled' : ''}>${esc(z.data.knoepfe[k].w)}</button>`).join('');
    document.querySelectorAll('#woerter .wort').forEach((b) => {
        b.classList.toggle('benutzt', z.gelegt.includes(Number(b.dataset.k)));
        b.disabled = z.beantwortet;
    });
    $('#pruefen').disabled = z.beantwortet || z.gelegt.length === 0;
}

/** Ein Wort an die Stelle legen (oder umlegen). */
function legen(k, stelle = null) {
    const z = zustand;
    if (!z || z.beantwortet) return;
    const alt = z.gelegt.indexOf(k);
    if (alt !== -1) {
        z.gelegt.splice(alt, 1);
        if (stelle !== null && stelle > alt) stelle--;
    }
    z.gelegt.splice(stelle ?? z.gelegt.length, 0, k);
    linieZeichnen();
}

/** Ein gelegtes Wort zurück nach unten. */
function zuruecknehmen(k) {
    const z = zustand;
    if (!z || z.beantwortet) return;
    z.gelegt = z.gelegt.filter((g) => g !== k);
    linieZeichnen();
}

function pruefen() {
    const z = zustand;
    if (!z || z.beantwortet || z.gelegt.length === 0) return;
    z.beantwortet = true;

    const richtig = hoerPruefen(gelegteWoerter(), z.data.woerter);
    linieZeichnen();
    document.querySelectorAll('#satzlinie .wort').forEach((b) => b.classList.add(richtig ? 'good' : 'bad'));
    $('#pruefen').hidden = true;
    $('#native').hidden = false;

    const result  = antwortMerken(z.data.vocabId, MODUS_HOEREN, richtig);
    const verdict = $('#verdict');

    if (richtig) {
        babing();
        punkteAktualisieren(document, result.streak);
        if (result.newly_learned) konfetti();
        serieAktualisieren(result.tag_geschafft);
        verdict.className = 'verdict good';
        verdict.textContent = result.just_learned ? 'Diese Vokabel kannst du jetzt.' : 'Richtig!';
        setTimeout(weiterSchalten, NEXT_DELAY_CORRECT);
    } else {
        punkteAktualisieren(document, 0);
        // Wie beim Einsetzen: kein Zeitablauf. Der richtige Satz steht da,
        // das Kind kann ihn noch einmal hören und geht selbst weiter.
        verdict.className = 'verdict bad';
        verdict.innerHTML = `Nicht ganz. Richtig ist:<br><strong${
            z.data.lang ? ` lang="${esc(z.data.lang)}"` : ''}>${esc(z.data.text)}</strong>`;
        const weiter = $('#weiter');
        weiter.hidden = false;
        weiter.focus();
    }
}

function weiterSchalten() {
    const z = zustand;
    if (!z || z.weiterGeschaltet) return;
    z.weiterGeschaltet = true;
    naechste(z.unitId);
}

/**
 * Antippen und Ziehen - mit Pointer-Events, wie beim Einsetzen (dort steht,
 * warum nicht "draggable"). Unten antippen legt hinten an, oben antippen
 * nimmt zurück. Gezogen zählt, wo losgelassen wird: über der Zeile an der
 * Stelle zwischen den Wörtern, die am nächsten liegt; ein gelegtes Wort
 * neben die Zeile gezogen geht zurück.
 */
function ziehenVerdrahten() {
    const bildschirm = document.querySelector('.screen-body');
    let zug = null;
    let schluckenBis = 0;

    const ueberLinie = (x, y) => {
        const r = $('#satzlinie')?.getBoundingClientRect();
        return !!r && x >= r.left - 16 && x <= r.right + 16 && y >= r.top - 24 && y <= r.bottom + 24;
    };

    /* Die Stelle zwischen den gelegten Wörtern, die dem Finger am nächsten liegt. */
    const stelleBei = (x, y, ohne) => {
        const woerter = [...document.querySelectorAll('#satzlinie .wort')]
            .filter((b) => Number(b.dataset.k) !== ohne);
        let stelle = woerter.length;
        let best = Infinity;
        woerter.forEach((b, i) => {
            const r = b.getBoundingClientRect();
            for (const [px, wo] of [[r.left, i], [r.right, i + 1]]) {
                const d = Math.hypot(px - x, (r.top + r.bottom) / 2 - y);
                if (d < best) { best = d; stelle = wo; }
            }
        });
        // In Positionen der vollen Liste umrechnen (ohne das gezogene Wort gezählt).
        if (ohne !== null) {
            const alt = zustand.gelegt.indexOf(ohne);
            if (alt !== -1 && stelle >= alt) stelle++;
        }
        return stelle;
    };

    const markieren = (x, y, ohne) => {
        document.querySelectorAll('#satzlinie .einfueger').forEach((m) => m.remove());
        $('#satzlinie')?.classList.toggle('ueber', ueberLinie(x, y));
        if (!ueberLinie(x, y)) return;
        const woerter = [...document.querySelectorAll('#satzlinie .wort')]
            .filter((b) => Number(b.dataset.k) !== ohne);
        let stelle = stelleBei(x, y, ohne);
        if (ohne !== null) {
            const alt = zustand.gelegt.indexOf(ohne);
            if (alt !== -1 && stelle > alt) stelle--;
        }
        const marke = document.createElement('span');
        marke.className = 'einfueger';
        if (stelle < woerter.length) woerter[stelle].before(marke);
        else $('#satzlinie').append(marke);
    };

    bildschirm.addEventListener('pointerdown', (e) => {
        const knopf = e.target.closest('#woerter .wort, #satzlinie .wort');
        if (!knopf || knopf.disabled || zustand?.beantwortet) return;
        zug = { knopf, k: Number(knopf.dataset.k), oben: knopf.classList.contains('gelegt'),
                id: e.pointerId, x: e.clientX, y: e.clientY, geist: null };
        knopf.setPointerCapture?.(e.pointerId);
    });

    bildschirm.addEventListener('pointermove', (e) => {
        if (!zug || e.pointerId !== zug.id) return;
        const dx = e.clientX - zug.x;
        const dy = e.clientY - zug.y;

        if (!zug.geist) {
            if (Math.hypot(dx, dy) < ZIEH_SCHWELLE) return;
            const r = zug.knopf.getBoundingClientRect();
            zug.geist = zug.knopf.cloneNode(true);
            zug.geist.className = 'wort wort-geist';
            zug.geist.style.width = `${r.width}px`;
            zug.geist.style.left  = `${r.left}px`;
            zug.geist.style.top   = `${r.top}px`;
            document.body.append(zug.geist);
            zug.knopf.classList.add('gezogen');
        }

        zug.geist.style.transform = `translate(${dx}px, ${dy}px)`;
        markieren(e.clientX, e.clientY, zug.oben ? zug.k : null);
    });

    const loslassen = (e, abgebrochen) => {
        if (!zug || e.pointerId !== zug.id) return;
        const { knopf, geist, k, oben } = zug;
        zug = null;
        document.querySelectorAll('#satzlinie .einfueger').forEach((m) => m.remove());
        $('#satzlinie')?.classList.remove('ueber');

        if (!geist) return;           // nur angetippt - das erledigt der Klick
        schluckenBis = performance.now() + 150;
        geist.remove();
        knopf.classList.remove('gezogen');
        if (abgebrochen) return;

        if (ueberLinie(e.clientX, e.clientY)) {
            legen(k, stelleBei(e.clientX, e.clientY, oben ? k : null));
        } else if (oben) {
            zuruecknehmen(k);
        }
    };
    bildschirm.addEventListener('pointerup', (e) => loslassen(e, false));
    bildschirm.addEventListener('pointercancel', (e) => loslassen(e, true));

    bildschirm.addEventListener('click', (e) => {
        if (performance.now() < schluckenBis) return;
        const knopf = e.target.closest('#woerter .wort, #satzlinie .wort');
        if (!knopf || knopf.disabled) return;
        if (knopf.classList.contains('gelegt')) zuruecknehmen(Number(knopf.dataset.k));
        else legen(Number(knopf.dataset.k));
    });
}

function geschafft(unitId, data) {
    render(`
        ${topbar('Geschafft', { backTo: `/unit/${unitId}` })}
        <div class="celebrate">
            <span class="big">\u{1F389}</span>
            <h1>Hören geschafft!</h1>
            <p class="sub">Du hast alle ${data.total} Sätze richtig nachgelegt.</p>
        </div>
        <div id="msg"></div>
        ${weiterKnopf(unitId, MODUS_HOEREN)}
        <button class="btn ghost" data-back="/unit/${unitId}">Zur Übersicht</button>
    `);
    // Erst zeichnen, dann anzünden - render() macht ein laufendes Feuerwerk aus.
    feuerwerk();
    wireBack();
    weiterVerdrahten();
}
