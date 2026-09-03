<?php
/*
 * api/quiz/living/quiz_start.php
 *
 * Starts a brand-new Living Things quiz.
 * GET/POST — no parameters required.
 *
 * Response:
 * {
 *   "question": {
 *     "number": 1,
 *     "prompt": "...a Palauan definition with _____ where the word goes...",
 *     "options": ["word A", "word B", "word C", "word D", "word E"],
 *     "optionValues": [104, 87, 203, 12, 56]
 *   },
 *   "progress": { "asked": 0, "correct": 0, "total": 25, "remaining": 25 }
 * }
 */

require_once __DIR__ . '/quiz_classes.php';

$status = new Quiz();
$question = generate_living_question($mysqli, $status);

$_SESSION['qstatus'] = $status;
$_SESSION['question'] = $question;

echo json_encode([
    'sessionToken' => session_id(),
    'question' => question_to_array($question),
    'progress' => progress_to_array($status),
]);
