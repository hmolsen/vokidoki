<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/** Hält die Verbindung; über db_reconnect() ersetzbar. */
final class DbHandle
{
    public static ?PDO $pdo = null;
}

/**
 * Wie lange MySQL diese Verbindung im Leerlauf offen halten soll.
 *
 * Ein KI-Aufruf dauert Minuten, und währenddessen liegt die Verbindung
 * ungenutzt herum. Der voreingestellte wait_timeout vieler Hoster liegt bei
 * 60 Sekunden - danach ist die Leitung tot und das Speichern schlägt mit
 * "MySQL server has gone away" fehl, nachdem die Anfrage bereits bezahlt war.
 */
const DB_WAIT_TIMEOUT = 900;

function db(): PDO
{
    if (DbHandle::$pdo instanceof PDO) {
        return DbHandle::$pdo;
    }

    $c   = cfg('db');
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $c['host'],
        (int) ($c['port'] ?? 3306),
        $c['name'],
    );

    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // Nicht jeder Hoster erlaubt das Setzen - misslingt es, greift weiterhin
    // die Wiederverbindung unten.
    try {
        $pdo->exec('SET SESSION wait_timeout = ' . DB_WAIT_TIMEOUT);
    } catch (Throwable) {
        // egal, db_ensure() fängt es auf
    }

    DbHandle::$pdo = $pdo;
    return $pdo;
}

/** Verwirft die Verbindung; der nächste db()-Aufruf baut eine neue auf. */
function db_reconnect(): PDO
{
    DbHandle::$pdo = null;
    return db();
}

/**
 * Stellt sicher, dass die Verbindung noch steht - nach jedem langen Aufruf
 * ohne Datenbankverkehr aufrufen, also nach jedem KI-Request.
 *
 * Wichtig: Vorbereitete Statements von vorher überleben eine Wiederverbindung
 * nicht. Deshalb erst aufrufen, dann vorbereiten.
 */
function db_ensure(): void
{
    if (!DbHandle::$pdo instanceof PDO) {
        db();
        return;
    }

    try {
        DbHandle::$pdo->query('SELECT 1');
    } catch (Throwable) {
        db_reconnect();
    }
}

/** Prepared statement ausführen und Statement zurückgeben. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** Erste Zeile oder null. */
function q1(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Alle Zeilen. */
function qa(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** Erste Spalte der ersten Zeile. */
function qv(string $sql, array $params = []): mixed
{
    $st  = q($sql, $params);
    $val = $st->fetchColumn();
    return $val === false ? null : $val;
}
