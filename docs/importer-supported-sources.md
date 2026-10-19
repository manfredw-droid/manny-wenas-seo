# Importer: supported sources in 1.0.6

**Decision (owner, for 1.0.6):** the importer offers only **Yoast SEO** and **Rank Math**. Import from **All in One SEO**, **SEOPress** and **The SEO Framework** is switched off.

## Why

Those three adapters wrote their values under keys starting `_mw_seo_` (for example `_mw_seo_title`, `_mw_seo_description`, `_mw_seo_focus_kw`, `_mw_seo_robots`). The plugin reads `_mwseo_title`, `_mwseo_desc`, `_mwseo_focus`, `_mwseo_noindex` and `_mwseo_nofollow`, and nothing reads `_mw_seo_*`. An import therefore reported success but changed nothing on the site. Whether their source formats were read correctly is also unproven, because none of the three plugins has been installed and checked. Repairing them needs real source data and is a separate piece of work.

## What changed

- The Import page lists only Yoast SEO and Rank Math, also when the other three plugins are active. Yoast and Rank Math stay available when their plugins are inactive, as before.
- The server checks the source before it reads or writes anything. Preview, Dry run and a real import (with or without Overwrite) for All in One SEO, SEOPress, The SEO Framework or an unknown source are refused. The page shows: "Import currently supports Yoast SEO and Rank Math. Support for the other sources is switched off." An unknown source is reported as "Unknown source plugin".
- The nonce and the `manage_options` check run first, as before.
- Nothing else changes: the Yoast and Rank Math import, Preview, Dry run and Overwrite behave as before, and existing native data, source plugin data and earlier `_mw_seo_*` records are left untouched. There is no migration and no cleanup. The old adapter code stays in the file but cannot be reached from the page or the AJAX action.

## Tests

- `php tests/importer-dry-run-wordpress.php /path/to/disposable/wordpress` (real WordPress, `solstice_dry_` table prefix, own fixtures): Yoast and Rank Math keep their Preview / Dry run / import / Overwrite cases; the three disabled sources and an unknown source are refused for every combination of dry run (`1`, omitted, `0`) and Overwrite, with zero metadata write attempts, zero metadata SQL writes, identical stored rows, and unchanged legacy `_mw_seo_*` and native records; an invalid nonce and a request without capability are rejected; the page offers only the two supported sources both with no source plugin present and with all five plugins simulated present.
- `node tests/importer-dry-run.cjs`: UI routing for the two supported sources, and the message shown for a refused source.

Plugin detection (the five detection constants) and all source metadata in these tests are simulated; no source plugin is installed. Verified on WordPress 6.4.5, PHP 8.3.6, SQLite. Not verified: PHP 7.4, MySQL/MariaDB, the real source plugins, a browser run.
