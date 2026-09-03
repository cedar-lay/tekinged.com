<?php
/*
 * api/quiz/pronouns/quiz_answer.php
 *
 * Grades one typed answer against the question stored in the session,
 * using fuzzy string matching (not exact match), then either returns
 * the next question or signals the quiz is done.
 *
 * POST (preferred) or GET param:
 *   answer  - the Palauan word the user typed
 *
 * Response:
 * {
 *   "result": "correct" | "almost" | "incorrect",
 *   "correct": true,               // convenience boolean, true only when result === "correct"
 *   "yourAnswerValue": "...",
 *   "correctAnswerLabel": "ngak or ngak2",  // all acceptable spellings, joined with " or "
 *   "word": "",
 *   "progress": { "asked": 1, "correct": 0.85, "total": 25, "remaining": 24 },
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

// Gather every acceptable spelling: the canonical word plus any
// recorded spelling variants (pos = 'var.') sharing the same stem.
$wid = $question->getCorrectId();
$q = "select pal from all_words3 where id=$wid or (stem=$wid and pos like 'var.')";
$r = query_or_die($mysqli, $q);
$possibles = [];
while ($row = $r->fetch_array(MYSQLI_NUM)) {
    $possibles[] = $row[0];
}
$correctLabel = implode(' or ', $possibles);

list($result, $score) = score_to_result(fuzzy_score($possibles, $answer));

$logLabel = $question->getLogLabel();
$status->addExtraMsg($logLabel, $score);
$status->addAnswer($score);
quizlog($mysqli, $logLabel, $score);

$response = [
    'result'              => $result,
    'correct'             => ($result === 'correct'),
    'yourAnswerValue'     => $answer,
    'correctAnswerLabel'  => $correctLabel,
    'word'                => '',
    'progress'            => progress_to_array($status),
    'done'                => ($status->remaining() <= 0),
    'nextQuestion'        => null,
];

if ($status->remaining() > 0) {
    $nextQuestion = generate_pronoun_question($mysqli, $status);
    $_SESSION['question'] = $nextQuestion;
    $response['nextQuestion'] = question_to_array($nextQuestion);
} else {
    unset($_SESSION['question']);
}

$_SESSION['qstatus'] = $status;

echo json_encode($response);
