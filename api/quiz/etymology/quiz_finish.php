<?php
/*
 * api/quiz/etymology/quiz_finish.php
 *
 * Identical shape to the Vocab quiz's quiz_finish.php.
 *
 * POST (preferred) or GET param:
 *   name  - player's display name (can be blank)
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

$_SESSION['qstatus'] = new Quiz();
unset($_SESSION['question']);

echo json_encode([
    'name'    => $name,
    'correct' => $correct,
    'total'   => $total,
    'percent' => $percent,
]);
