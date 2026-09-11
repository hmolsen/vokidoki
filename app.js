/* Router und Einstiegspunkt.
   Hash-Routing, damit die App ohne Rewrite-Regeln in jedem Unterverzeichnis
   eines Shared-Hostings läuft. */

import { VT, go, render, notice, api, hardRefresh } from './core.js';
import { loginView } from './views/login.js';
import { languagesView } from './views/languages.js';
import { languageView } from './views/language.js';
import { importView } from './views/import.js';
import { unitView } from './views/unit.js';
import { quizView } from './views/quiz.js';
import { clozeView } from './views/cloze.js';

const ROUTES = [
    [/^\/login$/,                 loginView,     { anonymous: true }],
    [/^\/$/,                      languagesView],
    [/^\/lang\/(\d+)$/,           languageView],
    [/^\/lang\/(\d+)\/import$/,   importView],
    // Die Lerneinheiten stehen jetzt auf der Sprachseite. Alte Adressen -
    // etwa aus einer noch nicht aktualisierten App auf dem Homescreen -
    // sollen trotzdem irgendwo landen.
    [/^\/lang\/(\d+)\/units$/,    (id) => go(`/lang/${id}`, true)],
    [/^\/unit\/(\d+)$/,           unitView],
    [/^\/quiz\/(\d+)$/,           quizView],
    [/^\/cloze\/(\d+)$/,          clozeView],
];

function currentPath() {
    const raw = location.hash.replace(/^#/, '');
    return raw === '' ? '/' : raw;
}

async function route() {
    const path = currentPath();

    for (const [pattern, view, opts = {}] of ROUTES) {
        const match = path.match(pattern);
        if (!match) continue;

        if (!opts.anonymous && !VT.user) {
            go('/login', true);
            return;
        }
        if (opts.anonymous && VT.user) {
            go('/', true);
            return;
        }

        try {
            await view(...match.slice(1));
        } catch (err) {
            // Ein Fehler beim Aufbau des Views darf nicht in einer leeren
            // Seite enden - lieber eine erklärende Meldung zeigen.
            console.error(err);
            render(`
                ${notice(err.message || 'Die Seite konnte nicht geladen werden.')}
                <button class="btn secondary" id="retry">Noch einmal versuchen</button>
            `);
            document.getElementById('retry').addEventListener('click', () => route());
        }
        return;
    }

    go(VT.user ? '/' : '/login', true);
}

/**
 * Hält --vvh auf der Höhe, die tatsächlich zu sehen ist.
 *
 * Klappt auf dem iPhone die Tastatur auf, schrumpft nur der sichtbare
 * Ausschnitt - die Seite bleibt so hoch wie zuvor. iOS scrollt daraufhin zum
 * Eingabefeld, und alles darüber wandert aus dem Bild. Wer seine Höhe an
 * --vvh bindet, hat nichts zu scrollen und bleibt stehen, wo er ist.
 *
 * Steht bewusst hier und nicht in core.js: app.js ist die einzige Datei, die
 * ihren Versionsstempel in der Adresse trägt und damit nach einem Update
 * verlässlich frisch ankommt. Ein neuer Name, den app.js aus core.js holt,
 * schlägt fehl, solange der Browser noch die alte core.js liefert - und ein
 * fehlgeschlagener Import reisst die ganze App mit. Genau das ist passiert.
 */
function trackViewport() {
    const vv = window.visualViewport;
    if (!vv) return;   // ältere Browser behalten 100svh

    const wurzel = document.documentElement;

    const anpassen = () => {
        const hoehe = Math.round(vv.height);

        /*
         * Der zweite Wert ist der entscheidende. iOS scrollt beim Fokus die
         * Layout-Ansicht, nicht den Container - ein fixiertes Element hängt
         * danach genau um diesen Betrag über dem sichtbaren Rand. offsetTop
         * sagt, um wie viel, und holt es wieder herunter.
         */
        wurzel.style.setProperty('--vvh', `${hoehe}px`);
        wurzel.style.setProperty('--vvtop', `${Math.round(vv.offsetTop)}px`);

        // Deutlich kleiner als das Fenster heißt: Die Tastatur ist offen.
        // Der Schwellwert liegt über allem, was Adressleisten ausmachen.
        document.body.classList.toggle('keyboard-open', window.innerHeight - hoehe > 140);
    };

    vv.addEventListener('resize', anpassen);
    vv.addEventListener('scroll', anpassen);
    anpassen();
}

// Muss vor dem ersten View laufen: --vvh steht sonst beim Aufbau noch nicht.
trackViewport();

window.addEventListener('hashchange', route);

// Erststart: ohne Session direkt zum Login.
if (!VT.user && currentPath() !== '/login') {
    go('/login', true);
}
route();

// Service Worker nur unter HTTPS bzw. localhost registrieren.
if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost')) {
    window.addEventListener('load', () => {
        navigator.serviceWorker
            // updateViaCache: 'none' ist hier das Entscheidende. Ohne das holt
            // der Browser auch sw.js aus seinem Zwischenspeicher - und ein
            // Service Worker, der sich nie erneuert, liefert die alten
            // Ansichten weiter aus, so oft man auch neu lädt.
            .register(`${VT.base}/sw.js`, { scope: `${VT.base}/`, updateViaCache: 'none' })
            .catch((err) => console.warn('Service Worker nicht registriert:', err));
    });
}

// ------------------------------------------------------------ Aktualisierung

const UPDATE_INTERVAL = 5 * 60 * 1000;

/**
 * Fragt in Abständen nach, ob auf dem Server etwas Neues liegt.
 *
 * Die installierte App wird selten wirklich beendet - sie liegt wochenlang im
 * Hintergrund und merkt von einer Aktualisierung sonst gar nichts. Deshalb
 * wird nicht nur nach der Uhr gefragt, sondern vor allem dann, wenn sie wieder
 * in den Vordergrund kommt. Die API läuft nie über den Zwischenspeicher, die
 * Antwort ist also verlässlich die des Servers.
 */
function watchForUpdate() {
    if (!VT.version) return;

    let gemeldet = false;

    const nachsehen = async () => {
        if (gemeldet || document.visibilityState === 'hidden') return;

        try {
            const { version } = await api('meta', 'version');
            if (version && version !== VT.version) {
                gemeldet = true;
                showUpdateBar();
            }
        } catch {
            // Offline oder Serverproblem - beim nächsten Mal wieder.
        }
    };

    document.addEventListener('visibilitychange', nachsehen);
    window.addEventListener('focus', nachsehen);
    setInterval(nachsehen, UPDATE_INTERVAL);
}

/** Band, das von oben hereinfährt. Bleibt stehen, bis jemand darauf reagiert. */
function showUpdateBar() {
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
        hardRefresh();
    });

    document.getElementById('update-later').addEventListener('click', () => {
        bar.classList.remove('show');
        setTimeout(() => bar.remove(), 300);
    });
}

watchForUpdate();
