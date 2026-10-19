# Manny Wenas SEO — Philosophy

*The founding principles of this plugin. When a scoring rule changes, an
amendment is added to AMENDMENTS.md, not this document.*

---

## §1 Identity

Manny Wenas SEO is a free WordPress plugin distributed through WordPress.org.

**§1.1 Free plugin guarantee.** The free plugin distributed through WordPress.org
contains no premium functionality, no license-checking code, and no gated
features. Every check described in §2 is fully available without payment.

**§1.2 Scope.** The plugin analyses the post a writer is currently editing. It
does not crawl the site, index other posts, or store editorial content beyond
the current session.

---

## §2 Scoring rubric

The scorer awards up to 100 points, rescaled over the checks that apply so that
N/A checks neither add nor remove points. The point values below are
constitutional: changing them requires a SCORING_VERSION increment and an entry
in AMENDMENTS.md.

### §2.1 Text & Content (45 points)

| Check | Points |
|---|---|
| Keyphrase in body text | 15 |
| Heading structure (H2 present + logical levels) | 10 |
| Readability (sentence length ≤ 25 words or Flesch ≥ 60) | 10 |
| Internal link | 10 |

### §2.2 Keyphrase placement (30 points)

| Check | Points |
|---|---|
| SEO title | 8 |
| Meta description | 6 |
| URL slug | 6 |
| Opening paragraph | 6 |
| Subheading (H2–H6) | 4 |

### §2.3 Technical (11 points)

| Check | Points |
|---|---|
| Image alt text | 8 |
| Clean URL slug | 3 |

### §2.4 Related keyphrases (10 points)

Up to two related keyphrases, scored together. N/A when none are configured.

### §2.5 Advisory notices

Word count below 300 words and absence of an external link are advisory notices
only. They appear in `notices[]` and never change the numeric score.

---

## §3 Content privacy

**§3.1 Local-only scoring.** The scoring engine operates entirely on the post
content supplied by the editor. It performs no network requests during a scoring
call.

**§3.2 No editorial content transmitted without consent.** No editorial content
may be transmitted to an external service before informed, explicit consent is
obtained from the site owner.

**§3.3 Integration opt-in.** Integrations that transmit data externally (for
example IndexNow ping, Google Trends comparison) must be opt-in and off by
default. Disabling an integration must stop all subsequent transmissions from
that integration.

**§3.4 No outbound HTTP during scoring.** The WordPress HTTP functions
(`wp_remote_get`, `wp_remote_post`, `wp_remote_request`, `wp_safe_remote_get`,
`wp_safe_remote_post`, `wp_safe_remote_request`) must never be called during a
`MWSEO_Scorer::score()` call. This is verified by the constitutional test suite.

---

## §4 Verdict reporting

**§4.1 Structured output.** The verdict is an array, not a string. The UI layer
is responsible for display; the scorer is responsible for facts.

**§4.2 Classification.** Each check result carries a `classification` field:
- `full` — points === max
- `partial` — 0 < points < max
- `none` — points === 0
- `na` — the check does not apply to this post

**§4.3 N/A handling.** N/A checks are excluded from `full_count`, `partial_count`,
and `none_count`. They do not lower the score.

**§4.4 Missing focus keyphrase.** When no focus keyphrase is set, all keyphrase
checks are N/A. The verdict carries a dedicated summary message; it does not
report counts.

**§4.5 Factual observations.** The verdict summary and observations list describe
the precise outcome of each check using factual language. The scorer never uses
editorial framing ("good job", "needs work") or arbitrary pass/fail thresholds
beyond the constitutional point values in §2.

---

## §5 Versioning

**§5.1 SCORING_VERSION.** `MWSEO_Scorer::SCORING_VERSION` is incremented
independently of the plugin release version whenever a scoring rule changes
intentionally. The constitutional test suite pins this version so any change
requires a deliberate update.

**§5.2 Amendment record.** Every intentional change to the scoring rules or
founding principles is recorded in AMENDMENTS.md with the commit reference that
introduced the change.
