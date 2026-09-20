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
   „Lillis Vokabeln" vor.
3. Beim Start aus dem Symbol öffnet iOS die `start_url` mit dem Token, tauscht ihn
   gegen eine Session in *diesem* Container und entfernt ihn aus der URL.
4. Für die zweite Tochter: in Safari abmelden, als zweites Kind anmelden, erneut
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
2. **Für welche Sprache?** Die fünf Schulsprachen als Kacheln — so war es
   in der Familien-App —, alle übrigen im durchsuchbaren Feld darunter.
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
Klasse bleibt ihr offen, aber als eigener Griff: „So sieht es die Klasse“.
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
Lerneinheit, nicht in der Schüleransicht: Eingelesen ist noch nicht
aufgemacht, und dort sähe sie eine leere Liste. Für ein Kind bleibt es die
Lerneinheit in der App — es hat gerade seine eigenen Vokabeln eingelesen und
will üben.

Von Kurs und Lerneinheit führt ein Knopf **in der Zeile der Überschrift** in
die Schüleransicht: „So sieht es die Klasse". Die App sagt dort, dass man
gerade die Schüleransicht vor sich hat — ohne diesen Satz ist es nur eine
Seite, die weniger zeigt als die Verwaltung, und das sieht nach einem Fehler
aus. Für Kinder steht dort nichts.

**Im selben Fenster**, nicht in einem zweiten. Der Knopf stand einmal auf
`target="_blank"`, und damals war das der einzige Weg zurück: Tab zu. Seit
die Schüleransicht selbst einen Knopf trägt, der auf genau die Seite zeigt,
von der man kam, ist der zweite Tab keine Hilfe mehr, sondern eine Ablage —
wer zweimal nachsieht, hat drei Fenster offen und weiß in keinem, wo er
ist.

**Und sonst steht dort nichts, was ein Kind nicht auch sieht.** „Vokabeln
einlesen" hing an `canImport`, und das hat eine Lehrkraft — die Probe zeigte
ihr damit eine Seite, die es so gar nicht gibt. Ebenso „Umbenennen" und
„Lerneinheit löschen" in der Lerneinheit. Beides gilt jetzt nur noch für
`canImport && !isTeacher`, also für ein Kind in einer Familie, das seine
Lerneinheiten selbst anlegt.

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
deshalb den Namen des Kurses — **aber nur dann**: In einer Familie heisst
der Kurs „Englisch Lilli M.", und der eigene Name auf der eigenen Kachel ist
keine Auskunft, sondern Lärm. Entschieden wird je Konto, denn es geht darum,
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

## Aufbau

```
index.php            App-Shell; rendert Manifest-Link und iOS-Meta pro Kind
manifest.php         dynamisches Manifest (Name, start_url mit Token)
icon.php             PNG-Icon aus Farbe + Initiale (GD), gecacht
app.js / core.js     Router und gemeinsame Bausteine
views/               login, languages, language, units, unit, import, quiz, cloze
sw.js                Service Worker (nur statische Dateien)
api/                 auth, languages, units, import, quiz, cloze  (JSON)
admin/               Kosten, Accounts, Sprachen und Vokabeln, Einstellungen, Selbsttest
lib/                 db, auth, settings, ai, keyvault, cost, json, config,
                     schema (Spalten nachziehen), wordtypes,
                     progress (Lernregel), sentences (Lückensätze)
schema.sql           Datenbankschema
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
php -S localhost:8000        # Projektwurzel ist zugleich Webroot
```

In `config.php` `'dev' => true` setzen, um PHP-Fehler im Browser zu sehen.
Auf `localhost` wird der Service Worker registriert, ohne HTTPS zu verlangen.

### Tests

```bash
php tests/e2e.php http://localhost:8000 DEIN-ADMIN-PASSWORT
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
node tests/browser/lauf.mjs --fixture            # 330 Prüfungen
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
