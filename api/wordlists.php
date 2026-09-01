<?php
/**
 * api/wordlists.php
 *
 * JSON API for the Wordlist template page. Ported from the logic in
 * show_words.php + the full $interesting array in functions.php (46 entries).
 *
 * Usage: api/wordlists.php?lookup=color
 *
 * This file does NOT modify functions.php or any existing PHP — it is a
 * new, standalone file, consistent with the rest of the API layer.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // tighten to your Webflow domain(s) if desired
require_once __DIR__ . '/db_config.php';

$GLOBALS['DEBUG'] = false;

/**
 * ==========================================================================
 * Ported directly from $GLOBALS['interesting'] in functions.php.
 * Each entry is:
 *
 *   'key' => [ $where_clause_fragment, $label, $method ]
 *
 * Methods:
 *   'words' -> simple query against all_words3, NO image/root word column
 *   'page'  -> not a real wordlist; it's a redirect to a legacy page.
 *              Excluded from API routing below — link to it directly from
 *              the homepage dropdown instead of through /wordlist.
 *   'table' -> (the vast majority) join query with image + root word support
 * ==========================================================================
 */
$interesting = [
    'animal'   => ["a.tags regexp 'bird|cheled|charm|malk'",             'Animals',                 'table'],
    'daob'     => ["a.tags rlike 'daob'",                                'Areas of the Ocean',      'table'],
    'affix'    => ["pos regexp 'prefix|suffix'",                         'Affixes',                 'words'],
    'baby'     => ["a.pos regexp 'baby'",                                'Baby Words',              'table'],
    'bananas'  => ["tags regexp 'banana'",                               'Bananas',                 'words'],
    'birds'    => ["a.tags rlike 'bird'",                                'Birds',                   'table'],
    'body'     => ["a.tags rlike 'body'",                                'Body Parts',              'table'],
    'english'  => ["a.origin not rlike 'native' and a.origin rlike 'E'", 'Borrowed English',        'table'],
    'german'   => ["a.origin rlike 'G'",                                 'Borrowed German',         'table'],
    'japan'    => ["a.origin rlike 'J'",                                 'Borrowed Japanese',       'table'],
    'malay'    => ["a.origin rlike 'M'",                                 'Borrowed Malay',          'table'],
    'spanish'  => ["a.origin rlike 'S'",                                 'Borrowed Spanish',        'table'],
    'tagalog'  => ["a.origin not rlike 'native' and a.origin rlike 'T'", 'Borrowed Tagalog',        'table'],
    'buil'     => ["a.tags rlike 'buil'",                                'Buil (moon words)',       'table'],
    'cerem'    => ["a.tags rlike 'ceremony'",                            'Ceremonies',              'table'],
    'cheled'   => ["a.tags rlike 'cheled'",                              'Cheled (sea food)',       'table'],
    'malk'     => ["a.tags rlike 'malk'",                                'Chickens',                'table'],
    'color'    => ["a.tags rlike 'color'",                               'Colors',                  'table'],
    'contr'    => ["a.pos like 'cont.'",                                 'Contractions',            'table'],
    'quiz'     => ["pal in (select Palauan from quiz_hard) limit 999",   'Difficult Quiz Words',    'words'],
    'chief'    => ["a.tags rlike 'chief'",                               'Dui (titles)',            'table'],
    'fish'     => ["a.tags rlike 'fish' and a.tags not rlike 'fishing'", 'Fish',                    'table'],
    'fishing'  => ["a.tags rlike 'fishing'",                             'Fishing Terms',           'table'],
    'flowers'  => ["a.tags rlike 'bung'",                                'Flowers',                 'table'],
    'game'     => ["a.tags rlike 'game'",                                'Games',                   'table'],
    'greet'    => ["a.tags rlike 'greet'",                               'Greetings',               'table'],
    'kinship'  => ["a.tags rlike 'kin'",                                 'Kinship',                 'table'],
    'legends'  => ["a.tags rlike 'legend'",                              'Legends',                 'table'],
    'joseph'   => ["not isnull(a.josephs) and a.josephs!=1",             'New Words Since Josephs', 'table'],
    'numbers'  => ["/grammar/numbers.php",                               'Numbers',                 'page'],
    'odor'     => ["a.tags rlike 'odor'",                                'Odors',                   'table'],
    'mlai'     => ["a.tags rlike 'mlai'",                                'Parts of Boats',          'table'],
    'blai'     => ["a.tags rlike 'blai'",                                'Parts of Houses',         'table'],
    'place'    => ["a.tags rlike 'place'",                               'Places',                  'table'],
    'plants'   => ["a.tags regexp 'bung|plant'",                         'Plants',                  'table'],
    'prefix'   => ["pos regexp 'prefix'",                                'Prefixes',                'words'],
    'reng'     => ["a.tags rlike 'reng'",                                'Reng Phrases',            'table'],
    'shapes'   => ["a.tags rlike 'shape'",                               'Shapes',                  'table'],
    'suffix'   => ["pos regexp 'suffix'",                                'Suffixes',                'words'],
    'flags'    => ["a.tags rlike 'flag'",                                'State Flags',             'table'],
    'chuodel'  => ["a.pdef rlike 'chuodel el ngklel'",                   'State Old Names',         'table'],
    'terms'    => ["a.tags rlike 'address'",                             'Terms of Address',        'table'],
    'pdef'     => ["not isnull(a.pdef)",                                 'Tekoi ma Omesodel',       'table'],
    'trees'    => ["a.tags rlike 'tree'",                                'Trees',                   'table'],
    'money'    => ["a.tags rlike 'money'",                               'Udoud (money)',           'table'],
    'pictures' => ["a.id in (select allwid from pictures where uploaded=1)", 'Words with Pictures',  'table'],
];

