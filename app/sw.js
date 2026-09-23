/* Service Worker.
   Zurückhaltend, aber nicht mehr hilflos: Die API läuft immer über das Netz,
   der Seitenrahmen wird gecacht. */

const CACHE = 'vokabeltrainer-v5';

/*
 * Der Rahmen der Seite, unter einem festen Namen.
 *
 * Hier stand einmal, HTML dürfe gar nicht in den Cache: index.php rendert
 * pro Konto, und eine gespeicherte Seite könnte den Namen des falschen
 * Kindes anzeigen. Das stimmt - ändert aber nichts, denn wer das Gerät
 * offline in die Hand bekommt, ist ohnehin noch als das vorige Kind
 * angemeldet: Das Sitzungs-Cookie liegt im Gerät und gilt weiter. Der
 * Rahmen im Cache macht kein Fenster auf, das nicht schon offen wäre.
 *
 * Und ohne ihn geht gar nichts ohne Netz: Der Vorrat kann noch so
 * vollständig sein - wenn die erste Seite nicht lädt, sieht ein Kind im
 * Zug nur "keine Verbindung".
 *
 * Beim Abmelden wird er weggeworfen, zusammen mit dem Vorrat.
 */
const RAHMEN = './?rahmen';

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

    /*
     * Seitenaufrufe: frisch aus dem Netz, und eine Kopie zur Seite legen.
     *
     * Netz zuerst, nicht Cache zuerst - so sieht ein Kind nach einer
     * Aktualisierung sofort die neue Fassung. Nur wenn der Abruf umfällt,
     * kommt der gespeicherte Rahmen zum Zug, und wenn es auch den nicht
     * gibt, die Hinweisseite.
     *
     * Gespeichert wird unter einem festen Namen und nicht unter der
     * angefragten Adresse: Die App ist eine einzige Seite, ihre Wege stehen
     * hinter dem #. Unter der Adresse gespeichert lägen dort am Ende ein
     * Dutzend identischer Kopien - und ausgerechnet die eine, die jemand
     * offline aufruft, wäre nicht dabei.
     */
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((antwort) => {
                    if (antwort.ok) {
                        const kopie = antwort.clone();
                        caches.open(CACHE).then((cache) => cache.put(RAHMEN, kopie));
                    }
                    return antwort;
                })
                .catch(async () => (await caches.match(RAHMEN))
                                ?? caches.match('./offline.html')),
        );
        return;
    }

    /*
     * Und der Weg, den Rahmen wieder loszuwerden. Beim Abmelden ruft die
     * App diese Adresse auf; der Service Worker räumt und antwortet.
     * Über postMessage ginge es auch, aber dann bräuchte es einen
     * Nachrichtenkanal für genau eine Sache.
     */
    if (url.searchParams.has('rahmen-weg')) {
        event.respondWith(
            caches.open(CACHE)
                .then((cache) => cache.delete(RAHMEN))
                .then(() => new Response('weg', { headers: { 'Content-Type': 'text/plain' } })),
        );
        return;
    }

    const merken = (response) => {
        if (response.ok) {
            const copy = response.clone();
            caches.open(CACHE).then((cache) => cache.put(request, copy));
        }
        return response;
    };

    /*
     * Code zuerst aus dem Netz, erst dann aus dem Cache.
     *
     * Vorher galt auch hier "aus dem Cache, im Hintergrund erneuern". Für
     * Bilder ist das richtig, für Module war es gefährlich: Nur app.js trägt
     * einen Versionsstempel in der Adresse, core.js und die Ansichten werden
     * ohne importiert. Nach einem Update traf deshalb eine frische app.js auf
     * eine alte core.js, der Import eines neuen Namens schlug fehl, und die
     * App blieb komplett weiß. Ein zusammengehörender Satz Dateien ist mehr
     * wert als die eingesparte Millisekunde.
     */
    if (/\.(js|css)$/.test(url.pathname)) {
        event.respondWith(
            fetch(request).then(merken).catch(() => caches.match(request)),
        );
        return;
    }

    // Alles Übrige: aus dem Cache liefern, im Hintergrund erneuern.
    event.respondWith(
        caches.match(request).then((cached) => {
            const network = fetch(request).then(merken).catch(() => cached);
            return cached || network;
        }),
    );
});
