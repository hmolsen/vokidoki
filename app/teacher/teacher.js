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

// ------------------------------------------------------------------ Menues

/**
 * Die beiden Schubladen und die Farbwahl.
 *
 * Das Verhalten steht in menue.js - dieselbe Datei bedient die
 * Kinderansicht. Zwei Abschriften waeren bald zwei verschiedene Menues, und
 * ein Kind und seine Lehrkraft sollen dieselbe Bewegung sehen.
 *
 * Nachgeladen statt importiert: Diese Datei ist ein gewoehnliches Skript,
 * kein Modul. Die Adresse steht im HTML, weil ein dynamisches import() in
 * einem klassischen Skript nicht ueberall gleich aufgeloest wird.
 */
async function initMenues() {
    const leiste = document.querySelector('.adminbar');
    if (!leiste) return;

    const { menueAktivieren, themaWahlAktivieren } =
        await import(leiste.dataset.menue);

    menueAktivieren(document);
    themaWahlAktivieren(document);
}

initMenues();

// --------------------------------------------------------- Hoehe der Leiste

/**
 * Wie hoch die Leiste oben ist - als Mass fuer alles, was darunter klebt.
 *
 * Der Kopf der Freigabetabelle bleibt beim Rollen stehen; er muss dabei
 * unter der Leiste stehenbleiben, nicht hinter ihr. Wie hoch die ist,
 * weiss nur der Browser: Am Telefon bricht der Pfad um und sie wird
 * doppelt so hoch. Also messen statt schaetzen - und erneut messen, wenn
 * sich die Breite aendert.
 */
function initBarHoehe() {
    const leiste = document.querySelector('.adminbar');
    if (!leiste) return;

    const messen = () => document.documentElement.style.setProperty(
        '--barhoehe', leiste.offsetHeight + 'px');

    messen();
    window.addEventListener('resize', messen);
    if (window.ResizeObserver) new ResizeObserver(messen).observe(leiste);
}

initBarHoehe();

// ------------------------------------------------------ Sprachen-Auswahlfeld

/**
 * Ein durchsuchbares Auswahlfeld.
 *
 * Der eigentliche Wert steht in einem versteckten Feld - das Formular sendet
 * also ganz gewöhnlich, auch wenn hier etwas schiefginge. Ohne JavaScript
 * bleibt die Liste als <select> sichtbar und ist bedienbar; das ist der
 * Grund, warum sie im HTML steht und nicht hier.
 */
/*
 * Ohne Umlaute vergleichen - fuer jedes Suchfeld hier. - und zwar in beiden Schreibweisen.
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
 * Das durchsuchbare Sprachfeld, wo eines steht.
 *
 * Vorher hing der Start an einem Formular mit data-coursform - dem
 * Kursformular in der Anlegezeile der Klasse. Das gibt es nicht mehr; der
 * Kurs entsteht jetzt im Assistenten, und dort steht das Feld in einem
 * gewoehnlichen Formular. Also fragen wir nach dem, worum es geht: nach
 * dem Feld selbst.
 */
if (document.querySelector('select[data-picker]')) {
    initLanguagePicker(document);
}

// --------------------------------------------------- Wen aufnehmen?

/**
 * Das Suchfeld für die Kursaufnahme.
 *
 * Eine Schule hat dreihundert Kinder, und der Kurs braucht eines davon.
 * Vorher stand dort ein <datalist> - der Browser bietet seine Vorschläge
 * erst nach eigenem Gutdünken an, in eigener Gestalt, und auf dem Telefon
 * oft gar nicht. Jetzt sucht das Feld bei jedem Zeichen in der Liste der
 * Kinder, die noch nicht im Kurs sind, und zeigt die Treffer darunter.
 *
 * Bleibt genau einer übrig, verschwindet die Liste und der Rest des Namens
 * steht grau hinter dem Getippten - Enter nimmt ihn. Das Graue ist kein
 * Text im Feld, sondern ein zweites Element darunter: Ein Eingabefeld kann
 * nicht zwei Farben zugleich. Der getippte Teil wird darin unsichtbar
 * gesetzt, damit die Buchstaben nicht doppelt stehen.
 *
 * Ohne JavaScript bleibt das <datalist> im HTML und tut, was es immer tat.
 * Deshalb liest das Skript seine Namen auch von dort - eine Quelle, kein
 * zweiter Datenweg.
 */
