<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../lib/tts.php';

admin_require();

/*
 * Die Ausspracheliste der Aufnahmen - siehe lib/tts.php.
 *
 * Eine Liste für alle Schulen. Lehrkräfte setzen Wörter darauf, wenn sie
 * in einer Meldung hören, dass die Stimme danebenliegt; hier lässt sich
 * alles ansehen, ändern und löschen. Nach jeder Änderung werden die Sätze
 * mit dem Wort neu gesprochen - gleich, und auf Rechnung ihrer Kurse.
 *
 * Und eine Hörprobe: einen Satz so sprechen lassen, wie ihn die Kinder
 * hören würden, mit allen Regeln (tts_sprechfassung()). Damit lässt sich
 * prüfen, ob ein Eintrag wirkt - und ob die Stimme am Satzende ohne Punkt
 * richtig nach unten geht.
 */

$sprachen = [];
foreach (array_keys(TTS_STIMMEN) as $code) {
    $sprachen[$code] = (string) (qv('SELECT name FROM languages WHERE code = ? ORDER BY id LIMIT 1', [$code])
                                 ?? strtoupper($code));
}
asort($sprachen);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    // ---- Hörprobe: als fetch, die Antwort ist die Aufnahme selbst.
    if (isset($_POST['probe'])) {
        $code   = (string) ($_POST['sprache'] ?? '');
        $satz   = trim((string) ($_POST['satz'] ?? ''));
        $stimme = tts_stimme($code);
        $fehler = match (true) {
            $stimme === null                => 'Für diese Sprache gibt es keine Stimme.',
            $satz === '' || mb_strlen($satz) > 300 => 'Bitte einen Satz (bis 300 Zeichen).',
            !tts_aktiv()                    => 'Die Aufnahmen sind in den Einstellungen abgeschaltet.',
            tts_tarif_frei() && tts_zeichen_monat() + mb_strlen($satz)
                > tts_freikontingent() * TTS_KONTINGENT_RAND => 'Das Freikontingent dieses Monats ist aufgebraucht.',
            default                         => null,
        };
        if ($fehler === null) {
            try {
                $antwort = tts_anfragen(keyvault_tts_key(), [['id' => 0, 'text' => $satz]], $stimme)[0] ?? null;
            } catch (KeyvaultException) {
                $antwort = ['fehler' => 'Für die Aufnahmen fehlt der Schlüssel im Keyvault.'];
            }
            ai_log([
                'user_id'      => null,
                'user_label'   => 'Admin (Hörprobe)',
                'model'        => $stimme['name'],
                'purpose'      => 'tts',
                'input_tokens' => mb_strlen($satz),
                'entry_count'  => is_string($antwort) ? 1 : 0,
                'cost_usd'     => tts_kosten(mb_strlen($satz)),
                'status'       => is_string($antwort) ? 'ok' : 'error',
                'error'        => is_string($antwort) ? null : (string) ($antwort['fehler'] ?? 'keine Antwort'),
            ]);
            if (is_string($antwort)) {
                header('Content-Type: audio/mpeg');
                header('X-Sprechfassung: ' . rawurlencode(tts_sprechfassung($satz, $code)['ssml']));
                echo $antwort;
                exit;
            }
            $fehler = 'Azure: ' . ($antwort['fehler'] ?? 'keine Antwort');
        }
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        echo $fehler;
        exit;
    }

    set_time_limit(300);
    $neu = static fn (string $code, string $wort): int => tts_alias_nachsprechen($code, $wort);

    if (isset($_POST['add']) || isset($_POST['save'])) {
        // Gespeichert wird eine Zeile der Tabelle (Felder je id), angelegt
        // mit der leeren Zeile darunter (einzelne Felder).
        $id     = (int) ($_POST['save'] ?? 0);
        $feld   = static fn (string $n): string => (string) ($id > 0
            ? (is_array($_POST[$n] ?? null) ? ($_POST[$n][$id] ?? '') : '')
            : (is_string($_POST[$n] ?? null) ? $_POST[$n] : ''));
        $vorher = $id > 0 ? q1('SELECT sprache, wort FROM tts_aliase WHERE id = ?', [$id]) : null;
        $code   = $feld('sprache');
        $wort   = $feld('wort');
        $fehler = tts_alias_setzen($code, $wort, $feld('aussprache'), $id);
        if ($fehler !== null) {
            flash($fehler, 'bad');
        } else {
            $n = $neu(strtolower(trim($code)), trim($wort));
            // Hiess das Wort vorher anders, klingen auch dessen Sätze jetzt wieder wie zuvor.
            if ($vorher !== null && mb_strtolower((string) $vorher['wort']) !== mb_strtolower(trim($wort))) {
                $n += $neu((string) $vorher['sprache'], (string) $vorher['wort']);
            }
            flash(sprintf('„%s" gemerkt.%s', trim($wort),
                $n > 0 ? sprintf(' %d %s neu gesprochen.', $n, $n === 1 ? 'Satz' : 'Sätze') : ''));
        }
        redirect('aussprache.php');
    }

    if (isset($_POST['delete'])) {
        $weg = tts_alias_loeschen((int) $_POST['delete']);
        if ($weg !== null) {
            $n = $neu((string) $weg['sprache'], (string) $weg['wort']);
            flash(sprintf('„%s" ist von der Liste.%s', $weg['wort'],
                $n > 0 ? sprintf(' %d %s neu gesprochen.', $n, $n === 1 ? 'Satz' : 'Sätze') : ''));
        }
        redirect('aussprache.php');
    }
}

