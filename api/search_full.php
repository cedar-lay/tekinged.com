<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db_config.php';

// ============================================================
// DATABASE CONNECTION
// ============================================================

try {
  $pdo = new PDO(
    'mysql:host=' . $db_host . ';dbname=' . $database . ';charset=utf8',
    $db_user,
    $db_pwd
  );
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
  echo json_encode(['error' => 'Database connection failed']);
  exit;
}

// ============================================================
// INPUT
// ============================================================

$query  = trim($_GET['q'] ?? '');
$direction = $_GET['dir'] ?? 'pe';

if ($query === '') {
  echo json_encode([]);
  exit;
}

// ============================================================
// HELPER: check if a simple word mp3 exists on the server
// The simple word mp3s live at SITE_ROOT/mp3s/WORD.mp3
// ============================================================

function has_word_audio($pal) {
  $path = $_SERVER['DOCUMENT_ROOT'] . '/mp3s/' . $pal . '.mp3';
  // Also check m4a
  $path_m4a = $_SERVER['DOCUMENT_ROOT'] . '/mp3s/' . $pal . '.m4a';
  return file_exists($path) || file_exists($path_m4a);
}

// ============================================================
// HELPER: check if an example/proverb mp3 exists
// These live at SITE_ROOT/uploads/mp3s/SUBDIR/ID.mp3
// ============================================================

function has_extra_audio($type, $id) {
  $subdirs = [
    'example'         => 'examples.palauan',
    'proverb'         => 'proverbs.palauan',
    'upload_sentence' => 'upload_sentence.palauan',
  ];
  if (!isset($subdirs[$type])) return false;
  $path = $_SERVER['DOCUMENT_ROOT'] . '/uploads/mp3s/' . $subdirs[$type] . '/' . $id . '.mp3';
  return file_exists($path);
}

// ============================================================
// HELPER: get audio URL for examples/proverbs
// ============================================================

function get_extra_audio_url($type, $id) {
  $subdirs = [
    'example'         => 'examples.palauan',
    'proverb'         => 'proverbs.palauan',
    'upload_sentence' => 'upload_sentence.palauan',
  ];
  if (!isset($subdirs[$type])) return null;
  return '/uploads/mp3s/' . $subdirs[$type] . '/' . $id . '.mp3';
}

// ============================================================
// HELPER: word origin label
// ============================================================

function get_origin_label($origin, $oword) {
  $labels = [
    'E' => 'English',
    'G' => 'German',
    'J' => 'Japanese',
    'S' => 'Spanish',
    'T' => 'Tagalog',
    'M' => 'Malay',
    'Y' => 'Yapese',
  ];
  if (!$origin || $origin === 'native' || $origin === '') return null;
  $lang = $labels[$origin] ?? $origin;
  $label = 'From ' . $lang;
  if ($oword) $label .= ' \'' . $oword . '\'';
  return $label;
}

// ============================================================
// HELPER: sort subentries (paradigm ordering)
// Matches John's subentry_sort() logic exactly
// ============================================================

function sort_subentries($words) {
  usort($words, function($a, $b) {
    $a_pal = $a['pal'];
    $b_pal = $b['pal'];
    $a_pos = $a['pos'] ?? '';
    $b_pos = $b['pos'] ?? '';

    if (empty($a_pos) || empty($b_pos)) {
      return strcmp($a_pal, $b_pal);
    }

    $a_vpf  = strpos($a_pos, 'v.pf.')  !== false;
    $b_vpf  = strpos($b_pos, 'v.pf.')  !== false;
    $a_nposs = strpos($a_pos, 'n.poss.') !== false;
    $b_nposs = strpos($b_pos, 'n.poss.') !== false;
    $a_expr = strpos($a_pos, 'expr')   !== false;
    $b_expr = strpos($b_pos, 'expr')   !== false;
    $a_vpf_any  = strpos($a_pos, 'v.pf') !== false;
    $b_vpf_any  = strpos($b_pos, 'v.pf') !== false;
    $a_nposs_any = strpos($a_pos, 'n.poss') !== false;
    $b_nposs_any = strpos($b_pos, 'n.poss') !== false;

    if ($a_vpf && $b_vpf) {
      return pos_compare($a_pos, $b_pos, 5);
    } else if ($a_nposs && $b_nposs) {
      return pos_compare($a_pos, $b_pos, 7);
    } else if ($a_vpf_any) {
      return -1;
    } else if ($b_vpf_any) {
      return 1;
    } else if ($a_nposs_any) {
      return -1;
    } else if ($b_nposs_any) {
      return 1;
    } else if ($a_expr && $b_expr) {
      return strcmp($a_pal, $b_pal);
    } else if ($a_expr) {
      return 1;
    } else if ($b_expr) {
      return -1;
    } else {
      return strcmp($a_pal, $b_pal);
    }
  });
  return $words;
}

