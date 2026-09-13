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
 */

/** Die Platzhalter mit dem, was sie bedeuten - für die Hilfe im Backend. */
function letter_placeholders(): array
{
    return [
        'name'         => 'Der Name des Kindes, etwa "Lilli M."',
        'benutzername' => 'Sein Benutzername, etwa "lilli.m"',
        'passwort'     => 'Das Anfangspasswort, etwa "müder Gepard"',
        'klasse'       => 'Die Klasse, etwa "5B"',
        'schule'       => 'Der Name der Schule',
        'url'          => 'Die Adresse der App',
    ];
}

/** Der Text, mit dem eine frische Installation anfängt. */
function letter_default(): string
{
    return <<<'TEXT'
        Hallo {name},

        ab jetzt kannst du deine Vokabeln am Handy oder am Tablet üben.

        So kommst du hinein:

        1. Den Code oben abfotografieren - oder {url} eintippen.
        2. Als Benutzername {benutzername} eingeben.
        3. Als Passwort {passwort} eingeben, mit dem Leerzeichen in der Mitte.

        Danach kannst du dir ein eigenes Passwort ausdenken. Merk es dir gut -
        wenn du es vergisst, kann dir deine Lehrkraft ein neues geben.

        Tipp: Leg dir die Seite auf den Startbildschirm, dann hast du sie wie
        eine App.

        Viel Erfolg!
        TEXT;
}

/** Die Vorlage, wie sie im Backend hinterlegt ist. */
function letter_template(): string
{
    $eigene = setting('letter_template', '');
    return trim($eigene) === '' ? letter_default() : $eigene;
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
