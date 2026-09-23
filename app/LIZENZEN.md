# Verwendete Software

Diese Anwendung benutzt fremde Bestandteile. Die meisten davon verlangen,
dass ihr Urheberrechtsvermerk und ihr Lizenztext bei jeder Weitergabe
mitgeliefert werden — auch bei einer Weitergabe über das Netz. Diese Seite
erfüllt das.

Die vollständigen Lizenztexte liegen dem Programm bei; wo sie zu finden
sind, steht jeweils dabei.

---

## Das Programm selbst

Vokidoki ist kein fremder Bestandteil, sondern die Anwendung, die
Sie gerade benutzen. Er ist in PHP und JavaScript geschrieben und kommt ohne
Rahmenwerk aus.

---

## Bibliotheken (PHP)

Alle folgenden Pakete stehen unter der **MIT-Lizenz**. Sie erlaubt
Verwendung, Veränderung und Weitergabe unter der Bedingung, dass der
Urheberrechtsvermerk und der Lizenztext erhalten bleiben. Die Lizenztexte
liegen im jeweiligen Paketverzeichnis unter `vendor/` als Datei `LICENSE`.

* **anthropic-ai/sdk** — Anthropic, PBC.
  Zugriff auf die Programmierschnittstelle, mit der Buchseiten gelesen und
  Lückensätze erzeugt werden.
  <https://github.com/anthropics/anthropic-sdk-php>
* **guzzlehttp/guzzle** — Michael Dowling und Mitwirkende.
  HTTP-Client, über den das SDK spricht.
  <https://github.com/guzzle/guzzle>
* **guzzlehttp/promises** — Michael Dowling und Mitwirkende.
  <https://github.com/guzzle/promises>
* **guzzlehttp/psr7** — Michael Dowling und Mitwirkende.
  <https://github.com/guzzle/psr7>
* **php-http/discovery** — PHP HTTP Team.
  <https://github.com/php-http/discovery>
* **psr/http-client**, **psr/http-factory**, **psr/http-message** —
  PHP Framework Interoperability Group.
  Die Schnittstellenbeschreibungen, auf die sich die obigen Pakete stützen.
  <https://www.php-fig.org/>
* **ralouphie/getallheaders** — Ralph Khattar.
  <https://github.com/ralouphie/getallheaders>
* **standard-webhooks/standard-webhooks** — Standard Webhooks.
  Steht unter der **Apache-Lizenz 2.0**; der Lizenztext liegt im
  Paketverzeichnis.
  <https://github.com/standard-webhooks/standard-webhooks>
* **symfony/deprecation-contracts**, **symfony/polyfill-php80** —
  Fabien Potencier und Mitwirkende.
  <https://symfony.com/>

---

## Schriftarten

* **Fredoka** — The Fredoka Project Authors, **SIL Open Font License 1.1**.
  Die Schrift der Überschriften und Knöpfe. Der Lizenztext liegt unter
  `assets/fonts/OFL-Fredoka.txt`.
  <https://github.com/hafontia/Fredoka-One>
* **Nunito** — The Nunito Project Authors, **SIL Open Font License 1.1**.
  Die Schrift für alles zum Lesen: Fließtext und Vokabeln. Der Lizenztext
  liegt unter `assets/fonts/OFL-Nunito.txt`.
  <https://github.com/googlefonts/nunito>

Die beiden Schriften der Oberfläche liegen **auf diesem Server** und werden
von dort geladen — nicht von Google. Das ist der Grund, aus dem hier früher
gar keine Schrift mitgeliefert wurde, und er gilt unverändert: Ein
eingebundenes Stilblatt von `fonts.googleapis.com` schickte die Adresse jedes
Kindes bei jedem Start dorthin. So verlässt weiterhin kein Zeichen von Ihnen
den Rechner, nur weil eine Schrift gebraucht wird — und die App bleibt auch
ohne Netz lesbar, was für sie wesentlich ist.

Mitgeliefert wird je Familie eine einzige Datei mit allen Strichstärken
(*variable font*), beschnitten auf die lateinischen Zeichen. Zusammen rund
90 KB.

---

## Sinnbilder und Fahnen

* **Twemoji** — ursprünglich von Twitter, heute weitergepflegt von
  Jon Decked und Mitwirkenden.
  Die Fahnen der Sprachen liegen als SVG-Dateien unter `assets/flags/`.
  Die Grafiken stehen unter **CC-BY 4.0**: Verwendung ist frei, solange die
  Herkunft genannt wird — das tut diese Seite.
  <https://github.com/jdecked/twemoji>
  <https://creativecommons.org/licenses/by/4.0/>

Warum überhaupt Dateien: Windows stellt die Regionalzeichen nicht als Fahnen
dar, sondern als die zwei Buchstaben des Länderkürzels — aus der britischen
Fahne wird „GB". Das lässt sich mit keiner Schriftart ändern; das Bild muss
mitgebracht werden.

Alle übrigen Sinnbilder der Oberfläche sind gewöhnliche Emoji aus dem
Unicode-Zeichensatz. Sie werden vom Gerät dargestellt und nicht
mitgeliefert.

---

## Dienste

* **Anthropic PBC** — die Programmierschnittstelle, die Buchseiten liest und
  Lückensätze erzeugt. Was dabei übermittelt wird und was nicht, steht in
  der Datenschutzerklärung.
* **ALL-INKL.COM** — der Betrieb des Servers. Serverstandort Deutschland,
  mit Vertrag zur Auftragsverarbeitung.

---

## Wie Sie die Lizenztexte einsehen

Die vollständigen Texte liegen dem Programm bei:

* PHP-Bibliotheken: `vendor/<Anbieter>/<Paket>/LICENSE`
* Fredoka: `assets/fonts/OFL-Fredoka.txt`
* Nunito: `assets/fonts/OFL-Nunito.txt`
* Twemoji: `assets/flags/HERKUNFT.md` sowie der verlinkte Lizenztext

Wer sie nicht selbst einsehen kann, bekommt sie auf Anfrage — die
Anschrift steht im Impressum.
