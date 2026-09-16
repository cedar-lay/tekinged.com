<?php
/**
 * api/phrases.php
 *
 * JSON API for the Common Phrases page — ported from the legacy
 * dosuub/phrases.php. Queries the `sentences` table (a curated subset,
 * filtered to Source RLIKE 'Debbie' — a fixed, non-user-adjustable filter
 * baked into the original page, not exposed here as a query param).
 *
 * Usage:
 *   api/phrases.php   -> all curated phrases, ordered by id. No filter box
 *                         on this page — matches the original, which had none.
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
 * IMPORTANT: audio for this page lives in uploads/mp3s/upload_sentence.palauan/
 * — the SAME subfolder used elsewhere for the separate `upload_sentence`
 * table (search_full.php's "More Examples" section) — even though this
 * page's own SQL table is `sentences`, a different table entirely. This
 * matches audio_button('upload_sentence', $id) in the legacy PHP exactly.
 * It is not a mistake to "fix" — just how the original site organized its
 * audio folders — so this is intentionally NOT named sentences.palauan.
 */
function phrase_audio_url($id) {
    if (!is_numeric($id)) {
        return null;
    }
    $subdir = 'upload_sentence.palauan';
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

// Hardcoded filter, matching the original page exactly — this page always
// shows only the curated "Debbie" source subset, not a user-adjustable filter.
// Column names (Palauan, English, Source) are capitalized here to match the
// `sentences` table's actual schema, unlike lowercase columns elsewhere.
$query = "SELECT id, Palauan, English FROM sentences WHERE Source RLIKE 'Debbie' ORDER BY id";

$result = $mysqli->query($query);
if (!$result) {
    json_error('Query failed: ' . $mysqli->error, 500);
}

$entries = [];
$has_any_audio = false;

while ($row = $result->fetch_assoc()) {
    $audio_url = phrase_audio_url($row['id']);
    if ($audio_url !== null) {
        $has_any_audio = true;
    }
    $entries[] = [
        'id'        => $row['id'],
        'pal'       => $row['Palauan'],
        'eng'       => $row['English'],
        'has_audio' => $audio_url !== null,
        'audio_url' => $audio_url,
    ];
}

echo json_encode([
    'has_any_audio' => $has_any_audio,
    'entries'       => $entries,
]);

$mysqli->close();
