Historical 1.0.6 evidence. For current editor support, see [native Gutenberg editor](native-gutenberg-editor.md).

# Companion UI corrections

Base: `development/1.0.6-companion`, commit `fb7e57ced4efbbb2890e1ff34f181ce5cb11c772`.

## Changes

- Block navigation: WordPress 6.4 does not expose `getBlocksByName`. Use its supported `getClientIdsWithDescendants` and `getBlockName` selectors when that newer selector is absent. Nested headings and introduction paragraphs retain document order. Navigation still only calls native `selectBlock`.
- Failed analysis: the latest failed request clears the shared cached result, score, checks and subscores. A separate `mwseo:analysis-error` notification clears both Companion views; the existing localized error is rendered as text. Successful `mwseo:score` payloads are unchanged. Older requests cannot replace a newer success or failure. Successful retry restores normal rendering.
- Classic keyboard access: scrolling cannot auto-hide the body while focus is inside the Companion. Focus is checked when the animation callback executes, with `:focus-within` as a CSS safeguard. Disabling auto-hide also protects against an already queued callback. The existing focus-in reveal behavior remains.

No PHP, scoring, schemas, metadata ownership, uninstall behavior, Pro, plugin versions or WordPress writes changed. No dependencies or settings added.

## Automated verification

Run `node tests/companion-ui.cjs` from this plugin directory (Node.js 18 or newer). This dependency-free suite executes the production scripts in isolated JavaScript contexts. For selectors, test-only in-memory exports expose private helpers; the production source has no test hooks.

- 15 focused tests passed, covering fallback and current selectors, nested blocks, introduction selection, failure clearing/recovery, stale responses, unsubscribe, actual Classic rendering, and scroll/focus races.
- 26 existing verdict tests passed.
- 28 existing content-source/update-routing tests passed (only their local input/output paths adapted).
- 7 existing passive slug tests passed.
- All four production JavaScript files passed `node --check`.
- Baseline control runs reproduce the three defects with the same focused tests.
- Local WordPress 6.4 and 7.1.3 source inspection confirms the fallback selectors; only the newer source exposes `getBlocksByName`. This is source evidence, not WordPress runtime verification.

The existing WordPress test servers were not listening on localhost ports 18774 and 18783. No WordPress browser/editor tests were performed for this change. The isolated tests do not establish actual editor or assistive-technology compatibility. No PHP file changed, so PHP checks were not run.

## Browser verification still required

On WordPress 6.4 and a current WordPress installation, use both Gutenberg and Classic Editor:

1. In Gutenberg, navigate to nested H2/H3 headings and the first nonempty introductory paragraph; confirm focus changes without content edits.
2. Produce an analysis failure after a valid result; confirm both the main analysis and open Companion stop showing the previous score/checks and show the localized error. Open the sidebar after the failure, then retry and confirm recovery.
3. In Classic Editor, enable auto-hide and use only the keyboard to navigate all Companion controls. Scroll while each control is focused; it must remain visible and usable. Check entering focus during queued scrolling and disabling auto-hide while scrolling.
4. Confirm normal editor saves remain unaffected. Check keyboard/screen-reader behavior explicitly; it has not been certified by isolated tests.

These corrections are a development PR, not release or Gold approval.
