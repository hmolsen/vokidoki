import {
    VT, api, render, esc, $, $$, go, withBusy, showError, clearError,
} from '../core.js';
import { vorratAuffrischen } from '../vorrat.js';

/**
 * Die Hinweise bei der ersten Anmeldung.
 *
 * Was hier steht, kommt vom Server (lib/einwilligung.php) - welche Punkte,
 * in welchem Wortlaut, mit welchen Links. Diese Ansicht zeichnet sie nur und
 * schickt zurück, was angehakt ist. Die Regel, dass ohne Bestätigung nichts
 * geht, steht ebenfalls dort: Die API lehnt jeden anderen Aufruf ab, bis
 * hier gespeichert ist. app.js schickt jede Adresse hierher, solange
 * VT.user.einwilligung gesetzt ist.
 *
 * Der Knopf wird erst brauchbar, wenn jeder Punkt angehakt ist. Ein
 * Knopf, der drückbar aussieht und dann "bitte alles anhaken" sagt, ist
 * eine Falle; einer, der sichtbar wartet, erklärt sich selbst.
 */
export async function einwilligungView() {
    const punkte = VT.user?.einwilligung?.punkte ?? [];
    const lehrkraft = VT.user?.isTeacher === true;

    render(`
        <div style="height:4vh"></div>
        <div class="center">
            <img class="logo" src="${esc(VT.base)}/assets/vokidoki_logo.svg"
                 alt="Vokidoki" width="768" height="256">
        </div>

        <div class="card einwilligung">
            <img class="einwilligung-voki" src="${esc(VT.base)}/assets/voki-icon.svg"
                 alt="" width="96" height="96">
            <h1>Willkommen, ${esc(VT.user?.name ?? '')}!</h1>
            <p class="sub">${lehrkraft
                ? 'Bitte bestätige vor der Nutzung kurz unsere rechtlichen Hinweise.'
                : 'Bevor es losgeht, lies bitte diese Sätze und hake jeden an.'}</p>

            <div id="msg"></div>

            <form id="form">
                <ul class="haken">
                    ${punkte.map((p) => `
                        <li>
                            <label>
                                <input type="checkbox" name="angehakt" value="${esc(p.schluessel)}">
                                <span>${p.html}</span>
                            </label>
                        </li>`).join('')}
                </ul>
                <button class="btn" type="submit" id="los" disabled>Jetzt starten</button>
            </form>
        </div>

        <p class="center tiny muted">
            <button class="linkbtn" type="button" id="nichtJetzt">Nicht jetzt &ndash; abmelden</button>
        </p>
    `);

    const kaesten = $$('#form input[type="checkbox"]');
    const knopf = $('#los');
    const pruefen = () => { knopf.disabled = !kaesten.every((k) => k.checked); };
    kaesten.forEach((k) => k.addEventListener('change', pruefen));

    $('#form').addEventListener('submit', async (event) => {
        event.preventDefault();
        clearError();
        const angehakt = kaesten.filter((k) => k.checked).map((k) => k.value);

        try {
            await withBusy(knopf, 'Einen Moment...', async () => {
                const data = await api('auth', 'einwilligung', { body: { angehakt } });
                VT.user = data.user;

                /*
                 * Erst jetzt gibt die API die Vokabeln heraus - beim Start
                 * hatte app.js deshalb keinen Vorrat holen können. Also hier,
                 * bevor die erste Ansicht ihn braucht.
                 */
                if (!VT.user.isTeacher) await vorratAuffrischen();

                // Eine Lehrkraft arbeitet in der Verwaltung, ein Kind in der App.
                if (VT.user.isTeacher) {
                    window.location.href = `${VT.base}/teacher/`;
                    return;
                }
                go('/', true);
            });
        } catch (err) {
            showError(err.message);
        }
    });

    $('#nichtJetzt').addEventListener('click', async () => {
        try { await api('auth', 'logout', { body: {} }); } catch { /* trotzdem hinaus */ }
        window.location.href = `${VT.base}/`;
    });
}
