# GAP ANALYSIS — THE REVIEW EXPERIENCE ROUND (owner browser pass 2026-07-19) — AWAITING GO

Nine owner findings/orders from the Super-optimize browser round, each
root-caused in the code. Supersedes nothing; extends gap eeec6b9 (Slice B
still held for the other dev's in-flight seo files — items 1 and parts of
4 land there when clear).

## THE FINDINGS (fact → cause → fix)

**F1 — ENGLISH REWRITES (the worst offender; live-confessed).**
The section prompt orders "Write in {{site.lang}} (the SAME language as
the current section)" (prompts.php:79) — but `site.lang` is filled from
`get_locale()` = THE HUB'S OWN WordPress language with a hardcoded 'en'
fallback (seo/service.php:2461, :7054). The hub is English → every remote
site's prompt says "Write in en" → Swedish pages translate to English.
LIVE PROOF: a change card in the owner's run confessed "Translated the
section from Swedish to English as required by the language setting."
FIX: the content itself is the language truth — the instruction becomes
"write in the SAME language as the current content", no locale code
injected anywhere; the same language law rides the strict contract and
the change-envelope. The hub-locale masquerade dies. (seo files — HELD
until the other dev's flight lands; highest priority of the round.)

**F2 — TOAST BEHIND THE EDITOR.** The toaster has no explicit stacking
order (ui/sonner.tsx); the page editor portals with a z-30 blur backdrop
above it. FIX: explicit toaster z-index above every modal layer. One line.

**F3 — MULTI-COLORED REWRITE BLOCKS.** In the consolidated rewrite view
all new blocks are the added lane (sky) EXCEPT the first heading, which
carries the identity origin attribute that also styles it amber
(word-diff.ts:152-162) — identity and color accidentally coupled.
FIX: decouple — origin attribute stays (identity law), consolidated
blocks render ONE uniform lane. Presentation only.

**F4 — THE SAME DESCRIPTION UNDER EVERY SECTION.** Pending sections and
card-less sections print the RUN-LEVEL directive dump identically
(SectionModal.tsx:2417-2445). FIX (enabled by the router, 7db4c7a):
pending cards show ONLY that section's routed directives ("what is being
done HERE"); finished cards show the section's verified changes; the
run-level order renders ONCE at the rail top. ONE format for quick and
super runs.

**F5 — HIERARCHY BACKWARDS.** Card sub-text out-weighs the section
heading (heading text-[11px] font-medium vs busy darker detail lines).
FIX: typography pass — section title clearly dominant, details quieter,
tags smallest.

**F6 — RAILS BECOME OUTSIDE ADD-ONS.** The keyword drawer attaches
OUTSIDE-left; the analysis/review rails render INSIDE the card and
squeeze the document. The width machinery already tapers
(pageCardWidth:151). FIX: mirror the drawer — the pair becomes
[drawer][card][rail], card slims slightly, rails attach outside-right
with the same 300ms taper. No anchor math (standing law).

**F7 — CHECKBOXES TINY + MISALIGNED.** 10px boxes aligned text-top
(SectionModal.tsx:2373-2381). FIX: proper size, centered to the first
text line.

**F8 — (folds into F4)** Super and quick runs must present identically —
covered by F4's one-format rule.

**F9 — THE REVIEW FILTER (owner order this pass).**
Every change already carries its purpose tag (`c.why` → teacher →
group 'search'|'ai'; GROUP_PILLS types.ts:105, teacher map in the rail).
NEW: at the rail top, TWO TABS — SEO · AI — and under them the TAG CHIPS
(the purposes present in this run: topic coverage, internal links, …).
Tag chips are MULTI-SELECT; a tab narrows to its group, chips narrow to
their purposes; the cards and their changes filter live so the user sees
exactly "all topic-coverage optimizations" at a click. TWO clear
actions: one clears the tab selection, one (below the chips) clears all
selected tags. Empty-filter result states itself (never a blank rail).
All data exists — this is display + filter state only.

## THE PLAN (execution order, ritual per slice)
1. **S1 — F2 + F7 + F5 + F3** (pure display, one pair: toaster z, checkbox
   size, hierarchy pass, uniform consolidated lane).
2. **S2 — F4/F8 + F9** (one pair: per-section pending cards from routed
   directives, run order once at top, one format, tabs + tag chips +
   the two clears).
3. **S3 — F1 + eeec6b9 Slice B** (seo/service+controller: the language
   law, the page map, the strict guard — lands the moment the other
   dev's in-flight files are clean; checked before every slice).
4. Ritual per slice: clean-tree check → build → php -l where PHP →
   harness 88/88 + research 34/34 → tsc 59/0 new → build "built in" →
   changelog → pathspec AFTER commit LOCAL ONLY.

## Regression surface (named)
Accept/Reject/Revise semantics untouched everywhere · identity anchors
untouched (F3 changes color only) · quick runs byte-identical except
where the owner ordered shared format (F4) · filters are view-state only
(no data model change) · the held seo files stay untouched until clean.

---

## ADDENDUM — THE SENIOR PLAN, REGROUPED BY FILE (owner GO 2026-07-19)

One file = one editing session = zero rework. Verified buildable with
full functionality per group; the held group is named, not hidden.

**GROUP A — ui/sonner.tsx (1 touch):** explicit toaster z-index above
every modal layer (F2).

**GROUP B — word-diff.ts (1 touch):** consolidated view renders ALL new
blocks in ONE lane — the origin attribute survives for identity but a
`data-pcm-rewrite` flag suppresses its lane color there (F3).

**GROUP C — SectionModal.tsx (ONE session, seven changes):**
C1 ReviewSection gains its OWN routed directives (captured at run start
   from the router's targets).
C2 Cards: pending = the section's own orders · finished = its verified
   changes · the run-level order renders ONCE at the rail top · the
   per-card run dump DIES (F4/F8).
C3 Hierarchy: title dominant, details quiet, tags smallest (F5).
C4 Checkboxes sized + centered (F7).
C5 THE FILTER: SEO/AI tabs + multi-select tag chips + per-level clears +
   stated empty state; filters match changes by why→teacher→group and
   pending cards by their directives' purposes (F9).
C6 RAILS OUTSIDE: the analyze/review rails move out of the card into the
   flex pair ([drawer][card][rail]) with their own ref exempted in the
   outside-click flow (the drawer's exact precedent) and the card taper
   extended to count the rail (F6).
C7 The Original row ALWAYS renders: loading state, unavailable-with-retry
   state, pickable when loaded (frontend half of the Original pair).

**GROUP D — HELD (seo/service.php + controller + prompts.php are the
other dev's LIVE in-flight set, re-verified this minute):** the language
law (F1), the page map + strict guard (Slice B), the pinned original
(server half) + the fetch probe. Lands as ONE server group the moment
the files are clean — the frontend above already sends/renders
everything it needs.

Ritual: build per group → tsc 59/0 new → build "built in" → harness
88/88 + research 34/34 → changelog → pathspec AFTER commits (A+B one
display commit, C one commit) — LOCAL ONLY.