// ============================================================
// HELPER: pos_compare for paradigm sorting (1s/2s/3s/1p/2p/3p)
// ============================================================

function pos_compare($a_pos, $b_pos, $offset) {
  $order = ['1s' => 0, '2s' => 1, '3s' => 2, '1p' => 3, '2p' => 4, '3p' => 5];
  $a_obj = substr($a_pos, $offset, 2);
  $b_obj = substr($b_pos, $offset, 2);
  $a_idx = $order[$a_obj] ?? 99;
  $b_idx = $order[$b_obj] ?? 99;
  if ($a_idx === $b_idx) {
    return strlen($a_pos) < strlen($b_pos) ? -1 : 1;
  }
  return $a_idx < $b_idx ? -1 : 1;
}

// ============================================================
// HELPER: sort top-level entries
// Expressions first, then alphabetical — matches entry_sort()
// ============================================================

function sort_entries($entries) {
  usort($entries, function($a, $b) {
    $a_pos = $a['pos'] ?? '';
    $b_pos = $b['pos'] ?? '';
    $a_expr = strpos($a_pos, 'expr') !== false;
    $b_expr = strpos($b_pos, 'expr') !== false;

    if ($a_expr && $b_expr) {
      return strcasecmp($a['pal'], $b['pal']);
    } else if ($a_expr) {
      return -1;
    } else if ($b_expr) {
      return 1;
    } else {
      return strcasecmp($a['pal'], $b['pal']);
    }
  });
  return $entries;
}

// ============================================================
// HELPER: merge variants (var.) into their parent entries
// ============================================================

function merge_variants($root, $subwords) {
  $vars  = [];
  $words = [];

  foreach ($subwords as $word) {
    if ($word['pos'] === 'var.') {
      $vars[] = $word;
    } else {
      $words[$word['pal']][] = $word;
    }
  }

  foreach ($vars as $var) {
    $ori = $var['eng']; // the variant's "definition" is the word it's a variant of
    if (isset($words[$ori])) {
      foreach ($words[$ori] as &$word) {
        // Append variant spelling with slash
        $word['pal'] = $word['pal'] . '/' . $var['pal'];
        // Merge pdef if both have one
        if ($var['pdef'] && $word['pdef']) {
          $word['pdef'] .= '<br>' . $var['pdef'];
        } else if ($var['pdef']) {
          $word['pdef'] = $var['pdef'];
        }
      }
    } else {
      // Variant of the root word itself
      $root['pal'] = $root['pal'] . '/' . $var['pal'];
      if ($var['pdef'] && $root['pdef']) {
        $root['pdef'] .= '<br>' . $var['pdef'];
      } else if ($var['pdef']) {
        $root['pdef'] = $var['pdef'];
      }
    }
  }

  // Flatten back to array
  $merged = [];
  foreach ($words as $word_array) {
    foreach ($word_array as $word) {
      $merged[] = $word;
    }
  }

  return [$root, $merged];
}

// ============================================================
// HELPER: get examples for an entry
// ============================================================

