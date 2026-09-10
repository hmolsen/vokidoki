# Vokabeltrainer

Eine PWA, mit der Kinder Vokabelseiten aus dem Schulbuch abfotografieren, von
Claude auslesen lassen und anschließend mit einem Flashcard-Trainer üben.
Jedes Kind hat einen eigenen Account und ein eigenes Symbol auf dem iOS-Home-Bildschirm
("Lillis Vokabeln"), das dauerhaft beim richtigen Kind angemeldet bleibt.

---

## Einrichtung

> Das Anthropic-SDK spricht über PSR-18 und braucht dafür eine HTTP-Client-
> Implementierung. Sie steht als `guzzlehttp/guzzle` ausdrücklich in der
> `composer.json` — sonst installiert `composer install` zwar fehlerfrei, aber
> die erste Foto-Analyse scheitert mit „No PSR-18 clients found".

### 1. Dateien auf den Server

```bash
VT_SSH=benutzer@server.de VT_PATH=/var/www/vokabeln ./deploy.sh
```

Das Skript überträgt alles außer `config.php`, `vendor/` und den generierten
Icons und führt anschließend `composer install` auf dem Server aus.

**Alternativ per FTP.** Den Projektordner hochladen, aber **ohne** diese Einträge:

| nicht hochladen | Grund |
|---|---|
| `.git/` | groß und unnötig; enthält die gesamte Historie |
| `vendor/` | entsteht auf dem Server per `composer install` (tausende Dateien, über FTP zäh) |
| `composer.phar` | wird auf dem Server nicht gebraucht |
| `config.php` | Zugangsdaten gehören nur auf den Server |
| `storage/icons/*.png` | werden bei Bedarf neu erzeugt |

Danach einmal per SSH:

```bash
cd /pfad/zum/ordner
composer install --no-dev --optimize-autoloader
```

`vendor/` lässt sich zwar auch lokal erzeugen und mitschicken, dauert über FTP
aber deutlich länger als der eine Befehl.

### 2. Konfiguration

```bash
cp config.example.php config.php
chmod 600 config.php
```

In `config.php` eintragen: MySQL-Zugang, das **Keyvault-Token** und ein
Start-Passwort für den Admin-Bereich. Liegt die App in einem Unterverzeichnis,
zusätzlich `base_path` setzen (z. B. `'/vokabeln'`).

Der Anthropic-Key steht **nicht** in dieser Datei — siehe nächster Abschnitt.

Erlaubt der Hoster ein Verzeichnis oberhalb des Webroots, kann die Datei auch
dort als `vokabeltrainer-config.php` liegen — sie wird automatisch gefunden.

### 3. Datenbank

```bash
mysql -u BENUTZER -p DATENBANK < schema.sql
```

### 4. Prüfen und Accounts anlegen

`https://deine-domain/admin/` aufrufen, mit dem Start-Passwort anmelden
(es wird beim ersten Login als Hash gespeichert, danach ist der Wert in
`config.php` wirkungslos). Dann:

1. **Selbsttest** öffnen — dort muss alles grün sein.
2. Unter **Accounts** für jede Tochter einen Account anlegen.
3. Unter **Einstellungen** ggf. Modell und Monatsbudget anpassen.

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

## Das Home-Bildschirm-Symbol pro Kind

iOS gibt jeder installierten Web-App einen **eigenen Cookie-Container**, getrennt
von Safari. Ein in Safari erzeugter Login wandert deshalb nicht automatisch in die
installierte App. Gelöst wird das über einen Geräte-Token in der `start_url` des
Manifests:

1. In **Safari** anmelden. Die App leitet auf `/?t=<Token>` um; `index.php` liefert
   dabei einen persönlichen `<link rel="manifest">` mit Namen, Farbe und Symbol
   des Kindes aus.
2. **Teilen → Zum Home-Bildschirm.** iOS liest das Manifest und schlägt
   „Lillis Vokabeln" vor.
3. Beim Start aus dem Symbol öffnet iOS die `start_url` mit dem Token, tauscht ihn
   gegen eine Session in *diesem* Container und entfernt ihn aus der URL.
4. Für die zweite Tochter: in Safari abmelden, als zweites Kind anmelden, erneut
   zum Home-Bildschirm hinzufügen. Unterschiedliche `start_url` ⇒ iOS legt eine
   zweite, unabhängige App an.

Im Admin lässt sich unter **Accounts** jedes angemeldete Gerät einzeln widerrufen;
das Symbol landet dann beim nächsten Start wieder im Login.

**Wichtig:** Ohne HTTPS gibt es weder Service Worker noch Kamerazugriff in iOS.

---

## Absicherung

Die mitgelieferte `.htaccess` sperrt `config.php`, `schema.sql`, `lib/`, `storage/`,
`vendor/`, `tests/` und ein versehentlich mit hochgeladenes `.git/`.
Die Testskripte weisen Web-Aufrufe zusätzlich selbst mit 404 ab (`PHP_SAPI`-Prüfung),
falls `.htaccess` nicht greift. Der **Selbsttest misst das aktiv nach**, indem er diese Pfade über die
eigene Adresse aufruft — meldet er dort ein Problem, wertet dein Server keine
`.htaccess` aus (nginx, oder `AllowOverride None`).

In dem Fall entweder `config.php` als `vokabeltrainer-config.php` eine Ebene
**oberhalb** des Webroots ablegen (wird automatisch gefunden), oder für nginx:

```nginx
location ~ ^/(lib|storage|vendor)/           { deny all; }
location ~ ^/(config\.php|schema\.sql|composer\.(json|lock)|deploy\.sh)$ { deny all; }
```

