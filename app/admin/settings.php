<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

admin_require();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    if (isset($_POST['save_model'])) {
        $model = (string) ($_POST['vision_model'] ?? '');
        if (!array_key_exists($model, VISION_MODELS)) {
            flash('Unbekanntes Modell.', 'bad');
            redirect('settings.php');
        }
        $effort = (string) ($_POST['vision_effort'] ?? 'medium');
        if (!in_array($effort, EFFORT_LEVELS, true)) {
            $effort = 'medium';
        }

        setting_set('vision_model', $model);
        setting_set('vision_effort', $effort);
        flash('Modell gespeichert: ' . $model);
        redirect('settings.php');
    }

    if (isset($_POST['save_letter'])) {
        /*
         * Reiner Text, unveraendert gespeichert. Die Vorlage geht an alle
         * Kinder einer Schule; liesse sie Markup zu, waere sie eine offene
         * Tuer. Ausgegeben wird sie in teacher/print.php durch h().
         */
        $text = (string) ($_POST['letter_template'] ?? '');

        if (trim($text) === '') {
            setting_set('letter_template', '');
            flash('Anschreiben auf die Standardfassung zurückgesetzt.');
            redirect('settings.php');
        }

        $fehlend = [];
        foreach (['name', 'benutzername', 'passwort'] as $noetig) {
            if (!str_contains($text, '{' . $noetig . '}')) {
                $fehlend[] = '{' . $noetig . '}';
            }
        }

        // Kein Verbot, nur ein Hinweis: Vielleicht steht der Name ja schon in
        // der Kopfzeile des Blattes, und das Anschreiben braucht ihn nicht.
        setting_set('letter_template', $text);
        flash($fehlend === []
            ? 'Anschreiben gespeichert.'
            : 'Anschreiben gespeichert - ohne ' . implode(' und ', $fehlend)
              . '. Das ist erlaubt, aber bitte einmal Probe drucken.');
        redirect('settings.php');
    }

    if (isset($_POST['save_words'])) {
        $art = (string) ($_POST['word_kind'] ?? '');
        if (!in_array($art, [PW_ADJECTIVE, PW_ANIMAL], true)) {
            flash('Unbekannte Wortart.', 'bad');
            redirect('settings.php');
        }

        [$anzahl, $meldung] = password_words_replace($art, (string) ($_POST['words'] ?? ''));

        if ($meldung !== null) {
            flash($meldung, 'bad');
        } else {
            flash(sprintf('%d %s gespeichert.', $anzahl,
                $art === PW_ADJECTIVE ? 'Adjektive' : 'Tiere'));
        }
        redirect('settings.php');
    }

    if (isset($_POST['save_sentences'])) {
        $model = (string) ($_POST['sentence_model'] ?? '');
        if (!array_key_exists($model, VISION_MODELS)) {
            flash('Unbekanntes Modell.', 'bad');
            redirect('settings.php');
        }
        $per = max(1, min(5, (int) ($_POST['per_vocab'] ?? 3)));

        setting_set('sentence_model', $model);
        setting_set('sentences_per_vocab', (string) $per);
        flash('Einstellungen für den Lückentext gespeichert.');
        redirect('settings.php');
    }

    if (isset($_POST['save_budget'])) {
        $cap  = max(0.0, (float) str_replace(',', '.', (string) ($_POST['cap'] ?? '0')));
        $rate = max(0.0, (float) str_replace(',', '.', (string) ($_POST['rate'] ?? '0.92')));
        $per  = max(0, (int) ($_POST['per_hour'] ?? 20));

        setting_set('monthly_cost_cap_usd', number_format($cap, 2, '.', ''));
        setting_set('usd_eur', number_format($rate, 4, '.', ''));
        setting_set('imports_per_hour', (string) $per);
        flash('Budget und Limits gespeichert.');
        redirect('settings.php');
    }

    if (isset($_POST['save_prices'])) {
        $prices = [];
        foreach (array_keys(VISION_MODELS) as $model) {
            $prices[$model] = [
                'in'          => (float) str_replace(',', '.', (string) ($_POST['in'][$model] ?? '0')),
                'out'         => (float) str_replace(',', '.', (string) ($_POST['out'][$model] ?? '0')),
                'cache_read'  => (float) str_replace(',', '.', (string) ($_POST['cr'][$model] ?? '0')),
                'cache_write' => (float) str_replace(',', '.', (string) ($_POST['cw'][$model] ?? '0')),
            ];
        }
        setting_set('prices_json', json_encode($prices, JSON_UNESCAPED_SLASHES));
        flash('Preistabelle gespeichert.');
        redirect('settings.php');
    }

    if (isset($_POST['save_password'])) {
        $new = (string) ($_POST['new_password'] ?? '');
        if (strlen($new) < 8) {
            flash('Das Admin-Passwort braucht mindestens 8 Zeichen.', 'bad');
        } else {
            setting_set('admin_password_hash', password_hash($new, PASSWORD_DEFAULT));
            flash('Admin-Passwort geändert.');
        }
        redirect('settings.php');
    }
}