function get_examples($pdo, $stem_id, $word_pals) {
  // Build WHERE clause matching John's extraQuery logic:
  // match by word appearing in palauan column OR by stem field
  $conditions = ['stem = :stem_id'];
  $params = [':stem_id' => $stem_id];
  $i = 0;
  foreach (array_slice($word_pals, 0, 15) as $pal) {
    $key = ':pal' . $i;
    $conditions[] = "lower(palauan) regexp lower(:regex$i)";
    $params[':regex' . $i] = '[[:<:]]' . preg_quote($pal, '/') . '[[:>:]]';
    $i++;
  }
  $where = implode(' OR ', $conditions);
  $sql = "SELECT id, palauan, english FROM examples WHERE $where ORDER BY RAND() LIMIT 5";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $results = [];
  foreach ($rows as $row) {
    $has_audio = has_extra_audio('example', $row['id']);
    $results[] = [
      'id'        => $row['id'],
      'palauan'   => $row['palauan'],
      'english'   => $row['english'],
      'has_audio' => $has_audio,
      'audio_url' => $has_audio ? get_extra_audio_url('example', $row['id']) : null,
    ];
  }
  return $results;
}

// ============================================================
// HELPER: get proverbs for an entry
// ============================================================

function get_proverbs($pdo, $stem_id, $word_pals) {
  $conditions = ['stem = :stem_id'];
  $params = [':stem_id' => $stem_id];
  $i = 0;
  foreach (array_slice($word_pals, 0, 15) as $pal) {
    $conditions[] = "lower(palauan) regexp lower(:regex$i)";
    $params[':regex' . $i] = '[[:<:]]' . preg_quote($pal, '/') . '[[:>:]]';
    $i++;
  }
  $where = implode(' OR ', $conditions);
  $sql = "SELECT id, palauan, english, explanation FROM proverbs WHERE $where ORDER BY RAND() LIMIT 5";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $results = [];
  foreach ($rows as $row) {
    $has_audio = has_extra_audio('proverb', $row['id']);
    $results[] = [
      'id'          => $row['id'],
      'palauan'     => $row['palauan'],
      'english'     => $row['english'],
      'explanation' => $row['explanation'],
      'has_audio'   => $has_audio,
      'audio_url'   => $has_audio ? get_extra_audio_url('proverb', $row['id']) : null,
    ];
  }
  return $results;
}

// ============================================================
// HELPER: get user-contributed sentences for an entry
// ============================================================

function get_sentences($pdo, $stem_id, $word_pals) {
  $conditions = ['stem = :stem_id'];
  $params = [':stem_id' => $stem_id];
  $i = 0;
  foreach (array_slice($word_pals, 0, 15) as $pal) {
    $conditions[] = "lower(palauan) regexp lower(:regex$i)";
    $params[':regex' . $i] = '[[:<:]]' . preg_quote($pal, '/') . '[[:>:]]';
    $i++;
  }
  $where = implode(' OR ', $conditions);
  $sql = "SELECT id, palauan, eng FROM upload_sentence WHERE $where ORDER BY RAND() LIMIT 5";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $results = [];
  foreach ($rows as $row) {
    $has_audio = has_extra_audio('upload_sentence', $row['id']);
    $results[] = [
      'id'        => $row['id'],
      'palauan'   => $row['palauan'],
      'english'   => $row['eng'],
      'has_audio' => $has_audio,
      'audio_url' => $has_audio ? get_extra_audio_url('upload_sentence', $row['id']) : null,
    ];
  }
  return $results;
}

// ============================================================
// HELPER: get cross-references (see also) for an entry
// ============================================================

function get_cfs($pdo, $stem_id, $word_ids) {
  $all_ids = array_merge([$stem_id], $word_ids);
  $placeholders = implode(',', array_fill(0, count($all_ids), '?'));
  $sql = "SELECT b FROM cf WHERE a IN ($placeholders)
          UNION ALL
          SELECT a FROM cf WHERE b IN ($placeholders)";
  $stmt = $pdo->prepare($sql);
  $stmt->execute(array_merge($all_ids, $all_ids));
  $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

  $cfs = [];
  $seen = [];
  foreach ($rows as $cf_id) {
    if (in_array($cf_id, $all_ids) || isset($seen[$cf_id])) continue;
    $seen[$cf_id] = true;
    $q2 = $pdo->prepare("SELECT pal FROM all_words3 WHERE id = ?");
    $q2->execute([$cf_id]);
    $row = $q2->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $cfs[] = ['id' => $cf_id, 'pal' => strtoupper($row['pal'])];
    }
  }
  usort($cfs, fn($a, $b) => strcasecmp($a['pal'], $b['pal']));
  return $cfs;
}

