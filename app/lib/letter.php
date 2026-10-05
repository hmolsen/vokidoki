<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';

/**
 * Das Anschreiben, das ein Kind mit nach Hause bekommt.
 *
 * Reiner Text mit Platzhaltern, keine Formatvorlage, kein HTML. Zwei Gründe:
 *
 * Der eine ist Sicherheit. Wer die Vorlage bearbeitet, schreibt für alle
 * Kinder einer Schule. Liesse sie HTML zu, wäre sie eine offene Tür - und die
 * einzige Person, die davon etwas hätte, wäre jemand, der sich Zugang zum
 * Backend verschafft hat. Text ist an dieser Stelle nichts weniger wert.
 *
 * Der andere ist Nutzbarkeit. Eine Lehrkraft soll den Brief umformulieren
 * können, ohne dabei etwas kaputtmachen zu können. Bei reinem Text ist das
 * schlimmste denkbare Ergebnis ein hässlicher Absatz.
 *
 * Welche Vorlage gilt, entscheidet sich in drei Stufen - die erste, die es
 * gibt, zählt:
 *
 *   1. die eigene der Lehrkraft, die den Zettel druckt (users.letter_template)
 *   2. die des Betreibers, im Admin unter Einstellungen (settings.letter_template)
 *   3. letter_default() hier unten
 *
 * Zwei Lehrkräfte derselben Schule können also verschiedene Zettel drucken.
 * Das ist gewollt: Die eine schreibt ihre Klasse mit "du" an, die andere
 * ergänzt einen Hinweis auf ihre Sprechstunde.
 */

/** Die Platzhalter mit dem, was sie bedeuten - für die Hilfe im Backend. */
function letter_placeholders(): array
{
    return [
        'name'         => 'Der Name des Kindes, etwa "Lilli M."',
        'kuerzel'      => 'Das Kürzel der Schule zum Anmelden, etwa "opsk"',
        'benutzername' => 'Sein Benutzername, etwa "lilli.m"',
        'passwort'     => 'Das Anfangspasswort, etwa "müder-Gepard"',
        'klasse'       => 'Die Klasse, etwa "5B"',
        'schule'       => 'Der Name der Schule',
        'url'          => 'Die Adresse der App',
        'datenschutz'  => 'Die Adresse der Datenschutzerklärung',
        'impressum'    => 'Die Adresse des Impressums',
    ];
}

/**
 * Der Text, mit dem eine frische Installation anfängt.
 *
 * Ein Absatz je Zeile, ohne Umbrüche mitten im Satz. Der Zettel übernimmt
 * jeden Zeilenumbruch der Vorlage - bei einer eigenen Vorlage ist das der
 * Wille der Lehrkraft. Stand der Text hier auf 80 Zeichen umbrochen, brach
 * er auf dem Zettel an denselben Stellen, mitten in der Zeile.
 */
function letter_default(): string
{
    return implode("\n", [
        'Hallo {name},',
        '',
        'ab jetzt kannst du deine Vokabeln am Handy oder am Tablet üben.',
        '',
        'So kommst du hinein:',
        '',
        '1. Den Code oben abfotografieren - dann stehen Schulkürzel und Benutzername schon da. '
            . 'Oder {url} eintippen.',
        '2. Als Schulkürzel {kuerzel} eingeben.',
        '3. Als Benutzername {benutzername} eingeben.',
        '4. Als Passwort {passwort} eingeben, mit dem Bindestrich in der Mitte.',
        '',
        'Danach kannst du dir ein eigenes Passwort ausdenken. Merk es dir gut - '
            . 'wenn du es vergisst, kann dir deine Lehrkraft ein neues geben.',
        '',
        'Tipp: Leg dir die Seite auf den Startbildschirm, dann hast du sie wie eine App.',
        '',
        'Viel Erfolg!',
        '',
        '',
        'Information für die Erziehungsberechtigten',
        '',
        'Die Nutzung dieser Vokabeltrainer-App ist ein freiwilliges Zusatzangebot zum '
            . 'Vokabellernen. Die Nutzung ist kostenlos. Für das Konto wird lediglich der '
            . 'Vorname und der erste Buchstabe des Nachnamens gespeichert. Es werden keine '
            . 'weiteren Daten (wie E-Mail-Adresse oder Standort) erfasst. Die Lehrkraft sieht '
            . 'nicht, ob und wie Ihr Kind übt.',
        '',
        'Datenschutzerklärung: {datenschutz}',
        'Impressum: {impressum}',
        '',
        'Sollten Sie nicht wünschen, dass Ihr Kind die App nutzt, muss das Konto nicht '
            . 'verwendet werden. Es entstehen dadurch keinerlei schulische Nachteile. Ist '
            . 'Ihr Kind jünger als 16 Jahre, bestätigt es bei der ersten Anmeldung, dass Sie '
            . 'mit der Nutzung einverstanden sind.',
    ]);
}

