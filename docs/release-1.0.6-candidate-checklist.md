# 1.0.6 release candidate — checklist before Gold

*Status record, not an approval. It does not authorise publication, a tag or Gold,
and it takes no risk acceptance on the owner's behalf. Scope: 1.0.6 as merged up to
PR #26 plus this preparation PR. `SCORING_VERSION` stays `1.2`. Finished findings
are reopened only on concrete new evidence (see PROCESS-AMENDMENTS.md).*

## A. Necessary checks (each needs its completion criterion met)

| # | Check | Completion criterion |
|---|---|---|
| A1 | Merge of the preparation PR | PR merged into `development/1.0.6-companion`; version in plugin header, `MWSEO_VERSION` and `Stable tag` all read `1.0.6` on the final commit |
| A2 | Release package | Zip built from the final commit contains only the intended files; SHA-256 recorded; `COMPANION-PROTOTYPE.md` (still describes an internal prototype) is deliberately included or excluded; the packaged plugin activates, deactivates and loads without PHP errors on a clean site |
| A3 | Final candidate smoke | On the package from A2: editor analysis runs, a settings save works, `/llms.txt` and the sitemap respond, the Abilities and REST routes load; results recorded with environment and versions |
| A4 | Compatibility statement | Owner confirms `Tested up to` (currently 7.1) and `Requires PHP 7.4`, or names the evidence behind them. Existing evidence in this project covers WordPress 6.4.x on PHP 7.4.33 / 8.3.6 only |
| A5 | Translation catalogue | `languages/manny-wenas-seo.pot` regenerated from the final commit (done in the preparation PR with WP-CLI i18n; repeat only if translatable strings change afterwards) |
| A6 | Owner decision | The owner records the Gold decision. Nothing in this repository grants it |

## B. Limitations to be accepted explicitly by the owner (listed, not accepted here)

| # | Limitation | What the owner decides |
|---|---|---|
| B1 | Abilities API (WordPress 6.9+) never run for real; the Ability fix and callbacks were checked with a registration shim | Accept as documented, or require a run on WordPress 6.9+ |
| B2 | Runtime evidence mostly WordPress 6.4.x on SQLite; MySQL/MariaDB and PHP 7.4 only where earlier tasks ran them | Accept, or name extra environments for A3 |
| B3 | Existing stored scores are not migrated; a re-analysis under scoring 1.2 can raise or lower a score (stated in the changelog) | Accept as documented |
| B4 | Import supports only Yoast SEO and Rank Math; data already written by the switched-off importers is not repaired | Accept as documented |
| B5 | Finding #12: conditionally closed, WordPress core sample-permalink race accepted as an upstream limitation | Already decided; only revisit if WordPress core changes (reassessment, not automatic reopening) |
| B6 | The unnumbered "Free-content" finding is kept separate from #12 | Owner states whether it falls inside 1.0.6 scope |
| B7 | The historical constitutional test suite exists only on the `constit` branch, not on the target branch | Accept, or require it to be brought over |

## C. Deferred improvements (not blocking; candidates for a later version)

- Sitemap work (whitespace output, further changes) stays out of 1.0.6 unless concrete evidence demands otherwise.
- Invalid UTF-8 in the schema word count (`count(false)`), a test that detects missing `wpautop`/`strip_shortcodes`, and shortcode-registration differences per context (from the PR #22 review).
- Test of the Ability through the real Abilities registry once a WordPress 6.9+ environment exists (see B1).
- Repair path for legacy `_mw_seo_*` data written by earlier imports; importer adapters for other plugins only with real exports as evidence.
- Taking the readability heuristics beyond English-oriented Flesch behaviour (needs a scoring version and an amendment).
- Overwrite-off hardening of the importer refusal test (PR #25 note).