Sitzungsdateien liegen in `storage/sessions` statt im Standardpfad des Servers —
der existiert bei geteiltem Hosting nicht immer, und ohne ihn scheitert die
Anmeldung. Unerwartete Fehler landen mit einer Kennung in `storage/error.log`;
nach außen gibt es eine verständliche Meldung statt einer leeren 500er-Seite.

Weitere eingebaute Schutzmaßnahmen: der Anthropic-Key nur im Keyvault,
Passwörter und Geräte-Token nur als Hash,
`HttpOnly`/`Secure`/`SameSite=Lax`-Cookies, CSRF-Schutz über einen eigenen Header
(API) bzw. Token (Admin-Formulare), Eigentümerprüfung bei jedem Datenzugriff und
die richtige Quizantwort ausschließlich serverseitig.

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

## Aufbau

```
index.php            App-Shell; rendert Manifest-Link und iOS-Meta pro Kind
manifest.php         dynamisches Manifest (Name, start_url mit Token)
icon.php             PNG-Icon aus Farbe + Initiale (GD), gecacht
app.js / core.js     Router und gemeinsame Bausteine
views/               login, languages, language, units, unit, import, quiz
sw.js                Service Worker (nur statische Dateien)
api/                 auth, languages, units, import, quiz  (JSON)
admin/               Kosten, Accounts, Sprachen und Vokabeln, Einstellungen, Selbsttest
lib/                 db, auth, settings, ai, keyvault, cost, json, config,
                     schema (Spalten nachziehen), wordtypes
schema.sql           Datenbankschema
```

### Wortarten

Beim Einlesen bestimmt das Modell zu jeder Vokabel die Wortart des
*fremdsprachigen* Begriffs — Substantiv, Artikel, Adjektiv, Verb, Pronomen,
Numerale, Adverb, Präposition, Konjunktion, Interjektion. Dazu kommt
`sonstiges` als Auffangwert: Vokabellisten enthalten regelmäßig Wendungen und
ganze Sätze, die in keine der zehn Wortarten passen.

Das Kind bekommt die Wortart beim Prüfen nicht zu Gesicht — sie reist
unsichtbar durch die Tabelle und wird mitgespeichert. Korrigiert wird sie im
Admin, wo sie als farbiges Kürzel in der Vokabelliste steht.

Für Vokabeln aus der Zeit davor gibt es unter **Vokabeln** einen Knopf, der die
Wortarten nachträgt: in Blöcken zu 100, höchstens 500 je Klick, jeweils
sprachweise. Das kostet wie eine Bilderkennung und zählt aufs Monatsbudget.

Die Datenbankspalte wird beim ersten Aufruf des Admin-Bereichs automatisch
ergänzt (`lib/schema.php`) — bei einem FTP-Update gibt es sonst keinen Schritt,
der SQL ausführt. Die Änderung fügt nur hinzu und lässt vorhandene Daten
unangetastet.

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
php -S localhost:8000        # Projektwurzel ist zugleich Webroot
```

In `config.php` `'dev' => true` setzen, um PHP-Fehler im Browser zu sehen.
Auf `localhost` wird der Service Worker registriert, ohne HTTPS zu verlangen.

### Tests

```bash
php tests/e2e.php http://localhost:8000 DEIN-ADMIN-PASSWORT
```

74 Prüfungen über die gesamte Kette: Admin-Login und -Seiten, Account-Anlage,
Vokabelkorrektur, Kind-Login, Geräte-Token, Manifest und Icon, Zugriffstrennung
zwischen den Accounts, die komplette Quiz-Logik samt „dreimal hintereinander",
Zurücksetzen und Token-Widerruf.

Der Test lässt sich auch gegen die fertige Installation fahren — er ist dafür
gebaut, nichts zu hinterlassen, und der Abschnitt zur Bilderkennung überspringt
sich dabei selbst:

```bash
php tests/e2e.php https://deine-domain/vokabeltrainer DEIN-ADMIN-PASSWORT
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

php tests/keyvault.php   # 15 Prüfungen
php tests/ai.php         # 31 Prüfungen
```

`tests/keyvault.php` prüft Abruf und Format, Zeilenumbrüche, leere Antwort, HTML
statt Key, unbekannten Eintrag, HTTP 401/403/500, das Entfernen von Schlüssel-
material aus Logtexten und dass wirklich nirgends zwischengespeichert wird.

`tests/ai.php` geht den kompletten Bilderkennungs-Pfad durch und prüft am
aufgezeichneten Request, **was das Modell tatsächlich zu sehen bekäme**: dass der
Key aus dem Keyvault im `x-api-key`-Header landet, dass die Bildblöcke mit
`media_type` und Base64 korrekt aufgebaut sind, dass `output_config` das
JSON-Schema und die Aufwandsstufe trägt — und danach, dass Antwort, Token und
Kosten richtig ausgewertet und protokolliert werden, Fehlversuche eingeschlossen.

Vorausgesetzt wird in der lokalen `config.php`:

```php
'keyvault_url'       => 'http://127.0.0.1:8124/',
'keyvault_token'     => 'test-token',
'anthropic_base_url' => 'http://127.0.0.1:8125',
```

Läuft der Anthropic-Simulator, prüft auch `tests/e2e.php` die Bilderkennung über
die HTTP-Schnittstelle mit. Zeigt `anthropic_base_url` nicht auf localhost, wird
dieser Abschnitt übersprungen — kein Test kann versehentlich die echte,
kostenpflichtige API treffen.

Zusammen 120 Prüfungen, und **keine** ruft die echte Anthropic-API auf.
Trotzdem gilt: Die Erkennungsqualität selbst zeigt sich erst an einem echten
Foto einer echten Buchseite — das einmal von Hand ausprobieren.
