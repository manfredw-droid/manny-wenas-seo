# Manny Wenas SEO 1.0.9 release candidate 1: checks

Candidate package: `manny-wenas-seo-1.0.9-rc1.zip`, built from code commit `c7c7a76` (the package contains no docs or tests, so later documentation commits do not change it).

- 41 files under `manny-wenas-seo/`, 167,534 bytes, SHA-256 `97e8ee2cf9d8ed7722e811f34b0a7a6df34721bb3a206a5bd9f135690a9e71de`. Per-file lengths and SHA-256: `docs/release-1.0.9-rc1-manifest.csv`.
- Allowlist: plugin file, `uninstall.php`, `readme.txt`, `LICENSE`, `assets/`, `includes/`, `languages/` (same as 1.0.8 RC3). Built with `git archive` with line-ending conversion disabled.
- All 41 files compared three ways (ZIP stream, extracted copy, Git blob of `c7c7a76`): 0 differences.

## Translation template

`languages/manny-wenas-seo.pot` regenerated with WP-CLI 2.12.0 `wp i18n make-pot` (phar SHA-512 matched the published checksum). Same entries as the earlier script-built template, 10 `php-format` flags present; only the date, the plugin-header references and one stale line reference changed.

## Plugin Check 2.1.0 (static and runtime checks, WP 7.1.3 / PHP 8.3 on MariaDB, run on the extracted ZIPs, same driver for both)

| | 1.0.8 (`1620b86b9`) | 1.0.9 RC1 |
| --- | --- | --- |
| Errors | 0 | 0 |
| Warnings | 8 | 9 |

The same eight warnings appear in both at the same places (line numbers moved): `error_log` (`class-mwseo-rest.php`), interpolated SQL (`class-mwseo-llms-txt.php`), `post__not_in` (`class-mwseo-llms-txt.php`), and five in `uninstall.php` (direct query, no caching, three non-prefixed variables). One warning is new: `WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber` at `includes/class-mwseo-meta.php:110`. It is a false positive: the `prepare()` call receives a variable-length argument array (key pairs plus the shared `IN` value list) that the sniff cannot count; the call is exercised by 32 SQL checks. Unpacking the array (`...$params`) did not silence it and was reverted. It is not hidden with an ignore comment.

## Runtime verification on the final code

| Lane | Result |
| --- | --- |
| WP 7.1.3 / PHP 8.2.35 (production-relevant lane; run against the extracted ZIP) | every runtime suite, 0 failures; HTTP suites 9/9 and 63/63 |
| WP 7.1.3 / PHP 8.3.33, WP 7.1.3 / PHP 7.4.33, WP 6.4 / PHP 7.4.33 | all pass at `23d8955` (see the PR); not repeated for a POT-only and documentation change |

`php -l` on all 45 PHP files on 8.2.35: 0 errors. `abilities-low-scoring-threshold-wordpress` cannot run on WP 7.1.3 (real Abilities API present); it passed 27/27 on WP 6.4.

## Browser checks on the ZIP-installed plugin (WP 7.1.3 / PHP 8.2.35, disposable site)

- ZIP installed through the WordPress plugin installer (`wp plugin install <zip> --force --activate`), plugin list and admin header show 1.0.9; scoring version 1.3.
- Empty slug: a saved draft with a focus keyphrase and no slug scores the slug check N/A with "The URL slug is not available yet." (placement 14/20).
- Setup screen and Import page: a Rank Math preview with only Pages selected shows "No changes would be made with the current selections." with no Run Import/Dry run instruction.
- Posts list: Cornerstone column and "Only cornerstone" filter list the two effective cornerstone posts (own flag, old Rank Math flag) and not the opted-out one.
- Block editor: a post flagged only by Rank Math loads with Cornerstone content ticked and the document not dirty.

## Not run

- Upload of the ZIP through the wp-admin file picker (the installer path was used instead).
- MySQL, other browsers, screen readers, WordPress.org SVN layout, the version-currency Plugin Check (needs the WordPress.org API).
