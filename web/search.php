<?php
// search.php â€” Bible Browser search results page.
// GET params:
//   q    â€” one term, or several comma-separated terms (AND logic across a verse)
//   mode â€” strongs | text
//   lang â€” Hebrew | Greek  (used for text normalisation)

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/search_lib.php';

$q_raw = trim($_GET['q']    ?? '');
$mode  = strtolower(trim($_GET['mode'] ?? 'strongs'));
$lang  = trim($_GET['lang'] ?? '');
$scope = search_valid_scope(strtolower(trim($_GET['scope'] ?? 'all')));

function search_label_scope(string $scope): string {
    return match ($scope) {
        'ot' => 'Old Testament',
        'nt' => 'New Testament',
        default => 'All',
    };
}

function search_label_system(string $system): string {
    return match ($system) {
        'standard' => 'Standard',
        'ordinal' => 'Ordinal',
        'reduced' => 'Reduced',
        default => 'All',
    };
}

function search_label_kind(string $kind): string {
    return match ($kind) {
        'verses' => 'Verse Matches',
        'words' => 'Word Matches',
        default => 'All',
    };
}

function search_filter_url(array $overrides): string {
    $params = $_GET;
    foreach ($overrides as $key => $value) {
        $params[$key] = $value;
    }
    return 'search.php?' . http_build_query($params);
}

function render_search_filter_group(string $label, string $param, array $choices, string $active): void {
    echo '<div class="search-filter-group"><span>' . h($label) . '</span><div class="search-filter-pills">';
    foreach ($choices as $value => $text) {
        $is_active = $value === $active;
        echo '<a class="' . ($is_active ? 'active' : '') . '" href="' . h(search_filter_url([$param => $value])) . '">'
           . h($text) . '</a>';
    }
    echo '</div></div>';
}

function gematria_values_line(array $row): string {
    return 'Std ' . (int)($row['standard'] ?? 0)
        . ' / Ord ' . (int)($row['ordinal'] ?? 0)
        . ' / Red ' . (int)($row['reduced'] ?? 0);
}

function gematria_match_line(array $row): string {
    $labels = array_map('search_label_system', $row['matched_systems'] ?? []);
    return empty($labels) ? '' : implode(', ', $labels);
}

// Handle JSON API requests forwarded here (strongs-tooltip.js, verse-tooltip.js).
// Both lookups go through bible_*() helpers in db.php that dispatch to the
// remote API in remote mode, so this page exposes the same endpoints in
// either mode.
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    switch ($_GET['api']) {
        case 'strongs':
            $code = trim($_GET['code'] ?? '');
            echo json_encode(bible_strongs_lookup($code));
            break;
        case 'kjv_verse':
            $osis    = trim($_GET['book']    ?? '');
            $chapter = (int)($_GET['chapter'] ?? 0);
            $verse   = (int)($_GET['verse']   ?? 0);
            $text    = null;
            if ($osis !== '' && $chapter > 0 && $verse > 0) {
                $text = bible_kjv_verse_clean($osis, $chapter, $verse);
            }
            echo json_encode(['text' => $text]);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'unknown api']);
    }
    exit;
}

