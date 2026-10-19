# Manny Wenas SEO — Amendment Record

*Each entry records an intentional change to the philosophy or scoring rules.
Entries are ordered oldest-first. Process rules (how releases are tested and approved)
are recorded separately in PROCESS-AMENDMENTS.md.*

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

---

## Amendment 3 — SCORING_VERSION 1.2: related keyphrases require a focus keyphrase

**Date:** 15 October 2026

**Commit:** 08eae89131224774db222b5cd5b001537bc8cd51 (branch: fix/related-phrases-require-focus)

**SCORING_VERSION:** 1.1 → 1.2

**Principles clarified:** §2.4, §4.3, §4.4

**Authority:** explicit owner approval of Finding #8, option A.

When the trimmed focus keyphrase is empty, `related_body` is N/A even when
related phrases are configured. It contributes neither earned points nor an
applicable maximum to the rescaled score. This makes §4.4's rule that all
keyphrase checks are inactive without focus apply to related phrases as well.
The dedicated no-focus verdict, empty observations and zero verdict counts
remain unchanged and consistent with that applicability rule.

With a focus keyphrase, related scoring is unchanged: up to two phrases share
10 points, or the check is N/A when none are configured. Section weights remain
45/30/11/10, with an absolute maximum of 96 before rescaling. Matching logic,
thresholds and the remaining checks are unchanged.

Removing both earned and missed related points can raise **or lower** a score.
Recorded examples without focus: a missing related phrase with an empty slug
changes 30/40 = 75 to 30/30 = 100; a present related phrase in an article missing
an internal link changes 33/43 = 77 to 23/33 = 70. This is an applicability change,
not a blanket score increase or a change to the related section's weight.

Configured phrases and other user metadata are preserved. No stored scores are
rewritten and no migration or bulk recalculation is introduced. New analyses use
1.2; this version increment does not itself refresh existing stored scores.

`tests/related-without-focus.php` pins version 1.2 and covers absent/whitespace
focus, zero/one/two related phrases, present/missing/mixed matches, effective
points and denominator, N/A classifications and the no-focus verdict. It also
compares complete focused-analysis outputs with the recorded 1.1 baseline and
covers both score-change examples. These are isolated PHP checks, not WordPress
runtime or browser compatibility certification.
