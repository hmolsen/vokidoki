import { api, render, $, withBusy, showError, clearError } from '../core.js';

export async function loginView() {
    render(`
        <div style="height:6vh"></div>
        <div class="center">
            <div style="font-size:3.4rem;line-height:1">&#128218;</div>
            <h1>Vokabeln</h1>
            <p class="sub">Melde dich mit deinem Namen an.</p>
        </div>

        <div id="msg"></div>

        <form class="card" id="form" autocomplete="on">
            <label for="username">Benutzername</label>
            <input type="text" id="username" name="username"
                   autocapitalize="none" autocorrect="off" spellcheck="false"
                   autocomplete="username" required>

            <label for="password">Passwort</label>
            <input type="password" id="password" name="password"
                   autocomplete="current-password" required>

            <button class="btn" type="submit" id="submit">Anmelden</button>
        </form>
    `);

    const form = $('#form');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearError();

        const username = $('#username').value.trim();
        const password = $('#password').value;
        if (!username || !password) {
            showError('Bitte Benutzername und Passwort eingeben.');
            return;
        }

        try {
            await withBusy($('#submit'), 'Einen Moment...', async () => {
                const data = await api('auth', 'login', { body: { username, password } });

                // Bewusst eine echte Seitennavigation statt eines SPA-Wechsels:
                // Erst dadurch liefert index.php den personalisierten
                // <link rel="manifest"> aus, den iOS beim Hinzufügen zum
                // Home-Bildschirm liest.
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

    $('#username').focus();
}
