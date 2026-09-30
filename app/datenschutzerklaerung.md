# Datenschutzerklärung

## 1. Datenschutz auf einen Blick

### Allgemeine Hinweise
Die folgenden Hinweise geben einen einfachen Überblick darüber, was mit Ihren personenbezogenen Daten passiert, wenn Sie diese Progressive Web App (PWA) bzw. Website nutzen. Personenbezogene Daten sind alle Daten, mit denen Sie persönlich identifiziert werden können.

### Datenerfassung in dieser App
**Wer ist verantwortlich für die Datenerfassung?**
Die Datenverarbeitung in dieser App erfolgt durch den Betreiber:

Hannes Molsen  
Wieselgang 7  
23683 Scharbeutz  
E-Mail: support@vokidoki.de

---

## 2. Allgemeine Hinweise und Pflichtinformationen

### Datenschutz
Der Schutz Ihrer Daten ist uns sehr wichtig. Wir behandeln Ihre personenbezogenen Daten vertraulich und entsprechend den gesetzlichen Datenschutzvorschriften (DSGVO) sowie dieser Datenschutzerklärung.

### Hinweis zur verantwortlichen Stelle und schulischen Nutzung
Diese App wird Schulen und Lehrkräften als Unterrichtswerkzeug bereitgestellt. Soweit die Nutzung im Rahmen des Schulunterrichts erfolgt, ist die jeweilige Schule die datenschutzrechtlich verantwortliche Stelle im Sinne der DSGVO. Mit den nutzenden Schulen werden entsprechende Verträge zur Auftragsverarbeitung (AVV) geschlossen.

### Speicherdauer
Soweit innerhalb dieser Datenschutzerklärung keine spezielle Speicherdauer genannt wurde, verbleiben Ihre personenbezogenen Daten bei uns, bis der Zweck für die Datenverarbeitung entfällt oder das Nutzerkonto durch die Lehrkraft bzw. den Administrator gelöscht wird.

### Ihre Rechte (Betroffenenrechte)
Sie haben jederzeit das Recht:
* Auskunft über Ihre bei uns gespeicherten personenbezogenen Daten zu erhalten (Art. 15 DSGVO).
* Die Berichtigung unrichtiger Daten zu verlangen (Art. 16 DSGVO).
* Die Löschung Ihrer Daten zu verlangen (Art. 17 DSGVO).
* Die Einschränkung der Datenverarbeitung zu verlangen (Art. 18 DSGVO).
* Die Datenübertragbarkeit zu verlangen (Art. 20 DSGVO).
* Sich bei einer Datenschutz-Aufsichtsbehörde zu beschweren.

---

## 3. Datenerfassung und Datenverarbeitung in der App

### A. Registrierung und Nutzerkonten

**Lehrkräfte:**
* **Erfasste Daten:** Vor- und Nachname, E-Mail-Adresse, Passwort (verschlüsselt/gehasht).
* **Zweck:** Authentifizierung, Bereitstellung der App-Funktionen für Verwaltung und Erstellung von Vokabelübungen, Supportabwicklung.
* **Rechtsgrundlage:** Art. 6 Abs. 1 lit. b DSGVO (Vertragserfüllung bzw. Nutzungsservice) sowie Art. 6 Abs. 1 lit. f DSGVO (berechtigtes Interesse an der sicheren Bereitstellung).

**Schülerinnen und Schüler:**
* **Erfasste Daten:** Vorname und erster Buchstabe des Nachnamens (pseudonymisiert), Passwort (gehasht), Lernfortschritte und Übungsstatistiken.
* **Besonderheit:** Es werden keine vollständigen Namen, E-Mail-Adressen oder sonstigen direkten Identifikatoren von Schülerinnen und Schülern erfasst. Lehrkräfte haben keinen Einblick in individuelle Anmeldezeiten oder das genaue Nutzungsverhalten einzelner Schüler. Das Zurücksetzen von Schülerpasswörtern erfolgt direkt durch die zuständige Lehrkraft.
* **Rechtsgrundlage:** Art. 6 Abs. 1 lit. e DSGVO (Wahrnehmung einer Aufgabe im öffentlichen Interesse / schulischer Bildungsauftrag) in Verbindung mit den Schulgesetzen der Länder bzw. Art. 6 Abs. 1 lit. a DSGVO (Einwilligung).

### B. Hosting und Server-Log-Files
Diese App wird bei dem externen Dienstleister **ALL-INKL.COM - Neue Medien Münnich** (Inhaber: René Münnich, Hauptstraße 68, 02742 Friedersdorf) gehostet.

Beim Aufruf der Web-App erfasst der Webserver automatisch technische Informationen in sogenannten Server-Log-Files:
* IP-Adresse des zugreifenden Geräts
* Datum und Uhrzeit des Zugriffs
* Verwendeter Browser und Betriebssystem

