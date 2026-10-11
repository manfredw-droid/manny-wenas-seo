=== Manny Wenas SEO ===
Contributors: mannywenas
Tags: seo, schema, sitemap, llms.txt, search console
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lean SEO: semantic keyphrase scoring, connected JSON-LD schema, sitemaps, llms.txt, Search Console and AI-agent abilities.

== Description ==

At a copywriting conference around 2011, a group of journalists asked their SEO tool — half sarcastically — whether they should just repeat their keyword phrase fifteen times in a row.

The tool essentially said yes.

That's the problem Manny Wenas SEO was built to fix.

Every other SEO plugin on the market was designed to make Google happy. Manny Wenas SEO was designed to make writers happy. The difference matters. Mechanical writing ranks worse than good writing. Always has. We just happen to be the first plugin to build that conviction into the scoring.

Our core principle: reward writers for organically placing their keyphrase. Never make them feel like they're writing for a search engine algorithm. Mechanical writing is the enemy.

= What makes this different =

We check five things: your keyphrase in the title, in the opening paragraph, in a subheading, in the slug, and in the meta description. That's it. We don't count how many times you used it in the body. We don't penalise you for not hitting a density percentage. We don't tell you to stuff it into places it doesn't belong.

If you wrote a good article and placed your keyphrase naturally in those five locations, you'll score well. If you wrote a great article but missed one, you'll still score well — and we'll tell you which one you missed, not scold you for it.

= Features =

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

== Installation ==

1. Upload the `manny-wenas-seo` folder to the `/wp-content/plugins/` directory, or install directly through the WordPress Plugins screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Manny Wenas SEO → Settings** to configure your site-wide defaults (site name, separator, home page meta).
4. Open any post or page in the block editor — the SEO metabox appears below the content area.
5. Enter your focus keyphrase, SEO title, and meta description. Your score updates in real time.

== Frequently Asked Questions ==

= Why doesn't this check keyword density? =

Because keyword density is not a Google ranking signal, and chasing it produces worse writing. The plugin checks that your keyphrase appears in the five places that actually matter for crawling and indexing: the title, the opening paragraph, a subheading, the slug, and the meta description. That's where placement counts.

= Can I use this alongside Yoast SEO or Rank Math? =

Yes. Manny Wenas SEO detects both plugins and defers automatically — it will not output duplicate meta tags, schema, or sitemaps when either is active. You can use Manny Wenas SEO for scoring and writing guidance while keeping your existing SEO plugin for other features.

= What is the scoring based on? =

The 0-100 score is split across four areas: Text & Content (45 points), Placement (30 points), Technical (15 points), and Optional signals (10 points). The written verdict in the editor tells you exactly what you scored and what, if anything, to fix — in plain language, not a traffic light colour.

= Does this plugin use AI? =

The free version on WordPress.org does not. A separate pro version (distributed outside the directory) includes optional AI-assisted meta generation via OpenAI or Anthropic. That functionality is not present here.

= What is llms.txt? =

An emerging standard that helps AI language models understand your site's content structure. The plugin generates it automatically on a weekly schedule, listing your anchor posts first.

= Does this work with the classic editor? =

The metabox renders in both the block editor and the classic editor. The real-time score preview requires the block editor.

== External Services ==

This plugin optionally connects to the following third-party services. All external connections are disabled by default and must be explicitly enabled by the site administrator.

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
The pro version of this plugin (distributed separately, not through WordPress.org) optionally connects to OpenAI or Anthropic APIs for AI-assisted meta generation. This functionality is not present in the version hosted on WordPress.org.

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
