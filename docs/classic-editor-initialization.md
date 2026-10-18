# Classic Editor initial content analysis

Historical evidence for 1.0.6/1.0.6.1. Classic SEO editing and this adapter were removed in 1.0.7; see [native Gutenberg editor](native-gutenberg-editor.md).

Base: `development/1.0.6-companion`, `bdac530f8de209debe3320c42ed8f29964a730e9`.

## Cause and correction

An existing, visible TinyMCE object can return an empty string before initialization while the textarea contains the saved article. The original metabox analyzed that empty string. Its binding guard registered the editor early, so the later WordPress `tinymce-editor-init` notification did not schedule a new analysis. Operation Solstice independently reproduced this on both the pre-PR #16 baseline and its head.

The Classic content reader now uses the textarea until TinyMCE is initialized. An initialized, visible TinyMCE remains authoritative, including when its content is genuinely empty. Text mode continues to use the textarea. The existing per-editor change subscription also listens for native `init`, invalidating an earlier in-flight result and scheduling analysis once initial content is ready. No polling or additional dependencies are introduced.

Only `assets/js/metabox.js` changes production behavior. The Block Editor branch, scorer, scoring version, REST, metadata ownership, uninstall and Pro are unchanged.

## Automated checks

Run `node tests/classic-initialization.cjs` (Node.js 18 or newer). The dependency-free isolated suite includes the existing 28 content-source/routing checks and six focused initialization, empty-content and mode-switch checks. Test doubles explicitly describe TinyMCE initialization state.

The suite returns 34 passes on the correction. Against the unchanged development metabox it returns 32 passes and two failures reproducing premature empty extraction and the initialization path. The existing Companion suite returns 15 passes, compatible verdict harness 26 passes, passive-slug harness seven passes, and four production JavaScript syntax checks pass. Total: 82 isolated checks, separate from real editor verification.

## Real editor verification

Executed on disposable WordPress 6.4 / PHP 7.4.33 / Classic Editor 1.6.7 in the Codex in-app Chromium browser (reported Chrome 155). Plugin script URLs included a test-only source hash to bypass stale asset caching.

- Unchanged merged baseline: a populated 273-word article produced two premature zero-word requests; TinyMCE initialized afterward. Reproduced independently on the current base.
- Correction: two fresh Classic loads each returned 273 words. The first request used the populated textarea before initialization; the subsequent request used initialized TinyMCE, without user edits.
- Native Text edit: five words; switching to Visual preserved that text. Visual edit: three words; clearing Visual: zero words and no stale score. Native Save Draft persisted an empty body, confirmed by database read and a fresh editor GET returning zero words.
- Re-entering three words refreshed analysis, saved natively, persisted in the database and reopened with three analyzed words.
- Gutenberg: initial 273 words; editing an empty paragraph added three words and refreshed analysis to 276. Native Save draft showed “Draft saved”; database content changed accordingly and a fresh editor GET returned 276 words.
- Captured analysis requests completed successfully; no captured page errors or unhandled rejections, and final console error query was empty. No page crash occurred during completed editor checks.

No WordPress 7.1.3 browser rerun, standalone-browser certification or screen-reader test was performed in this fix pass. Earlier unexplained browser crashes remain unresolved historical limitations. One browser-control startup timed out before the test tab was established; recovery opened the intended test site, and that timeout is not counted as a page crash or a passing test. The diagnostics add observation overhead, so this is functional evidence rather than a timing-frequency benchmark.

These checks are not screen-reader certification, merge authorization or Gold approval.
