<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/languages.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/passwords.php';

/**
 * Nachträgliche Schemaänderungen.
 *
 * Die App wird per FTP aktualisiert - es gibt keinen Schritt, der von sich aus
 * SQL ausführt. Jede Änderung ist so formuliert, dass sie nur hinzufügt und
 * vorhandene Daten unangetastet lässt.
 *
 * Ausgeführt wird ausschliesslich auf Knopfdruck im Selbsttest des
 * Admin-Bereichs. Früher lief das beim Aufruf jeder Admin-Seite von selbst -
 * bequem, aber blind: Ein Fehlschlag stand nur im Protokoll, und niemand
 * wusste, ob und wann eine Änderung gelaufen war. Eine Schemaänderung ist eine
 * Entscheidung, kein Seiteneffekt.
 */

/**
 * Wurde diese Änderung schon einmal ausgeführt?
 *
 * Die meisten Änderungen prüfen das an der Struktur selbst - eine Spalte ist
 * da oder nicht. Ein Nachtrag an vorhandenen Daten kann das nicht: Nach ihm
 * sieht die Datenbank wieder so aus, als stünde er noch aus, sobald jemand
 * einen der nachgetragenen Werte von Hand leert. Dann liefe er bei jedem
 * Aufruf erneut und machte die Handarbeit wieder zunichte. Solche Änderungen
 * fragen deshalb hier nach.
 */
function schema_was_applied(string $name): bool
{
    return setting('schema_applied_' . $name, '') !== '';
}

/**
 * Liegt hier noch Bestand aus der Zeit vor den Kursen?
 *
 * Die family-Änderungen sind ein Rettungsweg, kein Bestandteil des Modells:
 * Sie überführen die Daten der alten Familien-App in eine Schule "Familie".
 * Eine Neuinstallation hat davon nichts - dort legt der Betreiber seine
 * Schule unter Admin > Schulen an, und eine still erzeugte "Familie" wäre
 * nur ein Posten, den jemand wieder wegräumen muss.
 *
 * Das Kennzeichen ist eine Sprache ohne Kurs. Im neuen Modell entsteht der
 * Kurs zusammen mit der Sprache; eine Sprache ohne ihn kann also nur aus der
 * Zeit davor stammen. Die Frage "gibt es überhaupt Konten" taugt dafür
 * nicht: Auf einer frischen Installation legt der Betreiber sein erstes
 * Konto womöglich an, bevor er die Änderungen ausführt.
 */
function schema_has_legacy_data(): bool
{
    if (!table_exists('languages') || !table_exists('courses')) {
        return false;
    }

    return (int) qv(
        'SELECT COUNT(*) FROM languages l
          WHERE NOT EXISTS (SELECT 1 FROM courses co WHERE co.language_id = l.id)'
    ) > 0;
}

/**
 * Liste der Änderungen: Name => [Prüfung, SQL].
 * Die Prüfung liefert true, wenn die Änderung noch fehlt.
 */
