import {
    VT, api, render, esc, $, withBusy, showError, clearError, rechtsZeile,
} from '../core.js';

/*
 * Angemeldet wird mit drei Angaben: Schulkürzel, Benutzername, Passwort -
 * Benutzernamen sind nur innerhalb ihrer Schule eindeutig
 * (lib/schulkuerzel.php).
 *
 * Vorausgefüllt wird, was schon bekannt ist: Der QR-Code auf dem Zettel
 * bringt Kürzel und Benutzernamen mit (?schule=...&name=...), dann fehlt nur
 * noch das Passwort. Ohne ihn steht das Kürzel der letzten Anmeldung auf
 * diesem Gerät da - auf einem geteilten Tablet ist es meist dieselbe Schule.
 */
const SCHULE_MERKEN = 'vt-schule';

function vorbelegt() {
    const p = new URLSearchParams(location.search);
    let gemerkt = '';
    try { gemerkt = localStorage.getItem(SCHULE_MERKEN) ?? ''; } catch { /* privates Fenster */ }
    return {
        schule: (p.get('schule') ?? gemerkt).trim().toLowerCase(),
        name:   (p.get('name') ?? '').trim().toLowerCase(),
    };
}

export async function loginView() {
    const vor = vorbelegt();
    render(`
        <div style="height:6vh"></div>
        <div class="center">
            <!--
                Das Wortzeichen als Bild und nicht als Schrift: Das V ist
                Voki selbst, und die sieben Buchstaben dahinter stehen als
                Pfade in der Datei. So sieht es ueberall gleich aus - auch
                in dem Augenblick vor dem ersten Bild, in dem die Schrift
                noch gar nicht da ist. Ausgerechnet der Name der App duerfte
                dort nicht in einer fremden Schrift aufblitzen.
            -->
            <img class="logo" src="${esc(VT.base)}/assets/vokidoki_logo.svg"
                 alt="Vokidoki" width="768" height="256">
            <p class="sub">Melde dich mit deinem Namen an.</p>
        </div>

        <div id="msg"></div>

        <form class="card" id="form" autocomplete="on">
            <label for="school">Schulkürzel</label>
            <input type="text" id="school" name="school" maxlength="12"
                   autocapitalize="none" autocorrect="off" spellcheck="false"
                   placeholder="steht auf deinem Zettel" value="${esc(vor.schule)}" required>

            <label for="username">Benutzername</label>
            <input type="text" id="username" name="username"
                   autocapitalize="none" autocorrect="off" spellcheck="false"
                   autocomplete="username" value="${esc(vor.name)}" required>

            <label for="password">Passwort</label>
            <input type="password" id="password" name="password"
                   autocomplete="current-password" required>

            <button class="btn" type="submit" id="submit">Anmelden</button>
        </form>

        <!-- Auch ohne Konto erreichbar: Wer sich anmelden soll, darf vorher
             wissen, wer dahintersteht und was mit seinen Daten geschieht. -->
        ${rechtsZeile()}
    `);

    const form = $('#form');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearError();

        const school   = $('#school').value.trim().toLowerCase();
        const username = $('#username').value.trim();
        const password = $('#password').value;
        if (!school || !username || !password) {
            showError('Bitte Schulkürzel, Benutzername und Passwort eingeben.');
            return;
        }

        try {
            await withBusy($('#submit'), 'Einen Moment...', async () => {
                const data = await api('auth', 'login', { body: { school, username, password } });
                try { localStorage.setItem(SCHULE_MERKEN, school); } catch { /* egal */ }

                // Bewusst eine echte Seitennavigation statt eines SPA-Wechsels:
                // Erst dadurch liefert index.php den personalisierten
                // <link rel="manifest"> aus, den iOS beim Hinzufügen zum
                // Home-Bildschirm liest. Und wohin es geht, sagt der Server:
                // ein Kind in die App, eine Lehrkraft in die Verwaltung.
                window.location.href = data.redirect;

                // Warten, bis die Navigation greift - sonst blinkt der Button zurück.
                await new Promise((resolve) => setTimeout(resolve, 4000));
            });
        } catch (err) {
            showError(err.message);
            $('#password').value = '';
            $('#password').focus();
        }
    });

    // Ins erste Feld, das noch leer ist - nach dem QR-Code ist das das Passwort.
    (['#school', '#username', '#password'].map((s) => $(s)).find((f) => f.value === '') ?? $('#password')).focus();
}
