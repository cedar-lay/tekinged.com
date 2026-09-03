<?php
/*
 * api/quiz/pronouns/quiz_classes.php
 *
 * Shared logic for the JSON-based Pronouns quiz API.
 * Adapted from John's quiz2.php + pronouns.php.
 *
 * This is the first free-text-entry quiz type: the user types a
 * Palauan word instead of picking from options. Grading uses fuzzy
 * string matching (PHP's similar_text()), so scores can be
 * fractional (e.g. 0.85 for a close-but-not-exact answer, matching
 * the original site's "Almost..." tier).
 *
 * Must be required BEFORE session_start() (handled internally by
 * start_quiz_session() below).
 */

require_once __DIR__ . '/../../db_config.php';

// ------------------------------------------------------------------
// CORS + session setup (identical pattern to the other quizzes)
// ------------------------------------------------------------------

function send_cors_headers() {
    $allowed_origins = [
        'https://tekinged.webflow.io',
        'https://tekinged.com',
        'https://www.tekinged.com',
        'https://staging.tekinged.com',
    ];

    if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowed_origins, true)) {
        header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
        header('Access-Control-Allow-Credentials: true');
    }
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function start_quiz_session() {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'None',
    ]);
    session_start();
}

send_cors_headers();
start_quiz_session();
header('Content-Type: application/json');

$mysqli = new mysqli($db_host, $db_user, $db_pwd, $database);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed.']);
    exit;
}

function query_or_die($mysqli, $sql) {
    $result = $mysqli->query($sql);
    if (!$result) {
        http_response_code(500);
        echo json_encode(['error' => 'Database query failed.']);
        exit;
    }
    return $result;
}

function scrape_ip() {
    $server = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : "";
    $proxy  = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : "";
    $user   = isset($_SERVER['REMOTE_USER']) ? $_SERVER['REMOTE_USER'] : "";
    $agent  = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : "";
    return array($server, $proxy, $user, $agent);
}

function quizlog($mysqli, $pword, $correct, $qtype = 'Pronouns') {
    list($s, $p, $u, $a) = scrape_ip();
    $pword = $mysqli->real_escape_string($pword);
    $s = $mysqli->real_escape_string($s);
    $a = $mysqli->real_escape_string($a);
    $u = $mysqli->real_escape_string($u);
    $p = $mysqli->real_escape_string($p);
    $qtype = $mysqli->real_escape_string($qtype);
    $query = "INSERT INTO log_quiz (query,correct,ip,agent,user,proxy,quiztype) " .
             "VALUES ('$pword','$correct','$s','$a','$u','$p','$qtype')";
    $mysqli->query($query);
}

function quizzedlog($mysqli, $name, $correct, $total, $qtype = 'Pronouns') {
    list($s, $p, $u, $a) = scrape_ip();
    $name = $mysqli->real_escape_string($name);
    $s = $mysqli->real_escape_string($s);
    $a = $mysqli->real_escape_string($a);
    $u = $mysqli->real_escape_string($u);
    $p = $mysqli->real_escape_string($p);
    $qtype = $mysqli->real_escape_string($qtype);
    $query = "INSERT INTO log_quizzes (name,correct,total,ip,agent,user,proxy,quiztype) " .
             "VALUES ('$name','$correct','$total','$s','$a','$u','$p','$qtype')";
    $mysqli->query($query);
}

class Quiz {
    public $questions;
    public $correct;
    public $stems;
    public $total;
    public $extra_msg;

    public function __construct() {
        $this->questions = 0;
        $this->correct = 0;
        $this->stems = array();
        $this->extra_msg = NULL;
        $this->total = 25;
    }

    function addExtraMsg($word, $score) {
        $this->extra_msg = ": $word ($score)";
    }

    function getExtraMsg() {
        return $this->extra_msg;
    }

    function addAnswer($correct) {
        if ($this->remaining() > 0) {
            $this->questions++;
            $this->correct += $correct;
        }
    }

    function remaining() {
        return $this->total - $this->questions;
    }

    function asked() {
        return $this->questions;
    }

    function add_stem($stem) {
        $this->stems[] = $stem;
    }

    // Matches quiz2.php's Quiz::get_filter($field="id") exactly.
    function get_filter($field = "id") {
        $filter = '(';
        foreach ($this->stems as $stem) {
            $filter .= "$field != '$stem' && ";
        }
        $filter .= " 1)";
        return $filter;
    }

    function correct() {
        return $this->correct;
    }
}

class PronounQuestion {
    public $myid;
    public $prompt;    // the English meaning to translate, e.g. "we (excl.)"
    public $correctId; // id of the canonical answer row

    public function __construct($myid, $prompt, $correctId) {
        $this->myid = $myid;
        $this->prompt = $prompt;
        $this->correctId = $correctId;
    }

    function getCorrectId() {
        return $this->correctId;
    }

    // Unlike the other quiz2-engine quizzes, this one's original
    // getQuestion() correctly returns the actual prompt (the English
    // meaning), not the answer — so log continuity is straightforward.
    function getLogLabel() {
        return $this->prompt;
    }
}

/**
 * Fuzzy-matches a typed answer against a list of acceptable spellings,
 * mirroring quiz2.php's get_score(). Returns a 0-1 similarity score
 * (the best match among all acceptable spellings).
 */
function fuzzy_score($possibles, $answer) {
    $answer = trim($answer);
    $score = 0;
    foreach ($possibles as $possible) {
        similar_text($possible, $answer, $perc);
        $perc /= 100;
        $score = max($score, $perc);
    }
    return $score;
}

/**
 * Converts a fuzzy score into a result tier, mirroring quiz2.php's
 * score_to_result(). Exact match = 'correct' (score 1); >80% similar
 * = 'almost' (partial credit, the original's fractional-scoring
 * feature); anything else = 'incorrect' (score 0).
 */
function score_to_result($score) {
    if ($score == 1) {
        return ['correct', $score];
    } else if ($score > 0.8) {
        return ['almost', $score];
    } else {
        return ['incorrect', 0];
    }
}

/**
 * Generates one Pronouns question: a random word tagged pos='pro.'
 * (pronoun), excluding pronouns already asked this session.
 */
function generate_pronoun_question($mysqli, $quiz) {
    $qfilter = $quiz->get_filter();
    $filter = "pos like 'pro.' && $qfilter";
    $q_q = "select eng,pal,id from all_words3 where $filter order by rand() limit 1;";
    $r = query_or_die($mysqli, $q_q);
    $row = $r->fetch_assoc();

    $prompt = $row['eng'];
    $correctId = $row['id'];

    $quiz->add_stem($correctId);
    $myid = $quiz->asked() + 1;

    return new PronounQuestion($myid, $prompt, $correctId);
}

function question_to_array($question) {
    return [
        'number'     => $question->myid,
        'prompt'     => 'Please type the Palauan pronoun meaning "' . $question->prompt . '"',
        'answerType' => 'text',
    ];
}

function progress_to_array($status) {
    // Scores here can be fractional (partial credit for "almost"
    // answers), so round for display rather than showing long
    // floating-point decimals.
    $correct = $status->correct();
    if ($correct != (int)$correct) {
        $correct = round($correct, 2);
    }
    return [
        'asked'     => $status->asked(),
        'correct'   => $correct,
        'total'     => $status->total,
        'remaining' => $status->remaining(),
    ];
}
