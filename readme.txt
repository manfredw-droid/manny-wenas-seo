=== Manny Wenas SEO ===
Contributors: mannywenas
Tags: seo, schema, sitemap, llms.txt, search console
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.5
License: GPLv2 or later

Lean SEO: semantic keyphrase scoring, connected JSON-LD schema, sitemaps, llms.txt, Search Console and AI-agent abilities.

== Description ==

* Focus keyphrase (semantic matching) plus up to two related keyphrases
* SEO title, meta description, noindex/nofollow per post
* Open Graph and X/Twitter cards
* Sitemaps: posts, news, images, videos, categories, tags, authors, plus an HTML sitemap shortcode
* robots.txt management and weekly-generated llms.txt (anchor posts first)
* Connected @graph JSON-LD, server-side rendered
* Yoast SEO and Rank Math coexistence (we defer: no duplicate meta, schema or sitemaps)
* Google Search Console (OAuth), per-post query data
* WordPress Abilities API (WP 6.9+)
* 0-100 SEO and readability score with a written verdict in the editor

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
Triggered: about 800 milliseconds after you stop typing the focus keyphrase in the SEO metabox, if the feature is enabled (Manny Wenas SEO > General). Results are cached for 24 hours, failed requests for 1 hour.
Service provider: Google LLC
Terms of use: https://policies.google.com/terms
Privacy policy: https://policies.google.com/privacy

**Google Search Console** (optional, OAuth-based)
When enabled, connects to your GSC account to display search query data for a post.
Sends: OAuth tokens managed by the site administrator and, when the metabox asks for query data, the URL of the post. No post content is sent.
Triggered: only after the GSC integration is manually authorized by the administrator.
Service provider: Google LLC
Terms of use: https://policies.google.com/terms
Privacy policy: https://policies.google.com/privacy

**OpenAI / Anthropic** (pro tier only, not included in this plugin)
The pro version of this plugin (distributed separately, not through WordPress.org) 
optionally connects to OpenAI or Anthropic APIs for AI-assisted meta generation.
This functionality is not present in the version hosted on WordPress.org.

== Changelog ==

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
