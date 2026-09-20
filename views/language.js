import { VT, render, esc, on, go, topbar, wireBack, progressBar, flagHtml,
         showError, pupilHint } from '../core.js';
import { sprache, einheitenDerSprache, einheitStatistik } from '../vorrat.js';

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
 * Fuer ein Kind mit Einlese-Recht bleibt es: In einer Familie ist genau das
 * der Weg, und es gibt dort niemanden, der Kurse verwaltet. Dieselbe Regel
 * wie darfAnlegen() in der Kachelliste.
 */
function selbstEinlesen() {
    return VT.user.canImport && !VT.user.isTeacher;
}

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
        flag: roh.flag_emoji, courseId: roh.course_id ?? null,
    };
    const units = einheitenDerSprache(languageId).map((u) => einheitStatistik(u.i));

    /*
     * Gesamtfortschritt über beide Übungsarten.
     *
     * Gezählt wird in Schritten: Jede Vokabel bringt einen fürs Auswählen mit
     * und einen zweiten fürs Einsetzen, sofern sie einen Lückensatz hat.
     * Vorher zählte hier nur das Auswählen - der Balken stand auf voll,
     * während im Lückentext noch alles offen war.
     */
    const schritte = units.reduce((s, u) => s + u.steps_total, 0);
    const getan    = units.reduce((s, u) => s + u.steps_done, 0);
    const prozent  = schritte > 0 ? Math.round((getan / schritte) * 100) : 0;

    const mcKnown    = units.reduce((s, u) => s + u.known, 0);
    const mcTotal    = units.reduce((s, u) => s + u.total, 0);
    const clozeKnown = units.reduce((s, u) => s + u.cloze_known, 0);
    const clozeTotal = units.reduce((s, u) => s + u.cloze_total, 0);

    const rows = units.map((u) => `
        <button class="row" data-unit="${u.id}">
            <span class="lead">${u.done ? '\u{2705}' : '\u{1F4DA}'}</span>
            <span class="body">
                <span class="title">${esc(u.title)}</span>
                <span class="tiny muted">${u.percent} % gelernt</span>
                ${progressBar(u.steps_done, u.steps_total)}
            </span>
            <span class="chev">&#8250;</span>
        </button>
    `).join('');

    render(`
        ${topbar(language.label || language.name,
                 { backTo: '/', lead: flagHtml(language.flag, 'flag lead') })}
        <div id="msg"></div>
        ${pupilHint('Das ist die Ansicht deines Kurses, wie ein Kind sie hat.',
                    language.courseId
                        ? `/teacher/course.php?id=${language.courseId}`
                        : '/teacher/')}

        ${units.length > 0 ? `
            <div class="card">
                <div class="tiny muted">Insgesamt gelernt</div>
                <strong class="bigpercent">${prozent}&thinsp;%</strong>
                ${progressBar(getan, schritte)}
                <div class="tiny muted" style="margin-top:8px">
                    Auswählen ${mcKnown}/${mcTotal}
                    &middot; Lückentext ${clozeKnown}/${clozeTotal}
                </div>
            </div>` : ''}

        ${selbstEinlesen() ? `
            <button class="row" data-go="/lang/${language.id}/import">
                <span class="lead">\u{1F4F7}</span>
                <span class="body">
                    <span class="title">Vokabeln einlesen</span>
                    <span class="tiny muted">Buchseite fotografieren und automatisch erfassen</span>
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
            <h2 class="section">Lerneinheiten</h2>
            ${rows}
        `}
    `);

    wireBack();
    on('[data-unit]', 'click', (e) => go(`/unit/${e.currentTarget.dataset.unit}`));
    on('[data-go]', 'click', (e) => go(e.currentTarget.dataset.go));
}
