# Vokidoki

*(Das Verzeichnis heisst weiterhin `vokabeltrainer` — der Name der App hat
sich geändert, der Ablageort nicht.)*

Ein Vokabeltrainer für die Schule, als PWA. Lehrkräfte stellen die Vokabeln
zu Lerneinheiten zusammen – aus gedruckten oder handgeschriebenen Listen, die
die Texterkennung **auf dem Gerät** liest (das Foto verlässt es nie), oder von
Hand eingetippt. Eine KI berichtigt Lesefehler, markiert sie zum Prüfen und
erzeugt Lückensätze. Die Klasse übt, was freigegeben ist – in drei Übungen
(Auswählen, Einsetzen, Lückentext), auch ohne Netz. Jedes Kind hat ein eigenes
Symbol auf dem Home-Bildschirm („Lillis Vokidoki“), das dauerhaft angemeldet
bleibt.

Live: <https://vokidoki.de>

---

## Lizenz

Copyright © 2026 Hannes Molsen

Der **Quelltext** steht unter der
[GNU Affero General Public License v3.0](LICENSE) (AGPL-3.0). Sie dürfen ihn
nutzen, verändern und weitergeben – auch eine eigene Instanz für Ihre Schule
betreiben. Wer eine **veränderte** Fassung für andere über das Netz anbietet,
muss deren Nutzerinnen und Nutzern den geänderten Quelltext zugänglich machen
(AGPL, Abschnitt 13). So bleibt jede Weiterentwicklung offen, auch wenn sie
nur als Website läuft.

**Nicht** unter dieser Lizenz stehen:

- **Die Figur Voki** in allen Darstellungen – `app/assets/voki*.svg`,
  `app/assets/voki*.png`, `app/assets/voki-liest.svg`, das Logo
  `app/assets/vokidoki_logo.svg` sowie Voki in den Bildschirmfotos unter
  `website/bilder/`. Alle Rechte vorbehalten.
- **Der Name „Vokidoki“.** Eine eigene Instanz oder einen Ableger betreiben Sie
  bitte unter eigenem Namen und mit eigenem Zeichen, damit niemand ihn für das
  Original hält.

Wer eine eigene Instanz betreibt, ersetzt diese Dateien durch eigene unter
denselben Namen. Fehlen sie, läuft die App trotzdem – an ihren Stellen fehlt
dann nur das Bild, und das App-Symbol zeigt einen Punkt (der Selbsttest
meldet es).

**Fremde Bestandteile** – PHP-Bibliotheken, Tesseract.js mit seinen
Sprachdaten, die Schriften Fredoka und Nunito, die Fahnen aus Twemoji – stehen
unter ihren eigenen, freizügigen Lizenzen. Welche das sind und wo die Texte
liegen, steht in [`app/LIZENZEN.md`](app/LIZENZEN.md); in der App ist das die
Seite „Lizenzen“.

---


## Einrichtung

### 1. Was wohin gehört

Das Repository ist in drei Ordner geteilt, und jeder hat auf dem Server
seinen festen Platz im Webroot von vokidoki.de:

| im Repository | auf dem Server | wann hochladen |
|---|---|---|
| `website/` | Webroot (`index.html`, `bilder/`, `.htaccess`) | wenn sich die Startseite ändert |
| `app/` | `app/` | **bei jedem Update** — alles darin darf überschrieben werden |
| `daten-vorlage/` | `daten/` | **einmal**, bei der Einrichtung — danach nie wieder |

`app/` enthält nur, was die Anwendung zum Laufen braucht, und nichts, was
ein Update überleben muss. Zugangsdaten, Sitzungen, Fehlerprotokoll und die
erzeugten App-Symbole liegen in `daten/` (`config.php`, `storage/`). Ein
Update heisst damit: **`app/` hochladen und überschreiben lassen.**

Nicht hochgeladen werden `tests/`, `composer.phar`, `.git/`, die
Markdown-Dateien in der Wurzel des Repositorys - und `app/vendor/`: Die
Abhängigkeiten entstehen auf dem Server (siehe unten).

Liegt der Webroot so, dass der Hoster einen Ordner **oberhalb** davon
zulässt, gehört `daten/` besser dorthin, als `vokidoki-daten/` neben dem
Webroot. Die App sucht in dieser Reihenfolge und nimmt den ersten Ordner mit
einer `config.php` (`daten_dir()` in `app/lib/config.php`):

1. `../vokidoki-daten/` — eine Ebene über dem Webroot; der Webserver kommt
   gar nicht heran
2. `daten/` — im Webroot neben `app/`, gesperrt durch die `.htaccess` darin
   und die im Webroot

**Die Abhängigkeiten entstehen auf dem Server.** `composer.json` und
`composer.lock` liegen in `app/` und werden mit hochgeladen; danach per SSH:

```bash
cd /pfad/zum/webroot/app
composer install --no-dev --optimize-autoloader
```

Das legt `app/vendor/` an. Nötig ist es beim ersten Mal und immer dann, wenn
sich `composer.lock` geändert hat - ein gewöhnliches Update von `app/`
überschreibt `vendor/` nicht, es bleibt stehen. Lokal dasselbe mit
`php composer.phar -d app install`.

> Das Anthropic-SDK spricht über PSR-18 und braucht dafür eine HTTP-Client-
> Implementierung. Sie steht als `guzzlehttp/guzzle` ausdrücklich in der
> `composer.json` — sonst installiert `composer install` zwar fehlerfrei, aber
> die erste Foto-Analyse scheitert mit „No PSR-18 clients found".

**Per FTP:** den *Inhalt* von `website/` in den Webroot, `app/` als `app/`
(ohne `app/vendor/`, falls es lokal eines gibt). Beim ersten Mal zusätzlich
`daten-vorlage/` als `daten/` (samt der versteckten `.htaccess`-Dateien —
manche FTP-Programme blenden sie aus). Danach per SSH `composer install` wie
oben. Überschreiben reicht; nur eine Datei, die es im Repository nicht mehr
gibt, bleibt auf dem Server liegen, bis man sie dort löscht.

Nach dem ersten Hochladen nachsehen, ob **`app/lib/`** angekommen ist. Manche
FTP-Programme überspringen einen Ordner namens `lib`, und ohne ihn antwortet
jede PHP-Seite mit einem leeren „500 Internal Server Error" - noch bevor die
App eine Fehlermeldung ausgeben kann. Statische Dateien wie `style.css` gehen
dabei, was den Fehler zunächst woanders suchen lässt.

**Per SSH:**

```bash
VT_SSH=benutzer@server.de VT_PATH=/pfad/zum/webroot ./deploy.sh
```

Das Skript gleicht `app/` ab (auch Gelöschtes verschwindet, `vendor/`
bleibt stehen), kopiert die Startseite, legt `daten/` nur an, wenn es noch
keinen Datenordner gibt, und führt danach `composer install` auf dem Server
aus.

### 2. Konfiguration

Im Datenordner auf dem Server:

```bash
cp config.example.php config.php
chmod 640 config.php
mkdir -p storage/sessions
chmod 775 storage storage/icons storage/sessions
```

**Nicht `chmod 600`.** Bei ALL-INKL gehören die Dateien dem SSH-Benutzer
(`ssh-w…`), PHP läuft aber als der Konto-Benutzer (`w…`), der nur die Gruppe
teilt. Mit 600 kann PHP `config.php` nicht lesen, und jede Seite endet in
einem leeren 500er. 640 lässt die Gruppe lesen und sonst niemanden; von aussen
sperrt ohnehin die `.htaccess` des Datenordners. Aus demselben Grund braucht
`storage/` Schreibrecht für die Gruppe - dort legt PHP Sitzungen,
Fehlerprotokoll und App-Symbole ab. Der Selbsttest meldet, wenn das fehlt.

In `config.php` eintragen: MySQL-Zugang, das **Keyvault-Token** und ein
Start-Passwort für den Admin-Bereich. `base_path` steht auf `'/app'` — so
liegt die App auf vokidoki.de, die Startseite davor im Webroot.

Der Anthropic-Key steht **nicht** in dieser Datei — siehe nächster Abschnitt.

### 3. Datenbank

```bash
mysql -u BENUTZER -p DATENBANK < app/schema.sql
```

### 4. Prüfen und Accounts anlegen

`https://vokidoki.de/app/admin/` aufrufen, mit dem Start-Passwort anmelden
(es wird beim ersten Login als Hash gespeichert, danach ist der Wert in
`config.php` wirkungslos). Dann:

1. **Selbsttest** öffnen — dort muss alles grün sein. Er misst auch, dass
   `daten/config.php` und das Fehlerprotokoll von aussen nicht abrufbar sind.
   Die Liste der Schemaänderungen ist leer: `schema.sql` legt das fertige
   Schema an, eine frische Installation hat nichts nachzutragen.
