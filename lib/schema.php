<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/languages.php';
require_once __DIR__ . '/settings.php';

/**
 * Nachträgliche Schemaänderungen.
 *
 * Die App wird per FTP aktualisiert - es gibt keinen Schritt, der von sich aus
 * SQL ausführt. Deshalb prüft der Admin-Bereich beim Aufruf, ob das Schema zum
 * Code passt, und ergänzt fehlende Spalten selbst. Jede Änderung ist so
 * formuliert, dass sie nur hinzufügt und vorhandene Daten unangetastet lässt.
 *
 * Bewusst nicht in den API-Endpunkten: Dort soll kein Seiteneffekt auf das
 * Schema möglich sein, und es kostet eine Abfrage pro Aufruf.
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
    $applied = [];
    foreach (schema_migrations() as $name => [$isMissing, $sql]) {
        if (!$isMissing()) {
            continue;
        }
        try {
            db()->exec($sql);
            setting_set('schema_applied_' . $name, gmdate('c'));
            $applied[] = $name;
            error_log('[vokabeltrainer] Schema ergänzt: ' . $name);
        } catch (Throwable $e) {
            error_log('[vokabeltrainer] Schema konnte nicht ergänzt werden (' . $name . '): '
                . $e->getMessage());
        }
    }
    return $applied;
}
