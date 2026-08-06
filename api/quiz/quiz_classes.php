<?php
/*
 * quiz_classes.php
 *
 * Shared logic for the JSON-based Vocab (Classic) quiz API.
 * Adapted from John's original quiz.php + classic.php so the same
 * question-generation and scoring logic can be reused by three
 * stateless-looking endpoints that still rely on PHP $_SESSION under
 * the hood to keep the correct answer hidden from the browser:
 *
 *   quiz_start.php   - starts a new quiz, returns question #1
 *   quiz_answer.php  - grades an answer, returns next question (or final)
 *   quiz_finish.php  - logs the final score with the player's name
 *
 * IMPORTANT: This file must be required BEFORE session_start() in every
 * endpoint that uses it, so PHP has the Quiz/Question class definitions
 * available when it unserializes them out of the session.
 */

require_once __DIR__ . '/../db_config.php';

$mysqli = new mysqli($db_host, $db_user, $db_pwd, $database);
if ($mysqli->connect_errno) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed.']);
    exit;
}

function query_or_die($mysqli, $sql) {
    $result = $mysqli->query($sql);
    if (!$result) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database query failed.']);
        exit;
    }
    return $result;
}

// Matches not_vulgar() in functions.php exactly.
function not_vulgar() {
    return "(isnull(vulgar) or vulgar=2 or vulgar=0)";
}

// Matches scrape_ip() in functions.php exactly.
function scrape_ip() {
    $server = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : "";
    $proxy  = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : "";
    $user   = isset($_SERVER['REMOTE_USER']) ? $_SERVER['REMOTE_USER'] : "";
    $agent  = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : "";
    return array($server, $proxy, $user, $agent);
}

function quizlog($mysqli, $pword, $correct, $qtype = 'Classic') {
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

function quizzedlog($mysqli, $name, $correct, $total, $qtype = 'Classic') {
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
        // Keep this inside the loop guard because sometimes people
        // refresh/re-post on the "enter name" step.
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

    // NOTE: preserved as-is from quiz.php. The stems added via add_stem()
    // actually come from the "id" column (see generate_question below),
    // but this filter compares against the "stem" column. That mirrors
    // the original production behavior in quiz.php exactly.
    function get_filter() {
        $filter = '(';
        foreach ($this->stems as $stem) {
            $filter .= "stem != '$stem' && ";
        }
        $filter .= " 1)";
        return $filter;
    }

    function correct() {
        return $this->correct;
    }
}

class Question {
    public $answers;
    public $question;
    public $correct;
    public $myid;

    public function __construct($rows, $quiz) {
        $this->answers = array();
        while ($row = $rows->fetch_array(MYSQLI_NUM)) {
            $this->answers[] = $row[1];
            $this->question = $row[0];
            $this->correct = $row[1];
            $quiz->add_stem($row[2]);
        }
        $this->myid = $quiz->asked() + 1;
        shuffle($this->answers);
    }

    function getCorrect() {
        return $this->correct;
    }

    function getQuestion() {
        return $this->question;
    }
}

/**
 * Generates one weighted-random multiple-choice question, exactly
 * mirroring make_question() from classic.php, but returns a Question
 * object instead of echoing HTML.
 */
function generate_question($mysqli, $quiz) {
    $qfilter = $quiz->get_filter();
    $nvulg = not_vulgar();
    $filter = "!isnull(pos) && length(eng)>0 && pos not like 'var.' && pos not like 'abbr.' " .
              "&& pos not like 'mod.' && length(pos)>1 && $nvulg && $qfilter";

    $table = 'qz_eng';

    // Weighted-random pos selection: pick a part-of-speech proportionally
    // to how many qualifying words exist with that pos.
    $all_pos = array();
    $pos_q = "select pos,count(*) as c from $table where $filter group by pos having c>=5";
    $r = query_or_die($mysqli, $pos_q);
    $total = 0;
    while ($row = $r->fetch_array(MYSQLI_NUM)) {
        $all_pos[$row[0]] = $row[1] + $total;
        $total += $row[1];
    }
    $rand = rand(0, $total);
    $pos = 'n.'; // fallback default, matches classic.php
    foreach ($all_pos as $p => $c) {
        if ($rand <= $c) {
            $pos = $p;
            break;
        }
    }

    $pos_escaped = $mysqli->real_escape_string($pos);
    $q_q = "select pal,eng,id from $table where pos like '$pos_escaped' and $filter order by rand() limit 5;";
    $r = query_or_die($mysqli, $q_q);

    return new Question($r, $quiz);
}

function question_to_array($question) {
    return [
        'number'  => $question->myid,
        'word'    => $question->question,
        'options' => $question->answers,
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
