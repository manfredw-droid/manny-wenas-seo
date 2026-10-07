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

== External services ==

This plugin communicates with the following external services under the conditions described below.

= Google Trends (optional) =

When the Google Trends indicator is enabled in Settings → Manny Wenas SEO → General, your focus keyphrase is sent to trends.google.com to retrieve a relative search-interest score. This happens in the post editor, approximately 800 milliseconds after you finish typing your keyphrase. Results are cached for 24 hours; failed requests are cached for 1 hour.

No personally identifiable information is sent. You can disable this feature at any time under Settings → Manny Wenas SEO → General → Google Trends indicator.

Google Terms of Service: https://policies.google.com/terms
Google Privacy Policy: https://policies.google.com/privacy

= IndexNow =

When you publish or update a post, the post URL is sent to api.indexnow.org to notify IndexNow-compatible search engines (including Bing and Yandex) of the new content. No post content is transmitted — only the URL and your IndexNow key.

IndexNow protocol: https://www.indexnow.org/documentation
Microsoft Privacy Statement: https://privacy.microsoft.com/privacystatement

= Google Search Console =

If you connect Google Search Console via OAuth, this plugin exchanges an authorisation token with Google's OAuth 2.0 endpoint. No content is stored or transmitted beyond what is required for the OAuth flow.

Google OAuth Terms: https://developers.google.com/terms

= Bing Webmaster Tools =

If you use the Bing verification feature, a verification meta tag is output on your site's homepage. No data is actively sent to Microsoft.

== Changelog ==

= 1.0.0 =
* Initial release.
