# Woher die Fahnen kommen

Die SVG-Dateien in diesem Verzeichnis stammen aus **Twemoji**
(<https://github.com/jdecked/twemoji>), dem weitergepflegten Zweig des
ursprünglich von Twitter veröffentlichten Emoji-Satzes.

Die Grafiken stehen unter **CC-BY 4.0**
(<https://creativecommons.org/licenses/by/4.0/>). Verwendung ist frei,
solange die Herkunft genannt wird — das tut diese Datei, und die
Programmbeschreibung nennt sie ebenfalls.

Benannt sind die Dateien nach den Unicode-Stellen des Sinnbildes, in
Kleinbuchstaben und mit Bindestrich verbunden: `1f1ec-1f1e7.svg` ist
`U+1F1EC U+1F1E7`, also die britische Fahne. Die Variantenwahl `U+FE0F`
bleibt weg; sie sagt nur „bitte farbig" und gehört nicht zum Zeichen.

Warum überhaupt Dateien und nicht einfach das Emoji: Windows stellt die
Regionalzeichen nicht als Fahnen dar, sondern als die zwei Buchstaben des
Länderkürzels — aus der britischen Fahne wird „GB". Das lässt sich mit
keiner Schriftart der Seite ändern; das Bild muss mitgebracht werden.

Kommt eine Sprache dazu, genügt es, die passende Datei hier abzulegen.
`lib/flags.php` sieht auf der Platte nach und nimmt das Emoji, wenn nichts
da ist.
