# Vokidoki (Verzeichnis: vokabeltrainer)

## Entwicklungsumgebung

**Auf dieser Maschine gibt es kein Docker.** Nicht danach suchen, Docker Desktop
nicht starten - es ist zwar installiert, wird aber nicht benutzt.

Die Datenbank ist ein lokal installiertes **MariaDB**:

```
"C:/Program Files/MariaDB 12.3/bin/mariadbd.exe" --port=3399 --console
```

Der Port muss 3399 sein - so steht es in `daten/config.php`. Die mitgelieferte
`data/my.ini` von MariaDB sagt 3306, deshalb der ausdrückliche `--port`.
MariaDB läuft hier nicht als Dienst, der Daemon wird von Hand gestartet.

Die Datenbank heisst `vokabeltrainer`. Fehlt sie, einmal anlegen und
`app/schema.sql` einspielen:

```
CREATE DATABASE vokabeltrainer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

## Die Prüfungen

Vier PHP-Suiten und eine Browser-Suite. Die PHP-Suiten brauchen die Datenbank
und einen laufenden Server, die Browser-Suite zusätzlich Chrome (ist da, unter
`C:/Program Files/Google/Chrome/Application/chrome.exe`).

```
php -S 127.0.0.1:8123 -t . tests/router.php   # der Server, den die Suiten anfragen
php tests/e2e.php http://127.0.0.1:8123/app
php tests/sentences.php
php tests/ai.php
php tests/keyvault.php
node tests/browser/lauf.mjs --fixture
```

`tests/fake-keyvault.php` und `tests/fake-anthropic.php` stehen für die
KI-Aufrufe bereit; `daten/config.php` zeigt lokal bereits auf sie (Port 8124/8125).

## Aufbau

- `app/` ist die Anwendung und wird bei jedem Update als Ganzes
  überschrieben. Darin darf nichts liegen, was ein Update überleben muss.
- `daten/` (lokal, nicht versioniert; Vorlage `daten-vorlage/`) hält
  `config.php` und `storage/`. Die App findet den Ordner über `daten_dir()`
  in `app/lib/config.php`; Laufzeitdateien gehen über `storage_path()`.
- `website/` ist die Startseite von vokidoki.de, `index.html` im Webroot.
- `tests/router.php` stellt diesen Webroot für den eingebauten Server nach:
  die App unter `/app`, wie auf dem Server.

## Committen

Jeden abgeschlossenen Schritt committen, ohne dass danach gefragt wird:
sobald eine Aufgabe fertig ist und die Suiten grün sind. Nicht mehrere
Aufgaben unkommittet aufeinanderstapeln - sonst lassen sie sich hinterher
nicht mehr getrennt nachvollziehen oder zurücknehmen. Commit-Nachrichten auf
Deutsch, ohne Umlaute, im Stil der bisherigen (`git log`). Gepusht wird nur
auf ausdrücklichen Wunsch.

## Neue Dateien: ausdrücklich zum Hochladen nennen

Hochgeladen wird von Hand per FTP. Entsteht unter `app/` eine **neue Datei**
(oder wird eine umbenannt), am Ende der Antwort ausdrücklich und als Liste
sagen: "Diese neuen Dateien müssen mit hochgeladen werden: …". Nicht nur im
Commit erwähnen. Eine einzige fehlende Datei genügt, und die App bleibt
weiss - so geschehen mit `installieren.js` und `aktualisieren.js`.

## Wie hier geschrieben wird

- Bezeichner und Kommentare auf Deutsch, Code-Bezeichner ohne Umlaute
  (`schluessel`, nicht `schlüssel`), Kommentartext mit.
- Kommentare erklären **warum**, nicht was - oft mit dem Fehler, der dazu
  geführt hat. Diesen Ton beim Ändern beibehalten.
- Eine Regel steht an genau einer Stelle. Wo sie zwangsläufig zweimal steht
  (Server *und* Gerät, weil ohne Netz geübt wird), hält eine gemeinsame
  Fallsammlung unter `tests/faelle/` beide Fassungen zusammen.
- Schemaänderungen gehen an zwei Stellen: nach `app/schema.sql` für frische
  Installationen und als Eintrag in `schema_migrations()` (`app/lib/schema.php`)
  für die laufende Datenbank auf vokidoki.de - die lässt sich nicht mehr neu
  einspielen. Ausgeführt wird im Selbsttest auf Knopfdruck. Jede Änderung
  fügt nur hinzu.
- Der Satz, den Kinder bei der ersten Anmeldung bestätigen - die Lehrkraft
  sieht nicht, ob und wie sie üben -, muss stimmen (`app/lib/einwilligung.php`).
  Nichts im Lehrkraft-Bereich darf zeigen, welches Kind die App benutzt.
- Neue Dateien der Oberfläche landen automatisch in `app_assets()`
  (`app/lib/version.php`) - sie globbt `*.js`, `views/*.js`, `teacher/*.js` und
  `admin/*.css`. Ohne das merkt eine auf dem Homescreen installierte App - auch
  die Verwaltung - von einer Änderung nichts.
