<?php
/**
 * api/grammar/pronunciation.php
 *
 * JSON API for the Pronunciation page — ported from the legacy
 * grammar/pronounce.php. Queries the `sounds` table directly (id, letters,
 * palauan, english), rather than all_words3, so this is a separate, simpler
 * file from wordlists.php rather than another entry in that array.
 *
 * Usage:
 *   api/grammar/pronunciation.php                  -> all rows, ordered by letters
 *   api/grammar/pronunciation.php?filter=ch        -> rows whose letters contain "ch"
 *
 * NOTE: the original page used `letters RLIKE '$filter'` (a MySQL regex
 * match). This uses a plain `LIKE '%filter%'` substring match instead, which
 * covers the normal use case (typing part of a letter/blend) without the
 * injection risk of interpolating user input into a regex pattern. If you
 * need true regex filtering (e.g. matching alternation patterns), this can
 * be changed back to RLIKE with the filter value still escaped.
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

/**
 * Sound audio uses the same "extras" convention as examples/proverbs/
 * sentences — uploads/mp3s/SUBDIR/{id}.mp3 — NOT the ID-based word-audio
 * convention from wordlists.php (uploads/mp3s/all_words3.pal/{id}.mp3).
 * This matches get_mp3_paths()'s 'sounds' case in functions.php exactly
 * (subdir = "sounds.palauan"), so no domain/path debugging expected here —
 * this convention was already confirmed correct for other extras types.
 */
function sound_audio_url($id) {
    if (!is_numeric($id)) {
        return null;
    }
    $subdir = 'sounds.palauan';
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

$query = "SELECT id, letters, palauan, english FROM sounds";
if ($filter !== '') {
    $safe_filter = $mysqli->real_escape_string($filter);
    $query .= " WHERE letters LIKE '%$safe_filter%'";
}
$query .= " ORDER BY letters";

$result = $mysqli->query($query);
if (!$result) {
    json_error('Query failed: ' . $mysqli->error, 500);
}

$entries = [];
$has_any_audio = false;

while ($row = $result->fetch_assoc()) {
    $audio_url = sound_audio_url($row['id']);
    if ($audio_url !== null) {
        $has_any_audio = true;
    }
    $entries[] = [
        'id'          => $row['id'],
        'letters'     => $row['letters'],
        'pal'         => $row['palauan'],
        'explanation' => $row['english'],
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
