<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

$configPath = __DIR__ . '/config.php';
if (!is_readable($configPath)) {
    $configPath = __DIR__ . '/config.sample.php';
}

require $configPath;
require __DIR__ . '/db.php';

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function sanitizePlayerId(?string $playerId): string
{
    $playerId = trim((string) $playerId);
    if ($playerId === '') {
        return '';
    }

    if (!preg_match('/^[a-zA-Z0-9-]{1,36}$/', $playerId)) {
        return '';
    }

    return $playerId;
}

function sanitizePlayerName(?string $name): string
{
    $name = trim(strip_tags((string) $name));
    if ($name === '') {
        $name = 'Anonymous';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($name, 0, MAX_PLAYER_NAME_LENGTH);
    }

    return substr($name, 0, MAX_PLAYER_NAME_LENGTH);
}

function sanitizeScore($value): int
{
    $score = filter_var($value, FILTER_VALIDATE_INT);
    if ($score === false || $score < 0) {
        return -1;
    }

    return min($score, 9999999);
}

function sanitizeRows($value): int
{
    $rows = filter_var($value, FILTER_VALIDATE_INT);
    if ($rows === false || $rows < 0) {
        return -1;
    }

    return min($rows, 999999);
}

function formatLeaderboardRow(array $row): array
{
    $playedAt = strtotime((string) $row['played_at']);

    return [
        'name' => $row['player_name'],
        'score' => (int) $row['score'],
        'rows' => (int) $row['rows_cleared'],
        'at' => $playedAt !== false ? $playedAt * 1000 : (int) round(microtime(true) * 1000),
    ];
}

function fetchLeaderboard(PDO $pdo, int $limit): array
{
    $stmt = $pdo->prepare(
        'SELECT player_name, score, rows_cleared, played_at
         FROM scores
         ORDER BY score DESC, played_at ASC
         LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $leaderboard = [];
    foreach ($stmt->fetchAll() as $row) {
        $leaderboard[] = formatLeaderboardRow($row);
    }

    return $leaderboard;
}

function fetchGlobalHighScore(PDO $pdo): int
{
    $stmt = $pdo->query('SELECT COALESCE(MAX(score), 0) AS high_score FROM scores');
    $row = $stmt->fetch();

    return (int) ($row['high_score'] ?? 0);
}

function fetchPlayerHighScore(PDO $pdo, string $playerId): int
{
    if ($playerId === '') {
        return 0;
    }

    $stmt = $pdo->prepare(
        'SELECT COALESCE(MAX(score), 0) AS high_score
         FROM scores
         WHERE player_id = :player_id'
    );
    $stmt->execute(['player_id' => $playerId]);
    $row = $stmt->fetch();

    return (int) ($row['high_score'] ?? 0);
}

function buildScoresPayload(PDO $pdo, int $limit, string $playerId = ''): array
{
    return [
        'ok' => true,
        'highScore' => fetchGlobalHighScore($pdo),
        'playerHighScore' => fetchPlayerHighScore($pdo, $playerId),
        'leaderboard' => fetchLeaderboard($pdo, $limit),
    ];
}

try {
    $pdo = getDb();
    ensureScoresTable($pdo);
} catch (Throwable $e) {
    jsonResponse([
        'ok' => false,
        'error' => 'Database unavailable. Import api/schema.sql and check api/config.php.',
    ], 503);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT);
    if ($limit === false || $limit < 1) {
        $limit = LEADERBOARD_LIMIT;
    }
    $limit = min($limit, LEADERBOARD_LIMIT);

    $playerId = sanitizePlayerId($_GET['player_id'] ?? '');

    jsonResponse(buildScoresPayload($pdo, $limit, $playerId));
}

if ($method === 'POST') {
    $body = readJsonBody();

    $playerId = sanitizePlayerId($body['player_id'] ?? '');
    if ($playerId === '') {
        jsonResponse(['ok' => false, 'error' => 'Invalid player_id.'], 400);
    }

    $name = sanitizePlayerName($body['name'] ?? '');
    $score = sanitizeScore($body['score'] ?? null);
    $rows = sanitizeRows($body['rows'] ?? null);

    if ($score < 0 || $rows < 0) {
        jsonResponse(['ok' => false, 'error' => 'Invalid score or rows.'], 400);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO scores (player_id, player_name, score, rows_cleared)
         VALUES (:player_id, :player_name, :score, :rows_cleared)'
    );
    $stmt->execute([
        'player_id' => $playerId,
        'player_name' => $name,
        'score' => $score,
        'rows_cleared' => $rows,
    ]);

    jsonResponse(buildScoresPayload($pdo, LEADERBOARD_LIMIT, $playerId), 201);
}

jsonResponse(['ok' => false, 'error' => 'Method not allowed.'], 405);
