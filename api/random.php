<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db_config.php';

$pdo = new PDO(
  'mysql:host=' . $db_host . ';dbname=' . $database . ';charset=utf8',
  $db_user,
  $db_pwd
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sql = 'SELECT id, pal, eng, pos, pdef
        FROM all_words3
        WHERE pal IS NOT NULL
        AND eng IS NOT NULL
        AND pal != ""
        AND eng != ""
        AND pos != "var."
        AND pos != "cont."
        AND (vulgar IS NULL OR vulgar = 0 OR vulgar = 2)
        ORDER BY RAND()
        LIMIT 1';

$stmt = $pdo->prepare($sql);
$stmt->execute();
$word = $stmt->fetch(PDO::FETCH_ASSOC);

/**
 * Same ID-based word-audio convention as every other page on this site
 * (confirmed via the Results page: 'alii', id 7110, plays from
 * uploads/mp3s/all_words3.pal/7110.mp3) — NOT the {table}.{column} extras
 * convention used for examples/proverbs/sentences.
 */
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

if ($word) {
    $audio_url = word_audio_url($word['id']);
    $word['has_audio'] = $audio_url !== null;
    $word['audio_url'] = $audio_url;
}

echo json_encode($word);
