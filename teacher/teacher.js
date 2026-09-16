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
    let   flagEl = box.querySelector('.pickflag');
    const nameEl = box.querySelector('.picklabel');

    let gefiltert = options;
    let aktiv     = 0;

    const setzen = (name) => {
        const o = options.find((x) => x.name === name) || options[0];
        select.value = o.name;
        flagEl.outerHTML = fahnenBild(o.flag, 'pickflag') || '<span class="pickflag"></span>';
        flagEl = box.querySelector('.pickflag');
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
                    ${fahnenBild(o.flag, 'pickflag')}${escapeHtml(o.name)}
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

    /*
     * Die Klasse steht fest.
     *
     * Kurse entstehen jetzt in der Klasse, nicht mehr auf einer eigenen
     * Kursseite - auszuwaehlen gibt es sie also nicht mehr, und ihr Name
     * reist am Formular mit. Das alte Auswahlfeld wird trotzdem noch
     * gelesen, falls irgendwo eines stehen bleibt.
     */
    const klasse  = root.querySelector('select[name="class_id"]');
    const fest    = root.querySelector('form[data-classname]')?.dataset.classname
                 ?? document.querySelector('form[data-classname]')?.dataset.classname
                 ?? '';
    if (!anzeige || !feld || !stift) return;

    let vonHand = false;

    const bauen = () => {
        if (vonHand) return;
        const sprache = select ? select.value : '';
        const k = klasse && klasse.selectedOptions[0]
            ? (klasse.selectedOptions[0].dataset.name || '')
            : fest;
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

/**
 * Eine Fahne als Bild.
 *
 * Auf dem Handy sieht das Emoji gut aus, unter Windows steht an seiner
 * Stelle das Laenderkuerzel - aus der britischen Fahne wird "GB". Daran
 * aendert keine Schriftart etwas, also bringen wir das Bild mit; es liegt
 * in assets/flags, benannt nach den Unicode-Stellen.
 *
 * Fehlt die Datei, tritt weiter unten das Emoji an ihre Stelle - dann sieht
 * es aus wie vorher.
 */
function fahnenBild(emoji, klasse = 'cflag') {
    const text = String(emoji ?? '').trim();
    if (text === '') return '';

    const name = [...text]
        .map((z) => z.codePointAt(0))
        .filter((n) => n !== 0xfe0f)      // "bitte farbig" gehoert nicht zum Zeichen
        .map((n) => n.toString(16))
        .join('-');
    if (name === '') return '';

    const basis = document.body.dataset.base || '';
    return `<img class="${escapeHtml(klasse)}" src="${escapeHtml(basis)}/assets/flags/`
         + `${escapeHtml(name)}.svg" alt="" width="24" height="24"`
         + ` data-emoji="${escapeHtml(text)}">`;
}

// Fehlt die Datei, tritt das Emoji an ihre Stelle. Ein Hoerer fuers ganze
// Dokument statt ein onerror an jedem Bild; error steigt nicht auf, deshalb
// in der Abwaertsphase.
document.addEventListener('error', (e) => {
    const bild = e.target;
    if (!(bild instanceof HTMLImageElement) || !bild.dataset.emoji) return;

    const ersatz = document.createElement('span');
    ersatz.className   = bild.className;
    ersatz.textContent = bild.dataset.emoji;
    bild.replaceWith(ersatz);
}, true);

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

// --------------------------------------------------- Zeile als Knopf

/*
 * Eine ganze Zeile oeffnet den Eintrag.
 *
 * Vorher stand am Ende jeder Zeile ein Knopf "Öffnen" - eine Spalte, die
 * bei jeder Zeile dasselbe sagte, und auf dem Handy die Spalte, die am
 * meisten Platz frass. Der Name in der Zeile ist ohnehin ein Link; das hier
 * macht die Flaeche daneben mitklickbar.
 *
 * Bewusst nicht das ganze <tr> zu einem Link machen: Ein Link um
 * Tabellenzellen herum ist in HTML nicht erlaubt, und die Zeile enthaelt
 * auch Knoepfe, die etwas anderes tun.
 */
document.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-href]');
    if (!tr) return;

    // Was selbst schon etwas tut, behaelt seinen Klick: Links, Knoepfe,
    // Felder - und markierter Text ist ein Lesevorgang, kein Klick.
    if (e.target.closest('a, button, input, select, textarea, label')) return;
    if ((window.getSelection()?.toString() ?? '') !== '') return;

    const ziel = tr.dataset.href;
    if (e.metaKey || e.ctrlKey || e.button === 1) window.open(ziel, '_blank', 'noopener');
    else window.location.href = ziel;
});

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

    /*
     * Die Zeile bekommt dieselben Knoepfe wie die gewachsenen daneben.
     *
     * Vorher blieb die Aktionsspalte leer, und "Passwort" und "Zettel"
     * tauchten erst nach einem Neuladen auf - man trug fuenf Kinder ein und
     * hatte fuenf halbe Zeilen vor sich. Die Vorlagen stehen im HTML der
     * Seite, damit hier nichts nachgebaut wird, was dort schon steht: das
     * CSRF-Feld, die Adressen, die Beschriftungen.
     */
    const einhaengen = (kind) => {
        const tr = document.createElement('tr');
        tr.className = 'hit';
        tr.innerHTML = `
            <td data-label="Name">
                <span class="coursetitle">
                    <span class="cflag">&#128100;</span>
                    <span><strong></strong></span>
                </span>
            </td>
            <td data-label="Benutzername"><code class="token"></code></td>
            <td data-label="Anfangspasswort"><code class="token"></code></td>
            <td class="actions">${aktionen(kind)}</td>`;

        // Name, Benutzername und Passwort als Text setzen, nicht als Markup -
        // ein Kind namens "N'Diaye <3" darf die Tabelle nicht zerlegen.
        tr.querySelector('strong').textContent = kind.name;
        tr.querySelectorAll('code')[0].textContent = kind.username;
        tr.querySelectorAll('code')[1].textContent = kind.password;

        // Die Rueckfrage traegt den Namen - ebenfalls als Eigenschaft, nicht
        // in den Text hineingeschrieben.
        const pw = tr.querySelector('[name="reset_password"]');
        if (pw) pw.dataset.confirm =
            `Neues Anfangspasswort für ${kind.name}? Das alte gilt dann nicht mehr.`;

        tabelle.insertBefore(tr, zeile);
        zettelFreigeben();
    };

    /*
     * Der Zettel fuer die ganze Klasse wird brauchbar, sobald das erste Kind
     * da ist.
     *
     * Vorher entschied das PHP beim Ausliefern, und der Knopf erschien erst
     * beim naechsten Laden - ausgerechnet nachdem jemand seine Klassenliste
     * eingetippt hatte, war er nicht da. Jetzt steht er immer, abgeblendet,
     * und hier faellt die Sperre.
     */
    const zettelFreigeben = () => {
        const zettel = document.getElementById('zettelAlle');
        if (!zettel) return;
        zettel.classList.remove('aus');
        zettel.removeAttribute('aria-disabled');
        zettel.removeAttribute('tabindex');
        zettel.removeAttribute('title');
    };

    /** Die Knoepfe einer Zeile, aus den Vorlagen der Seite gebaut. */
    const aktionen = (kind) => {
        const csrf     = form.querySelector('input[name="csrf"]').value;
        const klasse   = form.querySelector('input[name="class_id"]').value;
        const zettelJe = form.dataset.printUser;

        const feld = (name, wert) =>
            `<input type="hidden" name="${name}" value="${escapeHtml(wert)}">`;

        return `
            <form method="post" class="compact">
                ${feld('csrf', csrf)}${feld('class_id', klasse)}
                <button class="iconaction quiet" name="reset_password"
                        value="${kind.id}" title="Neues Anfangspasswort">
                    <span aria-hidden="true">&#128273;</span> Passwort
                </button>
            </form>
            <a class="iconaction quiet" title="Zettel für dieses Kind drucken"
               href="${escapeHtml(zettelJe)}${kind.id}" target="_blank" rel="noopener">
                <span aria-hidden="true">&#128424;</span> Zettel
            </a>`;
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

    /*
     * Kein Fokus beim Laden.
     *
     * Die Kurse stehen jetzt oben, die Kinder darunter - ein Feld, das sich
     * den Fokus nimmt, scrollt die Seite an den Kurse vorbei. Nach dem
     * Abschicken bleibt der Fokus im Feld, und darum geht es beim
     * Nacheinander-Eintippen.
     */
}

