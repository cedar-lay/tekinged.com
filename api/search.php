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

$query = trim($_GET['q'] ?? '');
$direction = $_GET['dir'] ?? 'pe';

if ($query === '') {
  echo json_encode([]);
  exit;
}

if ($direction === 'ep') {
  $sql = 'SELECT id, pal, eng, pos, pdef
          FROM all_words3
          WHERE eng LIKE ?
          ORDER BY pal
          LIMIT 30';
} else {
  $sql = 'SELECT id, pal, eng, pos, pdef
          FROM all_words3
          WHERE pal LIKE ?
          ORDER BY pal
          LIMIT 30';
}

$stmt = $pdo->prepare($sql);
$stmt->execute(['%' . $query . '%']);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($results);
