<?php
// search_lib.php — Search data-gathering helpers.
//
// Pulled out of search.php so the same code can be reached two ways:
//   • Local mode: search.php calls these directly and renders the result.
//   • Remote API mode: search.php receives a raw row payload from
//     api.php (which calls these on the remote server) and renders it
//     identically. The render side is therefore unchanged.
//
// All helpers return plain PHP arrays that JSON-serialise cleanly.

require_once __DIR__ . '/db.php';

const SEARCH_RESULT_LIMIT       = 6001;   // verse-list LIMIT (search.php trims to 6000)
const SEARCH_GEMATRIA_OCC_LIMIT = 6000;   // gematria total-occurrence cap
const SEARCH_FORMATION_WORD_LIMIT   = 50;
const SEARCH_FORMATION_PHRASE_LIMIT = 30;

const GEMATRIA_SYSTEMS = ['standard', 'ordinal', 'reduced'];
const SEARCH_SCOPES = ['all', 'ot', 'nt'];
const SEARCH_RESULT_KINDS = ['all', 'verses', 'words'];

function search_valid_gematria_system(string $system): string {
    $system = strtolower(trim($system));
    return in_array($system, array_merge(['all'], GEMATRIA_SYSTEMS), true) ? $system : 'all';
}

function search_valid_scope(string $scope): string {
    $scope = strtolower(trim($scope));
    return in_array($scope, SEARCH_SCOPES, true) ? $scope : 'all';
}

function search_valid_result_kind(string $kind): string {
    $kind = strtolower(trim($kind));
    return in_array($kind, SEARCH_RESULT_KINDS, true) ? $kind : 'all';
}

function search_system_columns(string $system): array {
    return $system === 'all' ? GEMATRIA_SYSTEMS : [$system];
}

function search_scope_sql(string $scope, array &$params): string {
    $scope = search_valid_scope($scope);
    if ($scope === 'all') return '';
    $params[] = strtoupper($scope);
    return ' AND b.testament = ?';
}

function gematria_matched_systems(array $row, int $value, string $system): array {
    $matches = [];
    foreach (search_system_columns($system) as $col) {
        if ((int)($row[$col] ?? 0) === $value) $matches[] = $col;
    }
    return $matches;
}

