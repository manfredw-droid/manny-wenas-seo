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

---

## Amendment 4 — SCORING_VERSION 1.3: keyphrase in the URL slug is N/A until the post has a slug

**Date:** 22 October 2026

**Commit:** 768994ee875c71bf828123ad5a13d5bfc88fb07e (branch: implementation/1.0.9-slug-import-cornerstone, based on `main` at `1620b86b9`, plugin 1.0.8)

**SCORING_VERSION:** 1.2 → 1.3

**Principles clarified:** §2.2, §4.3

**Authority:** explicit owner approval of the agreed 1.0.9 changes, item 1 (empty URL slug).

When a focus keyphrase is set but the post has no slug yet (a new draft whose slug WordPress
has not generated), `slug_keyphrase` is N/A. It contributes neither earned points nor an
applicable maximum of 6 to the rescaled score. An empty slug cannot lack a keyphrase, so it
is no longer scored 0/6.

A slug that exists is scored exactly as before: with the keyphrase it earns 6/6, without it
0/6, and a partial semantic match keeps its existing half credit. With no focus keyphrase the
check stays N/A for its own reason ("No focus keyphrase set."), with or without a slug.

The technical `clean_slug` check was already N/A without a slug. Both slug checks now give the
same explanation in that state: "The URL slug is not available yet." (A saved draft can still have no stored slug, so the text does not promise that saving creates one.)

An empty slug could never earn those 6 points under 1.2, so the change can only raise the
score of such a draft, or leave it at 100. Recorded fixture (focus "solar garden", title and
description containing it): empty slug 92 (69/75) under 1.2 becomes 100 (69/69) under 1.3. The
same article with slug `solar-garden-notes` stays 100 (78/78) and with slug `quiet-afternoon`
stays 92 (72/78). Section weights remain 45/30/11/10, with an absolute maximum of 96 before
rescaling. Matching logic, thresholds and every other check are unchanged.

No stored scores are rewritten and no migration or bulk recalculation is introduced. New
analyses use 1.3; this version increment does not itself refresh existing stored scores.

`tests/slug-unavailable.php` pins version 1.3 and covers the three slug states (empty, with the
keyphrase, without it), the no-focus case, the shared explanation, the denominator arithmetic
and the recorded fixture results above. It was also run against the unmodified 1.0.8 scorer,
where the behaviour-change checks fail. These are isolated PHP checks, not WordPress runtime
or browser compatibility certification.