function initMemberSearch() {
    const box = document.querySelector('[data-suche]');
    if (!box) return;

    const feld  = box.querySelector('input[name="member_name"]');
    const geist = box.querySelector('.geist');
    const liste = box.querySelector('.vorschlaege');
    const daten = document.getElementById(feld?.getAttribute('list') ?? '');
    if (!feld || !geist || !liste || !daten) return;

    /*
     * Name und Klasse. Der Wert bleibt der blosse Name - danach sucht der
     * Server -, die Klasse steht daneben in data-zusatz und wird in
     * Klammern dahinter gezeigt. Gesucht wird in beidem: "Marta W." gibt
     * es an einer Schule zweimal, "Marta W. (7b)" nicht, und wer die
     * Klasse kennt, aber den Namen nur halb, kommt ueber sie ans Ziel.
     */
    const leute = [...daten.options].map((o) => ({
        name:   o.value,
        zusatz: o.dataset.zusatz ?? '',
    }));
    const beschriftung = (l) => (l.zusatz === '' ? l.name : `${l.name} (${l.zusatz})`);

    // Die eigene Liste ersetzt die des Browsers - beide zugleich wären zwei
    // Vorschlagslisten übereinander.
    feld.removeAttribute('list');

    let gezeigt = [];
    let aktiv   = -1;
    let ergaenzung = '';

    /*
     * Die Liste haengt an keinem Vorfahren: position: fixed. Ihre Lage
     * muss deshalb von Hand gesetzt und beim Rollen mitgefuehrt werden -
     * sonst bleibt sie stehen, waehrend die Seite darunter wegfaehrt.
     * Dieselbe Loesung wie beim Sprachfeld, und aus demselben Grund:
     * table.data schneidet mit overflow: hidden jedes Kind ab.
     */
    const platzieren = () => {
        const r = feld.getBoundingClientRect();
        liste.style.left  = `${r.left}px`;
        liste.style.top   = `${r.bottom + 4}px`;
        liste.style.width = `${r.width}px`;
    };

    const schliessen = () => {
        liste.hidden = true;
        liste.innerHTML = '';
        feld.setAttribute('aria-expanded', 'false');
        gezeigt = [];
        aktiv = -1;
    };

    const mitfuehren = () => { if (!liste.hidden) platzieren(); };
    window.addEventListener('scroll', mitfuehren, true);
    window.addEventListener('resize', mitfuehren);

    const geistSetzen = (getippt, voll) => {
        ergaenzung = voll;
        if (voll === '') {
            geist.textContent = '';
            return;
        }
        // Der getippte Teil unsichtbar, damit der Rest an der richtigen
        // Stelle beginnt - und zwar in der Schrift des Feldes.
        geist.innerHTML = '';
        const vorn = document.createElement('i');
        vorn.textContent = getippt;
        geist.append(vorn, document.createTextNode(voll.slice(getippt.length)));
    };

    const zeichnen = () => {
        const roh = feld.value;
        const suche = roh.trim();

        if (suche === '') {
            geistSetzen('', '');
            schliessen();
            return;
        }

        const treffer = leute.filter((l) => passt(beschriftung(l), suche));

        /*
         * Genau einer, und er fängt mit dem Getippten an: Dann ist die
         * Liste überflüssig - der Name steht ja schon da, nur grau.
         */
        const eindeutig = treffer.length === 1
            && ohnePunkte(treffer[0].name).startsWith(ohnePunkte(suche));

        if (eindeutig) {
            geistSetzen(roh, roh + treffer[0].name.slice(suche.length));
            schliessen();
            return;
        }

        geistSetzen('', '');

        if (treffer.length === 0) {
            liste.innerHTML = '<li class="leertreffer">Niemand gefunden, der noch '
                            + 'nicht im Kurs ist.</li>';
            liste.hidden = false;
            platzieren();
            feld.setAttribute('aria-expanded', 'true');
            gezeigt = [];
            aktiv = -1;
            return;
        }

        gezeigt = treffer.slice(0, 8);
        aktiv = -1;
        liste.innerHTML = gezeigt.map((l, i) => `<li role="option" data-i="${i}"
            aria-selected="false">${escapeHtml(l.name)}<span class="zusatz">${
                l.zusatz === '' ? '' : ` (${escapeHtml(l.zusatz)})`
            }</span></li>`).join('');
        liste.hidden = false;
        platzieren();
        feld.setAttribute('aria-expanded', 'true');
    };

    const markieren = () => {
        [...liste.querySelectorAll('li[role=option]')].forEach((li, i) => {
            li.classList.toggle('on', i === aktiv);
            li.setAttribute('aria-selected', i === aktiv ? 'true' : 'false');
        });
    };

    const nehmen = (name) => {
        feld.value = name;
        geistSetzen('', '');
        schliessen();
        feld.form?.requestSubmit?.(
            document.querySelector('[name="add_member_by_name"]'),
        );
    };

    feld.addEventListener('input', zeichnen);

    feld.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            if (gezeigt.length === 0) return;
            e.preventDefault();
            aktiv = e.key === 'ArrowDown'
                ? Math.min(aktiv + 1, gezeigt.length - 1)
                : Math.max(aktiv - 1, 0);
            markieren();
            return;
        }

        if (e.key === 'Escape') {
            geistSetzen('', '');
            schliessen();
            return;
        }

        if (e.key !== 'Enter') return;

        // Enter bestätigt: erst den markierten Eintrag, sonst die graue
        // Ergänzung. Beides schreibt den vollen Namen ins Feld - danach
        // schickt das Formular ganz gewöhnlich ab.
        if (aktiv >= 0) {
            e.preventDefault();
            nehmen(gezeigt[aktiv].name);
            return;
        }
        if (ergaenzung !== '') {
            feld.value = ergaenzung;
            geistSetzen('', '');
            schliessen();
        }
    });

    liste.addEventListener('click', (e) => {
        const li = e.target.closest('li[role=option]');
        if (!li) return;
        nehmen(gezeigt[Number(li.dataset.i)].name);
    });

    // Wer woandershin fasst, will die Liste nicht mehr sehen.
    document.addEventListener('click', (e) => {
        if (!box.contains(e.target)) schliessen();
    });
}

