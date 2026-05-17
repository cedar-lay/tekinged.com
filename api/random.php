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

$sql = 'SELECT pal, eng, pos, pdef
        FROM all_words3
        WHERE pal IS NOT NULL
        AND eng IS NOT NULL
        AND pal != ""
        AND eng != ""
        AND pos != "var."
        AND pos != "cont."
        ORDER BY RAND()
        LIMIT 1';

$stmt = $pdo->prepare($sql);
$stmt->execute();
$word = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode($word);
