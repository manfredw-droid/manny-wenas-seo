# Manny Wenas SEO — Amendment Record

*Each entry records an intentional change to the philosophy or scoring rules.
Entries are ordered oldest-first.*

---

## Amendment 1 — §3.3 restored: integrations are opt-in

**Date:** recorded during v1.0.5 cleanup  
**Commit:** ed33efa (branch: fix/v1-0-5-cleanup)  
**Principle amended:** §3.3

IndexNow ping and Google Trends comparison were made opt-in and off by default.
This restores the principle that integrations which transmit data externally
require explicit activation by the site owner. Sites that had previously enabled
these integrations are unaffected; new installations start with both off.

---

## Amendment 2 — SCORING_VERSION 1.1: Technical section corrected; word-count and focus checks revised

**Date:** recorded during v1.0.5 cleanup — constitutional enforcement milestone  
**Commits:** fde076f, 4873abc, 024cc7b, c230543 (branch: constit)  
**SCORING_VERSION:** 1.0 → 1.1  
**Principles amended:** §2.3, §2.2, §4.3, §4.4

Four related changes were introduced together and share the SCORING_VERSION 1.1 increment:

**2a — Word-count check removed from Technical scoring.**  
The `check_word_count` check (4 points) was removed from the Technical section.
Word count below 300 words is now an advisory notice only (§2.5): it appears in
`notices[]` and never changes the numeric score. This reduced the Technical section
from 15 to 11 points.

**2b — Technical section constant corrected.**  
`SECTIONS['technical']` was documented as 15 points but the two remaining Technical
checks (image alt text 8 + clean URL slug 3) total 11. The constant was corrected
to match the actual checks. The scoring arithmetic was already correct; only the
constant was wrong.

**2c — Missing focus keyphrase: checks changed from 0 to N/A.**  
When no focus keyphrase is set, all keyphrase-dependent checks now return N/A and
are excluded from the score (§4.4). Previously, missing-keyphrase checks scored 0,
artificially lowering the total. The verdict now carries a dedicated summary message
in place of counts.

**2d — Opening paragraph check: `<div>` fallback added.**  
When post content contains no `<p>` element, the opening paragraph check now
considers the first `<div>` block that contains at least 10 words (as counted by
`MWSEO_Analyzer::words()`). Blocks with fewer than 10 words (navigation links,
hero labels) are excluded; the check remains N/A when no qualifying block is found.
