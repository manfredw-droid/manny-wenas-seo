# Native Gutenberg editor — 1.0.7 Equinox

## Base and boundaries

Implementation branch: `implementation/1.0.7-equinox`.
Base: `d79b898b7b260f2d20823433404c655641bd3963` (1.0.6.1 including PR #28).
Remote `development/1.0.7-gutenberg` was verified at `3a1d15f50178c635bb62e97303a24e7b03bdfa82` and is an ancestor of this base. The only subsequent changes in the companion branch are PR #28 and 1.0.6.1 release references. No merge, tag, release, publication or change to main was made.

The PHP scorer, analyzer, REST analysis/snapshot engine, frontend head/schema, sitemaps, llms.txt, abilities and importer are reused. `SCORING_VERSION` remains **1.2**. No data migration runs on load or activation.

## Editor and storage contract

- `companion-block.js` extends the existing native `PluginSidebar` with WordPress TextControl, TextareaControl and CheckboxControl fields. It uses `core/editor.getEditedPostAttribute('meta')` and `editPost({meta: patch})`, integrated with WordPress's entity save lifecycle. Mounting, opening and analysis do not dispatch metadata edits.
- Focus, two related phrases, title, description, noindex, nofollow and cornerstone remain available. The slug is edited only through WordPress. Existing ownership of title/description by another active SEO plugin is respected.
- Metadata registration provides explicit editable defaults/types, sanitization and `revisions_enabled`. Array input is sanitized and limited to two phrases. Missing keys are not deleted. Explicit empty strings, empty arrays and false are stored by native REST; getters honor their presence, so old-plugin fallbacks cannot resurrect deliberately cleared fields.
- Edit-context REST responses expose effective legacy fallbacks only for absent keys, without writing them on GET. Thus the editor cannot wipe an untouched effective fallback when core sends a complete meta object. Explicitly empty own values remain authoritative. Existing stored blank own values are also authoritative in 1.0.7.
- `_mwseo_score` is a derived snapshot. Its REST schema has `context: ['view']`, preventing Gutenberg from echoing the old score as an explicit edit and thereby bypassing the existing snapshot refresh. Existing explicit REST score writes/resets remain supported; unauthorized view responses still strip editorial metadata. The existing refresh runs after successful ordinary post/meta REST saves. Live analysis never saves scores.
- The legacy metabox registration, nonce form, POST/save_post writer, duplicate slug adapter, Classic Companion and TinyMCE bindings are removed. `MWSEO_Metabox` retains score columns and a PHP-only unsupported-editor notice. There is no second metadata or score writer in Gutenberg.
- Assets are gated by the real block-editor screen, configured post type and edit capability, never by `has_blocks()`. Classic edit screens get the notice and no Manny editor JS/CSS.

## Analysis and optional integrations

One editor-lifetime subscription reads unsaved content, SEO fields and the native slug. It waits for the matching loaded post, debounces analysis by 700ms, and invalidates outstanding responses immediately on changed input. Late success/failure responses cannot replace current results. Repeated startup callbacks do not write editor state (the PR #28 safety guarantee survives removal of its old adapter).

The sidebar reuses the scorer's written verdict, checks, section totals and advisories. Nested H2/H3 navigation, opening-paragraph navigation and field/native-slug navigation remain. Its closed/open state does not create another analysis engine.

GSC queries and related-phrase suggestions use the existing REST route. Trends uses the existing optional AJAX action/nonce, 800ms debounce and abort/cleanup on changed input. No external service, connection or consent setting was added. Scoring remains local/server-side.

## Autosaves, revisions and limits

Editable SEO fields participate in core metadata revisions. Published-post autosaves retain unsaved SEO on the revision and leave the published parent's SEO and score untouched. Core revision restoration restores those fields. Score is not revision-enabled and the existing REST snapshot guards exclude autosaves/revisions.

**Native WordPress limitation, observed on both tested versions:** an unlocked author's draft autosave updates the draft's content but retains the last explicitly saved SEO metadata. There is no custom autosave endpoint or parent-meta workaround in this change. Save Draft persists SEO inputs; recovery of unsaved draft SEO after a crash is not guaranteed. The previous Classic metabox did not autosave SEO metadata either. Published-post revision metadata recovery is covered by executable tests; browser crash/recovery itself was not simulated.

Direct metadata writes/imports and revision restoration do not gain a new universal score-refresh policy. Custom REST controllers remain outside the existing ordinary-core-controller snapshot hook. This work does not change those backend boundaries.

## Verification — 19 October 2026

Two isolated table prefixes were created using the already available portable software, with no new database server installation or production-site access:

| Check | Result |
| --- | --- |
| Real REST/storage/editor-screen integration, WordPress 7.1.3 / PHP 8.3.33 | 41 checks, 0 failures |
| Same integration on declared minimum WordPress 6.4 / PHP 7.4.33 | 41 checks, 0 failures |
| Native editor store/network regression doubles | 9 checks, 0 failures |
| Existing snapshot unit regression | 39 checks, 0 failures |
| Existing scoring 1.2 focused regression | 23 checks, 0 failures |
| Real frontend HTTP smoke, WP 7.1.3 / PHP 8.3.33 | 9 checks, 0 failures |

Storage checks cover post/page GET with no writes, unchanged save, title-only update, partial SEO meta, intentional clear/false and refill, fallback interoperation, REST schema/defaults, invalid input, permissions, preflight null-reset containment, explicit API score writes, simulated late legacy POST, final score freshness, published autosaves/revisions and the native draft limitation. Screen checks cover block, Classic and unrelated administration screens.

In-app Chromium on the primary runtime: post/page open with all fields; save/reopen; page clear/uncheck/save/reopen and refill; live scores and written analysis; heading selection and native slug focus. Console error log was empty. An actual Gutenberg save ended with stored score **68**, equal to fresh PHP analysis **68**. A Classic screen visibly showed the requirement notice; DOM inspection found no Manny editor scripts, styles or legacy fields.

Frontend HTTP smoke created a fixture without an editor visit and checked title, explicit description, canonical, graph schema, indexable sitemap/llms inclusion, robots directives and noindex exclusion from both indexes. Unchanged scoring/backend areas were not broadly retested.

GSC/Trends live account/network behavior was not exercised: the disposable site has no authorized GSC connection and no external Trends consent. Existing optional paths are retained; offline score/error/race behavior is tested. Browser interaction was performed on the primary runtime; minimum-version evidence covers real REST/backend/screen hooks plus the JS navigation fallback doubles, not a full minimum-version browser matrix.

Syntax and whitespace checks cover changed source. The POT is regenerated with official WP-CLI 2.12.0 after the final texts; the base contains a POT only, no PO/MO translations. Existing reusable message IDs (including numbered related phrases) were retained. Obsolete Classic-adapter tests were removed; their still-relevant startup, race and navigation guarantees are covered by the native tests.

## Reproduction and review map

Run only on a disposable site whose table prefix begins `equinox_`, with Manny activated and administrator ID 1:

```
php -c /path/to/test/php.ini tests/native-editor-wordpress.php /path/to/wp-load.php
php -c /path/to/test/php.ini tests/native-frontend-wordpress.php /path/to/wp-load.php
node tests/companion-ui.cjs
php -c /path/to/test/php.ini tests/rest-score-snapshot.php
php -c /path/to/test/php.ini tests/related-without-focus.php
```

The frontend smoke requires its site's HTTP server to be running and the Twenty Twenty-Five theme to be available. Tests create and delete their own posts in the guarded disposable database. They do not install WordPress or enable external integrations.

Key changes: `includes/class-mwseo-meta.php`, `includes/class-mwseo-companion.php`, `includes/class-mwseo-metabox.php`, `assets/js/companion-core.js`, `assets/js/companion-block.js`, Companion CSS, native editor/frontend tests, version/readme and POT. `assets/js/metabox.js` and `assets/js/companion-classic.js` are deleted. The existing `admin.css` keeps only shared settings rules; metabox styling is removed.

Independent review and publication remain separate owner-directed steps. No mandatory second review was added.
