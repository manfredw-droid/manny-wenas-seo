=== Manny Wenas SEO ===
Contributors: mannywenas
Donate link: https://paypal.me/manfr3dw
Tags: seo, schema, sitemap, llms.txt, search console
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.8
License: GPLv2 or later

Lean SEO: semantic keyphrase scoring, connected JSON-LD schema, sitemaps, llms.txt, Search Console and AI-agent abilities.

== Description ==

Write it your way.

At a conference in 2011, journalists asked whether they really needed to repeat a keyphrase fifteen times in an article to rank in Google.

The answer was yes.

That answer stayed with me. Years later, I found myself adding awkward repetitions to an otherwise finished article because my SEO plugin said the keyword density was too low. The score improved. The story didn’t.

That is why I built Manny Wenas SEO.

Manny Wenas SEO helps you make your subject clear using semantic matching rather than requiring the same exact phrase everywhere. There is no keyword-density target to chase. Its score reflects the checks it can perform — not the quality of your ideas, your voice or your story.

Five placement checks cover the title, introduction, subheadings, slug and meta description. You remain the writer.

An optional Search Console connection shows you the actual queries people used to find your posts — inside WordPress, when you want it. The goal is simple: less time managing SEO, more time writing something worth reading.

* Focus keyphrase (semantic matching) plus up to two related keyphrases
* SEO title, meta description, noindex/nofollow per post
* Open Graph and X/Twitter cards
* Sitemaps: posts, news, images, videos, categories, tags, authors, plus an HTML sitemap shortcode
* robots.txt management and llms.txt built when requested (anchor posts first)
* Connected @graph JSON-LD, server-side rendered
* Yoast SEO and Rank Math coexistence (we defer: no duplicate meta, schema or sitemaps)
* Google Search Console (OAuth), per-post query data
* WordPress Abilities API (WP 6.9+)
* 0-100 SEO and readability score with a written verdict in the editor

From version 1.0.7 (Equinox), SEO controls require the WordPress block editor and a post type with custom-fields support. Classic edit screens and enabled post types without the required support show an explanation instead of SEO controls. The plugin does not add support to external post types.

Existing saved SEO metadata and frontend output continue to work independently of the editor. Save or update the post explicitly to persist changed SEO input: an author's draft autosave can update draft content while retaining the last explicitly saved SEO values. Recovery of unsaved draft SEO after a crash is not guaranteed.

Deliberately excluded: redirects, 404 monitor, analytics dashboard, AI writer.

== External Services ==

This plugin optionally connects to the following third-party services. All external 
connections are disabled by default and must be explicitly enabled by the site administrator.

**IndexNow** (optional, disabled by default)
When enabled, notifies search engines of new or updated content via the IndexNow protocol.
Sends: the URL of the published/updated post, plus the site's IndexNow API key.
Triggered: when a post is published or updated, if the feature is enabled (Manny Wenas SEO > Verification & IndexNow).
Service provider: IndexNow (Microsoft/Bing)
Terms of use: https://www.indexnow.org/documentation
Privacy policy: https://privacy.microsoft.com/en-us/privacystatement

**Google Trends** (optional, disabled by default)
When enabled, shows a search-interest score for your focus keyphrase in the post editor.
Sends: the focus keyphrase you typed, to trends.google.com (unofficial endpoint, no account or API key).
Triggered: about 800 milliseconds after you stop typing the focus keyphrase in the native SEO sidebar, if the feature is enabled (Manny Wenas SEO > General). Results are cached for 24 hours, failed requests for 1 hour.
Service provider: Google LLC
Terms of use: https://policies.google.com/terms
Privacy policy: https://policies.google.com/privacy

**Google Search Console** (optional, OAuth-based)
When enabled, connects to your GSC account to display search query data for a post.
Sends: OAuth tokens managed by the site administrator and, when the SEO sidebar asks for query data, the URL of the post. No post content is sent.
Triggered: only after the GSC integration is manually authorized by the administrator.
Service provider: Google LLC
Terms of use: https://policies.google.com/terms
Privacy policy: https://policies.google.com/privacy

== Changelog ==

= 1.0.8 =
* Added: a first-time "Get started" screen. It offers a previewed import of supported post SEO data from Yoast SEO or Rank Math, then a site identity step (name and logo). It opens once after a new installation, can be skipped, and is never forced on existing sites; "Get started" stays in the menu
* Changed: settings use a left navigation with section descriptions and a stable save bar; the logo and default social image are chosen from the media library (the stored attachment ID is unchanged)
* Changed: the editor panel groups the SEO fields, labels the number as the score of automated checks (not a judgement of your writing) and drops the redundant quick-navigation row. The slug link, heading navigation, analysis and Search Console tools remain
* Changed: administration texts that were still Dutch are now English translation sources
* Removed: the paragraph about a separate Pro AI service from the readme's External Services section; this plugin has no such feature
* Unchanged: scoring (version 1.2), stored SEO data, frontend output and Search Console behaviour

