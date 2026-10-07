=== Manny Wenas SEO ===
Contributors: mannywenas
Tags: seo, schema, sitemap, llms.txt, search console
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Lean SEO: semantic keyphrase scoring, connected JSON-LD schema, sitemaps, llms.txt, Search Console and AI-agent abilities.

== Description ==

Free:
* Focus keyphrase (semantic matching) plus up to two related keyphrases
* SEO title, meta description, noindex/nofollow per post
* Open Graph and X/Twitter cards
* Sitemaps: posts, news, images, videos, categories, tags, authors, plus an HTML sitemap shortcode
* robots.txt management and weekly-generated llms.txt (anchor posts first)
* Connected @graph JSON-LD, server-side rendered
* Yoast SEO coexistence (stitch or defer) and Rank Math coexistence (we defer: no duplicate meta, schema or sitemaps)
* Google Search Console (OAuth), per-post query data
* WordPress Abilities API (WP 6.9+)
* 0-100 SEO and readability score with a written verdict in the editor

Pro (activate via the `mwseo_is_pro` filter or the `MWSEO_PRO` constant):
* Bulk AI title/description generation with your own API key (Anthropic or OpenAI)
* FAQPage, VideoObject and Review schema
* GSC rank tracking and weekly email report
* Sitemap auto-submission (Google Search Console API, Bing via IndexNow)
* Related keyphrase suggestions from Search Console

Deliberately excluded: redirects, 404 monitor, analytics dashboard, AI writer.

== Changelog ==

= 1.0.0 =
* Initial release.