// Escape LIKE special characters in a user-supplied string so that '%' and '_'
// are treated as literals, not wildcards. Duplicated here from search.php so
// callers of these helpers don't need search.php's locals.
function search_escape_like(string $s): string {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

// Normalise a Hebrew/Greek query string for comparison against word.text_search
// / verse.text_search (which are stored already-normalised). The rules match
// add_text_search.py / add_verse_search.py on the import side.
function search_normalize_query(string $text, string $lang): string {
    $text = preg_replace('/\s*\([^)]+\)/u', '', $text);   // strip transliteration parens
    $text = str_replace(['/', '\\'], '', $text);           // strip STEPBible separators
    $text = trim($text);

    if (strtolower($lang) === 'hebrew') {
        if (function_exists('normalizer_normalize')) {
            $text = normalizer_normalize($text, Normalizer::NFD);
        }
        // Strip Hebrew vowel points and cantillation (U+0591–U+05C7, U+FB1E)
        $text = preg_replace('/[\x{0591}-\x{05C7}\x{FB1E}]/u', '', $text);
        return trim($text);
    }

    // Greek: NFD → strip combining diacritics (preserve U+0345 iota subscript)
    //        → dedupe stray U+0345 → NFC → lowercase → explicit ι-subscript compose.
    if (function_exists('normalizer_normalize')) {
        $text = normalizer_normalize($text, Normalizer::NFD);
        $text = preg_replace('/[\x{0300}-\x{0344}\x{0346}-\x{036F}]/u', '', $text);
        $text = preg_replace('/\x{0345}{2,}/u', "\u{0345}", $text);
        $text = normalizer_normalize($text, Normalizer::NFC);
    }
    $text = mb_strtolower(trim($text));
    return str_replace(
        ["\u{03B1}\u{0345}", "\u{03B7}\u{0345}", "\u{03C9}\u{0345}"],
        ["\u{1FB3}",         "\u{1FC3}",         "\u{1FF3}"],
        $text
    );
}

function formation_letters(string $text, string $lang): string {
    $lang = strtolower($lang);
    $text = preg_replace('/\s*\([^)]+\)/u', '', $text);
    $text = str_replace(['/', '\\'], '', trim($text));

    if ($lang === 'hebrew') {
        if (function_exists('normalizer_normalize')) {
            $text = normalizer_normalize($text, Normalizer::NFD);
        }
        $text = preg_replace('/[\x{0591}-\x{05C7}\x{FB1E}]/u', '', $text);
        $text = strtr($text, [
            'ך' => 'כ',
            'ם' => 'מ',
            'ן' => 'נ',
            'ף' => 'פ',
            'ץ' => 'צ',
        ]);
        preg_match_all('/[\x{05D0}-\x{05EA}]/u', $text, $m);
        return implode('', $m[0] ?? []);
    }

    if (function_exists('normalizer_normalize')) {
        $text = normalizer_normalize($text, Normalizer::NFD);
    }
    $text = str_replace("\u{0345}", 'ι', $text);
    $text = preg_replace('/[\x{0300}-\x{036F}]/u', '', $text);
    $text = mb_strtolower($text);
    $text = str_replace('ς', 'σ', $text);
    preg_match_all('/[\x{03B1}-\x{03C9}]/u', $text, $m);
    return implode('', $m[0] ?? []);
}

function formation_signature(string $letters): string {
    if ($letters === '') return '';
    $chars = mb_str_split($letters);
    sort($chars, SORT_STRING);
    return implode('', $chars);
}

function formation_ref(array $row): string {
    return (string)$row['osis_code'] . ' ' . (int)$row['chapter'] . ':' . (int)$row['verse'];
}

function formation_push_occurrence(array &$group, array $row): void {
    $ref = formation_ref($row);
    if (!isset($group['seen'][$ref])) {
        $group['seen'][$ref] = true;
        if (count($group['refs']) < 6) $group['refs'][] = $ref;
    }
    $group['occurrences']++;
}

function bible_letter_formations(string $lang, string $text): array {
    $lang = strtolower(trim($lang)) === 'hebrew' ? 'Hebrew' : 'Greek';
    $letters = formation_letters($text, $lang);
    $signature = formation_signature($letters);
    $letter_count = mb_strlen($letters);

    $empty = [
        'target' => [
            'text' => $text,
            'language' => $lang,
            'letters' => $letters,
            'letter_count' => $letter_count,
            'signature' => $signature,
        ],
        'word_forms' => [],
        'phrase_forms' => [],
        'too_large' => false,
    ];

    if ($letter_count < 2 || $signature === '') return $empty;

    if (should_use_remote_api()) {
        $resp = remote_api_call('formations', ['lang' => $lang, 'text' => $text]);
        return is_array($resp) ? $resp : $empty;
    }

    $pdo = bible_pdo();

    $word_groups = [];
    $len_min = max(1, $letter_count - 2);
    $len_max = $letter_count + 2;
    $stmt = $pdo->prepare(
        "SELECT w.text_original, w.transliteration, w.translation, w.strongs_primary,
                w.text_search, b.osis_code, b.book_order, w.chapter, w.verse
           FROM word w
           JOIN book b ON b.id = w.book_id
          WHERE w.language = ?
            AND w.text_search IS NOT NULL
            AND CHAR_LENGTH(w.text_search) BETWEEN ? AND ?
          ORDER BY b.book_order, w.chapter, w.verse, w.position
          LIMIT 20000"
    );
    $stmt->execute([$lang, $len_min, $len_max]);
    foreach ($stmt->fetchAll() as $row) {
        $cand_letters = formation_letters((string)($row['text_search'] ?: $row['text_original']), $lang);
        if (mb_strlen($cand_letters) !== $letter_count) continue;
        if (formation_signature($cand_letters) !== $signature) continue;

        $key = $cand_letters . '|' . ($row['strongs_primary'] ?? '') . '|' . ($row['translation'] ?? '');
        if (!isset($word_groups[$key])) {
            $word_groups[$key] = [
                'kind' => 'word',
                'text' => $row['text_original'],
                'letters' => $cand_letters,
                'translation' => $row['translation'],
                'transliteration' => $row['transliteration'],
                'strongs' => $row['strongs_primary'],
                'occurrences' => 0,
                'refs' => [],
                'seen' => [],
            ];
        }
        formation_push_occurrence($word_groups[$key], $row);
    }
    foreach ($word_groups as &$group) unset($group['seen']);
    unset($group);
    $word_forms = array_values($word_groups);
    usort($word_forms, fn($a, $b) => ($b['occurrences'] <=> $a['occurrences']) ?: strcmp((string)$a['text'], (string)$b['text']));
    $word_forms = array_slice($word_forms, 0, SEARCH_FORMATION_WORD_LIMIT);

    $phrase_forms = [];
    $too_large = $letter_count > 28;
    if (!$too_large) {
        $stmt = $pdo->prepare(
            "SELECT w.text_original, w.translation, w.text_search,
                    b.osis_code, b.book_order, w.chapter, w.verse, w.position
               FROM word w
               JOIN book b ON b.id = w.book_id
              WHERE w.language = ?
                AND w.text_search IS NOT NULL
              ORDER BY b.book_order, w.chapter, w.verse, w.position
              LIMIT 700000"
        );
        $stmt->execute([$lang]);

        $process_verse = function (array $words) use (&$phrase_forms, $letter_count, $signature, $lang): bool {
            $n = count($words);
            for ($i = 0; $i < $n; $i++) {
                $letters_run = '';
                $text_parts = [];
                $meaning_parts = [];
                for ($j = $i; $j < $n && $j < $i + 6; $j++) {
                    $letters_run .= $words[$j]['_letters'];
                    $run_len = mb_strlen($letters_run);
                    if ($run_len > $letter_count) break;
                    $text_parts[] = $words[$j]['text_original'];
                    if (!empty($words[$j]['translation'])) $meaning_parts[] = $words[$j]['translation'];
                    if ($j === $i) continue; // phrases require at least two words
                    if ($run_len !== $letter_count) continue;
                    if (formation_signature($letters_run) !== $signature) continue;

                    $phrase_text = implode(' ', $text_parts);
                    $key = formation_letters($phrase_text, $lang) . '|' . implode(' ', $meaning_parts);
                    if (!isset($phrase_forms[$key])) {
                        $phrase_forms[$key] = [
                            'kind' => 'phrase',
                            'text' => $phrase_text,
                            'letters' => $letters_run,
                            'translation' => implode(' / ', $meaning_parts),
                            'occurrences' => 0,
                            'refs' => [],
                            'seen' => [],
                        ];
                    }
                    formation_push_occurrence($phrase_forms[$key], $words[$i]);
                    if (count($phrase_forms) >= SEARCH_FORMATION_PHRASE_LIMIT * 3) return false;
                }
            }
            return true;
        };

        $current_key = null;
        $verse_words = [];
        while ($row = $stmt->fetch()) {
            $vkey = $row['osis_code'] . '|' . (int)$row['chapter'] . '|' . (int)$row['verse'];
            if ($current_key !== null && $vkey !== $current_key) {
                if (!$process_verse($verse_words)) break;
                $verse_words = [];
            }
            $current_key = $vkey;
            $clean = formation_letters((string)($row['text_search'] ?: $row['text_original']), $lang);
            if ($clean === '') continue;
            $verse_words[] = [
                'text_original' => $row['text_original'],
                'translation' => $row['translation'],
                'osis_code' => $row['osis_code'],
                'chapter' => $row['chapter'],
                'verse' => $row['verse'],
                '_letters' => $clean,
            ];
        }
        if (!empty($verse_words)) $process_verse($verse_words);

        foreach ($phrase_forms as &$group) unset($group['seen']);
        unset($group);
    }

    $phrase_forms = array_values($phrase_forms);
    usort($phrase_forms, fn($a, $b) => ($b['occurrences'] <=> $a['occurrences']) ?: strcmp((string)$a['text'], (string)$b['text']));
    $phrase_forms = array_slice($phrase_forms, 0, SEARCH_FORMATION_PHRASE_LIMIT);

    return [
        'target' => $empty['target'],
        'word_forms' => $word_forms,
        'phrase_forms' => $phrase_forms,
        'too_large' => $too_large,
    ];
}

// ------------------------------------------------------------------
// Gematria search: every word with the given standard gematria value.
// Returns:
//   [
//     'groups'    => [ <text_search-keyed group>, ... ],
//     'truncated' => bool,
//     'form_count'=> int,   // number of distinct word forms (groups)
//     'total_occ' => int,   // total verse occurrences across all groups
//   ]
// Each group:
//   [
//     'text_original'   => string,
//     'transliteration' => ?string,
//     'translation'     => ?string,
//     'strongs_primary' => ?string,
//     'language'        => 'Hebrew'|'Greek',
//     'verses'          => [
//         ['book_name' => ..., 'osis_code' => ..., 'chapter' => int, 'verse' => int],
//         ...
//     ],
//   ]
// ------------------------------------------------------------------
function bible_search_gematria(int $gem_value, string $system = 'all',
                               string $kind = 'all', string $scope = 'all'): array {
    $system = search_valid_gematria_system($system);
    $kind   = search_valid_result_kind($kind);
    $scope  = search_valid_scope($scope);

    $empty = [
        'value'             => $gem_value,
        'system'            => $system,
        'kind'              => $kind,
        'scope'             => $scope,
        'groups'            => [],
        'word_groups'       => [],
        'verse_matches'     => [],
        'truncated'         => false,
        'words_truncated'   => false,
        'verses_truncated'  => false,
        'form_count'        => 0,
        'total_occ'         => 0,
        'verse_count'       => 0,
    ];
    if ($gem_value <= 0) return $empty;

    if (should_use_remote_api()) {
        $resp = remote_api_call('search_gematria', [
            'value'  => $gem_value,
            'system' => $system,
            'kind'   => $kind,
            'scope'  => $scope,
        ]);
        if (!is_array($resp)) return $empty;
        $word_groups = $resp['word_groups'] ?? $resp['groups'] ?? [];
        return [
            'value'            => (int)($resp['value'] ?? $gem_value),
            'system'           => $resp['system'] ?? $system,
            'kind'             => $resp['kind'] ?? $kind,
            'scope'            => $resp['scope'] ?? $scope,
            'groups'           => $word_groups,
            'word_groups'      => $word_groups,
            'verse_matches'    => $resp['verse_matches'] ?? [],
            'truncated'        => !empty($resp['truncated']),
            'words_truncated'  => !empty($resp['words_truncated']),
            'verses_truncated' => !empty($resp['verses_truncated']),
            'form_count'       => (int)($resp['form_count'] ?? count($word_groups)),
            'total_occ'        => (int)($resp['total_occ'] ?? 0),
            'verse_count'      => (int)($resp['verse_count'] ?? count($resp['verse_matches'] ?? [])),
        ];
    }

    $pdo = bible_pdo();
    $columns = search_system_columns($system);
    $limit = SEARCH_GEMATRIA_OCC_LIMIT + 1;

    $word_groups = [];
    $words_truncated = false;
    if ($kind === 'all' || $kind === 'words') {
        $params = [];
        $where_parts = [];
        foreach ($columns as $col) {
            $where_parts[] = "gw.$col = ?";
            $params[] = $gem_value;
        }
        $scope_sql = search_scope_sql($scope, $params);
        $stmt = $pdo->prepare(
            "SELECT w.id, w.book_id, w.chapter, w.verse, w.text_original,
                    w.transliteration, w.translation, w.strongs, w.strongs_primary,
                    w.text_search, w.language,
                    b.name AS book_name, b.osis_code, b.testament, b.book_order,
                    gw.standard, gw.ordinal, gw.reduced
               FROM gematria_word gw
               JOIN word w ON w.id = gw.word_id
               JOIN verse v ON v.id = w.verse_id
               JOIN book b ON b.id = v.book_id
              WHERE (" . implode(' OR ', $where_parts) . ")$scope_sql
              ORDER BY b.book_order, w.chapter, w.verse, w.position
              LIMIT $limit"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if (count($rows) >= $limit) {
            $words_truncated = true;
            $rows = array_slice($rows, 0, SEARCH_GEMATRIA_OCC_LIMIT);
        }

        foreach ($rows as $row) {
            $matched = gematria_matched_systems($row, $gem_value, $system);
            if (empty($matched)) continue;
            // Strip Unicode punctuation so λόγῳ, / λόγω. / λόγῳ bucket together.
            $ts = preg_replace('/\p{P}+/u', '', (string)($row['text_search'] ?: $row['text_original']));
            $ts = trim($ts);
            if ($ts === '') $ts = 'word-' . (int)$row['id'];
            $gkey = implode('|', [
                $row['language'],
                $ts,
                (string)($row['strongs_primary'] ?? ''),
                (int)$row['standard'],
                (int)$row['ordinal'],
                (int)$row['reduced'],
            ]);
            if (!isset($word_groups[$gkey])) {
                $word_groups[$gkey] = [
                    'text_original'   => $row['text_original'],
                    'transliteration' => $row['transliteration'],
                    'translation'     => $row['translation'],
                    'strongs_primary' => $row['strongs_primary'],
                    'strongs'         => $row['strongs'],
                    'language'        => $row['language'],
                    'standard'        => (int)$row['standard'],
                    'ordinal'         => (int)$row['ordinal'],
                    'reduced'         => (int)$row['reduced'],
                    'matched_systems' => [],
                    'seen'            => [],
                    'verses'          => [],
                ];
            }
            $word_groups[$gkey]['matched_systems'] = array_values(array_unique(array_merge(
                $word_groups[$gkey]['matched_systems'],
                $matched
            )));
            $vkey = $row['book_id'] . ':' . $row['chapter'] . ':' . $row['verse'];
            if (isset($word_groups[$gkey]['seen'][$vkey])) continue;
            $word_groups[$gkey]['seen'][$vkey] = true;
            $word_groups[$gkey]['verses'][] = [
                'book_name' => $row['book_name'],
                'osis_code' => $row['osis_code'],
                'testament' => $row['testament'],
                'chapter'   => (int)$row['chapter'],
                'verse'     => (int)$row['verse'],
            ];
        }
        foreach ($word_groups as &$g) unset($g['seen']);
        unset($g);
    }

    $verse_matches = [];
    $verses_truncated = false;
    if ($kind === 'all' || $kind === 'verses') {
        $params = [];
        $where_parts = [];
        foreach ($columns as $col) {
            $where_parts[] = "gv.$col = ?";
            $params[] = $gem_value;
        }
        $scope_sql = search_scope_sql($scope, $params);
        $stmt = $pdo->prepare(
            "SELECT b.name AS book_name, b.osis_code, b.testament, b.book_order,
                    v.chapter, v.verse, v.text_original, v.text_english,
                    gv.standard, gv.ordinal, gv.reduced
               FROM gematria_verse gv
               JOIN verse v ON v.id = gv.verse_id
               JOIN book b ON b.id = v.book_id
              WHERE (" . implode(' OR ', $where_parts) . ")$scope_sql
              ORDER BY b.book_order, v.chapter, v.verse
              LIMIT $limit"
        );
        $stmt->execute($params);
        $verse_matches = $stmt->fetchAll();
        if (count($verse_matches) >= $limit) {
            $verses_truncated = true;
            $verse_matches = array_slice($verse_matches, 0, SEARCH_GEMATRIA_OCC_LIMIT);
        }
        foreach ($verse_matches as &$row) {
            $row['chapter'] = (int)$row['chapter'];
            $row['verse'] = (int)$row['verse'];
            $row['standard'] = (int)$row['standard'];
            $row['ordinal'] = (int)$row['ordinal'];
            $row['reduced'] = (int)$row['reduced'];
            $row['matched_systems'] = gematria_matched_systems($row, $gem_value, $system);
        }
        unset($row);
    }

    $groups_list = array_values($word_groups);
    $word_occ = array_sum(array_map(fn($g) => count($g['verses']), $groups_list));

    return [
        'value'            => $gem_value,
        'system'           => $system,
        'kind'             => $kind,
        'scope'            => $scope,
        'groups'           => $groups_list,
        'word_groups'      => $groups_list,
        'verse_matches'    => $verse_matches,
        'truncated'        => $words_truncated || $verses_truncated,
        'words_truncated'  => $words_truncated,
        'verses_truncated' => $verses_truncated,
        'form_count'       => count($groups_list),
        'total_occ'        => $word_occ,
        'verse_count'      => count($verse_matches),
    ];
}

// ------------------------------------------------------------------
// Verse search: Strong's / text / phrase modes.
// Returns:
//   [
//     'rows'       => [ ['book_name','osis_code','testament','book_order',
//                        'chapter','verse'], ... ],   // sorted, truncated to 6000
//     'truncated'  => bool,
//     'not_found'  => bool,   // a supplied Strong's code wasn't in `strongs`
//     'norms'      => [ string, ... ],  // display terms for the results header
//   ]
// Inputs:
//   $mode  = 'strongs' | 'text' | 'phrase'
//   $q_raw = the raw query string (e.g. "H430, G3056" or "logos" or
//            "weeping and wailing")
//   $lang  = 'Hebrew' | 'Greek' | 'English' (lower/upper case both accepted)
// ------------------------------------------------------------------
function bible_search_verses(string $mode, string $q_raw, string $lang = '',
                             string $scope = 'all'): array {
    $mode  = strtolower(trim($mode));
    $q_raw = trim($q_raw);
    $scope = search_valid_scope($scope);

    if ($q_raw === '') {
        return ['rows' => [], 'truncated' => false, 'not_found' => false, 'norms' => [], 'scope' => $scope];
    }

    if (should_use_remote_api()) {
        $resp = remote_api_call('search_verses', [
            'mode'  => $mode,
            'q'     => $q_raw,
            'lang'  => $lang,
            'scope' => $scope,
        ]);
        if (!is_array($resp)) {
            return ['rows' => [], 'truncated' => false, 'not_found' => false, 'norms' => [], 'scope' => $scope];
        }
        return [
            'rows'      => $resp['rows']      ?? [],
            'truncated' => !empty($resp['truncated']),
            'not_found' => !empty($resp['not_found']),
            'norms'     => $resp['norms']     ?? [],
            'scope'     => $resp['scope']     ?? $scope,
        ];
    }

    // Split on commas, drop empties, re-index.
    $terms = array_values(array_filter(array_map('trim', explode(',', $q_raw))));
    if (empty($terms)) {
        return ['rows' => [], 'truncated' => false, 'not_found' => false, 'norms' => [], 'scope' => $scope];
    }

    $pdo        = bible_pdo();
    $norms      = [];
    $params     = [];
    $where_sql  = '';
    $not_found  = false;

    if ($mode === 'phrase' && strtolower($lang) === 'english') {
        // English KJV phrase search — bible_kjv.Verse_Text_Clean LIKE.
        // Strip sentence punctuation from both sides; lowercase via LOWER().
        $needle  = trim(preg_replace('/\s+/u', ' ', $q_raw));
        $needle  = preg_replace('/[,;:.!?]/u', '', $needle);
        $needle  = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $needle)));
        $norms[] = $q_raw;
        $where_sql = "EXISTS (
                        SELECT 1
                          FROM bible_kjv k
                         WHERE k.Book    = b.id
                           AND k.Chapter = v.chapter
                           AND k.Verse   = v.verse
                           AND LOWER(REGEXP_REPLACE(k.Verse_Text_Clean, '[,;:.!?]', '')) LIKE ?)";
        $params[] = '%' . search_escape_like($needle) . '%';
    } elseif ($mode === 'phrase') {
        // Hebrew/Greek whole-phrase search against verse.text_search.
        $norm = search_normalize_query($q_raw, $lang);
        // Strip iota-subscript forms (utf8mb4_unicode_ci treats U+0345 as
        // zero-weight; verse.text_search is stored with the same stripping).
        if (strtolower($lang) !== 'hebrew') {
            $norm = str_replace(
                ["\u{1FB3}", "\u{1FC3}", "\u{1FF3}", "\u{0345}"],
                ["\u{03B1}", "\u{03B7}", "\u{03C9}", ''],
                $norm
            );
        }
        $norms[]   = $norm ?: $q_raw;
        $where_sql = "v.text_search LIKE ?";
        $params[]  = '%' . search_escape_like($norm) . '%';
    } else {
        $exists_clauses = [];
        if ($mode === 'strongs') {
            // Strong's: comma-separated codes, AND logic across the verse.
            foreach ($terms as $term) {
                $search_term = $term;
                if (preg_match('/^([HG])(\d{1,5})([A-Za-z]?)$/', $term, $sm)) {
                    $search_term = $sm[1] . str_pad($sm[2], 4, '0', STR_PAD_LEFT) . $sm[3];
                }
                $lookup_key = $term;
                if (preg_match('/^([HG])0*(\d+)([A-Za-z]?)$/', $term, $sm)) {
                    $lookup_key = $sm[1] . $sm[2] . $sm[3];
                }
                if (bible_strongs_lookup($lookup_key) === null) {
                    $norms     = [$term];
                    $not_found = true;
                    break;
                }
                $exists_clauses[] =
                    "EXISTS (SELECT 1 FROM word w\n"
                  . "         WHERE w.verse_id = v.id AND w.strongs LIKE ?)";
                $params[] = '%' . search_escape_like($search_term) . '%';
                $norms[]  = $term;
            }
        } else {
            // 'text' mode — split on commas and whitespace, AND each term.
            $text_terms = array_values(array_filter(
                array_map('trim', preg_split('/[\s,]+/u', $q_raw))
            ));
            if (strtolower($lang) === 'english') {
                foreach ($text_terms as $term) {
                    $needle = mb_strtolower(preg_replace('/[,;:.!?]/u', '', $term));
                    $exists_clauses[] =
                        "EXISTS (\n"
                      . "  SELECT 1 FROM bible_kjv k\n"
                      . "   WHERE k.Book = b.id AND k.Chapter = v.chapter AND k.Verse = v.verse\n"
                      . "     AND LOWER(REGEXP_REPLACE(k.Verse_Text_Clean, '[,;:.!?]', '')) LIKE ?)";
                    $params[] = '%' . search_escape_like($needle) . '%';
                    $norms[]  = $term;
                }
            } else {
                foreach ($text_terms as $term) {
                    $norm = search_normalize_query($term, $lang);
                    $exists_clauses[] =
                        "EXISTS (SELECT 1 FROM word w\n"
                      . "         WHERE w.verse_id = v.id AND w.text_search LIKE ?)";
                    $params[] = '%' . search_escape_like($norm) . '%';
                    $norms[]  = $norm ?: $term;
                }
            }
        }
        $where_sql = implode("\n  AND ", $exists_clauses);
    }

    $rows      = [];
    $truncated = false;
    if (!$not_found && $where_sql !== '') {
        try {
            $scope_sql = search_scope_sql($scope, $params);
            $stmt = $pdo->prepare(
                "SELECT b.name AS book_name, b.osis_code, b.testament,
                        b.book_order, v.chapter, v.verse
                   FROM verse v
                   JOIN book b ON b.id = v.book_id
                  WHERE $where_sql$scope_sql
                  ORDER BY b.book_order, v.chapter, v.verse
                  LIMIT " . SEARCH_RESULT_LIMIT
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            if (count($rows) === SEARCH_RESULT_LIMIT) {
                $truncated = true;
                array_pop($rows);
            }
        } catch (Throwable $e) {
            error_log('Bible search error: ' . $e->getMessage());
            // Surface the error message inside not_found-style envelope so the
            // caller can decide what to render. Keeping the old behaviour
            // (string $error) would have broken JSON shape.
            return [
                'rows'      => [],
                'truncated' => false,
                'not_found' => false,
                'norms'     => $norms,
                'scope'     => $scope,
                'error'     => $e->getMessage(),
            ];
        }
    }

    return [
        'rows'      => $rows,
        'truncated' => $truncated,
        'not_found' => $not_found,
        'norms'     => $norms,
        'scope'     => $scope,
    ];
}
