<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

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
 * Liste der Änderungen: Name => [Prüfung, SQL].
 * Die Prüfung liefert true, wenn die Änderung noch fehlt.
 *
 * `schema.sql` legt das fertige Schema an; eine frische Installation hat
 * nichts nachzutragen - die Prüfungen unten finden alles schon vor.
 *
 * Die Liste war einmal leer, mit Absicht (siehe unten). Seit vokidoki.de
 * läuft, gibt es aber wieder eine Datenbank mit echten Konten, die man nicht
 * neu einspielen kann - also stehen hier wieder Änderungen, bewusst und
 * einzeln. Jede fügt nur hinzu.
 *
 * Hier standen früher fünfundvierzig Änderungen, und jede einzelne war der Weg von
 * einem älteren Stand auf den heutigen: der Umbau vom Besitzer-Modell
 * ("diese Lerneinheit gehört diesem Kind") auf Kurse, das Nachziehen der
 * Schul- und Klassenspalten, das zweistufige Fallenlassen von
 * `units.user_id`, das Nachtragen von Sprachkürzeln und Positionen - und
 * zehn `family.*`-Schritte, die den Bestand der alten Familien-App in eine
 * Schule namens "Familie" überführten.
 *
 * Nichts davon wird je wieder laufen: Es gibt keine ältere Datenbank mehr,
 * die überführt werden müsste. Siebenhundert Zeilen, die niemand mehr lesen
 * muss, um das Schema zu verstehen - und die beim Lesen den Eindruck
 * erwecken, es gäbe hier noch Altlasten zu bedenken.
 *
 * Der Weg selbst bleibt: Die nächste Schemaänderung kommt hier hinein,
 * ensure_schema() führt sie auf Knopfdruck im Selbsttest aus, und
 * schema_was_applied() steht für den Fall bereit, dass sie Daten nachträgt
 * statt Struktur.
 *
 * Eine Änderung gehört IMMER an zwei Stellen: hier für die laufende
 * Installation und in schema.sql für die nächste frische.
 */
function schema_migrations(): array
{
    return [
        // Die Hinweise bei der ersten Anmeldung - siehe lib/einwilligung.php.
        'users.consent' => [
            static fn (): bool => !column_exists('users', 'consent_version'),
            'ALTER TABLE users
               ADD COLUMN consent_version SMALLINT UNSIGNED NULL AFTER streak_best,
               ADD COLUMN consent_at DATETIME NULL AFTER consent_version',
        ],
        // Die eigene Vorlage einer Lehrkraft für die Zettel - siehe lib/letter.php.
        'users.letter_template' => [
            static fn (): bool => !column_exists('users', 'letter_template'),
            'ALTER TABLE users ADD COLUMN letter_template TEXT NULL AFTER consent_at',
        ],
        /*
         * Nur freundliche Adjektive in den Anfangspasswörtern.
         *
         * "fauler Hamster" und "langsame Schnecke" standen auf den Zetteln -
         * ein Kind liest sein Passwort als Urteil über sich. Die Wörter
         * gehen nicht weg, sondern auf inaktiv: Wer sie schon auf dem Zettel
         * hat, meldet sich weiter damit an; neu vergeben werden sie nicht.
         * Eine Anweisung für beides, weil ensure_schema() eine ausführt.
         *
         * Offen ist die Änderung nur, solange eines der Wörter noch aktiv
         * ist - eine frische Installation kennt sie gar nicht erst.
         */
        'password_words.freundlich' => [
            static fn (): bool => !schema_was_applied('password_words.freundlich')
                && (int) qv("SELECT COUNT(*) FROM password_words
                              WHERE kind = 'adjective' AND active = 1
                                AND word IN ('müd', 'faul', 'frech', 'langsam', 'grimmig', 'brummig', 'schusselig', 'zappelig', 'schwer', 'streng', 'sprunghaft', 'kribbelig', 'schüchtern', 'tapsig', 'rund', 'schlank', 'nass')") > 0,
            "INSERT INTO password_words (kind, word, gender, active) VALUES
               ('adjective', 'müd', NULL, 0), ('adjective', 'faul', NULL, 0), ('adjective', 'frech', NULL, 0), ('adjective', 'langsam', NULL, 0), ('adjective', 'grimmig', NULL, 0), ('adjective', 'brummig', NULL, 0), ('adjective', 'schusselig', NULL, 0), ('adjective', 'zappelig', NULL, 0), ('adjective', 'schwer', NULL, 0), ('adjective', 'streng', NULL, 0), ('adjective', 'sprunghaft', NULL, 0), ('adjective', 'kribbelig', NULL, 0), ('adjective', 'schüchtern', NULL, 0), ('adjective', 'tapsig', NULL, 0), ('adjective', 'rund', NULL, 0), ('adjective', 'schlank', NULL, 0), ('adjective', 'nass', NULL, 0),
               ('adjective', 'schön', NULL, 1), ('adjective', 'froh', NULL, 1), ('adjective', 'fein', NULL, 1), ('adjective', 'kühn', NULL, 1), ('adjective', 'toll', NULL, 1), ('adjective', 'flott', NULL, 1), ('adjective', 'hübsch', NULL, 1), ('adjective', 'lässig', NULL, 1), ('adjective', 'schick', NULL, 1), ('adjective', 'clever', NULL, 1), ('adjective', 'genial', NULL, 1), ('adjective', 'wunderbar', NULL, 1), ('adjective', 'friedlich', NULL, 1), ('adjective', 'kreativ', NULL, 1), ('adjective', 'zauberhaft', NULL, 1), ('adjective', 'fantastisch', NULL, 1), ('adjective', 'elegant', NULL, 1), ('adjective', 'frisch', NULL, 1), ('adjective', 'strahlend', NULL, 1), ('adjective', 'glänzend', NULL, 1)
             ON DUPLICATE KEY UPDATE active = VALUES(active)",
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
 * Gibt es diesen Index auf der Tabelle?
 *
 * Gebraucht vom Selbsttest: Er zeigt, ob die Schluessel stehen, die der
 * Lernstand braucht - ein fehlender uq_progress_user waere still und
 * folgenschwer.
 */
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
