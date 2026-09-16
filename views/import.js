import {
    api, render, esc, $, $$, go, topbar, wireBack, showError, clearError, withBusy,
} from '../core.js';

const MAX_IMAGES = 6;
/* Claude skaliert größere Bilder ohnehin herunter - kleiner hochladen spart
   Uploadzeit und Token, ohne an Erkennungsqualität zu verlieren. */
const MAX_EDGE = 1568;
const JPEG_QUALITY = 0.82;

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

async function kursHolen(languageId) {
    if (kursName !== null) return kursName;
    try {
        const { language } = await api('units', 'list', { query: { language_id: languageId } });
        kursName = language?.course || language?.name || '';
    } catch {
        kursName = '';
    }
    return kursName;
}

export async function importView(languageId) {
    kursName = null;
    const draft = loadDraft(languageId);
    if (draft) {
        showReview(languageId, draft.title, draft.entries, true);
    } else {
        showCapture(languageId);
    }

    // Nachgereicht statt abgewartet: Die Kamera soll nicht auf eine Abfrage
    // warten, die nur eine Ueberschrift betrifft.
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
        <p class="kurszeile">Kurs: <strong id="kurs">${esc(kursName || '...')}</strong></p>
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
        showWorking();
        try {
            const data = await api('import', 'analyze', {
                body: {
                    language_id: Number(languageId),
                    images: images.map((i) => ({ data: i.data, media_type: i.media_type })),
                },
            });
            saveDraft(languageId, data.title, data.entries);
            showReview(languageId, data.title, data.entries, false);
        } catch (err) {
            // Fotos bewusst weiterreichen - sie noch einmal zu machen wäre ärgerlich.
            showCapture(languageId, images);
            showError(err.message);
        }
    });

    refresh();
}

function showWorking() {
    render(`
        <div class="empty" style="padding-top:22vh">
            <div class="spinner"></div>
            <strong>Die Vokabeln werden gelesen...</strong>
            <p class="tiny muted">Das dauert meist zehn bis zwanzig Sekunden.</p>
        </div>
    `);
}

// ------------------------------------------------------------------ Schritt 2: Prüfen

function showReview(languageId, title, entries, fromDraft) {
    const rows = entries.map((e, i) => pairRow(e, i)).join('');

    render(`
        ${topbar('Stimmt das so?', { backTo: `/lang/${languageId}` })}
        <p class="kurszeile">Kurs: <strong id="kurs">${esc(kursName || '...')}</strong></p>
        <div id="msg"></div>

        ${fromDraft ? '<div class="notice info">Deine letzte Eingabe wurde wiederhergestellt.</div>' : ''}

        <p class="sub">
            Schau kurz drüber und verbessere, was nicht stimmt.
            Gespeichert wird erst, wenn du unten tippst.
        </p>

        <div class="card">
            <label for="title">Titel der Lerneinheit</label>
            <input type="text" id="title" maxlength="128"
                   placeholder="z. B. Unit 1" value="${esc(title || '')}">
            ${title ? '' : '<p class="tiny muted" style="margin:-6px 0 0">Auf den Fotos stand keine Überschrift - denk dir einen Namen aus.</p>'}
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
    const persist = () => saveDraft(languageId, $('#title').value, collectAll());

    // Eine Delegation für Löschen und Tippen, statt Listener pro Zeile.
    pairs.addEventListener('click', (event) => {
        const button = event.target.closest('[data-del]');
        if (!button) return;
        button.closest('.pair')?.remove();
        persist();
    });
    pairs.addEventListener('input', persist);
    $('#title').addEventListener('input', persist);

    $('#addRow').addEventListener('click', () => {
        pairs.insertAdjacentHTML('beforeend', pairRow({ foreign: '', native: '' }, $$('.pair').length));
        $$('.pair').at(-1)?.querySelector('input')?.focus();
    });

    $('#discard').addEventListener('click', () => {
        if (!confirm('Diese erkannten Vokabeln verwerfen?')) return;
        clearDraft(languageId);
        showCapture(languageId);
    });

    $('#save').addEventListener('click', async (event) => {
        clearError();
        const unitTitle = $('#title').value.trim();
        if (!unitTitle) {
            showError('Bitte gib der Lerneinheit einen Titel.');
            $('#title').focus();
            return;
        }
        const collected = collectComplete();
        if (collected.length === 0) {
            showError('Es ist keine vollständige Vokabel übrig.');
            return;
        }

        try {
            await withBusy(event.currentTarget, 'Wird gespeichert...', async () => {
                const data = await api('import', 'save', {
                    body: { language_id: Number(languageId), title: unitTitle, entries: collected },
                });
                clearDraft(languageId);
                go(`/unit/${data.unit_id}`);
            });
        } catch (err) {
            showError(err.message);
        }
    });
}

function pairRow(entry, index) {
    // Die Wortart kommt vom Modell und wird dem Kind nicht gezeigt - sie reist
    // unsichtbar am Element mit, damit sie beim Speichern erhalten bleibt.
    return `
        <div class="pair" data-row="${index}" data-wt="${esc(entry.word_type || '')}">
            <input type="text" class="f" value="${esc(entry.foreign || '')}"
                   placeholder="Fremdsprache" maxlength="255"
                   autocapitalize="none" autocorrect="off" spellcheck="false">
            <input type="text" class="n" value="${esc(entry.native || '')}"
                   placeholder="Deutsch" maxlength="255" autocorrect="off">
            <button class="del" data-del="${index}" aria-label="Zeile löschen">&times;</button>
        </div>`;
}

/** Alle Zeilen inklusive halb ausgefüllter - Grundlage für den Entwurf. */
function collectAll() {
    return $$('.pair').map((row) => ({
        foreign:   row.querySelector('.f').value,
        native:    row.querySelector('.n').value,
        word_type: row.dataset.wt || null,
    }));
}

/** Nur vollständige Paare - das wird gespeichert. */
function collectComplete() {
    return collectAll()
        .map((e) => ({
            foreign:   e.foreign.trim(),
            native:    e.native.trim(),
            word_type: e.word_type,
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

// ------------------------------------------------------------------ Bildverkleinerung

/** Verkleinert ein Foto auf MAX_EDGE und liefert Base64-JPEG ohne Data-URL-Prefix. */
async function shrinkToBase64(file) {
    const bitmap = await loadBitmap(file);

    const scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height));
    const width  = Math.max(1, Math.round(bitmap.width * scale));
    const height = Math.max(1, Math.round(bitmap.height * scale));

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(bitmap, 0, 0, width, height);
    bitmap.close?.();

    const dataUrl = canvas.toDataURL('image/jpeg', JPEG_QUALITY);
    return { data: dataUrl.split(',')[1], media_type: 'image/jpeg' };
}

function loadBitmap(file) {
    if ('createImageBitmap' in window) {
        // imageOrientation korrigiert die EXIF-Drehung von iPhone-Fotos.
        return createImageBitmap(file, { imageOrientation: 'from-image' })
            .catch(() => loadViaImageElement(file));
    }
    return loadViaImageElement(file);
}

function loadViaImageElement(file) {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => { URL.revokeObjectURL(url); resolve(img); };
        img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Bild unlesbar')); };
        img.src = url;
    });
}
