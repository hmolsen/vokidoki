/* Router und Einstiegspunkt.
   Hash-Routing, damit die App ohne Rewrite-Regeln in jedem Unterverzeichnis
   eines Shared-Hostings läuft. */

import { VT, go, render, notice, trackViewport } from './core.js';
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
            .register(`${VT.base}/sw.js`, { scope: `${VT.base}/` })
            .catch((err) => console.warn('Service Worker nicht registriert:', err));
    });
}
