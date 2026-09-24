<?php
/**
 * api/debug_listening.php
 *
 * TEMPORARY diagnostic script — delete once the whole.mp3 glob() mystery is
 * solved. Dumps everything needed to figure out why listening.php's glob()
 * finds zero files even though John confirmed 2733 real matches exist via
 * SSH. Runs as a normal web request, so this sees exactly what the actual
 * PHP web-server process sees — not what an interactive SSH session sees,
 * which is the crux of what we're trying to isolate.
 */

header('Content-Type: text/plain');

echo "=== Basic environment ===\n";
echo "DOCUMENT_ROOT: " . $_SERVER['DOCUMENT_ROOT'] . "\n";
echo "PHP running as user (posix_getpwuid if available): ";
if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
    $u = posix_getpwuid(posix_geteuid());
    echo $u['name'] . "\n";
} else {
    echo "(posix functions not available)\n";
}
echo "open_basedir restriction: " . (ini_get('open_basedir') ?: '(none set)') . "\n\n";

$mp3_root = $_SERVER['DOCUMENT_ROOT'] . '/uploads/mp3s/';
echo "=== Checking mp3_root: $mp3_root ===\n";
echo "is_dir(): " . (is_dir($mp3_root) ? 'YES' : 'NO') . "\n";
echo "is_readable(): " . (is_readable($mp3_root) ? 'YES' : 'NO') . "\n\n";

echo "=== Top-level contents of mp3_root (scandir) ===\n";
if (is_dir($mp3_root)) {
    $top = @scandir($mp3_root);
    if ($top === false) {
        echo "scandir() FAILED (likely a permissions issue)\n";
    } else {
        print_r($top);
    }
} else {
    echo "(skipped — directory not found)\n";
}

echo "\n=== glob() test: exact pattern used in listening.php ===\n";
$pattern = $mp3_root . '*/*/whole.mp3';
echo "Pattern: $pattern\n";
$matches = @glob($pattern);
if ($matches === false) {
    echo "glob() returned FALSE (error)\n";
} else {
    echo "Match count: " . count($matches) . "\n";
    if (count($matches) > 0) {
        echo "First 5 matches:\n";
        print_r(array_slice($matches, 0, 5));
    }
}

echo "\n=== glob() test: one level shallower, in case of a depth mismatch ===\n";
$pattern2 = $mp3_root . '*/whole.mp3';
echo "Pattern: $pattern2\n";
$matches2 = @glob($pattern2);
echo "Match count: " . ($matches2 === false ? 'FALSE (error)' : count($matches2)) . "\n";

echo "\n=== glob() test: a specific known-good subfolder, if one is visible above ===\n";
if (!empty($top)) {
    foreach ($top as $entry) {
        if ($entry !== '.' && $entry !== '..' && is_dir($mp3_root . $entry)) {
            $sub_pattern = $mp3_root . $entry . '/*/whole.mp3';
            $sub_matches = @glob($sub_pattern);
            echo "$entry -> " . ($sub_matches === false ? 'FALSE (error)' : count($sub_matches) . ' matches') . "\n";
        }
    }
}

echo "\n=== Direct file_exists() check on one specific known path ===\n";
echo "(Edit this section with a real path John confirmed exists, e.g.:)\n";
$known_path = $mp3_root . 'upload_sentence.palauan/2415/whole.mp3';
echo "$known_path -> " . (file_exists($known_path) ? 'EXISTS' : 'NOT FOUND') . "\n";
