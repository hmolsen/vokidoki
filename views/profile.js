import {
    VT, api, render, esc, $, on, go, topbar, loading, wireBack,
    showError, clearError, withBusy,
} from '../core.js';
import { serie, serieHeute, serieTage, heute, tagZaehlt } from '../vorrat.js';

/**
 * Das eigene Konto: Name, Farbe, Passwort.
 *
 * Ändern kann es das Kind selbst, nicht nur der Betreiber im Admin. Ein
 * Kind, das sein Anfangspasswort behalten muss, weil niemand es ändern kann,
 * hat ein Passwort, das auf einem Zettel steht - und Zettel gehen in einer
 * Klasse herum.
 */
/**
 * Das eigene Konto.
 *
 * @param zumPasswort  Gleich beim Passwort anfangen. Der Lehrkraft-Bereich
 *                     hat dafuer einen eigenen Knopf - "erst suchen, dann
 *                     tippen" ist kein Weg, den man zweimal geht.
 */
export async function profileView(zumPasswort = false) {
    render(loading());

    const { profile, palette } = await api('profile', 'get');

    const farben = palette.map((c) => `
        <label class="swatch-pick">
            <input type="radio" name="color" value="${esc(c)}"
                   ${c === profile.color ? 'checked' : ''}>
            <span style="--c:${esc(c)}"></span>
        </label>
    `).join('');

    render(`
        ${topbar('Mein Konto', { backTo: '/' })}
        <div id="msg"></div>

        ${serieAbschnitt()}

        <div class="card">
            <label for="name">Dein Name</label>
            <input type="text" id="name" maxlength="64" value="${esc(profile.name)}">
            <p class="tiny muted">
                So heisst die App auf deinem Home-Bildschirm - aus „${esc(profile.name)}"
                wird „${esc(VT.user.appName)}".
            </p>

            <label>Deine Farbe</label>
            <div class="swatches">${farben}</div>

            <button class="btn" id="save">Speichern</button>
        </div>

        <h2 class="section">Passwort ändern</h2>

        ${profile.initial ? `
            <div class="notice" id="initialhint">
                Du hast noch dein Anfangspasswort. Denk dir eins aus, das nur du
                kennst - dann steht es nirgends mehr auf einem Zettel.
            </div>` : ''}

        <div class="card">
            <label for="current">Bisheriges Passwort</label>
            <input type="password" id="current" autocomplete="current-password">

            <label for="pw1">Neues Passwort</label>
            <input type="password" id="pw1" autocomplete="new-password">

            <label for="pw2">Noch einmal</label>
            <input type="password" id="pw2" autocomplete="new-password">

            <button class="btn secondary" id="changepw">Passwort ändern</button>
            <p class="tiny muted">
                Mindestens sechs Zeichen. Merk es dir gut - wenn du es vergisst,
                kann dir nur deine Lehrkraft ein neues geben.
            </p>
        </div>

        <p class="tiny muted">
            Dein Benutzername zum Anmelden ist
            <code>${esc(profile.username)}</code> und lässt sich nicht ändern.
        </p>
    `);

    wireBack();
    kalenderAktivieren();

    if (zumPasswort) {
        const feld = $('#current');
        feld?.scrollIntoView({ block: 'center' });
        feld?.focus();
    }

    // Die Farbe wirkt sofort - man soll sehen, was man waehlt.
    on('input[name="color"]', 'change', (e) => {
        document.body.style.setProperty('--accent', e.currentTarget.value);
    });

    $('#save').addEventListener('click', async (e) => {
        clearError();
        const name  = $('#name').value.trim();
        const color = (document.querySelector('input[name="color"]:checked') || {}).value
                      || profile.color;

        if (name === '') {
            showError('Bitte einen Namen angeben.');
            return;
        }

        try {
            await withBusy(e.currentTarget, 'Wird gespeichert...', async () => {
                const data = await api('profile', 'save', { body: { name, color } });
                // Der Kopf der App traegt den Namen - ohne das bliebe der alte
                // stehen, bis jemand neu laedt.
                VT.user = { ...VT.user, ...data.user };
                go('/');
            });
        } catch (err) {
            showError(err.message);
        }
    });

    $('#changepw').addEventListener('click', async (e) => {
        clearError();
        const current = $('#current').value;
        const pw1     = $('#pw1').value;
        const pw2     = $('#pw2').value;

        if (pw1 !== pw2) {
            showError('Die beiden neuen Passwörter sind nicht gleich.');
            return;
        }

        try {
            await withBusy(e.currentTarget, 'Wird geändert...', async () => {
                await api('profile', 'password', { body: { current, password: pw1 } });
            });
            $('#current').value = '';
            $('#pw1').value = '';
            $('#pw2').value = '';

            /*
             * Der Hinweis auf das Anfangspasswort verschwindet sofort.
             *
             * Er steht nur da, solange in der Datenbank noch das erzeugte
             * Passwort liegt - und das ist ab jetzt geloescht. Bliebe er
             * stehen, forderte die Seite zu etwas auf, das gerade erledigt
             * wurde, und man fragte sich, ob es geklappt hat.
             */
            profile.initial = false;
            const hinweis = $('#initialhint');
            if (hinweis) hinweis.remove();

            showError('Passwort geändert. Merk es dir gut!', 'good');
        } catch (err) {
            showError(err.message);
        }
    });
}


