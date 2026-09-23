<?php
declare(strict_types=1);

/**
 * Die Sprachen zur Auswahl, mit Kürzel und Sinnbild.
 *
 * Gedacht für das Auswahlfeld beim Anlegen eines Kurses: oben die fünf, die
 * an einer deutschen Schule tatsächlich unterrichtet werden, darunter der
 * Rest zum Durchsuchen. Wer Suaheli anbietet, findet es; wer Englisch
 * anbietet, tippt nichts.
 *
 * Über "alle Sprachen der Welt" liesse sich streiten - es sind je nach
 * Zählung siebentausend. Hier stehen die, die jemand lernen oder
 * unterrichten würde: die Amtssprachen, die grossen Verkehrssprachen und
 * was an Schulen und Volkshochschulen vorkommt. Fehlt eine, ist das kein
 * Beinbruch: Der Name lässt sich im Feld auch frei eintippen.
 *
 * Zur Flagge: Sprachen haben keine. Ein Sinnbild hilft aber beim schnellen
 * Wiedererkennen in einer Kachelliste, deshalb steht bei jeder eine Fahne,
 * die ohne langes Nachdenken zugeordnet wird - Spanisch bekommt Spanien,
 * obwohl die meisten Sprecher anderswo leben. Wo keine passt, steht eine
 * Weltkugel.
 *
 * Seiteneffektfrei: reine Daten, keine Datenbank.
 */

/** Die fünf, die oben stehen - in dieser Reihenfolge. */
const LANGUAGE_TOP = ['Englisch', 'Französisch', 'Latein', 'Spanisch', 'Dänisch'];

/**
 * Alle Sprachen: Name (deutsch) => [Kürzel, Sinnbild].
 *
 * Das Kürzel ist ISO 639-1, wo es eines gibt. Es steuert im Lückentext den
 * lang-Hinweis am Eingabefeld und die Reihe der Sonderzeichen; fehlt es,
 * bleibt beides weg und die Übung funktioniert trotzdem.
 */