initMemberSearch();

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
        // Verblasst von selbst (admin.css, frischWeg) - eine Bestätigung,
        // keine Markierung, die stehen bleibt.
        tr.className = 'frisch';
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
            `Neues Passwort für ${kind.name}? Das bisherige gilt dann nicht mehr - auch ein selbst gewähltes.`;

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
                        value="${kind.id}" title="Neues Anfangspasswort und Zettel">
                    <span aria-hidden="true">&#128273;</span> Neues Passwort
                </button>
            </form>
            <a class="iconaction quiet" title="Zettel für dieses Kind drucken"
               href="${escapeHtml(zettelJe)}${kind.id}" target="_blank" rel="noopener">
                <span aria-hidden="true">&#128424;</span> Zettel
            </a>
            <button class="iconaction danger" type="button"
                    data-auskl="${kind.id}" data-name="${escapeHtml(kind.name)}"
                    title="Aus der Klasse nehmen">
                <span aria-hidden="true">&#10005;</span> Entfernen
            </button>`;
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

// ------------------------------------------------- Vokabeln von Hand

/**
 * Eine Vokabel je Enter, ohne die Seite neu zu laden.
 *
 * Gedacht für den Fall, dass jemand eine Handvoll Wörter abtippt: Wort,
 * Tab, Wort, Enter - und die nächste Zeile steht schon da. Vorher lud die
 * Seite nach jeder Vokabel neu; bei zehn Wörtern sind das zehn Ladevorgänge
 * und zehnmal die Tabelle von oben.
 *
 * Die frische Zeile kommt vom Server, nicht von hier: `vocab_append()`
 * setzt `punctuation_fix()` darauf an, es steht also nicht zwingend das in
 * der Datenbank, was getippt wurde. Sie taucht grün auf und verblasst -
 * eine Bestätigung, die man nicht wegklicken muss.
 *
 * Ohne JavaScript schickt dasselbe Formular ganz gewöhnlich ab: Die Seite
 * lädt neu, die Zeile steht da. Dasselbe Ergebnis, nur langsamer.
 */
function initVocabAdd() {
    const zeile = document.getElementById('handzeile');
    const form  = document.getElementById('neueVokabel');
    const karte = document.getElementById('vonHand');
    if (!zeile || !form) return;

    const fremd = zeile.querySelector('input[name="new_f"]');
    const deutsch = zeile.querySelector('input[name="new_n"]');
    const knopf = zeile.querySelector('[name="add_vocab"]');
    const fehler = document.getElementById('handfehler');

    const sagen = (text) => {
        if (!fehler) return;
        fehler.querySelector('td').textContent = text;
        fehler.hidden = text === '';
    };

    /*
     * Der Knopf unter der Tabelle ist ein Link auf ?vonhand=1 - ohne
     * Skript lädt die Seite damit neu und die Zeile steht da. Mit Skript
     * genügt es, sie einzublenden.
     */
    const zeigen = () => {
        zeile.hidden = false;
        zeile.scrollIntoView({ block: 'center' });
        fremd.focus();
    };
    if (karte) karte.addEventListener('click', (e) => { e.preventDefault(); zeigen(); });

    const anlegen = async () => {
        if (fremd.value.trim() === '' || deutsch.value.trim() === '') {
            (fremd.value.trim() === '' ? fremd : deutsch).focus();
            return;
        }

        const daten = new FormData(form);
        daten.set('add_vocab', '1');
        daten.set('new_f', fremd.value);
        daten.set('new_n', deutsch.value);

        knopf.disabled = true;
        sagen('');
        try {
            const res = await fetch(form.action, {
                method: 'POST',
                body: daten,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch' },
            });
            const json = await res.json();
            if (!json.ok) {
                sagen(json.error || 'Das hat nicht geklappt.');
                fremd.select();
                fremd.focus();
                return;
            }
            einhaengen(json.vokabel);
            /*
             * Kein Nachzaehlen mehr: Eine frisch getippte Vokabel steht
             * hinter der Freigabemarke und wird noch nicht geuebt - ihr
             * Lueckensatz entsteht, wenn jemand sie aufmacht.
             */
            fremd.value = '';
            deutsch.value = '';
            fremd.focus();
        } catch {
            /*
             * Was hier ankommt, sagt nichts darueber, ob die Vokabel
             * gespeichert wurde - die Anfrage kann durchgegangen und nur
             * die Antwort verlorengegangen sein. Die Meldung behauptet
             * deshalb nicht mehr, als man weiss; frueher stand hier "Die
             * Vokabel ist nicht angekommen", und wer daraufhin noch einmal
             * drueckte, hatte sie zweimal.
             */
            sagen('Die Antwort kam nicht an. Ob die Vokabel gespeichert wurde, '
                  + 'zeigt ein Neuladen der Seite.');
        } finally {
            knopf.disabled = false;
        }
    };

    /*
     * Die neue Zeile vor die Anlegezeile - eine frisch angelegte Vokabel
     * steht hinten, und hinten ist hier direkt über dem Feld, in das man
     * gerade getippt hat.
     *
     * Sie sieht aus wie jede andere, mit Stift und Mülleimer: Eine Zeile
     * ohne Knöpfe sieht aus wie eine halb angelegte. Die Hörer dafür
     * hängen an der Tabelle und am Dokument, nicht an den Zeilen - sie
     * greifen also auch hier, ohne dass etwas nachgemeldet werden muss.
     */
    const einhaengen = (v) => {
        const id = Number(v.id);

        const tr = document.createElement('tr');
        tr.className = 'locked frisch';
        tr.dataset.pos = String(
            document.querySelectorAll('#freigabe tr[data-pos]').length + 1);
        tr.innerHTML = `
            <td>
                <strong data-wort>${escapeHtml(v.term_foreign)}</strong>
                <input type="text" name="edit_f" value="${escapeHtml(v.term_foreign)}"
                       form="vokabel${id}" maxlength="255" hidden>
            </td>
            <td>
                <span data-wort>${escapeHtml(v.term_native)}</span>
                <input type="text" name="edit_n" value="${escapeHtml(v.term_native)}"
                       form="vokabel${id}" maxlength="255" hidden>
            </td>
            <td class="actions">
                <button class="iconaction quiet nurbild" data-edit="${id}"
                        type="button" title="Diese Vokabel ändern">
                    <span aria-hidden="true">&#9999;&#65039;</span><span
                        class="nurvorlesen">Ändern</span>
                </button>
                <button class="iconaction primary nurbild" form="vokabel${id}"
                        name="save_vocab" value="${id}" data-save="${id}"
                        title="Änderung speichern" hidden>
                    <span aria-hidden="true">&#10003;</span><span
                        class="nurvorlesen">Sichern</span>
                </button>
                <button class="iconaction danger nurbild" form="vokabel${id}"
                        name="delete_vocab" value="${id}"
                        title="Diese Vokabel löschen"
                        data-confirm="„${escapeHtml(v.term_foreign)}“ löschen? Die Lückensätze dazu und der Lernstand aller Kinder daran verschwinden mit.">
                    <span aria-hidden="true">&#128465;&#65039;</span><span
                        class="nurvorlesen">Löschen</span>
                </button>
            </td>`;
        zeile.parentNode.insertBefore(tr, zeile);

        // Je Vokabel ein eigenes Formular - genau wie es die Seite selbst
        // baut; die Felder oben gehören über form= dazu.
        const eigen = document.createElement('form');
        eigen.method = 'post';
        eigen.id = `vokabel${id}`;
        eigen.innerHTML = form.innerHTML;
        form.parentNode.insertBefore(eigen, form);
    };

    knopf.addEventListener('click', (e) => { e.preventDefault(); anlegen(); });

    /*
     * Enter in einem der beiden Felder legt an. Nicht das Formular
     * abschicken lassen: Das lüde die Seite neu, und genau das soll hier
     * nicht passieren.
     */
    [fremd, deutsch].forEach((feld) => {
        feld.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            anlegen();
        });
    });
}


// ------------------------------------------ Vokabel aendern

/**
 * Aus einer Zeile ein Formular machen und zurueck.
 *
 * Beide Fassungen stehen im HTML - die Anzeige und die Felder. Das Skript
 * schaltet nur um. Ohne JavaScript bleiben die Felder verborgen und der
 * Sichern-Knopf ebenso; was dann bleibt, sind Hinzufuegen und Loeschen, und
 * beides funktioniert als gewoehnliches Formular.
 *
 * Bewusst kein Nachbauen der Zeile: Was im HTML steht, muss hier nicht noch
 * einmal entstehen - und ein Wort mit einer spitzen Klammer darin kann die
 * Tabelle so nicht zerlegen.
 */

initVocabAdd();

/**
 * Der Titel der Lerneinheit, an Ort und Stelle.
 *
 * Ein Druck auf den Stift macht aus der Ueberschrift ein Eingabefeld, mit
 * Haken zum Sichern und Kreuz zum Verwerfen. Vorher stand dafuer am Fuss
 * der Seite eine zweite Zeile mit demselben Titel darin - dieselbe Sache an
 * zwei Stellen, zwei Bildschirme voneinander entfernt.
 *
 * Ohne JavaScript steht alles nebeneinander da: Name, Feld, alle drei
 * Knoepfe. Das ist haesslich, aber bedienbar - und genau in dieser
 * Reihenfolge ist es richtig. Das Skript blendet um, was gerade nicht
 * gebraucht wird.
 */
function initUnitTitle() {
    const feld    = document.querySelector('.einheitfeld');
    const name    = document.querySelector('[data-titel]');
    const stift   = document.querySelector('[data-rename]');
    const sichern = document.querySelector('[data-rename-save]');
    const weg     = document.querySelector('[data-rename-cancel]');
    if (!feld || !name || !stift || !sichern || !weg) return;

    const urtext = feld.value;

    const zeigen = (bearbeiten) => {
        name.hidden    = bearbeiten;
        stift.hidden   = bearbeiten;
        feld.hidden    = !bearbeiten;
        sichern.hidden = !bearbeiten;
        weg.hidden     = !bearbeiten;
        if (bearbeiten) {
            feld.focus();
            feld.select();
        }
    };

    zeigen(false);

    stift.addEventListener('click', () => zeigen(true));
    weg.addEventListener('click', () => { feld.value = urtext; zeigen(false); });

    // Escape wie das Kreuz - und wie beim Aendern einer Vokabel.
    feld.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        e.preventDefault();
        feld.value = urtext;
        zeigen(false);
    });
}

initUnitTitle();

function initVocabEdit() {
    const tabelle = document.getElementById('freigabe');
    if (!tabelle) return;

    const umschalten = (zeile, bearbeiten) => {
        zeile.querySelectorAll('[data-wort]').forEach((e) => { e.hidden = bearbeiten; });
        zeile.querySelectorAll('input[name="edit_f"], input[name="edit_n"]')
             .forEach((e) => { e.hidden = !bearbeiten; });

        const stift  = zeile.querySelector('[data-edit]');
        const sicher = zeile.querySelector('[data-save]');
        if (stift)  stift.hidden  = bearbeiten;
        if (sicher) sicher.hidden = !bearbeiten;

        zeile.classList.toggle('bearbeiten', bearbeiten);
        if (bearbeiten) zeile.querySelector('input[name="edit_f"]')?.focus();
    };

    tabelle.addEventListener('click', (e) => {
        const stift = e.target.closest('[data-edit]');
        if (!stift) return;
        e.preventDefault();
        umschalten(stift.closest('tr'), true);
    });

    // Escape bricht ab, ohne zu speichern - und ohne die Seite zu verlassen.
    tabelle.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        const zeile = e.target.closest('tr');
        if (zeile && zeile.classList.contains('bearbeiten')) {
            e.preventDefault();
            umschalten(zeile, false);
        }
    });
}

initVocabEdit();

/**
 * "Passt" an einer Zeile, die die KI beim Einlesen berichtigt hat.
 *
 * Ohne Neuladen und ohne Warten: Die Markierung verschwindet beim Klick,
 * die Anfrage läuft dahinter. Wer vierzig Zeilen durchsieht, soll nicht
 * vierzigmal auf eine neue Seite warten und danach seine Stelle suchen -
 * so war es beim ersten Versuch. Geht die Anfrage schief, kommt die
 * Markierung zurück und es gibt eine Meldung.
 *
 * Der Fokus springt zum nächsten "Passt": Mit Enter geht es so Zeile für
 * Zeile durch die Liste, ohne die Maus.
 */
function initPruefen() {
    const tabelle = document.getElementById('freigabe');
    if (!tabelle) return;
    const kopf = document.querySelector('.pruefhinweis-kopf');

    const zaehlen = () => {
        const offen = tabelle.querySelectorAll('tr.pruefzeile:not([hidden])').length;
        if (!kopf) return;
        kopf.hidden = offen === 0;
        const zahl = kopf.querySelector('[data-pruefzahl]');
        if (zahl) zahl.textContent = offen === 1 ? 'Eine Vokabel' : `${offen} Vokabeln`;
    };

    tabelle.addEventListener('click', async (e) => {
        const knopf = e.target.closest('[data-passt]');
        if (!knopf) return;
        e.preventDefault();

        const id    = knopf.dataset.passt;
        const form  = document.getElementById(`vokabel${id}`);
        const notiz = knopf.closest('tr');
        const zeile = notiz.previousElementSibling;

        const alle      = [...tabelle.querySelectorAll('tr.pruefzeile:not([hidden]) [data-passt]')];
        const i         = alle.indexOf(knopf);
        const naechster = alle[i + 1] ?? alle[i - 1] ?? null;

        notiz.hidden = true;
        zeile?.classList.remove('pruefen');
        zaehlen();
        if (naechster) {
            naechster.focus({ preventScroll: true });
            naechster.closest('tr').scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }

        try {
            const daten = new FormData(form);
            daten.set('check_ok', id);
            const res = await fetch(form.action || window.location.href, {
                method: 'POST', body: daten, credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch' },
            });
            const json = await res.json();
            if (!json.ok) throw new Error(json.error || '');
            notiz.remove();
        } catch {
            notiz.hidden = false;
            zeile?.classList.add('pruefen');
            zaehlen();
            window.alert('Das hat nicht geklappt - die Vokabel ist noch markiert. Bitte noch einmal.');
        }
    });
}

initPruefen();

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
                daten.set('unit_id', dialog.dataset.unit);
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

// ------------------------------------------------------- Seiten einlesen

/**
 * Buchseiten fotografieren, ordnen, erkennen - auf dieser Seite.
 *
 * Das Einlesen war bis hierher eine eigene Ansicht in der App: Knopf
 * drücken, Seite wechselt, Dateien wählen, Titel eintippen, zurückkommen.
 * Für eine Lehrkraft, die gerade an einer Lerneinheit arbeitet, war das
 * ein Umweg über einen Ort, der von ihrer Arbeit nichts weiss - die
 * Lerneinheit steht dort gar nicht, sie muss sie in einer Liste wieder
 * suchen.
 *
 * Jetzt bleibt sie hier. Der Knopf öffnet den Dateidialog, die Fotos
 * legen sich darunter ab, und "Vokabeln erkennen" hängt sie an genau die
 * Lerneinheit an, auf deren Seite man steht.
 *
 * Drei Dinge, die die Ablage können muss, und alle drei aus demselben
 * Grund - Buchseiten sehen einander ähnlich:
 *   - gross ansehen (welche Seite ist das?),
 *   - verschieben (die Reihenfolge ist die der Vokabelliste),
 *   - entfernen (die verwackelte wieder raus).
 *
 * Ohne JavaScript passiert hier nichts; die Karte daneben bleibt ein
 * gewöhnlicher Link in die Einleseansicht der App.
 */
function initEinlesen() {
    const stapel = document.getElementById('stapel');
    if (!stapel) return;

    const liste   = document.getElementById('seiten');
    const zuviel  = document.getElementById('stapelZuviel');
    const fehler  = document.getElementById('stapelFehler');
    const knopf   = document.getElementById('erkennen');
    const text    = knopf.querySelector('[data-knopftext]');
    const weg     = document.getElementById('stapelWeg');
    const lupe    = document.getElementById('lupe');
    const lupeBild = document.getElementById('lupeBild');

    const dateiwahl  = document.getElementById('bildwahl');
    const kamerawahl = document.getElementById('kamerawahl');
    const ausDateien = document.getElementById('ausDateien');
    const perQr      = document.getElementById('perQr');
    const perKamera  = document.getElementById('perKamera');

    /* Muss zu MAX_IMAGES in api/import.php passen - dort wird es
       durchgesetzt, hier nur angezeigt. */
    const HOECHSTENS = 6;

    /** Die gewählten Seiten: { datei, url }. url ist eine Blob-Adresse für
     *  Vorschau und Lupe; verkleinert wird erst beim Erkennen. */
    let seiten = [];

    // ---------------------------------------------------------- Kamera oder Code

    /*
     * Am Telefon die Kamera, am Rechner der QR-Code.
     *
     * Gefragt wird nach dem Zeigegerät und der Kamera, nicht nach dem
     * Kennzeichen der Anfrage: Ein iPad meldet sich seit Jahren als
     * Mac, und ein Rechner mit Touchscreen ist kein Telefon. "Grober
     * Zeiger und mehr als ein Finger" trifft Telefone und Tablets und
     * lässt Mäuse draussen.
     */
    const amGeraet = window.matchMedia('(pointer: coarse)').matches
                  && navigator.maxTouchPoints > 1;
    if (amGeraet && perKamera && perQr) {
        perQr.hidden = true;
        perKamera.hidden = false;
    }

    // ---------------------------------------------------------- Auswählen

    if (ausDateien) {
        ausDateien.addEventListener('click', (e) => {
            e.preventDefault();
            dateiwahl.click();
        });
    }
    if (perKamera) {
        perKamera.addEventListener('click', () => kamerawahl.click());
    }

    const aufnehmen = (feld) => {
        for (const datei of feld.files) {
            if (!datei.type.startsWith('image/')) continue;
            seiten.push({ datei, url: URL.createObjectURL(datei) });
        }
        // Damit dieselbe Datei ein zweites Mal gewählt werden kann: Ohne
        // das Leeren feuert change beim gleichen Namen nicht noch einmal.
        feld.value = '';
        zeichnen();
        if (seiten.length > 0) stapel.scrollIntoView({ block: 'nearest' });
    };
    dateiwahl.addEventListener('change', () => aufnehmen(dateiwahl));
    kamerawahl.addEventListener('change', () => aufnehmen(kamerawahl));

    // ---------------------------------------------------------- Anzeigen

    const zeichnen = () => {
        stapel.hidden = seiten.length === 0;
        fehler.hidden = true;

        liste.innerHTML = seiten.map((s, i) => `
            <li class="seite${i >= HOECHSTENS ? ' zuviel' : ''}"
                draggable="true" data-i="${i}">
                <button class="seitenbild" type="button" data-gross="${i}"
                        title="Gross ansehen">
                    <img src="${escapeHtml(s.url)}" alt="Seite ${i + 1}">
                </button>
                <span class="seitennr">${i + 1}</span>
                <span class="seitenwerkzeug">
                    <button class="iconaction quiet" type="button" data-vor="${i}"
                            title="Nach vorne" ${i === 0 ? 'disabled' : ''}>
                        <span aria-hidden="true">&#9664;</span>
                        <span class="nurvorlesen">Nach vorne</span>
                    </button>
                    <button class="iconaction danger" type="button" data-weg="${i}"
                            title="Entfernen">
                        <span aria-hidden="true">&#10005;</span>
                        <span class="nurvorlesen">Entfernen</span>
                    </button>
                    <button class="iconaction quiet" type="button" data-zurueck="${i}"
                            title="Nach hinten" ${i === seiten.length - 1 ? 'disabled' : ''}>
                        <span aria-hidden="true">&#9654;</span>
                        <span class="nurvorlesen">Nach hinten</span>
                    </button>
                </span>
            </li>`).join('');

        const ueber = seiten.length - HOECHSTENS;
        zuviel.hidden = ueber <= 0;
        if (ueber > 0) {
            zuviel.textContent = `Höchstens ${HOECHSTENS} Seiten auf einmal. `
                + `Die ${ueber === 1 ? 'letzte Seite wird' : `letzten ${ueber} Seiten werden`}`
                + ` diesmal übergangen - entferne sie oder lies sie danach`
                + ` in einem zweiten Durchgang ein.`;
        }

        const nimmt = Math.min(seiten.length, HOECHSTENS);
        text.textContent = nimmt === 1
            ? 'Vokabeln von dieser Seite erkennen'
            : `Vokabeln von ${nimmt} Seiten erkennen`;
    };

    // ---------------------------------------------------------- Ordnen

    const tauschen = (a, b) => {
        if (b < 0 || b >= seiten.length) return;
        [seiten[a], seiten[b]] = [seiten[b], seiten[a]];
        zeichnen();
    };

    liste.addEventListener('click', (e) => {
        const gross = e.target.closest('[data-gross]');
        if (gross) {
            lupeBild.src = seiten[+gross.dataset.gross].url;
            lupeBild.alt = `Seite ${+gross.dataset.gross + 1}`;
            if (typeof lupe.showModal === 'function') lupe.showModal();
            return;
        }
        const vor = e.target.closest('[data-vor]');
        if (vor) { tauschen(+vor.dataset.vor, +vor.dataset.vor - 1); return; }

        const zur = e.target.closest('[data-zurueck]');
        if (zur) { tauschen(+zur.dataset.zurueck, +zur.dataset.zurueck + 1); return; }

        const raus = e.target.closest('[data-weg]');
        if (raus) {
            const i = +raus.dataset.weg;
            URL.revokeObjectURL(seiten[i].url);
            seiten.splice(i, 1);
            zeichnen();
        }
    });

    /*
     * Ziehen und Ablegen - mit der Maus.
     *
     * Auf einem Touchgerät gibt es keine HTML5-Zieherei; dafür sind die
     * beiden Pfeile da, und die tun dasselbe. Dass beide Wege nebeneinander
     * stehen, ist kein Versehen: Der eine ist schneller, der andere
     * funktioniert überall, auch mit der Tastatur.
     */
    let packe = -1;
    liste.addEventListener('dragstart', (e) => {
        const li = e.target.closest('.seite');
        if (!li) return;
        packe = +li.dataset.i;
        li.classList.add('zieht');
        e.dataTransfer.effectAllowed = 'move';
        // Firefox zieht nur, wenn etwas im Paket liegt.
        e.dataTransfer.setData('text/plain', String(packe));
    });
    liste.addEventListener('dragend', () => {
        packe = -1;
        liste.querySelectorAll('.zieht, .ueber')
             .forEach((el) => el.classList.remove('zieht', 'ueber'));
    });
    liste.addEventListener('dragover', (e) => {
        const li = e.target.closest('.seite');
        if (packe < 0 || !li) return;
        e.preventDefault();
        liste.querySelectorAll('.ueber').forEach((el) => el.classList.remove('ueber'));
        li.classList.add('ueber');
    });
    liste.addEventListener('drop', (e) => {
        const li = e.target.closest('.seite');
        if (packe < 0 || !li) return;
        e.preventDefault();
        const ziel = +li.dataset.i;
        const [s] = seiten.splice(packe, 1);
        seiten.splice(ziel, 0, s);
        packe = -1;
        zeichnen();
    });

    weg.addEventListener('click', () => {
        seiten.forEach((s) => URL.revokeObjectURL(s.url));
        seiten = [];
        zeichnen();
    });

    // ---------------------------------------------------------- Erkennen

    knopf.addEventListener('click', async () => {
        if (seiten.length === 0) return;

        knopf.disabled = true;
        weg.disabled = true;
        fehler.hidden = true;

        // Voki liest, und die Seite ist so lange zu - siehe lesevoki.js.
        const { vokiLiest } = await import(stapel.dataset.lesevoki);
        const decke = vokiLiest('Voki liest die Seiten …');

        try {
            /*
             * Verkleinert wird erst jetzt, und nur, was wirklich geschickt
             * wird: Wer acht Seiten wählt und zwei wieder wegnimmt, soll
             * nicht acht Bilder umgerechnet haben.
             *
             * Die Rechnerei steht in views/bilder.js - dieselbe wie in der
             * App. Nachgeladen wird sie erst hier, damit die Seite ohne
             * Einlesen nichts davon anfasst.
             */
            const { shrinkToBase64 } = await import(stapel.dataset.bilder);
            const bilder = [];
            for (const s of seiten.slice(0, HOECHSTENS)) {
                const { data, media_type: typ } = await shrinkToBase64(s.datei);
                bilder.push(`data:${typ};base64,${data}`);
            }

            /*
             * Gelesen wird hier, auf diesem Gerät (ocr.js) - die Fotos
             * verlassen es nicht. Zum Server geht nur der erkannte Text.
             */
            const { texterkennung } = await import(stapel.dataset.ocr);
            const text = await texterkennung(bilder, stapel.dataset.sprachcode, {
                fortschritt: (seite, alle, anteil) => decke.text(
                    alle > 1 ? `Voki liest Seite ${seite} von ${alle} …` : 'Voki liest die Seite …',
                    `${Math.round(anteil * 100)} % - die Fotos bleiben auf diesem Gerät.`,
                ),
            });

            decke.text('Voki sortiert die Vokabeln …',
                       'Die KI ordnet den erkannten Text und berichtigt Lesefehler.');
            const erkannt = await jsonPost(
                `${stapel.dataset.api}?action=analyze`,
                { language_id: +stapel.dataset.language, text, pages: bilder.length },
                { 'X-Vokabeltrainer': '1' },
            );
            if (!erkannt.ok) throw new Error(erkannt.error || 'Das Erkennen ging schief.');

            decke.text('Wird gespeichert …', '');

            const daten = new FormData();
            daten.set('add_scanned', '1');
            daten.set('unit_id', stapel.dataset.unit);
            daten.set('csrf', stapel.dataset.csrf);
            daten.set('entries', JSON.stringify(erkannt.entries));

            const res = await fetch(stapel.dataset.ziel, {
                method: 'POST', body: daten, credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch' },
            });
            const gesichert = await res.json();
            if (!gesichert.ok) throw new Error(gesichert.error || 'Das Speichern ging schief.');

            /*
             * Und dann die Seite neu laden. Nach dem Erkennen ändert sich
             * zu vieles auf einmal - die Zahlen im Satz über der Tabelle,
             * der Knopf "Alles freigeben", die Zeilen, an denen der
             * Freigabebalken misst. Ein ?neu= in der Adresse sorgt dafür,
             * dass die neuen Zeilen drüben grün dastehen; was die KI
             * berichtigt hat, steht dort gelb. Die Decke bleibt bis zum
             * Seitenwechsel liegen.
             */
            seiten.forEach((s) => URL.revokeObjectURL(s.url));
            window.location.href = `${stapel.dataset.ziel}&neu=${gesichert.dazu}#frisch`;
        } catch (e) {
            decke.weg();
            fehler.textContent = e.message || 'Keine Verbindung zum Server.';
            fehler.hidden = false;
            knopf.disabled = false;
            weg.disabled = false;
        }
    });

    zeichnen();
}

