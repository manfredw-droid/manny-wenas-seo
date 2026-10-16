# llms.txt: noindex excludes cornerstone posts too

**Policy (owner-authorized, Finding #23):** a post with `_mwseo_noindex` set to `1` is never listed in `/llms.txt`, also when it is selected as cornerstone ("Key content"). Noindex is the author's request to keep a post out of search results; it is not access control, and a noindex post stays publicly viewable.

## What changed

Before, only the ordinary per-type selection excluded noindex. The cornerstone query had no noindex condition, so a cornerstone post flagged noindex still appeared under "Key content".

The cornerstone query (`includes/class-mwseo-llms-txt.php`) keeps its OR group (`_mwseo_cornerstone`, `_yoast_wpseo_is_cornerstone`, `rank_math_pillar_content`) and gets the noindex rule as SQL in the same query, through `MWSEO_Llms_Txt::exclude_noindex_sql()`. It is attached to the `get_meta_sql` filter only while that one `get_posts()` call runs and acts only on a query carrying the `mwseo_llms_cornerstone` marker. A `build()` call removes the filter afterwards (also on errors) only when it registered it itself, so a `build()` started from inside another one cannot strip the outer registration, and the callback never stacks. Because the exclusion is part of the query, excluded posts do not use any of the 50 cornerstone places.

The rule is two correlated subqueries limited to `meta_key = '_mwseo_noindex'`: a post is eligible when it has no such row (`NOT EXISTS`), or a row whose value is not `'1'` (`EXISTS ... meta_value != '1'`). This is the same rule as the ordinary selection's meta query. A `meta_query` clause was deliberately not used for the cornerstone side: WordPress turns a `!=` clause into a second postmeta join without a `meta_key` condition, which together with the cornerstone OR group's join multiplies the joined rows per post (N x N, with N the post's meta rows). The subqueries keep a single postmeta join and read at most the post's `_mwseo_noindex` rows. The ordinary per-type query is unchanged.

A missing, empty or `0` noindex value stays eligible; only `1` excludes. The existing publish, password and public-viewability checks are unchanged.

## Scope

Only Manny's native `_mwseo_noindex` field is read. Noindex fields of other SEO plugins are not consulted for llms.txt. Sitemaps, scoring (`SCORING_VERSION` 1.2), the cache design and the "weekly-generated" wording are unchanged. llms.txt is built per request, so a changed flag appears on the next request.

## Test

`php tests/llms-noindex-cornerstone-wordpress.php /path/to/wp-load.php [http://127.0.0.1:PORT]` needs real WordPress with the plugin active and a `solstice_llms23_` table prefix. It checks ordinary and native/Yoast/Rank Math cornerstone posts with absent, `0`, empty and `1` noindex values, noindex and cornerstone toggles, and a case with 55 newer noindex and 52 older eligible cornerstone posts (the 50 places go to eligible posts). It also checks the cornerstone SQL: one postmeta join, noindex handled by two key-bounded subqueries, the filter removed after `build()` and not applied to other queries. It reads the generated body in-process and, with a base URL, over HTTP from `/?mwseo_llms=1`.

Verified on WordPress 6.4.5, PHP 8.3.6, SQLite (not MySQL/MariaDB, not PHP 7.4): 63 checks pass, the 58 behavior and query-shape checks plus 5 for the filter lifetime (repeated builds, an exception inside the query window, and a nested `build()`). The first version of this fix (a nested `meta_query`) passes the behavioral checks but fails the two query-shape checks. SQL inspection and SQLite timings do not establish MySQL/MariaDB performance.

Correction of earlier test reporting: earlier versions of this note and of the pull request description said the code before this change fails 19 of 57 applicable checks. That count was measured on a database that still held fixtures from earlier runs. On a clean database the code before this change fails 16 of 57; the failing behaviors are the same (noindex cornerstone, Yoast and Rank Math posts listed, noindex toggles ignored, the 50 places filled by noindex posts). The earlier output is kept as recorded.