initStudentAdd();

// ------------------------------------------- Sprung ans Telefon

/**
 * Der QR-Code, der die Anmeldung ersetzt.
 *
 * Er entsteht erst beim Druecken, nicht beim Laden der Seite: Die Marke
 * darin IST eine Anmeldung, und die soll nicht auf Vorrat erzeugt werden
 * und zehn Minuten lang auf einem unbeaufsichtigten Bildschirm liegen.
 *
 * Ohne JavaScript bleibt der Knopf wirkungslos - daneben steht deshalb
 * immer der gewoehnliche Weg: Einleseansicht oeffnen und sich am Telefon
 * anmelden.
 */
function initHandoff() {
    const dialog = document.getElementById('handoff');
    if (!dialog || typeof dialog.showModal !== 'function') return;

    const slot   = document.getElementById('handoffSlot');
    const hinweis = document.getElementById('handoffHint');
    const grund   = hinweis ? hinweis.textContent : '';

    document.querySelectorAll('[data-handoff]').forEach((knopf) => {
        knopf.addEventListener('click', async () => {
            slot.innerHTML = '<div class="spinner"></div>';
            if (hinweis) hinweis.textContent = grund;
            dialog.showModal();

            try {
                const daten = new FormData();
                daten.set('course_id', dialog.dataset.course);
                daten.set('csrf', dialog.dataset.csrf);

                const res  = await fetch(dialog.dataset.url, {
                    method: 'POST', body: daten, credentials: 'same-origin',
                });
                const json = await res.json();

                if (!json.ok) {
                    slot.textContent = '';
                    if (hinweis) hinweis.textContent = json.error || 'Das hat nicht geklappt.';
                    return;
                }
                // Der Server liefert fertiges SVG - es stammt aus lib/qr.php
                // und nicht aus einer Eingabe.
                slot.innerHTML = json.svg;
            } catch {
                slot.textContent = '';
                if (hinweis) hinweis.textContent = 'Keine Verbindung zum Server.';
            }
        });
    });

    // Beim Schliessen den Code wegnehmen: Ein offenes Fenster im Hintergrund
    // waere ein Schluessel, der liegen bleibt.
    dialog.addEventListener('close', () => { slot.innerHTML = ''; });
}

