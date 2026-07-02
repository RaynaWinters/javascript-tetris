<?php

declare(strict_types=1);

const LEADERBOARD_FILE = __DIR__ . '/leaderboard.txt';
const LEADERBOARD_SIZE = 100;

function loadLeaderboardData(): array
{
    if (!is_readable(LEADERBOARD_FILE) || filesize(LEADERBOARD_FILE) === 0) {
        return ['highScore' => 0, 'highRows' => 0, 'leaderboard' => []];
    }

    $raw = file_get_contents(LEADERBOARD_FILE);
    if ($raw === false) {
        return ['highScore' => 0, 'highRows' => 0, 'leaderboard' => []];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['highScore' => 0, 'highRows' => 0, 'leaderboard' => []];
    }

    return [
        'highScore' => (int) ($data['highScore'] ?? 0),
        'highRows' => (int) ($data['highRows'] ?? 0),
        'leaderboard' => is_array($data['leaderboard'] ?? null) ? $data['leaderboard'] : [],
    ];
}

function saveLeaderboardData(array $data): bool
{
    $leaderboard = $data['leaderboard'] ?? [];
    usort($leaderboard, static function (array $a, array $b): int {
        return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
    });
    $leaderboard = array_slice($leaderboard, 0, LEADERBOARD_SIZE);

    $payload = [
        'highScore' => (int) ($data['highScore'] ?? 0),
        'highRows' => (int) ($data['highRows'] ?? 0),
        'leaderboard' => $leaderboard,
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }

    return file_put_contents(LEADERBOARD_FILE, $json . PHP_EOL, LOCK_EX) !== false;
}

function sanitizePlayerName(?string $name): string
{
    $name = trim(strip_tags((string) $name));
    if ($name === '') {
        return 'Anonymous';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($name, 0, 20);
    }

    return substr($name, 0, 20);
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

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['api']) && $_GET['api'] === 'scores') {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        jsonResponse(loadLeaderboardData());
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $body = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($body)) {
            jsonResponse(['ok' => false, 'error' => 'Invalid JSON body.'], 400);
        }

        $name = sanitizePlayerName($body['name'] ?? '');
        $score = sanitizeScore($body['score'] ?? null);
        $rows = sanitizeRows($body['rows'] ?? null);

        if ($score < 0 || $rows < 0) {
            jsonResponse(['ok' => false, 'error' => 'Invalid score or rows.'], 400);
        }

        $data = loadLeaderboardData();
        if ($score > $data['highScore']) {
            $data['highScore'] = $score;
            $data['highRows'] = $rows;
        }

        $data['leaderboard'][] = [
            'name' => $name,
            'score' => $score,
            'rows' => $rows,
            'at' => (int) round(microtime(true) * 1000),
        ];

        if (!saveLeaderboardData($data)) {
            jsonResponse(['ok' => false, 'error' => 'Could not write leaderboard.txt.'], 500);
        }

        $saved = loadLeaderboardData();
        jsonResponse(array_merge(['ok' => true], $saved), 201);
    }

    jsonResponse(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$html = file_get_contents(__DIR__ . '/index.html');
if ($html === false) {
    http_response_code(500);
    echo 'Could not load index.html';
    exit;
}

$scorePersistenceJs = <<<'JS'
    //-------------------------------------------------------------------------
    // score persistence (leaderboard.txt via index.php)
    //-------------------------------------------------------------------------

    var SCORE_API           = 'index.php?api=scores',
        PLAYER_NAME_KEY     = 'tetris_player_name',
        LEADERBOARD_SIZE    = 100,
        showingLeaderboard  = false,
        cachedScores        = { highScore: 0, highRows: 0, leaderboard: [] };

    function getPlayerName() {
      var el = get('player-name');
      var name = el ? el.value.trim() : '';
      if (!name)
        name = 'Anonymous';
      try { localStorage.setItem(PLAYER_NAME_KEY, name); } catch (e) {}
      return name;
    }

    function loadPlayerName() {
      try {
        var name = localStorage.getItem(PLAYER_NAME_KEY);
        if (name && get('player-name'))
          get('player-name').value = name;
      }
      catch (e) {}
    }

    function loadScores(callback) {
      fetch(SCORE_API)
        .then(function(response) { return response.json(); })
        .then(function(data) {
          cachedScores = data || { highScore: 0, highRows: 0, leaderboard: [] };
          if (callback)
            callback(cachedScores);
        })
        .catch(function() {
          cachedScores = { highScore: 0, highRows: 0, leaderboard: [] };
          if (callback)
            callback(cachedScores);
        });
    }

    function saveGameResult(finalScore, finalRows) {
      return fetch(SCORE_API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name: getPlayerName(),
          score: finalScore,
          rows: finalRows
        })
      })
      .then(function(response) { return response.json(); })
      .then(function(data) {
        if (data && data.leaderboard)
          cachedScores = data;
        return cachedScores;
      })
      .catch(function() {
        return cachedScores;
      });
    }

    function formatScore(n) {
      return ("00000" + Math.floor(n)).slice(-5);
    }

    function formatLeaderboardDate(at) {
      var d = new Date(at);
      return (d.getMonth() + 1) + '/' + d.getDate();
    }

    function renderScoreViews(data) {
      html('high-score', formatScore(data.highScore));
      var list = '', i, entry;
      if (!data.leaderboard || data.leaderboard.length === 0)
        list = '<li>no games yet</li>';
      else {
        for (i = 0; i < data.leaderboard.length; i++) {
          entry = data.leaderboard[i];
          list += '<li><strong>' + (entry.name || 'Anonymous') + '</strong> ' + formatScore(entry.score) + ' (' + entry.rows + ' rows, ' + formatLeaderboardDate(entry.at) + ')</li>';
        }
      }
      html('leaderboard', '<h4>Server Leaderboard</h4><ol>' + list + '</ol>');
    }

    function toggleScoreView() {
      showingLeaderboard = !showingLeaderboard;
      get('leaderboard').style.display = showingLeaderboard ? 'block' : 'none';
      html('score-view-toggle', showingLeaderboard ? 'Hide Server Leaderboard' : 'View Server Leaderboard');
    }
JS;

$html = preg_replace(
    '/\/\/-{5,}\s*\n\s*\/\/ score persistence \(localStorage\).*?function toggleScoreView\(\) \{[^}]+\}/s',
    $scorePersistenceJs,
    $html,
    1,
    $replaced
);

if ($replaced !== 1) {
    http_response_code(500);
    echo 'Could not patch score persistence in index.html';
    exit;
}

$html = str_replace(
    "      var saved = saveGameResult(score, rows);\n      renderScoreViews(saved);",
    "      saveGameResult(score, rows).then(function(saved) {\n        renderScoreViews(saved);\n      });",
    $html
);

$html = str_replace(
    "    loadPlayerName();\n    renderScoreViews(loadScores());\n    run();",
    "    loadPlayerName();\n    loadScores(function(data) { renderScoreViews(data); });\n    run();",
    $html
);

$html = str_replace(
    'View Local Leaderboard',
    'View Server Leaderboard',
    $html
);

echo $html;
