-- Run in phpMyAdmin or: mysql -u root < api/schema.sql

CREATE DATABASE IF NOT EXISTS tetris
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE tetris;

CREATE TABLE IF NOT EXISTS scores (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  player_id VARCHAR(36) NOT NULL,
  player_name VARCHAR(20) NOT NULL,
  score INT UNSIGNED NOT NULL,
  rows_cleared INT UNSIGNED NOT NULL,
  played_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_score (score DESC),
  INDEX idx_player_id (player_id),
  INDEX idx_played_at (played_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
