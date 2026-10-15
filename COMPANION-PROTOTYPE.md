# Manny Wenas SEO — Editor Companion prototype

**Status: internal prototype built on the frozen 1.0.6-RC1. Not released, not production-ready.**
It does not resolve or override any public-release governance hold on RC1.

Baseline: `manny-wenas-seo-1.0.6-RC1.zip`, SHA-256
`6840F92AE26A88D2548976B28FE1E4B6F31188E7E99AD47DEA75FC3F46FA3C96` (verified before any change).
Prototype version string: `1.0.6-companion-proto.1` (so assets cache-bust apart from RC1).

## What it adds
- **Block editor:** a native `PluginSidebar` (pinned Manny logo in the editor toolbar; also in the Options menu).
  Score, "Go to" buttons, the article's real H2/H3 headings, and the existing checks with optional jump links.
- **Classic Editor:** a compact box, last in the native side column, with the same content.
- **Heading navigation:** Gutenberg uses `core/block-editor` (`getBlocksByName`, `selectBlock`). Classic uses
  TinyMCE (`selection.setCursorLocation` / `scrollIntoView`) or textarea selection on the Text tab.
- **SEO navigation:** focuses existing fields (`#mwseo_focus`, `#mwseo_title`, `#mwseo_desc`, related) or WordPress's
  own URL control. No fields, slugs or text are created or changed.
- **Logo:** `assets/images/mwseo-logo.svg` is now a traced copy of the approved artwork (RC1's file was a simplified
  line drawing). Transparent PNGs 32–512 px added.

## Files
Added: `includes/class-mwseo-companion.php`, `assets/js/companion-core.js`, `assets/js/companion-block.js`,
`assets/js/companion-classic.js`, `assets/css/companion.css`, `assets/images/mwseo-logo-{32,64,128,256,512}.png`,
this file.
Modified: `assets/images/mwseo-logo.svg` (approved geometry), `assets/js/metabox.js` (+2 lines: `window.mwseoLast = res;`),
`includes/class-mwseo-plugin.php` (+1 line: `MWSEO_Companion::init();`), `manny-wenas-seo.php` (version string only).
Everything else, including the scorer, analyzer, REST, meta and metabox classes, is byte-for-byte RC1.

## Known limitations
See the full report delivered with this package.