function json_error($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

function pics_dir() {
    // Matches functions.php's pics_dir() exactly.
    return '/uploads/pics';
}

function pic_url_for_id($id) {
    if (!is_numeric($id)) {
        return null;
    }
    $pdir = $_SERVER['DOCUMENT_ROOT'] . pics_dir();
    $pic_path = $pdir . '/' . $id . '.jpg';
    if (file_exists($pic_path)) {
        return pics_dir() . '/' . $id . '.jpg';
    }
    return null;
}

/**
 * Checks for simple word audio (e.g. alii.mp3), matching the convention
 * described in the technical docs: files live at DOCUMENT_ROOT/mp3s/WORD.mp3
 * (or .m4a), named after the exact Palauan word text.
 *
 * NOTE: the original has_word_audio() implementation isn't in the functions.php
 * excerpt available here, so this is a best-effort port of the documented
 * convention. If filenames use different sanitization (lowercase, no
 * diacritics, underscores for spaces, etc.), this may need adjusting to match
 * exactly how the real mp3s/ files are named once that folder is on staging.
 */
function word_audio_url($word) {
    if (!$word) {
        return null;
    }
    $dir = $_SERVER['DOCUMENT_ROOT'] . '/mp3s/';
    foreach (['mp3', 'm4a'] as $ext) {
        if (file_exists($dir . $word . '.' . $ext)) {
            return '/mp3s/' . rawurlencode($word) . '.' . $ext;
        }
    }
    return null;
}

// ---- Read the requested list ----
$lookup = $_GET['lookup'] ?? null;
if (!$lookup) {
    json_error('Missing lookup parameter');
}

if (!isset($interesting[$lookup])) {
    json_error("Unknown wordlist: $lookup", 404);
}

$config = $interesting[$lookup];
$where_or_query = $config[0];
$label = trim($config[1]); // a couple of source labels have trailing spaces
$method = $config[2];
$custom = $config[3] ?? null;

if ($method === 'page') {
    // Not a real wordlist page ('numbers' is the only current example) —
    // shouldn't be routed through this API. Link to it directly instead.
    json_error("'$lookup' is a redirect entry, not a wordlist", 400);
}

$mysqli = new mysqli($db_host, $db_user, $db_pwd, $database);
if ($mysqli->connect_error) {
    json_error('Database connection failed', 500);
}

$entries = [];

if ($method === 'words') {
    // Simple query — no image, no root word (affix, bananas, prefix, suffix, quiz).
    // NOTE: matches show_words.php exactly — no ORDER BY appended here, because
    // the 'quiz' entry's where-clause already ends in "limit 999" and MySQL
    // requires ORDER BY to come before LIMIT, not after.
    $query = "SELECT id, stem, pos, pal, eng, pdef FROM all_words3 WHERE $where_or_query";
    $result = $mysqli->query($query);
    if (!$result) {
        json_error('Query failed: ' . $mysqli->error, 500);
    }
    while ($row = $result->fetch_assoc()) {
        $audio_url = word_audio_url($row['pal']);
        $entries[] = [
            'id'     => $row['id'],
            'pic'    => null,
            'pal'    => $row['pal'],
            'pos'    => $row['pos'],
            'eng'    => $row['eng'],
            'pdef'   => $row['pdef'],
            'root'   => null,
            'has_image' => false,
            'has_root'  => false,
            'has_audio' => $audio_url !== null,
            'audio_url' => $audio_url,
        ];
    }
} else {
    // Default / custom: join query with root word + image support
    if ($custom) {
        $query = $custom;
    } else {
        $query = "SELECT a.id AS Pic, a.pal AS Palauan, a.pos AS Type, a.eng AS English,
                          a.pdef AS Omesodel, b.pal AS RootWord
                   FROM all_words3 a, all_words3 b
                   WHERE $where_or_query AND a.stem = b.id
                   ORDER BY a.pal";
    }
    $result = $mysqli->query($query);
    if (!$result) {
        json_error('Query failed: ' . $mysqli->error, 500);
    }
    while ($row = $result->fetch_assoc()) {
        $pic_url = pic_url_for_id($row['Pic']);
        $audio_url = word_audio_url($row['Palauan']);
        $entries[] = [
            'id'        => $row['Pic'],
            'pic'       => $pic_url,
            'pal'       => $row['Palauan'],
            'pos'       => $row['Type'],
            'eng'       => $row['English'],
            'pdef'      => $row['Omesodel'],
            'root'      => $row['RootWord'],
            'has_image' => $pic_url !== null,
            'has_root'  => true,
            'has_audio' => $audio_url !== null,
            'audio_url' => $audio_url,
        ];
    }
}

// Column-level visibility flags: hide a column's header entirely (not just
// individual cells) when NOTHING in this particular list has that data.
$has_any_image = false;
$has_any_root = false;
$has_any_audio = false;
foreach ($entries as $e) {
    if ($e['has_image']) { $has_any_image = true; }
    if ($e['has_root'] && $e['root']) { $has_any_root = true; }
    if ($e['has_audio']) { $has_any_audio = true; }
}

echo json_encode([
    'lookup'        => $lookup,
    'label'         => $label,
    'has_any_image' => $has_any_image,
    'has_any_root'  => $has_any_root,
    'has_any_audio' => $has_any_audio,
    'entries'       => $entries,
]);

$mysqli->close();
