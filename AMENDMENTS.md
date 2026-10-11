# Manny Wenas SEO — Amendment Record

*Each entry records an intentional change to the philosophy or scoring rules.
Entries are ordered oldest-first.*

---

## Amendment 1 — §2.5 restored: integrations are opt-in

**Date:** recorded during v1.0.5 cleanup  
**Commit:** ed33efa (branch: fix/v1-0-5-cleanup)  
**Principle amended:** §2.5 / §3.3

IndexNow ping and Google Trends comparison were made opt-in and off by default.
This restores the principle that integrations which transmit data externally
require explicit activation by the site owner. Sites that had previously enabled
these integrations are unaffected; new installations start with both off.

---

## Amendment 2 — §2.3 Technical section corrected to 11 points

**Date:** recorded during v1.0.5 cleanup — constitutional enforcement milestone  
**Commit:** see git log on branch constit  
**SCORING_VERSION:** 1.1  
**Principle amended:** §2.3

The Technical section was documented as 15 points but the two technical checks
(image alt text 8 + clean slug 3) actually total 11 points. The SECTIONS
constant was corrected to 11 and SCORING_VERSION was incremented to 1.1. No
check values changed; only the section total was wrong.