// ============================================================
// HELPER: get synonyms for an entry
// ============================================================

function get_synonyms($pdo, $stem_id) {
  $sql = "SELECT s.word FROM synonyms AS s
          WHERE s.mygrouping IN (
            SELECT s2.mygrouping FROM synonyms AS s2 WHERE s2.word = ?
          )
          AND s.word != ?";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$stem_id, $stem_id]);
  $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

  $syns = [];
  foreach ($rows as $syn_id) {
    $q2 = $pdo->prepare("SELECT pal FROM all_words3 WHERE id = ?");
    $q2->execute([$syn_id]);
    $row = $q2->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $syns[] = ['id' => $syn_id, 'pal' => strtoupper($row['pal'])];
    }
  }
  usort($syns, fn($a, $b) => strcasecmp($a['pal'], $b['pal']));
  return $syns;
}

// ============================================================
// HELPER: fuzzy search — find closest matching words
// Used when search returns no results
// ============================================================

function find_fuzzy($pdo, $input, $column) {
  if ($column === 'pal') {
    $sql = "SELECT DISTINCT(pal) FROM all_words3 WHERE LENGTH(pal) > 0 AND pos != 'var.' AND pos != 'cont.'";
  } else {
    $sql = "SELECT eng FROM eng_list";
  }
  $stmt = $pdo->query($sql);
  $words = $stmt->fetchAll(PDO::FETCH_COLUMN);

  $best = [];
  foreach ($words as $word) {
    similar_text(strtolower($input), strtolower($word), $percent);
    $best[] = ['word' => $word, 'score' => $percent];
  }

  usort($best, fn($a, $b) => $b['score'] <=> $a['score']);
  return array_slice($best, 0, 5);
}

// ============================================================
// MAIN SEARCH LOGIC
// ============================================================

// Build WHERE clause matching John's get_all_entries2() / make_search_col()
// Uses word-boundary regexp matching (\b equivalent in MySQL: [[:<:]] [[:>:]])

$safe_like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
$safe_like_stmt = $pdo->prepare(
  $direction === 'pp'
    ? "SELECT id, stem, pos, pal FROM all_words3 WHERE pal LIKE ? OR pdef LIKE ?"
    : ($direction === 'ep'
        ? "SELECT id, stem, pos, pal FROM all_words3 WHERE eng LIKE ?"
        : "SELECT id, stem, pos, pal FROM all_words3 WHERE pal LIKE ?")
);

if ($direction === 'pp') {
  $safe_like_stmt->execute([$safe_like, $safe_like]);
} else {
  $safe_like_stmt->execute([$safe_like]);
}

$initial_rows = $safe_like_stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// If no results, return fuzzy suggestions
// ============================================================

if (count($initial_rows) === 0) {
  $col = ($direction === 'ep') ? 'eng' : 'pal';
  $fuzzy = find_fuzzy($pdo, $query, $col);
  echo json_encode([
    'results'       => [],
    'fuzzy'         => $fuzzy,
    'query'         => $query,
    'direction'     => $direction,
  ]);
  exit;
}

// ============================================================
// GROUP: turn off grouping if too many results (matches John's logic)
// ============================================================

$group = true;
if (count($initial_rows) > 20) {
  $group = false;
}

// ============================================================
// BUILD ENTRIES: stem grouping
// ============================================================

$entries    = [];
$roots      = [];