/**
 * Der Zettel für eine neue Lehrkraft - in der Fassung, mit der eine frische
 * Installation anfängt.
 *
 * Anders als der für die Kinder pflegt ihn nur der Betreiber
 * (Einstellungen), nicht die Lehrkräfte selbst: Er erklärt, dass Kolleginnen
 * einander das Passwort neu setzen können - der Grund, warum Vokidoki ohne
 * E-Mail-Adressen auskommt -, und das soll an jeder Schule gleich lauten.
 */
function letter_lehrkraft_default(): string
{
    return implode("\n", [
        'Liebe Kollegin, lieber Kollege,',
        '',
        'für Sie ist ein Konto bei Vokidoki angelegt. Damit stellen Sie Vokabeln für Ihre Klassen zusammen, '
            . 'geben sie frei und legen Konten für die Kinder an.',
        '',
        'So kommen Sie hinein:',
        '',
        '1. Den Code oben mit dem Handy abfotografieren - dann stehen Schulkürzel und Benutzername schon da. '
            . 'Oder am Rechner {url} aufrufen.',
        '2. Als Schulkürzel {kuerzel} eingeben.',
        '3. Als Benutzername {benutzername} eingeben.',
        '4. Als Passwort {passwort} eingeben.',
        '',
        'Gleich danach: unter "Mein Konto" ein eigenes Passwort wählen. Bitte ein starkes - mindestens '
            . '10 Zeichen, am besten ein ganzer Satz.',
        '',
        'Als App auf dem Handy: Im Browser die Seite öffnen, angemeldet bleiben und "Zum Home-Bildschirm" '
            . 'wählen (iPhone: Teilen-Knopf, Android: Menü mit den drei Punkten). Dann öffnet sich Vokidoki '
            . 'wie eine App, und Sie fotografieren Vokabellisten direkt mit der Kamera.',
        '',
        'Wichtig: Vokidoki kennt keine E-Mail-Adressen. Vergessen Sie Ihr Passwort, gibt Ihnen eine Kollegin '
            . 'oder ein Kollege unter "Lehrkräfte" ein neues. Genau deshalb kann das jede Lehrkraft Ihrer '
            . 'Schule - und deshalb sollte Ihr Passwort niemand erraten können.',
        '',
        'Fragen beantwortet support@vokidoki.de.',
    ]);
}

/** Die Platzhalter des Zettels für Lehrkräfte - alle ausser der Klasse. */
function letter_lehrkraft_placeholders(): array
{
    $p = letter_placeholders();
    unset($p['klasse']);
    $p['name']         = 'Der Name der Lehrkraft, etwa "Frau Müller"';
    $p['benutzername'] = 'Ihr Benutzername, etwa "mue"';
    $p['url']          = 'Die Adresse des Lehrkraft-Bereichs';
    return $p;
}

/** Die Fassung, die gilt: die des Betreibers, sonst die Voreinstellung. */
function letter_lehrkraft(): string
{
    $eigene = setting('teacher_letter_template', '');
    return trim($eigene) === '' ? letter_lehrkraft_default() : $eigene;
}

/** Die Vorlage des Betreibers - die Voreinstellung für jede Lehrkraft. */
function letter_standard(): string
{
    $eigene = setting('letter_template', '');
    return trim($eigene) === '' ? letter_default() : $eigene;
}