/** Ein JSON-Aufruf gegen die API - sie nimmt kein FormData, sondern einen Rumpf. */
async function jsonPost(url, rumpf, kopf = {}) {
    const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', ...kopf },
        body: JSON.stringify(rumpf),
    });
    return res.json();
}

initEinlesen();

/**
 * Rückfragen, die der Server schon offen ausliefert: dialog[data-sofort].
 *
 * So die Frage nach dem Titel von den Fotos (unit.php, ?titel=) und die
 * nach den Kursen beim Zuordnen (ohneklasse.php, ?kind=&klasse=). In
 * data-sofort stehen die Teile der Adresse, die die Frage ausgelöst haben -
 * sie kommen danach heraus: Wer die Seite neu lädt, hat schon geantwortet
 * und soll nicht noch einmal gefragt werden.
 */
function initSofortFragen() {
    const frage = document.querySelector('dialog[data-sofort]');
    if (!frage || typeof frage.showModal !== 'function') return;
    const adresse = new URL(window.location.href);
    frage.dataset.sofort.split(' ').forEach((teil) => adresse.searchParams.delete(teil));
    history.replaceState(null, '', adresse);
    frage.showModal();
}

initSofortFragen();

/**
 * Der Hinweis aufs Home-Bildschirm auf "Meine Kurse" (index.php). Das
 * Modul hört auf Chromes Angebot zum Anlegen - je früher es geladen ist,
 * desto sicherer bekommt es das mit.
 */