$prices  = price_table();
$current = setting('vision_model', 'claude-opus-5');
$effort  = setting('vision_effort', 'medium');

admin_head('Einstellungen', 'settings.php');
flash_render();
?>

<h2>Modell für die Bilderkennung</h2>
<form method="post" class="card">
    <?= csrf_field() ?>
    <div class="formgrid">
        <div>
            <label for="vision_model">Modell</label>
            <select name="vision_model" id="vision_model">
                <?php foreach (VISION_MODELS as $id => $label): ?>
                    <option value="<?= h($id) ?>"<?= $id === $current ? ' selected' : '' ?>>
                        <?= h($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="vision_effort">Aufwand</label>
            <select name="vision_effort" id="vision_effort">
                <?php foreach (EFFORT_LEVELS as $level): ?>
                    <option value="<?= h($level) ?>"<?= $level === $effort ? ' selected' : '' ?>>
                        <?= h($level) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <p class="tiny muted">
        Opus 5 liest Handschrift und enge Buchlayouts am zuverlässigsten.
        Sonnet 5 kostet rund 60&nbsp;% weniger und reicht für sauber gedruckte Listen.
        Der Aufwand steuert, wie gründlich das Modell arbeitet - <code>medium</code>
        ist für das Abtippen von Vokabelseiten die passende Stufe.
    </p>
    <button class="btn small" name="save_model" value="1">Speichern</button>
</form>

<h2>Lückentext</h2>
<form method="post" class="card">
    <?= csrf_field() ?>
    <div class="formgrid">
        <div>
            <label for="sentence_model">Modell für die Sätze</label>
            <select name="sentence_model" id="sentence_model">
                <?php foreach (VISION_MODELS as $id => $label): ?>
                    <option value="<?= h($id) ?>"<?= $id === setting('sentence_model') ? ' selected' : '' ?>>
                        <?= h($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="per_vocab">Sätze je Vokabel</label>
            <select name="per_vocab" id="per_vocab">
                <?php foreach ([1, 2, 3, 4, 5] as $n): ?>
                    <option value="<?= $n ?>"<?= (string) $n === setting('sentences_per_vocab') ? ' selected' : '' ?>>
                        <?= $n ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <p class="tiny muted">
        Die Sätze entstehen im Hintergrund, gleich nachdem eine Lerneinheit
        eingelesen ist, in einem Aufruf für die ganze Lerneinheit und auf
        Rechnung des Kurses &ndash; einzeln abgefragt wäre dasselbe rund siebenmal
        so teuer, weil Anweisung und Wortschatz jedes Mal mitbezahlt würden.
        Drei Sätze passen zur Lernregel &bdquo;dreimal hintereinander richtig&ldquo;.
        Bei 60 Vokabeln und drei Sätzen kostet eine Lerneinheit einmalig rund
        8&nbsp;ct mit Sonnet 5, rund 21&nbsp;ct mit Opus 5.
    </p>
    <button class="btn small" name="save_sentences" value="1">Speichern</button>
</form>

<h2>Budget und Limits</h2>
<form method="post" class="card">
    <?= csrf_field() ?>
    <div class="formgrid">
        <div>
            <label for="cap">Monatslimit in USD (0 = kein Limit)</label>
            <input type="text" id="cap" name="cap" inputmode="decimal"
                   value="<?= h(setting('monthly_cost_cap_usd', '10.00')) ?>">
        </div>
        <div>
            <label for="rate">Kurs USD &rarr; EUR</label>
            <input type="text" id="rate" name="rate" inputmode="decimal"
                   value="<?= h(setting('usd_eur', '0.92')) ?>">
        </div>
        <div>
            <label for="per_hour">Analysen pro Konto und Stunde</label>
            <input type="text" id="per_hour" name="per_hour" inputmode="numeric"
                   value="<?= h(setting('imports_per_hour', '20')) ?>">
        </div>
    </div>
    <p class="tiny muted">
        Ist das Monatslimit erreicht, blockiert die App die Bilderkennung, bevor
        eine Anfrage an die API geht. Der Kurs dient nur der Anzeige in Euro.
    </p>
    <button class="btn small" name="save_budget" value="1">Speichern</button>
</form>

<h2>Preistabelle</h2>
<form method="post">
    <?= csrf_field() ?>
    <table class="data">
        <tr>
            <th>Modell</th>
            <th class="num">Input $/1M</th>
            <th class="num">Output $/1M</th>
            <th class="num">Cache lesen $/1M</th>
            <th class="num">Cache schreiben $/1M</th>
        </tr>
        <?php foreach (VISION_MODELS as $id => $label): ?>
            <?php $p = $prices[$id] ?? []; ?>
            <tr>
                <td><?= h($id) ?></td>
                <td class="num"><input type="text" name="in[<?= h($id) ?>]" inputmode="decimal"
                        value="<?= h((string) ($p['in'] ?? 0)) ?>" style="width:90px;text-align:right"></td>
                <td class="num"><input type="text" name="out[<?= h($id) ?>]" inputmode="decimal"
                        value="<?= h((string) ($p['out'] ?? 0)) ?>" style="width:90px;text-align:right"></td>
                <td class="num"><input type="text" name="cr[<?= h($id) ?>]" inputmode="decimal"
                        value="<?= h((string) ($p['cache_read'] ?? 0)) ?>" style="width:90px;text-align:right"></td>
                <td class="num"><input type="text" name="cw[<?= h($id) ?>]" inputmode="decimal"
                        value="<?= h((string) ($p['cache_write'] ?? 0)) ?>" style="width:90px;text-align:right"></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <p class="tiny muted">
        Preise in US-Dollar je eine Million Token. Ändert Anthropic die Preise,
        hier nachziehen - bereits protokollierte Anfragen behalten ihren damals
        berechneten Betrag.
    </p>
    <button class="btn small" name="save_prices" value="1">Speichern</button>
</form>

<h2>Anschreiben für die Kinder (Voreinstellung)</h2>
<form method="post" class="card">
    <?= csrf_field() ?>
    <p class="tiny muted" style="margin-top:0">
        Diese Fassung gilt für jede Lehrkraft, die keine eigene hat. Jede
        Lehrkraft kann sie unter <em>Mein Konto</em> für ihre eigenen Zettel
        anpassen - und dort jederzeit wieder auf diese zurückstellen.
    </p>
    <label for="letter">Text des Zettels</label>
    <textarea id="letter" name="letter_template" rows="18"
              style="width:100%;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.9rem"
    ><?= h(letter_standard()) ?></textarea>
    <p class="tiny muted">
        Reiner Text, kein HTML - so kann eine Formulierung nichts kaputtmachen.
        Diese Platzhalter werden ersetzt:
        <?php foreach (letter_placeholders() as $p => $was): ?>
            <br><code class="token">{<?= h($p) ?>}</code> &ndash; <?= h($was) ?>
        <?php endforeach; ?>
        <br><br>Leeren und speichern stellt die Standardfassung wieder her.
    </p>
    <button class="btn small" name="save_letter" value="1">Speichern</button>
</form>

<h2>Wörter für die Anfangspasswörter</h2>

<div class="stats" style="align-items:start">
    <form method="post" class="card">
        <?= csrf_field() ?>
        <input type="hidden" name="word_kind" value="<?= h(PW_ADJECTIVE) ?>">
        <label for="adj">Adjektive (<?= count(password_words(PW_ADJECTIVE)) ?>)</label>
        <textarea id="adj" name="words" rows="14"
                  style="width:100%;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.88rem"
        ><?= h(password_words_text(PW_ADJECTIVE)) ?></textarea>
        <p class="tiny muted">
            Ein Wort je Zeile, als <strong>Stamm ohne Endung</strong> - also
            "müd", nicht "müde". Die Endung kommt beim Erzeugen dazu und richtet
            sich nach dem Tier. Nur regelmässige Adjektive: "dunkel" würde zu
            "dunkler" statt "dunkeler" und gehört deshalb nicht hierher.
        </p>
        <button class="btn small" name="save_words" value="1">Speichern</button>
    </form>

    <form method="post" class="card">
        <?= csrf_field() ?>
        <input type="hidden" name="word_kind" value="<?= h(PW_ANIMAL) ?>">
        <label for="tier">Tiere (<?= count(password_words(PW_ANIMAL)) ?>)</label>
        <textarea id="tier" name="words" rows="14"
                  style="width:100%;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.88rem"
        ><?= h(password_words_text(PW_ANIMAL)) ?></textarea>
        <p class="tiny muted">
            Ein Tier je Zeile, dahinter das Geschlecht: <code class="token">m</code>
            für der, <code class="token">f</code> für die,
            <code class="token">n</code> für das. Ohne das käme "müde Gepard"
            heraus, und das ist in einer Schule peinlich.
        </p>
        <button class="btn small" name="save_words" value="1">Speichern</button>
    </form>
</div>

<p class="tiny muted">
    Zusammen ergeben die Listen
    <strong><?= number_format(count(password_words(PW_ADJECTIVE))
                              * count(password_words(PW_ANIMAL)), 0, ',', '.') ?></strong>
    mögliche Anfangspasswörter. Ein gestrichenes Wort wird nur abgeschaltet, nicht
    gelöscht - versehentlich Entferntes kommt durch erneutes Eintragen zurück.
</p>

<h2>Admin-Passwort</h2>
<form method="post" class="card">
    <?= csrf_field() ?>
    <label for="new_password">Neues Passwort</label>
    <input type="password" id="new_password" name="new_password"
           autocomplete="new-password" minlength="8">
    <button class="btn small" name="save_password" value="1">Passwort ändern</button>
</form>

<?php admin_foot(); ?>
