# GAP ANALYSIS — THE ATOMIC SAVE — 2026-07-17

Owner order: the save mechanism must be the most solid thing on the
platform. Full factual trace below; every claim at file:line
(includes/modules/seo/service.php unless noted).

## A. WHAT ONE CLICK OF SAVE DOES TODAY (traced, facts)

1. Frontend: ONE POST `…/page-edits` with the full document
   (SectionModal save() → remoteSavePageEdits; controller.php:507).
2. `save_page_edits()` (:4914) then:
   a. **Live baseline pull from the client site** — served_inventory()
      (:4921). Measured on this machine: 10–16s. A save cannot start
      without the site answering.
   b. Superseded-rule flatten (W2, :5299-5327): if dead rules exist —
      delete + **remote push #1** (push_current_rules_or_rollback :5313).
   c. **Every changed section routes through its own save path, and EVERY
      one of them pushes the whole rule set to the site individually**:
      section replace :4375 · section insert :4595 · remove :4703 ·
      restore :4783 · revise :4845 · image hide/unhide :5698/:5791 —
      each calls push_current_rules_or_rollback (:4199), each rolls back
      only ITS OWN step, each bumps pageState version by one (:4210-4217).
   d. One page-version row at the end when anything changed (:5578).

## B. THE VERDICT ON TODAY'S DESIGN (facts, not opinion)

- **Cost: O(N) remote round-trips per save.** A save touching 5 sections =
  1 baseline pull + up to 6 sequential pushes. On this machine
  (10-16s/request) that is 60-100+ seconds of wheel — the owner's
  observed spin. Even on a healthy site the structure is N round-trips
  for one user intent.
- **Atomicity is per-STEP, not per-SAVE** (:4908 comment says it
  plainly): a mid-sequence failure stops "honestly, reporting what
  already saved" — i.e. the page is left HALF-SAVED (steps 1..k applied
  and pushed live, k+1..N not). Honest, but not solid: the user's one
  intent ("save my document") can land partially on a LIVE client site.
- **pageState version jumps N times for one save** (one bump per push) —
  version numbers measure pushes, not user saves.
- Rollback exists and works per step (snapshot/restore :4155-4196) — the
  machinery for full-save atomicity is already on the shelf.
- Single-section saves (the section editor modal) are already 1 save =
  1 push = atomic. The page save is the outlier.

## C. TARGET — THE ATOMIC SAVE (the solid behavior, defined)

ONE user intent = ONE transaction = ONE push = ONE version. Concretely:

1. **Snapshot once** at save start (post_rule_rows — exists :4155).
2. **All routed saves mutate hub rows ONLY** — no per-step push, no
   per-step version bump. Mechanism: the routed save paths gain a
   deferred-push mode (EDIT of push_current_rules_or_rollback call sites
   inside the page-save flow only; the standalone section-editor paths
   keep their existing 1-save-1-push behavior — already correct).
3. **ONE push at the end** carrying the final net set + ONE
   version(+1)/fingerprint (push_current_rules_or_rollback exactly as
   today, called once).
4. **Failure = TOTAL rollback to the start snapshot** — "Nothing was
   saved — retry." A save can no longer half-land on a live site. The
   partial-progress reporting (:4908 law) is SUPERSEDED by this gap —
   named deletion, not silent.
5. **Version rows record only after the successful push** (section rows
   currently record per-step post-push :4383/:4788/:4850 — they move
   after the single push; page row :5578 unchanged in spirit).