async function initInstallHinweis() {
    const el = document.getElementById('installHinweis');
    if (!el?.dataset.installieren) return;
    const { installHinweis } = await import(el.dataset.installieren);
    installHinweis(el, {
        name:   el.dataset.name,
        symbol: el.dataset.symbol,
        wohin:  el.dataset.wohin,
    });
}

initInstallHinweis();

/**
 * In der installierten App: "App aktualisieren" statt "Abmelden".
 *
 * Dieselbe Regel wie in der Lernansicht (core.js). Geholt wird über
 * aktualisieren.js - dasselbe gründliche Neuholen wie beim Band "Es gibt
 * eine neue Fassung", danach lädt die Seite neu, auf der man gerade ist.
 */
function initAktualisierenStattAbmelden() {
    const installiert = window.navigator.standalone === true
        || window.matchMedia('(display-mode: standalone)').matches;
    const knopf = document.querySelector('[data-nav-refresh]');
    const fassung = document.querySelector('script[data-fassung]');
    if (!installiert || !knopf || !fassung) return;

    document.querySelector('[data-abmelden]')?.setAttribute('hidden', '');
    knopf.hidden = false;
    knopf.addEventListener('click', async () => {
        knopf.disabled = true;
        knopf.querySelector('.micon').innerHTML = '<span class="spinner inline"></span>';
        const { frischHolen } = await import(fassung.src);
        await frischHolen({
            base:   fassung.dataset.base,
            assets: JSON.parse(fassung.dataset.assets || '[]'),
        });
    });
}

