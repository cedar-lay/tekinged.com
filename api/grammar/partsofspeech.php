<?php
/**
 * api/grammar/partsofspeech.php
 *
 * JSON API for the Parts of Speech page — ported from the legacy
 * grammar/pos.php. Returns every part-of-speech type from the `pos` table,
 * each joined to ONE randomly selected example word from all_words3.
 *
 * Usage:
 *   api/grammar/partsofspeech.php   -> all parts of speech, each with a
 *                                       fresh random example on every call
 *                                       (matches the original page's
 *                                       intentional "refreshes every visit"
 *                                       behavior — no caching/pinning here)
 *
 * IMPORTANT: this query relies on MySQL's non-standard GROUP BY extension —
 * grouping by a.pos after ordering the subquery by RAND() picks an
 * arbitrary (effectively random) row per group, which only works if
 * ONLY_FULL_GROUP_BY is not being enforced on this server. This is ported
 * exactly as the legacy site's live query, since that's confirmed to be
 * how it already behaves in production — not something introduced here.
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

$mysqli = new mysqli($db_host, $db_user, $db_pwd, $database);
if ($mysqli->connect_error) {
    json_error('Database connection failed', 500);
}

// Ported directly from the legacy pos.php query, unchanged.
$query = "SELECT B.part AS Type, B.explanation AS Explanation, C.pal AS Example, C.eng AS Definition
          FROM (SELECT part, explanation FROM pos) AS B
          INNER JOIN (
              SELECT a.pal, a.pos, a.eng
              FROM (SELECT * FROM all_words3 b WHERE LENGTH(eng) > 0 ORDER BY RAND()) a
              GROUP BY a.pos
              ORDER BY a.pos
          ) C
          ON B.part LIKE C.pos
          ORDER BY B.part";

$result = $mysqli->query($query);
if (!$result) {
    json_error('Query failed: ' . $mysqli->error, 500);
}

$entries = [];
while ($row = $result->fetch_assoc()) {
    $entries[] = [
        'type'        => $row['Type'],
        'explanation' => $row['Explanation'],
        'example'     => $row['Example'],
        'definition'  => $row['Definition'],
    ];
}

echo json_encode([
    'entries' => $entries,
]);

$mysqli->close();
