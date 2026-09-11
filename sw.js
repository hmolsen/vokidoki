/* Service Worker.
   Bewusst zurückhaltend: Nur statische Dateien werden gecacht. HTML und die
   API laufen immer über das Netz - eine gecachte Shell könnte sonst den
   Namen des falschen Kindes anzeigen, weil index.php pro Account rendert. */

const CACHE = 'vokabeltrainer-v3';

/**
 * Im Voraus wird nur die Offline-Seite abgelegt.
 *
 * Alles andere trägt einen Versionsstempel in der Adresse (app.js?v=...), den
 * der Service Worker hier gar nicht kennen kann - ein Vorrat ohne Stempel ging
 * an den tatsächlich angefragten Adressen vorbei und wurde nie benutzt. Die
 * Dateien landen stattdessen beim ersten Abruf im Zwischenspeicher, und ein
 * neuer Stempel führt von selbst zu einem neuen Eintrag.
 */
const ASSETS = ['./offline.html'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            .then((cache) => cache.addAll(ASSETS))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)),
            ))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    // API, Manifest und Icons nie aus dem Cache beantworten.
    if (url.pathname.includes('/api/')
        || url.pathname.endsWith('/manifest.php')
        || url.pathname.endsWith('/icon.php')) {
        return;
    }

    // Seitenaufrufe: immer frisch aus dem Netz, offline die Hinweisseite.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match('./offline.html')),
        );
        return;
    }

    // Statische Dateien: aus dem Cache liefern, im Hintergrund erneuern.
    event.respondWith(
        caches.match(request).then((cached) => {
            const network = fetch(request)
                .then((response) => {
                    if (response.ok) {
                        const copy = response.clone();
                        caches.open(CACHE).then((cache) => cache.put(request, copy));
                    }
                    return response;
                })
                .catch(() => cached);
            return cached || network;
        }),
    );
});
