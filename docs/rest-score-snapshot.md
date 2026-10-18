# Stored score snapshot — Finding #20

For the 1.0.7 editor/storage integration, see [native Gutenberg editor](native-gutenberg-editor.md). The verification section below records historical 1.0.6 evidence.

The owner-authorized policy preserves `_mwseo_score` as a derived snapshot. Alongside existing Abilities writes, successful relevant ordinary
REST saves refresh it after WordPress has saved both the post and its metadata.
This changes update timing, not scoring: `SCORING_VERSION` stays **1.2**.

## Scope and precedence

- Configured `mwseo_options.post_types` (default post/page), dispatched through
  the exact standard `WP_REST_Posts_Controller` create/update callback. Pages and
  configured CPTs using that controller are included. Custom controllers,
  subclasses (including attachments), and manually invoked hooks are not covered.
- Creation receives an initial snapshot, including legitimate empty-body drafts.
  Updates refresh when explicitly supplied parameters include `content`, `title`
  or `slug`, or meta includes `_mwseo_title`, `_mwseo_desc`, `_mwseo_focus` or
  `_mwseo_related`. Supplying an empty value still counts. Schema defaults alone
  do not count. Excerpt, status, robots and cornerstone-only updates do not trigger
  analysis. External filters/renderers whose inputs change through other fields
  are outside this bounded invalidation policy.
- Any explicit `_mwseo_score` operation in the same request takes precedence,
  even combined with content/meta changes. This includes zero and null/reset.
  Existing registration, permissions and RDI reset containment remain intact.
- Autosaves and revisions are excluded. In particular a core draft autosave can
  change the parent content while intentionally retaining its previous snapshot.
  This is not a guarantee of universal snapshot freshness.

The `rest_after_insert_{$post_type}` hook runs only after successful standard
content/meta processing. The saved post is reread and the existing `analyze_post`
and `MWSEO_Meta::set` paths are used. The callback checks `edit_post`; it grants no
new permissions. It calculates at most once for each request object/post pair.
From 1.0.7 there is no Gutenberg metabox POST writer. The derived score is absent from edit-context REST metadata, preventing core from echoing it back as an explicit score operation. An unchanged stored score avoids a redundant write.

## Failure behavior

Only an integer result in 0–100 is accepted; **zero is valid**. Missing/malformed
results, analysis exceptions and failed score persistence retain the previous
snapshot and log a generic diagnostic with the post ID, without editorial content
or exception details. A failed refresh does not undo a successful content save or
turn that already-completed save into an HTTP error. No retry or fake zero is used.
REST failures before the final hook do not trigger refresh; this does not make
WordPress's other update operations transactional.

No historical scores are migrated, no version metadata is added, and read/live
analysis requests do not write a score. Imports, direct metadata calls, changed
site options and custom controllers are not newly covered. Scoring, metadata ownership and disclosure protections remain. Classic SEO editing was removed in 1.0.7; WordPress REST authentication and capabilities protect native saves.

## Verification

`tests/rest-score-snapshot.php` checks guards, supplied-vs-default parameters,
deduplication, explicit score precedence and malformed/error results using isolated
test doubles. `tests/rest-score-snapshot-wordpress.php /path/to/wp-load.php` uses
actual REST dispatch, metadata events, hook ordering and database reads. It refuses
a database prefix not starting `solstice_scorefix_`; use only a disposable site
with Manny activated and administrator ID 1. Its test-only preinitialized hooks
configure two fixture CPTs; created posts are deleted after the run. Run it in a
separate CLI process, because core defines `DOING_AUTOSAVE` during autosave tests.

Recorded execution: **64/64 actual WordPress checks on 6.4/PHP 7.4.33**. The
unchanged baseline fails 28 of those checks, including stale 100 versus fresh
31/38/18 for content/meta/combined requests. Actual authenticated HTTP content
and metadata saves finish at 31/31 and 38/38. Real in-app Chromium Classic Text
and Gutenberg Code saves finish at 18/18. Gutenberg performs one REST analysis
(45), then one metabox analysis with the changed focus (18); neither is suppressed.
The positive-score column follows the refreshed values. Its existing rendering
of a valid zero as a dash is preserved, not fixed by this task.

Isolated snapshot checks: **39/39** on PHP 7.4.33 and 8.3.33. Existing focused
scorer checks: **23/23** and metadata-disclosure checks: **79/79** on both.
Existing JavaScript suites: importer 40/40, Companion 15/15, Classic initialization
34/34. All three PHP files pass syntax checks on both PHP versions.
Browser/editor evidence and detailed results are in the accompanying local
verification report. Isolated checks do not certify other WordPress runtimes. No actual
Abilities calls were made; the separate Abilities-list query observation remains
open and is not corrected here.

**Open release action:** before publication, the public changelog must state that
reanalysis under scoring 1.2 can raise **or lower** scores. Stored scores are not
automatically migrated. This change grants no release or Gold approval.
