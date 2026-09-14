import {
    VT, api, render, esc, $, on, go, topbar, loading, wireBack,
    showError, clearError, withBusy,
} from '../core.js';

/**
 * Das eigene Konto: Name, Farbe, Passwort.
 *
 * Bisher konnte das nur der Betreiber im Admin ändern. Für eine Familie ging
 * das - Papa sass daneben. In einer Schule nicht: Ein Kind, das sein
 * Anfangspasswort behalten muss, weil niemand es ändern kann, hat ein
 * Passwort, das auf einem Zettel steht.
 */
export async function profileView() {
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
