/*
 * Einsetzen: ein Lückensatz, und das fehlende Wort wird gewählt statt getippt.
 *
 * Dieselben Sätze wie im Lückentext, aber unter dem Satz stehen drei Wörter
 * derselben Wortart. Das Kind tippt eines an oder zieht es in die Lücke.
 * Gedacht als Stufe zwischen Auswählen (die Bedeutung erkennen) und
 * Lückentext (das Wort selbst schreiben): hier geht es um das Wort im Satz,
 * ohne dass die Rechtschreibung schon im Weg steht.
 *
 * Die Aufgabe entsteht im Gerät (frageEinsetzen() in vorrat.js), die
 * Antwort wird dort verbucht - wie bei den anderen beiden Übungen, und
 * damit zählt sie auch für die Serie.
 *
 * Neben den Vokabeln in der Lerneinheit steht zu dieser Übung (noch) kein
 * Zeichen: Dort ist nur Platz für zwei. Das kommt, wenn die Liste neu
 * gezeichnet wird.
 */

import {
    render, esc, $, topbar, wireBack, progressBar, showError,
    babing, serieAktualisieren, punkteAktualisieren, konfetti, feuerwerk,
} from '../core.js';
import {
    frageEinsetzen, antwortMerken, MODUS_EINSETZEN, einheit, vorratAuffrischen,
} from '../vorrat.js';
import { meldeKnopf, meldenVerdrahten } from '../melden.js';
import { weiterKnopf, weiterVerdrahten } from './unit.js';

const NEXT_DELAY_CORRECT = 900;
const GAP = '{}';

/* Ab so vielen Pixeln Bewegung ist ein Druck ein Ziehen und kein Antippen. */
const ZIEH_SCHWELLE = 6;

/* Der Bildschirm, solange er steht - wird je Aufgabe nur neu befüllt. */
let zustand = null;

export async function einsetzenView(unitId) {
    zustand = null;
    render(`
        ${topbar('Einsetzen', { backTo: `/unit/${unitId}` })}
        <div id="msg"></div>
        <div class="empty" style="padding-top:16vh">
            <div class="spinner"></div>
            <strong>Einen Moment...</strong>
        </div>
    `);
    wireBack();
    naechste(unitId);
}

function naechste(unitId) {
    const data = frageEinsetzen(unitId);

    if (data === null) {
        zustand = null;
        render(`${topbar('Einsetzen', { backTo: `/unit/${unitId}` })}<div id="msg"></div>`);
        wireBack();
        showError('Die Vokabeln sind noch nicht da. Einmal mit Netz öffnen, dann geht es auch ohne.');
        return;
    }

    // Die Sätze entstehen noch - dieselben wie beim Lückentext.
    if (data.leer && (einheit(unitId)?.z ?? '') === 'running') {
        wartebild(unitId);
        setTimeout(async () => { await vorratAuffrischen(); naechste(unitId); }, 2500);
        return;
    }

    if (data.leer) {
        zustand = null;
        render(`
            ${topbar('Einsetzen', { backTo: `/unit/${unitId}` })}
            <div class="empty">
                <span class="big">\u{23F3}</span>
                Für diese Lektion gibt es noch keine Lückensätze.<br>
                Deine Lehrkraft holt das nach.
            </div>`);
        wireBack();
        return;
    }

    if (data.done) {
        zustand = null;
        geschafft(unitId, data);
        return;
    }

    if (zustand === null || !document.getElementById('luecke')) {
        aufbauen(unitId);
    }
    zeigen(data);
}

function wartebild(unitId) {
    if (document.getElementById('preparing')) return;
    zustand = null;
    render(`
        ${topbar('Einsetzen', { backTo: `/unit/${unitId}` })}
        <div class="empty" id="preparing" style="padding-top:18vh">
            <div class="spinner"></div>
            <strong>Deine Sätze werden vorbereitet...</strong>
        </div>`);
    wireBack();
}

/** Baut den Bildschirm einmal auf; danach werden nur Texte getauscht. */
function aufbauen(unitId) {
    render(`
        <div class="screen">
            <div class="screen-top">
                ${topbar('Einsetzen', {
                    backTo: `/unit/${unitId}`,
                    action: '<div class="topbar-progress" id="progress"></div>',
                })}
            </div>

            <div class="screen-body">
                <div class="screen-mid">
                    <p class="cloze-native" id="native"></p>
                    <p class="cloze-foreign">
                        <span id="gap-before"></span
                        ><span class="luecke" id="luecke" aria-live="polite"></span
                        ><span id="gap-after"></span>
                    </p>
                    <div class="cloze-dots"><span class="dots" id="dots"></span></div>
                    <div class="verdict" id="verdict"></div>
                </div>

                <div class="screen-bottom">
                    <div class="wortwahl" id="woerter" role="group"
                         aria-label="Welches Wort gehört in die Lücke?"></div>
                    <div class="cloze-actions">
                        <button class="btn cloze-check" type="button" id="weiter" hidden>Weiter</button>
                        ${meldeKnopf()}
                    </div>
                    <div id="msg"></div>
                </div>
            </div>
        </div>
    `);
    wireBack();

    zustand = { unitId, data: null, beantwortet: false, weiterGeschaltet: false };

    $('#weiter').addEventListener('click', () => weiterSchalten());
    ziehenVerdrahten();
}

