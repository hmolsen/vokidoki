/*
 * Kleinigkeiten im Lehrkraft-Bereich, ohne Rahmenwerk.
 *
 * Zwei Dinge: das Auswahlfeld für die Sprache und der Kursname, der sich von
 * selbst ergibt. Beides liesse sich auch mit einem <select> und einem
 * Textfeld erschlagen - aber ein <select> mit hundert Einträgen durchsucht
 * man nicht, und ein Textfeld, das fast immer denselben Wert enthält, will
 * niemand ausfüllen.
 */

// ---------------------------------------------------------------- Rückfrage

document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-confirm]');
    if (b && !confirm(b.dataset.confirm)) e.preventDefault();
});

// ------------------------------------------------------ Sprachen-Auswahlfeld

/**
 * Ein durchsuchbares Auswahlfeld.
 *
 * Der eigentliche Wert steht in einem versteckten Feld - das Formular sendet
 * also ganz gewöhnlich, auch wenn hier etwas schiefginge. Ohne JavaScript
 * bleibt die Liste als <select> sichtbar und ist bedienbar; das ist der
 * Grund, warum sie im HTML steht und nicht hier.
 */
function initLanguagePicker(root) {
    const select = root.querySelector('select[data-picker]');
    if (!select) return;

    const options = Array.from(select.options).map((o) => ({
        name: o.value,
        flag: o.dataset.flag || '',
        top:  o.dataset.top === '1',
    }));
    if (options.length === 0) return;

    // Das <select> weicht der eigenen Bedienung, bleibt aber im Formular.
    select.hidden = true;
    select.setAttribute('aria-hidden', 'true');
    select.tabIndex = -1;

    const box = document.createElement('div');
    box.className = 'picker';
    box.innerHTML = `
        <button type="button" class="pickbtn" aria-haspopup="listbox" aria-expanded="false">
            <span class="pickflag"></span>
            <span class="picklabel"></span>
            <span class="pickchev">&#9662;</span>
        </button>
        <div class="pickpanel" hidden>
            <input type="text" class="picksearch" placeholder="Sprache suchen..."
                   autocomplete="off" aria-label="Sprache suchen">
            <ul class="picklist" role="listbox"></ul>
        </div>`;
    select.after(box);

    const btn    = box.querySelector('.pickbtn');
    const panel  = box.querySelector('.pickpanel');
    const search = box.querySelector('.picksearch');
    const list   = box.querySelector('.picklist');
    const flagEl = box.querySelector('.pickflag');
    const nameEl = box.querySelector('.picklabel');

    let gefiltert = options;
    let aktiv     = 0;

    const setzen = (name) => {
        const o = options.find((x) => x.name === name) || options[0];
        select.value = o.name;
        flagEl.textContent = o.flag;
        nameEl.textContent = o.name;
    };

    /*
     * Ohne Umlaute vergleichen - und zwar in beiden Schreibweisen.
     *
     * Wer keine Umlaute tippt, schreibt mal "danisch" und mal "daenisch".
     * Nur eine Form zu falten trifft die andere nicht: "dänisch" wird zu
     * "daenisch", und darin steckt "danisch" nicht. Deshalb zwei Formen und
     * ein Treffer, wenn eine von beiden passt.
     */
    const ohnePunkte = (s) => s.toLowerCase()
        .replace(/ß/g, 'ss')
        .normalize('NFD').replace(/[̀-ͯ]/g, '');

    const ausgeschrieben = (s) => s.toLowerCase()
        .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
        .normalize('NFD').replace(/[̀-ͯ]/g, '');

    const passt = (name, suche) =>
        ohnePunkte(name).includes(ohnePunkte(suche))
        || ausgeschrieben(name).includes(ausgeschrieben(suche));

    const zeichnen = () => {
        const suche = search.value.trim();
        gefiltert = suche === ''
            ? options
            : options.filter((o) => passt(o.name, suche));

        if (aktiv >= gefiltert.length) aktiv = Math.max(0, gefiltert.length - 1);

        if (gefiltert.length === 0) {
            list.innerHTML = '<li class="pickempty">Nichts gefunden - '
                + 'der Name lässt sich auch frei eintragen.</li>';
            return;
        }

        let html = '';
        gefiltert.forEach((o, i) => {
            // Der Strich trennt die fünf oben vom Rest - aber nur, solange
            // nicht gesucht wird. Beim Suchen zaehlt der Treffer, nicht die Gruppe.
            const trenner = suche === '' && !o.top && i > 0 && gefiltert[i - 1].top;
            html += `${trenner ? '<li class="picksep" role="presentation"></li>' : ''}
                <li role="option" data-i="${i}" aria-selected="${i === aktiv}"
                    class="${i === aktiv ? 'on' : ''}">
                    <span class="pickflag">${o.flag}</span>${escapeHtml(o.name)}
                </li>`;
        });
        list.innerHTML = html;

        const el = list.querySelector('.on');
        if (el) el.scrollIntoView({ block: 'nearest' });
    };

    const oeffnen = () => {
        panel.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
        search.value = '';
        aktiv = Math.max(0, options.findIndex((o) => o.name === select.value));
        zeichnen();
        search.focus();
    };

    const schliessen = () => {
        panel.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
    };

    const waehlen = (i) => {
        if (!gefiltert[i]) return;
        setzen(gefiltert[i].name);
        schliessen();
        btn.focus();
        select.dispatchEvent(new Event('change', { bubbles: true }));
    };

    btn.addEventListener('click', () => (panel.hidden ? oeffnen() : schliessen()));
    search.addEventListener('input', () => { aktiv = 0; zeichnen(); });

    search.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown') { e.preventDefault(); aktiv = Math.min(aktiv + 1, gefiltert.length - 1); zeichnen(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); aktiv = Math.max(aktiv - 1, 0); zeichnen(); }
        else if (e.key === 'Enter') { e.preventDefault(); waehlen(aktiv); }
        else if (e.key === 'Escape') { e.preventDefault(); schliessen(); btn.focus(); }
    });

    list.addEventListener('click', (e) => {
        const li = e.target.closest('li[data-i]');
        if (li) waehlen(Number(li.dataset.i));
    });

    document.addEventListener('click', (e) => {
        if (!panel.hidden && !box.contains(e.target)) schliessen();
    });

    setzen(select.value || options[0].name);
}