2. Unter **Schulen** die erste Schule anlegen, mit ihrem **Kürzel** (etwa
   „opsk"). Ohne Schule kann ein Konto weder eine Sprache anlegen noch eine
   Lerneinheit sehen — beides hängt am Kurs und ein Kurs an der Schule. Es
   entsteht keine Schule von selbst. Angemeldet wird mit Schulkürzel,
   Benutzername und Passwort; Benutzernamen sind nur innerhalb ihrer Schule
   eindeutig (`lib/schulkuerzel.php`). Nach dem Update, mit dem die Kürzel
   kamen, vergibt der Admin sie für die bestehenden Schulen im **Selbsttest**
   — bis dahin kann sich dort niemand neu anmelden.
3. Unter **Accounts** das erste Lehrkraft-Konto anlegen; Klassen, Kinder und
   Kurse legt die Lehrkraft dann in ihrem eigenen Bereich an.
4. Unter **Einstellungen** ggf. Modell und Monatsbudget anpassen.

---

## Der Anthropic-Key kommt aus dem Keyvault

Der API-Key liegt nirgends in diesem Projekt. Er wird bei **jedem** Bildanalyse-Aufruf
frisch geholt:

```
GET https://cqrity.de/keyvault/api.php?key=vokabeltrainer&format=raw
Authorization: Bearer <token>
```

In `config.php` steht nur das Bearer-Token:

```php
'keyvault_url'   => 'https://cqrity.de/keyvault/api.php',
'keyvault_token' => '...',
'keyvault_key'   => 'vokabeltrainer',
```

Bewusst **ohne jede Zwischenspeicherung** (`lib/keyvault.php`) — kein statischer Cache,
keine Datei, keine Session. Dadurch greift ein rotierter Key sofort beim nächsten
Foto, ohne dass hier etwas nachgezogen werden muss. Der Preis dafür ist ein
zusätzlicher HTTPS-Aufruf von rund 100 ms pro Analyse.

Was der Vault zurückgibt, wird geprüft: leere Antwort, HTML-Fehlerseite oder ein
Wert mit Leerzeichen werden abgelehnt, statt als Key an Anthropic zu gehen.
Ist der Vault nicht erreichbar, sieht das Kind „Der Schlüsseldienst ist gerade
nicht erreichbar" (HTTP 503) statt einer Fotofehlermeldung — der Unterschied
spart bei der Fehlersuche Zeit. Der Versuch landet mit 0 USD im Kostenprotokoll.

Schlüsselmaterial wird vor jedem Protokolleintrag entfernt (`scrub_secrets()`),
und der **Selbsttest holt den Key wirklich ab** — ein abgelaufenes Token fällt
dort auf und nicht erst, wenn ein Kind ein Foto hochlädt. Angezeigt werden nur
Länge und Präfix, nie der Key selbst.

---

## Fahnen liegen als Datei bei

Jede Sprache trägt ein Sinnbild — meist eine Fahne. Gespeichert wird es als
Emoji, und auf dem Handy sieht das gut aus. **Windows stellt die
Regionalzeichen nicht als Fahne dar, sondern als die zwei Buchstaben des
Länderkürzels:** aus der britischen Fahne wird „GB". Das ist keine Sache der
Schriftart der Seite, sondern eine Entscheidung des Betriebssystems — umgehen
lässt sie sich nur, indem man das Bild mitbringt.

Deshalb liegt zu jedem Sinnbild eine SVG-Datei in `assets/flags/`, benannt
nach den Unicode-Stellen (`1f1ec-1f1e7.svg` ist `U+1F1EC U+1F1E7`, also
Grossbritannien). `lib/flags.php` und `core.js` bauen daraus ein `<img>`;
fehlt die Datei, bleibt das Emoji stehen, und dann sieht es aus wie vorher.
Eine Sprache, für die niemand eine Fahne beigelegt hat, verliert dadurch
nichts.

Kommt eine Sprache dazu, genügt es, die Datei danebenzulegen — es gibt keine
Liste, die gepflegt werden müsste.

Die Grafiken stammen aus **Twemoji** (<https://github.com/jdecked/twemoji>)
und stehen unter **CC-BY 4.0**. `assets/flags/HERKUNFT.md` sagt dasselbe noch
einmal an Ort und Stelle.

---

## Das Home-Bildschirm-Symbol pro Kind

iOS gibt jeder installierten Web-App einen **eigenen Cookie-Container**, getrennt
von Safari. Ein in Safari erzeugter Login wandert deshalb nicht automatisch in die
installierte App. Gelöst wird das über einen Geräte-Token in der `start_url` des
Manifests:

1. In **Safari** anmelden. Die App leitet auf `/?t=<Token>` um; `index.php` liefert
   dabei einen persönlichen `<link rel="manifest">` mit Namen, Farbe und Symbol
   des Kindes aus.
2. **Teilen → Zum Home-Bildschirm.** iOS liest das Manifest und schlägt
   „Lillis Vokidoki" vor.
3. Beim Start aus dem Symbol öffnet iOS die `start_url` mit dem Token, tauscht ihn
   gegen eine Session in *diesem* Container und entfernt ihn aus der URL.
4. Für ein zweites Kind auf demselben Gerät: in Safari abmelden, als dieses
   Kind anmelden, erneut
   zum Home-Bildschirm hinzufügen. Unterschiedliche `start_url` ⇒ iOS legt eine
   zweite, unabhängige App an.

Im Admin lässt sich unter **Accounts** jedes angemeldete Gerät einzeln widerrufen;
das Symbol landet dann beim nächsten Start wieder im Login.

**Wichtig:** Ohne HTTPS gibt es weder Service Worker noch Kamerazugriff in iOS.

### Aktualisierungen erreichen die installierte App

In der installierten App gibt es keine Adresszeile und kein Neu-Laden. Damit
eine neue Fassung dort ankommt, greifen drei Dinge ineinander:

- `index.php` hängt an jede Datei einen Versionsstempel aus deren
  **Änderungsdatum** — nach einem Upload zeigt die Adresse also auf etwas
  Neues, und weder Browser noch Service Worker liefern die alte Fassung.
- Die Hülle selbst wird mit `Cache-Control: no-store` ausgeliefert.
- Oben rechts steht in der App ein **Aktualisieren-Knopf** statt des
  Abmeldens: Er meldet den Service Worker ab, leert die Zwischenspeicher und
  startet neu. Abmelden wäre dort ohnehin sinnlos — das Symbol gehört zu genau
  einem Kind.

---

## Absicherung

Drei `.htaccess`-Dateien sperren, was nicht ausgeliefert werden darf: die in
`app/` sperrt `lib/`, `vendor/` und `schema.sql`, die in `daten/` den ganzen
Datenordner, und die im Webroot (aus `website/`) noch einmal `daten/` sowie
ein versehentlich mit hochgeladenes `.git/` oder `tests/`. Die Testskripte
weisen Web-Aufrufe zusätzlich selbst mit 404 ab (`PHP_SAPI`-Prüfung). Der
**Selbsttest misst das aktiv nach**, indem er diese Pfade über die eigene
Adresse aufruft — meldet er dort ein Problem, wertet dein Server keine
`.htaccess` aus (nginx, oder `AllowOverride None`).

In dem Fall den Datenordner als `vokidoki-daten/` eine Ebene **oberhalb** des
Webroots ablegen (wird automatisch gefunden), oder für nginx:

```nginx
location ~ ^/(daten|vokidoki-daten)/          { deny all; }
location ~ ^/app/(lib|vendor)/                { deny all; }
location ~ ^/app/(schema\.sql|composer\.(json|lock))$ { deny all; }
```

Sitzungsdateien liegen in `daten/storage/sessions` statt im Standardpfad des
Servers — der existiert bei geteiltem Hosting nicht immer, und ohne ihn
scheitert die Anmeldung. Unerwartete Fehler landen mit einer Kennung in
`daten/storage/error.log`;
nach außen gibt es eine verständliche Meldung statt einer leeren 500er-Seite.

Weitere eingebaute Schutzmaßnahmen: der Anthropic-Key nur im Keyvault,
Passwörter und Geräte-Token nur als Hash,
`HttpOnly`/`Secure`/`SameSite=Lax`-Cookies, CSRF-Schutz über einen eigenen Header
(API) bzw. Token (Admin-Formulare), Eigentümerprüfung bei jedem Datenzugriff und
die richtige Quizantwort ausschließlich serverseitig.

---

## Ein Wort je Sache

Dieselbe Sache hiess an verschiedenen Stellen verschieden — „Kurs" und
„Sprache", „Lerneinheit" und „Lektion", „Kinder" und „SchülerInnen" und
„Teilnehmende" und „Konten" und „Person". Das ist kein Schönheitsfehler:
Wer „Teilnehmende" liest, fragt sich, ob das etwas anderes ist als die
Kinder in der Zeile darüber.

| Sache | Wort | *nicht* |
|---|---|---|
| die Gruppe, die zusammen lernt | **Klasse** | Gruppe |
| Klasse + Sprache, mit eigenen Unterlagen | **Kurs** | Sprache, Fach |
| eine Portion Vokabeln, meist eine Buchseite | **Lerneinheit** | Lektion, Unit |
| wer lernt | **Kind** | SchülerIn, Teilnehmende, Konto, Person |
| wer unterrichtet | **Lehrkraft** | Lehrer, Kollegin |
| das gedruckte Blatt mit Zugangsdaten | **Zettel** | Blatt, Anschreiben |
| das erzeugte erste Passwort | **Anfangspasswort** | Startpasswort |
| aufmachen, was die Klasse sehen darf | **freigeben** | veröffentlichen |

Im Quelltext bleiben die englischen Namen (`units`, `vocab`, `courses`) —
sie stehen in der Datenbank und ändern sich nicht, weil eine Beschriftung
sich ändert.

## Das Schema, und wie es sich ändert

**`schema.sql` legt das fertige Schema an.** Eine frische Installation hat
nichts nachzutragen: Die Liste der Schemaänderungen in `lib/schema.php` ist
leer, und der Selbsttest meldet nichts Offenes.

Dort standen fünfundvierzig Änderungen, und jede einzelne war der Weg von
einem älteren Stand auf den heutigen: der Umbau vom Besitzer-Modell („diese
Lerneinheit gehört diesem Kind") auf Kurse, das Nachziehen der Schul- und
Klassenspalten, das zweistufige Fallenlassen von `units.user_id`, das
Nachtragen von Sprachkürzeln und Positionen — und zehn `family.*`-Schritte,
die den Bestand der alten Familien-App in eine Schule namens „Familie"
überführten. Nichts davon wird je wieder laufen; es gibt keine ältere
Datenbank mehr, die überführt werden müsste.

Mit ihnen sind die Helfer gegangen, die nur sie brauchten:
`schema_has_legacy_data()`, `column_is_nullable()`,
`vocab_positions_have_gaps()`, `language_code_backfill_*()` und die drei
`password_seed_*()`. Die Wörter für die Anfangspasswörter stehen jetzt als
`INSERT` in `schema.sql` — ohne sie blieben die Listen leer, und
`password_generate()` liefert dann bewusst gar kein Passwort.

**Der Weg selbst bleibt.** Die nächste Schemaänderung kommt in dieselbe
Liste, `ensure_schema()` führt sie auf Knopfdruck im Selbsttest aus, und
`schema_was_applied()` steht bereit, falls sie Daten nachträgt statt
Struktur. Eine Änderung gehört **immer an zwei Stellen**: in die Liste für
die laufende Installation und in `schema.sql` für die nächste frische.

Dass beides nicht auseinanderläuft, ist keine Frage der Sorgfalt, sondern
eine Prüfung: Die Suite fährt gegen eine aus `schema.sql` gebaute Datenbank
und besteht darauf, dass `schema_pending()` leer ist.

## Vokidoki — Name, Zeichen, Schrift

Die App heisst **Vokidoki**. Das V des Namens ist Voki selbst; dahinter steht
„okidoki" in fettem Fredoka, im Grün des Maskottchens. Das Wortzeichen liegt
als `assets/vokidoki_logo.svg` und steht auf der Anmeldeseite.

„okidoki" steht darin als **Pfad, nicht als `<text>`**. Ein `<text>` in einem
über `<img>` eingebundenen SVG findet die Schriften der Seite nicht und fällt
auf irgendeine Systemschrift zurück — ausgerechnet beim Namen der App, und
ausgerechnet in dem Augenblick vor dem ersten Bild, in dem die Schrift noch
gar nicht geladen ist. Als Pfad sieht es überall gleich aus.

Wer das Zeichen neu bauen will: Es entsteht aus `app/assets/voki-mini.svg` und
Fredoka 700, auf Versalhöhe gesetzt und um ein Dreissigstel grösser als die
Buchstaben — ein rundes Maskottchen wirkt neben fetten Buchstaben sonst
kleiner, als es ist.

### App-Symbol und Favicon

Auf dem Home-Bildschirm liegt der frohe Voki mit seinen Sternen, auf der
Farbe, die das Kind im Profil wählt. Weil diese Farbe auch Grün oder Gelb
sein kann, tragen Voki und jeder Stern einen **weissen Rand** — ohne ihn
verschwände er auf manchen Farben ganz. Derselbe Voki ist das Favicon, dort
ohne farbige Fläche.

`icon.php` zeichnet mit GD, und GD liest kein SVG. Deshalb liegt Voki
zweimal bereit, beide gebaut aus `app/assets/voki-mini.svg`:

- `app/assets/voki-icon.svg` — mit Rand; Favicon und Vorschau im Profil
- `app/assets/voki-icon.png` — dasselbe, 1024 px, durchsichtig; für `icon.php`

Ändert sich Voki, beide neu bauen und mit einchecken:

```
node tests/browser/voki-symbol.mjs
```

Schon auf einem Telefon abgelegte Symbole bleiben, wie sie sind — iOS holt
das Bild nur beim Hinzufügen zum Home-Bildschirm.

### Zwei Schriften

| | |
|---|---|
| **Fredoka** | Überschriften und Knöpfe |
| **Nunito** | alles zum Lesen: Fließtext, Sätze, **Vokabeln** |

Die Antwortknöpfe im Quiz sind ausdrücklich ausgenommen: Sie sind zwar
`<button>`, aber darin steht eine Vokabel. Eine runde Anzeigeschrift über
französischen Wortformen macht das Vergleichen schwerer, nicht leichter — und
genau darum geht es beim Üben. Dasselbe gilt für das abgefragte Wort selbst.

**Beide Dateien liegen auf dem eigenen Server**, nicht bei Google. Das ist
kein Geschmacksurteil: Ein eingebundenes Stilblatt von `fonts.googleapis.com`
schickte die Adresse jedes Kindes bei jedem kalten Start dorthin — in einer
Schule nicht zu rechtfertigen. Und ohne Netz gäbe es dann gar keine Schrift,
obwohl diese App ausdrücklich weiterlaufen soll.

Je Familie eine einzige Datei mit allen Strichstärken (*variable font*),
beschnitten auf Latin und Latin-Ext — das deckt Deutsch, Französisch, Dänisch,
Englisch und Latein ab. Zusammen rund 90 KB, mit `font-display: swap` und
einem Jahr Cache-Control.

## Freies Üben

Die dritte Übungsart, und die einzige ohne Ziel. Die beiden anderen sind
fertig, wenn jede Vokabel dreimal hintereinander sass; diese läuft, bis
jemand aufhört, und zieht aus allem, was ausgewählt wurde — auch aus dem, was
längst sitzt. Wiederholen, nicht abarbeiten.

Zwei Wege hinein:

* **Von einer Lerneinheit** (`#/unit/12/frei`) — alles, was dort freigegeben
  ist. Der Zurück-Pfeil führt dann in die Lerneinheit.
* **Vom Kurs** (`#/frei/waehlen/5`) — erst die Frage „Welche Lerneinheiten
  sollen geübt werden?" mit einem Haken je Einheit, dann die Runde über alle
  angekreuzten (`#/frei/12-13-15`). Der Zurück-Pfeil führt in den Kurs.

Die Auswahl steht in der Adresse und nicht in einer Variablen: So übersteht
eine Runde das Neuladen, und der Zurück-Pfeil des Browsers führt dorthin, wo
man war. Deshalb steht dort auch, woher man kam — beide Wege hiessen einmal
`#/frei/12`, und der Pfeil oben links führte auch von der Lerneinheit aus in
den Kurs.

Gewürfelt wird zweierlei — welche Vokabel und welche Aufgabenart. Drei von
fünf Aufgaben werden Lückentext, wenn es zu der Vokabel einen Satz gibt: Er
ist die schwerere Übung, und wer frei übt, hat das Auswählen meist hinter
sich. Eine Vokabel ohne Satz bekommt ihre Frage trotzdem.

### Der Lernstand bleibt unberührt

**Das ist die wichtigste Eigenschaft dieser Übung.** Freies Üben schreibt
nicht in `progress`: Eine richtige Antwort macht hier nichts „gekonnt", und
ein Fehler beim lockeren Wiederholen reisst keine Serie ein, die über Wochen
entstanden ist. „Gekonnt" bleibt die Aussage der strukturierten Übung, und
der Fortschrittsbalken einer Lerneinheit bewegt sich hier nicht.

Gezählt wird trotzdem. Jede richtige Antwort geht als eigenes Ereignis in den
Strom (`k: 'frei'`), und der Server verbucht damit **nur den Tag**:

```php
if ($art === 'frei') {
    …
    streak_verbuchen($uid, $tag, $richtig, false);   // kein record_answer()
}
```

Das Ereignis trägt die Vokabel trotzdem mit — an ihr prüft dieselbe Schranke
wie bei jeder anderen Antwort, ob dieses Konto überhaupt antworten darf, und
eine Quittung bekommt es auch: Ein zweimal geschickter Stapel zählt sonst
doppelt. Damit landen die Antworten in Serie, Kalender und Abzeichen. Geübt
ist geübt.

### Die Serie der Runde

Über jeder Aufgabe steht eine Leiste: in der Mitte, wie viele gerade
hintereinander richtig sind, und das Beste dieser Runde, dazwischen ein
Balken. Aussen, etwas leiser, die Zahlen der ganzen Runde: links der Voki mit
allen richtigen Antworten, rechts die Trefferquote.

* Am Anfang stehen beide auf **0**, der Balken voll und **grün**.
* Solange die laufende Serie das Beste *ist*, zählen beide Zahlen gemeinsam
  hoch, und der Balken bleibt voll und grün.
* Nach einem Fehler fällt die laufende Serie auf 0, der Rekord bleibt stehen.
  Der Balken zeigt jetzt den Weg zurück: bei 2 von 5 ist er zu zwei Fünfteln
  gefüllt, in der Akzentfarbe. Holt die Serie den Rekord ein, wird er wieder
  grün, und beide zählen gemeinsam weiter.

Die Zahlen springen sofort nach der Antwort weiter, mit derselben kurzen
Bewegung wie der Punkt in den anderen Übungen, und zur selben Zeit wie der
Ton. Eine Tür „Runde beenden" gibt es nicht mehr — sie tat dasselbe wie der
Zurück-Pfeil daneben.

**Der Lückentext ist derselbe Bildschirm wie in der Lückentext-Übung**
(`lueckeZeigen()` in `views/cloze.js`): Feld in der Lücke, Zeichenreihe,
der Knopf, der Richtig und Falsch trägt, nach einem Fehler „Weiter" statt
eines Zeitablaufs, und die Tastatur bleibt zwischen zwei Lückenaufgaben
offen. Das freie Üben bringt nur mit, was bei ihm anders ist — die Leiste
oben und wohin die Antwort geht.

Gelobt wird an zwei Marken: alle **25** richtigen Antworten und alle **5**
hintereinander. Unter dem Lob steht, wofür — „25 Richtige!" oder „5 in
Folge"; ohne diese Zeile wäre es ein Ausruf ohne Anlass. Fallen beide
zusammen, gewinnt die seltenere: Wer bei der 25. auch noch fünf in Folge hat,
soll die 25 lesen.

### Kleinigkeiten mit Grund

**Das Symbol ist eine gezeichnete Hantel**, kein Emoji — es gibt keines. Das
nächstliegende (🏋️) ist ein Mensch, der etwas stemmt, und das ist etwas
anderes als das Gerät. Zwei Scheiben, eine Stange, `currentColor`.

**Nach dem Verlassen schaltet nichts mehr weiter.** Der Zeitgeber, der die
nächste Aufgabe bringt, prüft vorher die Adresse:

```js
if (runde === null || location.hash !== runde.adresse) return;
```

Verglichen wird die ganze Adresse vom Start der Runde, nicht ein Anfang wie
`#/frei/` — von der Lerneinheit aus heisst sie `#/unit/12/frei`.

Der Zurück-Pfeil und das Menü wechseln die Adresse, ohne dass diese Ansicht
davon erfährt. Ohne die Prüfung liefe der
Zeitgeber trotzdem ab und zeichnete die nächste Aufgabe über die Seite, auf
der man gerade gelandet ist.

## Die Serie

Links vom Zahnrad steht auf jeder Seite Voki und eine Zahl: an wie vielen
Tagen hintereinander dieses Kind gelernt hat.

| Lage | Bild | Zahl | heisst |
|---|---|---|---|
| heute schon gelernt | Voki froh, **farbig** | **grün** | alles gut |
| heute noch nicht | Voki froh, aber grau | grau | der Tag ist noch offen |
| ein Tag ausgelassen | Voki traurig, grau | grau | heute nichts mehr, und sie ist weg |
| zwei Tage ausgelassen | Voki traurig, grau | **0** | von vorn |

**Ein Tag Pause wird verziehen, zwei nicht.** Wer Montag lernt, darf Dienstag
aussetzen und Mittwoch weitermachen — die Serie läuft weiter. Wer Dienstag
*und* Mittwoch aussetzt, fängt Donnerstag bei null an. Der traurige Voki ist
die Warnung dazwischen: Er erscheint einen Tag, bevor die Serie fällt, nicht
erst danach. Eine Warnung, die erst kommt, wenn nichts mehr zu retten ist,
ist keine.

### Wie man einen Tag bekommt

Zwei Wege, und der zweite ist der wichtigere:

1. **Eine Vokabel neu können** — dreimal hintereinander richtig, dieselbe
   Regel wie überall (`KNOWN_THRESHOLD`).
2. **Oder zehn richtige Antworten** an diesem Tag (`STREAK_UEBUNG_MIN`).

Ohne den zweiten Weg könnte eine Serie aus einem Grund sterben, an dem das
Kind nichts ändern kann: Das Quiz legt nur Vokabeln vor, die noch nicht
gekonnt sind. Wer alles Freigegebene kann, bekommt gar keine neue mehr — und
gäbe die Lehrkraft eine Woche nichts frei, verlöre eine ganze Klasse am
selben Tag ihre Serien, obwohl alle täglich geübt haben. Wiederholen ist dann
das Beste, was ein Kind tun kann, und dafür soll es den Tag bekommen.

Ein Tipp auf das Abzeichen erklärt beides in einer Karte. Das ist keine
Zugabe: Gerade der zweite Weg erklärt sich nicht von selbst, und ein Kind,
das sich fragt, warum die Zahl heute grau ist, soll eine Antwort bekommen,
ohne jemanden fragen zu müssen.

### Welcher Tag zählt — der des Geräts

**Jede Antwort bringt ihren eigenen Tag mit** (`d` im Ereignisstrom), und der
kommt vom Gerät, nicht vom Server. Die App übt ohne Netz und schickt ihre
Antworten später am Stück; wer Montag im Zug lernt und Mittwoch wieder online
ist, hätte sonst zwei verpasste Tage und eine tote Serie — für Lernen, das
stattgefunden hat.

Der Server stutzt den Tag auf ein glaubhaftes Maß (nicht in der Zukunft,
höchstens vierzehn Tage alt), lehnt ihn aber nie ab: Die Antwort selbst war
ja richtig. Dass sich der Tag stellen lässt, indem jemand die Uhr des Tablets
verstellt, ist bekannt und in Kauf genommen. Es ist eine Lernhilfe, keine
Klassenarbeit.

Ein Tag beginnt in `Europe/Berlin` (`STREAK_ZONE`) — ausdrücklich dort und
nicht per `date_default_timezone_set()`: Die Zeitstempel in der Datenbank
schreibt MySQL mit `NOW()` in *seiner* Zone, und der Admin-Bereich zeigt sie
mit `date()` an. Stellte man PHP um und MySQL nicht, stünden dort Uhrzeiten,
die es nie gab.

### Gerechnet wird zweimal

`lib/streak.php` auf dem Server, `vorrat.js` im Gerät — dieselbe Regel in
zwei Sprachen, wie schon beim Vergleich der Lückenantwort. Es geht nicht
anders: Das Abzeichen steht auf jeder Seite und muss auch ohne Netz stimmen,
und eine installierte App liegt wochenlang im Hintergrund — zwischen dem
letzten Abruf und dem Blick auf den Bildschirm kann Mitternacht liegen.

Die Arbeit ist dabei geteilt. Der Server rechnet die **Kette** aus allen
Tagen (`streak_rechnen`), das Gerät rechnet nur die **Anzeige** daraus
(`serieAnzeige`) — es kennt nur den Ausschnitt, den der Kalender braucht,
muss aber wissen, ob die Serie heute noch steht. Dafür reichen zwei Werte: wie lang die
Kette an ihrem letzten Lerntag war, und wann dieser Tag war.

Zusammengehalten werden beide Fassungen von `tests/faelle/serien.json` —
einer Fallsammlung, die keiner der beiden Seiten gehört. `tests/sentences.php`
prüft sie gegen PHP, die Browser-Suite gegen JavaScript. Fällt eine
auseinander, fällt eine Suite um.

### Gespeichert wird in Tagen, nicht als Zähler

`learn_days` hält eine Zeile je Kind und Tag (`learned`, `correct`). Ein
Zähler an `users` wäre kleiner, liesse sich aber nie nachrechnen — und genau
das wird gebraucht: Antworten kommen aus der Warteschlange nachträglich für
vorgestern herein, und ein Zähler wüsste dann nicht mehr, ob dieser Tag schon
zählte. Aus den Tagen fällt die Serie jedesmal neu heraus, und der Kalender
im Konto kommt ohne eine zweite Buchführung aus. Nur die Bestmarke steht als
`users.streak_best` daneben; sie überlebt eine gerissene Serie.

**Fehlt das Schema noch, hält die Serie still.** Der Code geht per FTP sofort
live, die Schemaänderung läuft erst auf Knopfdruck im Selbsttest — dazwischen
gibt es `learn_days` nicht. Ohne diesen Riegel fiele in genau diesem Fenster
jede angemeldete Seite um, wegen eines Abzeichens. Eine Serie darf nie der
Grund sein, warum ein Kind nicht üben kann.

### Belohnung: Punkt, Konfetti, Feuerwerk

Drei Stufen, und jede hat ihren Anlass:

| | wann | was |
|---|---|---|
| **Punkt** | jede richtige Antwort | er wächst auf, wird grün, fällt zurück in die Reihe |
| **Konfetti** | die Vokabel sitzt (drittes Mal hintereinander) | Schnipsel über den Schirm, davor ein Lob |
| **Feuerwerk** | die ganze Lerneinheit steht | Raketen hinter der Geschafft-Seite, bis jemand weiterklickt |

**Die Punkte standen vorher auf dem Stand *vor* der Antwort.** Sie rückten
erst mit der nächsten Frage nach — wer zweimal richtig lag, sah zwei Punkte,
und beim dritten Mal, dem Augenblick, auf den es ankommt, immer noch zwei.
Jetzt springt der Punkt sofort an, mit derselben Länge wie der Ton (500 ms).
Er wächst über `transform: scale`, nicht über `width` — sonst rücken die
Nachbarpunkte beiseite und die Reihe zappelt.

**Nichts davon hält den Ablauf auf.** Nach einer richtigen Antwort bleibt es
bei denselben 700 ms (Quiz) bzw. 900 ms (Lückentext) bis zur nächsten Frage.
Konfetti und Feuerwerk hängen deshalb an `<body>` und nicht in der Ansicht:
`render()` ersetzt den ganzen Inhalt von `#app`, und in der Ansicht wären sie
schon 700 ms später mitten im Flug verschwunden. Die nächste Frage wird
darunter gezeichnet, während es noch fliegt; `pointer-events: none` lässt
jeden Druck durch. Die Browser-Suite misst genau das nach.

Das Lob wird aus vierzehn kurzen Wendungen gezogen („Super!", „Spitze!",
„Klasse!", „Prima!" …), nie zweimal dieselbe hintereinander — das fällt
sofort auf. „Sitzt!" ist bewusst nicht dabei: Das steht schon als Rückmeldung
unter der Frage, und zweimal dasselbe Wort auf einem Bildschirm ist eine
Verdopplung, keine Steigerung.

**Das Feuerwerk hört nicht von selbst auf.** Die Geschafft-Seite ist kein
Durchgang, sondern der Augenblick, auf den zwanzig Vokabeln hingearbeitet
haben — drei Raketen und Schluss waren zu Ende, bevor ein Kind aufgesehen
hatte. Jetzt steigt alle 850 ms eine neue, bis jemand weiterklickt.

Es liegt dabei **hinter** der Seite (`z-index: -1`), nicht darüber: Die
Überschrift und die beiden Knöpfe bleiben lesbar, und die Raketen steigen
drumherum. `z-index: 0` genügt dafür nicht — ein fixiertes Element wird auch
damit über den nicht positionierten Blöcken der Seite gezeichnet; erst bei
−1 liegt es darunter, und der Hintergrund von `<body>` trägt es weiterhin,
weil der als Grund der ganzen Seite noch tiefer liegt.

Beendet wird es **in `render()`**, nicht an den Knöpfen: Jeder Wechsel der
Ansicht kommt durch diese eine Zeile, ob über „Noch einmal üben", „Zur
Übersicht", das Menü oder den Zurück-Pfeil. Ein eigener Hörer je Ausgang
wäre einer zu wenig. Das Konfetti ist davon ausdrücklich nicht betroffen —
es soll über der nächsten Frage weiterfliegen, und die wird in genau diesem
`render()` gezeichnet. Deshalb haben die beiden **getrennte Bühnen**: Sonst
schnitte das Feuerwerk das Konfetti der dritten richtigen Antwort 700 ms
später mitten im Flug ab.

Jede Rakete räumt sich nach ihrem Ausklang selbst ab, sonst wüchse die Seite
mit jeder um achtzehn Elemente; gemessen sind nie mehr als zwei gleichzeitig
in der Luft. In einem versteckten Tab steigt keine — eine Uhr, die dort
weiterläuft, kostet Strom für etwas, das niemand sieht.

Bei `prefers-reduced-motion` bleibt das Lob stehen, die Schnipsel und Funken
fallen weg.

### Der Kalender im Konto

Unter „Deine Serie" steht ein **Monatskalender**: sieben Spalten, Montag
links, und in jedem Kästchen die Zahl der richtigen Antworten dieses Tages —
bis zu dreistellig. Die Nummer des Tages steht nicht darin: Sie ergibt sich
aus der Stelle im Gitter, und zwei Zahlen in einem Kästchen dieser Grösse
liest niemand mehr. Wochentagsköpfe braucht es auch keine; im Kalender weiss
jeder, wo er steht.

Hier standen dreissig Kästchen in einer Reihe — eine Zeitleiste ohne Bezug.
Sie beantwortete „wie viele Tage am Stück", aber nicht „wann eigentlich": Der
vierte Kasten von links war irgendein Dienstag.

Geblättert wird mit zwei Pfeilen, **zwölf Monate zurück** — oder bis zu dem
Monat, in dem das Konto entstanden ist, wenn das später war. In Monate zu
blättern, in denen es das Konto noch gar nicht gab, sähe aus wie ein Fehler.
Weiter zurück hält die Tabelle ohnehin nichts: `streak_aufraeumen()` räumt
weg, was älter ist als die Historie der Serie — selten und nebenbei, wie die
Quittungen in `api/bundle.php`, denn dieses Projekt hat keinen Cron.

Gerechnet wird durchweg in **UTC**. Die Zeitumstellung macht einen Tag 23
oder 25 Stunden lang, und ein Kalender, der im Oktober einen Tag verliert,
ist schlimmer als keiner.

Die Hülle (`index.php`) bekommt den Kalender **nicht** mit — sie wird nie
zwischengespeichert und bei jedem Seitenaufruf neu gebaut. Sie holt nur, was
das Abzeichen braucht (`streak_stand($uid, false)`); der Kalender kommt mit
dem Bündel, denn das liegt ohnehin im Gerät und soll auch ohne Netz etwas
zeigen.

### Der Ton

Bei jeder richtigen Antwort ein kleines Glöckchen — im Browser erzeugt statt
als Datei geladen, damit es ohne Netz und beim allerersten Mal sofort da ist.
Abschaltbar im rechten Menü; die Einstellung gehört dem Gerät, nicht dem
Konto, wie die Farbwahl auch.

**Warum mehrere Töne je Anschlag.** Hier stand ein Dreieckton mit einer
Hüllkurve von 0,28 Sekunden: ein Piepser, abgeschnitten, bevor er klingen
konnte, und mit der Obertonreihe eines Rechtecksignals eher Spielzeugtrompete
als Glocke. Eine Glocke besteht aus mehreren Teiltönen, die **nicht** die
ganzzahligen Vielfachen des Grundtons sind — beim Glockenspiel ungefähr
1 : 2,76 : 5,40 : 8,93 — und die verschieden schnell verklingen: Die hohen
sind im Anschlag am lautesten und als erste weg, der Grundton trägt den
Nachhall. Genau dieses Auseinanderlaufen ist der Unterschied zwischen
„Glocke" und „Ton"; nachgebaut wird es mit einem Oszillator je Teilton.

Gemessen: Der Ausklang geht von **344 ms auf 1527 ms**, und er endet
tatsächlich bei null. Der alte Ton lief exponentiell auf 0,0001 und wurde
dort abgeschaltet — ein exponentieller Verlauf erreicht die Null nie, und ein
Oszillator, der bei einem Restwert aufhört, knackt. Die letzten dreissig
Millisekunden gehen deshalb linear auf die Null.

Alle Anschläge laufen über einen gemeinsamen Regler. Beim zügigen Üben kommt
der nächste, bevor der vorige verklungen ist; ohne ihn addierte sich das
irgendwann über die Eins, und Übersteuerung klingt nach kaputt, nicht nach
laut. Nachgemessen bleibt der Spitzenwert auch bei sechs Antworten in Folge
im Abstand von 700 ms genau dort, wo er bei einer einzelnen liegt.

## Der Lehrkraft-Bereich beginnt bei der Arbeit

**Die Startseite sind die eigenen Kurse.** Vorher war es die Klassenliste —
und damit die Verwaltung: Eine Klasse legt man einmal im Schuljahr an, eine
Lerneinheit jede Woche. Bis zur Freigabe waren es drei Klicks und vier
Seiten, und die Kursseite war die einzige Stelle im ganzen Quelltext, die
auf eine Lerneinheit verlinkte.

Jetzt steht je Kurs eine Karte da, mit den beiden Handgriffen, die ständig
gebraucht werden: **„+ Lerneinheit"** führt in die Einleseansicht dieses
Kurses, **„Freigeben"** direkt in die neueste Lerneinheit. Ohne Lerneinheit
ist der Knopf abgeblendet statt abwesend — eine Karte, die je nach Datenlage
anders aussieht, lässt einen suchen. Die Kurse der Kolleginnen stehen
zugeklappt darunter: Eine Vertretung muss an die Unterlagen kommen, aber das
ist der Ausnahmefall. Klassen und Kinder sind Verwaltung und stehen am Fuß.

Dafür gibt es `courses_for_teacher()` — die Abfrage „welche Kurse
unterrichte *ich*" fehlte bis dahin ganz; nichts im Quelltext filterte je
nach Konto **und** Rolle, obwohl der Index dafür seit der Schulumstellung
bereitlag.

Am Fuß steht die **Verwaltung als Karte**, nicht als Fußnote: Klassen
anlegen, Kinder eintragen, Zettel drucken — mit einem Knopf statt einem
unterstrichenen Wort mitten in einem grauen Satz. Was man zweimal im Jahr
braucht, gehört nach unten, aber es gehört auszusehen wie etwas, das man
anfassen kann.

**Und die letzte Kachel legt einen neuen an.** Ein Kurs entstand vorher in
einer Anlegezeile am Fuß der Kurstabelle *einer Klasse* — wer einen wollte,
musste erst wissen, dass Kurse in Klassen wohnen, dann die Klassenliste
finden, dann die richtige Klasse öffnen. Drei Entscheidungen, von denen nur
eine mit dem Kurs zu tun hatte. Einen Kurs *ohne* Klasse gab es über die
Oberfläche gar nicht mehr, obwohl das Datenmodell ihn kann.

Jetzt fragt `teacher/neu.php` das, was wirklich zu entscheiden ist, und zwar
eins nach dem anderen:

1. **Für welche Klasse?** Alle Klassen der Schule als Kacheln, und
   „Kurs ohne Klasse" als eine davon — nicht als Kleingedrucktes darunter.
   Auf jeder Kachel steht, was die Wahl bedeutet („28 Kinder kommen mit in
   den Kurs" gegen „Bleibt leer — Kinder nimmst du einzeln auf"). Eine
   Klasse, die es noch nicht gibt, lässt sich hier anlegen; sonst wäre der
   erste Schritt für eine neue Lehrkraft eine Sackgasse.
2. **Für welche Sprache?** Die fünf Schulsprachen als Kacheln, alle
   übrigen im durchsuchbaren Feld darunter.
   Jede Kachel ist ein Absendeknopf, der seinen Sprachnamen trägt;
   abgeschickt wird nur der gedrückte. Das kann HTML von sich aus.

Danach steht der Kurs, und man ist da, wo man hinwollte: auf seiner Seite.
Der Name ergibt sich aus beidem („Englisch - 5B"), die Flagge aus der
Sprachliste — sie ist eine Eigenschaft der Sprache und keine Entscheidung,
die jemand treffen soll. Gefragt wird erst, wenn genau dieser Name schon
vergeben ist; dann ist das keine Zusatzfrage, sondern die Antwort auf ein
Problem.

Jeder Schritt ist eine eigene Adresse. Das ist nicht Geschmack: Zurück-Knopf,
Lesezeichen und Neuladen sollen tun, was man von ihnen erwartet — und ohne
JavaScript muss es genauso gehen.

**Und die Anmeldung führt dorthin.** Es gibt zwei Wege hinein — das
Formular des Lehrkraft-Bereichs und die Anmeldung der App —, und der zweite
kannte nur ein Ziel: die Kachelansicht. Eine Lehrkraft landete damit in der
Ansicht ihrer Klasse und musste sich erst in die Verwaltung durchklicken.
Jetzt liefert `api/auth.php` das Ziel je nach Rolle mit. Die Ansicht der
Klasse bleibt ihr offen, aber als eigener Griff: der Ansichtsschalter im
Zahnrad.
Ein Geräte-Token entsteht dabei keiner — der ist der Schlüssel der
installierten App, und wer in die Verwaltung geht, braucht ihn nicht.

**Der Kurswechsel steht im Menü.** Wer Englisch in der 5a und Französisch in
der 7b gibt, musste hoch zur Schule und durch eine andere Klasse wieder
hinunter. Jetzt stehen die eigenen Kurse eingerückt unter „Meine Kurse" —
ein Griff von jeder Seite aus.

Die Navigation ist **ein Burger links und ein zweiter rechts** — sonst
steht in der Leiste nur der eigene Name. Hier war einmal ein Pfad aus
Knöpfen (`🏠 › Englisch - 5B › Unit 4`), und am Rechner war das
richtig. Auf einem Telefon nicht: Drei Knöpfe mit Kursnamen darin brauchen
zwei Zeilen, und die Leiste war damit so hoch wie der halbe Bildschirm. Was
man selten braucht, darf nicht dauernd dastehen.

Links schiebt sich die Navigation herein, hierarchisch: **🏠 Meine Kurse**,
darunter eingerückt die eigenen Kurse mit ihren Fahnen (der aktuelle
markiert), darunter **Alle Kurse der Schule**, und nach einem Trennstrich
**Klassen und Kinder**. Rechts dasselbe noch einmal für das eigene Konto:
Profil, Passwort ändern, Abmelden — und „Passwort ändern" führt auf
`#/konto/passwort`, also gleich ans Feld statt auf die Seite, auf der es
irgendwo steht.

Gebaut als `<details>`, nicht als Skript: Der Browser kann das Auf- und
Zuklappen von selbst, mit Tastatur und Vorleseprogramm, und ohne JavaScript
funktioniert es genauso — nur ohne das Hereinschieben und ohne den Schleier,
der sich wegklicken lässt. Das Hereinschieben ist eine **Animation** und
kein Übergang: `<details>` blendet seinen Inhalt beim Schliessen aus, und
ein `transition` läuft auf einem Element, das gerade erst entsteht, gar
nicht an. Geprüft wird sie im **selben Augenblick** wie der Klick — sie
dauert gut eine Viertelsekunde, und wer danach nachsieht, findet nichts
mehr und hält das für „keine Animation".

`teacher.js` legt drei Dinge dazu, die der Browser nicht wissen kann: dass
die beiden Schubladen einander ausschliessen, dass ein Druck auf den
Schleier schliesst, und dass Escape schliesst.

Jede Zeile darin ist **gleich hoch** (`min-height: 48px`), und beide
Schubladen fangen oben an. Das war nicht so: Die Regeln der
Betreiber-Leiste (`.adminbar nav a`) griffen auf die Schubladen durch —
die sind ebenfalls `<nav>` und liegen in derselben Leiste —, und machten
aus ihnen Flex-Reihen mit Reiter-Innenabstand. Jetzt steht dort
`.adminbar > nav`, als direktes Kind.

**Die Klasse steht nicht mehr darin.** Sie stand einmal dazwischen —
`Schule › Klasse 5B › Englisch - 5B` —, und das bildete die Datenstruktur
ab, nicht den Weg: Eine Klasse öffnet man zweimal im Jahr, einen Kurs jede
Woche, und beim Wechsel zwischen zwei eigenen Kursen war der Umweg über die
Klasse genau das. Die Klasse ist deshalb kein Halt mehr, sondern ein Ziel
wie jedes andere: Sie steht dort, wo es um ihre Kinder geht.

**Der Knopf zum Nachtragen sagt, was er tun würde.** „Klasse 5B nachtragen"
ließ offen, ob dabei etwas passiert — und meistens passierte nichts: Die
Kinder kommen beim Anlegen des Kurses mit hinein, nachzutragen ist nur, wer
seither dazugekommen ist. Wer draufdrückte, bekam „Es war niemand
nachzutragen", also eine Auskunft auf eine Frage, die er nicht gestellt
hatte. Jetzt steht die Zahl darin („9 fehlende Kinder aus Klasse 5B
eintragen"), und ohne etwas zu tun ist er abgeblendet — dastehen soll er
trotzdem, sonst sucht man ihn beim nächsten Mal. Gezählt wird mit
`course_class_missing()`, derselben Menge, die `course_sync_class()`
eintragen würde.

Von der Kursseite führt „Klasse 5B verwalten" dorthin, und der Kurs reist
in der Adresse mit (`class.php?id=…&kurs=…`). Der Pfad in der Klasse lautet
dann `Schule › Englisch - 5B › Klasse 5B` statt `Schule › Klassen › Klasse
5B`, und daneben steht ein Knopf zurück. Wer ohne Kurs kommt — über
„Verwaltung: Klassen und Kinder" am Fuß der Startseite —, bekommt den
anderen Pfad. Beides sind echte Wege, und der Pfad zeigt den, den man
gegangen ist.

Die Klassenliste ist damit das, wonach sie aussieht: die Liste der Klassen,
in denen Kinder angelegt und Zettel gedruckt werden. Kurse stehen dort keine
mehr — auch nicht die ohne Klasse; **alle** Kurse stehen auf der Startseite.

**Eine Lerneinheit entsteht auch leer.** In der Kurstabelle steht als
letzte Zeile ein Knopf — „Lerneinheit hinzufügen" — statt der beiden
Einleseknopfe, die dort standen. Die Frage an dieser Stelle lautet nicht
„womit fülle ich sie", sondern „ich brauche eine neue"; womit, entscheidet
man auf ihrer Seite, wo alle drei Wege nebeneinander stehen — auch der von
Hand, den es dort gar nicht gab. Sie heißt zunächst „Unbenannte
Lerneinheit"; ein Pflichtfeld wäre eine Frage vor der Arbeit, und beim
Einlesen kommt der Titel ohnehin von der Buchseite.

**„Wen aufnehmen?" sucht mit, während getippt wird.** Eine Schule hat
dreihundert Kinder, und der Kurs braucht eines davon; dort stand ein
`<datalist>`, und der Browser bietet seine Vorschläge nach eigenem
Gutdünken an, in eigener Gestalt, auf dem Telefon oft gar nicht. Jetzt
filtert das Feld bei jedem Zeichen in der Liste derer, die noch **nicht** im
Kurs sind. Drei Zustände: mehrere Treffer → Liste darunter; genau einer →
die Liste verschwindet und der Rest des Namens steht grau hinter dem
Getippten, Enter nimmt ihn; keiner → ein Satz statt einer leeren Liste.

Hinter jedem Namen steht **die Klasse in Klammern** — „Marta W. (7b)",
„Herr Vertretung (Lehrkraft)" —, und gesucht wird in beidem: „Marta W."
gibt es an einer Schule zweimal, „Marta W. (7b)" nicht, und wer die Klasse
kennt, aber den Namen nur halb, kommt über sie ans Ziel. In der Tabelle
„Wer im Kurs ist" steht sie ebenfalls, an der Stelle der Rolle: „Kind" in
jeder Zeile sagte nichts.

Das Graue ist kein Text im Feld, sondern ein zweites Element darunter — ein
Eingabefeld kann nicht zwei Farben zugleich —, und der getippte Teil wird
darin unsichtbar gesetzt, damit die Buchstaben nicht doppelt stehen. Die
Namen liest das Skript aus demselben `<datalist>`, das ohne JavaScript
stehenbleibt: eine Quelle, zwei Wege. Und die Vorschlagsliste hängt an
keinem Vorfahren (`position: fixed`), sonst schnäpe `table.data` mit seinem
`overflow: hidden` drei Viertel davon ab — dieselbe Falle wie beim
Sprachfeld, und im DOM steht die Liste dabei vollständig da. Gesehen hat es
erst eine Prüfung mit `elementFromPoint`.

Rechts in der Leiste stehen der eigene Name, ein Zahnrad und ein Knopf zum
Abmelden. Das Zahnrad führt in dieselben Einstellungen wie in der App
(`#/konto`) — Name, Farbe und Passwort sind dieselbe Sache, egal von welcher
Seite man kommt, und eine zweite Fassung davon wären bald zwei
verschiedene.

**Am Zahnrad steht rot, was die Kinder gemeldet haben.** In jeder Übung
steht ein ⚑ — beim Auswählen oben in der Fragekarte, im Lückentext
neben „Prüfen". Nach der Rückfrage „Diese Vokabel deiner Lehrkraft
melden?" — eine Karte in der Seite, kein Kästchen des Browsers — geht die Meldung in dieselbe Warteschlange wie die Antworten, kommt
also auch ohne Netz an. Gezählt werden Vokabeln, nicht Meldungen: Stolpern
fünf Kinder über dasselbe Wort, ist das eine Sache zu richten. Unter
`teacher/meldungen.php` kommen sie eine nach der anderen, die meistgemeldete
zuerst — mit dem Wortpaar, wenn beim Auswählen gemeldet wurde, und mit dem
Lückensatz samt dem, was die Kinder getippt hatten, wenn es dort war. „Ändern"
speichert und erledigt, „Stimmt so" erledigt nur; danach steht die nächste da.
Der Admin sieht dieselbe Liste über alle Schulen unter **Meldungen**. Die
Regeln stehen in `lib/meldungen.php`.

**Die Überschrift einer Lerneinheit ist der Weg zurück — und das
Umbenennen.** Sie lautet „Englisch - 5B › Unit 4": Der Kurs davor ist ein
Knopf, und er sieht auch nach einem aus; ein unterstrichenes Wort in einer
Überschrift liest man als Überschrift. Eine Ebene höher will man von hier
aus öfter als ganz nach oben, und ganz nach oben führt das Menü links.
Daneben ein Stift: Der Titel wird an Ort und Stelle zum Eingabefeld, mit
Haken zum Sichern und Kreuz zum Verwerfen. Er stand vorher als eigenes
Formular am Fuß der Seite — dieselbe Sache an zwei Stellen, zwei
Bildschirme voneinander entfernt. Ohne JavaScript steht alles nebeneinander
da; das Skript blendet nur um.

Beim Einlesen trägt die Ansicht neben dem Kursnamen den Knopf „‹ Meine
Kurse", und **nach** dem Einlesen landet eine Lehrkraft in der Freigabe der
Lerneinheit, nicht in der Lernansicht: Eingelesen ist noch nicht
aufgemacht, und dort sähe sie eine leere Liste. Für ein Kind bleibt es die
Lerneinheit in der App — es hat gerade seine eigenen Vokabeln eingelesen und
will üben.

### Der Wechsel zwischen den beiden Ansichten

**Ein Schalter mit zwei Stellungen, im Zahnrad** — unter „Passwort ändern"
und über den Farben, in beiden Bereichen an derselben Stelle und von
derselben Bauart wie die Farbwahl darunter. Er zeigt, in welcher Ansicht man
steht, und führt mit einem Griff in die andere.

Er führt dabei auf die **Entsprechung dieser Seite**, nicht auf die
Startseite. Drei Seiten haben eine:

| Verwaltung | Lernansicht |
|---|---|
| Meine Kurse | Meine Kurse |
| Kurs | Kurs |
| Lerneinheit | Lerneinheit |

Üben und Lückentext zählen zu ihrer Lerneinheit — es ist dieselbe, nur in
Betrieb. Alles andere (Klassenlisten, das eigene Konto, der Anlege-Assistent)
gibt es drüben nicht; von dort führt der Wechsel auf die Startseite, nicht
ins Leere.

Vorher waren es zwei halbe Wege: ein Knopf „So sieht es die Klasse" neben
der Überschrift — auf zwei von sieben Seiten und nur in eine Richtung — und
zurück ein Knopf im Hinweis der Lernansicht, den es auch nicht überall
gab. Dazu ein Eintrag „Zur Verwaltung" im *linken* Menü, der immer auf die
Startseite führte. Drei Bedienelemente für eine Bewegung, keines davon
vollständig. Jetzt eines, und links stehen wieder nur die Kurse.

Die Kennung des Kurses steht dabei nicht in der Adresse der App — dort steht
die der **Sprache**. Sie kommt aus der Kursliste, die `app.js` in `core.js`
hereinreicht, und nur eine Lehrkraft bekommt sie überhaupt mitgeliefert.
Umgekehrt sucht die Verwaltung den Kurs in der Kursliste *dieser* Lehrkraft:
Eine Kennung aus der Adresse, die darin nicht vorkommt, führt damit von
selbst auf die Startseite statt auf einen fremden Kurs.

### Der Streifen statt des Hinweiskastens

Die App sagt weiterhin, dass man die Lernansicht vor sich hat — ohne dieses
Wort ist es nur eine Seite, die weniger zeigt als die Verwaltung, und das
sieht nach einem Fehler aus.

Gesagt wird es aber in **einem Wort**, in einem Streifen von rund 26 px ganz
oben, und nicht mehr in einem Hinweiskasten mit vier Zeilen Erklärung über
den Kursen. Der Kasten war richtig, solange er die einzige Auskunft war;
seit im Zahnrad ein Schalter steht, der dasselbe sagt **und** den Weg zurück
kennt, waren es zwei Erklärungen für eine Sache — und die grössere stand
ausgerechnet über dem, weswegen man hergekommen ist.

Der Streifen zieht sich mit negativen Rändern bis an die Kanten: Einer, der
die Einrückung des Inhalts mitmacht, sieht aus wie ein Kasten, der nicht
ganz passt. Oben kommt der Sicherheitsabstand des Geräts als Polster zurück,
damit das Wort auf einem iPhone nicht unter der Uhr liegt.

Er steht auf denselben drei Seiten wie der Schalter — **nicht** im Quiz und
im Lückentext. Die binden sich an die sichtbare Höhe (`.app.fitted` ist
fixiert und genau so hoch wie das Fenster), und ein Streifen darüber schöbe
die Eingabezeile aus dem Bild.

Für Kinder steht dort nichts. Sie brauchen nicht erklärt zu bekommen, dass
sie ihre eigene App sehen — sie kennen gar keine andere.

Über den Kacheln stand für eine Lehrkraft ausserdem eine Zeile
„Verwaltung". Auch die ist weg: Der Schalter kann dasselbe und mehr, und
eine zweite Tür daneben kostete den Platz über genau dem, weswegen man
hergekommen ist — den eigenen Kursen.

**Im selben Fenster**, nicht in einem zweiten. Der alte Knopf stand einmal
auf `target="_blank"`, und damals war das der einzige Weg zurück: Tab zu.
Seit der Weg zurück überall an derselben Stelle steht, ist der zweite Tab
keine Hilfe mehr, sondern eine Ablage — wer zweimal nachsieht, hat drei
Fenster offen und weiß in keinem, wo er ist.

**Und sonst steht dort nichts, was ein Kind nicht auch sieht.** „Vokabeln
einlesen" hing an `canImport`, und das hat eine Lehrkraft — die Probe zeigte
ihr damit eine Seite, die es so gar nicht gibt. Ebenso „Umbenennen" und
„Lerneinheit löschen" in der Lerneinheit. Beides gilt jetzt nur noch für
`canImport && !isTeacher`, also für ein Kind, dem die Lehrkraft das Einlesen
ausdrücklich erlaubt hat und das seine Lerneinheiten damit selbst anlegt.

Umbenennen und Löschen sind dabei nicht verschwunden, sondern umgezogen: Sie
stehen unter „Diese Lerneinheit" im Lehrkraft-Bereich, auf derselben
Lerneinheit — Umbenennen als Zeile, Löschen zugeklappt mit den Zahlen in der
Rückfrage. Das Zurücksetzen des Lernstands bleibt in der App: Der Stand
gehört dem Konto, das ihn erarbeitet hat.

**Und im selben Hinweis steht der Weg zurück.** Er sagte bisher, wo man ist,
aber nicht, wie man wieder herauskommt: Die installierte App hat keine
Adresszeile, und ihr Zurück führt tiefer hinein statt heraus. Der Knopf
zeigt auf genau die Stelle der Verwaltung, an der man war — aus der
Kursansicht auf `course.php?id=…`, aus einer Lerneinheit auf
`unit.php?id=…`, und erst ohne beides auf die Startseite. Die Kennung des
Kurses liefert `api/units.php` dafür mit, **nur an eine Lehrkraft**: Einem
Kind sagt die Zahl nichts, und der Lehrkraft-Bereich lässt es ohnehin nicht
hinein.

Tabellenzeilen öffnen sich per Klick statt über einen „Öffnen"-Knopf in
jeder Zeile. Der Name in der Zeile bleibt ein echter Link — für die
Tastatur, fürs Aufklappen in einem neuen Tab und für alle, die keinen Zeiger
benutzen.

**Am Telefon** wird aus jeder Tabellenzeile eine Karte: Unterhalb von 720 px
steht die Spaltenüberschrift vor dem Wert (aus `data-label` am `<td>`), die
Aktionen liegen unten in der Karte, und nichts scrollt seitwärts. Am Rechner
bleibt es eine Tabelle — dort vergleicht man Spalten.

**Eine Tabelle bleibt eine Tabelle: die Freigabe.** Dort vergleicht man
Vokabeln zeilenweise, und der Balken braucht durchgehende Zeilen — Karten
wären falsch. Die Ausnahme stand von Anfang an da, sie griff nur nicht
ganz: Zwei der Kartenregeln sind zweiklassig (`table.data
td[data-label]::before` und `table.data td:first-child`) und wiegen damit
schwerer als `table.release td`. Vor jedem deutschen Wort stand deshalb noch
einmal „DEUTSCH", und die erste Zelle war ein Block statt einer Zelle. Am
Quelltext sah die Ausnahme richtig aus; gerechnet wurde etwas anderes — das
sieht nur ein Browser, und deshalb steht die Prüfung dafür in der fünften
Suite.

**Die beiden Mengen-Knöpfe hängen an den Enden der Tabelle.** Oben, als
allererste Zeile, „Nichts freigeben"; unten, unter der Anlegezeile, „Alles
freigeben". Beide spannen die volle Breite und haben keine eigene Rundung —
die Rundung der Tabelle beschneidet sie, sie sitzen bündig in den Ecken.

Der Grund ist der Balken dazwischen: Die beiden sind seine Endstellungen.
Als Knopfreihe *über* der Tabelle sagten sie nichts darüber, wohin sie
greifen; an den Enden sieht man die Strecke, auf der sie wirken. Und sie
tragen die Farbe, die sie bewirken — oben das Grau der gesperrten Zeilen,
unten das Grün der freigegebenen.

Dass „Alles freigeben" *unter* der Anlegezeile steht, ist Absicht: Wer
gerade von Hand eine Vokabel angefügt hat, will sie mitfreigeben. Stünde der
Knopf darüber, läge die frisch getippte Zeile ausserhalb dessen, worauf er
zu zeigen scheint — und genau diese Frage soll er nicht aufwerfen.

Technisch hängen beide über `form="releaseform"` am Formular unter der
Tabelle: Ein `<form>` kann in HTML nicht um Tabellenzeilen herumstehen.
Denselben Weg gehen die Knöpfe je Zeile schon, die ohne JavaScript der
Rückfallweg sind.

Dazu wurde die Tabelle auf das eingedampft, was ein Telefon trägt: **drei
Spalten** statt fünf. Die laufende Nummer und die Zahl der Lückensätze sind
weg — wie viel freigegeben ist, sagt die Blase am Balken, und wie viele
Sätze fehlen, der Knopf „Sätze nachtragen". Ändern und Löschen sind nur noch
Stift und Mülleimer; den Namen dazu bekommt das Vorleseprogramm über eine
Spanne, die nur es sieht (`.nurvorlesen`) — `title=` allein hängt am Zeiger,
und am Telefon gibt es keinen. Beide stehen als Emoji da, also mit der
Variantenwahl `&#65039;` dahinter: Ohne sie wählt der Browser die Textform,
und dann steht neben einem farbigen Mülleimer ein blasser Strich, der ein
Stift sein soll. Dass sie **nebeneinander** stehen und nicht untereinander,
war zweimal eine Rechnung und zweimal falsch: Wie breit ein Emoji ist,
entscheidet die Schrift des Geräts — unter Windows anders als auf einem
iPhone —, und mit einer Spalte, die auf den Pixel passt, brach der zweite
Knopf dort trotzdem um. Jetzt wird nicht mehr gerechnet: Die Knöpfe haben
eine feste Breite (40 px), die Zelle `white-space: nowrap`, und der dritte
Knopf ohne JavaScript bekommt mit `display: block` seine eigene Zeile
darüber, statt die beiden auseinanderzudrücken.

**Unter der Tabelle stehen drei gleichwertige Wege**, sie zu füllen —
„Vokabeln zur Lerneinheit hinzufügen": von Hand, aus Dateien (die
Einleseansicht), mit dem Telefon (der QR-Code, der bisher nur im Kurs
stand). Die Überschrift heißt nicht „erweitern", weil das nur stimmt, wenn
schon etwas da ist; eine frisch angelegte Lerneinheit ist leer.

**„Von Hand" öffnet kein Feld am Knopf, sondern eine Zeile am Fuß der
Tabelle** — dort, wo die neue Vokabel gleich stehen wird. Als Link auf
`?vonhand=1`: Ohne JavaScript lädt die Seite neu und die Zeile steht da,
mit JavaScript blendet das Skript sie ein.

Und das Eintragen lädt die Seite **nicht** neu. Wort, Tab, Wort, Enter —
und die nächste Zeile steht da; bei zehn Wörtern waren es vorher zehn
Ladevorgänge und zehnmal die Tabelle von oben. Die frische Zeile kommt vom
Server und nicht aus dem Skript: `vocab_append()` setzt `punctuation_fix()`
darauf an, es steht also nicht zwingend das in der Datenbank, was getippt
wurde. Sie taucht grün auf und verblasst — eine Bestätigung, die man nicht
wegklicken muss — und trägt Stift, Haken und Mülleimer wie jede andere.
Dasselbe Muster wie beim Eintragen einer Klassenliste: `X-Requested-With:
fetch`, und die Antwort ist eine Zeile statt einer Seite.

**Die Antwort trägt ihre Länge**, und das ist hier kein Beiwerk. Nach dem
Abschicken arbeitet der Vorgang weiter (die Lückensätze), die Verbindung
bleibt also offen. Ohne `Content-Length` weiß der Browser nicht, wo die
Antwort aufhört: Er wartet auf das Schließen der Verbindung und meldet am
Ende „keine Verbindung", obwohl die Vokabel längst in der Datenbank steht.
Wer das sieht, drückt noch einmal — und hat sie zweimal.
`teacher_redirect_and_continue()` setzt aus demselben Grund
`Content-Length: 0`.

**Und dasselbe Paar kommt kein zweites Mal hinein.** `vocab_append()`
überspringt, was schon in der Einheit steht — ein Doppelklick, eine
verlorene Antwort, dieselbe Buchseite zweimal fotografiert; die Wege zu
einem Duplikat sind viele, und keiner davon ist eine Absicht. Verglichen
wird das **Paar**, nicht das fremde Wort allein: „bank" heißt Bank und
Ufer, und beide gehören in dieselbe Einheit. Und verglichen wird, was
wirklich gespeichert würde — also nach `punctuation_fix()`, sonst
schlüpft „apple." an „apple" vorbei.

**Im Kopf steht die Sprache, nicht das Wort „Fremdsprache".** `🇬🇧 Englisch`
und `🇩🇪 Deutsch`, beide mit ihrer Fahne als SVG: Die Spalte sagt damit, was
in ihr steht, statt was sie ist — und sie ist kürzer, denn „FREMDSPRACHE"
ist ein Wort ohne Bruchstelle und passte bei 320 px gerade eben.

**Und der Rahmen unter dem Zeiger nur da, wo es einen Zeiger gibt.** Auf
einem Telefon bleibt `:hover` nach einer Berührung hängen und wandert beim
Rollen unter dem Finger von Zeile zu Zeile mit — ein Kästchen um jede Zelle,
das beim Scrollen springt. Beide Zeilen-Hover-Regeln stehen deshalb in
`@media (hover: hover)`. Geprüft am Quelltext und nicht im Browser: Die
Geräteemulation von Chrome meldet weiterhin `(hover: hover)`, der Fall
lässt sich dort gar nicht herstellen. Die Breite wird am Telefon fest verteilt
(`table-layout: fixed`): Wird eine Zeile zum Formular, stehen dort statt
zweier Wörter zwei Eingabefelder, und die Spalten rutschten sonst unter dem
Finger weg. Geprüft wird das bei 390, 375 und 320 px — im Ruhezustand und
mitten im Ändern.

**Der Kopf bleibt beim Rollen stehen.** Das Maß dafür kommt von außen: Die
Leiste oben klebt selbst, und wie hoch sie ist, weiß nur der Browser — am
Telefon bricht sie um und wird doppelt so hoch. `teacher.js` misst sie und
legt das Ergebnis als `--barhoehe` ab; ohne das stünde der Tabellenkopf
hinter der Leiste statt darunter.

Dazu gehört eine zweite Zeile, die nicht danach aussieht: `table.release {
overflow: clip }`. `table.data` trägt `overflow: hidden` für die runden
Ecken, und damit wird **die Tabelle** der Bezug des Klebens statt des
Fensters — der Kopf saß dann um die Leistenhöhe versetzt zwischen der
ersten und der zweiten Zeile und rollte mit weg. `overflow: clip`
schneidet genauso ab, ohne einen Rollbehälter zu erzeugen. Am Telefon fiel
das nie auf: Dort setzt die Kartenregel ohnehin `overflow: visible`.
**Geprüft wird es deshalb an beiden Größen** — die erste Fassung der
Prüfung lief nur bei 390 px und blieb grün, während der Kopf am Rechner
mitten in der Tabelle stand.

### Der QR-Code, der die Anmeldung ersetzt

Eingelesen wird mit der Kamera, also am Telefon; verwaltet wird am Rechner.
Dazwischen lag eine Anmeldung: Adresse abtippen, Benutzername, Passwort —
für einen Vorgang, der danach zwanzig Sekunden dauert. „Am Smartphone
einlesen" zeigt stattdessen einen QR-Code; wer ihn scannt, ist angemeldet
und steht direkt im Einlesen dieses Kurses.

**Das ist ein Passwort in Bildform**, und entsprechend eng ist es gefasst
(`lib/handoff.php`):

- **Einmal.** Eingelöst wird über ein bedingtes `UPDATE`; wer als zweiter
  kommt, bekommt nichts.
- **Kurz.** Zehn Minuten.
- **Nur für sich selbst.** Erzeugen kann eine Marke nur eine angemeldete
  Lehrkraft, nur per POST mit CSRF-Token, und nur auf das eigene Konto.
- **Auf Druck, nicht auf Vorrat.** Der Code entsteht erst beim Klick und
  verschwindet beim Schließen des Fensters — er soll nicht zehn Minuten lang
  auf einem unbeaufsichtigten Bildschirm liegen.
- **Das Ziel kommt aus der Marke**, nicht aus der Adresse, und wird beim
  Einlösen geprüft.

Was er *nicht* ist: eine eingeschränkte Sitzung. Wer die Marke einlöst, ist
angemeldet wie nach Benutzername und Passwort. Das ist Absicht — zum
Einlesen gehört die ganze App — und der Grund für die kurze Frist.

Im noch leeren Kurs steht **kein** QR-Code mehr. Er führte dorthin, wo man
sich erst noch anmelden muss — der Weg, der genau das überspringt, heisst
„Am Smartphone einlesen", und zwei Codes nebeneinander waren einer zu viel.

### Eine Lerneinheit lässt sich erweitern

Jedes Einlesen legte bisher eine **neue** Lerneinheit an — die Route trug
nur eine `language_id`, die Nutzlast kein `unit_id`. Wer eine zweite
Buchseite derselben Lektion fotografierte, bekam „Unit 4" und „Unit 4 (2)"
und musste beide einzeln freigeben.

Im Prüfschritt steht jetzt die Wahl: **neue Lerneinheit** oder **an eine
vorhandene anhängen**. Die Wahl kommt erst dort, nach dem Fotografieren —
vorher weiss man noch nicht, ob die Seite zur letzten Lektion gehört.
Angehängt wird hinten; **`released_position` bleibt unberührt**, die neuen
Wörter sind für die Klasse also zunächst unsichtbar, genau wie eine frisch
eingelesene Einheit. Lückensätze entstehen für sie wie für alle anderen.

### Vokabeln von Hand

In der Freigabetabelle lässt sich jede Vokabel **ändern** und **löschen**,
und unten steht eine Zeile zum **Hinzufügen** — dasselbe Muster wie in jeder
anderen Tabelle. Bisher konnte das nur der Betreiber im Admin; eine
Lehrkraft sah ein falsch erkanntes Wort in ihrer eigenen Lerneinheit und
konnte nichts tun, ausser alles neu einzulesen.

Alles läuft über `lib/vocab.php`: Positionen bleiben lückenlos, die
Freigabemarke wird beim Löschen mitgeführt, `punctuation_fix()` greift wie
beim Einlesen, und zu einer neuen Vokabel entsteht gleich ein Lückensatz.

Eine Vokabel zu löschen nimmt jedem Kind seinen Lernstand dazu — `progress`
hängt per `ON DELETE CASCADE` daran. Die Rückfrage sagt das.

### Zwei Kurse derselben Sprache

Wer Englisch in der 5B und in der 6A gibt, sah in der App zweimal die Kachel
„Englisch" und konnte nicht raten, welche welche ist. Die Kachel trägt
deshalb den Namen des Kurses — **aber nur dann**: Legt ein Kind selbst eine
Sprache an, heisst sein Kurs „Englisch Lilli M.", und der eigene Name auf der
eigenen Kachel ist keine Auskunft, sondern Lärm. Entschieden wird je Konto, denn es geht darum,
was *dieser* Mensch vor sich hat. Die Seite hinter der Kachel trägt
denselben Namen; stünde dort etwas anderes, wäre der Weg dorthin eine
Überraschung.

---

## Kosten

Jede Bilderkennung wird mit Modell, Token, Dauer und berechnetem Preis in
`ai_requests` protokolliert und im Admin nach Tag, Kind und Modell ausgewertet.

Standardmodell ist `claude-opus-5`; eine Buchseite kostet typischerweise ein bis
drei Cent. Zwei Bremsen sind eingebaut und im Admin einstellbar:

- **Monatsbudget** (Standard 10 USD) — ist es erreicht, blockiert die App die
  Analyse, *bevor* eine Anfrage an die API geht.
- **Analysen pro Kind und Stunde** (Standard 20).

Die Preistabelle ist editierbar. Ändert Anthropic die Preise, hier nachziehen —
bereits protokollierte Anfragen behalten ihren damals berechneten Betrag.

---

## Die Startseite

`website/index.html` ist die Seite, die unter https://vokidoki.de/ steht: eine
einzelne, statische HTML-Seite, die die App vorstellt – in zwei Fassungen, „Für
Schülerinnen und Schüler" und „Für Lehrkräfte", zwischen denen ein Umschalter
wechselt (`#schueler`, `#lehrkraefte` in der Adresse). Ohne JavaScript stehen
beide untereinander. „Anmelden" führt nach `app/`, unten stehen Impressum,
Datenschutz und Lizenzen aus `app/rechtliches.php`.

Schriften, Logo und Voki kommen aus `app/assets/`, also vom eigenen Server —
aus demselben Grund wie in der App. Die Startseite braucht deshalb die App
daneben; getrennt hochladen lassen sich beide trotzdem.

Die Bildschirmfotos in `website/bilder/` sind echte Fotos der App, keine
Zeichnungen. Nach einer sichtbaren Änderung an der App neu machen und mit
einchecken:

```bash
node tests/browser/website-bilder.mjs
```

Das Skript braucht den laufenden Entwicklungsserver, legt sich dafür eine
Vorführklasse an (`tests/browser/demo.php`: das Gymnasium am See, Klasse 6b,
Französisch und Englisch, Lina mit einer Serie von zwölf Tagen) und räumt sie
danach wieder weg. Adresse und QR-Code auf Zettel und Dialog werden dabei auf
`https://vokidoki.de/app/` gestellt — wer den Code vom Bildschirm
abfotografiert, soll bei Vokidoki landen, nicht auf einem fremden Rechner.

---

## Aufbau

```
website/             die Startseite von vokidoki.de (index.html, bilder/)
daten-vorlage/       der Datenordner zum ersten Hochladen: config.example.php, storage/
tests/               die Prüfungen, der Router für den Entwicklungsserver, Werkzeuge
app/                 die Anwendung - alles darunter wird bei jedem Update überschrieben:

index.php            App-Shell; rendert Manifest-Link und iOS-Meta pro Kind
manifest.php         dynamisches Manifest (Name, start_url mit Token)
icon.php             PNG-Icon: Voki auf der Farbe des Kontos (GD), gecacht; auch Favicon
app.js / core.js     Router und gemeinsame Bausteine
vorrat.js            alles zum Üben im Gerät; rechnet offline wie der Server
menue.js             die beiden Schubladen, für App und Lehrkraft-Bereich
views/               login, languages, language, unit, import, quiz, cloze,
                     frei (Freies Ueben), profile, bilder
sw.js                Service Worker (nur statische Dateien)
api/                 auth, languages, units, import, quiz, cloze  (JSON)
admin/               Kosten, Accounts, Sprachen und Vokabeln, Einstellungen, Selbsttest
lib/                 db, auth, settings, ai, keyvault, cost, json, config,
                     schema (Spalten nachziehen), wordtypes,
                     progress (Lernregel), streak (die Serie),
                     sentences (Lückensätze)
assets/fonts/        Fredoka und Nunito, selbst ausgeliefert
assets/vokidoki_logo.svg  das Wortzeichen; das V ist Voki, "okidoki" sind Pfade
assets/voki-icon.*   Voki mit weissem Rand fürs App-Symbol (siehe oben)
schema.sql           Datenbankschema
composer.json/.lock  die Abhängigkeiten; composer install läuft hier, auf dem Server
vendor/              dorthin legt composer install sie (nicht versioniert)
```

### Kategorien

Beim Einlesen ordnet das Modell jeden Eintrag ein — maßgeblich ist der
*fremdsprachige* Eintrag, nicht die Übersetzung. Dreizehn Kategorien:

| | |
|---|---|
| Wortarten | Substantiv, Artikel, Adjektiv, Verb, Pronomen, Numerale, Adverb, Präposition, Konjunktion, Interjektion |
| Ganze Äußerungen | **Aussage** („Bonne nuit !", „Merci, Madame !"), **Frage** („Comment tu t'appelles ?") |
| Auffangwert | Sonstiges |

Vokabellisten führen Grußformeln und Fragen oft als einen Eintrag — als Wortart
ließe sich das nicht einordnen, deshalb die beiden Äußerungs-Kategorien.
Entscheidend ist, ob gefragt wird, nicht das Satzzeichen. `sonstiges` bleibt als
Auffangwert, damit das Modell nie raten muss.

Nach außen heißt das Ganze „Kategorie", intern `word_type` — ein Spaltenname
weniger, der wandern muss.

Das Kind bekommt die Kategorie beim Prüfen nicht zu Gesicht — sie reist
unsichtbar durch die Tabelle und wird mitgespeichert. Korrigiert wird sie im
Admin, wo sie als farbiges Kürzel in der Vokabelliste steht.

Für Vokabeln aus der Zeit davor gibt es unter **Vokabeln** einen Knopf, der die
Kategorien nachträgt: in Blöcken zu 100, höchstens 500 je Klick, jeweils
sprachweise. Das kostet wie eine Bilderkennung und zählt aufs Monatsbudget.

Die Datenbankspalte wird beim ersten Aufruf des Admin-Bereichs automatisch
ergänzt (`lib/schema.php`) — bei einem FTP-Update gibt es sonst keinen Schritt,
der SQL ausführt. Die Änderung fügt nur hinzu und lässt vorhandene Daten
unangetastet.

### Übungsarten

Zwei Wege, dieselbe Lernregel. `progress.mode` trennt die Serien, der
eindeutige Schlüssel `(vocab_id, mode)` sorgt dafür von selbst — wer beim
Auswählen weit ist, fängt im Lückentext trotzdem bei null an.

**Auswählen** (`mc`): Zufällige Vokabel, zufällige Richtung, vier Antworten.

**Lückentext** (`cloze`): Das Kind sieht den deutschen Satz und denselben Satz
in der Fremdsprache mit einer Lücke und tippt das Fehlende ein.

```
        Ich heiße Hannes.
   ______________ Hannes.        →  Je m'appelle
```

Die erwartete Antwort ist **nicht** die gespeicherte Vokabel: Aus `s'appeler`
wird im Satz `Je m'appelle`. Deshalb liefert das Modell sie mit; ableiten
lässt sie sich nicht.

Die Sätze entstehen **im Hintergrund, gleich nach dem Einlesen**, in Blöcken zu
20 Vokabeln. Das Kind sieht seine Lerneinheit sofort; die Zeile „Lückentext"
zeigt so lange einen Spinner und ist nicht anklickbar und schaltet sich ohne
Neuladen frei, sobald die Sätze stehen (`views/unit.js` fragt dafür
`units.php?action=sentence_status` ab).

Auf geteiltem Hosting gibt es keine Warteschlange und keinen Dienst, den man
dafür anwerfen könnte. `json_out_and_continue()` in `lib/json.php` schickt
deshalb die Antwort ab, gibt die Sitzung frei und arbeitet im selben Vorgang
weiter — mit `ignore_user_abort()`, damit ein geschlossener Browser den Auftrag
nicht killt.

Der Zustand steht an der Lerneinheit (`sentences_status`: `running`, `done`,
`failed`). Ein Lauf, der länger als 15 Minuten „running" ist, gilt als
abgestürzt — sonst bliebe die Übung für immer gesperrt. Gibt es dann trotzdem
Sätze, zählt das als fertig. Einzeln abgefragt wäre dasselbe rund siebenmal so teuer —
Anweisung und Wortschatz (~1.400 Token) sind bei jedem Satz identisch und
würden jedes Mal mitbezahlt. Bei 60 Vokabeln und drei Sätzen: rund 8 ct mit
Sonnet 5, 21 ct mit Opus 5. Lerneinheiten, die nie so geübt werden, kosten
nichts.

Warum Blöcke und nicht ein Aufruf für alles: Bei einer Einheit mit 79 Vokabeln
lief der eine Aufruf 95 Sekunden. So lange liegt die Datenbankverbindung
ungenutzt herum — länger als der `wait_timeout` vieler Hoster, und das
Speichern scheiterte danach mit „MySQL server has gone away", *nachdem* die
Anfrage bezahlt war. Blöcke sind kürzer unterwegs, und ein misslungener Block
kostet nicht die ganze Einheit. Zusätzlich setzt `lib/db.php` einen großzügigen
`wait_timeout` und prüft nach jedem KI-Aufruf mit `db_ensure()`, ob die
Verbindung noch steht. Das Kostenprotokoll wird **vor** dem Speichern
geschrieben — ein bezahlter Aufruf soll auch dann auftauchen, wenn das
Speichern danach schiefgeht.

Als bekannt gelten die Vokabeln der Einheit, bis zu 300 Wörter aus früheren
Einheiten derselben Sprache und Grundwörter wie Artikel, Zahlwörter und die
Formen von „sein" und „haben".

**Auch ganze Äußerungen bekommen einen Lückentext.** Bei einer Frage oder
Grußformel wird kein Satz darum herum gebaut — die Lücke deckt einen
kennzeichnenden Teil der Äußerung selbst ab:

```
Wie heißt du?                    Wie heißt du?              Gute Nacht!
______________ comment ?         Tu ______ comment ?        ______________
→ Tu t'appelles                  → t'appelles               → Bonne nuit !
```

Gerade hier ist der Lückentext die wertvollste Übung, weil das Kind die Wendung
produzieren muss statt sie wiederzuerkennen. Bei mehreren Sätzen wandert die
Lücke, damit die Wendung nach und nach ganz sitzt.

Jeder erzeugte Satz wird geprüft, bevor er gespeichert wird: genau eine Lücke
`{}` im fremdsprachigen Satz, keine im deutschen, nicht leere Lösung, und die
Lösung darf nicht daneben im Satz stehen. Was durchfällt, wird verworfen.

**Bewertet** wird nachsichtig: Groß-/Kleinschreibung und Leerzeichen zählen
nicht, fehlende Akzente und Apostrophe gelten als richtig — die App zeigt dann
die korrekte Schreibweise („Fast! So schreibt man es: Je m'appelle"). Auf einer
Handytastatur ist ein `è` mühsam, und geübt wird die Vokabel, nicht das Tippen.
Die Zeichenzuordnung dafür steht ausdrücklich in `lib/sentences.php` und
benutzt **nicht** `iconv('ASCII//TRANSLIT')` — dessen Ergebnis hängt von der
Locale des Servers ab.

**Zur Tastatur:** Das Eingabefeld trägt `lang` aus `languages.code` und schaltet
Autokorrektur, Autovervollständigung und Großschreibung ab. iOS lässt sich das
Tastaturlayout allerdings **nicht vorschreiben** — dafür gibt es keine
Web-Schnittstelle. Das Kind tippt einmal auf die Weltkugel, iOS merkt es sich.
Verhindert wird der größere Ärger: dass die deutsche Autokorrektur
`Je m'appelle` in etwas Deutsches „verbessert".

### Die Übersicht einer Lerneinheit

Jede Vokabel zeigt ihren Stand in **beiden** Übungsarten nebeneinander — Haken,
drei Punkte oder ein Strich:

```
                                      🎯 Auswählen · ✏️ Lückentext
the pencil          der Bleistift        🎯 ✓      ✏️ ●○○
the teacher         die Lehrerin         🎯 ●●○    ✏️ ✓
Bonne nuit !        Gute Nacht!          🎯 ○○○    ✏️ –
```

Der Strich heißt „noch kein Lückensatz" — etwa weil das Modell für diese
Vokabel keinen brauchbaren erzeugen konnte. Ohne diese Unterscheidung sähe sie
ewig unerledigt aus. Entsprechend können die beiden Übungen unterschiedlich
viele Vokabeln zählen.

### Lernlogik

Der Trainer zieht eine zufällige noch nicht gekonnte Vokabel der Lerneinheit,
wählt zufällig die Richtung und stellt drei Ablenker aus derselben Einheit daneben.
Die richtige Antwort verlässt den Server nicht — sie liegt unter einem Nonce in der
Session, sonst wäre sie im Netzwerk-Tab ablesbar.

Dreimal **hintereinander** richtig ⇒ die Vokabel gilt als gekonnt und wird
übersprungen. Eine falsche Antwort setzt die Serie zurück. Sind alle Vokabeln
gekonnt, ist die Lerneinheit bestanden.

Der Lernstand liegt in `progress` mit einer Spalte `mode` (aktuell `'mc'`), damit
weitere Trainer-Varianten später eigene Serien bekommen, ohne die bestehenden
zu überschreiben.

---

## Entwicklung

```bash
php -S 127.0.0.1:8123 -t . tests/router.php
```

Der Router stellt den Webroot von vokidoki.de nach: `/` ist die Startseite
aus `website/`, `/app/` die Anwendung, `/daten/` ist gesperrt. So läuft auch
lokal alles unter `/app` - ein Pfad, der das Unterverzeichnis vergisst, fällt
hier auf und nicht erst nach dem Upload.

Die lokale Konfiguration liegt wie auf dem Server in `daten/config.php`
(nicht versioniert; Vorlage in `daten-vorlage/`), mit `'base_path' => '/app'`.
Dort `'dev' => true` setzen, um PHP-Fehler im Browser zu sehen.
Auf `localhost` wird der Service Worker registriert, ohne HTTPS zu verlangen.

### Tests

```bash
php tests/e2e.php http://127.0.0.1:8123/app DEIN-ADMIN-PASSWORT
```

1200 Prüfungen über die gesamte Kette: Admin-Login und -Seiten, Schulen,
Account-Anlage, Vokabelkorrektur, Kind-Login, Geräte-Token, Manifest und Icon,
Zugriffstrennung zwischen den Accounts, die komplette Quiz-Logik samt „dreimal
hintereinander", Zurücksetzen und Token-Widerruf — dazu der Lehrkraft-Bereich
mit Klassen, Kursen, Massenanlage, Anfangspasswörtern, Anmeldebremse, QR-Code
und Druckblatt, und die gestufte Freigabe.

Der Test lässt sich auch gegen die fertige Installation fahren — er ist dafür
gebaut, nichts zu hinterlassen, und der Abschnitt zur Bilderkennung überspringt
sich dabei selbst:

```bash
php tests/e2e.php https://vokidoki.de/app DEIN-ADMIN-PASSWORT
```

Das ist zugleich die einfachste Art, den Code einmal unter der PHP-Version des
Servers laufen zu lassen — Meldungen wie „Deprecated" fallen dabei sofort auf.

Der Test braucht Zugriff auf dieselbe Datenbank wie die App (er schlägt die
richtige Antwort nach, weil die API sie bewusst nicht herausgibt), legt nur
eigene Testkonten an und räumt sie wieder weg; geänderte Einstellungen setzt er
auf ihren vorherigen Wert zurück. Ohne Admin-Passwort als zweites Argument wird
der Admin-Teil übersprungen.

Dazu zwei Suiten gegen Simulatoren statt gegen die echten Dienste:

```bash
php -S 127.0.0.1:8124 tests/fake-keyvault.php &
php -S 127.0.0.1:8125 tests/fake-anthropic.php &

php tests/sentences.php  # 138 Prüfungen, braucht nichts davon
php tests/keyvault.php   # 15 Prüfungen
php tests/ai.php         # 59 Prüfungen
```

`tests/keyvault.php` prüft Abruf und Format, Zeilenumbrüche, leere Antwort, HTML
statt Key, unbekannten Eintrag, HTTP 401/403/500, das Entfernen von Schlüssel-
material aus Logtexten und dass wirklich nirgends zwischengespeichert wird.

`tests/sentences.php` prüft den Antwortvergleich und die Satzprüfung des
Lückentexts rein rechnerisch — ohne Datenbank, ohne API.

`tests/ai.php` geht den kompletten Bilderkennungs-Pfad durch und prüft am
aufgezeichneten Request, **was das Modell tatsächlich zu sehen bekäme**: dass der
Key aus dem Keyvault im `x-api-key`-Header landet, dass die Bildblöcke mit
`media_type` und Base64 korrekt aufgebaut sind, dass `output_config` das
JSON-Schema und die Aufwandsstufe trägt — und danach, dass Antwort, Token und
Kosten richtig ausgewertet und protokolliert werden, Fehlversuche eingeschlossen.

Vorausgesetzt wird in der lokalen `daten/config.php`:

```php
'keyvault_url'       => 'http://127.0.0.1:8124/',
'keyvault_token'     => 'test-token',
'anthropic_base_url' => 'http://127.0.0.1:8125',
```

Läuft der Anthropic-Simulator, prüft auch `tests/e2e.php` die Bilderkennung über
die HTTP-Schnittstelle mit. Zeigt `anthropic_base_url` nicht auf localhost, wird
dieser Abschnitt übersprungen — kein Test kann versehentlich die echte,
kostenpflichtige API treffen.

Zusammen 1412 Prüfungen, und **keine** ruft die echte Anthropic-API auf.
Trotzdem gilt: Die Erkennungsqualität selbst zeigt sich erst an einem echten
Foto einer echten Buchseite — das einmal von Hand ausprobieren.

### Die fünfte Suite: ein echter Browser

Die vier Suiten oben sehen HTML. Sie können nicht sehen, ob sich ein Balken
ziehen lässt, ob ein Bild wirklich ankommt oder ob ein Knopf nach dem
Eintippen freigegeben wird — und genau dort lagen drei Fehler, die im
Quelltext völlig richtig aussahen:

- Der Freigabebalken verlor beim Verschieben seine **Zeigerbindung** und
  liess sich um genau eine Vokabel bewegen.
- Die Fahnen standen als `<img>` im Quelltext; ob die Datei dahinter
  existiert, verrät der Quelltext nicht.
- Der Zettel für die ganze Klasse wurde erst beim nächsten Laden anklickbar.

```bash
node tests/browser/lauf.mjs --fixture            # 375 Prüfungen
node tests/browser/lauf.mjs --fixture bilder/    # dazu Bildschirmfotos
```

Gesteuert wird Chrome über das DevTools-Protokoll, ohne Fremdpaket: Node
bringt seit Fassung 22 einen WebSocket mit. Gebraucht werden also **Node und
ein installiertes Chrome** — und deshalb ist das hier ausdrücklich die
fünfte Suite und **nicht** eine der vier: Wer nur die vier fährt, braucht
nichts davon. `tests/browser/fixture.php` legt eine eigene Schule
„BROWSERTEST-Schule" an und räumt sie am Ende wieder weg, auch wenn eine
Prüfung umfällt.

Zwei Fallen, die je eine Stunde gekostet haben und deshalb hier stehen:

- In Git Bash macht die Pfadumsetzung aus einem Argument `/konto` klaglos
  `C:/Program Files/Git/konto`. Wer Hash-Pfade übergibt, setzt
  `MSYS_NO_PATHCONV=1` davor.
- Eine Navigation, die sich **nur im Hash** unterscheidet, lädt das Dokument
  nicht neu. Nach einer frischen Anmeldung kennt die laufende Seite
  `VT.user` noch als `null` — erst neu laden, dann den Hash setzen.

Und eine Lehre über Prüfungen selbst: Die erste Fassung der Balkenprüfung zog
in Schritten von fünf Pixeln und blieb grün, als die Zeigerbindung
testweise entfernt wurde — bei kleinen Schritten bleibt der Balken unter dem
Zeiger, also treffen die Ereignisse ihn auch ohne Bindung. Erst ein
**zügiger** Zug, bei dem der Zeiger den Balken überholt, fällt um. Eine
Prüfung, die bei entferntem Riegel grün bleibt, prüft den Riegel nicht.

Dieselbe Lehre noch einmal, bei den kompakten Tabellen: Die Prüfung mass
`tbody tr` — und traf damit die **Kopfzeile** mit, denn diese Tabellen tragen
kein `<thead>`; der Kopf steht als gewöhnliche `<tr>` in dem `<tbody>`, das
der Browser selbst ergänzt. Verglichen wurde also der Kopf mit sich selbst,
und das stimmt immer. Drei Proben blieben deshalb grün, bevor auffiel, dass
gar nicht das gemessen wurde, worum es ging. Seit die Prüfung ausdrücklich
`tr:not(:has(> th)):not(.newrow)` nimmt, fällt sie um, sobald aus den Zeilen
wieder Karten werden.

Und eine Ehrlichkeit dazu: `table-layout: fixed` bei diesen Tabellen liess
sich **nicht** festnageln. Auf `auto` umgestellt blieben alle Prüfungen grün,
auch mit einem fünfzig Zeichen langen Wort in der Namensspalte — die
ausdrücklichen Spaltenbreiten und die Umbruchregeln tragen das offenbar
allein. Geprüft ist darum das Ergebnis (eine Zeile, bündige Spalten, nichts
läuft über), nicht der Weg dorthin. Das steht so auch im Stylesheet.