/**
 * Die Serie im Konto: ein Satz zur Lage, darunter ein Monatskalender.
 *
 * Hier standen dreissig Kaestchen in einer Reihe - eine Zeitleiste ohne
 * Bezug. Sie beantwortete "wie viele Tage am Stueck", aber nicht "wann
 * eigentlich": Der vierte Kasten von links war irgendein Dienstag. Ein
 * Kalender beantwortet beides, weil jeder weiss, wo im Monat er steht.
 *
 * In jedem Kasten steht, wie viele Antworten an dem Tag richtig waren.
 */
function serieAbschnitt() {
    const s = serieHeute();

    const best = (s.best ?? 0) > 0
        ? `<p class="tiny muted">Deine beste Serie: <strong>${s.best} ${
               s.best === 1 ? 'Tag' : 'Tage'}</strong></p>`
        : '';

    const satz = {
        heute:  `Du hast heute schon geübt. Deine Serie: <strong>${s.zahl} ${s.zahl === 1 ? 'Tag' : 'Tage'}</strong>.`,
        offen:  `Deine Serie steht bei <strong>${s.zahl} ${s.zahl === 1 ? 'Tag' : 'Tage'}</strong> - heute fehlt noch etwas.`,
        gefahr: `Deine Serie steht bei <strong>${s.zahl} ${s.zahl === 1 ? 'Tag' : 'Tage'}</strong>, aber sie wackelt: Übst du heute nichts, fängt sie wieder bei null an.`,
        aus:    'Du hast gerade keine Serie. Lerne heute etwas, dann steht hier morgen eine 1.',
    }[s.lage] ?? '';

    return `
        <h2 class="section">Deine Serie</h2>
        <div class="card">
            <p class="serietext">${satz}</p>

            <div class="monatskopf">
                <button class="iconbtn" type="button" id="monat-zurueck"
                        aria-label="Voriger Monat">&#8249;</button>
                <strong id="monat-name"></strong>
                <button class="iconbtn" type="button" id="monat-vor"
                        aria-label="Nächster Monat">&#8250;</button>
            </div>

            <div class="monatsgitter" id="monatsgitter"></div>

            <div class="serielegende">
                <span><i class="serietag voll"></i> Tag geschafft</span>
                <span><i class="serietag halb"></i> geübt, aber zu wenig</span>
                <span><i class="serietag"></i> nichts geübt</span>
            </div>
            ${best}
        </div>`;
}

const MONATE = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli',
                'August', 'September', 'Oktober', 'November', 'Dezember'];

