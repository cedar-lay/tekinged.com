<?php
/*
 * api/quiz/pronouns/quiz_start.php
 *
 * Starts a brand-new Pronouns quiz.
 * GET/POST — no parameters required.
 *
 * Response:
 * {
 *   "question": {
 *     "number": 1,
 *     "prompt": "Please type the Palauan pronoun meaning \"we (excl.)\"",
 *     "answerType": "text"
 *   },
 *   "progress": { "asked": 0, "correct": 0, "total": 25, "remaining": 25 }
 * }
 */

require_once __DIR__ . '/quiz_classes.php';

$status = new Quiz();
$question = generate_pronoun_question($mysqli, $status);

$_SESSION['qstatus'] = $status;
$_SESSION['question'] = $question;

echo json_encode([
    'sessionToken' => session_id(),
    'question' => question_to_array($question),
    'progress' => progress_to_array($status),
]);
