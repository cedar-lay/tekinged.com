<?php
/*
 * api/quiz/audio/quiz_start.php
 *
 * Starts a brand-new Audio quiz.
 * GET/POST — no parameters required.
 *
 * Response:
 * {
 *   "question": {
 *     "number": 1,
 *     "audioUrl": "/uploads/mp3s/examples.palauan/482.mp3",
 *     "options": ["translation A", "translation B", ...],
 *     "optionValues": [482, 91, 204, 15, 337]
 *   },
 *   "progress": { "asked": 0, "correct": 0, "total": 25, "remaining": 25 }
 * }
 */

require_once __DIR__ . '/quiz_classes.php';

$status = new Quiz();
$question = generate_audio_question($mysqli, $status);

$_SESSION['qstatus'] = $status;
$_SESSION['question'] = $question;

echo json_encode([
    'sessionToken' => session_id(),
    'question' => question_to_array($question),
    'progress' => progress_to_array($status),
]);