// â”€â”€ Gematria mode: find all words with a given standard gematria value â”€â”€â”€â”€â”€â”€â”€
if ($mode === 'gematria') {
    $gem_value = (int)($_GET['value'] ?? 0);
    if ($gem_value <= 0 && isset($_GET['standard'])) $gem_value = (int)$_GET['standard'];
    if ($gem_value <= 0) {
        header('Location: index.php');
        exit;
    }
    $gem_system = search_valid_gematria_system(strtolower(trim($_GET['system'] ?? 'all')));
    $gem_kind   = search_valid_result_kind(strtolower(trim($_GET['kind'] ?? 'all')));

    $gem_result      = bible_search_gematria($gem_value, $gem_system, $gem_kind, $scope);
    $groups          = $gem_result['word_groups'] ?? $gem_result['groups'] ?? [];
    $verse_matches   = $gem_result['verse_matches'] ?? [];
    $gem_truncated   = !empty($gem_result['truncated']);
    $words_truncated = !empty($gem_result['words_truncated']);
    $verses_truncated = !empty($gem_result['verses_truncated']);
    $form_count      = (int)($gem_result['form_count'] ?? count($groups));
    $total_occ       = (int)($gem_result['total_occ'] ?? 0);
    $verse_count     = (int)($gem_result['verse_count'] ?? count($verse_matches));
    ?>
<?php bible_render_layout_header(); ?>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="author" content="Richard Amiel McGough">
<title>Gematria <?= (int)$gem_value ?> - Bible Browser</title>
<?php bible_render_layout_styles(); ?>
</head>
<body>
<?php bible_render_layout_banner(); ?>
<div class="bible-layout">
<main class="bible-main">
<div class="selector">
    <a href="javascript:history.back()" class="back-link">&#8592; Back</a>
    <span class="search-summary">
        Gematria&nbsp;<strong><?= (int)$gem_value ?></strong>
        &nbsp;/&nbsp;<?= h(search_label_system($gem_system)) ?>
        &nbsp;/&nbsp;<?= h(search_label_kind($gem_kind)) ?>
        &nbsp;/&nbsp;<?= h(search_label_scope($scope)) ?>
        &nbsp;-&nbsp;<?= $verse_count ?> verse match<?= $verse_count !== 1 ? 'es' : '' ?>,
        <?= $form_count ?> word form<?= $form_count !== 1 ? 's' : '' ?>,
        <?= $total_occ ?> word occurrence<?= $total_occ !== 1 ? 's' : '' ?>
        <?= $gem_truncated ? '<span class="trunc-note">(showing first 6,000 per section)</span>' : '' ?>
    </span>
    <?php
        $gr_file = $_SERVER['DOCUMENT_ROOT'] . '/GR/GR_' . (int)$gem_value . '.php';
        if (file_exists($gr_file)):
    ?>
        <a href="/GR/GR_<?= (int)$gem_value ?>.php" class="gr-article-link" target="_blank">
            Article on <?= (int)$gem_value ?> &#8594;
        </a>
    <?php endif; ?>
</div>

<div class="search-filters">
    <?php render_search_filter_group('Scope', 'scope', [
        'all' => 'All',
        'ot' => 'Old Testament',
        'nt' => 'New Testament',
    ], $scope); ?>
    <?php render_search_filter_group('Value', 'system', [
        'all' => 'All',
        'standard' => 'Standard',
        'ordinal' => 'Ordinal',
        'reduced' => 'Reduced',
    ], $gem_system); ?>
    <?php render_search_filter_group('Results', 'kind', [
        'all' => 'All',
        'verses' => 'Verse Matches',
        'words' => 'Word Matches',
    ], $gem_kind); ?>
</div>

<?php if (empty($groups) && empty($verse_matches)): ?>
    <div class="verse-card empty">No verse or word matches found for <?= (int)$gem_value ?>.</div>
<?php else: ?>
<?php if (($gem_kind === 'all' || $gem_kind === 'verses')): ?>
    <div class="search-testament">Verse Matches<?= $verses_truncated ? ' <span class="trunc-note">(showing first 6,000)</span>' : '' ?></div>
    <?php if (empty($verse_matches)): ?>
        <div class="verse-card empty">No verse matches for the selected filters.</div>
    <?php else: ?>
    <table class="search-table gematria-verse-table">
    <thead>
        <tr>
            <th>Reference</th>
            <th>Values</th>
            <th>Match</th>
            <th>Text</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($verse_matches as $vr):
        $url = 'index.php?book=' . urlencode($vr['osis_code'])
             . '&chapter=' . (int)$vr['chapter'] . '&verse=' . (int)$vr['verse'];
        $orig = clean_inline($vr['text_original'] ?? '');
        if (mb_strlen($orig) > 140) $orig = mb_substr($orig, 0, 140) . '...';
    ?>
        <tr>
            <td class="search-book">
                <a href="<?= h($url) ?>" class="verse-ref"
                   data-book="<?= h($vr['osis_code']) ?>"
                   data-chapter="<?= (int)$vr['chapter'] ?>"
                   data-verse="<?= (int)$vr['verse'] ?>">
                    <?= h($vr['osis_code']) ?> <?= (int)$vr['chapter'] ?>:<?= (int)$vr['verse'] ?>
                </a>
            </td>
            <td class="result-values"><?= h(gematria_values_line($vr)) ?></td>
            <td><?= h(gematria_match_line($vr)) ?></td>
            <td class="result-snippet"><?= h($orig) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    <?php endif; ?>
<?php endif; ?>

<?php if (($gem_kind === 'all' || $gem_kind === 'words')): ?>
<div class="search-testament">Word Matches<?= $words_truncated ? ' <span class="trunc-note">(showing first 6,000)</span>' : '' ?></div>
<?php if (empty($groups)): ?>
    <div class="verse-card empty">No word matches for the selected filters.</div>
<?php else: ?>
<table class="search-table">
<thead>
    <tr>
        <th>Word</th>
        <th>Values</th>
        <th>Match</th>
        <th>Strong&rsquo;s</th>
        <th class="gem-count-col">&times;</th>
        <th>Verses</th>
    </tr>
</thead>
<tbody>
<?php foreach ($groups as $g):
    $lcls = ($g['language'] === 'Hebrew') ? 'heb' : 'grk';
    if ($lcls === 'heb') {
        $orig = clean_inline($g['text_original']);
        $tlit = clean_inline($g['transliteration'] ?? '');
    } else {
        [$orig, $tlit_raw] = split_greek_word($g['text_original']);
        $orig = preg_replace('/\p{P}+$/u', '', $orig ?? '');
        $tlit = $tlit_raw ?? clean_inline($g['transliteration'] ?? '');
    }
    $eng  = clean_inline($g['translation'] ?? '');
    // strongs_full_code strips leading zeros (e.g. H430) â€” correct for the
    // strongs table tooltip lookup. For display and LIKE search we need the
    // zero-padded form (H0430) to match the word.strongs column ({H0430G}).
    $strg_key  = strongs_full_code($g['strongs_primary'], $g['language']);
    $strg_raw  = strongs_display($g['strongs_primary']);
    $strg_disp = $strg_raw !== ''
        ? (($g['language'] === 'Hebrew' ? 'H' : 'G') . $strg_raw)
        : '';

    // Group verse links by book, preserving Bible order
    $by_book = [];
    foreach ($g['verses'] as $vr) {
        $code = $vr['osis_code'];
        if (!isset($by_book[$code])) {
            $by_book[$code] = ['name' => $vr['book_name'], 'links' => []];
        }
        $url = 'index.php?book=' . urlencode($code)
             . '&chapter=' . $vr['chapter'] . '&verse=' . $vr['verse'];
        $by_book[$code]['links'][] = '<a href="' . h($url) . '"'
            . ' class="verse-ref"'
            . ' data-book="' . h($code) . '"'
            . ' data-chapter="' . (int)$vr['chapter'] . '"'
            . ' data-verse="' . (int)$vr['verse'] . '">'
            . $vr['chapter'] . ':' . $vr['verse'] . '</a>';
    }
    $bparts = [];
    foreach ($by_book as $bk_code => $bk) {
        $bparts[] = '<strong>' . h($bk_code) . '</strong> '
                  . implode(', ', $bk['links']);
    }
?>
<tr>
    <td class="gem-word-cell">
        <span class="original <?= $lcls ?>"
              style="font-family:var(--<?= $lcls === 'heb' ? 'hebrew' : 'greek' ?>);font-size:<?= $lcls === 'heb' ? '22px' : '18px' ?>"><?= h($orig) ?></span>
        <?php if ($tlit): ?><br><span class="gem-tlit"><?= h($tlit) ?></span><?php endif; ?>
        <?php if ($eng):  ?><br><span class="gem-eng"><?= h($eng) ?></span><?php endif; ?>
    </td>
    <td class="result-values"><?= h(gematria_values_line($g)) ?></td>
    <td><?= h(gematria_match_line($g)) ?></td>
    <td class="gem-strongs"><?php if ($strg_key): ?><a href="search.php?q=<?= urlencode($strg_disp) ?>&amp;mode=strongs&amp;scope=<?= h($scope) ?>" class="strongs-link" data-strongs="<?= h($strg_key) ?>"><?= h($strg_disp) ?></a><?php else: ?>&mdash;<?php endif; ?></td>
    <td class="gem-count-col"><?= count($g['verses']) ?></td>
    <td class="search-verses"><?= implode('; ', $bparts) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
</main>
<?php require __DIR__ . '/bible_sidebar.php'; ?>
</div>
<script src="js/strongs-tooltip.js"></script>
<script src="js/verse-tooltip.js"></script>
</body>
</html>
<?php
    exit;
}