/** Eine neue Aufgabe in den stehenden Bildschirm. */
function zeigen(data) {
    zustand.data = data;
    zustand.beantwortet = false;
    zustand.weiterGeschaltet = false;
    zustand.gewaehlt = '';

    $('#native').textContent = data.native;
    const [vor, nach] = String(data.foreign).split(GAP);
    $('#gap-before').textContent = vor ?? '';
    $('#gap-after').textContent  = nach ?? '';

    const luecke = $('#luecke');
    luecke.textContent = '';
    luecke.className = 'luecke';
    if (data.lang) luecke.lang = data.lang;

    $('#woerter').innerHTML = data.optionen.map((wort, i) => `
        <button type="button" class="wort" data-i="${i}"
                ${data.lang ? `lang="${esc(data.lang)}"` : ''}>${esc(wort)}</button>`).join('');

    $('#weiter').hidden = true;
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
        getippt: zustand?.gewaehlt ?? '',
    }));
}

/** Ein Wort ist gewählt - angetippt oder in die Lücke gezogen. */
function waehlen(knopf) {
    const z = zustand;
    if (!z || z.beantwortet || !knopf) return;
    z.beantwortet = true;

    const wahl    = Number(knopf.dataset.i);
    const richtig = wahl === z.data.richtig;
    z.gewaehlt    = z.data.optionen[wahl] ?? '';

    const luecke = $('#luecke');
    luecke.textContent = z.gewaehlt;
    luecke.classList.add(richtig ? 'correct' : 'wrong');

    document.querySelectorAll('#woerter .wort').forEach((k) => {
        k.disabled = true;
        if (Number(k.dataset.i) === z.data.richtig) k.classList.add('good');
    });
    if (!richtig) knopf.classList.add('bad');

    const result  = antwortMerken(z.data.vocabId, MODUS_EINSETZEN, richtig);
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
        /*
         * Falsch: kein Zeitablauf, wie im Lückentext. Das richtige Wort
         * leuchtet unten grün, und das Kind geht selbst weiter, wenn es
         * es gelesen hat.
         */
        verdict.className = 'verdict bad';
        verdict.innerHTML = `Nicht ganz. Richtig ist:<br><strong>${esc(z.data.loesung)}</strong>`;
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
 * Antippen oder in die Lücke ziehen - mit Pointer-Events.
 *
 * Das Ziehen von HTML ("draggable") kennt Safari auf dem iPhone nicht, und
 * dort sitzen die Kinder. Pointer-Events gehen mit Finger und Maus gleich:
 * Wer drückt und kaum bewegt, hat angetippt; wer weiter als ein paar Pixel
 * zieht, zieht - ein Abbild des Wortes folgt dem Finger, und losgelassen
 * über der Lücke zählt es als Wahl. Daneben losgelassen passiert nichts.
 *
 * Die Tastatur geht über den gewöhnlichen Klick (Enter, Leertaste); ein
 * Ziehen schluckt den Klick, der danach vom Browser noch kommt - aber nur
 * einen Augenblick lang. Als Schalter, der bis zum nächsten Klick stand,
 * schluckte er nach einem Ziehen daneben (dann kommt gar kein Klick) den
 * nächsten echten: Das Kind tippte ein Wort an, und nichts geschah.
 */
function ziehenVerdrahten() {
    const woerter = $('#woerter');
    let zug = null;
    let schluckenBis = 0;

    const ueberLuecke = (x, y) => {
        const luecke = $('#luecke');
        if (!luecke) return false;
        const r = luecke.getBoundingClientRect();
        // Etwas Spielraum um die Lücke: Ein Finger trifft keine Strichstärke.
        return x >= r.left - 24 && x <= r.right + 24 && y >= r.top - 24 && y <= r.bottom + 24;
    };

    woerter.addEventListener('pointerdown', (e) => {
        const knopf = e.target.closest('.wort');
        if (!knopf || knopf.disabled || zustand?.beantwortet) return;
        zug = { knopf, id: e.pointerId, x: e.clientX, y: e.clientY, geist: null };
        knopf.setPointerCapture?.(e.pointerId);
    });

    woerter.addEventListener('pointermove', (e) => {
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
            zug.start = { left: r.left, top: r.top };
            document.body.append(zug.geist);
            zug.knopf.classList.add('gezogen');
        }

        zug.geist.style.transform = `translate(${dx}px, ${dy}px)`;
        $('#luecke')?.classList.toggle('ueber', ueberLuecke(e.clientX, e.clientY));
    });

    const loslassen = (e, abgebrochen) => {
        if (!zug || e.pointerId !== zug.id) return;
        const { knopf, geist } = zug;
        zug = null;

        if (!geist) return;           // nur angetippt - das erledigt der Klick
        schluckenBis = performance.now() + 150;
        geist.remove();
        knopf.classList.remove('gezogen');
        const luecke = $('#luecke');
        const treffer = !abgebrochen && ueberLuecke(e.clientX, e.clientY);
        luecke?.classList.remove('ueber');
        if (treffer) waehlen(knopf);
    };
    woerter.addEventListener('pointerup', (e) => loslassen(e, false));
    woerter.addEventListener('pointercancel', (e) => loslassen(e, true));

    woerter.addEventListener('click', (e) => {
        if (performance.now() < schluckenBis) return;
        waehlen(e.target.closest('.wort'));
    });
}

function geschafft(unitId, data) {
    render(`
        ${topbar('Geschafft', { backTo: `/unit/${unitId}` })}
        <div class="celebrate">
            <span class="big">\u{1F389}</span>
            <h1>Einsetzen geschafft!</h1>
            <p class="sub">Du hast alle ${data.total} Vokabeln richtig eingesetzt.</p>
        </div>
        <div id="msg"></div>
        ${weiterKnopf(unitId, MODUS_EINSETZEN)}
        <button class="btn ghost" data-back="/unit/${unitId}">Zur Übersicht</button>
    `);
    // Erst zeichnen, dann anzünden - render() macht ein laufendes Feuerwerk aus.
    feuerwerk();
    wireBack();
    weiterVerdrahten();
}
