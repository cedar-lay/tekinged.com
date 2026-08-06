<?php
/*
 * api/quiz_finish.php
 *
 * Call once the quiz is done (progress.remaining reaches 0) to log the
 * score under the player's name — mirrors classic.php's "enter your
 * name to see if you got a high score" step.
 *
 * POST (preferred) or GET param:
 *   name  - player's display name (can be blank; classic.php logs even
 *           blank-name attempts so nothing is lost if they skip it)
 *
 * Response:
 * {
 *   "name": "...",
 *   "correct": 21,
 *   "total": 25,
 *   "percent": 84.0
 * }
 */

require_once __DIR__ . '/quiz_classes.php';
session_start();

header('Content-Type: application/json');

$name = isset($_POST['name']) ? trim($_POST['name']) : (isset($_GET['name']) ? trim($_GET['name']) : '');

if (!isset($_SESSION['qstatus'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No quiz in session. Call quiz_start.php first.']);
    exit;
}

$status = $_SESSION['qstatus'];
$correct = $status->correct();
$total = $status->asked();
$percent = ($total > 0) ? round(100 * ($correct / $total), 2) : 0;

quizzedlog($mysqli, $name, $correct, $total);

// Reset the session so a fresh "Start Quiz" click begins clean.
$_SESSION['qstatus'] = new Quiz();
unset($_SESSION['question']);

echo json_encode([
    'name'    => $name,
    'correct' => $correct,
    'total'   => $total,
    'percent' => $percent,
]);
