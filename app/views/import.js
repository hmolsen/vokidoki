import {
    VT, api, render, esc, $, $$, go, topbar, wireBack, showError, clearError, withBusy,
    teacherBack,
} from '../core.js';

import { MAX_IMAGES, shrinkToBase64 } from './bilder.js';
import { vokiLiest } from '../lesevoki.js';
import { texterkennung } from '../ocr.js';

const draftKey = (languageId) => `vt-draft-${languageId}`;

/*
 * In welchen Kurs das hier faellt.
 *
 * Wichtig fuer alle, die per Link oder QR-Code direkt hier landen, ohne
 * vorher die Kursliste gesehen zu haben - "Vokabeln einlesen" allein sagt
 * nicht, wessen Vokabeln. Der Name wird einmal geholt und gemerkt; kommt er
 * nicht, wird eben nichts angezeigt, und das Einlesen geht trotzdem.
 */
let kursName = null;

/* Die vorhandenen Lerneinheiten - Ziele zum Anhaengen. */
let einheiten = [];

/* Das Sprachkürzel - es wählt die Sprachdatei der Texterkennung. */
let sprachCode = '';

async function kursHolen(languageId) {
    if (kursName !== null) return kursName;
    try {
        const { language, units } = await api('units', 'list', { query: { language_id: languageId, einlesen: 1 } });
        kursName  = language?.course || language?.name || '';
        einheiten = Array.isArray(units) ? units : [];
        sprachCode = language?.code || '';
    } catch {
        kursName  = '';
        einheiten = [];
    }
    return kursName;
}

export async function importView(languageId) {
    kursName  = null;
    einheiten = [];

    const draft = loadDraft(languageId);

    if (draft) {
        /*
         * Hier wird gewartet, und zwar mit Absicht.
         *
         * Der Pruefschritt enthaelt die Wahl "neue Lerneinheit oder an eine
         * vorhandene anhaengen", und die braucht die Liste der Einheiten.
         * Wird sie nachgereicht, ist die Wahl im Moment des Zeichnens noch
         * leer - und dann steht sie gar nicht da.
         */
        await kursHolen(languageId);
        showReview(languageId, draft.title, draft.entries, true);
        return;
    }

    showCapture(languageId);

    // Beim Fotografieren nachgereicht: Dort haengt nur die Ueberschrift
    // daran, und die Kamera soll nicht auf eine Abfrage warten.
    const name = await kursHolen(languageId);
    const ziel = $('#kurs');
    if (ziel && name) ziel.textContent = name;
}

// ------------------------------------------------------------------ Schritt 1: Fotos

/**
 * @param images  bereits gewählte Fotos - nach einem Fehlschlag bleiben sie
 *                erhalten, damit niemand alles neu fotografieren muss.
 */
