# Website/App Parity - 2026-06-10

Updated the desktop/local website repo after the app improvements.

## Static Site

- `docs/index.html` is byte-identical to the current iPhone offline HTML.
- This preserves the app-side improvements on the static desktop/GitHub Pages surface:
  - numeric search by Standard, Ordinal, and Reduced values
  - Verse Matches and Word Matches filters
  - All/Old Testament/New Testament search scope
  - split prime-index and factors boxes
  - bulk verse-value export page
  - persisted NA27/TR selector
  - same-chapter multi-verse selection

## PHP Local Site

Updated the PHP website code to bring its desktop search/navigation closer to the app:

- `web/book_aliases.php` parses same-chapter ranges such as `Isaiah 53:1-12`.
- `web/index.php` uses an explicit ending-verse dropdown while keeping legacy `count=` links working.
- `web/js/dropdowns.js` syncs the ending verse dropdown and persists the NT text selection.
- `web/js/search-trigger.js` passes search scope and routes number searches to the new gematria filters.
- `web/search_lib.php` searches `gematria_word` and `gematria_verse` across Standard, Ordinal, and Reduced values.
- `web/search.php` shows URL-backed filters for Testament scope, gematria value system, and result kind.
- `web/api.php` accepts the same search filter parameters for API callers.
- `web/style.css` styles the new search filters and mobile controls.

## Verification

Passed PHP syntax checks with MAMP PHP 8.4.17 for:

- `web/index.php`
- `web/search.php`
- `web/search_lib.php`
- `web/api.php`

Live database search was not run from this clean GitHub package because `web/config.php` and the private local database are intentionally not included.
