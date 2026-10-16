# Article schema word count

`MWSEO_Schema::article()` set `wordCount` with `str_word_count( wp_strip_all_tags( $post->post_content ) )`, while the analyzer counts with `MWSEO_Analyzer::words()` after `MWSEO_Scorer::context()` prepares the content. `str_word_count` is locale- and byte-based, so `naïef café` gave 3 in schema and 2 in the analyzer. It also ignored non-ASCII letters entirely, joined adjacent paragraphs (`<p>one</p><p>two</p>` gave 1), kept HTML entities as words and did not remove block comments.

`wordCount` now uses a private `MWSEO_Schema::word_count()`: the same preparation as the scorer (`strip_shortcodes`, HTML comment removal, `wpautop` when there is no `<p>`), then `count( MWSEO_Analyzer::words( MWSEO_Analyzer::plain( $html ) ) )`. No full analysis runs and the analyzer and scoring are unchanged (`SCORING_VERSION` stays 1.2).

The contract is the analyzer's: runs of Unicode letters and digits plus `'`, `’` and `-`. Unspaced scripts such as Chinese are one run; no language support beyond that contract is claimed.

## Test

`php tests/schema-word-count.php [source-root]` invokes the production `article()` (by reflection) and `MWSEO_Scorer::score()` for 16 fixtures and requires equal counts, with literal expectations for each fixture. It uses WordPress helper shims (simplified `wpautop`, no shortcodes), so it is isolated PHP evidence, not WordPress runtime verification. Against the unchanged code it passes 24 of 35 checks (11 failures); with the change it passes 35 of 35.
