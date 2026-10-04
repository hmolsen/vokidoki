/*
 * Lernstatistik: die Serie im Kalender, und was sonst über das eigene Üben
 * zu wissen ist.
 *
 * Die Serie stand im Konto, zwischen Name und Passwort - dort sucht sie
 * niemand, und die Karte hinter dem Abzeichen erklärte sie dafür in einer
 * ganzen Seite Text. Jetzt sagt die Karte kurz, was heute fehlt, und führt
 * hierher; hier steht der Kalender, die Regeln unter "Mehr erfahren", und
 * ein paar Zahlen dazu.
 *
 * NUR FÜR DAS KIND. Alles hier wird im Gerät aus dem eigenen Vorrat
 * gerechnet (lernstatistik() in vorrat.js); der Lehrkraft-Bereich zeigt
 * nichts davon - nicht einmal, ob ein Kind die App benutzt. Das steht
 * oben auf der Seite, damit es niemand vermuten muss.
 */

import { esc, $, render, topbar, wireBack, serieBalkenHtml, flagHtml } from '../core.js';
import { serie, serieHeute, serieTage, heute, tagZaehlt, lernstatistik } from '../vorrat.js';

/* Die Übungsarten in der Reihenfolge, in der sie in der Lerneinheit stehen. */
const UEBUNGEN = [
    ['mc',     '\u{1F3AF}',        'Auswählen'],
    ['pick',   '\u{1F9E9}',        'Einsetzen'],
    ['cloze',  '\u{270F}\u{FE0F}', 'Lückentext'],
    ['listen', '\u{1F3A7}',        'Hören'],
];

const zahl = (n) => Number(n).toLocaleString('de-DE');
const quote = (r, f) => (r + f === 0 ? '–' : `${Math.round((r / (r + f)) * 100)}\u{202F}%`);

export function lernstatistikView() {
    const st = lernstatistik();
    const s  = serieHeute();

    render(`
        ${topbar('Lernstatistik', { backTo: '/' })}
        <div id="msg"></div>

        <div class="notice good nurdu">
            <span aria-hidden="true">\u{1F512}</span>
            <span><strong>Nur für dich.</strong> Diese Zahlen siehst nur du -
            deine Lehrkraft sieht sie nicht.</span>
        </div>

        <h2 class="section">Deine Serie</h2>
        <div class="card">
            ${serieBalkenHtml(s)}

            <div class="monatskopf">
                <button class="iconbtn" type="button" id="monat-zurueck"
                        aria-label="Voriger Monat">&#8249;</button>
                <strong id="monat-name"></strong>
                <button class="iconbtn" type="button" id="monat-vor"
                        aria-label="Nächster Monat">&#8250;</button>
            </div>

            <div class="monatsgitter" id="monatsgitter"></div>

            <div class="serielegende">
                <span><i class="serietag voll"></i> Tag geschafft</span>
                <span><i class="serietag halb"></i> geübt, aber zu wenig</span>
                <span><i class="serietag"></i> nichts geübt</span>
            </div>

            <details class="mehrerfahren">
                <summary class="btn secondary small">Mehr erfahren</summary>
                <p class="mkopf klein">So bekommst du einen Tag</p>
                <ul class="serieregel">
                    <li>Eine neue Vokabel lernen - dreimal hintereinander richtig, dann sitzt sie.</li>
                    <li>Oder alte wiederholen: Ab ${s.schwelle ?? 10} richtigen Antworten
                        zählt der Tag auch. So bleibt deine Serie am Leben, wenn gerade
                        nichts Neues aufgegeben ist.</li>
                </ul>
                <p class="mkopf klein">Wenn du mal keine Zeit hast</p>
                <p class="serietext">
                    Einen Tag darfst du auslassen, die Serie läuft weiter. Lässt du
                    zwei Tage hintereinander aus, fängt sie wieder bei null an.
                </p>
                <p class="mkopf klein">Was in den Kästchen steht</p>
                <p class="serietext">
                    Die Zahl der richtigen Antworten an diesem Tag - auch die aus
                    dem Freien Üben.
                </p>
            </details>
        </div>

        ${st === null ? '' : zahlenHtml(st)}
    `);

    wireBack();
    kalenderAktivieren();
}

