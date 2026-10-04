import { VT, render, esc, on, go, topbar, wireBack, progressBar, flagHtml,
         showError, lernansicht } from '../core.js';
import {
    sprache, einheitenDerSprache, einheitStatistik, prozentVon,
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
    [MODUS_WAHL, '\u{1F3AF}', 'Auswählen'],
    [MODUS_EINSETZEN, '\u{1F9E9}', 'Einsetzen'],
    [MODUS_LUECKE, '\u{270F}\u{FE0F}', 'Lückentext'],
    [MODUS_HOEREN, '\u{1F3A7}', 'Hören'],
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
    const prozent  = prozentVon(punkte, moeglich);

    /*
     * Aufgeklappt: je Übung ihr Anteil, nach derselben Punkteregel wie das
     * Ganze - die vier Balken ergeben zusammen den grossen. Darunter, wie
     * viele Vokabeln dort gekonnt sind. Vorher stand das in einer Zeile
     * ("Auswählen 12/177 · Einsetzen 24/177 ..."), die niemand las.
     */
    const jeUebung = UEBUNGEN.map(([m, zeichen, name]) => {
        const p = units.reduce((s, u) => s + u.jeUebung[m].punkte, 0);
        const g = units.reduce((s, u) => s + u.jeUebung[m].moeglich, 0);
        const gekonnt = units.reduce((s, u) => s + u.modi[m].known, 0);
        const gesamt  = units.reduce((s, u) => s + u.modi[m].total, 0);
        if (g === 0) return '';
        return `
            <div class="ue" data-uebung="${m}">
                <span class="ue-z" aria-hidden="true">${zeichen}</span>
                <span class="ue-n">${esc(name)}</span>
                <span class="ue-p">${prozentVon(p, g)}&thinsp;%</span>
                <div class="ue-bar">${progressBar(p, g)}</div>
                <span class="ue-k tiny muted">${gekonnt} von ${gesamt} gekonnt</span>
            </div>`;
    }).join('');

    /*
     * Die Lerneinheiten in einer Karte, Zeile an Zeile: Titel links, der
     * Anteil rechts in einer festen Spalte, der Balken darunter. Vorher
     * stand vor jeder ein Bücherstapel - bei zwanzig Lerneinheiten zwanzig
     * Mal dasselbe Bild - und "51 % gelernt" klebte am Titel und wanderte
     * mit dessen Länge. Geschafft heisst: ein Haken statt "100 %".
     */
    const rows = units.map((u) => `
        <button class="einheitzeile" data-unit="${u.id}">
            <span class="ez-t">${esc(u.title)}</span>
            <span class="ez-p${u.done ? ' fertig' : ''}"${u.done ? ' aria-label="geschafft"' : ''}>${
                u.done ? '\u{2713}' : `${u.percent}&thinsp;%`}</span>
            <div class="ez-bar">${progressBar(u.punkte, u.moeglich)}</div>
        </button>
    `).join('');

    render(`
        ${lernansicht()}
        ${topbar(language.label || language.name,
                 { backTo: '/', lead: flagHtml(language.flag, 'flag lead') })}
        <div id="msg"></div>


        ${units.length > 0 ? `
            <details class="card gesamt">
                <summary>
                    <span class="tiny muted">Insgesamt gelernt</span>
                    <span class="g-zeile">
                        <strong class="bigpercent">${prozent}&thinsp;%</strong>
                        <span class="g-auf tiny muted"><span class="g-zu">Je Übung</span><span class="g-offen">Weniger</span>
                            <span class="g-pfeil" aria-hidden="true">&#8250;</span></span>
                    </span>
                    ${progressBar(punkte, moeglich)}
                </summary>
                <div class="ue-liste">${jeUebung}</div>
            </details>` : ''}

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
            <!-- Aus Fehlern lernen: dieselbe Auswahl, die schwächsten Aufgaben (frei.js). -->
            <button class="row" data-go="/fehler/waehlen/${language.id}">
                <span class="lead" aria-hidden="true">\u{1FA79}</span>
                <span class="body">
                    <span class="title">Aus Fehlern lernen</span>
                    <span class="tiny muted">Was dir am schwersten fällt - bis es sitzt</span>
                </span>
                <span class="chev">&#8250;</span>
            </button>

            <h2 class="section">Lerneinheiten</h2>
            <div class="card einheitenliste">${rows}</div>
        `}
    `);

    wireBack();
    on('[data-unit]', 'click', (e) => go(`/unit/${e.currentTarget.dataset.unit}`));
    on('[data-go]', 'click', (e) => go(e.currentTarget.dataset.go));
}
