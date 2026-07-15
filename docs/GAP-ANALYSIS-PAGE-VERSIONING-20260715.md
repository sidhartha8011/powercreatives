# GAP ANALYSIS — PAGE VERSION NUMBER + REPLACE-NOT-APPEND SAVE (owner design 2026-07-15)

Owner's architecture ruling (converged over the session): keep the overlay
fence (builders never written, original untouched) and the per-section
versions; ADD a page version number + content fingerprint on both sides;
make SAVE REPLACE the page's rule set instead of appending; COMPARE before
fetching. Nothing removed anywhere.

## FACTS (live-verified, HEAD d9d04b4)
1. THE PILE IS REAL: Privacy Policy (site 2, post 3) carries 63 rules —
   17 section / 28 sectionInsert / 18 sectionRemove (DB probe 2026-07-15);
   versions to 09:13; debug.log shows ZERO push errors today → saves and
   pushes succeed; the stale render is the stacked pile itself.
2. SAVE PATH: frontend save → seo.remoteSavePageEdits (SectionModal:870,
   errors DO toast) → save_page_edits (seo/service.php:4692): fetches the
   SERVED inventory from the site (HTTP #1), diffs every section against
   the editor html, routes per-section rule writes, versions row per
   change, then push_current_rules_or_rollback (:3994) sends the WHOLE
   rule set (HTTP #2) — rollback restores prior rows on push failure.
   The admin waits for the entire chain = the GUI lag; work grows with
   the pile.
3. THE SITE HAS NO VERSION CONCEPT: the connector stores the rule set and
   applies it per view; nothing anywhere compares state — every editor
   open and every save re-fetches + re-analyzes the full page.
4. Per-section history lives in wp_pcm_seo_rule_versions — cheap rows,
   read only when the versions menu opens; NOT a lag source; STAYS.
5. The push already ships the COMPLETE rule set each save
   (post_rule_rows → rules_to_schema → push_rules, :3994-4002) — a
   version+fingerprint field can ride the SAME payload; no new transport.
6. TO-CONFIRM during build (first checklist step, read-only): (a) the
   exact append points inside save_page_edits' routing (why removals of
   own inserts append sectionRemove instead of deleting the insert);
   (b) the connector-side storage shape in seohub/service.php for adding
   {version, fingerprint} + returning them on the served-view/scan reply.

## WHY (functional)
- Lag: the admin's save waits on two site round-trips + per-row
  bookkeeping that grows with the pile (fact 2).
- Bug: stacked instructions from old sessions fight new saves — the
  "old crap" render and the "switched version" impression (fact 1).
- Blindness: no cheap way to know hub and site agree (fact 3) — outside
  edits are invisible until something breaks.

## WHERE + HOW (the senior build — no new machinery, no duplication)
- W1 **Page state record** (hub): ONE option-map (the proven pattern)
  `pcm_page_state` "siteId:postId" → {version:int, fingerprint:sha1 of
  the net rule set + served baseline}. Written in the SAME transaction
  as the save. No schema change.
- W2 **Save replaces** (save_page_edits): compute the NET rule set for
  the page from the editor document, then swap the page's rows
  WHOLESALE in one transaction (delete page rows + insert net rows) —
  superseded inserts/removes die HERE, the pile can never re-grow.
  Per-section version rows keep being recorded exactly as today
  (fact 4). Rollback law unchanged (:3994).
- W3 **Version rides the push** (rules_to_schema payload + connector):
  the connector stores {version, fingerprint} beside the rule set and
  echoes them in the served-view reply (fact 5, fact 6b). Connector
  minor bump; sites reinstall — the standing procedure.
- W4 **Compare before work**: save reuses the baseline captured at
  editor-OPEN when the connector's echoed version+fingerprint still
  match (kills HTTP #1 on the common path — the felt lag);
  fingerprint mismatch = an honest conflict prompt ("the page changed
  on the site — load theirs or overwrite with yours"), never a silent
  overwrite either way.
- W5 **One-time flatten**: the first save under W2 naturally replaces
  any legacy pile (Privacy Policy's 63 included) — no separate
  migration code, no old code left behind.
- SMART-CODE LAWS BINDING THE BUILD: reuse post_rule_rows /
  rules_to_schema / push_current_rules_or_rollback verbatim (no second
  push path); ONE fingerprint function used by both sides; no new
  endpoints where the existing scan/push replies can carry the fields;
  zero dead code — anything W2 makes unreachable is deleted in the
  same commit and named in the changelog.

## CHECKLIST
[ ] confirm fact 6a/6b (read-only) · [ ] W1 state record · [ ] W2
replace-not-append · [ ] W3 connector fields + bump · [ ] W4 compare +
conflict prompt · [ ] W5 verified on Privacy Policy (63 → net set) ·
[ ] php -l · [ ] harness 88/88 + research 34/34 (+ new net-set tests) ·
[ ] tsc 59/0 · [ ] build · [ ] changelog · [ ] AFTER commit LOCAL ONLY
