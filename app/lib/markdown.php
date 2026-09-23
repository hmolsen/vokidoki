<?php
declare(strict_types=1);

require_once __DIR__ . '/html.php';

/**
 * Ein kleiner Markdown-Wandler - genau so viel, wie die Rechtstexte brauchen.
 *
 * Warum selbstgebaut und keine Bibliothek: `vendor/` entsteht auf dem Server
 * per composer install, und jede weitere Abhängigkeit ist eine, die dort
 * ankommen muss. Für drei Dokumente mit Überschriften, Absätzen, Listen und
 * ein paar Links wäre das ein schweres Werkzeug für eine leichte Sache.
 *
 * DIE REIHENFOLGE IST DIE SICHERHEIT: Erst wird ALLES maskiert, dann werden
 * die Markdown-Zeichen zu Auszeichnung. Damit kann in einem Dokument nichts
 * stehen, was zu HTML wird - auch nicht in einem Link. Wer eine dieser
 * Dateien ändert, ändert Text, nicht Markup.
 *
 * Was nicht unterstützt wird - bewusst: Bilder, Tabellen, eingebettetes HTML,
 * Codeblöcke. Nichts davon steht in den Dokumenten, und jedes davon wäre
 * mehr Oberfläche für Fehler.
 */

/** Wandelt Markdown in HTML. Die Eingabe wird vollständig maskiert. */
function markdown_to_html(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    $aus    = [];
    $absatz = [];
    /** @var array<int,string> $listen offene Listen, als Stapel von Einrückungen */
    $listen = [];

    $absatzSchliessen = static function () use (&$absatz, &$aus): void {
        if ($absatz === []) {
            return;
        }
        $aus[]  = '<p>' . implode("<br>\n", $absatz) . '</p>';
        $absatz = [];
    };

    $listenSchliessen = static function (int $bis = 0) use (&$listen, &$aus): void {
        while (count($listen) > $bis) {
            array_pop($listen);
            $aus[] = '</li></ul>';
        }
    };

    foreach (explode("\n", $text) as $zeile) {
        $roh  = rtrim($zeile, "\n");
        $trim = trim($roh);

        // ---- Leerzeile: beendet einen Absatz, nicht aber eine Liste.
        if ($trim === '') {
            $absatzSchliessen();
            continue;
        }

        // ---- Trennlinie.
        if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $trim) === 1) {
            $absatzSchliessen();
            $listenSchliessen();
            $aus[] = '<hr>';
            continue;
        }

        // ---- Überschrift.
        if (preg_match('/^(#{1,6})\s+(.*)$/', $trim, $m) === 1) {
            $absatzSchliessen();
            $listenSchliessen();
            /*
             * Eine Stufe tiefer als im Dokument: Die <h1> der Seite ist der
             * Titel des Dokuments, und zwei erste Überschriften auf einer
             * Seite sind für ein Vorleseprogramm zwei Anfänge.
             */
            $stufe = min(6, strlen($m[1]) + 1);
            $aus[] = "<h{$stufe}>" . markdown_inline($m[2]) . "</h{$stufe}>";
            continue;
        }

        // ---- Listenpunkt, mit Einrückung für verschachtelte Listen.
        if (preg_match('/^(\s*)[-*+]\s+(.*)$/', $roh, $m) === 1) {
            $absatzSchliessen();
            $tiefe = strlen(str_replace("\t", '    ', $m[1]));

            // Tiefer: eine neue Liste im offenen Punkt.
            while ($listen !== [] && $tiefe < (int) end($listen)) {
                $listenSchliessen(count($listen) - 1);
            }
            if ($listen === [] || $tiefe > (int) end($listen)) {
                $aus[]    = $listen === [] ? '<ul><li>' : '<ul><li>';
                $listen[] = (string) $tiefe;
            } else {
                $aus[] = '</li><li>';
            }
            $aus[] = markdown_inline($m[2]);
            continue;
        }

        // ---- Alles andere ist Fliesstext.
        if ($listen !== []) {
            /*
             * Eine eingerückte Folgezeile gehört zum Listenpunkt darüber.
             * Steht sie am Rand, ist die Liste zu Ende.
             */
            if ($roh !== ltrim($roh)) {
                $aus[] = ' ' . markdown_inline($trim);
                continue;
            }
            $listenSchliessen();
        }
        $absatz[] = markdown_inline($trim);
    }

    $absatzSchliessen();
    $listenSchliessen();

    return implode("\n", $aus);
}