= 1.0.7 =
* Changed: Equinox moves all SEO controls into a native Gutenberg sidebar, saved through WordPress editor metadata. Edit the slug using WordPress's own control
* Preserved: existing SEO values, written analysis, heading navigation, optional Search Console queries/suggestions and Trends, and the working PHP/frontend core
* Removed: Classic Editor SEO metaboxes, their POST save handler, TinyMCE bindings and the duplicate slug adapter. Unsupported edit screens explain that the block editor is required; saved SEO remains active
* Fixed: intentional empty values and disabled flags remain authoritative; untouched fields and title-only REST updates retain their data. Editable metadata supports WordPress revisions and published-post autosave recovery
* Fixed: the derived score is excluded from editable REST responses so Gutenberg saves refresh it from the final stored inputs; explicit API score operations remain supported
* Fixed: enabled post types without custom-fields support load no SEO editor assets and show a native Gutenberg notice; Classic retains the PHP notice. No support is automatically added to external post types
* Known limitation: changed SEO input requires explicit Save Draft, Save or Update for durable storage; native draft autosave does not guarantee recovery of unsaved SEO input. The independent review found no autosave regression
* Scoring version remains 1.2; no scoring rules or stored scores are migrated

= 1.0.6.1 =
* Fixed: in the block editor, a repeated editor-initialisation callback could cause a JavaScript error and a blank editor; it is now ignored safely once the slug adapter has started

= 1.0.6 =
* Changed: the importer now offers Yoast SEO and Rank Math only; import from All in One SEO, SEOPress and The SEO Framework is switched off, because those imports did not fill the plugin's own fields. Data already written by those imports is not changed
* Fixed: the importer's Dry run checkbox is now honoured for every source; a checked Dry run could previously write data for some sources
* Fixed: the AI ability that lists low-scoring posts now honours its threshold; it returned every scored post before
* Fixed: llms.txt now leaves out posts marked noindex, including when they are anchor (cornerstone) posts. llms.txt is built when it is requested; the settings text no longer says it is regenerated weekly
* Fixed: Article wordCount in the schema now counts words the same way as the analyzer, including accented and non-Latin text
* Fixed: a repeated schema build could emit a publisher logo reference without its image; fixed
* Fixed: the Classic Editor now analyzes the saved content when the editor opens
* Fixed: SEO score updates after content or SEO field changes made through the REST API; the stored score is refreshed after the post is saved
* Fixed: block navigation, error display and keyboard focus in the editor Companion on WordPress 6.4
* Changed: uninstall no longer deletes your SEO titles, descriptions, keyphrases, indexing settings, stored scores or plugin settings; it removes only operational data such as the Search Console connection, llms.txt snapshot, caches and the schedule
* Scoring (version 1.2): related keyphrases are not scored when no focus keyphrase is set. Re-analysing a post can raise or lower its score; stored scores are not migrated and change only when a post is analysed again
* Scoring: word count below 300 words is now an advisory notice only, and the Technical section is 11 points (image alt text 8 + clean slug 3). With no focus keyphrase, all keyphrase checks show N/A instead of 0. The opening paragraph check falls back to the first block of body text when content uses div elements
* Changed: the readability message states the average sentence length without calling the text easy to read; scoring is unchanged
* Docs: added PHILOSOPHY.md (scoring rubric and founding principles), AMENDMENTS.md (intentional change record) and a process amendment on proportional testing

= 1.0.5 =
* Fixed: Search Console "Suggest" button now shows a clear "not connected" message when GSC is not linked
* Removed: Dead internal methods pages_report() and youtube_id()
* Fixed: Uninstall now cleans up Trends transients (mwseo_trends_*)

= 1.0.4 =
* Compliance: the ABSPATH direct-access guard (`defined( 'ABSPATH' ) || exit;`) is now the first statement of every file in includes/
* Packaging: removed the Domain Path header until translation files ship

= 1.0.3 =
* Compliance: every PHP file in includes/ now starts with the standard ABSPATH direct-access guard
* Packaging: the languages/ folder (Domain Path) is always present in the distribution

= 1.0.2 =
* Compliance: removed pro-tier licence gate; all included features are now fully functional
* Security: replaced wp_salt('auth') with a neutral site-specific hash seed in schema output
* Code quality: replaced inline script tag in importer with wp_add_inline_script()
* Privacy: IndexNow and Google Trends integrations are now disabled by default and require explicit opt-in
* Docs: added External Services section to readme.txt disclosing all third-party connections
* Hardening: tightened REST API permission callback for low-scoring posts endpoint
* Branding: updated Plugin URI to mannywenas.com

= 1.0.1 =
* New 100-point scoring system (Text & Content 45, Placement 30, Technical 15, Optional 10)
* Optional keyphrases field: shows N/A instead of 0 when empty
* Alt text check is now accessibility-based (any non-empty alt passes)
* Word count under 300 words is now a notice, not a score deduction
* External links: advisory notice only, no point deduction
* Rank Math / Yoast title templates rendered before scoring
* All user-facing strings are now i18n-ready (.pot included)

= 1.0.0 =
* Initial release.
