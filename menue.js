/*
 * Die beiden Schubladen - links die Navigation, rechts das eigene Konto.
 *
 * Steht für sich, weil es zwei Aufrufer hat, die sonst nichts gemeinsam
 * haben: den Lehrkraft-Bereich (ein gewöhnliches Skript, das sich das hier
 * per import() nachlädt) und die App (ein Modul). Zwei Abschriften wären
 * bald zwei verschiedene Menüs - und ein Kind und seine Lehrkraft sollen
 * dieselbe Bewegung sehen.
 *
 * Gebaut auf <details>: Das Auf- und Zuklappen kann der Browser von selbst,
 * mit Tastatur und Vorleseprogramm, und ohne JavaScript funktioniert es
 * genauso - nur ohne das Hereinschieben und ohne den Schleier, der sich
 * wegklicken lässt.
 */

// ------------------------------------------------------------- Hell, dunkel

/** Was jemand gewählt hat: 'hell', 'dunkel' oder 'auto'. */
export const THEMA_SCHLUESSEL = 'vt-thema';

export function themaLesen() {
    try {
        const w = localStorage.getItem(THEMA_SCHLUESSEL);
        return (w === 'hell' || w === 'dunkel') ? w : 'auto';
    } catch {
        return 'auto';
    }
}

/**
 * Die Wahl anwenden - und merken.
 *
 * 'auto' nimmt das Attribut weg; dann entscheidet die Medienabfrage im
 * Stilblatt, also das Gerät. Genau dafür ist sie die Voreinstellung: Wer
 * sein Telefon abends auf dunkel stellt, meint die App mit.
 */
export function themaSetzen(wahl) {
    const wurzel = document.documentElement;

    if (wahl === 'hell' || wahl === 'dunkel') {
        wurzel.dataset.theme = wahl === 'dunkel' ? 'dark' : 'light';
    } else {
        delete wurzel.dataset.theme;
    }

    try {
        if (wahl === 'auto') localStorage.removeItem(THEMA_SCHLUESSEL);
        else localStorage.setItem(THEMA_SCHLUESSEL, wahl);
    } catch { /* privates Fenster: dann gilt es eben nur für diesen Besuch */ }
}

/**
 * Die drei Knöpfe verdrahten.
 *
 * Die Auswahl steht als Markierung am gewählten Knopf, nicht als Häkchen
 * daneben: Drei Knöpfe nebeneinander, einer ist an - das liest man im
 * Vorbeigehen.
 */
export function themaWahlAktivieren(wurzel = document) {
    const knoepfe = [...wurzel.querySelectorAll('[data-thema]')];
    if (knoepfe.length === 0) return;

    const zeigen = () => {
        const jetzt = themaLesen();
        knoepfe.forEach((k) => {
            const an = k.dataset.thema === jetzt;
            k.classList.toggle('on', an);
            k.setAttribute('aria-pressed', an ? 'true' : 'false');
        });
    };

    knoepfe.forEach((k) => {
        k.addEventListener('click', (e) => {
            e.preventDefault();
            themaSetzen(k.dataset.thema);
            zeigen();
        });
    });

    zeigen();
}

/**
 * Das Markup für die drei Knöpfe.
 *
 * Als Zeichenkette und nicht als DOM-Bau, weil beide Aufrufer ihre Menüs
 * als Text zusammensetzen - der eine in PHP, der andere in JavaScript.
 * Diese Fassung ist für die App; teacher/_boot.php schreibt dieselbe in
 * PHP, und eine Prüfung hält beide zusammen.
 */
export function themaWahlHtml() {
    return `
        <p class="mkopf klein">Farben</p>
        <div class="themawahl" role="group" aria-label="Helligkeit">
            <button type="button" class="themaknopf" data-thema="hell">
                <span aria-hidden="true">&#9728;&#65039;</span> Hell
            </button>
            <button type="button" class="themaknopf" data-thema="dunkel">
                <span aria-hidden="true">&#127769;</span> Dunkel
            </button>
            <button type="button" class="themaknopf" data-thema="auto">
                <span aria-hidden="true">&#128241;</span> Automatisch
            </button>
        </div>`;
}

// ------------------------------------------------------- Welche Ansicht

/**
 * Der Umschalter zwischen Verwaltung und Lernansicht.
 *
 * Nur für eine Lehrkraft - einem Kind sagt er nichts, und es gibt für es
 * auch keine Verwaltung.
 *
 * Als Zeichenkette und nicht als DOM-Bau, aus demselben Grund wie die
 * Farbwahl darüber: Beide Aufrufer setzen ihre Menüs als Text zusammen,
 * der eine in PHP, der andere in JavaScript. Diese Fassung ist für die
 * App; teacher/_boot.php schreibt dieselbe in PHP, und eine Prüfung hält
 * beide zusammen.
 *
 * @param verwaltungUrl Wohin "Verwaltung" führt - die Entsprechung der
 *                      Seite, auf der man gerade steht.
 */
export function ansichtWahlHtml(verwaltungUrl) {
    return `
        <p class="mkopf klein">Ansicht</p>
        <div class="ansichtwahl" role="group" aria-label="Ansicht">
            <a class="ansichtknopf" href="${verwaltungUrl}">
                <span aria-hidden="true">&#128203;</span> Verwaltung
            </a>
            <span class="ansichtknopf on" aria-current="page">
                <span aria-hidden="true">&#128065;</span> Lernansicht
            </span>
        </div>`;
}