/** Die übrigen Zahlen: insgesamt, je Übung, die letzten dreissig Tage, je Kurs. */
function zahlenHtml(st) {
    const ueb = UEBUNGEN
        .filter(([m]) => st.modi[m] && (m !== 'listen' || st.modi[m].richtig + st.modi[m].falsch > 0))
        .map(([m, zeichen, name]) => {
            const x = st.modi[m];
            return `
                <tr>
                    <td><span aria-hidden="true">${zeichen}</span> ${esc(name)}</td>
                    <td class="num">${zahl(x.gekonnt)}</td>
                    <td class="num">${zahl(x.richtig)}</td>
                    <td class="num">${quote(x.richtig, x.falsch)}</td>
                </tr>`;
        }).join('');

    const t = st.tage30;
    const kurse = st.kurse.filter((k) => k.vokabeln > 0).map((k) => {
        const anteil = Math.round((k.gekonnt / k.vokabeln) * 100);
        return `
            <div class="kursstand">
                <div class="kursstand-kopf">
                    <span class="kursstand-name">${flagHtml(k.flagge || '🌐', 'kursflagge')} ${esc(k.name)}</span>
                    <span class="tiny muted">${zahl(k.gekonnt)} von ${zahl(k.vokabeln)}</span>
                </div>
                <div class="bar"><i style="width:${anteil}%"></i></div>
            </div>`;
    }).join('');

    return `
        <h2 class="section">Auf einen Blick</h2>
        <div class="statkacheln">
            <div class="statkachel"><b>${zahl(st.gekonnt)}</b><span>Vokabeln gekonnt</span></div>
            <div class="statkachel"><b>${zahl(t.lerntage)}</b><span>Lerntage in 30 Tagen</span></div>
            <div class="statkachel"><b>${zahl(t.richtig)}</b><span>Richtige in 30 Tagen</span></div>
            <div class="statkachel"><b>${zahl(t.neu)}</b><span>Neu gelernt in 30 Tagen</span></div>
        </div>
        ${t.bester ? `<p class="tiny muted center">Dein bester Tag: ${esc(tagLesbar(t.bester.tag))}
            mit ${zahl(t.bester.richtig)} richtigen Antworten.</p>` : ''}

        <h2 class="section">Je Übung</h2>
        <div class="card tabellenkarte">
            <table class="lernzahlen">
                <tr><th>Übung</th><th class="num">gekonnt</th><th class="num">richtig</th><th class="num">Treffer</th></tr>
                ${ueb}
            </table>
            <p class="tiny muted" style="margin:10px 0 0">
                „Gekonnt" heißt: dreimal hintereinander richtig. Das Freie Üben zählt
                für die Serie, aber nicht hier - dort wird nur wiederholt.
            </p>
        </div>

        ${kurse ? `<h2 class="section">Je Kurs</h2><div class="card">${kurse}</div>` : ''}`;
}

const MONATE = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli',
                'August', 'September', 'Oktober', 'November', 'Dezember'];

/**
 * Den Kalender zeichnen und die beiden Pfeile verdrahten.
 *
 * Gerechnet wird durchweg in UTC. Die Zeitumstellung macht einen Tag 23 oder
 * 25 Stunden lang, und ein Kalender, der im Oktober einen Tag verliert, ist
 * schlimmer als keiner.
 */
function kalenderAktivieren() {
    const gitter = $('#monatsgitter');
    if (!gitter) return;

    const s     = serie();
    const jetzt = heute();
    const tage  = new Map(serieTage().map((t) => [t.d, t]));

    const [jJ, jM] = jetzt.split('-').map(Number);
    const ende = jJ * 12 + (jM - 1);              // der laufende Monat

    /*
     * So weit zurueck geht es: zwoelf Monate - oder bis zu dem Monat, in dem
     * das Konto entstanden ist, wenn das spaeter war. In Monate zu blaettern,
     * in denen es das Konto noch gar nicht gab, sieht aus wie ein Fehler.
     */
    const [sJ, sM] = (s.seit ?? jetzt).split('-').map(Number);
    const anfang = Math.max(ende - (s.monate ?? 12), sJ * 12 + (sM - 1));

    let zeigt = ende;

    const zeichnen = () => {
        const jahr  = Math.floor(zeigt / 12);
        const monat = zeigt % 12;                 // 0 = Januar

        $('#monat-name').textContent = `${MONATE[monat]} ${jahr}`;
        $('#monat-zurueck').disabled = zeigt <= anfang;
        $('#monat-vor').disabled     = zeigt >= ende;

        const imMonat = new Date(Date.UTC(jahr, monat + 1, 0)).getUTCDate();
        // Montag als erste Spalte: getUTCDay() zaehlt ab Sonntag.
        const versatz = (new Date(Date.UTC(jahr, monat, 1)).getUTCDay() + 6) % 7;

        const zellen = [];
        for (let i = 0; i < versatz; i++) {
            zellen.push('<div class="monatstag leer"></div>');
        }

        const zwei = (n) => String(n).padStart(2, '0');
        for (let t = 1; t <= imMonat; t++) {
            const tag = `${jahr}-${zwei(monat + 1)}-${zwei(t)}`;
            const e   = tage.get(tag);
            const l   = e?.l ?? 0;
            const c   = e?.c ?? 0;

            const klassen = ['monatstag'];
            if (tagZaehlt(l, c)) klassen.push('voll');
            else if (c > 0)      klassen.push('halb');
            if (tag === jetzt)   klassen.push('heute');
            if (tag > jetzt)     klassen.push('spaeter');

            const was = tagZaehlt(l, c)
                ? (l > 0 ? `${c} richtige Antworten, ${l} neue Vokabel${l === 1 ? '' : 'n'}`
                         : `${c} richtige Antworten`)
                : (c > 0 ? `${c} richtige Antworten - zu wenig für den Tag`
                         : 'nichts geübt');

            /*
             * Im Kasten steht die Zahl der richtigen Antworten - bis zu
             * dreistellig. Die Nummer des Tages steht NICHT darin: Sie
             * ergibt sich aus der Stelle im Gitter, und zwei Zahlen in einem
             * Kaestchen von dieser Groesse liest niemand mehr.
             */
            zellen.push(
                `<div class="${klassen.join(' ')}" title="${esc(tagLesbar(tag))}: ${esc(was)}">`
                + `${c > 0 ? esc(String(Math.min(c, 999))) : ''}</div>`,
            );
        }

        gitter.innerHTML = zellen.join('');
    };

    $('#monat-zurueck').addEventListener('click', () => {
        if (zeigt > anfang) { zeigt--; zeichnen(); }
    });
    $('#monat-vor').addEventListener('click', () => {
        if (zeigt < ende) { zeigt++; zeichnen(); }
    });

    zeichnen();
}

/** 2026-09-21 wird zu 21.09.2026 - so steht es auf jedem Zettel in der Schule. */
function tagLesbar(tag) {
    const [j, m, t] = tag.split('-');
    return `${t}.${m}.${j}`;
}
