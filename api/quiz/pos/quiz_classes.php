<?php
/*
 * api/quiz/pos/quiz_classes.php
 *
 * Shared logic for the JSON-based Parts of Speech quiz API.
 * Adapted from John's quiz2.php + pos.php.
 *
 * Free-text entry quiz (same UI as Pronouns): given a root word, its
 * part of speech, and its meaning, the user types the requested
 * derived form (a different part of speech built from the same
 * stem). The prompt includes a few illustrative example pairs to
 * make the requested transformation clear.
 *
 * On a wrong answer, a simple plain-text explanation is included
 * showing the root and target words with their parts of speech —
 * a deliberately simplified stand-in for the original site's full
 * dictionary-entry-card explanation (which reuses the same rich
 * rendering system as the main Results page).
 *
 * Must be required BEFORE session_start() (handled internally by
 * start_quiz_session() below).
 */

require_once __DIR__ . '/../../db_config.php';

// ------------------------------------------------------------------
// CORS + session setup (identical pattern to the other quizzes)
// ------------------------------------------------------------------

function send_cors_headers() {
    // No cookies are used by this API (see start_quiz_session() below),
    // so a wildcard origin is safe here and avoids maintaining an
    // allow-list as the site moves between domains.
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function start_quiz_session() {
    // Avoid relying on cookies entirely: some mobile browsers (notably
    // iOS Safari/WebKit, and anything built on it like iOS Chrome/Firefox)
    // block third-party cookies between a Webflow-hosted page and this API
    // domain, which silently breaks the whole quiz. Instead, the client
    // generates/stores a session token itself and sends it explicitly on
    // every request; we use it directly as the PHP session ID rather than
    // transporting it via a cookie at all.
    $token = isset($_POST['session_token']) ? $_POST['session_token'] : (isset($_GET['session_token']) ? $_GET['session_token'] : null);

    // Only accept a well-formed token (PHP session ids are alphanumeric,
    // optionally with "," or "-").
    if ($token && preg_match('/^[a-zA-Z0-9,-]{20,64}$/', $token)) {
        session_id($token);
    }
    // else: no valid token supplied — PHP generates a fresh one below,
    // which quiz_start.php reads via session_id() and returns to the client.

    ini_set('session.use_cookies', 0);
    ini_set('session.use_only_cookies', 0);
    ini_set('session.use_trans_sid', 0);

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

function quizlog($mysqli, $pword, $correct, $qtype = 'Parts of Speech') {
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

function quizzedlog($mysqli, $name, $correct, $total, $qtype = 'Parts of Speech') {
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
    // This quiz calls it with "b.id" since the question query joins
    // all_words3 to itself (aliases a/b) and needs a qualified column.
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

class PosQuestion {
    public $myid;
    public $pal;      // root word
    public $pos;      // root's part of speech
    public $eng;      // root's English meaning
    public $apos;     // the target part of speech being asked for
    public $answer;   // the correct derived-form word (b.pal)
    public $stem;     // b.stem (equals a.id per the join)
    public $aid;      // root word's id (a.id)
    public $wid;       // target word's id (b.id)
    public $examples; // illustrative [one, two] pairs for this pos->apos pattern

    public function __construct($myid, $pal, $pos, $eng, $apos, $answer, $stem, $aid, $wid, $examples) {
        $this->myid = $myid;
        $this->pal = $pal;
        $this->pos = $pos;
        $this->eng = $eng;
        $this->apos = $apos;
        $this->answer = $answer;
        $this->stem = $stem;
        $this->aid = $aid;
        $this->wid = $wid;
        $this->examples = $examples;
    }

    // Matches the original's getQuestion(), which returns $this->answer.
    function getLogLabel() {
        return $this->answer;
    }
}

/**
 * Fuzzy-matches a typed answer against a list of acceptable spellings.
 * Identical to the Pronouns quiz's version.
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
 * Finds a few illustrative root->derived-form pairs sharing the same
 * pos->apos transformation, to show the user what pattern is expected.
 * Mirrors get_example() from pos.php.
 */
function get_pos_examples($mysqli, $pos, $apos) {
    $q = "select a.pal as one, b.pal as two from all_words3 a,all_words3 b " .
         "where a.pos like '$pos' and a.id=b.stem and b.pos like '$apos' " .
         "and a.vulgar=0 and b.vulgar=0 order by rand() limit 5";
    $r = query_or_die($mysqli, $q);
    $examples = [];
    while ($row = $r->fetch_assoc()) {
        $examples[] = ['one' => $row['one'], 'two' => $row['two']];
    }
    return $examples;
}

/**
 * Gathers every acceptable spelling of the correct answer. Preserves
 * the original getPossibleAnswers() query exactly, including its
 * operator-precedence quirk: "stem=$stem and pal like '$answer' or
 * (pos like 'var.' and eng like '$answer')" parses as
 * (stem=$stem AND pal LIKE $answer) OR (pos LIKE 'var.' AND eng LIKE
 * $answer) — the second clause is unscoped to the stem. Kept as-is to
 * match live behavior.
 */
function get_pos_possible_answers($mysqli, $answer, $stem) {
    $answerEsc = $mysqli->real_escape_string($answer);
    $q = "select pal from all_words3 where stem=$stem and pal like '$answerEsc' " .
         "or (pos like 'var.' and eng like '$answerEsc')";
    $r = query_or_die($mysqli, $q);
    $possibles = [];
    while ($row = $r->fetch_array(MYSQLI_NUM)) {
        $possibles[] = $row[0];
    }
    return $possibles;
}

/**
 * Simplified stand-in for the original's explanation()/get_words()/
 * print_words() combo, which renders full dictionary-entry cards.
 * This instead returns one plain-text line: "root (pos): meaning →
 * target (pos)".
 */
function get_pos_explanation($mysqli, $aid, $wid) {
    $q = "select id, pal, pos, eng from all_words3 where id=$aid or id=$wid";
    $r = query_or_die($mysqli, $q);
    $rows = [];
    while ($row = $r->fetch_assoc()) {
        $rows[$row['id']] = $row;
    }

    $parts = [];
    if (isset($rows[$aid])) {
        $root = $rows[$aid];
        $parts[] = $root['pal'] . ' (' . $root['pos'] . ')' . ($root['eng'] ? ': ' . $root['eng'] : '');
    }
    if (isset($rows[$wid])) {
        $target = $rows[$wid];
        $parts[] = $target['pal'] . ' (' . $target['pos'] . ')';
    }

    return implode('  →  ', $parts);
}

/**
 * Generates one Parts of Speech question, mirroring pos_make_question()
 * from pos.php: finds a root word (a) and one of its derived forms (b)
 * with a different part of speech, excluding target words already
 * used this session.
 */
function generate_pos_question($mysqli, $quiz) {
    $field = "b.id";
    $qfilter = $quiz->get_filter($field);
    $q = "select a.pal as pal,a.id as aid,a.pos as pos,a.eng as eng,b.pal as answer,b.pos as apos,b.id as wid,b.stem as stem " .
         "from all_words3 a,all_words3 b " .
         "where a.id=a.stem and b.id!=a.id and b.stem=a.id " .
         "and b.pos not in ('expression', 'var.', 'cont.', 'interj.', 'expr.') " .
         "and a.pal not like b.pal and a.vulgar=0 and b.vulgar=0 and a.pos not like b.pos " .
         "and $qfilter order by rand() limit 1";
    $r = query_or_die($mysqli, $q);
    $row = $r->fetch_assoc();

    $pal = $row['pal'];
    $aid = $row['aid'];
    $pos = $row['pos'];
    $eng = $row['eng'];
    $answer = $row['answer'];
    $apos = $row['apos'];
    $wid = $row['wid'];
    $stem = $row['stem'];

    $quiz->add_stem($wid);
    $myid = $quiz->asked() + 1;

    $examples = get_pos_examples($mysqli, $pos, $apos);

    return new PosQuestion($myid, $pal, $pos, $eng, $apos, $answer, $stem, $aid, $wid, $examples);
}

function build_pos_prompt($question) {
    $prompt = "The Palauan word " . $question->pal . " is a " . $question->pos .
              " meaning " . $question->eng . ".\n\n";
    $prompt .= "Please type the " . $question->apos . " form of " . $question->pal . ".";
    return $prompt;
}

function build_pos_examples_text($question) {
    if (empty($question->examples)) {
        return '';
    }
    $text = "For example:\n";
    foreach ($question->examples as $ex) {
        $text .= "- " . $ex['two'] . " is the " . $question->apos . " form of " . $ex['one'] . "\n";
    }
    return rtrim($text);
}

function question_to_array($question) {
    return [
        'number'     => $question->myid,
        'prompt'     => build_pos_prompt($question),
        'examples'   => build_pos_examples_text($question),
        'answerType' => 'text',
    ];
}

function progress_to_array($status) {
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
