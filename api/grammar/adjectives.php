<?php
/**
 * api/grammar/adjectives.php
 *
 * JSON API for the Adjectives page — ported from the legacy
 * grammar/adjectives.php. Combines four independent data sections in one
 * response, since all four are needed on a single page load:
 *
 *   resulting_state_verbs    - 7 random pos='v.r.s.' words, dictionary-card
 *                               style (root-only, with a See Also cross-
 *                               reference back to the word's own stem, same
 *                               logic as api/grammar/contractions.php)
 *   anticipating_state_verbs - same shape, pos='v.a.s.'
 *   noun_adjs                - 7 random rows from the noun_adjs table
 *                               (simple 4-column reference table)
 *   reng_idioms               - 7 random "rengul" idiom entries from
 *                               all_words3 (simple 2-column table, no audio,
 *                               matching the original's plain print_table())
 *
 * Quick Links sidebar intentionally NOT implemented, per request.
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

// Same ID-based word-audio convention as wordlists.php / contractions.php.
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

// Same origin-label mapping as search_full.php / contractions.php.
function origin_label($origin, $oword) {
    $labels = ['E' => 'English', 'G' => 'German', 'J' => 'Japanese', 'S' => 'Spanish', 'T' => 'Tagalog', 'M' => 'Malay', 'Y' => 'Yapese'];
    if (!$origin || $origin === 'native' || $origin === '') {
        return null;
    }
    $lang = $labels[$origin] ?? $origin;
    $label = 'From ' . $lang;
    if ($oword) {
        $label .= " '" . $oword . "'";
    }
    return $label;
}

$mysqli = new mysqli($db_host, $db_user, $db_pwd, $database);
if ($mysqli->connect_error) {
    json_error('Database connection failed', 500);
}

function get_random_ids($mysqli, $where_clause, $limit) {
    $safe_limit = (int) $limit;
    $result = $mysqli->query("SELECT id FROM all_words3 WHERE $where_clause ORDER BY RAND() LIMIT $safe_limit");
    $ids = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $ids[] = $row['id'];
        }
    }
    return $ids;
}

// Same "root-only card + single cfs back-reference to stem" logic as
// contractions.php's main loop, factored out here since both state-verb
// sections need it identically.
function build_root_cards($root_stmt, $stem_pal_stmt, $ids) {
    $entries = [];
    $roots = [];

    foreach ($ids as $id) {
        $root_stmt->bind_param('i', $id);
        $root_stmt->execute();
        $root_row = $root_stmt->get_result()->fetch_assoc();
        if (!$root_row) {
            continue;
        }

        $audio_url = word_audio_url($root_row['id']);

        $cfs = [];
        if ($root_row['stem'] && $root_row['id'] != $root_row['stem']) {
            $stem_id = $root_row['stem'];
            $stem_pal_stmt->bind_param('i', $stem_id);
            $stem_pal_stmt->execute();
            $stem_row = $stem_pal_stmt->get_result()->fetch_assoc();
            if ($stem_row) {
                $cfs[] = ['id' => (int) $stem_id, 'pal' => strtoupper($stem_row['pal'])];
            }
        } else {
            $roots[$root_row['id']] = true;
        }

        $entries[$id] = [
            'id'        => (int) $root_row['id'],
            'pal'       => $root_row['pal'],
            'pos'       => $root_row['pos'],
            'eng'       => $root_row['eng'],
            'pdef'      => $root_row['pdef'],
            'origin'    => origin_label($root_row['origin'], $root_row['oword']),
            'has_audio' => $audio_url !== null,
            'audio_url' => $audio_url,
            'cfs'       => $cfs,
            'stem'      => $root_row['stem'],
        ];
    }

    $trimmed = [];
    foreach ($entries as $entry) {
        if ($entry['stem'] == $entry['id'] || !array_key_exists($entry['stem'], $roots)) {
            unset($entry['stem']);
            $trimmed[] = $entry;
        }
    }

    // get_words() always re-sorts alphabetically at the end (entry_sort),
    // even for a randomly-SELECTED sample -- so the random 7 words still
    // DISPLAY in alphabetical order, not random order.
    usort($trimmed, function ($a, $b) {
        return strcasecmp($a['pal'], $b['pal']);
    });

    return $trimmed;
}

$root_stmt = $mysqli->prepare("SELECT pal, pos, eng, pdef, origin, stem, id, oword FROM all_words3 WHERE id = ?");
$stem_pal_stmt = $mysqli->prepare("SELECT pal FROM all_words3 WHERE id = ?");

// --- Resulting State Verbs ---
$resulting_ids = get_random_ids($mysqli, "pos like 'v.r.s.' and length(eng) > 1", 7);
$resulting_state_verbs = build_root_cards($root_stmt, $stem_pal_stmt, $resulting_ids);

// --- Anticipating State Verbs ---
$anticipating_ids = get_random_ids($mysqli, "pos like 'v.a.s.' and length(eng) > 1", 7);
$anticipating_state_verbs = build_root_cards($root_stmt, $stem_pal_stmt, $anticipating_ids);

// --- State Verbs with Related Nouns (noun_adjs table) ---
// Plain print_table() in the original -- no alphabetical re-sort, stays in
// raw RAND() order. Column is really "Engish_Noun" (missing the "l") in the
// actual schema; queried as-is, given a correctly-spelled key on our side.
$noun_adjs = [];
$na_result = $mysqli->query("SELECT Palauan_Noun, Engish_Noun, Palauan_Adj, English_Adj FROM noun_adjs ORDER BY RAND() LIMIT 7");
if ($na_result) {
    while ($row = $na_result->fetch_assoc()) {
        $noun_adjs[] = [
            'palauan_noun' => $row['Palauan_Noun'],
            'english_noun' => $row['Engish_Noun'],
            'palauan_adj'  => $row['Palauan_Adj'],
            'english_adj'  => $row['English_Adj'],
        ];
    }
}

// --- Reng Idioms ---
// Also plain print_table() -- raw RAND() order, no audio (the original
// never calls audio_button() for this section).
$reng_idioms = [];
$reng_result = $mysqli->query("SELECT pal, eng FROM all_words3 WHERE pal RLIKE 'rengul' AND LENGTH(pal) > 5 AND eng IS NOT NULL ORDER BY RAND() LIMIT 7");
if ($reng_result) {
    while ($row = $reng_result->fetch_assoc()) {
        $reng_idioms[] = [
            'pal' => $row['pal'],
            'eng' => $row['eng'],
        ];
    }
}

echo json_encode([
    'resulting_state_verbs'    => $resulting_state_verbs,
    'anticipating_state_verbs' => $anticipating_state_verbs,
    'noun_adjs'                => $noun_adjs,
    'reng_idioms'              => $reng_idioms,
]);

$mysqli->close();