if ($q_raw === '') {
    header('Location: index.php');
    exit;
}

// Keep $terms locally for the rendering header (display_q / multi flag).
$terms = array_values(array_filter(array_map('trim', explode(',', $q_raw))));
if (empty($terms)) {
    header('Location: index.php');
    exit;
}

// bible_search_verses dispatches to the remote API in remote mode and runs
// the full normalise + SQL pipeline locally otherwise. The return shape is
// the same in either mode so the renderer below is unchanged.
$search_result = bible_search_verses($mode, $q_raw, $lang, $scope);
$rows          = $search_result['rows'];
$truncated     = $search_result['truncated'];
$not_found     = $search_result['not_found'];
$norms         = $search_result['norms'];
$error         = !empty($search_result['error'])
                  ? 'A database error occurred. Please try again.'
                  : null;

// Original behaviour: bubble the underlying error message up so the renderer
// can still hint at "run add_verse_search.py" when text_search is missing.
if (!empty($search_result['error']) && str_contains($search_result['error'], 'text_search')) {
    $error = $search_result['error'];
}

$verse_count = count($rows);

// â”€â”€ Group: testament â†’ book â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$grouped = [];
foreach ($rows as $r) {
    $test = $r['testament'] ?: 'OT';
    $code = $r['osis_code'];
    if (!isset($grouped[$test][$code])) {
        $grouped[$test][$code] = ['name' => $r['book_name'], 'verses' => []];
    }
    $grouped[$test][$code]['verses'][] = [(int)$r['chapter'], (int)$r['verse']];
}

