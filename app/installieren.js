/*
 * Der Weg aufs Home-Bildschirm - für die Lernansicht und die Verwaltung.
 *
 * Bis hierher stand nur in der Lernansicht ein Satz dazu, nur auf dem
 * iPhone, mit einem Emoji davor. Wie das Symbol aussehen würde, sah man
 * erst hinterher, und im Lehrkraft-Bereich stand gar nichts. Jetzt zeigt
 * der Hinweis das echte Symbol - bei der Verwaltung das mit dem grauen
 * Balken - und je nach Gerät den passenden Weg:
 *
 *   - iPhone und iPad: "Teilen", dann "Zum Home-Bildschirm". Einen Knopf,
 *     der das auslöst, gibt Safari nicht her.
 *   - Android: ein Knopf, sobald Chrome das Anlegen anbietet
 *     (beforeinstallprompt); vorher der Weg über das Menü.
 *   - Am Rechner nichts - dort ist ein Symbol auf dem Schreibtisch nicht
 *     gemeint, und der Hinweis nähme nur Platz weg.
 *
 * Ein Modul ohne Abhängigkeiten, weil teacher.js keines ist und es mit
 * import() nachlädt - wie lesevoki.js.
 */

let angebot = null;
const wartende = new Set();

/*
 * Chrome bietet das Anlegen einmal an, früh nach dem Laden. Wer das Ereignis
 * verpasst, bekommt es nicht noch einmal - deshalb hört das Modul gleich
 * beim Laden zu und merkt es sich, auch wenn noch kein Hinweis steht.
 */
window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    angebot = e;
    wartende.forEach((zeichnen) => zeichnen());
});
window.addEventListener('appinstalled', () => {
    angebot = null;
    wartende.forEach((zeichnen) => zeichnen());
});

export function laeuftInstalliert() {
    return window.navigator.standalone === true
        || window.matchMedia('(display-mode: standalone)').matches;
}

/**
 * Dem Server sagen, dass dieses Gerät Vokidoki als Symbol führt.
 *
 * Erst damit steht es unter "Deine Geräte" (lib/geraete.php): Ein
 * Geräte-Token entsteht auch ohne Symbol, bei jeder Anmeldung. Gemeldet
 * wird bei jedem Start als Symbol - auch für Symbole, die schon lagen, bevor
 * es die Liste gab. Ohne Netz geht die Meldung verloren; der nächste Start
 * holt sie nach.
 *
 * Die Finger verraten ein iPad, das sich als Mac ausgibt (siehe geraet()).
 */
export function installiertMelden(base) {
    if (!laeuftInstalliert()) return;
    fetch(`${base}/api/profile.php?action=installiert`, {
        method: 'POST',
        headers: { 'X-Vokabeltrainer': '1', 'Content-Type': 'application/json' },
        body: JSON.stringify({ beruehrbar: navigator.maxTouchPoints > 1 }),
        credentials: 'same-origin',
    }).catch(() => {});
}

function geraet() {
    const ua = navigator.userAgent;
    // iPadOS meldet sich als Mac - erkennbar nur an den Fingern.
    if (/iPhone|iPad|iPod/.test(ua)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)) return 'ios';
    if (/Android/.test(ua)) return 'android';
    return 'rechner';
}

const esc = (s) => String(s).replace(/[&<>"']/g, (z) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[z]);

// Das Teilen-Zeichen von iOS: ein Kasten mit Pfeil nach oben.
const TEILEN = `<svg class="teilenzeichen" viewBox="0 0 20 24" aria-hidden="true">
    <path d="M10 2v13M5.5 6.5 10 2l4.5 4.5M6.5 10H4v12h12V10h-2.5" fill="none"
          stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
</svg>`;

/**
 * Füllt el mit dem Hinweis - oder lässt es leer, wo es nichts zu tun gibt.
 *
 * name:   wie das Symbol heissen wird ("Lillis Vokidoki", "Verwaltung")
 * symbol: Adresse des Symbols, wie es auf dem Bildschirm liegen wird
 * wohin:  was das Symbol öffnet, für den Satz darunter
 */
export function installHinweis(el, { name, symbol, wohin }) {
    if (!el) return;

    const zeichnen = () => {
        const art = geraet();
        if (laeuftInstalliert() || art === 'rechner') {
            el.hidden = true;
            el.innerHTML = '';
            return;
        }

        let weg;
        if (art === 'ios') {
            weg = `<p class="installweg">Unten auf <strong>Teilen</strong> ${TEILEN} tippen,
                   dann <strong>Zum Home-Bildschirm</strong>.</p>`;
        } else if (angebot) {
            weg = `<button class="btn small installknopf" type="button">Auf den Startbildschirm</button>`;
        } else {
            weg = `<p class="installweg">Im Browsermenü <strong>&#8942;</strong>
                   <strong>Zum Startbildschirm hinzufügen</strong> wählen.</p>`;
        }

        el.hidden = false;
        el.innerHTML = `
            <div class="install">
                <img class="installsymbol" src="${esc(symbol)}" alt="" width="60" height="60">
                <div class="installtext">
                    <strong>${esc(name)} auf den Home-Bildschirm</strong>
                    <span class="tiny muted">${esc(wohin)} Du bist dort immer direkt angemeldet.</span>
                    ${weg}
                </div>
            </div>`;

        el.querySelector('.installknopf')?.addEventListener('click', async () => {
            if (!angebot) return;
            const jetzt = angebot;
            angebot = null;
            await jetzt.prompt();
            zeichnen();
        });
    };

    wartende.add(zeichnen);
    zeichnen();
}
