<?php
/**
 * api/commonerrors.php
 *
 * JSON API for the Common Errors page — ported from the legacy
 * dosuub/confusion.php. Renamed from confusion.php to commonerrors.php per
 * request, and moved to api/ (top level, not a subfolder).
 *
 * The original page ran one query per group (looping group_id from 1 to
 * MAX(group_id)). This does the equivalent in a single query, grouping the
 * results in PHP afterward — same output, fewer DB round trips.
 *
 * Usage:
 *   api/commonerrors.php   -> all groups, each an array of {word, pos, root}
 *
 * No audio, no filter, no per-group label — matches what was requested:
 * "Group 1" / "Group 2" style labels are intentionally dropped.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db_config.php';

$GLOBALS['DEBUG'] = false;

function json_error($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

$mysqli = new mysqli($db_host, $db_user, $db_pwd, $database);
if ($mysqli->connect_error) {
    json_error('Database connection failed', 500);
}

// Same join as the original page's per-group query, just without the
// group_id = $x restriction — pulls every group's rows at once, ordered so
// they arrive already grouped and sorted exactly as before (by group_id,
// then by pal within each group).
$query = "SELECT c.group_id, a.pal AS word_pal, a.pos AS word_pos,
                 b.pal AS root_pal, b.eng AS root_eng
          FROM all_words3 a, confusion c, all_words3 b
          WHERE c.word = a.id AND a.stem = b.id
          ORDER BY c.group_id, a.pal";

$result = $mysqli->query($query);
if (!$result) {
    json_error('Query failed: ' . $mysqli->error, 500);
}

// Group rows by group_id in PHP, preserving ascending group_id order.
$groups = [];
while ($row = $result->fetch_assoc()) {
    $gid = $row['group_id'];
    if (!isset($groups[$gid])) {
        $groups[$gid] = [];
    }
    $groups[$gid][] = [
        'word' => strtoupper($row['word_pal']),
        'pos'  => $row['word_pos'],
        // Matches the original's concat(upper(b.pal), ': ', b.eng) exactly —
        // a single combined "ROOT: definition" string.
        'root' => strtoupper($row['root_pal']) . ': ' . $row['root_eng'],
    ];
}

ksort($groups, SORT_NUMERIC);
$groups = array_values($groups);

echo json_encode([
    'groups' => $groups,
]);

$mysqli->close();
