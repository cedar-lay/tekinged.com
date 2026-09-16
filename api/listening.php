<?php
/**
 * api/listening.php
 *
 * JSON API for the Listening page (formerly "Dorrenges"). Returns a full
 * playlist of "whole.mp3" combined Palauan+English clips for a given mode,
 * rather than one clip per request the way the legacy word.php did via a
 * PHP session. The front-end JS tracks playback position itself and
 * advances locally on each clip's 'ended' event.
 *
 * This is a deliberate architecture change from the original, not just a
 * straight port: word.php relied on $_SESSION to remember the current
 * playlist and position between requests. PHP sessions don't reliably
 * survive a cross-origin request from the Webflow front end to this
 * staging/production API (browser SameSite cookie restrictions), so this
 * endpoint is stateless instead — it hands back the ENTIRE playlist in one
 * call, and the client does the bookkeeping.
 *
 * Usage:
 *   api/listening.php?mode=beginner  -> curated ID range 2402-2929 within
 *                                        upload_sentence.palauan only, in
 *                                        ascending order (NOT shuffled) —
 *                                        matches the original's Beginner set
 *   api/listening.php?mode=advanced  -> every whole.mp3 clip across every
 *                                        subfolder, freshly shuffled on
 *                                        EVERY request — so re-selecting
 *                                        Advanced, or hitting Reset while
 *                                        already in Advanced mode, produces
 *                                        a new shuffle each time
 *
 * No database access needed — this is purely a filesystem glob() over
 * uploads/mp3s/, matching the legacy word.php's random_mp3() logic exactly
 * in terms of which files qualify for each mode.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

function json_error($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

$mode = $_GET['mode'] ?? 'advanced';
$mode = ($mode === 'beginner') ? 'beginner' : 'advanced'; // whitelist; anything unrecognized defaults to advanced

$mp3_root = $_SERVER['DOCUMENT_ROOT'] . '/uploads/mp3s/';

$files = [];

if ($mode === 'beginner') {
    // Matches the original's glob('upload_sentence.palauan/2[4-9]*/whole.mp3')
    // filtered down to the exact 2402-2929 ID range. The original relied on
    // glob()'s natural filesystem ordering; this sorts explicitly so the
    // order doesn't depend on the OS/filesystem's directory listing behavior.
    $matches = glob($mp3_root . 'upload_sentence.palauan/2[4-9]*/whole.mp3');
    foreach ($matches as $path) {
        $parts = explode('/', $path);
        $id_folder = $parts[count($parts) - 2]; // the {id} folder name, just before whole.mp3
        if (is_numeric($id_folder) && $id_folder >= 2402 && $id_folder <= 2929) {
            $files[] = $path;
        }
    }
    sort($files, SORT_NATURAL);
} else {
    // Advanced: every whole.mp3 clip under any {subfolder}/{id}/ pairing,
    // freshly shuffled on every request.
    $files = glob($mp3_root . '*/*/whole.mp3');
    shuffle($files);
}

// Convert absolute filesystem paths back into relative URLs, matching the
// convention every other API file on this project uses (relative path from
// the API, domain prepended by the front-end JS).
$relative_files = array_map(function ($path) use ($mp3_root) {
    return '/uploads/mp3s/' . substr($path, strlen($mp3_root));
}, $files);

if (count($relative_files) === 0) {
    json_error(
        "No audio clips found for mode '$mode' on this server. " .
        "If this is staging, the whole.mp3 clips may not have been copied here yet " .
        "— check for them the same way the uploads/pics/ folder was confirmed missing earlier.",
        404
    );
}

echo json_encode([
    'mode'  => $mode,
    'files' => $relative_files,
]);
