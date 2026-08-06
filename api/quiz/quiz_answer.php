<?php
/*
 * api/quiz_answer.php
 *
 * Grades one answer against the question stored in the session, then
 * either returns the next question or signals the quiz is done.
 *
 * POST (preferred) or GET param:
 *   answer  - the exact English definition text the user selected
 *             (this is one of the "options" strings returned by
 *             quiz_start.php / the previous quiz_answer.php call)
 *
 * Response:
 * {
 *   "correct": true,
 *   "yourAnswer": "...",
 *   "correctAnswer": "...",
 *   "word": "tekoi",
 *   "progress": { "asked": 1, "correct": 1, "total": 25, "remaining": 24 },
 *   "done": false,
 *   "nextQuestion": { "number": 2, "word": "...", "options": [...] }  // or null if done
 * }
 */

require_once __DIR__ . '/quiz_classes.php';
session_start();

header('Content-Type: application/json');

$answer = isset($_POST['answer']) ? $_POST['answer'] : (isset($_GET['answer']) ? $_GET['answer'] : null);

if ($answer === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing "answer" parameter.']);
    exit;
}

if (!isset($_SESSION['question']) || !isset($_SESSION['qstatus'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No active quiz question. Call quiz_start.php first.']);
    exit;
}

$question = $_SESSION['question'];
$status = $_SESSION['qstatus'];

$correctAnswer = $question->getCorrect();
$pword = $question->getQuestion();
$isCorrect = ($answer === $correctAnswer);
$score = $isCorrect ? 1 : 0;

$status->addExtraMsg($pword, $score);
$status->addAnswer($score);
quizlog($mysqli, $pword, $score);

$response = [
    'correct'       => $isCorrect,
    'yourAnswer'    => $answer,
    'correctAnswer' => $correctAnswer,
    'word'          => $pword,
    'progress'      => progress_to_array($status),
    'done'          => ($status->remaining() <= 0),
    'nextQuestion'  => null,
];

if ($status->remaining() > 0) {
    $nextQuestion = generate_question($mysqli, $status);
    $_SESSION['question'] = $nextQuestion;
    $response['nextQuestion'] = question_to_array($nextQuestion);
} else {
    unset($_SESSION['question']);
}

$_SESSION['qstatus'] = $status;

echo json_encode($response);
