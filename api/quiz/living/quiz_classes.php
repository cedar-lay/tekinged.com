<?php
/*
 * api/quiz/living/quiz_classes.php
 *
 * Shared logic for the JSON-based Living Things quiz API.
 * Adapted from John's quiz2.php + q_living.php.
 *
 * Unlike the other quiz types, the "question" here isn't a single
 * word — it's a full Palauan-language definition (pdef) with the
 * target word blanked out. The blanked text is sent as a "prompt"
 * field; the front end displays it in the same element normally used
 * for a single word, so no new Webflow elements are needed.
 *
 * Answers are matched by database "id", same as Reng and Synonyms.
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

function quizlog($mysqli, $pword, $correct, $qtype = 'Living Things') {
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

function quizzedlog($mysqli, $name, $correct, $total, $qtype = 'Living Things') {
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

class LivingThingsQuestion {
    public $myid;
    public $prompt;       // Palauan-language definition, target word blanked out
    public $labels;       // 5 candidate Palauan words (shuffled), for display
    public $values;       // matching 5 database ids, same order as labels
    public $correctValue;
    public $correctLabel;

    public function __construct($myid, $prompt, $labels, $values, $correctValue, $correctLabel) {
        $this->myid = $myid;
        $this->prompt = $prompt;
        $this->labels = $labels;
        $this->values = $values;
        $this->correctValue = $correctValue;
        $this->correctLabel = $correctLabel;
    }

    function getCorrectValue() {
        return $this->correctValue;
    }

    function getCorrectLabel() {
        return $this->correctLabel;
    }

    function getLogLabel() {
        return $this->correctLabel;
    }
}

/**
 * Generates one Living Things question, mirroring SynQuestion's
 * constructor from q_living.php: picks 5 random qualifying words in
 * one query, blanks the first word out of its own definition to make
 * the prompt, and uses the other 4 as decoy answers.
 */
function generate_living_question($mysqli, $quiz) {
    $filter = $quiz->get_filter();
    $q_q = "select pal,pdef,id,stem from all_words3 " .
           "where not isnull(tags) and tags rlike 'charm|cheled|tree|plant|fish' " .
           "and tags not rlike 'fishing' and length(pdef) > 50 and $filter " .
           "order by rand() limit 5";
    $r = query_or_die($mysqli, $q_q);

    $row = $r->fetch_assoc();
    $pal = $row['pal'];
    $pdef = $row['pdef'];
    $correctValue = $row['id'];
    $correctLabel = $pal;

    // Blank out every occurrence of the answer word within its own
    // definition. The original used an <u>...</u> HTML placeholder;
    // this uses plain underscores since the front end renders text
    // safely via textContent rather than innerHTML.
    $prompt = str_replace($pal, '_____', $pdef);

    $labels = [$pal];
    $values = [$correctValue];
    $quiz->add_stem($row['id']);

    while ($row = $r->fetch_assoc()) {
        $labels[] = $row['pal'];
        $values[] = $row['id'];
        $quiz->add_stem($row['id']);
    }

    $pairs = array_map(null, $labels, $values);
    shuffle($pairs);
    $labels = array_column($pairs, 0);
    $values = array_column($pairs, 1);

    $myid = $quiz->asked() + 1;

    return new LivingThingsQuestion($myid, $prompt, $labels, $values, $correctValue, $correctLabel);
}

function question_to_array($question) {
    return [
        'number'       => $question->myid,
        'prompt'       => $question->prompt,
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
