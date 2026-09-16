<?php
/**
 * api/proverbs.php
 *
 * JSON API for the Proverbs page — ported from the legacy misc/proverbs.php.
 * Queries the `proverbs` table directly (id, palauan, english, explanation).
 *
 * Usage:
 *   api/proverbs.php                  -> all rows (no ORDER BY, matching the
 *                                         original page's behavior — it never
 *                                         specified one either)
 *   api/proverbs.php?filter=chelebed  -> rows where palauan, english, OR
 *                                         explanation contains the filter text
 *
 * NOTE: the original page used `RLIKE '$filter'` (a MySQL regex match) across
 * all three columns. This uses a plain `LIKE '%filter%'` substring match on
 * each column instead — same reasoning as pronunciation.php: avoids the
 * injection risk of interpolating raw user input into a regex pattern, and
 * covers the normal use case of typing a partial word/phrase.
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

/**
 * Proverb audio uses the same "extras" convention as examples/sentences/pdef
 * — uploads/mp3s/SUBDIR/{id}.mp3 — matching get_mp3_paths()'s 'proverb' case
 * in functions.php exactly (subdir = "proverbs.palauan"). This convention
 * has already been confirmed correct for other extras types, so no
 * path/domain debugging expected here.
 */
function proverb_audio_url($id) {
    if (!is_numeric($id)) {
        return null;
    }
    $subdir = 'proverbs.palauan';
    $base = $_SERVER['DOCUMENT_ROOT'] . '/uploads/mp3s/' . $subdir . '/' . $id;
    foreach (['mp3', 'm4a'] as $ext) {
        if (file_exists($base . '.' . $ext)) {
            return '/uploads/mp3s/' . $subdir . '/' . $id . '.' . $ext;
        }
    }
    return null;
}

$mysqli = new mysqli($db_host, $db_user, $db_pwd, $database);
if ($mysqli->connect_error) {
    json_error('Database connection failed', 500);
}

$filter = trim($_GET['filter'] ?? '');

$query = "SELECT id, palauan, english, explanation FROM proverbs";
if ($filter !== '') {
    $safe_filter = $mysqli->real_escape_string($filter);
    $query .= " WHERE palauan LIKE '%$safe_filter%'"
             . " OR english LIKE '%$safe_filter%'"
             . " OR explanation LIKE '%$safe_filter%'";
}
// No ORDER BY — matches the original page, which never specified one either.

$result = $mysqli->query($query);
if (!$result) {
    json_error('Query failed: ' . $mysqli->error, 500);
}

$entries = [];
$has_any_audio = false;

while ($row = $result->fetch_assoc()) {
    $audio_url = proverb_audio_url($row['id']);
    if ($audio_url !== null) {
        $has_any_audio = true;
    }
    $entries[] = [
        'id'          => $row['id'],
        'pal'         => $row['palauan'],
        'eng'         => $row['english'],
        'explanation' => $row['explanation'],
        'has_audio'   => $audio_url !== null,
        'audio_url'   => $audio_url,
    ];
}

echo json_encode([
    'filter'        => $filter,
    'has_any_audio' => $has_any_audio,
    'entries'       => $entries,
]);

$mysqli->close();
