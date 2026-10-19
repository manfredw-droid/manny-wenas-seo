# 1.0.6 release candidate — checklist before Gold

*Status record, not an approval. It does not authorise publication, a tag or Gold,
and it takes no risk acceptance on the owner's behalf. Scope: 1.0.6 as merged up to
PR #26 plus this preparation PR (documentation corrections included). `SCORING_VERSION` stays `1.2`. Finished findings
are reopened only on concrete new evidence (see PROCESS-AMENDMENTS.md).*

## Evidence status for the candidate package

Candidate: `manny-wenas-seo-1.0.6-rc.zip`, SHA-256 `0a2d7849442467731d86fbaf268da98d26433cb2bb1dabef14d00a75d95a6beb`
(40 files, built from `9fd83164b64e713a2c31766a3f2bf52e3aa0d57f`). Documentation-only commits on this branch do not change
the packaged files; the package stays valid as long as the 40 packaged files are unchanged.

| Evidence | Scope | Status |
|---|---|---|
| Package contents, manifest, byte comparison with the source | This package | Done |
| Install, activate, deactivate, re-activate, frontend/admin/REST smoke | This package on WordPress 6.4.5, PHP 8.3.6, SQLite | Done |
| Plugin Check 2.1.0 with runtime checks | This package on WordPress 6.4.5 (SQLite) | Done: 0 errors, 7 warnings. The current-version validation (`Tested up to` against the latest WordPress, plugin-directory lookups) **was skipped**: it needs api.wordpress.org, which was unreachable. It is not a pass |
| Static PHP 7.4–8.4 compatibility (PHPCompatibilityWP) | This package | Done: 0 findings |
| WordPress 7.1.3: lifecycle, real Abilities API, frontend, block editor, Plugin Check | This package | See the 7.1.3 verification report delivered with this PR; it is limited to the checks listed there and is not a full verification |
| WordPress 7.1.3 / PHP 8.3.33 lifecycle and uninstall (60/60) | **Historical**: an earlier commit and another run (`docs/uninstall-data-preservation.md`) | Limited evidence, not a verification of this package |
| WordPress 6.4 vs 7.1.3 source comparison of the editor selectors | **Historical**, source reading only (`docs/companion-ui-fixes.md`) | Not runtime evidence |
| PHP 7.4.33 runs (uninstall, REST snapshot, schema, importer, llms) | **Historical**: earlier commits on WordPress 6.4 | Not a run of this package; PHP 7.4 has not run PRs #22–#26 or the Ability fix |

## A. Necessary checks (each needs its completion criterion met)

| # | Check | Completion criterion |
|---|---|---|
| A1 | Merge of the preparation PR | PR merged into `development/1.0.6-companion`; version in plugin header, `MWSEO_VERSION` and `Stable tag` all read `1.0.6` on the final commit |
| A2 | Release package | **Done for the candidate above**: only the intended files, SHA-256 recorded, `COMPANION-PROTOTYPE.md` and the governance/process documents deliberately left out, activates and deactivates without PHP errors. Rebuild only if a packaged file changes |
| A3 | Final candidate smoke | **Done on WordPress 6.4.5**; the WordPress 7.1.3 smoke is reported separately (see the evidence table). Not run: MySQL/MariaDB, PHP 7.4 |
| A4 | Compatibility statement | Owner decides `Tested up to` and `Requires PHP 7.4` on the evidence above: `Tested up to: 7.1` has a limited, partly historical basis (see the evidence table) and the tool-based currency check could not run; PHP 7.4 rests on static analysis of this package plus historical runs |
| A5 | Translation catalogue | **Done**: `languages/manny-wenas-seo.pot` regenerated with WP-CLI i18n (211 → 244 entries); repeat only if translatable strings change |
| A6 | Plugin Check, current-version validation | Run Plugin Check where api.wordpress.org is reachable and record the `*_tested_upto_header` results (command in the Plugin Check report) |
| A7 | Owner decision | The owner records the Gold decision. Nothing in this repository grants it |

## B. Limitations to be accepted explicitly by the owner (listed, not accepted here)

| # | Limitation | What the owner decides |
|---|---|---|
| B1 | The Ability fix (PR #26) was checked with a registration shim on WordPress 6.4.5; a real Abilities API run exists only in the WordPress 7.1.3 verification report | Accept as documented |
| B2 | Runtime evidence is mainly SQLite; MySQL/MariaDB and PHP 7.4 only in historical runs on earlier commits | Accept, or name extra environments |
| B3 | Existing stored scores are not migrated; a re-analysis under scoring 1.2 can raise or lower a score (stated in the changelog) | Accept as documented |
| B4 | Import supports only Yoast SEO and Rank Math; data already written by the switched-off importers is not repaired | Accept as documented |
| B5 | Finding #12: conditionally closed, WordPress core sample-permalink race accepted as an upstream limitation | Already decided; revisit only if WordPress core changes (reassessment, not automatic reopening) |
| B6 | The unnumbered "Free-content" finding is kept separate from #12 | Owner states whether it falls inside 1.0.6 scope |
| B7 | The historical constitutional test suite exists only on the `constit` branch, not on the target branch | Accept, or require it to be brought over |
| B8 | Plugin Check warnings (7): `error_log()` in a catch, uninstall direct query and variable prefixes, and a mis-placed `phpcs:ignore` in `class-mwseo-llms-txt.php` | Accept with the stated reasons, or ask for the optional ignore-line move (which changes a packaged file and needs a new package) |

## C. Deferred improvements (not blocking; candidates for a later version)

- Sitemap work (whitespace output, further changes) stays out of 1.0.6 unless concrete evidence demands otherwise.
- Invalid UTF-8 in the schema word count (`count(false)`), a test that detects missing `wpautop`/`strip_shortcodes`, and shortcode-registration differences per context (from the PR #22 review).
- Repair path for legacy `_mw_seo_*` data written by earlier imports; importer adapters for other plugins only with real exports as evidence.
- Taking the readability heuristics beyond English-oriented Flesch behaviour (needs a scoring version and an amendment).
- Overwrite-off hardening of the importer refusal test (PR #25 note).
