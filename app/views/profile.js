import {
    VT, api, render, esc, $, go, topbar, loading, wireBack,
    showError, clearError, withBusy,
} from '../core.js';
import { farbwahlVerdrahten } from '../appsymbol.js';

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
/*
 * Das App-Symbol, wie es auf dem Home-Bildschirm liegt - die Regeln dafür
 * (Verlauf, Farbwahl) stehen in appsymbol.js, zusammen mit dem
 * Lehrkraft-Bereich, der dieselbe Karte zeigt.
 */

/** Ein Stück Home-Bildschirm: Hintergrund, Symbol, Name darunter. */
function homescreen() {
    // Die Farben setzt symbolFaerben(), nachdem gezeichnet ist. Voki ist
    // dasselbe SVG, aus dem icon.php sein PNG bekommt.
    return `
        <div class="homescreen" aria-hidden="true">
            <div class="appsymbol" id="appsymbol">
                <img src="${esc(VT.base)}/assets/voki-icon.svg" alt="">
            </div>
            <span class="appname">${esc(VT.user.appName ?? '')}</span>
        </div>`;
}

export async function profileView(zumPasswort = false) {
    render(loading());

    const { profile, palette } = await api('profile', 'get');

    const farben = palette.map((c) => `
        <label class="swatch-pick" title="${esc(c)}">
            <input type="radio" name="color" value="${esc(c)}"
                   ${c === profile.color ? 'checked' : ''}>
            <span style="--c:${esc(c)}"></span>
        </label>
    `).join('');

    render(`
        ${topbar('Mein Konto', { backTo: '/' })}
        <div id="msg"></div>

        <div class="card">
            <label for="name">Dein Name</label>
            <input type="text" id="name" maxlength="64" value="${esc(profile.name)}">
            <p class="tiny muted">
                So heisst die App auf deinem Home-Bildschirm - aus „${esc(profile.name)}"
                wird „${esc(VT.user.appName)}".
            </p>

            <label>Dein App-Symbol</label>
            <div class="appsymbolzeile">
                ${homescreen()}
                <p class="tiny muted">
                    So sieht deine App auf dem Home-Bildschirm aus. Die Farbe
                    gilt auch in der App selbst.
                </p>
            </div>

            <!--
                Das Feld liegt hinter einem Knopf, der die gewaehlte Farbe
                zeigt. Offen nahm es mehr Platz als alles andere auf der
                Seite, und gebraucht wird es selten. <details> klappt ohne
                eigenes Skript auf und zu, auch mit der Tastatur.
            -->
            <details class="farbwahl" id="farbwahl">
                <summary class="btn secondary farbknopf">
                    <span class="farbpunkt" id="farbpunkt" style="--c:${esc(profile.color)}"></span>
                    <span>Farbe ändern</span>
                </summary>
                <div class="swatches">${farben}</div>
            </details>

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
    farbwahlVerdrahten();

    if (zumPasswort) {
        const feld = $('#current');
        feld?.scrollIntoView({ block: 'center' });
        feld?.focus();
    }


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