// ------------------------------------------------------------ Kursname

/**
 * Der Name ergibt sich aus Sprache und Klasse und steht als Text da.
 *
 * Ein Eingabefeld, das fast immer denselben Wert enthält, ist eine Aufgabe
 * ohne Entscheidung. Wer doch etwas anderes will, klickt auf den Stift -
 * dann wird aus dem Text ein Feld, und ab da bleibt es dabei: Was jemand von
 * Hand geschrieben hat, darf ihm die Sprachwahl nicht wieder wegnehmen.
 */
function initCourseName(root) {
    const anzeige = root.querySelector('[data-coursename]');
    const feld    = root.querySelector('input[name="name"]');
    const stift   = root.querySelector('[data-editname]');
    const select  = root.querySelector('select[data-picker]');
    const klasse  = root.querySelector('select[name="class_id"]');
    if (!anzeige || !feld || !stift) return;

    let vonHand = false;

    const bauen = () => {
        if (vonHand) return;
        const sprache = select ? select.value : '';
        const k = klasse && klasse.selectedOptions[0]
            ? (klasse.selectedOptions[0].dataset.name || '')
            : '';
        const name = k === '' ? sprache : `${sprache} - ${k}`;
        anzeige.textContent = name;
        feld.value = name;
    };

    if (select) select.addEventListener('change', bauen);
    if (klasse) klasse.addEventListener('change', bauen);

    stift.addEventListener('click', () => {
        vonHand = true;
        anzeige.hidden = true;
        stift.hidden = true;
        feld.hidden = false;
        feld.focus();
        feld.select();
    });

    bauen();
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
}

document.querySelectorAll('[data-coursform]').forEach((f) => {
    initLanguagePicker(f);
    initCourseName(f);
});
