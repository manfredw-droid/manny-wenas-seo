# Manny Wenas SEO — Process Amendments

*This file records how work on releases is organised: how much testing, review and
approval a change needs. It sits beside PHILOSOPHY.md (founding principles and the
scoring rubric) and AMENDMENTS.md (intentional changes to scoring or principles).
Entries are ordered oldest-first.*

---

## Process Amendment 1 — Proportionality

**Date:** 16 October 2026
**Applies to:** version 1.0.6 and all later versions
**Authority:** agreed by the owner during the 1.0.6 release preparation.

This amendment changes how work is organised. It does not change scoring rules,
`SCORING_VERSION` (1.2), user rights, permissions, data-protection behaviour or any
founding principle in PHILOSOPHY.md.

1. **Test depth follows risk and impact.** A text correction needs a text-level
   check; a change to scoring, stored data, security or what the plugin writes
   needs executable evidence in proportion to what can go wrong.
2. **Valid existing evidence is reused.** A passing test or review of an unchanged
   area is not repeated without a concrete reason, such as a change in that area, a
   failure, or an uncertainty that affects the decision.
3. **The implementer chooses suitable checks** and states the important limits of
   the evidence (environment, versions, what was simulated and what was not).
4. **Extra tests or reviews follow from specific causes:** a concrete change, a
   failure, or an uncertainty that would alter the decision. "More is safer" is not
   such a cause.
5. **Routine test adjustments inside an authorised fix need no separate approval
   round** (for example updating a test that pins the exact wording the fix was
   authorised to change). They are reported in the change.
6. **Non-blocking improvements may move to a later version.** They are listed, not
   silently dropped and not used to widen the current change.
7. **Every release has a finite scope and clear completion criteria,** recorded
   before final checking starts. New findings are weighed against that scope.

Not changed by this amendment: the owner decides risk acceptance and release
approval; a finished finding is reopened only on concrete new evidence; changes to
scoring rules still need a version increment and an entry in AMENDMENTS.md.
