/* Router und Einstiegspunkt.
   Hash-Routing, damit die App ohne Rewrite-Regeln in jedem Unterverzeichnis
   eines Shared-Hostings läuft. */

import {
    VT, go, render, notice, api, hardRefresh, navQuelle, navAbmelden, serieQuelle,
} from './core.js';
import { loginView } from './views/login.js';
import { languagesView } from './views/languages.js';
import { languageView } from './views/language.js';
import { importView } from './views/import.js';
import { unitView } from './views/unit.js';
import { quizView } from './views/quiz.js';
import { clozeView } from './views/cloze.js';
import { profileView } from './views/profile.js';
import { freiView, freiWahlView } from './views/frei.js';
import {
    vorratAuffrischen, vorratLaden, vorratAlter, vorratVergessen,
    sprachen, einheit, serieHeute,
} from './vorrat.js';

/*
 * Woher die Leiste ihre Kurse nimmt - und was beim Abmelden wegzuraeumen
 * ist.
 *
 * core.js baut die beiden Schubladen, kennt den Vorrat aber nicht: vorrat.js
 * holt sich von dort VT und api(), ein Import in die andere Richtung waere
 * ein Ring. Also reicht app.js die beiden Faeden herein - hier, wo ohnehin
 * alles zusammenlaeuft.
 */
navQuelle(() => ({ kurse: sprachen(), aktiv: aktiverKurs() }));
navAbmelden(async () => { vorratVergessen(); });

/*
 * Und woher das Abzeichen in der Leiste seine Zahl nimmt.
 *
 * Gerechnet wird bei jedem Zeichnen neu und nicht einmal beim Start: Die
 * installierte App liegt wochenlang im Hintergrund, und zwischen zwei
 * Blicken auf den Bildschirm kann Mitternacht liegen. Eine beim Start
 * festgehaltene Lage stuende dann noch auf "heute schon gelernt", obwohl
 * der Tag laengst ein anderer ist.
 */
serieQuelle(() => serieHeute());

/**
 * Welcher Kurs gerade offen ist - damit er im Menue markiert steht.
 *
 * Nicht nur auf der Kursseite: Wer in einer Lerneinheit oder mitten im
 * Ueben steht, ist genauso in einem Kurs, und ein Menue, das das vergisst,
 * markiert ausgerechnet dort nichts, wo man am tiefsten drin ist.
 */
function aktiverKurs() {
    const pfad = currentPath();

    const direkt = pfad.match(/^\/lang\/(\d+)/);
    if (direkt) return Number(direkt[1]);

    const ueber = pfad.match(/^\/(?:unit|quiz|cloze)\/(\d+)/);
    if (ueber) return einheit(ueber[1])?.l ?? null;

    return null;
}

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
    // Freies Üben, von einer Lerneinheit aus gestartet - siehe unten.
    [/^\/unit\/(\d+)\/frei$/,     (id) => freiView(id, `/unit/${id}`)],
    [/^\/quiz\/(\d+)$/,           quizView],
    [/^\/cloze\/(\d+)$/,          clozeView],
    /*
     * Freies Ueben. Zwei Wege hinein: von einer Lerneinheit direkt
     * (#/unit/12/frei, oben) und vom Kurs ueber die Auswahl, die dann eine
     * oder mehrere Kennungen mitbringt (#/frei/12-13-15).
     *
     * Die Auswahl steht in der Adresse und nicht in einer Variablen: So
     * ueberlebt eine Runde das Neuladen, und der Zurueck-Pfeil des Browsers
     * fuehrt dorthin, wo man war. Aus demselben Grund steht auch darin,
     * woher man kam: Beide Wege hiessen einmal #/frei/12, und der Pfeil
     * oben links fuehrte deshalb auch von der Lerneinheit aus in den Kurs.
     */
    [/^\/frei\/waehlen\/(\d+)$/,  freiWahlView],
    [/^\/frei\/([\d-]+)$/,        freiView],
    [/^\/konto$/,                 profileView],
    // Dieselbe Seite, aber gleich beim Passwort: Der Lehrkraft-Bereich hat
    // dafuer einen eigenen Knopf, und "erst suchen, dann tippen" ist kein
    // Weg, den man zweimal geht.
    [/^\/konto\/passwort$/,        () => profileView(true)],
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

/**
 * Der Vorrat, und zwar bevor die erste Ansicht ihn braucht.
 *
 * Ist schon einer da, wird sofort gezeichnet und im Hintergrund auf den
 * neuesten Stand gebracht - so sieht ein Kind seine Kacheln ohne Warten,
 * auch ohne Netz. Ist noch keiner da (erste Anmeldung, neues Geraet), muss
 * einmal gewartet werden; danach nie wieder.
 *
 * VORRAT_FRISCH gilt nur fuer die Rueckkehr aus dem Hintergrund: Die
 * installierte App liegt wochenlang da und kommt zwanzigmal am Tag nach
 * vorn; jedesmal nachzufragen waere Laerm ohne Gewinn.
 *
 * BEIM KALTSTART WIRD IMMER NACHGESEHEN, und zwar aus einem Grund, der
 * eine Fehlermeldung wert war: Eine Lehrkraft gibt Vokabeln frei und
 * drueckt "So sieht es die Klasse" - und sah dort ihren eigenen Vorrat von
 * vor vier Minuten, also die Freigabe von vorhin. Das sieht aus, als haette
 * das Freigeben nicht gewirkt.
 *
 * Gewartet wird darauf nicht: Was im Geraet liegt, wird sofort gezeichnet,
 * und wenn sich etwas geaendert hat, zeichnet route() gleich noch einmal.
 */
const VORRAT_FRISCH = 5 * 60;

async function vorratBereit() {
    if (!VT.user) return;

    if (vorratLaden() === null) {
        await vorratAuffrischen();
        return;
    }

    vorratAuffrischen().then((frisch) => {
        // Kam etwas Neues, die aktuelle Ansicht noch einmal aufbauen -
        // sonst stuende die frisch freigegebene Lerneinheit erst beim
        // naechsten Antippen da.
        if (frisch) route();
    });
}

await vorratBereit();
route();

/*
 * Und wenn die App aus dem Hintergrund zurueckkommt: Die installierte App
 * wird selten wirklich beendet, sie liegt wochenlang da. Ohne das saehe ein
 * Kind eine Freigabe von heute morgen erst naechste Woche.
 */
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible' || !VT.user) return;
    if (vorratAlter() > VORRAT_FRISCH) {
        vorratAuffrischen().then((frisch) => { if (frisch) route(); });
    }
});

/*
 * Service Worker nur in einem sicheren Kontext registrieren.
 *
 * Hier stand "https: oder localhost" - eine Aufzaehlung, die genau das
 * nachbaut, was der Browser selbst schon weiss, und dabei 127.0.0.1
 * vergisst. Der ist ebenfalls sicher, und ohne ihn liess sich der Kaltstart
 * gar nicht pruefen: Die Browser-Suite laeuft unter dieser Adresse.
 */
if ('serviceWorker' in navigator && window.isSecureContext) {
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
