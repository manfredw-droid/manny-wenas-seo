# llms.txt: noindex excludes cornerstone posts too

**Policy (owner-authorized, Finding #23):** a post with `_mwseo_noindex` set to `1` is never listed in `/llms.txt`, also when it is selected as cornerstone ("Key content"). Noindex is the author's request to keep a post out of search results; it is not access control, and a noindex post stays publicly viewable.

## What changed

Before, only the ordinary per-type selection excluded noindex. The cornerstone query had no noindex condition, so a cornerstone post flagged noindex still appeared under "Key content". `includes/class-mwseo-llms-txt.php` now combines the existing cornerstone OR group (`_mwseo_cornerstone`, `_yoast_wpseo_is_cornerstone`, `rank_math_pillar_content`) with the same noindex clause as the ordinary selection, through one shared `not_noindex()` helper. The filter is part of the query, so excluded posts do not use any of the 50 cornerstone places.

A missing, empty or `0` noindex value stays eligible; only `1` excludes. The existing publish, password and public-viewability checks are unchanged.

## Scope

Only Manny's native `_mwseo_noindex` field is read. Noindex fields of other SEO plugins are not consulted for llms.txt. Sitemaps, scoring (`SCORING_VERSION` 1.2), the cache design and the "weekly-generated" wording are unchanged. llms.txt is built per request, so a changed flag appears on the next request.

## Test

`php tests/llms-noindex-cornerstone-wordpress.php /path/to/wp-load.php [http://127.0.0.1:PORT]` needs real WordPress with the plugin active and a `solstice_llms23_` table prefix. It checks ordinary and native/Yoast/Rank Math cornerstone posts with absent, `0`, empty and `1` noindex values, noindex and cornerstone toggles, and a case with 55 newer noindex and 52 older eligible cornerstone posts (the 50 places go to eligible posts). It reads the generated body in-process and, with a base URL, over HTTP from `/?mwseo_llms=1`.

Verified on WordPress 6.4.5, PHP 8.3.6, SQLite (not MySQL/MariaDB, not PHP 7.4): 52 checks, 18 failures on the code before this change, 0 after.