initAktualisierenStattAbmelden();

/** "Mein Konto": die Farbwahl wie in der App - sofort sichtbar (appsymbol.js). */
async function initFarbwahl() {
    const feld = document.querySelector('[data-farbwahl]');
    if (!feld) return;
    const { farbwahlVerdrahten } = await import(feld.dataset.farbwahl);
    farbwahlVerdrahten(feld.closest('form') ?? document);
}

initFarbwahl();

// Eine Auswahl, die schon die ganze Antwort ist: beim Wählen abschicken.
document.addEventListener('change', (e) => {
    const form = e.target.closest('form[data-sofortsenden]');
    if (form && e.target.matches('select') && e.target.value !== '') form.submit();
});

/**
 * "Entfernen" in der Klassenliste (class.php, #auskl): ein Fenster für
 * alle Zeilen, Name und Nummer kommen aus dem Knopf. Der Name als Text,
 * nicht als Markup - "N'Diaye <3" darf das Fenster nicht zerlegen.
 */
function initAusKlasse() {
    const fenster = document.getElementById('auskl');
    if (!fenster || typeof fenster.showModal !== 'function') return;

    document.addEventListener('click', (e) => {
        const knopf = e.target.closest('[data-auskl]');
        if (!knopf) return;
        fenster.querySelector('[data-name]').textContent = knopf.dataset.name;
        fenster.querySelector('[name="remove_student"]').value = knopf.dataset.auskl;
        fenster.showModal();
    });
}

