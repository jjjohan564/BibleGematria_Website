# Bible Gematria Website

Desktop/local website and static GitHub Pages build for the Bible Gematria / Mispar project.

## Contents

- `web/` - full PHP/MySQL local website.
- `docs/` - static GitHub Pages build with embedded offline data.
- `scripts/` - import and maintenance pipeline helpers.
- `sql/` - database schema files.
- `BibleGematria.command` - Mac launcher for the local PHP app.
- `BibleGematria-iPhone-Share.command` - local-network launcher for iPhone Safari.

## Current Features

- Hebrew OT and Greek NT gematria analysis.
- Standard, ordinal, and reduced verse/word values.
- Numeric search filters for Standard/Ordinal/Reduced, Testament scope, Verse Matches, and Word Matches.
- English/Hebrew/Greek text search with Testament scope.
- Same-chapter multi-verse references such as `Isaiah 53:1-12`.
- Split prime-index and factor display.
- NT text selector for NA/NA27-compatible text and TR, persisted until changed.
- Static offline page in `docs/index.html` matching the current iPhone app HTML.

## Private Files

Do not commit local credentials or private source data:

- `config.ini`
- `web/config.php`
- `data/raw/`
- SQL dumps and zip dumps
- logs

These are intentionally covered by `.gitignore`.