/**
 * Die Vorlage, die gilt: die eigene der Lehrkraft, sonst die des Betreibers.
 *
 * @param array|null $lehrkraft die Zeile aus users; null = nur die des Betreibers
 */
function letter_template(?array $lehrkraft = null): string
{
    $eigene = (string) ($lehrkraft['letter_template'] ?? '');
    return trim($eigene) === '' ? letter_standard() : $eigene;
}

/** Hat diese Lehrkraft eine eigene Vorlage? */
function letter_is_own(array $lehrkraft): bool
{
    return trim((string) ($lehrkraft['letter_template'] ?? '')) !== '';
}

/**
 * Die eigene Vorlage einer Lehrkraft speichern - oder mit '' wieder auf die
 * des Betreibers zurückstellen.
 *
 * Eine Vorlage, die wortgleich der des Betreibers ist, wird nicht als eigene
 * gespeichert. Sonst hinge die Lehrkraft an einer Abschrift fest und bekäme
 * nie mit, wenn der Betreiber die Voreinstellung verbessert.
 *
 * @return list<string> die Pflicht-Platzhalter, die im Text fehlen - erlaubt,
 *                      aber einen Hinweis wert
 */
function letter_save_own(int $lehrkraftId, string $text): array
{
    $text = str_replace("\r\n", "\n", $text);
    $eigen = trim($text) === '' || trim($text) === trim(letter_standard()) ? null : $text;
    q('UPDATE users SET letter_template = ? WHERE id = ?', [$eigen, $lehrkraftId]);

    return $eigen === null ? [] : letter_missing_placeholders($text);
}

/** Welche der unverzichtbaren Platzhalter fehlen im Text? */
function letter_missing_placeholders(string $text): array
{
    $fehlend = [];
    // Ohne Schulkürzel kommt niemand hinein (lib/schulkuerzel.php).
    foreach (['name', 'kuerzel', 'benutzername', 'passwort'] as $noetig) {
        if (!str_contains($text, '{' . $noetig . '}')) {
            $fehlend[] = '{' . $noetig . '}';
        }
    }
    return $fehlend;
}

/**
 * Setzt die Werte in die Vorlage ein.
 *
 * Unbekannte Platzhalter bleiben stehen, statt zu verschwinden. Ein
 * sichtbares "{passwrot}" auf dem Ausdruck ist ein Tippfehler, den jemand
 * bemerkt und behebt; eine leere Stelle ist ein Kind ohne Passwort.
 */
function letter_render(string $template, array $werte): string
{
    $ersatz = [];
    foreach (letter_placeholders() as $schluessel => $_) {
        if (array_key_exists($schluessel, $werte)) {
            $ersatz['{' . $schluessel . '}'] = (string) $werte[$schluessel];
        }
    }

    return strtr($template, $ersatz);
}

/**
 * Der fertige Brief als Absätze - und welche davon Zwischenüberschriften sind.
 *
 * Die Vorlage ist reiner Text und soll es bleiben. Damit der Zettel trotzdem
 * gegliedert aussieht, wird aus einem Absatz, der nur aus einer kurzen Zeile
 * ohne Satzzeichen am Ende besteht, eine Überschrift - so wie "Information
 * für die Erziehungsberechtigten". Wer die Vorlage umschreibt, bekommt das
 * von selbst, ohne Markup zu lernen. Der HTML-Zettel (teacher/print.php) und
 * das PDF (lib/zettel_pdf.php) gliedern damit gleich.
 *
 * @return list<array{0: 'h'|'p', 1: string}>
 */
function letter_absaetze(string $text): array
{
    $teile = [];
    foreach (preg_split('/\n\s*\n/', trim(str_replace("\r\n", "\n", $text))) ?: [] as $absatz) {
        $absatz = trim($absatz);
        if ($absatz === '') {
            continue;
        }
        $ueberschrift = !str_contains($absatz, "\n") && mb_strlen($absatz) <= 70
            && preg_match('/[.,:;!?)]$/u', $absatz) !== 1;
        $teile[] = [$ueberschrift ? 'h' : 'p', $absatz];
    }
    return $teile;
}
