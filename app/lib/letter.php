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
        '1. Den Code oben abfotografieren - oder {url} eintippen.',
        '2. Als Benutzername {benutzername} eingeben.',
        '3. Als Passwort {passwort} eingeben, mit dem Bindestrich in der Mitte.',
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
    foreach (['name', 'benutzername', 'passwort'] as $noetig) {
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