6. Wheel time becomes: 1 baseline pull + 1 push (2 site round-trips,
   ~25s on today's machine, seconds on a healthy one) — and honest
   staged wording on the button phases (state statements, sanctioned).

## D. EXPLICITLY OUT OF THIS PAIR (named, not lost)

- Baseline-from-fingerprint (skip the live pull when the featherweight
  check says the site still serves our exact version — the stored
  snapshot becomes the baseline): REAL upgrade, its own gap AFTER the
  atomic save proves itself. One step at a time.
- The environment (2 workers, 10-16s/request) — owner items, unchanged.
- E2 stored-first verdict (pipeline gap C2) — separate, still priced.

## E-CHECKLIST (owner GO 2026-07-17 — implement exactly this)

**Corner-truth addendum (owner order):** the status icon must represent the
site's exact state through the save: during save = spinner ("Saving —
pushing to your site…"); after a PUSHED save = green seeded from the SAVE's
own confirmation (the connector accepted the push and echoed the state —
push_rules only succeeds on acceptance, :4204-4217 law — so no second
15-30s round-trip re-asking what was just confirmed); a NO-OP save (nothing
pushed) must NOT overwrite the verdict; a failed save leaves the previous
verdict + named error. Facts: today the corner is untouched by save —
stale amber survives a successful save (save() SectionModal:979-1020 never
writes stateQuery).

1. [ ] service.php — TWO chokepoint deferrals (every routed path flows
       through them, grep-proven): push_current_rules_or_rollback returns a
       deferred stub first-line when the txn flag is set; record_version
       queues instead of inserting. Static flag + queue, private.
2. [ ] save_page_edits — txn wrapper: ONE snapshot before the W2 flatten;
       flag set inside try / cleared in finally (leak-proof); the $fail
       closure becomes TOTAL-ABORT (restore snapshot + clear queue +
       "Nothing was saved — retry" message); commit at end ONLY when
       mutated (changed>0 or flattened>0): clear flag → ONE real push
       (rollback to the txn snapshot on failure) → flush queued version
       rows → page version row (changed>0, as today) → reply gains
       `pushed` (bool) + `pageState` {version,fingerprint,savedAt} = the
       hub record the connector just echoed.
3. [ ] Section-editor single saves + all non-page callers: UNTOUCHED
       (flag only ever set inside save_page_edits, finally-cleared).
4. [ ] SectionModal.tsx — corner glyph: saving state FIRST (busyAction
       save/saveClose → spinner + "Saving — pushing to your site…" title);
       save() success with res.pushed → queryClient.setQueryData on
       ['seo','pageState',{siteId,postId}] with the confirmed state
       (local=record, remote={version,fingerprint}, drifted:false,
       error:null, brandId/pageType preserved from prev); no-op → no write.
5. [ ] page_versioning_test.php — new checks: flag set (reflection) ⇒
       push_current_rules_or_rollback returns deferred stub touching
       NOTHING; record_version queues; flag cleared ⇒ behavior unchanged.
6. [ ] php -l · 88/88 · versioning suite (26 + new) · 34/34.
7. [ ] tsc 59 zero new · build "built in" · served byte-identical.
8. [ ] LIVE END-TO-END: mu-plugin probe runs save_page_edits with the
       CURRENT saved html (no-op) — expect all-skipped, pushed=false,
       version 38 unchanged in BOTH DBs; probe deleted after (400 check).
9. [ ] Senior self-review vs this checklist, every edit.
10. [ ] Changelog + seo module 1.0.2 + AFTER commit "LOCAL ONLY".

## F. BUILT + PROVEN (same day) — and one open observation

All checklist boxes done. Named deviation: no try/finally — every exit path
between transaction open and commit was return-scanned (exactly two
non-$fail returns exist, both mine: the commit-push failure and the final
reply); $fail and commit clear the flag explicitly; PHP request isolation
is the backstop. Live web-context proof (temporary probe, deleted, 400
re-check): a save through the new transaction = ONE push, version 39→40
(+1 exactly), zero duplicate history rows (max row id unchanged), reply
carries pushed:true + the echoed pageState, 17s total = the two remaining
site round-trips.

**Open observation (pre-existing, not this pair):** the probe's re-submit
of the newest saved document reported `removed:1` — the served baseline
still exposes a "privacy policy" H1 section that saved documents omit
(sectionRemove rule id 239 now asserts the saved intent; earlier remove
rules 210-238 span the same page). Whether that H1 section's removal
APPLIES on the served page needs its own probe when the owner wants it —
saves converge the site to the saved document either way.

## E. VERIFY PLAN

php -l · harness 88/88 + 26/26 (versioning suite covers page-state
semantics — the one-bump-per-save change lands there as updated
expectations, extended if uncovered) + 34/34 · tsc 59/build (frontend
wording only, if touched) · live: one multi-section save on the draft
Privacy Policy = exactly ONE connector /rules POST (count via connector
option write / probe), corner green after, version +1 not +N ·
changelog · AFTER commit LOCAL ONLY.