$liste = tts_alias_liste();
$wahl  = static function (string $name, string $aktiv, string $form = '') use ($sprachen): string {
    $html = '<select name="' . h($name) . '"' . ($form !== '' ? ' form="' . h($form) . '"' : '') . ' required>';
    foreach ($sprachen as $code => $label) {
        $html .= sprintf('<option value="%s"%s>%s</option>', h($code),
                         $code === $aktiv ? ' selected' : '', h($label));
    }
    return $html . '</select>';
};

admin_head('Aussprache', 'aussprache.php');
flash_render();
?>

<?php
// Die Überschrift setzt admin_head() - hier stand sie ein zweites Mal.
?>
<p class="unterzeile">
    Wörter, die die Stimme falsch liest, und wie sie klingen sollen &ndash; eine Liste für alle
    Schulen. Nach jeder Änderung werden die Sätze mit dem Wort neu gesprochen.
</p>
<p class="tiny muted">
    Ein Punkt am Satzende wird nie mitgesprochen (sonst wäre dänisch &bdquo;kat.&ldquo; ein
    &bdquo;katalog&ldquo;) &ndash; dafür braucht es keinen Eintrag.
</p>

<div class="card hoerprobe">
    <h3>&#127911; Hörprobe</h3>
    <p class="tiny muted">So klingt ein Satz mit allen Regeln und dieser Liste &ndash; wie für die Kinder.
        Kostet die Zeichen des Satzes.</p>
    <form id="hoerprobe" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="probe" value="1">
        <?= $wahl('sprache', 'da') ?>
        <input type="text" name="satz" maxlength="300" required placeholder="Jeg har en kat." style="flex:1;min-width:14em">
        <button class="btn">Anhören</button>
    </form>
    <p class="tiny muted" id="probeinfo" hidden></p>
</div>

<h2>Die Liste</h2>
<form method="post" id="aliasform"><?= csrf_field() ?></form>
<table class="data">
    <tr><th>Sprache</th><th>Wort</th><th>So klingt es</th><th class="actions"></th></tr>
    <?php foreach ($liste as $a): ?>
        <?php $id = (int) $a['id']; ?>
        <tr>
            <td data-label="Sprache"><?= $wahl('sprache[' . $id . ']', $a['sprache'], 'aliasform') ?></td>
            <td data-label="Wort"><input type="text" name="wort[<?= $id ?>]" form="aliasform"
                    value="<?= h($a['wort']) ?>" maxlength="64" required></td>
            <td data-label="So klingt es"><input type="text" name="aussprache[<?= $id ?>]" form="aliasform"
                    value="<?= h($a['aussprache']) ?>" maxlength="128" required></td>
            <td class="actions">
                <button class="iconaction" form="aliasform" name="save" value="<?= $id ?>">Speichern</button>
                <button class="iconaction danger" form="aliasform" name="delete" value="<?= $id ?>" formnovalidate
                        data-confirm="„<?= h($a['wort']) ?>" von der Liste nehmen?">Löschen</button>
            </td>
        </tr>
    <?php endforeach; ?>
    <tr class="newrow">
        <td data-label="Sprache"><?= $wahl('sprache', 'da', 'neualias') ?></td>
        <td data-label="Wort"><input type="text" name="wort" form="neualias" maxlength="64" required placeholder="fx"></td>
        <td data-label="So klingt es"><input type="text" name="aussprache" form="neualias" maxlength="128" required
                placeholder="for eksempel"></td>
        <td class="actions"><button class="iconaction primary" form="neualias" name="add" value="1">Hinzufügen</button></td>
    </tr>
</table>
<form method="post" id="neualias"><?= csrf_field() ?></form>
<?php if ($liste === []): ?>
    <p class="tiny muted">Noch kein Wort auf der Liste.</p>
<?php endif; ?>

<script>
// Die Hörprobe: die Aufnahme kommt als Antwort zurück und wird gleich gespielt.
document.getElementById('hoerprobe').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.currentTarget;
    const info = document.getElementById('probeinfo');
    const knopf = form.querySelector('button');
    knopf.disabled = true;
    info.hidden = false;
    info.textContent = 'Wird gesprochen …';
    try {
        const res = await fetch(location.pathname, { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
        if (!res.ok) throw new Error(await res.text());
        const fassung = decodeURIComponent(res.headers.get('X-Sprechfassung') || '');
        new Audio(URL.createObjectURL(await res.blob())).play();
        info.textContent = 'An Azure ging: ' + fassung;
    } catch (fehler) {
        info.textContent = fehler.message || 'Das ging nicht.';
    } finally {
        knopf.disabled = false;
    }
});
</script>
<?php
admin_foot();
