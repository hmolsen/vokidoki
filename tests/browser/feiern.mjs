/*
 * Belohnung und Kalender - beides sieht man erst im Browser.
 *
 * Die Punkte springen an, das Konfetti fliegt, das Feuerwerk steht, und der
 * Kalender blättert. Keine PHP-Suite kann das prüfen: Es sind Animationen
 * und ein Gitter, und beides entsteht erst, wenn jemand antwortet.
 *
 * DIE WICHTIGSTE PRÜFUNG HIER IST DIE ZEIT. Die Feiern dürfen den Ablauf
 * nicht aufhalten - nach einer richtigen Antwort bleibt es bei denselben
 * 700 ms bis zur nächsten Frage wie vorher.
 */

import { execFileSync } from 'node:child_process';
import { browser, alsKind, ok, abschnitt, schlafe } from './browser.mjs';

const php = (wurzel, code) =>
    execFileSync('php', ['-r', code], { cwd: wurzel, encoding: 'utf8' });

export async function pruefe(f, aus, wurzel) {
    abschnitt('Belohnung beim Üben');

    /*
     * Genau EINE Vokabel freigeben. Das Quiz zieht sonst zufällig aus allen
     * offenen, und dann treffen drei richtige Antworten drei verschiedene
     * Wörter - die Serie stünde nie auf drei, und das Konfetti bliebe aus.
     */
    php(wurzel, "require 'lib/db.php';"
        + "q('UPDATE units SET released_position = 1 WHERE id = ?', [" + f.unit + "]);"
        + "q('DELETE p FROM progress p JOIN vocab v ON v.id = p.vocab_id"
        + "    WHERE v.unit_id = ?', [" + f.unit + "]);");

    const b = await browser({ port: 9416, breite: 390, hoehe: 840, aus });
    try {
        await alsKind(b, f.basis, f.kind, f.passwort);
        await b.hash('/quiz/' + f.unit, 2500);
        await schlafe(600);

        const punkte = () => b.js(`[...document.querySelectorAll('.dots i')]
            .map((e) => (e.classList.contains('on') ? 'an' : 'aus')
                      + (e.classList.contains('frisch') ? '+' : '')).join(',')`);

        // Die richtige Antwort steht im Vorrat - genau dafür ist er da.
        const richtig = () => b.js(`(async () => {
            const v = await import('${f.basis}/vorrat.js');
            const wort = document.querySelector('.prompt .word').textContent.trim();
            const w = v.vokabelnDerEinheit(${f.unit})
                       .find((x) => x.f === wort || x.n === wort);
            const loesung = w ? (w.f === wort ? w.n : w.f) : null;
            const k = [...document.querySelectorAll('.option')]
                        .find((o) => o.textContent.trim() === loesung);
            if (!k) return false;
            k.click();
            return true;
        })()`);

        ok('Vorher sind alle drei Punkte aus', (await punkte()) === 'aus,aus,aus');

        ok('Die richtige Antwort liegt im Gerät', await richtig());
        await schlafe(80);
        ok('Der erste Punkt springt sofort an', (await punkte()) === 'an+,aus,aus',
           await punkte());

        const wachsen = await b.js(`(() => {
            const p = document.querySelector('.dots i.frisch');
            const a = (p?.getAnimations?.() ?? [])
                        .filter((x) => x.animationName === 'punktAuf');
            return { da: a.length, dauer: a[0]?.effect?.getTiming?.().duration ?? 0 };
        })()`);
        ok('Und wächst dabei auf und zurück',
           wachsen.da === 1 && wachsen.dauer === 500, JSON.stringify(wachsen));
        ok('Konfetti fliegt noch nicht',
           (await b.js(`!!document.getElementById('feier')`)) === false);

        // ---- Zweite und dritte Antwort. Beim dritten sitzt die Vokabel.
        await schlafe(900);
        await richtig();
        await schlafe(900);
        const losGehts = Date.now();
        await richtig();
        await schlafe(120);

        const fest = await b.js(`(() => {
            const el = document.getElementById('feier');
            return {
                da: !!el,
                konfetti: el?.classList.contains('konfetti') ?? false,
                lob: el?.querySelector('.feierwort')?.textContent ?? '',
                schnipsel: el?.querySelectorAll('i').length ?? 0,
                amBody: el?.parentElement === document.body,
                durchlaessig: el ? getComputedStyle(el).pointerEvents : '',
                an: [...document.querySelectorAll('.dots i')]
                      .filter((e) => e.classList.contains('on')).length,
            };
        })()`);
        ok('Beim dritten Mal fliegt Konfetti', fest.da && fest.konfetti);
        ok('Mit einem Lob davor', fest.lob.endsWith('!'), fest.lob);
        ok('Und mit Schnipseln', fest.schnipsel >= 30, String(fest.schnipsel));
        ok('Alle drei Punkte stehen an', fest.an === 3, String(fest.an));
        /*
         * Es hängt an <body>, nicht in der Ansicht: Sonst wäre es beim
         * nächsten render() mitten im Flug verschwunden - und render()
         * kommt schon 700 ms später.
         */
        ok('Die Feier hängt an <body>', fest.amBody === true);
        ok('Und lässt jeden Druck durch', fest.durchlaessig === 'none');
        if (aus) await b.bild('feier-konfetti');

        // ---- Und der Ablauf bleibt genauso schnell wie vorher.
        await b.js(`window.__w = document.querySelector('.prompt .word')?.textContent ?? null`);
        let gebraucht = 0;
        for (let i = 0; i < 40; i++) {
            await schlafe(50);
            const weiter = await b.js(`(() => {
                const w = document.querySelector('.prompt .word')?.textContent ?? null;
                return (w !== window.__w) || !!document.querySelector('.celebrate h1');
            })()`);
            if (weiter) { gebraucht = Date.now() - losGehts; break; }
        }
        ok('Es geht nach wie vor nach rund 700 ms weiter',
           gebraucht > 500 && gebraucht < 1600, gebraucht + ' ms');

        // ---- Die Einheit hatte genau diese eine Vokabel: jetzt steht sie.
        await schlafe(900);

        const stand = () => b.js(`(() => {
            const el = document.getElementById('feuerwerk');
            const cs = el ? getComputedStyle(el) : null;
            const r  = el?.getBoundingClientRect();
            return {
                ueberschrift: document.querySelector('.celebrate h1')?.textContent ?? '',
                da: !!el,
                raketen: el?.querySelectorAll('.rakete').length ?? 0,
                z: cs?.zIndex ?? '',
                durchlaessig: cs?.pointerEvents ?? '',
                deckt: r ? (Math.round(r.width) === innerWidth
                            && Math.round(r.height) === innerHeight) : false,
                amBody: el?.parentElement === document.body,
            };
        })()`);

        const e = await stand();
        ok('Am Ende steht die Geschafft-Seite',
           e.ueberschrift.includes('bestanden'), e.ueberschrift);
        ok('Und dazu ein Feuerwerk', e.da === true);
        /*
         * Es liegt HINTER der Seite: z-index -1. Ein fixiertes Element mit 0
         * läge über der Überschrift und den Knöpfen - dann wäre es keine
         * Kulisse mehr, sondern ein Vorhang.
         */
        ok('Es liegt hinter der Seite', e.z === '-1', 'z-index ' + e.z);
        ok('Und deckt den ganzen Schirm', e.deckt === true);
        ok('Es lässt jeden Druck durch', e.durchlaessig === 'none');
        ok('Auch es hängt an <body>', e.amBody === true);
        if (aus) await b.bild('feier-feuerwerk');

        /*
         * Und es hört nicht von selbst auf. Die Geschafft-Seite ist kein
         * Durchgang, sondern der Augenblick, auf den zwanzig Vokabeln
         * hingearbeitet haben - drei Raketen wären zu Ende, bevor ein Kind
         * aufgesehen hat.
         */
        const gesehen = [];
        for (let i = 0; i < 10; i++) {
            await schlafe(420);
            gesehen.push((await stand()).raketen);
        }
        ok('Es steigen nach vier Sekunden immer noch Raketen',
           gesehen.slice(-4).every((n) => n > 0), JSON.stringify(gesehen));
        // Jede Rakete räumt sich selbst ab; sonst wüchse die Seite endlos.
        ok('Und es staut sich nicht auf', Math.max(...gesehen) <= 4,
           'höchstens ' + Math.max(...gesehen) + ' gleichzeitig');

        // Wegklicken - und Schluss.
        await b.js(`document.querySelector('[data-back]').click()`);
        await schlafe(1200);
        ok('Ein Klick weiter, und es ist aus', (await stand()).da === false,
           'render() macht es aus - das trifft jeden Ausgang');
    } finally {
        await b.schliessen();
    }

    // ------------------------------------------------------ Freies Üben

    abschnitt('Freies Üben');

    /*
     * Erst aufräumen, was der Abschnitt darüber hinterlassen hat: Dort war
     * nur EINE Vokabel freigegeben, damit dreimal richtig dieselbe trifft.
     * Beim freien Üben wäre das eine Falle - mit einer einzigen Vokabel gibt
     * es nur eine Antwortmöglichkeit, und dann lässt sich gar nicht falsch
     * antworten.
     *
     * Und Lückensätze braucht es auch: Ohne sie kann die Hälfte der Aufgaben
     * gar nicht erscheinen, und die Hälfte bliebe ungeprüft.
     */
    php(wurzel, "require 'lib/db.php';"
        + "q('UPDATE units SET released_position ="
        + "   (SELECT COUNT(*) FROM vocab WHERE unit_id = ?) WHERE id = ?',"
        + "  [" + f.unit + ", " + f.unit + "]);");

    php(wurzel, "require 'lib/db.php';"
        + "foreach (qa('SELECT id, term_foreign, term_native FROM vocab"
        + "             WHERE unit_id = ?', [" + f.unit + "]) as $v) {"
        + "  q('INSERT IGNORE INTO sentences (vocab_id, native_text, foreign_text, answer)"
        + "     VALUES (?,?,?,?)',"
        + "    [$v['id'], 'Satz zu ' . $v['term_native'], 'Hier fehlt {} im Satz.',"
        + "     $v['term_foreign']]);"
        + "}");

    const lernstand = () => JSON.parse(php(wurzel,
        "require 'lib/db.php';"
        + "$u=(int)qv('SELECT id FROM users WHERE username=?',['" + f.kind + "']);"
        + "echo json_encode(["
        + " 'zeilen'=>(int)qv('SELECT COUNT(*) FROM progress WHERE user_id=?',[$u]),"
        + " 'summe'=>(int)qv('SELECT COALESCE(SUM(streak+correct_count+wrong_count),0)"
        + "                   FROM progress WHERE user_id=?',[$u]),"
        + " 'tag'=>(int)qv('SELECT COALESCE(correct,0) FROM learn_days"
        + "                 WHERE user_id=? AND `day`=CURDATE()',[$u])]);"));

    const fr = await browser({ port: 9418, breite: 390, hoehe: 860, aus });
    try {
        const vorher = lernstand();
        await alsKind(fr, f.basis, f.kind, f.passwort);

        // Die Auswahl vom Kurs aus.
        await fr.hash('/frei/waehlen/' + f.sprache, 2400);
        await schlafe(400);
        const wahl = await fr.js(`({
            frage: document.querySelector('.sub')?.textContent ?? '',
            boxen: document.querySelectorAll('.wahlbox').length,
            alleAn: [...document.querySelectorAll('.wahlbox')].every((b) => b.checked),
        })`);
        ok('Die Auswahl stellt die Frage',
           wahl.frage.includes('Welche Lerneinheiten sollen geübt werden?'), wahl.frage);
        ok('Mit einer Zeile je Lerneinheit', wahl.boxen >= 1, String(wahl.boxen));
        ok('Alle vorgehakt', wahl.alleAn === true);

        await fr.js(`document.getElementById('keine').click()`);
        await schlafe(150);
        ok('Ohne Auswahl geht es nicht los',
           (await fr.js(`document.getElementById('los').disabled`)) === true);
        await fr.js(`document.getElementById('alle').click()`);
        await schlafe(150);
        await fr.js(`document.getElementById('los').click()`);
        await schlafe(1300);

        /*
         * Die Serie der Runde: links die laufende, rechts die beste, dazwischen
         * der Balken. Voll und grün, solange die laufende die beste ist -
         * sonst so weit gefüllt, wie sie schon wieder aufgeholt hat.
         */
        const serie = () => fr.js(`(() => {
            const b = document.getElementById('z-balken');
            return {
                folge: document.getElementById('z-folge')?.textContent ?? '',
                beste: document.getElementById('z-beste')?.textContent ?? '',
                breite: b?.firstElementChild.style.width ?? '',
                gruen: !!b?.classList.contains('done'),
                frisch: !!document.querySelector('#z-folge.frisch'),
            };
        })()`);

        const leiste = { ort: await fr.js('location.hash'), ...(await serie()),
                         tuer: await fr.js(`!!document.getElementById('raus')`) };
        ok('Die Runde läuft', leiste.ort.startsWith('#/frei/'), leiste.ort);
        ok('Am Anfang: null und null, der Balken voll und grün',
           leiste.folge === '0' && leiste.beste === '0'
           && leiste.breite === '100%' && leiste.gruen,
           JSON.stringify(leiste));
        ok('Eine Tür hinaus gibt es nicht mehr - dafür ist der Zurück-Pfeil da',
           !leiste.tuer);

        /* Richtig antworten - die Lösung steht im Vorrat, genau dafür ist er da. */
        const antworten = (richtig) => fr.js(`(async () => {
            const v = await import('${f.basis}/vorrat.js');
            const feld = document.getElementById('answer');
            if (feld) {
                const satz = document.querySelector('.cloze-native').textContent.trim();
                let loesung = null;
                for (const w of v.vokabelnDerEinheiten([${f.unit}])) {
                    for (const s of (v.vorratLaden().saetze.get(w.i) ?? [])) {
                        if (s.n.trim() === satz) loesung = s.a;
                    }
                }
                feld.value = ${richtig} ? (loesung ?? '') : 'xxfalschxx';
                document.getElementById('check').click();
                return { art: 'luecke', ok: loesung !== null };
            }
            const wort = document.querySelector('.prompt .word').textContent.trim();
            const w = v.vokabelnDerEinheiten([${f.unit}])
                       .find((x) => x.f === wort || x.n === wort);
            const l = w ? (w.f === wort ? w.n : w.f) : null;
            const o = [...document.querySelectorAll('.option')];
            const i = o.findIndex((x) => x.textContent.trim() === l);
            if (i < 0) return { art: 'wahl', ok: false };
            o[${richtig} ? i : (i + 1) % o.length].click();
            return { art: 'wahl', ok: true };
        })()`);

        const arten = new Set();
        let lob = null;
        for (let i = 1; i <= 5; i++) {
            const a = await antworten(true);
            arten.add(a.art);
            await schlafe(150);
            if (i === 1) {
                const z = await serie();
                ok('Nach der ersten richtigen zählen beide Zahlen mit: 1 und 1',
                   z.folge === '1' && z.beste === '1', JSON.stringify(z));
                ok('Der Balken bleibt voll und grün', z.breite === '100%' && z.gruen,
                   JSON.stringify(z));
                ok('Und die Zahl springt mit einer Bewegung', z.frisch === true);
            }
            if (i === 5) {
                lob = await fr.js(`(() => {
                    const el = document.getElementById('feier');
                    return { da: !!el,
                             wort: el?.querySelector('.feierwort')?.textContent ?? '',
                             grund: el?.querySelector('.feiergrund')?.textContent ?? '' };
                })()`);
            }
            await schlafe(1100);
        }
        ok('Beide Aufgabenarten kommen vor', arten.size === 2, [...arten].join('+'));
        ok('Bei fünf in Folge wird gelobt', lob?.da === true);
        ok('Und darunter steht, wofür', lob?.grund === '5 in Folge', lob?.grund);
        ok('Das Lob ist eines der kurzen', (lob?.wort ?? '').endsWith('!'), lob?.wort);
        if (aus) await fr.bild('frei-lob');

        // Eine falsche: die laufende Folge fällt, die beste bleibt stehen.
        const falsch = await antworten(false);
        await schlafe(300);
        const nachFalsch = await serie();
        ok('Nach einer falschen fällt die linke Zahl auf null, die rechte bleibt',
           nachFalsch.folge === '0' && nachFalsch.beste === '5', JSON.stringify(nachFalsch));
        ok('Und der Balken steht leer, nicht mehr grün',
           nachFalsch.breite === '0%' && !nachFalsch.gruen, JSON.stringify(nachFalsch));

        // Im Lückentext wartet ein Fehler auf "Weiter" - wie in der Lückentext-Übung.
        if (falsch.art === 'luecke') {
            ok('Nach einem Fehler im Lückentext steht dort "Weiter"',
               (await fr.js(`document.getElementById('check').textContent`)) === 'Weiter');
            await fr.js(`document.getElementById('check').click()`);
        }
        await schlafe(2100);

        await antworten(true);
        await schlafe(300);
        const aufholen = await serie();
        ok('Danach zeigt der Balken, wie weit es bis zum Rekord ist: 1 von 5',
           aufholen.folge === '1' && aufholen.beste === '5'
           && aufholen.breite === '20%' && !aufholen.gruen, JSON.stringify(aufholen));
        await schlafe(1100);

        /*
         * UND DAS WICHTIGSTE: Der Lernstand bleibt, wie er war. Geübt wird
         * hier alles, auch was längst sitzt - ein Fehler dabei darf keine
         * Serie einreissen, die über Wochen entstanden ist.
         */
        await fr.js(`(async () => {
            const v = await import('${f.basis}/vorrat.js');
            await v.warteschlangeSenden();
        })()`);
        await schlafe(1500);
        const nachher = lernstand();
        ok('Freies Üben rührt den Lernstand nicht an',
           nachher.zeilen === vorher.zeilen && nachher.summe === vorher.summe,
           'progress ' + vorher.zeilen + '->' + nachher.zeilen
           + ', Summe ' + vorher.summe + '->' + nachher.summe);
        ok('Zählt aber für den Tag - und damit für die Serie',
           nachher.tag === vorher.tag + 6, vorher.tag + ' -> ' + nachher.tag);

        // Hinaus geht es über den Zurück-Pfeil.
        await fr.js(`document.querySelector('[data-back]').click()`);
        await schlafe(1200);
        ok('Der Zurück-Pfeil führt in den Kurs',
           (await fr.js(`location.hash`)).startsWith('#/lang/'));
    } finally {
        await fr.schliessen();
    }

    // ------------------------------------------------------ Der Kalender

    abschnitt('Der Kalender im Konto');

    /*
     * Ein halbes Jahr Verlauf erfinden, mit einem dreistelligen Tag darin -
     * die Zahl der richtigen Antworten kann bis 999 gehen, und sie muss in
     * das Kästchen passen, auch bei 320 px Fensterbreite.
     */
    php(wurzel,
        "require 'lib/db.php'; require 'lib/streak.php';"
        + "$uid = (int) qv('SELECT id FROM users WHERE username = ?', ['" + f.kind + "']);"
        + "q('DELETE FROM learn_days WHERE user_id = ?', [$uid]);"
        + "q('UPDATE users SET created_at = DATE_SUB(NOW(), INTERVAL 200 DAY)"
        + "   WHERE id = ?', [$uid]);"
        + "$zone = new DateTimeZone(STREAK_ZONE);"
        + "for ($i = 0; $i < 190; $i++) {"
        + "  if ($i % 3 === 2) continue;"
        + "  $tag = (new DateTimeImmutable(streak_heute(), $zone))"
        + "           ->modify(\"-$i days\")->format('Y-m-d');"
        + "  q('INSERT INTO learn_days (user_id, `day`, learned, correct)"
        + "     VALUES (?, ?, ?, ?)',"
        + "    [$uid, $tag, ($i % 4 === 0) ? 2 : 0,"
        + "     $i === 1 ? 999 : (2 + ($i * 7) % 40)]);"
        + "}");

    const k = await browser({ port: 9417, breite: 320, hoehe: 1000, aus });
    try {
        await alsKind(k, f.basis, f.kind, f.passwort);
        await k.geh(f.basis + '/#/konto', 3000);
        await schlafe(800);

        const lage = () => k.js(`(() => {
            const g = document.getElementById('monatsgitter');
            const z = [...g.children];
            return {
                monat:   document.getElementById('monat-name').textContent,
                spalten: getComputedStyle(g).gridTemplateColumns.split(' ').length,
                heute:   z.filter((e) => e.classList.contains('heute')).length,
                zahlen:  z.filter((e) => e.textContent.trim() !== '').length,
                gross:   z.filter((e) => e.textContent.trim().length === 3).length,
                ueber:   z.filter((e) => e.scrollWidth > e.clientWidth + 1
                                      || e.scrollHeight > e.clientHeight + 1).length,
                zurueckAus: document.getElementById('monat-zurueck').disabled,
                vorAus:     document.getElementById('monat-vor').disabled,
            };
        })()`);

        let l = await lage();
        ok('Sieben Spalten - eine Woche je Zeile', l.spalten === 7, String(l.spalten));
        ok('Der laufende Monat steht oben', /\d{4}$/.test(l.monat.trim()), l.monat);
        ok('Heute ist markiert', l.heute === 1, String(l.heute));
        ok('In den Kästchen stehen Zahlen', l.zahlen > 5, String(l.zahlen));
        ok('Auch eine dreistellige ist dabei', l.gross >= 1, String(l.gross));
        /*
         * Bei 320 px ist das Kästchen rund 31 px breit. Eine 999, die dort
         * überläuft, wäre die eine Zahl, die niemand lesen kann - und die
         * fällt genau dem Kind auf, das am meisten geübt hat.
         */
        ok('Und keine läuft über den Rand hinaus', l.ueber === 0, String(l.ueber));
        ok('Vorwärts geht es nicht über den laufenden Monat hinaus', l.vorAus === true);

        // Zurück bis an die Grenze: zwölf Monate oder das Anlegedatum.
        let schritte = 0;
        while (!(await k.js(`document.getElementById('monat-zurueck').disabled`))
               && schritte < 20) {
            await k.js(`document.getElementById('monat-zurueck').click()`);
            await schlafe(70);
            schritte++;
        }
        ok('Zurück geht es bis zum Anlegen des Kontos, dann ist Schluss',
           schritte === 6, schritte + ' Monate - das Konto ist 200 Tage alt');
        l = await lage();
        ok('Dort steht der Pfeil still', l.zurueckAus === true);
        ok('Und der Kalender zeigt trotzdem einen Monat', l.spalten === 7);
        if (aus) await k.bild('kalender-monat');
    } finally {
        await k.schliessen();
    }
}
