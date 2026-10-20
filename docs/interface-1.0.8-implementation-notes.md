# Manny Wenas SEO 1.0.8 — interface implementation notes

Base: `982aba328ac29a6d479ebb777038b71f17dad373` (branch `implementation/1.0.8-interface`). The plugin version stays 1.0.7; `SCORING_VERSION` stays 1.2. Nothing is pushed, merged, tagged or published.

## What changed

| Area | Change |
| --- | --- |
| Setup | New `MWSEO_Setup` screen (`admin.php?page=mwseo-setup`, menu entry "Get started", also a "Get started" link on the Plugins screen). Step 1 import, step 2 site identity, step 3 pointer to the block editor. Stateless and skippable; no completion gate. |
| First-run landing | `MWSEO_Plugin::activate()` flags a *new installation* (no stored options) with a 5-minute transient; the next administrator page load redirects once. Reactivation, upgrades and bulk/network/AJAX requests never redirect. No notice, no popup. |
| Import | `MWSEO_Importer::render_import_form()` is shared by the Import page and the setup screen; batch logic, nonce, capability checks and the server-side source refusal are unchanged. Yoast SEO and Rank Math are always selectable. Writing is enabled only after a completed preview of the current choices and is disabled again after writing or after a changed choice. The setup form has no dry-run option (Preview previews, Run writes). Notes appear when Yoast or Rank Math is still active. |
| Site identity | Existing options only (`site_represents`, `org_name`, `org_logo`, `person_user`). Logo and default social image use the native media library; the stored value is still the numeric attachment ID in the original input (still usable without JavaScript). Missing items are listed as optional. |
| Settings | Left navigation with the seven sections, section descriptions, a sticky save bar, mobile stacking. All fields and option keys unchanged. `assets/css/admin.css` is now enqueued (it was not), together with the media library and `assets/js/admin.js`, on Manny screens only (`MWSEO_Admin`). |
| Gutenberg sidebar | Fields are grouped (Keyphrases, Search result, visibility, Search Console); the redundant "Go to" row is removed (a "Edit the URL slug in WordPress" link keeps the slug capability; heading navigation and the check links remain); the badge is labelled "Automated checks score" with a note that it does not judge writing; the Analysis panel title shows how many checks need a look. |
| Palette | `docs/brand-palette.md` tokens, scoped to `.mwseo-admin` and `.mwseo-cmp`. The existing `mwseo-logo.svg` is used unchanged; the tagline is real text. |
| POT | Regenerated for the new strings with a small script (WP-CLI is not available in this environment); the extractor was validated against the base POT (identical message set). |

Not touched: scorer, analyzer, head/schema output, sitemaps, llms.txt, GSC/Trends, REST, metadata contract, uninstall.

## Tests actually run (disposable WordPress 7.1.3, PHP 8.3.6, SQLite, tables `equinox_` / `solstice_dry_`)

- `tests/interface-wordpress.php` (new): 64 checks, 0 failures (after the review fixes below).
- `tests/native-editor-wordpress.php`: 41/41. `tests/editor-custom-fields-wordpress.php`: 23/23.
- `tests/importer-dry-run-wordpress.php`: 42 PASS, 0 FAIL.
- `tests/native-frontend-wordpress.php` (HTTP): 9/9.
- `tests/importer-dry-run.cjs`: 37/37 (13 gating and race checks; the write and refused-source cases were updated: a real write is only sent after a preview of the same choices). `tests/companion-ui.cjs`: 9/9.
- Real Chromium (Playwright) against the disposable site: 50/50 checks on setup, import preview/run, media picker, settings sections, mobile width, scoping and the post and page editors; plus real plugin activation (fresh installation lands on the setup screen once; reactivation with stored options does not).

## Review fixes (after commit `c11de14`)

1. **Setup redirect:** `MWSEO_Setup::maybe_redirect()` now acts only on a plain GET page view. It never redirects a POST, `admin-post.php`, `admin-ajax.php`, `async-upload.php`, `options.php`, `update.php`/`upgrade.php`, cron or REST request, or a page view carrying an `action` parameter. The flag stays until it expires or a later plain page view, so the first landing after activation still works (checked in the browser).
2. **Import preview:** the importer script records the exact choices (source, post types, overwrite) of the last *completed* preview. `Run Import` is enabled only if the form still holds those choices when the preview finishes, any change clears it, and a write submit without a matching preview sends nothing.

## Limitations

- Only one browser (Chromium), WordPress 7.1.3. WordPress 6.4 / PHP 7.4 were not run for this change; PHP syntax was checked on PHP 8.3 only.
- The preview race is tested with script doubles (a deferred response and changed choices), not by timing a real batch in the browser. If the flag is pending when an `admin-post.php` action finishes, that action's own redirect target counts as the later plain page view and lands on the setup screen once.
- Live GSC/Trends were not exercised. The uninstall suite, scoring and other `solstice_*` suites were not rerun (untouched areas).
- The setup flag transient (5 minutes) is not removed by `uninstall.php`; it expires on its own.
- Screen reader behaviour and colour-contrast measurement were not tested; focus rings and non-colour cues were implemented, not audited.
