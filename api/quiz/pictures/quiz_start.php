<?php
/*
 * api/quiz/pictures/quiz_start.php
 *
 * Starts a brand-new Pictures quiz.
 * GET/POST — no parameters required.
 *
 * Response:
 * {
 *   "question": {
 *     "number": 1,
 *     "imageUrl": "/uploads/pics/482.jpg",
 *     "options": ["word A", "word B", ...],
 *     "optionValues": [482, 91, 204, 15, 337]
 *   },
 *   "progress": { "asked": 0, "correct": 0, "total": 25, "remaining": 25 }
 * }
 */

require_once __DIR__ . '/quiz_classes.php';

$status = new Quiz();
$question = generate_picture_question($mysqli, $status);

$_SESSION['qstatus'] = $status;
$_SESSION['question'] = $question;

echo json_encode([
    'sessionToken' => session_id(),
    'question' => question_to_array($question),
    'progress' => progress_to_array($status),
]);
