<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/html.php';

/**
 * Die Hinweise bei der ersten Anmeldung.
 *
 * Bevor ein Konto die App benutzt, bestätigt es einmal, was es wissen muss.
 * Eine Lehrkraft: dass sie Datenschutzerklärung und Impressum gelesen hat.
 * Ein Kind darüber hinaus, dass die Nutzung freiwillig ist, dass seine
 * Lehrkraft nicht sieht, ob und wie es übt, und - unter 16 - dass seine
 * Eltern einverstanden sind.
 *
 * Die Regel steht hier und nur hier: welche Punkte, wann gefragt wird, wie
 * gespeichert wird. Die App (views/einwilligung.js) und der Lehrkraft-Bereich
 * (teacher/einwilligung.php) zeichnen nur, was diese Datei liefert; die
 * Schranke davor steht in require_user() und teacher_require().
 *
 * DER SATZ ÜBER DIE LEHRKRAFT MUSS STIMMEN. Er ist nicht Werbung, sondern
 * eine Zusage, die ein Kind bestätigt und die auf dem Zettel an die Eltern
 * steht. Als er dazukam, stimmte er an zwei Stellen nicht: Meldungen zeigten
 * den Namen des Kindes, und die Klassenliste zeigte "selbst geändert", sobald
 * ein Kind sein Anfangspasswort geändert hatte - beides verriet, wer die App
 * benutzt. Beides ist geändert (lib/meldungen.php, teacher/class.php), und
 * tests/e2e.php hält es fest. Wer der Lehrkraft künftig etwas über einzelne
 * Kinder zeigen will, muss diesen Satz mitändern und EINWILLIGUNG_FASSUNG
 * erhöhen.
 */

/**
 * Die Fassung der Hinweise.
 *
 * Erhöhen, wenn sich an dem, was bestätigt wird, etwas ändert - etwa an der
 * Datenschutzerklärung in einem Punkt, der die Kinder betrifft. Dann fragt
 * die App jedes Konto beim nächsten Mal noch einmal.
 */
const EINWILLIGUNG_FASSUNG = 1;

/** Muss dieses Konto die Hinweise (noch einmal) bestätigen? */
function einwilligung_noetig(array $user): bool
{
    return (int) ($user['consent_version'] ?? 0) < EINWILLIGUNG_FASSUNG;
}

/**
 * Was bestätigt wird - je nach Rolle.
 *
 * Jeder Punkt ist ein eigenes Häkchen. Ein einziges Häkchen für alles wäre
 * kürzer, aber ein Kind soll jeden Satz einzeln gelesen haben; das Häkchen
 * daneben ist der Weg, der dafür sorgt.
 *
 * Geliefert wird fertiges HTML: Die Links auf die beiden Dokumente stehen
 * mitten im Satz, und der Satz kommt nur von hier - nie aus einer Eingabe.
 *
 * @return list<array{schluessel: string, html: string}>
 */
function einwilligung_punkte(array $user): array
{
    $link = static fn (string $d, string $text): string => sprintf(
        '<a href="%s" target="_blank" rel="noopener">%s</a>',
        h(url('/rechtliches.php?d=' . $d)), h($text),
    );

    $gelesen = [
        'schluessel' => 'gelesen',
        'html'       => 'Ich habe die ' . $link('datenschutz', 'Datenschutzerklärung')
                      . ' und das ' . $link('impressum', 'Impressum') . ' gelesen.',
    ];

    if (($user['role'] ?? '') === 'teacher') {
        return [$gelesen];
    }

    return [
        $gelesen,
        [
            'schluessel' => 'freiwillig',
            'html'       => 'Ich weiß, dass ich Vokidoki <strong>freiwillig</strong> nutze. '
                          . 'Wer nicht mitmacht, hat in der Schule keinen Nachteil.',
        ],
        [
            'schluessel' => 'lehrkraft',
            'html'       => 'Ich weiß, dass meine Lehrkraft <strong>nicht sieht</strong>, '
                          . 'ob ich übe, wie oft ich übe und wie weit ich bin.',
        ],
        [
            'schluessel' => 'eltern',
            'html'       => 'Ich bin 16 Jahre oder älter &ndash; oder meine '
                          . '<strong>Eltern sind einverstanden</strong>, dass ich Vokidoki nutze.',
        ],
    ];
}

/**
 * Die Bestätigung speichern.
 *
 * Nur, wenn wirklich jeder Punkt angehakt ist - die Prüfung im Browser ist
 * Bequemlichkeit, diese hier ist die Regel. Gespeichert werden Fassung und
 * Zeitpunkt; welche Häkchen es waren, ergibt sich aus beidem.
 *
 * @param list<string> $angehakt die Schlüssel der angehakten Punkte
 * @return string|null eine Meldung, wenn etwas fehlt; null, wenn gespeichert
 */
function einwilligung_speichern(array $user, array $angehakt): ?string
{
    foreach (einwilligung_punkte($user) as $p) {
        if (!in_array($p['schluessel'], $angehakt, true)) {
            return 'Bitte hake jeden Punkt an.';
        }
    }

    q('UPDATE users SET consent_version = ?, consent_at = NOW() WHERE id = ?',
      [EINWILLIGUNG_FASSUNG, (int) $user['id']]);
    return null;
}

/**
 * Was die App über die Hinweise wissen muss: null, wenn alles bestätigt ist,
 * sonst die Punkte zum Anhaken.
 */
function einwilligung_fuer_app(array $user): ?array
{
    return einwilligung_noetig($user) ? ['punkte' => einwilligung_punkte($user)] : null;
}