function world_languages(): array
{
    return [
        'Abchasisch'      => ['ab', "\u{1F310}"],
        'Afrikaans'       => ['af', "\u{1F1FF}\u{1F1E6}"],
        'Akan'            => ['ak', "\u{1F1EC}\u{1F1ED}"],
        'Albanisch'       => ['sq', "\u{1F1E6}\u{1F1F1}"],
        'Altgriechisch'   => ['grc', "\u{1F3DB}\u{FE0F}"],
        'Amharisch'       => ['am', "\u{1F1EA}\u{1F1F9}"],
        'Arabisch'        => ['ar', "\u{1F1F8}\u{1F1E6}"],
        'Aramäisch'       => ['arc', "\u{1F310}"],
        'Armenisch'       => ['hy', "\u{1F1E6}\u{1F1F2}"],
        'Aserbaidschanisch' => ['az', "\u{1F1E6}\u{1F1FF}"],
        'Assamesisch'     => ['as', "\u{1F1EE}\u{1F1F3}"],
        'Baskisch'        => ['eu', "\u{1F310}"],
        'Belarussisch'    => ['be', "\u{1F1E7}\u{1F1FE}"],
        'Bengalisch'      => ['bn', "\u{1F1E7}\u{1F1E9}"],
        'Bosnisch'        => ['bs', "\u{1F1E7}\u{1F1E6}"],
        'Bretonisch'      => ['br', "\u{1F310}"],
        'Bulgarisch'      => ['bg', "\u{1F1E7}\u{1F1EC}"],
        'Chinesisch'      => ['zh', "\u{1F1E8}\u{1F1F3}"],
        'Dänisch'         => ['da', "\u{1F1E9}\u{1F1F0}"],
        'Deutsch'         => ['de', "\u{1F1E9}\u{1F1EA}"],
        'Englisch'        => ['en', "\u{1F1EC}\u{1F1E7}"],
        'Esperanto'       => ['eo', "\u{1F310}"],
        'Estnisch'        => ['et', "\u{1F1EA}\u{1F1EA}"],
        'Färöisch'        => ['fo', "\u{1F1EB}\u{1F1F4}"],
        'Filipino'        => ['fil', "\u{1F1F5}\u{1F1ED}"],
        'Finnisch'        => ['fi', "\u{1F1EB}\u{1F1EE}"],
        'Französisch'     => ['fr', "\u{1F1EB}\u{1F1F7}"],
        'Friesisch'       => ['fy', "\u{1F310}"],
        'Galicisch'       => ['gl', "\u{1F310}"],
        'Georgisch'       => ['ka', "\u{1F1EC}\u{1F1EA}"],
        'Griechisch'      => ['el', "\u{1F1EC}\u{1F1F7}"],
        'Grönländisch'    => ['kl', "\u{1F1EC}\u{1F1F1}"],
        'Gujarati'        => ['gu', "\u{1F1EE}\u{1F1F3}"],
        'Haitianisch'     => ['ht', "\u{1F1ED}\u{1F1F9}"],
        'Hausa'           => ['ha', "\u{1F1F3}\u{1F1EC}"],
        'Hawaiianisch'    => ['haw', "\u{1F310}"],
        'Hebräisch'       => ['he', "\u{1F1EE}\u{1F1F1}"],
        'Hindi'           => ['hi', "\u{1F1EE}\u{1F1F3}"],
        'Indonesisch'     => ['id', "\u{1F1EE}\u{1F1E9}"],
        'Irisch'          => ['ga', "\u{1F1EE}\u{1F1EA}"],
        'Isländisch'      => ['is', "\u{1F1EE}\u{1F1F8}"],
        'Italienisch'     => ['it', "\u{1F1EE}\u{1F1F9}"],
        'Japanisch'       => ['ja', "\u{1F1EF}\u{1F1F5}"],
        'Javanisch'       => ['jv', "\u{1F1EE}\u{1F1E9}"],
        'Jiddisch'        => ['yi', "\u{1F310}"],
        'Kannada'         => ['kn', "\u{1F1EE}\u{1F1F3}"],
        'Kasachisch'      => ['kk', "\u{1F1F0}\u{1F1FF}"],
        'Katalanisch'     => ['ca', "\u{1F310}"],
        'Khmer'           => ['km', "\u{1F1F0}\u{1F1ED}"],
        'Kirgisisch'      => ['ky', "\u{1F1F0}\u{1F1EC}"],
        'Kisuaheli'       => ['sw', "\u{1F1F0}\u{1F1EA}"],
        'Koreanisch'      => ['ko', "\u{1F1F0}\u{1F1F7}"],
        'Korsisch'        => ['co', "\u{1F310}"],
        'Kroatisch'       => ['hr', "\u{1F1ED}\u{1F1F7}"],
        'Kurdisch'        => ['ku', "\u{1F310}"],
        'Laotisch'        => ['lo', "\u{1F1F1}\u{1F1E6}"],
        'Latein'          => ['la', "\u{1F3DB}\u{FE0F}"],
        'Lettisch'        => ['lv', "\u{1F1F1}\u{1F1FB}"],
        'Litauisch'       => ['lt', "\u{1F1F1}\u{1F1F9}"],
        'Luxemburgisch'   => ['lb', "\u{1F1F1}\u{1F1FA}"],
        'Madagassisch'    => ['mg', "\u{1F1F2}\u{1F1EC}"],
        'Malaiisch'       => ['ms', "\u{1F1F2}\u{1F1FE}"],
        'Malayalam'       => ['ml', "\u{1F1EE}\u{1F1F3}"],
        'Maltesisch'      => ['mt', "\u{1F1F2}\u{1F1F9}"],
        'Maori'           => ['mi', "\u{1F1F3}\u{1F1FF}"],
        'Marathi'         => ['mr', "\u{1F1EE}\u{1F1F3}"],
        'Mazedonisch'     => ['mk', "\u{1F1F2}\u{1F1F0}"],
        'Mongolisch'      => ['mn', "\u{1F1F2}\u{1F1F3}"],
        'Nepalesisch'     => ['ne', "\u{1F1F3}\u{1F1F5}"],
        'Niederländisch'  => ['nl', "\u{1F1F3}\u{1F1F1}"],
        'Norwegisch'      => ['no', "\u{1F1F3}\u{1F1F4}"],
        'Okzitanisch'     => ['oc', "\u{1F310}"],
        'Paschtu'         => ['ps', "\u{1F1E6}\u{1F1EB}"],
        'Persisch'        => ['fa', "\u{1F1EE}\u{1F1F7}"],
        'Polnisch'        => ['pl', "\u{1F1F5}\u{1F1F1}"],
        'Portugiesisch'   => ['pt', "\u{1F1F5}\u{1F1F9}"],
        'Punjabi'         => ['pa', "\u{1F1EE}\u{1F1F3}"],
        'Rätoromanisch'   => ['rm', "\u{1F1E8}\u{1F1ED}"],
        'Rumänisch'       => ['ro', "\u{1F1F7}\u{1F1F4}"],
        'Russisch'        => ['ru', "\u{1F1F7}\u{1F1FA}"],
        'Samoanisch'      => ['sm', "\u{1F1FC}\u{1F1F8}"],
        'Sanskrit'        => ['sa', "\u{1F310}"],
        'Schottisch-Gälisch' => ['gd', "\u{1F310}"],
        'Schwedisch'      => ['sv', "\u{1F1F8}\u{1F1EA}"],
        'Serbisch'        => ['sr', "\u{1F1F7}\u{1F1F8}"],
        'Sindhi'          => ['sd', "\u{1F1F5}\u{1F1F0}"],
        'Singhalesisch'   => ['si', "\u{1F1F1}\u{1F1F0}"],
        'Slowakisch'      => ['sk', "\u{1F1F8}\u{1F1F0}"],
        'Slowenisch'      => ['sl', "\u{1F1F8}\u{1F1EE}"],
        'Somali'          => ['so', "\u{1F1F8}\u{1F1F4}"],
        'Spanisch'        => ['es', "\u{1F1EA}\u{1F1F8}"],
        'Tamil'           => ['ta', "\u{1F1EE}\u{1F1F3}"],
        'Telugu'          => ['te', "\u{1F1EE}\u{1F1F3}"],
        'Thai'            => ['th', "\u{1F1F9}\u{1F1ED}"],
        'Tschechisch'     => ['cs', "\u{1F1E8}\u{1F1FF}"],
        'Türkisch'        => ['tr', "\u{1F1F9}\u{1F1F7}"],
        'Ukrainisch'      => ['uk', "\u{1F1FA}\u{1F1E6}"],
        'Ungarisch'       => ['hu', "\u{1F1ED}\u{1F1FA}"],
        'Urdu'            => ['ur', "\u{1F1F5}\u{1F1F0}"],
        'Usbekisch'       => ['uz', "\u{1F1FA}\u{1F1FF}"],
        'Vietnamesisch'   => ['vi', "\u{1F1FB}\u{1F1F3}"],
        'Walisisch'       => ['cy', "\u{1F310}"],
        'Weissrussisch'   => ['be', "\u{1F1E7}\u{1F1FE}"],
        'Wolof'           => ['wo', "\u{1F1F8}\u{1F1F3}"],
        'Xhosa'           => ['xh', "\u{1F1FF}\u{1F1E6}"],
        'Yoruba'          => ['yo', "\u{1F1F3}\u{1F1EC}"],
        'Zulu'            => ['zu', "\u{1F1FF}\u{1F1E6}"],
    ];
}

/**
 * Die Liste, wie das Auswahlfeld sie braucht: erst die fünf, dann der Rest.
 *
 * @return list<array{name: string, code: string, flag: string, top: bool}>
 */
function language_choices(): array
{
    $alle  = world_languages();
    $liste = [];

    foreach (LANGUAGE_TOP as $name) {
        if (isset($alle[$name])) {
            $liste[] = ['name' => $name, 'code' => $alle[$name][0],
                        'flag' => $alle[$name][1], 'top' => true];
        }
    }

    foreach ($alle as $name => [$code, $flag]) {
        if (in_array($name, LANGUAGE_TOP, true)) {
            continue;
        }
        $liste[] = ['name' => $name, 'code' => $code, 'flag' => $flag, 'top' => false];
    }

    return $liste;
}

/** Das Sinnbild zu einem Sprachnamen, oder eine Weltkugel. */
function language_flag(string $name): string
{
    return world_languages()[trim($name)][1] ?? "\u{1F310}";
}
