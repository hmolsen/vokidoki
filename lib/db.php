<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
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

    return $pdo;
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
