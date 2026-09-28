/*
 * "Es gibt eine neue Fassung" - überall: in der App, im Lehrkraft-Bereich
 * und im Admin.
 *
 * Das Band stand bis hierher nur in der App (app.js). Der Lehrkraft-Bereich
 * hat inzwischen ein eigenes Symbol auf dem Home-Bildschirm und liegt dort
 * genauso wochenlang im Hintergrund - ohne Band merkte er von einer
 * Aktualisierung nichts, und ohne Adresszeile gibt es auch kein Neu-Laden.
 *
 * Ein Modul ohne Abhängigkeiten, damit alle drei es laden können:
 *   - die App ruft fassungBeobachten() selbst auf (app.js),
 *   - der Lehrkraft-Bereich und der Admin binden es als
 *     <script type="module" data-fassung ...> ein (fassung_skript_html() in
 *     lib/html.php) - dann startet es von allein.
 *
 * Auch das gründliche Neuholen steht hier und nur hier; core.js reicht
 * hardRefresh() nur durch.
 */

const UPDATE_INTERVAL = 5 * 60 * 1000;

/**
 * Holt die Oberfläche frisch vom Server.
 *
 * In der installierten App gibt es keine Adresszeile und kein Neu-Laden -
 * eine Aktualisierung käme dort sonst erst an, wenn iOS von sich aus
 * nachsieht. Deshalb gründlich: Service Worker abmelden, Zwischenspeicher
 * leeren, jede Datei ausdrücklich neu holen, dann neu starten.
 *
 * ziel: wohin danach - eine Adresse, oder null für "diese Seite neu laden".
 */
export async function frischHolen({ base, assets = [], ziel = null }) {
    try {
        // Zuerst abmelden, damit die Abrufe unten am Service Worker vorbei
        // wirklich ans Netz gehen.
        if ('serviceWorker' in navigator) {
            const regs = await navigator.serviceWorker.getRegistrations();
            await Promise.all(regs.map((r) => r.unregister()));
        }
        if ('caches' in window) {
            const keys = await caches.keys();
            await Promise.all(keys.map((k) => caches.delete(k)));
        }

        /*
         * Und jetzt jede Datei ausdrücklich neu holen.
         *
         * Nicht jede Datei trägt einen Versionsstempel in der Adresse - die
         * Module der App werden mit blankem Pfad importiert. Ohne diesen
         * Schritt bliebe es dem Browser überlassen, ob er sie für frisch
         * genug hält, und genau daran ist das Aktualisieren einmal
         * gescheitert. cache: 'reload' geht am Zwischenspeicher vorbei und
         * legt die neue Fassung gleich dort ab.
         */
        await Promise.all(assets.map(
            (pfad) => fetch(`${base}/${pfad}`, { cache: 'reload' }).catch(() => {}),
        ));
    } catch (err) {
        // Auch ohne Leeren ist ein Neustart besser als gar nichts.
        console.warn('Zwischenspeicher nicht vollständig geleert:', err);
    }

    if (ziel) window.location.replace(ziel);
    else window.location.reload();
}

/** Band, das von oben hereinfährt. Bleibt stehen, bis jemand darauf reagiert. */
function bandZeigen(neuholen) {
    if (document.getElementById('update-bar')) return;

    const bar = document.createElement('div');
    bar.className = 'update-bar';
    bar.id = 'update-bar';
    bar.setAttribute('role', 'status');
    bar.innerHTML = `
        <span class="update-text">Es gibt eine neue Fassung.</span>
        <button type="button" class="update-go" id="update-go">Aktualisieren</button>
        <button type="button" class="update-later" id="update-later"
                aria-label="Später">&times;</button>
    `;
    document.body.appendChild(bar);

    // Erst im nächsten Bild einblenden, sonst gibt es keinen Übergang.
    requestAnimationFrame(() => bar.classList.add('show'));

    document.getElementById('update-go').addEventListener('click', () => {
        const knopf = document.getElementById('update-go');
        knopf.disabled = true;
        knopf.textContent = 'Einen Moment...';
        neuholen();
    });

    document.getElementById('update-later').addEventListener('click', () => {
        bar.classList.remove('show');
        setTimeout(() => bar.remove(), 300);
    });
}

/**
 * Fragt in Abständen nach, ob auf dem Server etwas Neues liegt.
 *
 * Eine installierte App wird selten wirklich beendet - sie liegt wochenlang
 * im Hintergrund. Deshalb wird nicht nur nach der Uhr gefragt, sondern vor
 * allem dann, wenn sie wieder in den Vordergrund kommt. Die API läuft nie
 * über den Zwischenspeicher, die Antwort ist also die des Servers.
 *
 * ziel: eine Funktion, die sagt, wohin es nach dem Neuholen geht (null:
 * dieselbe Seite).
 */
export function fassungBeobachten({ base, version, assets = [], ziel = () => null }) {
    if (!version) return;

    let gemeldet = false;

    const nachsehen = async () => {
        if (gemeldet || document.visibilityState === 'hidden') return;

        try {
            const res = await fetch(`${base}/api/meta.php?action=version`, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Vokabeltrainer': '1' },
            });
            const { version: aufDemServer } = await res.json();
            if (aufDemServer && aufDemServer !== version) {
                gemeldet = true;
                bandZeigen(() => frischHolen({ base, assets, ziel: ziel() }));
            }
        } catch {
            // Offline oder Serverproblem - beim nächsten Mal wieder.
        }
    };

    document.addEventListener('visibilitychange', nachsehen);
    window.addEventListener('focus', nachsehen);
    setInterval(nachsehen, UPDATE_INTERVAL);
}

/*
 * Von selbst starten, wenn die Seite es so einbindet (Lehrkraft-Bereich,
 * Admin). Die Angaben stehen am <script>-Element - in einem Modul gibt es
 * kein document.currentScript, also wird es gesucht.
 */
const eigenesSkript = document.querySelector('script[data-fassung]');
if (eigenesSkript) {
    fassungBeobachten({
        base:    eigenesSkript.dataset.base,
        version: eigenesSkript.dataset.fassung,
        assets:  JSON.parse(eigenesSkript.dataset.assets || '[]'),
    });
}
