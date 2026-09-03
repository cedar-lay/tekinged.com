<?php
/*
 * api/quiz/etymology/quiz_answer.php
 *
 * Grades one answer against the question stored in the session, then
 * either returns the next question or signals the quiz is done.
 *
 * POST (preferred) or GET param:
 *   answer  - the exact language name text the user selected
 *             (one of the "options" strings from quiz_start.php /
 *             the previous quiz_answer.php call)
 *
 * Response shape matches the Vocab quiz's quiz_answer.php exactly.
 */

require_once __DIR__ . '/quiz_classes.php';

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
$logLabel = $question->getLogLabel();
$isCorrect = ($answer === $correctAnswer);
$score = $isCorrect ? 1 : 0;

$status->addExtraMsg($logLabel, $score);
$status->addAnswer($score);
quizlog($mysqli, $logLabel, $score);

$response = [
    'correct'             => $isCorrect,
    'yourAnswerValue'     => $answer,
    'correctAnswerValue'  => $correctAnswer,
    'correctAnswerLabel'  => $correctAnswer, // same as value for this quiz type
    'word'                => $question->word,
    'gloss'               => $question->gloss,
    'progress'            => progress_to_array($status),
    'done'                => ($status->remaining() <= 0),
    'nextQuestion'        => null,
];

if ($status->remaining() > 0) {
    $nextQuestion = generate_etymology_question($mysqli, $status);
    $_SESSION['question'] = $nextQuestion;
    $response['nextQuestion'] = question_to_array($nextQuestion);
} else {
    unset($_SESSION['question']);
}

$_SESSION['qstatus'] = $status;

echo json_encode($response);
