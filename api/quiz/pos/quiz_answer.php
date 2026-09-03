<?php
/*
 * api/quiz/pos/quiz_answer.php
 *
 * Grades one typed answer, then either returns the next question or
 * signals the quiz is done. On a wrong answer, includes a simple
 * plain-text explanation (see get_pos_explanation() in quiz_classes.php).
 *
 * POST (preferred) or GET param:
 *   answer  - the Palauan word the user typed
 *
 * Response:
 * {
 *   "result": "correct" | "almost" | "incorrect",
 *   "correct": true,
 *   "yourAnswerValue": "...",
 *   "correctAnswerLabel": "...",
 *   "explanation": "root (pos): meaning  \u2192  target (pos)",  // only present when result is "incorrect"
 *   "word": "",
 *   "progress": { ... },
 *   "done": false,
 *   "nextQuestion": { ... }  // or null if done
 * }
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

$possibles = get_pos_possible_answers($mysqli, $question->answer, $question->stem);
$correctLabel = implode(' or ', $possibles);

list($result, $score) = score_to_result(fuzzy_score($possibles, $answer));

$explanation = null;
if ($result === 'incorrect') {
    $explanation = get_pos_explanation($mysqli, $question->aid, $question->wid);
}

$logLabel = $question->getLogLabel();
$status->addExtraMsg($logLabel, $score);
$status->addAnswer($score);
quizlog($mysqli, $logLabel, $score);

$response = [
    'result'              => $result,
    'correct'             => ($result === 'correct'),
    'yourAnswerValue'     => $answer,
    'correctAnswerLabel'  => $correctLabel,
    'explanation'         => $explanation,
    'word'                => '',
    'progress'            => progress_to_array($status),
    'done'                => ($status->remaining() <= 0),
    'nextQuestion'        => null,
];

if ($status->remaining() > 0) {
    $nextQuestion = generate_pos_question($mysqli, $status);
    $_SESSION['question'] = $nextQuestion;
    $response['nextQuestion'] = question_to_array($nextQuestion);
} else {
    unset($_SESSION['question']);
}

$_SESSION['qstatus'] = $status;

echo json_encode($response);
