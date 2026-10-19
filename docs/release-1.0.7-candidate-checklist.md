# Equinox 1.0.7 release candidate — 2026-10-19

Status: candidate prepared; not a Gold decision or publication approval.

## Source and package

- Reviewed base: `6cc8d1c3a61ce3837c2e2db2c0b833430cb361fd`, verified as the current remote `development/1.0.7-gutenberg` head before preparation; source worktree clean.
- Preparation branch: `release/1.0.7-rc-prep`. Original implementation worktree preserved.
- Release changes: readme requirements/changelog explicitly explain Gutenberg, custom-fields support, retained data/frontend output, and explicit-save durability; POT support slug corrected and timestamp refreshed; this checklist added.
- Plugin header, `MWSEO_VERSION`, Stable tag and POT project version: **1.0.7**. `SCORING_VERSION`: **1.2**. No PHP, JavaScript, CSS, scoring or GSC behavior changes.
- ZIP: `manny-wenas-seo-1.0.7-rc1.zip`, **150850 bytes**, **38 files** under `manny-wenas-seo/`.
- SHA-256: `2d102136fd36dec3e6ccc8b5d716216a51fbe453e3a001e89b2d1d1d5758401d`.
- Allowlisted runtime files only: root plugin/uninstall/readme/license plus assets, includes and languages. No Git data, tests, development documentation, source/build directories, dependencies, temporary output or other work files.
- Manifest records every file's length and SHA-256. 36 packaged files match reviewed Git blobs byte for byte; only readme and POT contain the declared release changes. POT message IDs preserved; no translation catalog overwritten.

## Checks against the installed ZIP

Actual combination: WordPress **7.1.3**, PHP **8.3.33**, MariaDB **11.4.13**, mysqli, Windows localhost; Classic Editor **1.7.0**, Plugin Check **2.1.0**. Existing disposable environment reused.

- [x] Install ZIP, activate, deactivate and reactivate. Installed payload matches all 38 ZIP entries, without extras.
- [x] Real Gutenberg post and page: change focus/related phrases, title, description and flags, explicitly save draft, reopen and compare. Stored database fields also match.
- [x] Frontend smoke: **9/9** checks (title, description, canonical, schema, sitemap/llms inclusion and noindex exclusions, stored robots).
- [x] Enabled Gutenberg CPT without custom-fields: one visible native notice, no SEO editor assets or fields; support remains absent and existing metadata remains intact.
- [x] Classic screen: one visible PHP notice, no SEO editor assets or fields.
- [x] Plugin Check static and runtime-enabled CLI: **0 errors, 8 warnings each**, no excluded checks or ignored codes. The same eight warnings occur in both runs; an empty stderr or exit code alone is not treated as proof of test success.

## Warnings and limits

Plugin Check warnings concern unchanged reviewed code: `post__not_in`, interpolated SQL in the llms builder, REST fail-safe `error_log`, and uninstall globals/direct-query/cache conventions (five warnings). These are recorded, not fixed or accepted as a Gold decision in this preparation.

Initial lifecycle diagnostics came from stale disposable options referencing an unregistered test CPT; the test setup was normalized, then activation/deactivation completed cleanly. A duplicate bootstrap diagnostic from an initial harness invocation was corrected. Neither required a product change.

New SEO input requires explicit Save Draft/Save/Update to be durable. Draft autosave may save content while retaining the last explicitly saved SEO values; crash recovery of unsaved SEO is not guaranteed. Prior independent review classified this as no autosave regression.

Live GSC/Trends connections were not tested. This candidate was tested only on the combination above; historical compatibility/review evidence is reused, not presented as a fresh ZIP run on minimum WordPress/PHP versions. No broad matrix repeated. No tag, release, upload, main merge or Gold decision.

## Evidence

Local delivery directory: `../equinox-1.0.7-rc1/` beside the worktrees. Contains ZIP, `manifest.csv`, checksum, package/installed-payload audits, environment, browser and stored-metadata JSON, frontend results, complete static/runtime Plugin Check output and warning summaries, screenshots, `CHECKLIST.md` and `REPORT.md`.

Historical independent implementation/B1 reviews concern head `726b93cc191c1ba2fd2828a5a90dd4f992dc51d4`, merged through the reviewed base above. They were not reopened. The new checks listed here concern the installed release candidate ZIP.
