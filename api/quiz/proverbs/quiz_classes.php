<?php
/*
 * api/quiz/proverbs/quiz_classes.php
 *
 * Shared logic for the JSON-based Proverbs quiz API.
 * Adapted from John's quiz2.php + q_proverbs.php.
 *
 * The user listens to an audio clip of a Palauan proverb and picks
 * its correct figurative meaning from 5 options (one from each of
 * 5 randomly-fetched proverbs). Audio files are static and directly
 * predictable — no PHP endpoint needed: /uploads/mp3s/proverbs.palauan/{id}.mp3
 * — matching get_mp3_paths()/audio_button() from functions.php.
 *
 * NOTE on which answer is "correct": the original PQuestion/AQuestion
 * constructor's comment says "the first one that comes in is the
 * correct one," but the loop actually overwrites the tracked answer
 * with EVERY row as it iterates — so the LAST fetched row ends up
 * being the one marked correct, not the first. This doesn't cause any
 * real unfairness since all 5 rows already arrive in random order
 * from "order by rand()" — any of them being "the correct one" is
 * equally valid. Preserved here exactly (last row wins) to match live
 * behavior rather than the comment's stated intent.
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

function quizlog($mysqli, $pword, $correct, $qtype = 'Proverbs') {
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

function quizzedlog($mysqli, $name, $correct, $total, $qtype = 'Proverbs') {
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

class ProverbQuestion {
    public $myid;
    public $audioUrl;
    public $labels;         // 5 figurative explanations (shuffled), for display
    public $values;         // matching 5 proverb ids, same order as labels
    public $correctValue;
    public $correctLabel;   // the correct figurative explanation
    public $correctEnglish; // the literal English definition of the proverb
    public $correctPal;     // the Palauan proverb text itself

    public function __construct($myid, $audioUrl, $labels, $values, $correctValue, $correctLabel, $correctEnglish, $correctPal) {
        $this->myid = $myid;
        $this->audioUrl = $audioUrl;
        $this->labels = $labels;
        $this->values = $values;
        $this->correctValue = $correctValue;
        $this->correctLabel = $correctLabel;
        $this->correctEnglish = $correctEnglish;
        $this->correctPal = $correctPal;
    }

    function getCorrectValue() {
        return $this->correctValue;
    }

    function getCorrectLabel() {
        return $this->correctLabel;
    }

    // Matches the original's getQuestion(), which returns the
    // figurative explanation text of the correct proverb.
    function getLogLabel() {
        return $this->correctLabel;
    }
}

/**
 * Generates one Proverbs question: 5 random proverbs that have
 * confirmed uploaded audio, one English figurative explanation from
 * each as the multiple-choice options. Mirrors AQuestion's
 * constructor from q_proverbs.php, including its last-row-wins
 * "correct answer" behavior (see file-level note above).
 */
function generate_proverb_question($mysqli, $quiz) {
    $qfilter = $quiz->get_filter();
    $filter = "id in (select externalid from upload_audio where uploaded=1 && externaltable like 'proverbs') && $qfilter";
    $q_q = "select english,palauan,id,explanation from proverbs where $filter order by rand() limit 5;";
    $r = query_or_die($mysqli, $q_q);

    $labels = [];
    $values = [];
    $correctId = null;
    $correctEnglish = null;
    $correctExplanation = null;
    $correctPal = null;

    while ($row = $r->fetch_assoc()) {
        $labels[] = $row['explanation'];
        $values[] = $row['id'];
        // Last row wins — matches original behavior, see note above.
        $correctId = $row['id'];
        $correctEnglish = $row['english'];
        $correctExplanation = $row['explanation'];
        $correctPal = $row['palauan'];
        $quiz->add_stem($row['id']);
    }

    $pairs = array_map(null, $labels, $values);
    shuffle($pairs);
    $labels = array_column($pairs, 0);
    $values = array_column($pairs, 1);

    $myid = $quiz->asked() + 1;
    $audioUrl = '/uploads/mp3s/proverbs.palauan/' . $correctId . '.mp3';

    return new ProverbQuestion($myid, $audioUrl, $labels, $values, $correctId, $correctExplanation, $correctEnglish, $correctPal);
}

function question_to_array($question) {
    return [
        'number'       => $question->myid,
        'audioUrl'     => $question->audioUrl,
        'options'      => $question->labels,
        'optionValues' => $question->values,
    ];
}

function progress_to_array($status) {
    return [
        'asked'     => $status->asked(),
        'correct'   => $status->correct(),
        'total'     => $status->total,
        'remaining' => $status->remaining(),
    ];
}
