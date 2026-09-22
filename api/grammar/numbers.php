<?php
/**
 * api/grammar/numbers.php
 *
 * JSON API for the Numbers page — ported from the legacy grammar/numbers.php,
 * with one deliberate structural improvement: the original builds its main
 * table from SEVEN independent single-column queries (one per category:
 * human, time, animal, longobj, banana, raft), each just `ORDER BY quantity`
 * on its own, relying on all seven happening to return the same number of
 * rows in the same order to visually line up as a grid. There's no actual
 * join guaranteeing that alignment.
 *
 * This version instead runs ONE query per table and groups by quantity in
 * PHP, so each row is genuinely tied together by its real quantity value,
 * not by coincidence. Output is the same data, just assembled more robustly.
 *
 * Usage:
 *   api/grammar/numbers.php   -> { categories: [...], ordering_counting: [...] }
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

// ---- Table 1: Humans / Time / Animal Objects / Long Objects / Bananas / Rafts ----
// Matches the original's six separate `where <category>=1 order by quantity`
// queries, but as one query grouped by quantity, so columns are guaranteed
// to align by their actual quantity value.
$categories = [];
$cat_result = $mysqli->query(
    "SELECT quantity, palauan, human, time, animal, longobj, banana, raft
     FROM numbers
     WHERE human = 1 OR time = 1 OR animal = 1 OR longobj = 1 OR banana = 1 OR raft = 1
     ORDER BY quantity"
);
if ($cat_result) {
    while ($row = $cat_result->fetch_assoc()) {
        $q = $row['quantity'];
        if (!isset($categories[$q])) {
            $categories[$q] = [
                'quantity' => (int) $q,
                'human'    => null,
                'time'     => null,
                'animal'   => null,
                'longobj'  => null,
                'banana'   => null,
                'raft'     => null,
            ];
        }
        foreach (['human', 'time', 'animal', 'longobj', 'banana', 'raft'] as $flag) {
            if ((int) $row[$flag] === 1) {
                $categories[$q][$flag] = $row['palauan'];
            }
        }
    }
}
ksort($categories, SORT_NUMERIC);
$categories = array_values($categories);

// ---- Table 2: Ordering / Counting (quantity < 11) ----
$ordering_counting = [];
$oc_result = $mysqli->query(
    "SELECT quantity, palauan, ordinal, counting
     FROM numbers
     WHERE (ordinal = 1 OR counting = 1) AND quantity < 11
     ORDER BY quantity"
);
if ($oc_result) {
    while ($row = $oc_result->fetch_assoc()) {
        $q = $row['quantity'];
        if (!isset($ordering_counting[$q])) {
            $ordering_counting[$q] = [
                'quantity' => (int) $q,
                'ordinal'  => null,
                'counting' => null,
            ];
        }
        if ((int) $row['ordinal'] === 1) {
            $ordering_counting[$q]['ordinal'] = $row['palauan'];
        }
        if ((int) $row['counting'] === 1) {
            $ordering_counting[$q]['counting'] = $row['palauan'];
        }
    }
}
ksort($ordering_counting, SORT_NUMERIC);
$ordering_counting = array_values($ordering_counting);

echo json_encode([
    'categories'        => $categories,
    'ordering_counting' => $ordering_counting,
]);

$mysqli->close();
