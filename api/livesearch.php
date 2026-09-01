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

$input     = trim($_GET['q'] ?? '');
$direction = $_GET['dir'] ?? 'pe';

if (strlen($input) === 0) {
  echo json_encode([]);
  exit;
}

if ($direction === 'ep') {
  $table = 'eng_list';
  $field = 'eng';
} else {
  $table = 'all_words3';
  $field = 'pal';
}

$stmt = $pdo->prepare(
  "SELECT DISTINCT($field) as word FROM $table
   WHERE $field LIKE ?
   AND $field != ''
   ORDER BY $field
   LIMIT 7"
);
$stmt->execute([$input . '%']);
$results = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo json_encode($results);
