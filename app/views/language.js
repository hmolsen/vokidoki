import { VT, render, esc, on, go, topbar, wireBack, progressBar, flagHtml,
         showError, lernansicht } from '../core.js';
import {
    sprache, einheitenDerSprache, einheitStatistik,
    MODUS_WAHL, MODUS_EINSETZEN, MODUS_LUECKE, MODUS_HOEREN,
} from '../vorrat.js';
import { hantel } from './frei.js';

/**
 * Startseite einer Sprache: einlesen und üben auf einer Ebene.
 *
 * Früher stand hier ein Punkt "Üben", hinter dem sich die Liste der
 * Lerneinheiten verbarg - als einziger Eintrag einer eigenen Seite. Ein
 * Menüpunkt, der nur zu einer Liste führt, ist ein Klick ohne Entscheidung;
 * die Liste steht jetzt direkt hier.
 */
/**
 * Wer liest hier selbst ein?
 *
 * Eine Lehrkraft nicht - jedenfalls nicht von hier aus. Diese Seite ist die
 * Ansicht ihrer Klasse, und die soll genau das sein: Was ein Kind nicht
 * sieht, steht auch fuer sie nicht da. Sonst probiert sie eine Seite aus,
 * die es so gar nicht gibt.
 *
 * Eingelesen wird aus ihrem Bereich heraus - "+ Lerneinheit" auf der
 * Kurskarte und "Vokabeln einlesen" im Kurs fuehren in dieselbe Ansicht.
 *
 * Fuer ein Kind mit Einlese-Recht bleibt es: Wem die Lehrkraft das Einlesen
 * erlaubt hat, der geht genau hier entlang - einen Lehrkraft-Bereich hat es
 * nicht. Dieselbe Regel wie darfAnlegen() in der Kachelliste.
 */
function selbstEinlesen() {
    return VT.user.canImport && !VT.user.isTeacher;
}

/* Die Übungen in der Reihenfolge der Lerneinheit, wie sie unter dem Balken stehen. */
const UEBUNGEN = [
    [MODUS_WAHL, 'Auswählen'],
    [MODUS_EINSETZEN, 'Einsetzen'],
    [MODUS_LUECKE, 'Lückentext'],
    [MODUS_HOEREN, 'Hören'],
];

export async function languageView(languageId) {
    /*
     * Aus dem Vorrat statt vom Server. Kein Ladepunkt, kein Warten - und
     * das Ganze funktioniert auch im Zug.
     */
    const roh = sprache(languageId);
    if (roh === null) {
        render(`${topbar('Kurs', { backTo: '/' })}<div id="msg"></div>`);
        wireBack();
        showError('Diesen Kurs gibt es nicht - oder er ist noch nicht geladen.');
        return;
    }

    /*
     * Der Kursname steht nur da, wenn er etwas unterscheidet - dieselbe
     * Regel wie auf der Kachel, und api/bundle.php hat sie schon angewandt.
     */
    const language = {
        id: roh.id, name: roh.name, label: roh.name,
        flag: roh.flag_emoji,
    };
    const units = einheitenDerSprache(languageId).map((u) => einheitStatistik(u.i));

    /*
     * Gesamtfortschritt über alle Übungen, in Punkten (einheitStatistik()):
     * drei je Vokabel und Übung. Abgerundet wie dort - 100 % erst, wenn
     * alles gekonnt ist.
     */
    const moeglich = units.reduce((s, u) => s + u.moeglich, 0);
    const punkte   = units.reduce((s, u) => s + u.punkte, 0);
    const prozent  = moeglich === 0 ? 0
        : (punkte >= moeglich ? 100 : Math.floor((punkte / moeglich) * 100));

    // Darunter je Übung, wie viele Vokabeln dort gekonnt sind.
    const jeUebung = UEBUNGEN
        .map(([m, name]) => [name, units.reduce((s, u) => s + u.modi[m].known, 0),
                             units.reduce((s, u) => s + u.modi[m].total, 0)])
        .filter(([, , gesamt]) => gesamt > 0)
        .map(([name, gekonnt, gesamt]) => `${name} ${gekonnt}/${gesamt}`)
        .join(' &middot; ');

    const rows = units.map((u) => `
        <button class="row" data-unit="${u.id}">
            <span class="lead">${u.done ? '\u{2705}' : '\u{1F4DA}'}</span>
            <span class="body">
                <span class="title">${esc(u.title)}</span>
                <span class="tiny muted">${u.percent} % gelernt</span>
                ${progressBar(u.punkte, u.moeglich)}
            </span>
            <span class="chev">&#8250;</span>
        </button>
    `).join('');

    render(`
        ${lernansicht()}
        ${topbar(language.label || language.name,
                 { backTo: '/', lead: flagHtml(language.flag, 'flag lead') })}
        <div id="msg"></div>


        ${units.length > 0 ? `
            <div class="card">
                <div class="tiny muted">Insgesamt gelernt</div>
                <strong class="bigpercent">${prozent}&thinsp;%</strong>
                ${progressBar(punkte, moeglich)}
                <div class="tiny muted" style="margin-top:8px">${jeUebung}</div>
            </div>` : ''}

        ${selbstEinlesen() ? `
            <button class="row" data-go="/lang/${language.id}/import">
                <span class="lead">\u{1F4F7}</span>
                <span class="body">
                    <span class="title">Vokabeln einlesen</span>
                    <span class="tiny muted">Vokabelliste fotografieren und automatisch erfassen</span>
                </span>
                <span class="chev">&#8250;</span>
            </button>` : ''}

        ${units.length === 0 ? `
            <div class="empty">
                <span class="big">\u{1F4D6}</span>
                ${selbstEinlesen()
                    ? 'Fotografiere deine erste Vokabelseite,<br>dann kann es losgehen.'
                    : 'Hier ist noch nichts freigegeben.<br>Deine Lehrkraft macht die erste Lektion auf.'}
            </div>
        ` : `
            <!--
                Freies Ueben ueber mehrere Lerneinheiten. Es steht ueber der
                Liste, weil es die ganze Liste betrifft - und darunter waere
                es bei zwanzig Lerneinheiten nicht mehr zu finden.
            -->
            <button class="row" data-go="/frei/waehlen/${language.id}">
                <span class="lead">${hantel('hantel lead')}</span>
                <span class="body">
                    <span class="title">Freies Üben</span>
                    <span class="tiny muted">Mehrere Lerneinheiten zusammen wiederholen</span>
                </span>
                <span class="chev">&#8250;</span>
            </button>

            <h2 class="section">Lerneinheiten</h2>
            ${rows}
        `}
    `);

    wireBack();
    on('[data-unit]', 'click', (e) => go(`/unit/${e.currentTarget.dataset.unit}`));
    on('[data-go]', 'click', (e) => go(e.currentTarget.dataset.go));
}