initAusKlasse();

// --------------------------------------------- Was im Hintergrund entsteht

/**
 * Ring und Haken in den Lerneinheiten eines Kurses - vom Server geschickt.
 *
 * Nach dem Freigeben entstehen Lückensätze und Aufnahmen im Hintergrund.
 * Die Kursseite bekommt den Stand als Strom (teacher/erzeugung.php) und
 * setzt nur die Zellen ein, die sich geändert haben - fertig gerechnet und
 * fertig gezeichnet kommen sie aus lib/erzeugung.php, wie beim ersten
 * Aufbau der Seite.
 *
 * Wann der Browser wieder fragt, sagt der Server (retry): gleich, solange
 * etwas entsteht, sonst in vier Sekunden. Ist der Tab nicht zu sehen, ist
 * der Strom zu - eine vergessene Kursseite soll auf dem Webhoster keinen
 * Prozess festhalten.
 */
function initErzeugung() {
    const tabelle = document.getElementById('einheiten');
    const quelle  = tabelle?.dataset.erzeugung;
    if (!quelle || !window.EventSource) return;

    const anwenden = (stand) => {
        for (const [id, zellen] of Object.entries(stand.einheiten ?? {})) {
            const zeile = tabelle.querySelector(`tr[data-unit="${id}"]`);
            if (!zeile) continue;
            for (const [art, z] of Object.entries(zellen)) {
                const td = zeile.querySelector(`td[data-erz="${art}"]`);
                if (!td || td.dataset.stand === z.s) continue;
                const war = td.dataset.stand;
                td.innerHTML = z.html;
                td.dataset.stand = z.s;
                // Eben fertig geworden - der Haken springt einmal auf (admin.css).
                td.classList.toggle('eben', (war === 'laeuft' || war === 'wartet') && z.s === 'fertig');
            }
        }
    };

    let strom = null;
    const auf = () => {
        if (strom) return;
        strom = new EventSource(quelle);
        strom.addEventListener('stand', (e) => {
            try { anwenden(JSON.parse(e.data)); } catch { /* ein kaputtes Stück - das nächste kommt */ }
        });
    };
    const zu = () => {
        strom?.close();
        strom = null;
    };
    document.addEventListener('visibilitychange', () => (document.hidden ? zu() : auf()));
    window.addEventListener('pagehide', zu);
    if (!document.hidden) auf();
}