function showCapture(languageId, images = []) {
    render(`
        ${topbar('Vokabeln einlesen', { backTo: `/lang/${languageId}` })}
        <p class="kurszeile">
            <span>Kurs: <strong id="kurs">${esc(kursName || '...')}</strong></span>
            ${teacherBack()}
        </p>
        <div id="msg"></div>

        <p class="sub">
            Fotografiere die Vokabelseite aus deinem Buch. Mehrere Fotos gehören
            zu einer Lerneinheit - bis zu ${MAX_IMAGES} Stück.
        </p>

        <div class="thumbs" id="thumbs"></div>

        <div class="btn-row" style="margin-bottom:10px">
            <button class="btn secondary" id="camera">\u{1F4F7} Foto aufnehmen</button>
            <button class="btn secondary" id="album">\u{1F5BC} Aus Album</button>
        </div>

        <button class="btn" id="analyze" disabled>Vokabeln erkennen</button>

        <input type="file" id="fileCamera" accept="image/*" capture="environment" multiple hidden>
        <input type="file" id="fileAlbum"  accept="image/*" multiple hidden>
    `);

    wireBack();

    const thumbs = $('#thumbs');

    const refresh = () => {
        thumbs.innerHTML = images.map((img, i) => `
            <div class="thumb">
                <img src="data:image/jpeg;base64,${img.data}" alt="Foto ${i + 1}">
                <button data-remove="${i}" aria-label="Foto entfernen">&times;</button>
            </div>
        `).join('');
        $('#analyze').disabled = images.length === 0;
    };

    // Delegation statt Handler pro Miniatur - so bleibt nach jedem Neuzeichnen
    // genau ein Listener aktiv.
    thumbs.addEventListener('click', (event) => {
        const button = event.target.closest('[data-remove]');
        if (!button) return;
        images.splice(Number(button.dataset.remove), 1);
        refresh();
    });

    const handleFiles = async (event) => {
        clearError();
        const files = Array.from(event.target.files || []);
        event.target.value = '';   // dieselbe Datei soll erneut wählbar bleiben

        for (const file of files) {
            if (images.length >= MAX_IMAGES) {
                showError(`Mehr als ${MAX_IMAGES} Fotos gehen nicht auf einmal.`);
                break;
            }
            try {
                images.push(await shrinkToBase64(file));
            } catch {
                showError(`"${file.name}" konnte nicht gelesen werden.`);
            }
        }
        refresh();
    };

    $('#fileCamera').addEventListener('change', handleFiles);
    $('#fileAlbum').addEventListener('change', handleFiles);
    $('#camera').addEventListener('click', () => $('#fileCamera').click());
    $('#album').addEventListener('click', () => $('#fileAlbum').click());

    $('#analyze').addEventListener('click', async () => {
        clearError();
        const decke = vokiLiest('Voki liest die Vokabeln …');
        try {
            // Sicherstellen, dass die Liste da ist - der Pruefschritt baut
            // seine Wahl daraus, und die Texterkennung braucht das Kürzel.
            await kursHolen(languageId);

            /*
             * Gelesen wird hier, auf dem Gerät (ocr.js) - die Fotos
             * verlassen es nicht. Zum Server geht nur der erkannte Text.
             */
            const text = await texterkennung(
                images.map((i) => `data:${i.media_type};base64,${i.data}`),
                sprachCode,
                {
                    fortschritt: (seite, alle, anteil) => decke.text(
                        alle > 1 ? `Voki liest Foto ${seite} von ${alle} …` : 'Voki liest das Foto …',
                        `${Math.round(anteil * 100)} % - die Fotos bleiben auf diesem Gerät.`,
                    ),
                },
            );

            decke.text('Voki sortiert die Vokabeln …',
                       'Die KI ordnet den erkannten Text und berichtigt Lesefehler.');
            const data = await api('import', 'analyze', {
                body: { language_id: Number(languageId), text, pages: images.length },
            });

            // Einen Titel liefert das Einlesen nicht mehr - er wird eingetippt.
            saveDraft(languageId, '', data.entries);
            decke.weg();
            showReview(languageId, '', data.entries, false);
        } catch (err) {
            decke.weg();
            // Fotos bewusst weiterreichen - sie noch einmal zu machen wäre ärgerlich.
            showCapture(languageId, images);
            showError(err.message);
        }
    });

    refresh();
}

// ------------------------------------------------------------------ Schritt 2: Prüfen

