<?php
/*
 * api/quiz_start.php
 *
 * Starts a brand-new Vocab (Classic) quiz.
 * GET/POST — no parameters required.
 *
 * Response:
 * {
 *   "question": { "number": 1, "word": "tekoi", "options": [...5 English defs...] },
 *   "progress": { "asked": 0, "correct": 0, "total": 25, "remaining": 25 }
 * }
 */

require_once __DIR__ . '/quiz_classes.php';
session_start();

header('Content-Type: application/json');

$status = new Quiz();
$question = generate_question($mysqli, $status);

$_SESSION['qstatus'] = $status;
$_SESSION['question'] = $question;

echo json_encode([
    'question' => question_to_array($question),
    'progress' => progress_to_array($status),
]);
