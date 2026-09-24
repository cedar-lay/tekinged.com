<?php
/**
 * api/books.php
 *
 * JSON API for the Books page — ported from the legacy books/books.php.
 * Queries the `books` table directly. Sortable and filterable, both as real
 * URL query params (?sort=FIELD&filter=CATEGORY) rather than the original's
 * $_SESSION-based filter persistence — same reasoning as the Listening
 * page: a PHP session on staging isn't reliably readable by a cross-origin
 * request from the Webflow front end, so this is redesigned stateless.
 *
 * Usage:
 *   api/books.php                          -> all books, sorted by title
 *   api/books.php?sort=year                -> sorted by year (descending — see below)
 *   api/books.php?filter=Fiction           -> only books in that category
 *   api/books.php?sort=year&filter=Fiction -> both combined
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
// raw $_GET value into ORDER BY.
$allowed_sorts = ['title', 'author', 'year', 'category', 'grade', 'html', 'pdf', 'ebook', 'purchase'];
$sort = $_GET['sort'] ?? 'title';
if (!in_array($sort, $allowed_sorts, true)) {
    $sort = 'title';
}

// Matches the ORIGINAL exactly, confirmed intentional: title/author/category/
// grade sort ascending (alphabetical). Year/html/pdf/ebook/purchase sort
// DESCENDING — not arbitrary: MySQL sorts NULL last in DESC order, so this
// pushes books that actually HAVE a PDF/ebook/purchase link/browse link (or
// a more recent year) to the top, and blanks to the bottom. This is a
// deliberate presence-first sort, not a bug — do not "fix" to plain ASC.
$order = in_array($sort, ['title', 'author', 'category', 'grade'], true) ? 'ASC' : 'DESC';

$filter = $_GET['filter'] ?? null;
if ($filter === '--' || $filter === '') {
    $filter = null;
}

$query = "SELECT * FROM books ORDER BY $sort $order, title ASC";
$result = $mysqli->query($query);
if (!$result) {
    json_error('Query failed: ' . $mysqli->error, 500);
}

// Categories are collected from the FULL unfiltered result set (matching
// the original exactly), so the filter dropdown always offers every
// category regardless of which filter is currently active.
$categories = [];
$books = [];

while ($row = $result->fetch_assoc()) {
    if (!in_array($row['category'], $categories, true)) {
        $categories[] = $row['category'];
    }

    if ($filter !== null && $filter !== $row['category']) {
        continue;
    }

    $pdf_url = $row['pdf'] ? ('https://tekinged.com/misc/pdf.php?file=' . $row['pdf']) : null;

    $books[] = [
        'title'    => $row['title'],
        'author'   => $row['author'],
        'year'     => $row['year'],
        'category' => $row['category'],
        'grade'    => $row['grade'],
        'html'     => $row['html'] ?: null,
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
    'books'      => $books,
]);

$mysqli->close();
