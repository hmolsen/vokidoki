/*
 * Ring und Haken auf der Kursseite: Was nach dem Freigeben im Hintergrund
 * entsteht, schickt der Server (teacher/erzeugung.php) - die Seite lädt
 * dabei nicht neu.
 *
 * Die Prüfung spielt den Hintergrund selbst: Sie setzt eine Lerneinheit auf
 * "Sätze entstehen", trägt die Sätze nach, hält in einem zweiten Prozess die
 * Sperre der Aufnahmen (wie tts_nachtragen()) und lässt dann wirklich
 * sprechen - über den Azure-Simulator.
 */

import { execFileSync, spawn } from 'node:child_process';

import { browser, alsLehrkraft, ok, abschnitt, schlafe } from './browser.mjs';

const php = (wurzel, code) =>
    execFileSync('php', ['-r', code], { cwd: wurzel, encoding: 'utf8' });

/** Wartet, bis die Zelle den Stand hat - höchstens sekunden lang. */
async function warten(b, unit, art, soll, sekunden = 15) {
    for (let i = 0; i < sekunden * 4; i++) {
        const ist = await b.js(`document.querySelector('tr[data-unit="${unit}"] td[data-erz="${art}"]')?.dataset.stand`);
        if (ist === soll) return true;
        await schlafe(250);
    }
    return false;
}

export async function pruefe(f, aus, wurzel) {
    abschnitt('Kursseite: Ring und Haken für Sätze und Aufnahmen');

    // Eine eigene Lerneinheit mit drei freigegebenen Vokabeln, deren Sätze
    // "gerade entstehen".
    const unit = Number(php(wurzel, `require 'lib/db.php'; require 'lib/vocab.php';
        $k = q1('SELECT * FROM courses WHERE id = ?', [${f.kurs}]);
        q("INSERT INTO units (language_id, course_id, title, released_position, position,
                              sentences_status, sentences_started_at)
           VALUES (?, ?, 'Ring-Probe', 3, 98, 'running', NOW())", [(int) $k['language_id'], (int) $k['id']]);
        $u = (int) db()->lastInsertId();
        foreach (['apple', 'bridge', 'candle'] as $i => $w) {
            q('INSERT INTO vocab (unit_id, position, term_foreign, term_native) VALUES (?, ?, ?, ?)',
              [$u, $i, $w, 'de-' . $w]);
        }
        echo $u;`));

    const b = await browser({ port: 9481, breite: 1200, hoehe: 1000, aus });
    try {
        await alsLehrkraft(b, f.basis, f.lehrer, f.passwort, f.kuerzel);
        await b.geh(f.basis + '/teacher/course.php?id=' + f.kurs, 1500);

        const kopf = await b.js(`[...document.querySelectorAll('#einheiten th.erzspalte')].map((t) => t.title)`);
        ok('Zwei schmale Spalten: Lückensätze und Aufnahmen', JSON.stringify(kopf) === '["Lückensätze","Aufnahmen zum Hören"]',
           JSON.stringify(kopf));

        const zellen = () => b.js(`(() => {
            const z = (art) => document.querySelector('tr[data-unit="${unit}"] td[data-erz="' + art + '"]');
            return { saetze: z('saetze')?.dataset.stand, ton: z('ton')?.dataset.stand,
                     ring: !!z('saetze')?.querySelector('.erz.laeuft'),
                     haken: !!z('saetze')?.querySelector('.erz.fertig'),
                     eben: z('saetze')?.classList.contains('eben') };
        })()`);
        const anfang = await zellen();
        ok('Solange die Sätze entstehen, dreht sich ein Ring', anfang.saetze === 'laeuft' && anfang.ring,
           JSON.stringify(anfang));
        ok('Die Aufnahmen warten auf die Sätze', anfang.ton === 'wartet', JSON.stringify(anfang));

        // Merkt sich die Seite - nach einem Neuladen wäre die Marke weg.
        await b.js(`window.__ohneNeuladen = true`);

        // Die Sätze sind fertig.
        php(wurzel, `require 'lib/db.php';
            foreach (qa('SELECT id, term_foreign FROM vocab WHERE unit_id = ?', [${unit}]) as $v) {
                q('INSERT INTO sentences (vocab_id, native_text, foreign_text, answer) VALUES (?, ?, ?, ?)',
                  [(int) $v['id'], 'Das ist ' . $v['term_foreign'] . '.', 'This is {}.', $v['term_foreign']]);
            }
            q("UPDATE units SET sentences_status = 'done' WHERE id = ?", [${unit}]);`);

        ok('Der Ring wird zum Haken - vom Server geschickt', await warten(b, unit, 'saetze', 'fertig'));
        const danach = await zellen();
        ok('Mit dem Haken, der einmal aufspringt', danach.haken && danach.eben, JSON.stringify(danach));
        ok('Die Aufnahmen fehlen noch', await warten(b, unit, 'ton', 'fehlt'));

        /*
         * Jetzt wird gesprochen. Ein zweiter Prozess hält die Sperre von
         * tts_nachtragen() ein paar Sekunden, wie ein langer Lauf - dann
         * spricht er wirklich, über den Simulator.
         */
        const lauf = spawn('php', ['-r', `require 'lib/db.php'; require 'lib/tts.php';
            qv('SELECT GET_LOCK(?, 0)', ['vt-tts-${unit}']);
            sleep(8);
            qv('SELECT RELEASE_LOCK(?)', ['vt-tts-${unit}']);
            $l = q1("SELECT * FROM users WHERE username = ?", ['${f.lehrer}']);
            $r = tts_nachtragen(${unit}, $l);
            if ($r['fehler'] !== null) { fwrite(STDERR, $r['fehler']); exit(1); }`], { cwd: wurzel });
        let fehler = '';
        lauf.stderr.on('data', (d) => { fehler += d; });
        const fertig = new Promise((ja) => lauf.on('exit', ja));

        ok('Während gesprochen wird, dreht sich der Ring bei den Aufnahmen', await warten(b, unit, 'ton', 'laeuft'));
        const code = await fertig;
        ok('Der Lauf hat gesprochen', code === 0, fehler);
        ok('Danach steht dort ein Haken', await warten(b, unit, 'ton', 'fertig'));
        ok('Und die Seite wurde dabei nie neu geladen', await b.js(`window.__ohneNeuladen === true`));
    } finally {
        await b.schliessen();
        php(wurzel, `require 'lib/db.php'; q('DELETE FROM units WHERE id = ?', [${unit}]);`);
    }
}
