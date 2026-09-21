<?php
/**
 * api/grammar/contractions.php
 *
 * JSON API for the Contractions page — ported from the legacy
 * grammar/cont.php. Returns a live count plus one "root-only" dictionary
 * card per contraction (pos LIKE 'cont.'), matching show_words(..., false)
 * — grouping disabled, so no subwords/examples/proverbs are ever attached
 * (the legacy page never fetches them in this mode either).
 *
 * Usage:
 *   api/grammar/contractions.php   -> { count, entries: [...] }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../db_config.php';

$GLOBALS['DEBUG'] = false;

function json_error($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

// Same ID-based word-audio convention as wordlists.php / search_full.php.
function word_audio_url($id) {
    if (!is_numeric($id)) {
        return null;
    }
    $subdir = 'all_words3.pal';
    $base = $_SERVER['DOCUMENT_ROOT'] . '/uploads/mp3s/' . $subdir . '/' . $id;
    foreach (['mp3', 'm4a'] as $ext) {
        if (file_exists($base . '.' . $ext)) {
            return '/uploads/mp3s/' . $subdir . '/' . $id . '.' . $ext;
        }
    }
    return null;
}

// Same origin-label mapping as search_full.php's get_origin_label().
function origin_label($origin, $oword) {
    $labels = ['E' => 'English', 'G' => 'German', 'J' => 'Japanese', 'S' => 'Spanish', 'T' => 'Tagalog', 'M' => 'Malay', 'Y' => 'Yapese'];
    if (!$origin || $origin === 'native' || $origin === '') {
        return null;
    }
    $lang = $labels[$origin] ?? $origin;
    $label = 'From ' . $lang;
    if ($oword) {
        $label .= " '" . $oword . "'";
    }
    return $label;
}

$mysqli = new mysqli($db_host, $db_user, $db_pwd, $database);
if ($mysqli->connect_error) {
    json_error('Database connection failed', 500);
}

// Live count, matching get_count('all_words3', "where pos like 'cont.'")
$count = 0;
$count_result = $mysqli->query("SELECT COUNT(1) AS c FROM all_words3 WHERE pos LIKE 'cont.'");
if ($count_result) {
    $count = (int) $count_result->fetch_assoc()['c'];
}

// Matches show_words("select id,stem from all_words3 where pos like 'cont.'", False)
// with grouping OFF: each contraction becomes its own root-only card, then
// any contraction whose stem points at ANOTHER returned contraction is
// trimmed out, keeping only the true root form — mirrors search_full.php's
// no-group logic exactly, simplified since subwords/examples never apply here.
$id_result = $mysqli->query("SELECT id FROM all_words3 WHERE pos LIKE 'cont.'");
if (!$id_result) {
    json_error('Query failed: ' . $mysqli->error, 500);
}

$entries = [];
$roots = [];
$root_stmt = $mysqli->prepare("SELECT pal, pos, eng, pdef, origin, stem, id, oword FROM all_words3 WHERE id = ?");
$stem_pal_stmt = $mysqli->prepare("SELECT pal FROM all_words3 WHERE id = ?");

while ($id_row = $id_result->fetch_assoc()) {
    $id = $id_row['id'];
    $root_stmt->bind_param('i', $id);
    $root_stmt->execute();
    $root_row = $root_stmt->get_result()->fetch_assoc();
    if (!$root_row) {
        continue;
    }

    $audio_url = word_audio_url($root_row['id']);

    // Matches the legacy get_words()'s !group branch exactly: a non-root
    // word gets exactly ONE cross-reference added, pointing back to its own
    // stem/root word (via AddCf($row['stem']) -> id_to_pword() -> Cf, which
    // uppercases the displayed word). A root word gets no cfs at all.
    $cfs = [];
    if ($root_row['stem'] && $root_row['id'] != $root_row['stem']) {
        $stem_id = $root_row['stem'];
        $stem_pal_stmt->bind_param('i', $stem_id);
        $stem_pal_stmt->execute();
        $stem_row = $stem_pal_stmt->get_result()->fetch_assoc();
        if ($stem_row) {
            $cfs[] = ['id' => (int) $stem_id, 'pal' => strtoupper($stem_row['pal'])];
        }
    } else {
        $roots[$root_row['id']] = true;
    }

    $entries[$id] = [
        'id'        => (int) $root_row['id'],
        'pal'       => $root_row['pal'],
        'pos'       => $root_row['pos'],
        'eng'       => $root_row['eng'],
        'pdef'      => $root_row['pdef'],
        'origin'    => origin_label($root_row['origin'], $root_row['oword']),
        'has_audio' => $audio_url !== null,
        'audio_url' => $audio_url,
        'cfs'       => $cfs,
        'stem'      => $root_row['stem'],
    ];
}

$trimmed = [];
foreach ($entries as $entry) {
    if ($entry['stem'] == $entry['id'] || !array_key_exists($entry['stem'], $roots)) {
        unset($entry['stem']); // internal only, not needed by the front end
        $trimmed[] = $entry;
    }
}

// Matches get_words()'s unconditional final sort (entry_sort): alphabetical
// by Palauan word (none of these are pos='expr', so no expr-first branch applies).
usort($trimmed, function ($a, $b) {
    return strcasecmp($a['pal'], $b['pal']);
});

echo json_encode([
    'count'   => $count,
    'entries' => $trimmed,
]);

$mysqli->close();