// ------------------------------------------------------------ Auf und zu

/**
 * Beide Schubladen bedienbar machen.
 *
 * @param wurzel Worin gesucht wird. Die App zeichnet ihre Leiste bei jedem
 *               Wechsel neu, also wird das hier auch jedes Mal gerufen.
 */
export function menueAktivieren(wurzel = document) {
    const menues = [...wurzel.querySelectorAll('details.menue')];
    if (menues.length === 0) return;

    const teile = (m) => [m.querySelector('.schublade'), m.querySelector('.schleier')]
        .filter(Boolean);

    /*
     * Vor jedem Öffnen die Animation zurücksetzen.
     *
     * Sie lief sonst genau einmal je Seite. Ein geschlossenes <details>
     * nimmt seinen Inhalt inzwischen nicht mehr aus dem Baum, sondern
     * versteckt ihn per content-visibility: Das Element bleibt dasselbe,
     * seine Animation ist abgelaufen, und wieder sichtbar zu werden ist
     * kein Grund, von vorn anzufangen. Beim zweiten Öffnen stand die
     * Schublade darum einfach da.
     *
     * animation: none, ein erzwungener Umbruch, dann zurück auf die Regel
     * aus dem Stilblatt - das ist der Weg, eine CSS-Animation neu zu
     * starten, und er ist so alt wie CSS-Animationen.
     */
    const oeffnen = (m) => {
        menues.forEach((a) => { if (a !== m && a.open) schliessen(a, true); });
        m.classList.remove('zu');
        m.open = true;
        teile(m).forEach((el) => {
            el.style.animation = 'none';
            void el.offsetWidth;
            el.style.animation = '';
        });
    };

    /*
     * Und wieder hinaus - das kann <details> von sich aus nicht.
     *
     * open = false nimmt den Inhalt sofort weg; es gibt dann nichts mehr,
     * was hinausfliegen könnte. Also erst .zu setzen, die Animation
     * abwarten und dann schliessen. Läuft keine (reduzierte Bewegung, oder
     * ein Browser, der getAnimations nicht kennt), passiert es sofort -
     * eine Schublade, die hängenbleibt, wäre schlimmer als eine, die
     * springt.
     */
    const schliessen = (m, sofort = false) => {
        if (!m.open || m.dataset.schliesst === '1') return;

        const fertig = () => {
            m.classList.remove('zu');
            delete m.dataset.schliesst;
            m.open = false;
        };

        if (sofort) { fertig(); return; }

        m.dataset.schliesst = '1';
        m.classList.add('zu');

        const laeuft = teile(m).flatMap((el) => el.getAnimations?.() ?? []);
        if (laeuft.length === 0) { fertig(); return; }
        Promise.all(laeuft.map((a) => a.finished)).then(fertig, fertig);
    };

    menues.forEach((m) => {
        /*
         * Zweimal verdrahten wäre schlimmer als gar nicht.
         *
         * Normalerweise kommt das nicht vor: Die App zeichnet ihre Leiste
         * bei jedem Wechsel neu, und ein frisch gebautes <details> hat noch
         * keinen Hörer. Das Abzeichen der Serie erneuert sich aber mitten
         * im Üben an Ort und Stelle, ohne dass die Leiste neu entsteht -
         * und danach lief diese Schleife ein zweites Mal über dieselben
         * Menüs. Zwei Hörer an einem Knopf heisst: aufklappen und sofort
         * wieder zu. Nach ein paar richtigen Antworten ging das Menü gar
         * nicht mehr auf.
         */
        if (m.dataset.verdrahtet === '1') return;
        m.dataset.verdrahtet = '1';

        /*
         * Den Klick auf den Knopf selbst übernehmen: Der Browser würde
         * open sofort umlegen, und damit wäre das Zuklappen vorbei, bevor
         * es angefangen hat. Ohne Skript bleibt genau dieses Umlegen der
         * Weg - hier wird es nur aufgeschoben, nicht ersetzt.
         */
        m.querySelector('summary')?.addEventListener('click', (e) => {
            e.preventDefault();
            if (m.open) schliessen(m); else oeffnen(m);
        });
        m.querySelector('[data-zu]')?.addEventListener('click', () => schliessen(m));
    });

    /*
     * Escape schliesst. Am Dokument und nicht an der Wurzel: Der Finger
     * kann beim Tippen überall stehen, und die App zeichnet ihre Leiste
     * bei jedem Wechsel neu - ein Hörer je Leiste wäre nach zehn
     * Ansichten zehnmal da.
     */
    if (!document.documentElement.dataset.menueEscape) {
        document.documentElement.dataset.menueEscape = '1';
        document.addEventListener('keydown', (e) => {
            if (e.key !== 'Escape') return;
            const offen = [...document.querySelectorAll('details.menue')].find((m) => m.open);
            if (!offen) return;
            offen.classList.add('zu');
            const laeuft = [offen.querySelector('.schublade'), offen.querySelector('.schleier')]
                .filter(Boolean).flatMap((el) => el.getAnimations?.() ?? []);
            const fertig = () => { offen.classList.remove('zu'); offen.open = false; };
            if (laeuft.length === 0) fertig();
            else Promise.all(laeuft.map((a) => a.finished)).then(fertig, fertig);
            offen.querySelector('summary')?.focus();
        });
    }
}