function showReview(languageId, title, entries, fromDraft) {
    const rows = entries.map((e, i) => pairRow(e, i)).join('');

    render(`
        ${topbar('Stimmt das so?', { backTo: `/lang/${languageId}` })}
        <p class="kurszeile">
            <span>Kurs: <strong id="kurs">${esc(kursName || '...')}</strong></span>
            ${teacherBack()}
        </p>
        <div id="msg"></div>

        ${fromDraft ? '<div class="notice info">Deine letzte Eingabe wurde wiederhergestellt.</div>' : ''}

        <p class="sub">
            Schau kurz drüber und verbessere, was nicht stimmt.
            Gespeichert wird erst, wenn du unten tippst.
        </p>

        <div class="card">
            ${zielWahl(entries)}
            <div id="neueEinheit">
                <label for="title">Titel der Lerneinheit</label>
                <input type="text" id="title" maxlength="128"
                       placeholder="z. B. Unit 1" value="${esc(title || '')}">
                <p class="tiny muted" style="margin:-6px 0 0">Meist steht er als Überschrift im Buch, zum Beispiel „Unit 4“.</p>
            </div>
        </div>

        <div class="pairs-head">
            <span>Fremdsprache</span><span>Deutsch</span><span></span>
        </div>
        <div class="pairs" id="pairs">${rows}</div>

        <button class="btn secondary small" id="addRow" style="width:100%;margin-bottom:16px">
            + Zeile hinzufügen
        </button>

        <button class="btn" id="save">Lerneinheit speichern</button>
        <button class="btn ghost" id="discard">Verwerfen und neu fotografieren</button>
    `);

    wireBack();

    const pairs  = $('#pairs');
    // Das Titelfeld kann versteckt sein (beim Anhaengen) - dann steht im
    // Entwurf eben nichts. Nur da sein muss es, sonst wirft der Zugriff.
    const persist = () => saveDraft(languageId, $('#title')?.value ?? '', collectAll());

    // Eine Delegation für Löschen und Tippen, statt Listener pro Zeile.
    pairs.addEventListener('click', (event) => {
        const button = event.target.closest('[data-del]');
        if (!button) return;
        button.closest('.pair')?.remove();
        persist();
    });
    /*
     * Wer eine von der KI berichtigte Zeile anfasst, hat sie geprüft -
     * die Markierung geht weg und reist auch nicht mit in die Tabelle.
     */
    pairs.addEventListener('input', (event) => {
        const zeile = event.target.closest('.pair');
        if (zeile?.dataset.korrektur) {
            delete zeile.dataset.korrektur;
            zeile.classList.remove('pruefen');
            zeile.querySelector('.pruefnotiz')?.remove();
        }
        persist();
    });
    $('#title')?.addEventListener('input', persist);

    $('#addRow').addEventListener('click', () => {
        pairs.insertAdjacentHTML('beforeend', pairRow({ foreign: '', native: '' }, $$('.pair').length));
        $$('.pair').at(-1)?.querySelector('input')?.focus();
    });

    $('#discard').addEventListener('click', () => {
        if (!confirm('Diese erkannten Vokabeln verwerfen?')) return;
        clearDraft(languageId);
        showCapture(languageId);
    });

    /*
     * Beim Anhaengen braucht es keinen Titel - die Einheit hat schon einen.
     * Das Feld verschwindet dann, damit niemand etwas eintippt, was
     * hinterher nirgends steht.
     */
    const ziel = $('#ziel');
    const umschalten = () => {
        const anhaengen = ziel && ziel.value !== '';
        const feld = $('#neueEinheit');
        if (feld) feld.hidden = anhaengen;
        const hinweis = $('#zielhinweis');
        if (hinweis) hinweis.hidden = !anhaengen;
        $('#save').textContent = anhaengen
            ? 'Vokabeln anhängen'
            : 'Lerneinheit speichern';
    };
    if (ziel) {
        ziel.addEventListener('change', umschalten);
        umschalten();
    }

    $('#save').addEventListener('click', async (event) => {
        clearError();

        const anId      = ziel && ziel.value !== '' ? Number(ziel.value) : 0;
        const unitTitle = $('#title') ? $('#title').value.trim() : '';

        if (anId === 0 && !unitTitle) {
            showError('Bitte gib der Lerneinheit einen Titel.');
            $('#title')?.focus();
            return;
        }
        const collected = collectComplete();
        if (collected.length === 0) {
            showError('Es ist keine vollständige Vokabel übrig.');
            return;
        }

        try {
            await withBusy(event.currentTarget,
                           anId > 0 ? 'Wird angehängt...' : 'Wird gespeichert...', async () => {
                const body = { language_id: Number(languageId), entries: collected };
                if (anId > 0) body.unit_id = anId;
                else body.title = unitTitle;

                const data = await api('import', 'save', { body });
                clearDraft(languageId);
                /*
                 * Und danach dorthin, wo es weitergeht.
                 *
                 * Fuer ein Kind ist das die Lerneinheit in der App - es hat
                 * gerade seine eigenen Vokabeln eingelesen und will ueben.
                 * Fuer eine Lehrkraft ist es die Freigabe: Eingelesen ist
                 * noch nicht aufgemacht, und der naechste Griff ist immer
                 * derselbe. Sie in der Lernansicht abzusetzen hiess,
                 * ihr eine leere Liste zu zeigen - freigegeben ist ja noch
                 * nichts.
                 */
                if (VT.user?.isTeacher) {
                    window.location.href = `${VT.base}/teacher/unit.php?id=${data.unit_id}`;
                    await new Promise((r) => setTimeout(r, 4000));
                    return;
                }
                go(`/unit/${data.unit_id}`);
            });
        } catch (err) {
            showError(err.message);
        }
    });
}