Die Erfassung dieser Daten dient ausschließlich der Gewährleistung eines störungsfreien Betriebs der Website und der IT-Sicherheit. Eine Zusammenführung dieser Daten mit anderen Datenquellen wird nicht vorgenommen.
* **Rechtsgrundlage:** Art. 6 Abs. 1 lit. f DSGVO (Berechtigtes Interesse an der Stabilität und Sicherheit des Dienstes).
* **Auftragsverarbeitung:** Mit ALL-INKL.COM wurde ein Vertrag zur Auftragsverarbeitung (AVV) gemäß Art. 28 DSGVO abgeschlossen. Serverstandort ist ausschließlich Deutschland.

### C. Vokabeln aus Fotos einlesen (Texterkennung auf dem Gerät, Anthropic API)
Lehrkräfte können Fotos von Vokabellisten aufnehmen oder auswählen, um daraus automatisiert Vokabeln erstellen zu lassen.

* **Texterkennung auf dem Gerät:** Die Fotos werden ausschließlich auf dem Gerät der Lehrkraft, im Browser, gelesen (Texterkennung mit Tesseract, siehe „Lizenzen“). Die Fotos werden **weder an unseren Server noch an Dritte übertragen** und nicht gespeichert; sie liegen nur so lange im Browser, bis die Seite verlassen wird. Die dafür nötigen Programmdateien und Sprachdaten werden von unserem Server geladen und im Browser zwischengespeichert, nicht von einem fremden Anbieter.
* **Ordnen durch KI:** Nur der erkannte **Text** wird über unseren Server an die Programmierschnittstelle (API) des Anbieters **Anthropic PBC** (USA) übermittelt. Dort wird er zu Vokabelpaaren geordnet; offensichtliche Lesefehler der Texterkennung werden berichtigt und für die Lehrkraft sichtbar markiert.
* **Anonymität & Datenschutz:**
  * Es werden **keine** personenbezogenen Daten (wie Namen, IP-Adressen oder Nutzer-IDs) an Anthropic übermittelt. Die Anfrage stellt unser Server, nicht das Gerät der Lehrkraft.
  * Übermittelt wird nur der Text der fotografierten Vokabelliste. Bitte fotografieren Sie ausschließlich Vokabellisten – keine Schülerarbeiten, Namenslisten oder andere Dokumente mit personenbezogenen Daten.
  * **Speicherdauer bei Anthropic:** Nach den Angaben von Anthropic werden Eingaben und Ausgaben der API in der Regel innerhalb von **30 Tagen** automatisch gelöscht. Ausnahmen: Werden Inhalte von den automatischen Sicherheitssystemen des Anbieters als möglicher Verstoß gegen dessen Nutzungsrichtlinien markiert, können sie bis zu 2 Jahre aufbewahrt werden; außerdem, soweit eine gesetzliche Pflicht besteht (siehe [Anthropic Privacy Center](https://privacy.claude.com/en/articles/7996866-how-long-do-you-store-my-organization-s-data)). Eine Vereinbarung ohne Datenspeicherung (Zero Data Retention) ist beantragt, aber noch nicht bestätigt.
  * Über die API übermittelte Daten werden nach den kommerziellen Bedingungen von Anthropic nicht zum Training von KI-Modellen verwendet.
  * Unser Server speichert den übermittelten Text nicht; gespeichert werden nur die erkannten Vokabelpaare in der Lerneinheit und, für die Kostenabrechnung, die Zahl der gelesenen Seiten.

### D. KI-Hilfe-Bot für Lehrkräfte
Innerhalb der App steht Lehrkräften ein automatisierter Support-Bot zur Seite, der Fragen zur Bedienung der App beantwortet.

* **Datenverarbeitung:** Fragen, die in das Chatfenster eingegeben werden, werden zur Erzeugung einer Antwort verarbeitet.
* **Wichtiger Hinweis:** Der Bot dient rein der Bereitstellung von Anleitungen. Er hat keinen Zugriff auf Datenbanken, Personen- oder Kontodaten und kann keine Systemaktionen (wie Passwörter zurücksetzen) ausführen. Bitte geben Sie im Chat mit dem Hilfe-Bot keine personenbezogenen Daten ein.

---

## 4. Tracking, Analytics und Local Storage

### Keine Tracking- oder Analysetools
Diese App nutzt **keine** Tracking-Tools (wie Google Analytics, Matomo etc.), keine Werbe-Cookies und keine Dienste zur Analyse des Nutzerverhaltens.

### Lokale Speicherung (Local Storage / PWA)
Als Progressive Web App (PWA) nutzt die Anwendung den lokalen Speicher Ihres Browsers (Local Storage / Cache), um die App als Anwendung auf dem Startbildschirm nutzbar zu machen und temporäre Sitzungsinformationen (z. B. den aktuellen Login-Status) zu speichern. Dies ist technisch zwingend erforderlich, um die Grundfunktionen der App bereitzustellen.