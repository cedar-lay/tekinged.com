<?php
/*
 * api/quiz/etymology/quiz_classes.php
 *
 * Shared logic for the JSON-based Etymology quiz API.
 * Adapted from John's quiz2.php + q_etymology.php.
 *
 * This lives in its own subfolder (api/quiz/etymology/) rather than
 * alongside the Vocab quiz files, since both quiz types need files
 * named quiz_start.php / quiz_answer.php / quiz_finish.php — the
 * subfolder keeps them from colliding.
 *
 * Must be required BEFORE session_start() (handled internally by
 * start_quiz_session() below) so PHP has the Quiz class definition
 * available when it unserializes it out of the session.
 */

require_once __DIR__ . '/../../db_config.php';

// ------------------------------------------------------------------
// CORS + session setup (identical pattern to the Vocab quiz)
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

// Matches scrape_ip() in functions.php exactly.
function scrape_ip() {
    $server = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : "";
    $proxy  = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : "";
    $user   = isset($_SERVER['REMOTE_USER']) ? $_SERVER['REMOTE_USER'] : "";
    $agent  = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : "";
    return array($server, $proxy, $user, $agent);
}

function quizlog($mysqli, $pword, $correct, $qtype = 'Etymology') {
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

function quizzedlog($mysqli, $name, $correct, $total, $qtype = 'Etymology') {
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

// Identical to the Quiz class in quiz.php / quiz2.php.
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

    function correct() {
        return $this->correct;
    }
}

class EtymologyQuestion {
    public $myid;
    public $word;
    public $gloss;
    public $options;
    public $correct; // full language name, e.g. "Japanese"

    public function __construct($myid, $word, $gloss, $options, $correct) {
        $this->myid = $myid;
        $this->word = $word;
        $this->gloss = $gloss;
        $this->options = $options;
        $this->correct = $correct;
    }

    function getCorrect() {
        return $this->correct;
    }

    // NOTE: mirrors the original SynQuestion::getQuestion(), which
    // (likely unintentionally) returns the correct language name
    // rather than the Palauan word — preserved here so score logs
    // stay consistent with existing log_quiz / log_quizzes data.
    function getLogLabel() {
        return $this->correct;
    }
}

/**
 * Generates one Etymology question: a random Palauan word with a
 * known single-letter origin code, multiple choice among the 7 fixed
 * language names. Mirrors SynQuestion's constructor from q_etymology.php.
 *
 * NOTE: like the original, this does NOT avoid repeating words within
 * a single quiz session — $quiz->get_filter() was unused in the
 * original code too, so this preserves that (lack of) behavior.
 */
function generate_etymology_question($mysqli, $quiz) {
    $q_q = "select pal,eng,id,stem,origin from all_words3 " .
           "where not isnull(origin) and length(origin)=1 order by rand() limit 1;";
    $r = query_or_die($mysqli, $q_q);
    $row = $r->fetch_assoc();

    $pal = $row['pal'];
    $eng = rtrim($row['eng'], '.');
    $origin = $row['origin'];

    $languageNames = ['English', 'German', 'Japanese', 'Malay', 'Tagalog', 'Yapese', 'Spanish'];
    sort($languageNames);

    $correctName = null;
    foreach ($languageNames as $name) {
        if (strtoupper($origin) === strtoupper(substr($name, 0, 1))) {
            $correctName = $name;
            break;
        }
    }

    $myid = $quiz->asked() + 1;

    return new EtymologyQuestion($myid, $pal, $eng, $languageNames, $correctName);
}

function question_to_array($question) {
    return [
        'number'  => $question->myid,
        'word'    => $question->word,
        'gloss'   => $question->gloss,
        'options' => $question->options,
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