initErzeugung();

// --------------------------------------------- Lerneinheiten sortieren

/**
 * Die Reihenfolge der Lerneinheiten mit der Maus legen.
 *
 * Sie ist dieselbe, in der die Klasse sie in ihrer App sieht. Bis hierher
 * war es die Entstehungsreihenfolge, neueste zuerst - wer Unit 7 vor Unit 3
 * fotografierte, weil die Seite gerade aufgeschlagen war, bekam sie auch so
 * vorgesetzt.
 *
 * Ohne Skript tun es die beiden Pfeile je Zeile; sie schicken dieselbe Liste
 * als gewoehnliches Formular. Mit Skript verschwinden sie (js-hide), und es
 * wird gezogen.
 */
function initUnitSort() {
    const tabelle = document.getElementById('einheiten');
    const form    = document.getElementById('sortierform');
    if (!tabelle || !form) return;

    const zeilen = () => [...tabelle.querySelectorAll('tr[data-unit]')];
    if (zeilen().length < 2) return;

    /*
     * Gezogen wird nur mit einer Maus.
     *
     * Das Ziehen des Browsers gibt es auf einem Touchgeraet nicht - dort
     * bewegt ein Wisch die Seite, und ein dragstart kommt nie. Wer hier
     * trotzdem die Pfeile entfernte, liesse eine Lehrkraft am Telefon mit
     * einer Tabelle zurueck, die sich gar nicht mehr sortieren laesst.
     * Also: feiner Zeiger, dann ziehen; sonst bleiben die Pfeile.
     */
    if (!window.matchMedia('(pointer: fine)').matches) return;

    /*
     * Die Pfeile sind der Weg ohne Skript und verschwinden hier - dasselbe
     * Muster wie beim Freigabebalken. Sie tragen die Nachbarlisten in ihren
     * Werten, und nach dem ersten Zug stimmten die nicht mehr; sie
     * stehenzulassen hiesse, einen Knopf anzubieten, der etwas Falsches
     * tut.
     */
    tabelle.querySelectorAll('.js-hide').forEach((b) => b.remove());
    tabelle.classList.add('sortierbar');

    let packe = null;

    tabelle.addEventListener('dragstart', (e) => {
        const tr = e.target.closest('tr[data-unit]');
        if (!tr) return;
        packe = tr;
        tr.classList.add('zieht');
        e.dataTransfer.effectAllowed = 'move';
        // Firefox zieht nur, wenn etwas im Paket liegt.
        e.dataTransfer.setData('text/plain', tr.dataset.unit);
    });

    tabelle.addEventListener('dragover', (e) => {
        const tr = e.target.closest('tr[data-unit]');
        if (packe === null || !tr || tr === packe) return;
        e.preventDefault();

        /*
         * Vor oder hinter die Zeile, je nachdem, wo der Zeiger steht.
         * Ohne diese Unterscheidung springt die gezogene Zeile an der
         * untersten Position hin und her, weil sie sich selbst
         * verdraengt.
         */
        const kasten = tr.getBoundingClientRect();
        const untereHaelfte = e.clientY > kasten.top + kasten.height / 2;
        tr.parentNode.insertBefore(packe, untereHaelfte ? tr.nextSibling : tr);
    });

    tabelle.addEventListener('dragend', () => {
        if (packe === null) return;
        packe.classList.remove('zieht');
        packe = null;
        speichern();
    });

    let uhr = null;

    /*
     * Gesichert wird kurz nach dem Loslassen, nicht sofort: Wer drei Zeilen
     * hintereinander schiebt, soll daraus eine Anfrage machen und nicht
     * drei.
     */
    const speichern = () => {
        if (uhr !== null) clearTimeout(uhr);
        uhr = setTimeout(async () => {
            uhr = null;
            const liste = zeilen().map((tr) => tr.dataset.unit).join(',');

            const daten = new FormData(form);
            daten.set('reihenfolge', liste);

            tabelle.classList.add('sichert');
            try {
                const res = await fetch(form.action, {
                    method: 'POST',
                    body: daten,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'fetch' },
                });
                const json = await res.json();
                if (!json.ok) throw new Error(json.error || 'Das hat nicht geklappt.');
            } catch {
                /*
                 * Neu laden statt eine Meldung: Was auf dem Bildschirm
                 * steht, stimmt dann nicht mit der Datenbank ueberein, und
                 * eine falsche Reihenfolge stillschweigend stehenzulassen
                 * waere schlimmer als ein Sprung.
                 */
                alert('Die Reihenfolge konnte nicht gespeichert werden.');
                location.reload();
                return;
            } finally {
                tabelle.classList.remove('sichert');
            }

        }, 500);
    };
}

initUnitSort();

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
        tr.addEventListener('click', (e) => {
            if (zieht) return;
            /*
             * In den Zeilen stehen jetzt auch Knoepfe und Felder - Aendern,
             * Loeschen, die Eingabefelder beim Bearbeiten. Ein Klick darauf
             * ist kein Klick auf die Zeile, sonst verschiebt "Loeschen"
             * nebenbei die Freigabe. Dieselbe Wache wie bei tr[data-href].
             */
            if (e.target.closest('a, button, input, select, textarea, label')) return;
            if ((window.getSelection()?.toString() ?? '') !== '') return;

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
