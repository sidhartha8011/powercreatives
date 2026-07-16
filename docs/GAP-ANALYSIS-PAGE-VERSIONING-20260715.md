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

---
# ADDENDUM — ROOT CAUSE PROVEN (2026-07-15, live probe)

- **Fact 1 REVISED:** `http://powerleads.local/?page_id=3` → **404** (curl
  probe). The Privacy Policy is a DRAFT — no public render exists. The
  owner's "old version" view is WordPress's authenticated draft PREVIEW,
  which renders the draft's raw content; the connector's rule engine
  serves on front-end renders (template_redirect ob_start,
  seohub/service.php:2170-2183 — admin/feed/REST excluded, preview NOT
  explicitly handled/verified). Saves + pushes PROVEN good (clean log,
  rules stored to 09:13).
- **The 63-rule pile is real but NOT the render bug** — it remains the
  LAG driver (fact 2 stands) and a correctness risk, not the cause of
  the stale preview.
- **Fix split:** (A) DRAFT/PREVIEW SERVING — verify + support rules on
  authenticated previews in the connector (bump) AND the editor states
  the page's status honestly ("draft — publishes changes when the page
  is published"); needs the owner's 2-minute confirm on a PUBLISHED page
  that saves render there. (B) The versioning/lag build (W1-W4)
  proceeds unchanged — its facts are intact.

---
# ADDENDUM 2 — ARCHITECT PLAN + SUB-AGENT LANES (owner order 2026-07-16)

New fact (third symptom, live-traced): editor OPEN loads the site-side
ASSEMBLY (snapshot+rules, SectionModal:687) while dropdown "Current" loads
versions[0].replacement (:804) — identity drift makes them disagree.
NEW W0: **the saved version wins the editor open**; the assembly is used
only for drift DETECTION (fingerprint compare → honest notice).

## FROZEN CONTRACTS BETWEEN LANES
- Fingerprint = sha1 of normalized net rule set JSON (one function,
  hub-side PCM_SEO_Service::page_fingerprint; connector receives the
  VALUE, never recomputes).
- Push payload gains {pageState: {version:int, fingerprint:string}} per
  post; connector stores it and ECHOES it (same keys) in the scan/served
  reply envelope.
- Hub state record: option 'pcm_page_state', key "siteId:postId" →
  {version, fingerprint, savedAt}.
- Inventory/open reply to frontend gains {pageState: {version,
  fingerprint, drifted: bool}} (hub compares echo vs record).

## LANES (zero file overlap)
- **LANE A — hub backend**: includes/modules/seo/{service,controller}.php
  + tests/standalone/page_versioning_test.php (NEW). W1 state record ·
  W2 save replaces the page rule set wholesale (net set; per-section
  version rows unchanged; rollback law kept) · fingerprint fn · inventory
  reply gains pageState+drifted. NEVER touches seohub or frontend.
- **LANE B — connector**: includes/modules/seohub/service.php ONLY.
  W3: store {version,fingerprint} beside rules on push; echo in the
  scan/served/snapshot reply; connector patch version bump; parity
  harness (tests/standalone/run.php) must stay green.
- **LANE C — frontend**: app/src/modules/SEO/SectionModal.tsx + hooks +
  trpc-routes.ts. W0 open-from-saved-version (versions[0].replacement
  when present; assembly only as fallback when NO versions exist) ·
  W4 drift notice (one quiet line + reload action, no dialogs) ·
  keep the 2026-07-11 never-clobber law.
- Orchestrator (me): verify all (php -l · run.php 88/88 · research 34/34
  · new tests · tsc 59/0 · build) · changelog · commits.