function schema_migrations(): array
{
    return [
        'vocab.word_type' => [
            static fn (): bool => !column_exists('vocab', 'word_type'),
            'ALTER TABLE vocab ADD COLUMN word_type VARCHAR(16) NULL AFTER note',
        ],
        // Sprachkürzel (fr, en, la, da) für den Tastaturhinweis im Lückentext.
        'languages.code' => [
            static fn (): bool => !column_exists('languages', 'code'),
            'ALTER TABLE languages ADD COLUMN code VARCHAR(8) NULL AFTER flag_emoji',
        ],
        // Die Spalte oben entsteht leer. Gefüllt wird sie sonst nur beim
        // Anlegen einer Sprache - wer seine Sprachen vorher angelegt hatte,
        // stand ohne Kürzel da, und im Lückentext fehlten dann der
        // lang-Hinweis und die Reihe der Sonderzeichen.
        'languages.code.backfill' => [
            static fn (): bool => column_exists('languages', 'code')
                && !schema_was_applied('languages.code.backfill')
                && language_code_backfill_pending(),
            language_code_backfill_sql(),
        ],
        // Zustand der Satzerzeugung je Lerneinheit. Sie laeuft seit neuestem
        // im Hintergrund, also braucht die Oberflaeche etwas zum Abfragen.
        'units.sentences_status' => [
            static fn (): bool => !column_exists('units', 'sentences_status'),
            "ALTER TABLE units
               ADD COLUMN sentences_status VARCHAR(16) NULL,
               ADD COLUMN sentences_started_at DATETIME NULL,
               ADD COLUMN sentences_error VARCHAR(255) NULL",
        ],
        /*
         * Gemeldete Lückensätze.
         *
         * Eine Zeile je Kind und Satz statt eines Zählers in sentences: So
         * kann ein Kind denselben Satz nicht mehrfach melden, und im Admin
         * steht, wer gemeldet hat und was getippt wurde. Gerade das Getippte
         * entscheidet oft, ob der Satz oder die Antwort daneben lag.
         */
        'sentence_flags' => [
            static fn (): bool => !table_exists('sentence_flags'),
            'CREATE TABLE sentence_flags (
               id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               sentence_id INT UNSIGNED NOT NULL,
               user_id     INT UNSIGNED NOT NULL,
               typed       VARCHAR(128) NULL,
               created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               UNIQUE KEY uq_flag (sentence_id, user_id),
               KEY idx_flag_sentence (sentence_id),
               CONSTRAINT fk_flag_sentence FOREIGN KEY (sentence_id)
                   REFERENCES sentences(id) ON DELETE CASCADE,
               CONSTRAINT fk_flag_user FOREIGN KEY (user_id)
                   REFERENCES users(id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        /*
         * Der Lernstand gehoert dem Kind - Teil 1 von 2.
         *
         * Vor dem Indexumbau die Daten geradeziehen: progress.user_id trug im
         * UPDATE-Zweig des alten Upserts nicht mit, konnte also von der
         * Unit abweichen. Danach ist (user_id, vocab_id, mode) garantiert
         * eindeutig, weil (vocab_id, mode) es schon ist - der spaetere
         * ADD UNIQUE kann damit nicht an einer Dublette scheitern.
         *
         * Ueber schema_was_applied() geschuetzt: Eine Datenreparatur laesst
         * sich der Struktur nicht ansehen.
         */
        'progress.user_id.repair' => [
            // Auch diese Reparatur ist Geschichte: Sie liest units.user_id,
            // und die Spalte gibt es nur noch auf Datenbanken, die den Umbau
            // vor sich haben. Ohne die Bedingung scheiterte auf einer frischen
            // Installation gleich die erste Aenderung - und weil beim ersten
            // Fehler abgebrochen wird, liefe danach gar nichts mehr.
            static fn (): bool => table_exists('progress')
                && column_exists('units', 'user_id')
                && !schema_was_applied('progress.user_id.repair'),
            'UPDATE progress p
               JOIN vocab v ON v.id = p.vocab_id
               JOIN units t ON t.id = v.unit_id
                SET p.user_id = t.user_id
              WHERE p.user_id <> t.user_id',
        ],

        /*
         * Teil 2: den richtigen Schluessel danebenlegen. Rein additiv.
         *
         * idx_progress_vocab ist nicht optional. Der alte uq_progress deckt
         * zugleich den Fremdschluessel fk_progress_vocab ab; faellt er ohne
         * Ersatz, verweigert MySQL das DROP oder legt still selbst einen Index
         * an. Beides zusammen in EINEM ALTER, damit es keinen Halbzustand gibt.
         *
         * Der alte Schluessel bleibt vorerst stehen. Sein Entfernen ist ein
         * eigener Schritt in einem eigenen Deployment.
         */
        'progress.uq.add' => [
            static fn (): bool => table_exists('progress')
                && !index_exists('progress', 'uq_progress_user'),
            'ALTER TABLE progress
               ADD UNIQUE KEY uq_progress_user (user_id, vocab_id, mode),
               ADD KEY idx_progress_vocab (vocab_id, mode)',
        ],

        /*
         * Teil 3: den alten Schluessel abwerfen.
         *
         * Erst jetzt koennen zwei Kinder ueberhaupt eine eigene Zeile je
         * Vokabel haben - vorher verbot (vocab_id, mode) die zweite.
         *
         * Die Bedingung verlangt ausdruecklich, dass BEIDE Ersatzindizes schon
         * stehen. ensure_schema() verschluckt Fehler und protokolliert sie nur;
         * waere der Schritt davor still misslungen, stuende die Tabelle sonst
         * am Ende voellig ohne eindeutigen Schluessel da, und Dubletten
         * sammelten sich unbemerkt an. So passiert in dem Fall schlicht nichts.
         */
        'progress.uq.drop' => [
            static fn (): bool => index_exists('progress', 'uq_progress')
                && index_exists('progress', 'uq_progress_user')
                && index_exists('progress', 'idx_progress_vocab'),
            'ALTER TABLE progress DROP INDEX uq_progress',
        ],

        'sentences' => [
            static fn (): bool => !table_exists('sentences'),
            'CREATE TABLE sentences (
               id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               vocab_id     INT UNSIGNED NOT NULL,
               native_text  VARCHAR(255) NOT NULL,
               foreign_text VARCHAR(255) NOT NULL,
               answer       VARCHAR(128) NOT NULL,
               created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               KEY idx_sentences_vocab (vocab_id),
               CONSTRAINT fk_sentences_vocab FOREIGN KEY (vocab_id)
                   REFERENCES vocab(id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],

        /*
         * Aufraeumen: idx_progress_user (user_id) ist seit uq_progress_user
         * (user_id, vocab_id, mode) ueberfluessig - ein Index auf einem
         * Praefix des anderen. Er kostet bei jedem Schreibvorgang Arbeit und
         * bringt nichts. Bewusst getrennt vom eigentlichen Umbau: Das hier
         * darf schiefgehen, ohne dass es jemanden stoert.
         */
        'progress.idx.cleanup' => [
            static fn (): bool => index_exists('progress', 'idx_progress_user')
                && index_exists('progress', 'uq_progress_user'),
            'ALTER TABLE progress DROP INDEX idx_progress_user',
        ],

        /* ------------------------------------------------------------------
         * Schule, Klasse, Kurs.
         *
         * Alles hier ist rein additiv: Die Tabellen entstehen, die Spalten
         * kommen dazu, die Familiendaten wandern hinein - aber keine Zeile
         * Code liest sie. Das ist Absicht. Ein Fehler ist in diesem Schritt am
         * billigsten, weil noch nichts davon abhaengt. Erst der naechste
         * Schritt schaltet lib/access.php darauf um, und dass die Pruefungen
         * dabei gruen bleiben, ist dann der Beweis, dass die Ueberfuehrung
         * vollstaendig war.
         * --------------------------------------------------------------- */

        'schools' => [
            static fn (): bool => !table_exists('schools'),
            'CREATE TABLE schools (
               id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               name       VARCHAR(128) NOT NULL,
               active     TINYINT(1) NOT NULL DEFAULT 1,
               monthly_cost_cap_usd DECIMAL(10,2) NULL,
               created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               UNIQUE KEY uq_school_name (name)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],

        'classes' => [
            static fn (): bool => !table_exists('classes'),
            'CREATE TABLE classes (
               id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               school_id  INT UNSIGNED NOT NULL,
               name       VARCHAR(64) NOT NULL,
               active     TINYINT(1) NOT NULL DEFAULT 1,
               created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               UNIQUE KEY uq_class_name (school_id, name),
               CONSTRAINT fk_class_school FOREIGN KEY (school_id)
                   REFERENCES schools(id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],

        'class_members' => [
            static fn (): bool => !table_exists('class_members'),
            'CREATE TABLE class_members (
               id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               class_id   INT UNSIGNED NOT NULL,
               user_id    INT UNSIGNED NOT NULL,
               created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               UNIQUE KEY uq_class_member (class_id, user_id),
               KEY idx_class_member_user (user_id),
               CONSTRAINT fk_cm_class FOREIGN KEY (class_id)
                   REFERENCES classes(id) ON DELETE CASCADE,
               CONSTRAINT fk_cm_user FOREIGN KEY (user_id)
                   REFERENCES users(id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],

        /*
         * Kurs = Klasse + Sprache, etwa "Englisch 5B".
         *
         * Die Lerneinheiten haengen am Kurs, nicht an der Schule - so kann
         * eine Lehrkraft mit denselben Unterlagen unabhaengig arbeiten. Das
         * kostet: Zwei Lehrkraefte mit derselben Buchseite lesen zweimal ein
         * und erzeugen zweimal Saetze. Bewusst so entschieden.
         */
        'courses' => [
            static fn (): bool => !table_exists('courses'),
            'CREATE TABLE courses (
               id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               school_id   INT UNSIGNED NOT NULL,
               class_id    INT UNSIGNED NULL,
               language_id INT UNSIGNED NOT NULL,
               name        VARCHAR(128) NOT NULL,
               created_by  INT UNSIGNED NULL,
               active      TINYINT(1) NOT NULL DEFAULT 1,
               created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               KEY idx_course_school (school_id),
               KEY idx_course_class (class_id),
               KEY idx_course_language (language_id),
               CONSTRAINT fk_course_school FOREIGN KEY (school_id)
                   REFERENCES schools(id) ON DELETE CASCADE,
               CONSTRAINT fk_course_class FOREIGN KEY (class_id)
                   REFERENCES classes(id) ON DELETE SET NULL,
               CONSTRAINT fk_course_language FOREIGN KEY (language_id)
                   REFERENCES languages(id) ON DELETE CASCADE,
               CONSTRAINT fk_course_creator FOREIGN KEY (created_by)
                   REFERENCES users(id) ON DELETE SET NULL
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],

        /*
         * Mitgliedschaft ausdruecklich je Kurs, nicht aus der Klasse
         * abgeleitet: Ab Jahrgang 6 teilt sich eine Klasse regelmaessig in
         * Franzoesisch und Latein. Lehrkraft und Kind stehen in derselben
         * Tabelle, damit Vertretung und Mehrfachmitgliedschaft von selbst
         * anfallen.
         */
        'course_members' => [
            static fn (): bool => !table_exists('course_members'),
            "CREATE TABLE course_members (
               id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               course_id   INT UNSIGNED NOT NULL,
               user_id     INT UNSIGNED NOT NULL,
               member_role VARCHAR(16) NOT NULL DEFAULT 'student',
               created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               UNIQUE KEY uq_course_member (course_id, user_id),
               KEY idx_course_member_user (user_id, member_role),
               CONSTRAINT fk_cmem_course FOREIGN KEY (course_id)
                   REFERENCES courses(id) ON DELETE CASCADE,
               CONSTRAINT fk_cmem_user FOREIGN KEY (user_id)
                   REFERENCES users(id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],

        'users.school' => [
            static fn (): bool => !column_exists('users', 'school_id'),
            "ALTER TABLE users
               ADD COLUMN school_id INT UNSIGNED NULL AFTER id,
               ADD COLUMN role VARCHAR(16) NOT NULL DEFAULT 'student' AFTER display_name,
               ADD COLUMN can_import TINYINT(1) NOT NULL DEFAULT 0 AFTER role,
               ADD KEY idx_users_school (school_id)",
        ],

        'languages.school' => [
            static fn (): bool => !column_exists('languages', 'school_id'),
            'ALTER TABLE languages
               ADD COLUMN school_id INT UNSIGNED NULL AFTER user_id,
               ADD KEY idx_lang_school (school_id)',
        ],

        'units.course' => [
            static fn (): bool => !column_exists('units', 'course_id'),
            'ALTER TABLE units
               ADD COLUMN course_id INT UNSIGNED NULL AFTER language_id,
               ADD COLUMN released_position INT UNSIGNED NOT NULL DEFAULT 0 AFTER title,
               ADD KEY idx_units_course (course_id)',
        ],

        'ai_requests.school' => [
            static fn (): bool => !column_exists('ai_requests', 'school_id'),
            'ALTER TABLE ai_requests
               ADD COLUMN school_id INT UNSIGNED NULL AFTER user_id,
               ADD KEY idx_ai_school (school_id)',
        ],

        /* ---- Ueberfuehrung des vorhandenen Bestandes -------------------- */

        'family.school' => [
            static fn (): bool => table_exists('schools')
                && schema_has_legacy_data()
                && !schema_was_applied('family.school'),
            "INSERT INTO schools (name)
             SELECT 'Familie' FROM DUAL
              WHERE NOT EXISTS (SELECT 1 FROM schools WHERE name = 'Familie')",
        ],

        'family.class' => [
            static fn (): bool => table_exists('classes')
                && schema_was_applied('family.school')
                && !schema_was_applied('family.class'),
            "INSERT INTO classes (school_id, name)
             SELECT s.id, 'Familie' FROM schools s
              WHERE s.name = 'Familie'
                AND NOT EXISTS (SELECT 1 FROM classes c
                                 WHERE c.school_id = s.id AND c.name = 'Familie')",
        ],

        /*
         * Der Bestand behaelt seine heutigen Rechte: can_import = 1. Neue
         * Schuelerkonten bekommen die Voreinstellung 0 - Einlesen kostet Geld
         * und wird einzeln vergeben.
         */
        'family.users' => [
            static fn (): bool => column_exists('users', 'school_id')
                && schema_was_applied('family.school')
                && !schema_was_applied('family.users'),
            "UPDATE users
                SET school_id  = (SELECT id FROM schools WHERE name = 'Familie'),
                    can_import = 1
              WHERE school_id IS NULL",
        ],

        'family.class_members' => [
            static fn (): bool => table_exists('class_members')
                && schema_was_applied('family.school')
                && !schema_was_applied('family.class_members'),
            "INSERT IGNORE INTO class_members (class_id, user_id)
             SELECT c.id, u.id
               FROM classes c
               JOIN schools s ON s.id = c.school_id
               JOIN users u   ON u.school_id = s.id
              WHERE s.name = 'Familie' AND c.name = 'Familie'",
        ],

        'family.languages' => [
            static fn (): bool => column_exists('languages', 'school_id')
                && column_exists('languages', 'user_id')
                && schema_was_applied('family.school')
                && !schema_was_applied('family.languages'),
            'UPDATE languages l
               JOIN users u ON u.id = l.user_id
                SET l.school_id = u.school_id
              WHERE l.school_id IS NULL',
        ],

        /*
         * Je vorhandener Sprache ein Kurs. Da eine Sprache heute genau einem
         * Kind gehoert, ist die Zuordnung eindeutig - "Franzoesisch Lilli".
         * Die doppelten Sprachnamen bleiben bestehen; sie zusammenzufuehren
         * waere der gefaehrlichste Teil einer Migration und bringt nichts
         * ausser Ordnung.
         */
        'family.courses' => [
            static fn (): bool => table_exists('courses')
                && column_exists('languages', 'user_id')
                && schema_was_applied('family.school')
                && !schema_was_applied('family.courses'),
            "INSERT INTO courses (school_id, class_id, language_id, name, created_by)
             SELECT l.school_id,
                    (SELECT c.id FROM classes c
                      WHERE c.school_id = l.school_id AND c.name = 'Familie' LIMIT 1),
                    l.id,
                    CONCAT(l.name, ' ', u.display_name),
                    l.user_id
               FROM languages l
               JOIN users u ON u.id = l.user_id
              WHERE l.school_id IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM courses co WHERE co.language_id = l.id)",
        ],

        'family.course_members' => [
            static fn (): bool => table_exists('course_members')
                && column_exists('languages', 'user_id')
                && schema_was_applied('family.school')
                && !schema_was_applied('family.course_members'),
            "INSERT IGNORE INTO course_members (course_id, user_id, member_role)
             SELECT co.id, l.user_id, 'student'
               FROM courses co
               JOIN languages l ON l.id = co.language_id",
        ],

        'family.units' => [
            static fn (): bool => column_exists('units', 'course_id')
                && schema_was_applied('family.school')
                && !schema_was_applied('family.units'),
            'UPDATE units t
               JOIN courses co ON co.language_id = t.language_id
                SET t.course_id = co.id
              WHERE t.course_id IS NULL',
        ],

        /*
         * Altbestand gilt als vollstaendig aufgegeben - sonst saehe ein Kind
         * seine bisherigen Vokabeln ploetzlich nicht mehr.
         */
        'family.released' => [
            static fn (): bool => column_exists('units', 'released_position')
                && schema_was_applied('family.school')
                && !schema_was_applied('family.released'),
            'UPDATE units SET released_position = 4294967295 WHERE released_position = 0',
        ],

        // ------------------------------------------------ Konten fuer Klassen

        /*
         * Die Bausteine der Initialpasswoerter. Sie stehen in der Datenbank
         * und nicht im Quelltext, weil eine Lehrkraft ein Wort streichen
         * koennen soll, das in ihrer Klasse zum Spitznamen wird - ohne dass
         * dafuer jemand die Anwendung neu hochlaedt.
         */
        /*
         * Die vorhandenen Kostenzeilen der Schule zuordnen, zu der ihr Konto
         * gehoert. Ohne das steht die Familie in der Auswertung bei null,
         * obwohl die Summe stimmt - und der Unterschied faellt genau dann auf,
         * wenn man ihm nicht mehr nachgehen kann.
         */
        'family.ai_requests' => [
            static fn (): bool => column_exists('ai_requests', 'school_id')
                && schema_was_applied('family.school')
                && !schema_was_applied('family.ai_requests'),
            'UPDATE ai_requests a
               JOIN users u ON u.id = a.user_id
                SET a.school_id = u.school_id
              WHERE a.school_id IS NULL AND u.school_id IS NOT NULL',
        ],

        /* ---- Der Besitzer verschwindet, Schritt 1 von 2 ----------------- */

        /*
         * units.user_id und languages.user_id sagen "das gehoert genau einem
         * Kind". Das stimmt seit der Umstellung auf Kurse nicht mehr, und
         * eine Spalte, die etwas Falsches behauptet, wird frueher oder
         * spaeter wieder benutzt. Sie muessen weg.
         *
         * Weg in zwei Deployments, aus demselben Grund wie beim Umbau von
         * uq_progress: Der Code geht per FTP sofort live, die Migration
         * laeuft erst beim naechsten Knopfdruck. Wuerde die Spalte hier
         * fallen, waehrend der Code sie noch schreibt, brechen alle
         * Einfuegungen. Und umgekehrt: Wuerde der Code sie nicht mehr
         * schreiben, bevor sie NULL erlaubt, brechen sie genauso.
         *
         * Also erst locker machen, dann in einem spaeteren Deployment
         * fallen lassen. Dazwischen ist beides gleichzeitig richtig.
         *
         * Der Fremdschluessel geht mit, und das ist die eigentliche
         * Aenderung im Verhalten: fk_lang_user loeschte mit einem Konto
         * dessen Sprachen samt Lerneinheiten, Vokabeln und Saetzen. In einer
         * Familie war das gewollt. In einer Klasse waere es eine
         * Katastrophe - ein Kind wechselt die Schule, und die Unterlagen von
         * 27 anderen sind weg.
         */
        'units.user_id.loosen' => [
            static fn (): bool => column_exists('units', 'user_id')
                && !schema_was_applied('units.user_id.loosen'),
            'ALTER TABLE units
               DROP FOREIGN KEY fk_units_user,
               MODIFY user_id INT UNSIGNED NULL',
        ],

        'languages.user_id.loosen' => [
            static fn (): bool => column_exists('languages', 'user_id')
                && !schema_was_applied('languages.user_id.loosen'),
            'ALTER TABLE languages
               DROP FOREIGN KEY fk_lang_user,
               DROP INDEX uq_lang_user_name,
               MODIFY user_id INT UNSIGNED NULL',
        ],

        /* ---- Der Besitzer verschwindet, Schritt 2 von 2 ----------------- */

        /*
         * Jetzt darf die Spalte fallen. Diese Version schreibt sie nicht
         * mehr - deshalb die Bedingung auf NULL erlaubt: Ist der erste
         * Schritt nicht gelaufen, wird hier nichts angefasst, und der
         * Selbsttest zeigt beide Schritte als offen an. Ein Fallenlassen
         * ohne den ersten Schritt waere kein Fortschritt, sondern ein
         * Zeitfenster, in dem jedes Einfuegen scheitert.
         */
        'units.user_id.drop' => [
            static fn (): bool => column_exists('units', 'user_id')
                && column_is_nullable('units', 'user_id'),
            'ALTER TABLE units
               DROP INDEX idx_units_user,
               DROP COLUMN user_id',
        ],

        'languages.user_id.drop' => [
            static fn (): bool => column_exists('languages', 'user_id')
                && column_is_nullable('languages', 'user_id'),
            'ALTER TABLE languages DROP COLUMN user_id',
        ],

        'password_words.table' => [
            static fn (): bool => !table_exists('password_words'),
            "CREATE TABLE password_words (
                 id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
                 kind    VARCHAR(16)  NOT NULL,
                 word    VARCHAR(48)  NOT NULL,
                 gender  CHAR(1)      NULL,
                 active  TINYINT(1)   NOT NULL DEFAULT 1,
                 PRIMARY KEY (id),
                 UNIQUE KEY uq_password_words (kind, word),
                 KEY idx_password_words_pick (kind, active)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],

        'password_words.seed' => [
            static fn (): bool => table_exists('password_words')
                && !schema_was_applied('password_words.seed'),
            password_seed_sql(),
        ],

        /*
         * Das erzeugte Passwort im Klartext, damit das Anschreiben eines
         * Kindes nachdruckbar bleibt. Bewusste Abwaegung des Betreibers.
         * Der Wert wird geleert, sobald das Kind sein Passwort aendert -
         * dann ist er ohnehin wertlos, und der Bestand offener Passwoerter
         * schrumpft mit der Zeit statt zu wachsen.
         */
        'users.initial_password' => [
            static fn (): bool => !column_exists('users', 'initial_password'),
            'ALTER TABLE users ADD COLUMN initial_password VARCHAR(64) NULL AFTER password_hash',
        ],

        /*
         * Fehlversuche bei der Anmeldung.
         *
         * Zwei Woerter ergeben rund elftausend Kombinationen. Das reicht fuer
         * ein Kind, das sein Passwort abtippt, und nicht gegen jemanden, der
         * es durchprobiert - die Benutzernamen einer Klasse sind absehbar.
         * Gezaehlt wird je Konto und je Adresse; die Wartezeit waechst mit
         * der Zahl der Versuche.
         */
        // Einmal-Marken fuer den Sprung vom Rechner ans Telefon. Ein
        // QR-Code auf dem Bildschirm der Lehrkraft traegt eine davon; wer
        // ihn scannt, ist angemeldet und steht im Einlesen. Kurzlebig und
        // genau einmal einloesbar - siehe lib/handoff.php.
        /*
         * vocab.position soll wieder heissen, was alles annimmt: 0..n-1
         * ohne Luecken - Teil 1 von 2.
         *
         * Die Spalte ist zweierlei zugleich: Reihenfolge UND Freigabezeiger.
         * Elf Abfragen vergleichen v.position < u.released_position, und
         * "Alles freigeben" setzt die Marke auf COUNT(*). Loescht jemand
         * eine Vokabel in der Mitte, bleibt ein Loch - und danach ist
         * MAX(position)+1 groesser als COUNT(*), die letzte Vokabel also
         * mit "Alles freigeben" nicht mehr erreichbar. Still, ohne Meldung.
         *
         * Gelaufen wird das genau einmal, vor dem Schluessel: Danach kann
         * kein Weg mehr Luecken erzeugen, und die Pruefung kostet nur noch
         * einen Blick auf die Indexliste statt einer Gruppierung ueber alle
         * Vokabeln.
         */
        'vocab.position.compact' => [
            static fn (): bool => table_exists('vocab')
                && !index_exists('vocab', 'uq_vocab_pos')
                && vocab_positions_have_gaps(),
            'UPDATE vocab v
               JOIN (SELECT id,
                            ROW_NUMBER() OVER (PARTITION BY unit_id
                                                   ORDER BY position, id) - 1 AS neu
                       FROM vocab) r ON r.id = v.id
                SET v.position = r.neu
              WHERE v.position <> r.neu',
        ],

        /*
         * Teil 2: der Riegel.
         *
         * Zwei Vokabeln mit derselben Position teilen sich einen
         * Freigabeschritt - eine von beiden ist danach nicht einzeln
         * freizugeben. Der Schluessel macht daraus einen lauten Fehler statt
         * einer stillen Verfaelschung, und er schliesst das Wettrennen
         * zweier gleichzeitiger Anfuegungen.
         *
         * Muss NACH der Verdichtung stehen: Auf einer Datenbank mit Luecken
         * gaebe es zwar keine Dubletten, aber die Reihenfolge ist trotzdem
         * die richtige - erst geradeziehen, dann festnageln.
         */
        'vocab.position.unique' => [
            static fn (): bool => table_exists('vocab')
                && !index_exists('vocab', 'uq_vocab_pos'),
            'ALTER TABLE vocab ADD UNIQUE KEY uq_vocab_pos (unit_id, position)',
        ],

        'login_handoffs.table' => [
            static fn (): bool => !table_exists('login_handoffs'),
            "CREATE TABLE login_handoffs (
               id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               user_id    INT UNSIGNED NOT NULL,
               token_hash CHAR(64)     NOT NULL,
               target     VARCHAR(255) NOT NULL DEFAULT '',
               created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
               expires_at DATETIME     NOT NULL,
               used_at    DATETIME     NULL,
               UNIQUE KEY uq_lh_hash (token_hash),
               KEY idx_lh_user (user_id),
               CONSTRAINT fk_lh_user FOREIGN KEY (user_id)
                 REFERENCES users(id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],
        /*
         * Quittungen fuer nachgereichte Antworten.
         *
         * Wer offline uebt, sammelt seine Antworten im Geraet und schickt
         * sie spaeter am Stueck. Geht dabei die Antwort des Servers
         * verloren - und nicht die Anfrage -, schickt das Geraet dieselben
         * Antworten noch einmal, und ohne Gedaechtnis zaehlte der Server
         * sie doppelt. Eine Vokabel gaelte dann eine Runde zu frueh als
         * gekonnt.
         *
         * Jede Antwort traegt deshalb eine im Geraet erzeugte Kennung. Der
         * eindeutige Schluessel darauf macht das zweite Einreichen zu
         * einem Nichts statt zu einer Verdopplung.
         *
         * Aufgeraeumt wird nach ein paar Tagen: Was so lange nicht
         * angekommen ist, kommt nicht mehr.
         */
        'answer_receipts.table' => [
            static fn (): bool => !table_exists('answer_receipts'),
            "CREATE TABLE answer_receipts (
                 id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                 user_id    INT UNSIGNED NOT NULL,
                 event_id   CHAR(36)     NOT NULL,
                 created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                 PRIMARY KEY (id),
                 UNIQUE KEY uq_receipt (user_id, event_id),
                 KEY idx_receipt_alter (created_at),
                 CONSTRAINT fk_receipt_user FOREIGN KEY (user_id)
                   REFERENCES users(id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],
        'login_attempts.table' => [
            static fn (): bool => !table_exists('login_attempts'),
            "CREATE TABLE login_attempts (
                 id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                 username   VARCHAR(64)  NOT NULL,
                 ip         VARCHAR(45)  NOT NULL,
                 created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                 PRIMARY KEY (id),
                 KEY idx_login_attempts_user (username, created_at),
                 KEY idx_login_attempts_ip (ip, created_at)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],
    ];
}

function table_exists(string $table): bool
{
    $n = qv(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [$table],
    );
    return (int) $n > 0;
}

function column_exists(string $table, string $column): bool
{
    $n = qv(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column],
    );
    return (int) $n > 0;
}

/**
 * Darf diese Spalte NULL sein?
 *
 * Gebraucht als Sicherung vor dem Fallenlassen von units.user_id: Diese
 * Version schreibt die Spalte nicht mehr, also muss sie NULL erlauben, bevor
 * irgendetwas passiert. Ist sie noch NOT NULL, wurde der erste Schritt nicht
 * ausgeführt - dann ist Nichtstun richtig, und der Selbsttest zeigt, was
 * fehlt.
 */
function column_is_nullable(string $table, string $column): bool
{
    $v = qv(
        'SELECT IS_NULLABLE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column],
    );
    return $v === 'YES';
}

/**
 * Gibt es diesen Index auf der Tabelle?
 *
 * Bisher liessen sich Änderungen nur an Tabellen und Spalten festmachen. Der
 * Umbau von uq_progress braucht aber eine Prüfung auf den Index selbst - sonst
 * liefe er bei jedem Aufruf erneut oder gar nicht.
 */
/**
 * Gibt es irgendeine Lerneinheit mit Loechern in den Positionen?
 *
 * Nur fuer den Waechter der Verdichtung. Bewusst hier und nicht in
 * lib/vocab.php: lib/schema.php soll fuer eine Bedingung keine weitere
 * Datei laden muessen.
 */
function vocab_positions_have_gaps(): bool
{
    $n = qv(
        'SELECT COUNT(*) FROM (
             SELECT unit_id FROM vocab
              GROUP BY unit_id
             HAVING MIN(position) <> 0 OR MAX(position) <> COUNT(*) - 1
             LIMIT 1
         ) x',
    );
    return (int) $n > 0;
}

function index_exists(string $table, string $index): bool
{
    $n = qv(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
        [$table, $index],
    );
    return (int) $n > 0;
}

/** Namen der noch fehlenden Änderungen. */
function schema_pending(): array
{
    $pending = [];
    foreach (schema_migrations() as $name => [$isMissing, $_sql]) {
        if ($isMissing()) {
            $pending[] = $name;
        }
    }
    return $pending;
}

/**
 * Führt fehlende Änderungen aus und liefert deren Namen.
 * Fehler werden protokolliert, aber nicht durchgereicht - eine misslungene
 * Schemaänderung darf den Admin-Bereich nicht unerreichbar machen.
 */
function ensure_schema(): array
{
    $ergebnis = [];

    foreach (schema_migrations() as $name => [$isMissing, $sql]) {
        if (!$isMissing()) {
            continue;
        }
        try {
            db()->exec($sql);
            setting_set('schema_applied_' . $name, gmdate('c'));
            $ergebnis[] = ['name' => $name, 'ok' => true, 'error' => null];
            error_log('[vokabeltrainer] Schema ergänzt: ' . $name);
        } catch (Throwable $e) {
            $ergebnis[] = ['name' => $name, 'ok' => false, 'error' => $e->getMessage()];
            error_log('[vokabeltrainer] Schema konnte nicht ergänzt werden (' . $name . '): '
                . $e->getMessage());

            /*
             * Nach dem ersten Fehlschlag abbrechen.
             *
             * Die Änderungen bauen aufeinander auf - eine Tabelle entsteht,
             * dann werden Daten hineingeschrieben. Läuft der erste Schritt
             * nicht, ist der zweite bestenfalls wirkungslos und schlimmstenfalls
             * schädlich. Lieber mit einer klaren Meldung stehenbleiben, als
             * sich durch eine Reihe von Folgefehlern zu arbeiten.
             */
            break;
        }
    }

    return $ergebnis;
}
