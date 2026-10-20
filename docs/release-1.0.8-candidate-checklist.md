# Manny Wenas SEO 1.0.8 — release candidate checklist

Status: **release candidate for independent review. Not a Gold decision.** No tag, GitHub release, WordPress.org upload or merge to `main` has been made.

## Source and package

- Branch `implementation/1.0.8-interface`, based on `main` `9b5e19c55d94fef4abc9ad715b997367c3ab5739` (1.0.7 plus the donation-link documentation).
- Package (release candidate 2) built from code commit `01e4fc1c2794786e3227900a5f72f35370820449`. Later commits on the branch change documentation only; the 41 distribution files are identical. RC2 differs from RC1 (built from `09f19c6f…`) in exactly two files: `includes/class-mwseo-importer.php` (the setup screen's preview hint) and `languages/manny-wenas-seo.pot` (php-format flags).
- Version 1.0.8 in the plugin header, `MWSEO_VERSION`, the readme *Stable tag* and the POT. `SCORING_VERSION` is **1.2**. Scorer, analyzer, head/schema, sitemaps, llms.txt, GSC, REST and the metadata registration are byte-identical to 1.0.7.
- `manny-wenas-seo-1.0.8-rc2.zip`: 41 files under `manny-wenas-seo/`, 162148 bytes, SHA-256 `fa6bad1301f95ff5513bc48bbb0fb91cc6bade93cd340647c7ef8190033423e5`. Allowlist: plugin file, `uninstall.php`, `readme.txt`, `LICENSE`, `assets/`, `includes/`, `languages/`. No tests, docs, Git data or build files. Per-file lengths and SHA-256: `docs/release-1.0.8-rc2-manifest.csv`.
- All 41 packaged files were compared byte for byte with `git archive` of the code commit `01e4fc1c` (0 differences). Against the 1.0.7 package built the same way: 12 files changed (`readme.txt`, `manny-wenas-seo.php`, `assets/css/admin.css`, `assets/css/companion.css`, `assets/js/companion-block.js`, `includes/class-mwseo-{companion,importer,notices,plugin,settings,verification}.php`, the POT) and 3 are new (`assets/js/admin.js`, `includes/class-mwseo-admin.php`, `includes/class-mwseo-setup.php`).

## What changes for writers

New "Get started" screen (import first, then site identity), shown once after a *new* installation and never forced on existing sites; settings with a left navigation and a stable save bar; the logo and default social image picked from the media library (stored attachment ID unchanged); a clearer editor panel whose number is labelled as the score of automated checks. Existing SEO data, scoring and frontend output are unchanged.

## Findings fixed during this preparation (including RC2)

- After an import preview the setup screen told writers to untick "Dry run", an option that screen does not show. It now says to click "Run Import"; the Import page keeps the original instruction.
- Eight messages with printf placeholders lacked `php-format` flags in the POT. It was regenerated with WP-CLI 2.12.0 `i18n make-pot`: no message ID missing, exactly one added (the new setup hint), eight flags added; a test checks every printf message is flagged.

- The one-time setup flag was global: another administrator's page view could consume it. It now belongs to the activating user (WP-CLI activations let the first administrator use it).
- `tests/uninstall-preservation.php` had been failing on 1.0.7 (missing `metadata_exists()` double); the harness now has it plus a double for the new setup class and a check that reactivation is not flagged as a new installation. Product code unaffected.

## Checks actually run (disposable sites, SQLite)

| Check | WordPress 7.1.3 / PHP 8.3.6 | WordPress 6.4.3 / PHP 8.3.6 |
| --- | --- | --- |
| `interface-wordpress.php` (installed from the ZIP) | 70/70 | 70/70 |
| `native-editor-wordpress.php` | 41/41 | 41/41 |
| `editor-custom-fields-wordpress.php` | 23/23 | 23/23 |
| `importer-dry-run-wordpress.php` (ZIP contents) | 42 PASS, 0 FAIL | 42 PASS, 0 FAIL |
| `native-frontend-wordpress.php` (HTTP; run on RC1, no frontend file changed in RC2) | 9/9 | 9/9 |
| Browser: ZIP uploaded and installed through wp-admin, activated, setup landing, import preview then write, post and page saved in the block editor and reopened, frontend title/description/canonical/schema, sitemap, plus the setup and Import-page preview hints | 23/23 | 23/23 |
| Browser: setup, import gating, media picker, settings sections, 390 px width, scoping, post and page editor panel | 50/50 | 50/50 |

Script tests: `importer-dry-run.cjs` 40/40, `companion-ui.cjs` 9/9, `rest-score-snapshot.php` 39/39, `related-without-focus.php` 23/23, `schema-word-count.php` 35/35, `uninstall-preservation.php` 61/61. PHP syntax (PHP 8.3) and `node --check` pass for every PHP and JavaScript file; `git diff --check` is clean.

## Plugin Check 2.1.0 (static checks, run on the extracted ZIPs, same driver for both)

| | 1.0.7 (`main`) | 1.0.8 candidate |
| --- | --- | --- |
| Errors | 0 | 0 |
| Warnings | 7 | 7 — the same seven, at the same files and lines |

The warnings: `error_log` (`includes/class-mwseo-rest.php:97`), interpolated SQL (`includes/class-mwseo-llms-txt.php:233`), and in `uninstall.php` (line 24) a direct query, no caching and three non-prefixed variables (lines 24, 30, 31). None is new; none is accepted or hidden. The new files (`class-mwseo-admin.php`, `class-mwseo-setup.php`, `admin.js`) produce no findings. A deliberately bad control plugin produced 9 errors and 14 warnings, so the driver detects problems.

## Not run, and limits

- **PHP 7.4 was not run** (only PHP 8.3 is available here). WordPress 6.4.3 was run, on PHP 8.3. PHPCompatibility 9.x cannot detect 8.x-only features, so a pattern scan for PHP 8-only functions and syntax over the 41 files was used instead (no hits); that is not a 7.4 execution.
- **Plugin Check runtime-enabled checks were not run**: the runtime environment could not insert test posts on SQLite. The 1.0.7 release checklist recorded eight warnings for runtime-enabled runs; this static driver shows seven for the same 1.0.7 code. Nothing indicates a new warning, but the full runtime set should be rerun on MySQL/MariaDB. The version-currency check needs the WordPress.org API, which is unreachable from this environment.
- Live Search Console and Trends were not exercised. The `solstice_*` suites for untouched areas (scoring, schema, llms.txt, abilities, uninstall on a database) were not rerun.
- Browser checks: Chromium only; screen reader and colour-contrast measurements were not made. The import-preview race is covered by script doubles, not by timing a real batch.
- Pre-existing, not changed here: with debugging on, `MWSEO_Meta::register()` makes WordPress 6.4+ log "Meta keys cannot enable revisions support unless the object subtype supports revisions" for an enabled post type that does not support revisions (seen with the custom post type used by the editor test). It exists in 1.0.7 and does not occur for posts and pages.
- The RC ZIP was built by script from Git blobs; it is not a WordPress.org SVN tree.
