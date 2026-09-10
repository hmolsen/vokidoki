<?php
declare(strict_types=1);

/**
 * Kategorien einer Vokabel.
 *
 * Zehn Wortarten, dazu zwei Kategorien für ganze Äußerungen: Vokabellisten
 * führen Grußformeln und Fragen oft als einen Eintrag ("Bonne nuit!",
 * "Comment tu t'appelles ?"). Als Wortart liesse sich das nicht einordnen.
 *
 * "sonstiges" bleibt der Auffangwert für alles, was auch das nicht trifft -
 * ohne ihn müsste das Modell raten.
 *
 * Die Schlüssel sind bewusst ASCII: Sie stehen in der Datenbank, im
 * JSON-Schema für das Modell und in CSS-Klassennamen. Nur das Label wird
 * angezeigt und trägt die Umlaute. Nach aussen heisst das Ganze "Kategorie",
 * intern bleibt es word_type - ein Spaltenname weniger, der wandern muss.
 */
const WORD_TYPES = [
    'substantiv'   => ['label' => 'Substantiv',   'short' => 'Subst.'],
    'artikel'      => ['label' => 'Artikel',      'short' => 'Art.'],
    'adjektiv'     => ['label' => 'Adjektiv',     'short' => 'Adj.'],
    'verb'         => ['label' => 'Verb',         'short' => 'Verb'],
    'pronomen'     => ['label' => 'Pronomen',     'short' => 'Pron.'],
    'numerale'     => ['label' => 'Numerale',     'short' => 'Num.'],
    'adverb'       => ['label' => 'Adverb',       'short' => 'Adv.'],
    'praeposition' => ['label' => 'Präposition',  'short' => 'Präp.'],
    'konjunktion'  => ['label' => 'Konjunktion',  'short' => 'Konj.'],
    'interjektion' => ['label' => 'Interjektion', 'short' => 'Interj.'],
    // Ganze Äußerungen, wie sie in Vokabellisten oft als ein Eintrag stehen.
    'aussage'      => ['label' => 'Aussage',      'short' => 'Aussage'],
    'frage'        => ['label' => 'Frage',        'short' => 'Frage'],
    'sonstiges'    => ['label' => 'Sonstiges',    'short' => 'Sonst.'],
];

/** Schlüssel als Liste - so gehen sie als enum ins JSON-Schema. */
function word_type_keys(): array
{
    return array_keys(WORD_TYPES);
}

function word_type_label(?string $key): string
{
    return WORD_TYPES[$key]['label'] ?? 'unbekannt';
}

/** Normalisiert, was vom Modell oder aus einem Formular kommt. */
function word_type_clean(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $key = strtolower(trim($value));
    return isset(WORD_TYPES[$key]) ? $key : null;
}

/** Farbig hinterlegtes Kürzel für die Vokabeltabellen im Admin. */
function word_type_badge(?string $key): string
{
    if ($key === null || !isset(WORD_TYPES[$key])) {
        return '<span class="wt wt-leer" title="noch nicht bestimmt">&ndash;</span>';
    }

    return sprintf(
        '<span class="wt wt-%s" title="%s">%s</span>',
        htmlspecialchars($key, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars(WORD_TYPES[$key]['label'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars(WORD_TYPES[$key]['short'], ENT_QUOTES, 'UTF-8'),
    );
}
