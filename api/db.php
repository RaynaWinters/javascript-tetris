<?php

function getDb(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function ensureScoresTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS scores (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            player_id VARCHAR(36) NOT NULL,
            player_name VARCHAR(20) NOT NULL,
            score INT UNSIGNED NOT NULL,
            rows_cleared INT UNSIGNED NOT NULL,
            played_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_score (score DESC),
            INDEX idx_player_id (player_id),
            INDEX idx_played_at (played_at DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}