initHandoff();

// ------------------------------------------------------- Freigabe-Balken

/**
 * Die Freigabe als ein Balken, den man verschiebt.
 *
 * Vorher stand neben jeder Zeile ein Knopf "bis hier freigeben" - bei
 * hundert Vokabeln hundert Knoepfe, und keiner verriet vorher, was er
 * bewirkt. Jetzt liegt ein Balken zwischen der letzten freigegebenen und
 * der ersten gesperrten Vokabel. Wer ihn anfasst, zieht ihn dorthin, wo er
 * hin soll, sieht die Zeilen dabei umschlagen und liest in der Blase am
 * Griff mit, wie viele Vokabeln das waeren. Gespeichert wird beim
 * Loslassen.
 *
 * Ohne JavaScript passiert hier nichts, und die Knoepfe je Zeile bleiben
 * stehen - dieselbe Seite, nur umstaendlicher.
 */
function initReleaseBar() {
    const tabelle = document.getElementById('freigabe');
    const form    = document.getElementById('releaseform');
    if (!tabelle || !form) return;

    const zeilen = Array.from(tabelle.querySelectorAll('tr[data-pos]'));
    if (zeilen.length === 0) return;

    // Die Knoepfe je Zeile sind jetzt der Rueckfallweg und verschwinden.
    tabelle.querySelectorAll('.js-hide').forEach((b) => b.remove());
    tabelle.classList.add('draggable');

    /*
     * Der Balken liegt UEBER der Tabelle, nicht in ihr.
     *
     * Als eigene Tabellenzeile liess er sich um genau eine Vokabel
     * verschieben, und der Grund ist kein Rechenfehler, sondern eine Regel
     * der Zeigerbindung: Wird ein Element in der DOM umgehaengt - und
     * insertBefore haengt um -, verliert es seine Bindung an den Zeiger.
     * Beim ersten Schritt schob sich der Balken also zwischen zwei andere
     * Zeilen, gab dabei die Bindung ab, und alle weiteren Bewegungen
     * landeten auf den Tabellenzeilen statt auf dem Griff. Nachgemessen:
     * von dreissig pointermove kamen drei an, und pointerup kam nie an -
     * gespeichert wurde am Ende, was die angeklickte Zeile sagte.
     *
     * Dazu kam, dass die eingefuegte Zeile alle Zeilen darunter um ihre
     * eigene Hoehe nach unten schob: Die Tabelle bewegte sich unter dem
     * stillstehenden Zeiger, fast eine Zeilenhoehe weit.
     *
     * Ueber der Tabelle faellt beides weg. Die Bindung haelt, die Geometrie
     * steht still, und der Griff folgt dem Zeiger, so weit man will.
     */
    const huelle = document.createElement('div');
    huelle.className = 'releasewrap';
    tabelle.parentNode.insertBefore(huelle, tabelle);
    huelle.appendChild(tabelle);

    const start = Math.min(Number(tabelle.dataset.released || 0), zeilen.length);
    let stand   = start;
    let zieht   = false;
    let letztes = 0;        // zuletzt gesehene Zeigerhoehe, fuers Mitrollen
    let tempo   = 0;        // Rollschub am Fensterrand, 0 = steht

    const balken = document.createElement('div');
    balken.className = 'releasebar';
    balken.innerHTML = `
        <div class="line"></div>
        <div class="grip" tabindex="0" role="slider" aria-valuemin="0"
             aria-label="Freigabe verschieben"
             title="Ziehen: alles oberhalb ist freigegeben">
            <span aria-hidden="true">&#8942;&#8942;</span>
        </div>
        <div class="bubble"></div>`;
    huelle.appendChild(balken);

    const griff = balken.querySelector('.grip');
    const blase = balken.querySelector('.bubble');

    /*
     * Die Grenzen zwischen den Zeilen, gemessen von der Huelle aus.
     *
     * grenzen[i] ist die Hoehe, bei der genau i Vokabeln freigegeben waeren
     * - also die Oberkante von Zeile i, und ganz zuletzt die Unterkante der
     * letzten Zeile. Weil beides von derselben Huelle aus gemessen ist,
     * bleiben die Werte beim Scrollen gueltig.
     */
    let grenzen = [];
    const messen = () => {
        const h = huelle.getBoundingClientRect();
        grenzen = zeilen.map((tr) => tr.getBoundingClientRect().top - h.top);
        grenzen.push(zeilen[zeilen.length - 1].getBoundingClientRect().bottom - h.top);
    };

    /** Welche Grenze liegt dieser Hoehe am naechsten? */
    const naechste = (y) => {
        let beste = 0;
        let weite = Infinity;
        for (let i = 0; i < grenzen.length; i++) {
            const d = Math.abs(grenzen[i] - y);
            if (d < weite) { weite = d; beste = i; }
        }
        return beste;
    };

    /** Nur die Anzeige: Zeilen, Blase, Vorlesewerte. */
    const zeigen = (n, weich) => {
        stand = Math.max(0, Math.min(zeilen.length, n));

        zeilen.forEach((tr, i) => {
            const frei = i < stand;
            const war  = tr.classList.contains('released');
            tr.classList.toggle('released', frei);
            tr.classList.toggle('locked', !frei);
            // Nur umschlagen lassen, was sich wirklich aendert - sonst
            // flackert beim Ziehen die ganze Tabelle mit.
            if (weich && frei !== war) {
                tr.classList.remove('flip');
                void tr.offsetWidth;          // Neustart der Abfolge erzwingen
                tr.classList.add('flip');
            }
        });

        const text = stand === 0
            ? 'Nichts freigegeben'
            : stand >= zeilen.length
                ? `Alle ${zeilen.length} freigegeben`
                : `${stand} von ${zeilen.length} freigegeben`;

        griff.setAttribute('aria-valuemax', String(zeilen.length));
        griff.setAttribute('aria-valuenow', String(stand));
        griff.setAttribute('aria-valuetext', text);
        blase.textContent = text;
    };

    /** Nur die Lage: Der Balken haengt an dieser Hoehe. */
    const legen = (y) => { balken.style.top = Math.round(y) + 'px'; };

    /** Beides - der eingerastete Zustand. */
    const setzen = (n, weich) => { zeigen(n, weich); legen(grenzen[stand]); };

    /**
     * Waehrend des Ziehens.
     *
     * Der Balken folgt dem Zeiger auf den Pixel, nicht von Grenze zu
     * Grenze - das ist der Unterschied zwischen Schieben und Klicken. Die
     * Zeilen und die Zahl in der Blase richten sich dabei nach der
     * naechstgelegenen Grenze, so dass man vor dem Loslassen sieht, was
     * herauskommt. Eingerastet wird erst beim Loslassen.
     */
    const folgen = (clientY) => {
        const h = huelle.getBoundingClientRect();
        const y = Math.max(grenzen[0],
                  Math.min(grenzen[grenzen.length - 1], clientY - h.top));
        legen(y);
        zeigen(naechste(y), true);
    };

    const speichern = () => {
        if (stand === start) return;          // nichts geaendert

        const feld = document.createElement('input');
        feld.type  = 'hidden';
        feld.name  = 'release';
        feld.value = String(stand);
        form.appendChild(feld);
        form.submit();
    };

    // ---- Ziehen

    /*
     * Am Fensterrand wird mitgerollt. Ohne das waere bei hundert Vokabeln
     * Schluss, sobald der Bildschirm zu Ende ist - man kaeme nie von
     * Vokabel 5 zu Vokabel 80.
     */
    const RAND  = 72;       // Abstand zum Fensterrand, ab dem gerollt wird
    const SCHUB = 14;       // Pixel je Bild

    const rollen = () => {
        if (!zieht) return;
        if (tempo !== 0) {
            const vorher = window.scrollY;
            window.scrollBy(0, tempo);
            // Die Huelle hat sich mitbewegt, der Zeiger nicht: neu ausrichten.
            if (window.scrollY !== vorher) folgen(letztes);
        }
        requestAnimationFrame(rollen);
    };

    balken.addEventListener('pointerdown', (e) => {
        e.preventDefault();
        messen();
        zieht   = true;
        letztes = e.clientY;
        balken.setPointerCapture(e.pointerId);
        balken.classList.add('dragging');
        griff.focus({ preventScroll: true });
        folgen(e.clientY);
        requestAnimationFrame(rollen);
    });

    balken.addEventListener('pointermove', (e) => {
        if (!zieht) return;
        letztes = e.clientY;
        folgen(e.clientY);

        const hoehe = window.innerHeight;
        tempo = e.clientY < RAND         ? -SCHUB
              : e.clientY > hoehe - RAND ?  SCHUB
              : 0;
    });

    const loslassen = () => {
        if (!zieht) return;
        zieht = false;
        tempo = 0;
        balken.classList.remove('dragging');
        messen();
        setzen(stand, false);             // einrasten
        speichern();
    };

    balken.addEventListener('pointerup', loslassen);
    balken.addEventListener('pointercancel', loslassen);

    // ---- Tastatur: hoch, runter, Anfang, Ende - und Enter speichert.

    griff.addEventListener('keydown', (e) => {
        const schritt = { ArrowUp: -1, ArrowDown: 1, PageUp: -10, PageDown: 10 }[e.key];
        if (schritt !== undefined) {
            e.preventDefault();
            setzen(stand + schritt, true);
        } else if (e.key === 'Home') {
            e.preventDefault(); setzen(0, true);
        } else if (e.key === 'End') {
            e.preventDefault(); setzen(zeilen.length, true);
        } else if (e.key === 'Enter') {
            e.preventDefault(); speichern();
        }
    });

    // Ein Klick auf eine Zeile setzt den Balken dorthin - der kurze Weg,
    // wenn man schon weiss, wohin.
    zeilen.forEach((tr, i) => {
        tr.addEventListener('click', () => {
            if (zieht) return;
            setzen(i + 1, true);
            speichern();
        });
    });

    // Aendert sich das Layout - Fenstergroesse, nachgeladene Schrift -,
    // steht der Balken sonst zwischen zwei anderen Zeilen als vorher.
    const nachfuehren = () => {
        if (zieht) return;
        messen();
        legen(grenzen[stand]);
    };
    window.addEventListener('resize', nachfuehren);
    if (window.ResizeObserver) new ResizeObserver(nachfuehren).observe(tabelle);

    messen();
    setzen(start, false);
}

initReleaseBar();