foreach ($initial_rows as $row) {
  $stem_id = ($row['stem']) ? $row['stem'] : $row['id'];
  if (!$group) { $stem_id = $row['id']; }

  // Skip if we've already processed this stem
  if (isset($entries[$stem_id])) continue;

  // Fetch the root/stem word
  $root_stmt = $pdo->prepare("SELECT pal, pos, eng, pdef, origin, stem, id, oword FROM all_words3 WHERE id = ?");
  $root_stmt->execute([$stem_id]);
  $root_row = $root_stmt->fetch(PDO::FETCH_ASSOC);

  if (!$root_row) continue;

  $root = [
    'id'        => $stem_id,
    'pal'       => $root_row['pal'],
    'eng'       => $root_row['eng'],
    'pos'       => $root_row['pos'],
    'pdef'      => $root_row['pdef'],
    'origin'    => get_origin_label($root_row['origin'], $root_row['oword']),
    'has_audio' => has_word_audio($root_row['pal']),
    'audio_url' => has_word_audio($root_row['pal']) ? '/mp3s/' . $root_row['pal'] . '.mp3' : null,
    'is_root'   => true,
    'stem'      => $root_row['stem'],
  ];

  // Handle no-grouping mode
  if (!$group) {
    if ($root_row['stem'] && $root_row['id'] != $root_row['stem']) {
      // Add a cf back to root
      $root['cfs'] = [['id' => $root_row['stem'], 'pal' => '']];
    } else {
      $roots[$root_row['id']] = true;
    }
    $entries[$stem_id] = [
      'root'      => $root,
      'subwords'  => [],
      'examples'  => [],
      'proverbs'  => [],
      'sentences' => [],
      'cfs'       => [],
      'synonyms'  => [],
    ];
    continue;
  }

  // Fetch all branch words (subentries)
  $branch_stmt = $pdo->prepare("SELECT pal, pos, eng, pdef, origin, id, oword FROM all_words3 WHERE stem = ? AND id != ?");
  $branch_stmt->execute([$stem_id, $stem_id]);
  $branch_rows = $branch_stmt->fetchAll(PDO::FETCH_ASSOC);

  $subwords = [];
  $word_ids = [];
  $word_pals = [$root_row['pal']];

  foreach ($branch_rows as $br) {
    $subwords[] = [
      'id'        => $br['id'],
      'pal'       => $br['pal'],
      'eng'       => $br['eng'],
      'pos'       => $br['pos'],
      'pdef'      => $br['pdef'],
      'origin'    => get_origin_label($br['origin'], $br['oword']),
      'has_audio' => has_word_audio($br['pal']),
      'audio_url' => has_word_audio($br['pal']) ? '/mp3s/' . $br['pal'] . '.mp3' : null,
    ];
    $word_ids[]  = $br['id'];
    $word_pals[] = $br['pal'];
  }

  // Merge variants into their parents
  [$root, $subwords] = merge_variants($root, $subwords);

  // Sort subwords (paradigm ordering)
  $subwords = sort_subentries($subwords);

  // Get extras
  $examples  = get_examples($pdo, $stem_id, $word_pals);
  $proverbs  = get_proverbs($pdo, $stem_id, $word_pals);
  $sentences = get_sentences($pdo, $stem_id, $word_pals);
  $cfs       = get_cfs($pdo, $stem_id, $word_ids);
  $synonyms  = get_synonyms($pdo, $stem_id);

  $entries[$stem_id] = [
    'root'      => $root,
    'subwords'  => $subwords,
    'examples'  => $examples,
    'proverbs'  => $proverbs,
    'sentences' => $sentences,
    'cfs'       => $cfs,
    'synonyms'  => $synonyms,
  ];
}

// ============================================================
// TRIM: remove subword entries whose root is already returned
// (only needed in no-group mode)
// ============================================================

if (!$group) {
  $trimmed = [];
  foreach ($entries as $id => $entry) {
    $r = $entry['root'];
    if ($r['stem'] == $r['id'] || !array_key_exists($r['stem'], $roots)) {
      $trimmed[$id] = $entry;
    }
  }
  $entries = $trimmed;
}

// ============================================================
// SORT entries: expressions first, then alphabetical
// ============================================================

$entries = array_values($entries);
$entries = sort_entries($entries);

// ============================================================
// PRIORITIZE: move exact match to the very top
// ============================================================

foreach ($entries as $i => $entry) {
  if (strcasecmp($entry['root']['pal'], $query) === 0) {
    array_unshift($entries, array_splice($entries, $i, 1)[0]);
    break;
  }
}

// ============================================================
// OUTPUT
// ============================================================

echo json_encode([
  'results'   => $entries,
  'fuzzy'     => [],
  'query'     => $query,
  'direction' => $direction,
]);
