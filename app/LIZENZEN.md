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
Sie gerade benutzen. Es ist in PHP und JavaScript geschrieben und kommt ohne
Rahmenwerk aus.

Copyright © 2026 Hannes Molsen. Der Quelltext steht unter der
**GNU Affero General Public License v3.0** (AGPL-3.0) und ist öffentlich:
<https://github.com/hmolsen/vokidoki>. Wer eine veränderte Fassung über das
Netz anbietet, muss deren Quelltext ebenso zugänglich machen. Der vollständige
Lizenztext liegt als `LICENSE` im Quelltext bei und steht unter
<https://www.gnu.org/licenses/agpl-3.0.html>.

Nicht unter dieser Lizenz stehen die Figur **Voki** in allen Darstellungen,
das Vokidoki-Logo und der Name „Vokidoki“ – alle Rechte vorbehalten (siehe
`assets/VOKI.md`).

---

## Bibliotheken (PHP)

Alle folgenden Pakete stehen unter der **MIT-Lizenz**. Sie erlaubt
Verwendung, Veränderung und Weitergabe unter der Bedingung, dass der
Urheberrechtsvermerk und der Lizenztext erhalten bleiben. Die Lizenztexte
liegen im jeweiligen Paketverzeichnis unter `vendor/` als Datei `LICENSE`.

* **anthropic-ai/sdk** — Anthropic, PBC.
  Zugriff auf die Programmierschnittstelle, mit der erkannter Text zu
  Vokabeln geordnet und Lückensätze erzeugt werden.
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

## Texterkennung (JavaScript, im Browser)

Die Fotos beim Einlesen werden auf dem Gerät gelesen, nicht auf dem Server.
Dafür liegen unter `ocr/` diese Bestandteile bei:

* **Tesseract.js** — Naptha und Mitwirkende, **Apache-Lizenz 2.0**.
  Steuert die Texterkennung im Browser (`ocr/tesseract.min.js`,
  `ocr/worker.min.js`). Lizenztext: `ocr/LICENSE-tesseract.js.txt`; die
  darin gebündelten kleinen Hilfsbibliotheken (u. a. zlib.js,
  regenerator-runtime, buffer) nennen ihre Lizenzen (MIT, BSD) in
  `ocr/worker.min.js.LICENSE.txt` und `ocr/tesseract.min.js.LICENSE.txt`.
  <https://github.com/naptha/tesseract.js>
* **tesseract.js-core** — die nach WebAssembly übersetzte Tesseract-Engine,
  **Apache-Lizenz 2.0** (`ocr/tesseract-core-*.wasm.js`). Sie enthält
  **Tesseract OCR** (Google und Mitwirkende, Apache-Lizenz 2.0) und dessen
  Hilfsbibliotheken, darunter **Leptonica** (BSD-artige Lizenz); die
  vollständige Liste steht im Ordner `third_party` des Projekts.
  Lizenztext: `ocr/LICENSE-tesseract.js-core.txt`.
  <https://github.com/naptha/tesseract.js-core>
* **Sprachdaten für Tesseract** (`ocr/sprachen/*.traineddata.gz`) — aus
  `tessdata` des Tesseract-Projekts, **Apache-Lizenz 2.0**, bereitgestellt
  über die Pakete `@tesseract.js-data` (MIT).
  <https://github.com/tesseract-ocr/tessdata_best>

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

Für die Zettel als PDF liegen dieselben Schriften ein zweites Mal bei, in
festen Strichstärken (Nunito Regular und Bold, Fredoka SemiBold) und
zugeschnitten auf die Zeichen von Windows-1252, unter `lib/fpdf/font/`. Sie
werden in jedes PDF eingebettet; es gilt dieselbe Lizenz.

---

## PDF-Erzeugung

* **FPDF** 1.9 — Olivier Plathey. Erzeugt die Zettel mit den Zugangsdaten als
  PDF (`lib/fpdf/fpdf.php`). Die Lizenz erlaubt Verwendung, Veränderung und
  Weitergabe ohne Bedingungen; ihr Text liegt unter `lib/fpdf/license.txt`.
  <http://www.fpdf.org>

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

* **Simple Icons** — Simple-Icons-Mitwirkende.
  Die Zeichen der Betriebssysteme unter „Deine Geräte“ (Apple, Android,
  Linux, Windows, Chrome) liegen unter `assets/geraete/`. Sie stehen unter
  **CC0 1.0** und sind damit gemeinfrei; die Marken selbst gehören ihren
  Inhabern.
  <https://simpleicons.org>
  <https://creativecommons.org/publicdomain/zero/1.0/>

Alle übrigen Sinnbilder der Oberfläche sind gewöhnliche Emoji aus dem
Unicode-Zeichensatz. Sie werden vom Gerät dargestellt und nicht
mitgeliefert.

---

## Dienste

* **Anthropic PBC** — die Programmierschnittstelle, die den auf dem Gerät
  erkannten Text zu Vokabeln ordnet, Lückensätze erzeugt und Wortarten
  zuordnet. Fotos erhält sie nicht. Was dabei übermittelt wird und was
  nicht, steht in der Datenschutzerklärung.
* **Microsoft Azure AI Speech** — die Stimmen, die die Sätze fürs „Hören“
  und die Vokabeln beim „Auswählen“ vorlesen. Gesprochen wird einmal, die
  Tondateien liegen danach auf diesem Server. Übermittelt wird nur der
  Text; Einzelheiten in der Datenschutzerklärung.
* **ALL-INKL.COM** — der Betrieb des Servers. Serverstandort Deutschland,
  mit Vertrag zur Auftragsverarbeitung.

---

## Wie Sie die Lizenztexte einsehen

Die vollständigen Texte liegen dem Programm bei:

* PHP-Bibliotheken: `vendor/<Anbieter>/<Paket>/LICENSE`
* Texterkennung: `ocr/LICENSE-tesseract.js.txt`,
  `ocr/LICENSE-tesseract.js-core.txt` und die `*.LICENSE.txt` daneben
* Fredoka: `assets/fonts/OFL-Fredoka.txt`
* Nunito: `assets/fonts/OFL-Nunito.txt`
* Twemoji: `assets/flags/HERKUNFT.md` sowie der verlinkte Lizenztext

Wer sie nicht selbst einsehen kann, bekommt sie auf Anfrage — die
Anschrift steht im Impressum.
