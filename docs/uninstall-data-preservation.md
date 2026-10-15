# Free uninstall: preserve information

Uninstall removes the interface and its verified operational state, not the
owner's SEO information. This policy requires no new setting. Deactivation and
activation behavior are unchanged. Plugin and scoring versions are unchanged.

## Metadata classification

| Key | Meaning and ownership | Uninstall action |
|---|---|---|
| `_mwseo_title` | User-authored SEO title; Free | Preserve |
| `_mwseo_desc` | User-authored SEO description; Free | Preserve |
| `_mwseo_focus` | Author's focus phrase; Free | Preserve |
| `_mwseo_related` | Author's related phrases; Free | Preserve |
| `_mwseo_noindex` | Editorial indexing decision; Free | Preserve, including explicit false values |
| `_mwseo_nofollow` | Editorial link-following decision; Free | Preserve, including explicit false values |
| `_mwseo_cornerstone` | Editorial cornerstone decision; Free | Preserve |
| `_mwseo_score` | Derived analysis snapshot, not user-authored; Free | Preserve; do not silently rewrite existing information |
| `_mwseo_indexnow_pinged` | Last ping timestamp written by Free's IndexNow module | Delete this exact key only |
| `_mwseo_faq` | Authored FAQ data; observed Pro owner | Preserve |
| `_mwseo_video` | Authored video URL; observed Pro owner | Preserve |
| `_mwseo_review` | Authored review data; observed Pro owner | Preserve |
| Other `_mwseo_*` keys | Unknown or independently owned | Preserve; a prefix does not establish ownership |
| Legacy `_mw_seo_*` keys and source-plugin metadata | Historical import/source information | Preserve; no migration or cleanup in this fix |

The Free registry is in `MWSEO_Meta::fields()`. Its accessor and editor save
code are unchanged. The operational timestamp is owned by
`MWSEO_Indexnow::META_KEY`; no other post metadata is deleted on uninstall.

## Table ownership

Free contains no `mwseo_rank` creation, read or write path: its previous DROP
was its only reference. The separately supplied Pro source creates this table
in `MWSEO_Pro_Rank::create_table()`, writes weekly page-ranking snapshots and
uses the history for reporting. Its columns are `id`, `post_id`, `week`,
`clicks`, `impressions` and `avg_position`. It is historical information, not a
disposable Free cache. Free uninstall now leaves its schema and rows untouched.

Ownership evidence came from the unchanged Pro ZIP with SHA-256
`919B72D479859EC09577A8CE53BF02B3D91C2B968F55DC63557E9575ADE3FC6E`.
That inspection does not certify every independently distributed Pro version.
Pro's own uninstall routine still drops its table; changing that separate
component is outside this fix. Unknown table contents are never inspected or
deleted by the new Free uninstall routine.

## Retained options and operational cleanup

- Preserve `mwseo_options` in full: it contains authored site identity,
  templates, editorial configuration, configured credentials and potentially
  extension-owned settings. No attempt is made to split or migrate that row.
- Preserve independent extension options and unrecognized transient names.
  In particular, the old `mwseo_gsc_sitemap_sent` deletion is removed because
  this Free branch has no creation/use path establishing its ownership.
- Remove Free's `mwseo_weekly` scheduled event, `mwseo_llms_txt` compatibility
  snapshot and `mwseo_gsc` OAuth connection-token state. Reauthorization is
  necessary after reinstall; configured client credentials remain preserved.
- Remove the specifically named `mwseo_sitemap_cache` and
  `mwseo_rewrite_missing` temporary state.
- Remove GSC and Trends transients only when their names match Free's actual
  `mwseo_gsc_` / `mwseo_trends_` plus 32-character lowercase hexadecimal MD5
  naming contract. Anchored matching retains similarly prefixed unknown keys.
  Use `delete_transient()` for cache invalidation and explicitly remove database
  value/timeout rows, including orphan timeouts and old rows left after a cache
  backend switch. Do not flush the whole site's object cache.

## Focused verification

Standalone control-flow regression tests (no WordPress/database required):

```text
php -n tests/uninstall-preservation.php
```

Actual WordPress integration runner:

```text
php -c <test.ini> tests/uninstall-preservation-wordpress.php <isolated-bootstrap> [bootstrap-config]
```

The external bootstrap must load a fresh disposable single-site WordPress
installation, define `MWSEO_UNINSTALL_TEST_SITE` as true, use a unique prefix
matching `mwseo_uninstall_test_[pc]_[a-f0-9]{8}_`, set `WP_PLUGIN_DIR` to the
parent of this checkout, and supply an administrator with ID 1. Exit installer
mode with `wp_installing(false)` after installation. Block external HTTP and
email. Never use production credentials or an existing site's table prefix.
The runner deletes all tables under that isolated prefix when finished.

Verification for this change:

| Environment | Isolated doubles | Actual WordPress |
|---|---|---|
| PHP 8.3.33 / WordPress 7.1.3 | 60 passed, 0 failed | 60 passed, 0 failed |
| PHP 7.4.33 / WordPress 6.4 | 60 passed, 0 failed | 60 passed, 0 failed |

The original uninstall fails 40 of the 60 standalone checks on each PHP
version, reproducing the metadata/configuration/history destruction using
doubles only. The destructive original was never run on an existing database.
The real WordPress tests use `activate_plugin()`, `deactivate_plugins()` and
`uninstall_plugin()`, then reactivate the same-version files. They compare raw
metadata rows, including serialized and duplicate extension records, and rank
history before/after. Published posts, drafts, private pages and a custom post
type are covered. Reinstalled native accessors read the preserved fields.
This does not claim a browser-driven file deletion/reupload test.

An initial local test run incorrectly retained installer cache mode and omitted
an existing IndexNow key (whose normal activation generates/saves defaults).
Those fixture issues were corrected without weakening preservation assertions.
A subsequent real-WP run exposed the orphan-timeout behavior; the production
cleanup and its doubles were corrected and both final runs passed. Earlier
results are retained in the local verification evidence.

## Remaining limits

- Multisite/network-wide uninstall was not runtime-tested. Cleanup remains
  scoped to the current site's options/meta, with no network-wide sweep.
- Redis/Memcached was not available. Hash-keyed transients stored only in an
  external object cache have no options rows to enumerate and may survive until
  their normal TTL (GSC 6 hours; Trends 24 hours or 1 hour after failure).
- Core's object-cache API branch and old database-cache rows were tested using
  WordPress's built-in in-memory cache; this is not a persistent-backend test.
- Unrecognized keys and unknown tables intentionally remain; retention is
  safer than an irreversible ownership guess. No historical repair is added.
- Existing score freshness, scoring policy, UI and other release findings are
  unchanged. These checks do not authorize merge or a Gold/public release.
