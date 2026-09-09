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
            <label for="per_hour">Analysen pro Kind und Stunde</label>
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

<h2>Admin-Passwort</h2>
<form method="post" class="card">
    <?= csrf_field() ?>
    <label for="new_password">Neues Passwort</label>
    <input type="password" id="new_password" name="new_password"
           autocomplete="new-password" minlength="8">
    <button class="btn small" name="save_password" value="1">Passwort ändern</button>
</form>

<?php admin_foot(); ?>