/**
 * Den Kalender zeichnen und die beiden Pfeile verdrahten.
 *
 * Gerechnet wird durchweg in UTC. Die Zeitumstellung macht einen Tag 23 oder
 * 25 Stunden lang, und ein Kalender, der im Oktober einen Tag verliert, ist
 * schlimmer als keiner.
 */
function kalenderAktivieren() {
    const gitter = $('#monatsgitter');
    if (!gitter) return;

    const s     = serie();
    const jetzt = heute();
    const tage  = new Map(serieTage().map((t) => [t.d, t]));

    const [jJ, jM] = jetzt.split('-').map(Number);
    const ende = jJ * 12 + (jM - 1);              // der laufende Monat

    /*
     * So weit zurueck geht es: zwoelf Monate - oder bis zu dem Monat, in dem
     * das Konto entstanden ist, wenn das spaeter war. In Monate zu blaettern,
     * in denen es das Konto noch gar nicht gab, sieht aus wie ein Fehler.
     */
    const [sJ, sM] = (s.seit ?? jetzt).split('-').map(Number);
    const anfang = Math.max(ende - (s.monate ?? 12), sJ * 12 + (sM - 1));

    let zeigt = ende;

    const zeichnen = () => {
        const jahr  = Math.floor(zeigt / 12);
        const monat = zeigt % 12;                 // 0 = Januar

        $('#monat-name').textContent = `${MONATE[monat]} ${jahr}`;
        $('#monat-zurueck').disabled = zeigt <= anfang;
        $('#monat-vor').disabled     = zeigt >= ende;

        const imMonat = new Date(Date.UTC(jahr, monat + 1, 0)).getUTCDate();
        // Montag als erste Spalte: getUTCDay() zaehlt ab Sonntag.
        const versatz = (new Date(Date.UTC(jahr, monat, 1)).getUTCDay() + 6) % 7;

        const zellen = [];
        for (let i = 0; i < versatz; i++) {
            zellen.push('<div class="monatstag leer"></div>');
        }

        const zwei = (n) => String(n).padStart(2, '0');
        for (let t = 1; t <= imMonat; t++) {
            const tag = `${jahr}-${zwei(monat + 1)}-${zwei(t)}`;
            const e   = tage.get(tag);
            const l   = e?.l ?? 0;
            const c   = e?.c ?? 0;

            const klassen = ['monatstag'];
            if (tagZaehlt(l, c)) klassen.push('voll');
            else if (c > 0)      klassen.push('halb');
            if (tag === jetzt)   klassen.push('heute');
            if (tag > jetzt)     klassen.push('spaeter');

            const was = tagZaehlt(l, c)
                ? (l > 0 ? `${c} richtige Antworten, ${l} neue Vokabel${l === 1 ? '' : 'n'}`
                         : `${c} richtige Antworten`)
                : (c > 0 ? `${c} richtige Antworten - zu wenig für den Tag`
                         : 'nichts geübt');

            /*
             * Im Kasten steht die Zahl der richtigen Antworten - bis zu
             * dreistellig. Die Nummer des Tages steht NICHT darin: Sie
             * ergibt sich aus der Stelle im Gitter, und zwei Zahlen in einem
             * Kaestchen von dieser Groesse liest niemand mehr.
             */
            zellen.push(
                `<div class="${klassen.join(' ')}" title="${esc(tagLesbar(tag))}: ${esc(was)}">`
                + `${c > 0 ? esc(String(Math.min(c, 999))) : ''}</div>`,
            );
        }

        gitter.innerHTML = zellen.join('');
    };

    $('#monat-zurueck').addEventListener('click', () => {
        if (zeigt > anfang) { zeigt--; zeichnen(); }
    });
    $('#monat-vor').addEventListener('click', () => {
        if (zeigt < ende) { zeigt++; zeichnen(); }
    });

    zeichnen();
}

/** 2026-09-21 wird zu 21.09.2026 - so steht es auf jedem Zettel in der Schule. */
function tagLesbar(tag) {
    const [j, m, t] = tag.split('-');
    return `${t}.${m}.${j}`;
}