/**
 * Neue Lerneinheit oder eine vorhandene erweitern?
 *
 * Die Wahl steht erst hier, nach dem Fotografieren: Vorher weiss man noch
 * nicht, ob die Seite zur letzten Lektion gehoert oder eine neue anfaengt.
 * Ohne vorhandene Einheiten gibt es nichts zu waehlen - dann bleibt es beim
 * Titelfeld allein.
 */
function zielWahl() {
    if (einheiten.length === 0) return '';

    const optionen = einheiten.map((u) => `
        <option value="${u.id}">${esc(u.title)} (${u.total} Vokabeln)</option>
    `).join('');

    return `
        <label for="ziel">Wohin?</label>
        <select id="ziel">
            <option value="">Neue Lerneinheit</option>
            <optgroup label="An eine vorhandene anhängen">${optionen}</optgroup>
        </select>
        <p class="tiny muted" id="zielhinweis" style="margin:-6px 0 14px" hidden>
            Die neuen Vokabeln kommen hinten dran. Freigegeben wird dadurch
            nichts &ndash; deine Klasse sieht sie erst, wenn du sie aufmachst.
        </p>`;
}

function pairRow(entry, index) {
    // Die Wortart kommt vom Modell und wird dem Kind nicht gezeigt - sie reist
    // unsichtbar am Element mit, damit sie beim Speichern erhalten bleibt.
    return `
        <div class="pair${entry.correction ? ' pruefen' : ''}" data-row="${index}"
             data-wt="${esc(entry.word_type || '')}"
             ${entry.correction ? `data-korrektur="${esc(entry.correction)}"` : ''}>
            <input type="text" class="f" value="${esc(entry.foreign || '')}"
                   placeholder="Fremdsprache" maxlength="255"
                   autocapitalize="none" autocorrect="off" spellcheck="false">
            <input type="text" class="n" value="${esc(entry.native || '')}"
                   placeholder="Deutsch" maxlength="255" autocorrect="off">
            <button class="del" data-del="${index}" aria-label="Zeile löschen">&times;</button>
            ${entry.correction ? `<span class="pruefnotiz">⚠️ Von der KI berichtigt: ${esc(entry.correction)} - bitte genau prüfen</span>` : ''}
        </div>`;
}

/** Alle Zeilen inklusive halb ausgefüllter - Grundlage für den Entwurf. */
function collectAll() {
    return $$('.pair').map((row) => ({
        foreign:    row.querySelector('.f').value,
        native:     row.querySelector('.n').value,
        word_type:  row.dataset.wt || null,
        correction: row.dataset.korrektur || null,
    }));
}

/** Nur vollständige Paare - das wird gespeichert. */
function collectComplete() {
    return collectAll()
        .map((e) => ({
            foreign:    e.foreign.trim(),
            native:     e.native.trim(),
            word_type:  e.word_type,
            correction: e.correction,
        }))
        .filter((e) => e.foreign !== '' && e.native !== '');
}

// ------------------------------------------------------------------ Entwurf

function saveDraft(languageId, title, entries) {
    try {
        localStorage.setItem(draftKey(languageId), JSON.stringify({ title, entries }));
    } catch { /* privater Modus o. ae. - der Entwurf ist nur Komfort */ }
}

function loadDraft(languageId) {
    try {
        const raw = localStorage.getItem(draftKey(languageId));
        if (!raw) return null;
        const data = JSON.parse(raw);
        return Array.isArray(data?.entries) && data.entries.length > 0 ? data : null;
    } catch {
        return null;
    }
}

function clearDraft(languageId) {
    try {
        localStorage.removeItem(draftKey(languageId));
    } catch { /* egal */ }
}
