# Importer Dry-run safety — Operation Solstice

Base: `51204fd8bd853b125475f041595b7313ecd7ede0`, targeting
`development/1.0.6-companion`. Verification date: 15 October 2026.

## Cause and correction

The real form submit handler passed `native && checkboxChecked` to `runImport`.
For AIOSEO, SEOPress and The SEO Framework, `native` is false, so a checked
Dry run produced `dry_run=0`. The unchanged backend correctly treated that as
an actual import. Overwrite could therefore replace existing destination data.

The correction removes only the source-dependent condition. Every source now
uses the checkbox. Preview already calls `runImport(true)` and stays unchanged.
The payload and backend dry-run guards stay unchanged. No mappings, metadata
contracts, historical records, scoring, REST or permissions are changed.

## Independently executed checks

Disposable WordPress 6.4 / PHP 7.4.33 / MariaDB 11.4.13, Classic Editor 1.6.7.
Browser: Codex in-app browser; actual request user agent reports Chromium
`Chrome/155.0.0.0`. This is not a separate standard Chrome/Firefox acceptance run.
Only a new `solstice_dry_20261015_` table prefix and owned fixtures were used.

| Check | Result |
| --- | --- |
| Baseline, actual AIOSEO form submission, checkbox checked, Overwrite off/on | Reproduced twice: `dry_run=0`; each request made 4 metadata hook events and 2 mutation SQL statements; destination records changed |
| Fixed UI script, actual registered handlers, five sources, both checkbox and Overwrite states, Submit/Preview | 40/40 isolated cases pass; unchanged baseline fails the six legacy checked-Submit cases |
| Real browser: five sources, Overwrite off/on, checked Submit and unchecked Preview | 20/20 pass: `dry_run=1`, zero metadata hook events, zero mutation SQL, identical complete before/after rows |
| Real browser: Preview with destinations already matching | Pass: zero hook events/SQL; four matched values |
| Real browser: unchecked Run, Yoast, Overwrite off/on | Both pass: absent targets created, existing targets preserved then overwritten; source records unchanged; 2 SQL writes per request |
| Actual WordPress registered AJAX handler | 35/35 cases pass: explicit dry run, omitted safe default, matching/existing/absent destinations, and 10 real imports with both Overwrite states |
| Existing description regression harness, PHP 7.4.33 and 8.3.33 | 21/21 each |
| Existing importer regression harness, PHP 7.4.33 and 8.3.33 | 33/34 each; only the obsolete literal source-code assertion fails (see below) |
| Existing llms harness, PHP 7.4.33 and 8.3.33 | 32/32 each after allowing its localhost HTTP probe |
| Existing Companion and Classic initialization JS suites | 15 and 34 test groups pass |
| Production PHP and WordPress test PHP syntax, both PHP versions; new JS test syntax | Pass |

Metadata observers return the original filter result; they do not block writes.
Mutation SQL and complete rows including `meta_id` are also inspected. Actual
imports are positive controls proving that these observers detect writes.

### Existing test incompatibility

The external historical harness `stabilization-v1.0.6/tests/finding-05-importer.php:266`
literally requires the old `runImport(native && ...)` source string. It passes
34/34 against the baseline but necessarily fails that one assertion against
this correction. Its 33 other checks pass. The protected historical test was
not altered. The new 40-case behavioral test replaces that assertion's relevance
for this change, and detects the actual original defect. No failure is hidden.

The llms HTTP probe initially could not start successfully within the restricted
sandbox. Both complete suites subsequently passed with localhost access; the
initial outputs are retained in the local evidence directory.

## Reproduction / regression commands

```text
node tests/importer-dry-run.cjs
node tests/importer-dry-run.cjs /path/to/unchanged/baseline
php tests/importer-dry-run-wordpress.php /path/to/disposable/wordpress
php -l includes/class-mwseo-importer.php
php -l tests/importer-dry-run-wordpress.php
```

The WordPress test requires the active plugin, an administrator, and a dedicated
`solstice_dry_` table prefix. It creates and cleans up its own posts. It runs the
registered AJAX action with real WordPress nonce/capability checks and database
storage; only the final `wp_die` exit is converted into a catchable response.
It is distinct from the HTTP/browser checks.

## Limits and preserved issues

Source-plugin detection constants in the disposable MU helper expose all five
UI options. Seeded source records exercise the actual importer, but the five
source plugins were not installed or run. This verifies dry-run isolation, not
source-plugin integration or modern source-storage compatibility.

AIOSEO, SEOPress and TSF still use legacy `_mw_seo_*` destinations and retain
their known mapping/type/reporting limitations. Their real-import controls only
confirm unchanged legacy behavior; they do not establish a successful native SEO
migration. No historical repair or cleanup is performed by this fix.

WordPress runtime coverage is 6.4 / PHP 7.4.33 only. PHP 8.3.33 coverage is syntax
and isolated regression testing, not another WordPress/browser compatibility
claim. No publication, merge, release tag or Gold approval is implied.