$mode_label  = match($mode) {
    'strongs' => "Strong's",
    'phrase'  => (strtolower($lang) === 'english') ? "KJV phrase" : "Phrase",
    'text'    => (strtolower($lang) === 'english') ? "KJV words"  : "Text",
    default   => "Text",
};
$multi       = ($mode !== 'phrase') && count($terms) > 1;
$display_q   = ($mode === 'phrase') ? h($norms[0]) : implode(' + ', array_map('h', $norms));
?>
<?php bible_render_layout_header(); ?>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="author" content="Richard Amiel McGough">
<title>Search: <?= h($q_raw) ?> &mdash; Bible Browser</title>
<?php bible_render_layout_styles(); ?>
</head>
<body>
<?php bible_render_layout_banner(); ?>
<div class="bible-layout">
<main class="bible-main">
    <div class="selector">
        <a href="javascript:history.back()" class="back-link">&#8592; Back</a>
        <span class="search-summary" dir="ltr">
            <?= h($mode_label) ?> search:
            <strong><bdi><?= $display_q ?></bdi></strong>
            &nbsp;/&nbsp;<?= h(search_label_scope($scope)) ?>
            <?php if ($multi && !$error): ?>
                <span class="search-all-label">(all in same verse)</span>
            <?php endif; ?>
            <?php if (!$error): ?>
                &nbsp;&mdash;&nbsp;<?= $verse_count ?> verse<?= $verse_count !== 1 ? 's' : '' ?>
                <?= $truncated ? '<span class="trunc-note">(showing first 6 000)</span>' : '' ?>
            <?php endif; ?>
        </span>
    </div>

    <div class="search-filters">
        <?php render_search_filter_group('Scope', 'scope', [
            'all' => 'All',
            'ot' => 'Old Testament',
            'nt' => 'New Testament',
        ], $scope); ?>
    </div>

<?php if ($error): ?>
    <div class="verse-card empty">
        Query error: <?= h($error) ?>
        <?php if (str_contains($error, 'text_search')): ?>
            <br><small>
            <?= $mode === 'phrase'
                ? 'The <code>verse.text_search</code> column hasn\'t been populated yet â€” run <code>add_verse_search.py</code> from the project root first.'
                : 'The <code>word.text_search</code> column hasn\'t been populated yet â€” run <code>add_text_search.py</code> from the project root first.' ?>
            </small>
        <?php endif; ?>
    </div>
<?php elseif ($verse_count === 0): ?>
    <div class="verse-card empty">
        No verses found containing
        <?= $multi ? 'all of: <strong>' . $display_q . '</strong>' : '&ldquo;' . h($terms[0]) . '&rdquo;' ?>.
    </div>
<?php else: ?>

    <?php foreach (['OT' => 'Old Testament', 'NT' => 'New Testament'] as $test => $test_label): ?>
        <?php if (empty($grouped[$test])) continue; ?>
        <div class="search-testament"><?= $test_label ?></div>
        <table class="search-table">
            <thead><tr><th>Book</th><th>Verses</th></tr></thead>
            <tbody>
            <?php foreach ($grouped[$test] as $code => $bk): ?>
                <tr>
                    <td class="search-book"><?= h($code) ?></td>
                    <td class="search-verses">
                    <?php
                        $links = [];
                        foreach ($bk['verses'] as [$ch, $vs]) {
                            $url     = 'index.php?book=' . urlencode($code)
                                     . '&chapter=' . $ch . '&verse=' . $vs;
                            $links[] = '<a href="' . h($url) . '"'
                                     . ' class="verse-ref"'
                                     . ' data-book="' . h($code) . '"'
                                     . ' data-chapter="' . $ch . '"'
                                     . ' data-verse="' . $vs . '">'
                                     . $ch . ':' . $vs . '</a>';
                        }
                        echo implode(', ', $links);
                    ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>

<?php endif; ?>
</main>
<?php require __DIR__ . '/bible_sidebar.php'; ?>
</div>
<script src="js/verse-tooltip.js"></script>
</body>
</html>
