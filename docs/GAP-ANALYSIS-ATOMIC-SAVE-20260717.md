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

## E. VERIFY PLAN

php -l · harness 88/88 + 26/26 (versioning suite covers page-state
semantics — the one-bump-per-save change lands there as updated
expectations, extended if uncovered) + 34/34 · tsc 59/build (frontend
wording only, if touched) · live: one multi-section save on the draft
Privacy Policy = exactly ONE connector /rules POST (count via connector
option write / probe), corner green after, version +1 not +N ·
changelog · AFTER commit LOCAL ONLY.
