<?php
/**
 * api/austro.php
 *
 * JSON API for the "Other Austronesian Books" page — ported from the legacy
 * books/austro.php. Structurally identical to api/books.php: same table
 * shape, same sort/filter approach, same stateless redesign (real
 * ?sort=/?filter= URL params instead of $_SESSION, for the same
 * cross-origin-cookie reason as every other page on this site).
 *
 * The only differences from books.php:
 *   - queries the `austronesian` table instead of `books`
 *   - no `grade` column (doesn't exist on this table)
 *   - the "Browse" column (html field) is dropped, same reasoning as Books:
 *     it links to a legacy web-viewer page that isn't part of this rebuild
 *     and would be a broken link once live.
 *
 * Usage:
 *   api/austro.php                          -> all entries, sorted by title
 *   api/austro.php?sort=year                -> sorted by year (descending — see below)
 *   api/austro.php?filter=Grammar           -> only entries in that category
 *   api/austro.php?sort=year&filter=Grammar -> both combined
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

$mysqli = new mysqli($db_host, $db_user, $db_pwd, $database);
if ($mysqli->connect_error) {
    json_error('Database connection failed', 500);
}

// Whitelist sort fields against actual column names — never interpolate a
// raw $_GET value into ORDER BY. 'html' (Browse) isn't offered as a sort
// option since that column was dropped.
$allowed_sorts = ['title', 'author', 'year', 'category', 'pdf', 'ebook', 'purchase'];
$sort = $_GET['sort'] ?? 'title';
if (!in_array($sort, $allowed_sorts, true)) {
    $sort = 'title';
}

// Same intentional NULL-sorts-last logic as books.php, confirmed correct
// there and ported byte-for-byte: title/author/category sort ascending
// (alphabetical); year/pdf/ebook/purchase sort DESCENDING so that entries
// which actually HAVE a PDF/ebook/purchase link (or a more recent year) are
// pushed to the top, with blanks at the bottom. Not a bug — do not "fix" to
// plain ASC.
$order = in_array($sort, ['title', 'author', 'category'], true) ? 'ASC' : 'DESC';

$filter = $_GET['filter'] ?? null;
if ($filter === '--' || $filter === '') {
    $filter = null;
}

$query = "SELECT * FROM austronesian ORDER BY $sort $order, title ASC";
$result = $mysqli->query($query);
if (!$result) {
    json_error('Query failed: ' . $mysqli->error, 500);
}

// Categories are collected from the FULL unfiltered result set (matching
// the original exactly), so the filter dropdown always offers every
// category regardless of which filter is currently active.
$categories = [];
$entries = [];

while ($row = $result->fetch_assoc()) {
    if (!in_array($row['category'], $categories, true)) {
        $categories[] = $row['category'];
    }

    if ($filter !== null && $filter !== $row['category']) {
        continue;
    }

    $pdf_url = $row['pdf'] ? ('https://tekinged.com/misc/pdf.php?file=' . $row['pdf']) : null;

    $entries[] = [
        'title'    => $row['title'],
        'author'   => $row['author'],
        'year'     => $row['year'],
        'category' => $row['category'],
        'pdf'      => $pdf_url,
        'ebook'    => $row['ebook'] ?: null,
        'purchase' => $row['purchase'] ?: null,
    ];
}

sort($categories);

echo json_encode([
    'sort'       => $sort,
    'filter'     => $filter,
    'categories' => $categories,
    'entries'    => $entries,
]);

$mysqli->close();
