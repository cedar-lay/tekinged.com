<?php
/*
 * api/quiz/synonyms/quiz_classes.php
 *
 * Shared logic for the JSON-based Synonyms quiz API.
 * Adapted from John's quiz2.php + q_synonyms.php.
 *
 * Like Reng, this quiz matches answers by database "id" rather than
 * by visible text, since two different words could coincidentally
 * have overlapping spellings/definitions.
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

function quizlog($mysqli, $pword, $correct, $qtype = 'Synonyms') {
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

function quizzedlog($mysqli, $name, $correct, $total, $qtype = 'Synonyms') {
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
    // Synonyms' original code calls add_stem($word) with the tested
    // word's id, so this filter correctly excludes it from being
    // asked again in the same quiz session.
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

class SynonymQuestion {
    public $myid;
    public $word;         // the headword being tested (pal)
    public $labels;       // 5 candidate synonym words (shuffled), for display
    public $values;       // matching 5 database ids, same order as labels
    public $correctValue; // the id of the actual synonym
    public $correctLabel; // the text of the actual synonym

    public function __construct($myid, $word, $labels, $values, $correctValue, $correctLabel) {
        $this->myid = $myid;
        $this->word = $word;
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

    // Mirrors the original SynQuestion::getQuestion(), which returns
    // the correct answer text rather than the tested word — preserved
    // for log continuity with the other quiz2-engine-based quizzes.
    function getLogLabel() {
        return $this->correctLabel;
    }
}

/**
 * Runs the join query from the original getAnswer() method: finds
 * words in synonyms_populated matching $where (and vulgar=0), then
 * joins to all_words3 to get each match's actual headword text (pal).
 *
 * Returns a list of ['pal' => ..., 'id' => ...] pairs.
 */
function get_synonym_matches($mysqli, $where, $limit, $order = "rand()") {
    $q = "select a.pal as pal, a.id as id, a.pos from all_words3 a inner join " .
         "(select * from (select id,pos from synonyms_populated where $where && vulgar=0 order by rand()) T1 " .
         "order by $order limit $limit) as s ON a.id = s.id;";
    $r = query_or_die($mysqli, $q);
    $matches = [];
    while ($row = $r->fetch_assoc()) {
        $matches[] = ['pal' => $row['pal'], 'id' => $row['id']];
    }
    return $matches;
}

/**
 * Generates one Synonyms question: picks a random headword from
 * synonyms_populated, finds one true synonym from its group as the
 * correct answer, and 4 decoys from other groups (preferring decoys
 * that share the tested word's part of speech, matching the
 * original's "order by pos not like '$pos'" trick).
 */
function generate_synonym_question($mysqli, $quiz) {
    $filter = $quiz->get_filter();

    $q_q = "select mygrouping,id as word,pos,pal from synonyms_populated " .
           "where $filter and vulgar=0 order by rand() limit 1;";
    $r = query_or_die($mysqli, $q_q);
    $row = $r->fetch_assoc();

    $g = $row['mygrouping'];
    $word = $row['word'];
    $pos = $row['pos'];
    $pal = $row['pal'];

    $labels = [];
    $values = [];
    $correctValue = null;
    $correctLabel = null;

    // Correct answer: another word from the same synonym group.
    $whereCorrect = "mygrouping = $g and id != $word";
    $correctMatches = get_synonym_matches($mysqli, $whereCorrect, 1);
    foreach ($correctMatches as $m) {
        $labels[] = $m['pal'];
        $values[] = $m['id'];
        $correctValue = $m['id'];
        $correctLabel = $m['pal'];
    }

    // Decoys: words from different synonym groups, preferring the
    // same part of speech as the tested word.
    $whereDecoys = "mygrouping != $g";
    $orderDecoys = "pos not like '$pos'";
    $decoyMatches = get_synonym_matches($mysqli, $whereDecoys, 4, $orderDecoys);
    foreach ($decoyMatches as $m) {
        $labels[] = $m['pal'];
        $values[] = $m['id'];
    }

    // Shuffle labels and values together so the pairing survives.
    $pairs = array_map(null, $labels, $values);
    shuffle($pairs);
    $labels = array_column($pairs, 0);
    $values = array_column($pairs, 1);

    $quiz->add_stem($word);
    $myid = $quiz->asked() + 1;

    return new SynonymQuestion($myid, $pal, $labels, $values, $correctValue, $correctLabel);
}

function question_to_array($question) {
    return [
        'number'       => $question->myid,
        'word'         => $question->word,
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
