# Vokidoki (Verzeichnis: vokabeltrainer)

## Entwicklungsumgebung

**Auf dieser Maschine gibt es kein Docker.** Nicht danach suchen, Docker Desktop
nicht starten - es ist zwar installiert, wird aber nicht benutzt.

Die Datenbank ist ein lokal installiertes **MariaDB**:

```
"C:/Program Files/MariaDB 12.3/bin/mariadbd.exe" --port=3399 --console
```

Der Port muss 3399 sein - so steht es in `config.php`. Die mitgelieferte
`data/my.ini` von MariaDB sagt 3306, deshalb der ausdrückliche `--port`.
MariaDB läuft hier nicht als Dienst, der Daemon wird von Hand gestartet.

Die Datenbank heisst `vokabeltrainer`. Fehlt sie, einmal anlegen und
`schema.sql` einspielen:

```
CREATE DATABASE vokabeltrainer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

## Die Prüfungen

Vier PHP-Suiten und eine Browser-Suite. Die PHP-Suiten brauchen die Datenbank
und einen laufenden Server, die Browser-Suite zusätzlich Chrome (ist da, unter
`C:/Program Files/Google/Chrome/Application/chrome.exe`).

```
php -S 127.0.0.1:8123 -t .            # der Server, den die Suiten anfragen
php tests/e2e.php http://127.0.0.1:8123
php tests/sentences.php
php tests/ai.php
php tests/keyvault.php
node tests/browser/lauf.mjs --fixture
```

`tests/fake-keyvault.php` und `tests/fake-anthropic.php` stehen für die
KI-Aufrufe bereit; `config.php` zeigt lokal bereits auf sie (Port 8124/8125).

## Wie hier geschrieben wird

- Bezeichner und Kommentare auf Deutsch, Code-Bezeichner ohne Umlaute
  (`schluessel`, nicht `schlüssel`), Kommentartext mit.
- Kommentare erklären **warum**, nicht was - oft mit dem Fehler, der dazu
  geführt hat. Diesen Ton beim Ändern beibehalten.
- Eine Regel steht an genau einer Stelle. Wo sie zwangsläufig zweimal steht
  (Server *und* Gerät, weil ohne Netz geübt wird), hält eine gemeinsame
  Fallsammlung unter `tests/faelle/` beide Fassungen zusammen.
- Schemaänderungen gehen nur nach `schema.sql`. Migrationen gibt es nicht
  mehr: `schema_migrations()` ist leer, seit feststeht, dass dies eine
  Neuinstallation ist. Wer sie wieder braucht, baut sie bewusst zurück -
  nicht nebenbei.
- Neue Dateien der Oberfläche landen automatisch in `app_assets()`
  (`lib/version.php`) - sie globbt `*.js` und `views/*.js`. Ohne das merkt eine
  auf dem Homescreen installierte App von einer Änderung nichts.