/**
 * Auszeichnung innerhalb einer Zeile.
 *
 * Maskiert zuerst, dann werden Platzhalter zu Markup - anders herum liesse
 * sich Markup einschleusen.
 */
function markdown_inline(string $text): string
{
    $s = h($text);

    // Fett und kursiv. Doppelte Sterne zuerst, sonst frisst die einfache
    // Regel die Hälfte davon.
    $s = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s) ?? $s;
    $s = preg_replace('/(?<![\*\w])\*([^\*\n]+?)\*(?!\*)/u', '<em>$1</em>', $s) ?? $s;
    $s = preg_replace('/`([^`\n]+?)`/u', '<code>$1</code>', $s) ?? $s;

    /*
     * Links: [Text](Ziel) und <Ziel>.
     *
     * Erlaubt sind nur http, https und mailto. Ein Ziel wie javascript:
     * würde sonst zu einem Knopf, der Code ausführt - in einem Dokument,
     * das jemand später einmal aus dem Netz zusammenkopiert, ist das keine
     * ferne Möglichkeit.
     */
    $s = preg_replace_callback(
        '/\[([^\]]+)\]\(([^)\s]+)\)/u',
        static function (array $m): string {
            $ziel = markdown_safe_url($m[2]);
            return $ziel === null ? $m[1] : markdown_link($ziel, $m[1]);
        },
        $s,
    ) ?? $s;

    $s = preg_replace_callback(
        '/&lt;((?:https?|mailto):[^\s&]+)&gt;/u',
        static function (array $m): string {
            $ziel = markdown_safe_url(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
            return $ziel === null ? $m[1] : markdown_link($ziel, $ziel);
        },
        $s,
    ) ?? $s;

    return $s;
}

/** Ein Ziel, das aufgerufen werden darf - oder null. */
function markdown_safe_url(string $ziel): ?string
{
    $ziel = html_entity_decode($ziel, ENT_QUOTES, 'UTF-8');

    if (preg_match('#^(https?://|mailto:)#i', $ziel) === 1) {
        return $ziel;
    }
    // Ein Weg innerhalb dieser Anwendung ist ebenfalls in Ordnung.
    if (str_starts_with($ziel, '/') && !str_starts_with($ziel, '//')) {
        return $ziel;
    }
    return null;
}

function markdown_link(string $ziel, string $text): string
{
    // Fremde Ziele in einem neuen Fenster, und ohne Rückweg auf dieses hier.
    $fremd = !str_starts_with($ziel, '/') && !str_starts_with($ziel, 'mailto:');

    return sprintf(
        '<a href="%s"%s>%s</a>',
        h($ziel),
        $fremd ? ' target="_blank" rel="noopener noreferrer"' : '',
        $text,
    );
}

/**
 * Die Rechtstexte, die es gibt.
 *
 * Der Schlüssel steht in der Adresse, die Datei daneben. Ein Dokument, das
 * hier nicht steht, lässt sich auch nicht aufrufen - die Adresse bestimmt
 * damit nie, welche Datei gelesen wird.
 */
function legal_documents(): array
{
    return [
        'impressum' => [
            'datei'  => 'impressum.md',
            'titel'  => 'Impressum',
            'kurz'   => 'Impressum',
        ],
        'datenschutz' => [
            'datei'  => 'datenschutzerklaerung.md',
            'titel'  => 'Datenschutzerklärung',
            'kurz'   => 'Datenschutz',
        ],
        'lizenzen' => [
            'datei'  => 'LIZENZEN.md',
            'titel'  => 'Verwendete Software',
            'kurz'   => 'Lizenzen',
        ],
    ];
}

/**
 * Die Zeile mit den Rechtstexten, wie sie unten auf einer Seite steht.
 *
 * Sie muss von überall erreichbar sein - auch von der Anmeldung, und
 * gerade dort: Wer noch kein Konto hat, kann kein Menü öffnen.
 *
 * @param string $zurueck 'teacher', wenn der Weg zurück in die Verwaltung
 *                        führen soll. Sonst in die App.
 */
function legal_links_html(string $zurueck = ''): string
{
    $anhang = $zurueck === 'teacher' ? '&amp;z=teacher' : '';

    $teile = [];
    foreach (legal_documents() as $k => $d) {
        $teile[] = sprintf('<a href="%s">%s</a>',
            h(url('/rechtliches.php')) . '?d=' . h($k) . $anhang,
            h($d['kurz']));
    }

    return '<nav class="rechtszeile" aria-label="Rechtliches">'
         . implode('', $teile) . '</nav>';
}
