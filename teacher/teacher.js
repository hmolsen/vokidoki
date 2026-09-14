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
            if (!panel.hidden) platzieren();
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

        // Beim Filtern schrumpft die Liste - dann muss das Panel neu sitzen,
        // sonst klebt es mit einem Eintrag darin noch in voller Hoehe da.
        if (!panel.hidden) platzieren();
    };

    /*
     * Das Panel liegt fest im Fenster, nicht im Fluss der Seite.
     *
     * Der Grund ist die Tabelle: Sie traegt overflow: hidden fuer ihre
     * runden Ecken, und das schneidet jedes Kind ab, das darueber
     * hinausragt. In der letzten Zeile - genau dort steht das Feld - war
     * die Liste damit halb weg. Ein festes Panel kennt keinen Vorfahren,
     * der es kappen koennte; dafuer muss die Lage von Hand nachgefuehrt
     * werden.
     */
    const platzieren = () => {
        const r     = btn.getBoundingClientRect();
        const rand  = 8;
        const breit = Math.max(r.width, 240);

        // Erst die Breite, dann messen: Die Hoehe haengt daran, wie viele
        // Eintraege umbrechen.
        panel.style.width = `${breit}px`;
        panel.style.left  = `${Math.max(rand, Math.min(r.left, window.innerWidth - breit - rand))}px`;

        const hoch = panel.offsetHeight || 300;

        // Passt es nach unten? Sonst darueber. Ein Feld am unteren Rand
        // klappt sonst aus dem Fenster heraus.
        const unten    = window.innerHeight - r.bottom;
        const nachOben = unten < hoch + rand && r.top > unten;

        if (nachOben) {
            panel.style.top = `${Math.max(rand, r.top - hoch - 4)}px`;
        } else {
            panel.style.top = `${r.bottom + 4}px`;
        }

        // Nie hoeher als der Platz, der da ist - sonst laeuft die Liste
        // unten aus dem Fenster.
        const platz = nachOben ? r.top - rand - 4 : unten - rand - 4;
        list.style.maxHeight = `${Math.max(120, Math.min(320, platz - 46))}px`;
    };

    const oeffnen = () => {
        panel.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
        search.value = '';
        aktiv = Math.max(0, options.findIndex((o) => o.name === select.value));
        zeichnen();
        platzieren();
        search.focus();

        // Beim Scrollen mitgehen. Das dritte Argument faengt auch Scrollen
        // in einem Kasten innerhalb der Seite ab, nicht nur am Fenster.
        window.addEventListener('scroll', platzieren, true);
        window.addEventListener('resize', platzieren);
    };

    const schliessen = () => {
        panel.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
        window.removeEventListener('scroll', platzieren, true);
        window.removeEventListener('resize', platzieren);
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

/*
 * Gesucht wird im ganzen Dokument, nicht im Formular.
 *
 * Die Felder stehen in der Tabellenzeile und gehoeren ueber form="newcourse"
 * dazu - ein Formular kann sich in HTML nicht ueber mehrere Zellen spannen.
 * Das Formular selbst ist damit leer, und ein querySelector darin faende
 * nichts.
 */
if (document.querySelector('[data-coursform]')) {
    initLanguagePicker(document);
    initCourseName(document);
}

// ------------------------------------------------------ Kinder nachtragen

/**
 * Ein Kind je Enter, ohne die Seite neu zu laden.
 *
 * Gedacht fuer den Fall, dass jemand mit einer Liste auf Papier davorsitzt:
 * Namen tippen, Enter, naechster Name. Die neue Zeile kommt vom Server -
 * Benutzername und Anfangspasswort entstehen dort, nicht hier -, wird
 * eingehaengt, und der Fokus bleibt im Feld.
 *
 * Ohne JavaScript schickt dasselbe Formular ganz gewoehnlich ab: Die Seite
 * laedt neu, die Zeile steht da, das Feld hat den Fokus. Dasselbe Ergebnis,
 * nur langsamer.
 */
function initStudentAdd() {
    const form = document.querySelector('form[data-addstudent]');
    const zeile = document.getElementById('neuesKind');
    if (!form || !zeile) return;

    const feld = document.querySelector('input[name="student"][form="newstudent"]');
    if (!feld) return;

    const tabelle = zeile.parentNode;
    let laeuft = false;

    const melden = (text, art) => {
        let box = document.getElementById('addmsg');
        if (!box) {
            box = document.createElement('div');
            box.id = 'addmsg';
            zeile.parentNode.parentNode.after(box);
        }
        box.className = text === '' ? '' : `notice ${art || ''}`;
        box.textContent = text;
    };

    const einhaengen = (kind) => {
        const tr = document.createElement('tr');
        tr.className = 'hit';
        tr.innerHTML = `
            <td>
                <span class="coursetitle">
                    <span class="cflag">&#128100;</span>
                    <span><strong></strong></span>
                </span>
            </td>
            <td><code class="token"></code></td>
            <td><code class="token"></code></td>
            <td class="actions"></td>`;
        // Die Werte als Text setzen, nicht als Markup - ein Name wie
        // "N'Diaye <3" darf die Tabelle nicht zerlegen.
        tr.querySelector('strong').textContent = kind.name;
        tr.querySelectorAll('code')[0].textContent = kind.username;
        tr.querySelectorAll('code')[1].textContent = kind.password;
        tabelle.insertBefore(tr, zeile);
    };

    const senden = async () => {
        const wert = feld.value.trim();
        if (wert === '' || laeuft) return;

        laeuft = true;
        feld.disabled = true;
        melden('');

        try {
            const daten = new FormData(form);
            daten.set('student', wert);
            daten.set('add_student', '1');

            const res = await fetch(form.action, {
                method: 'POST',
                body: daten,
                headers: { 'X-Requested-With': 'fetch' },
                credentials: 'same-origin',
            });
            const json = await res.json();

            if (!json.ok) {
                melden(json.error || 'Das hat nicht geklappt.', 'bad');
            } else {
                einhaengen(json.kind);
                feld.value = '';
            }
        } catch {
            // Bei einem Netzfehler lieber der gewoehnliche Weg als eine
            // Meldung, mit der niemand etwas anfangen kann.
            melden('Keine Verbindung - die Seite wird neu geladen.', 'bad');
            form.submit();
            return;
        } finally {
            laeuft = false;
            feld.disabled = false;
            feld.focus();
        }
    };

    // Enter im Feld schickt ab, ohne die Seite zu verlassen.
    feld.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            senden();
        }
    });

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        senden();
    });

    // Der Knopf gehoert ueber form= zum Formular und loest damit submit aus.
    feld.focus();
}

initStudentAdd();
