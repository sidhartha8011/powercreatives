# GAP ANALYSIS — DYNAMIC ALIGNMENT (2026-07-10) — AWAITING GO

**Owner order:** headings edit DYNAMICALLY (the storage-rewriting path dies),
identity is SMART and PER-PAGE (never accidentally site-wide), the editor
represents the SERVED page, zero dead code, zero tech debt, keep what's
reusable. **Supersedes and absorbs** `GAP-ANALYSIS-HEADINGS-DYNAMIC-20260710.md`
(deleted in this commit). Confirmed checklist: conversation 2026-07-10.

Every fact below is code-verified or live-proven on powerleads this day.

## PART 1 — CURRENT STATE (facts, with evidence)

| # | Fact | Evidence |
|---|------|----------|
| F1 | v3 heading edits are WRITER-FIRST source writes (identity-only `/replace-heading`: builder widget → inline markup → shared templates); only when storage has no form does the edit become a rule — and then always **SITE-scope** (`save_heading_rule` hardcodes postId 0) | `remote_update_heading_apply()` snapshot branch; live: rules 7/8 (`sample page→This is my best page`, `powerleads→This is the best power leads`) are site-wide rows born from page-level intent |
| F2 | The serving heading pass replaces **EVERY** matching `<hN>` — `match.occurrence` is carried but ignored | connector template, Pass 0 (`preg_replace_callback`, no occurrence check) |
| F3 | The inventory can't tell page content from site chrome for headings, and labels every instructed row "Site-wide override (render-time)" | `parse_page_snapshot()` — headings inventoried without a chrome tag |
| F4 | **Rule chains exist in production data**: heading rule 7 transforms `Sample Page→This is my best page`, and section rule 6 keys on `this is my best page` to serve `<h1>Welcome to Our Best Page</h1>…`. Output correct, but N-deep handoffs where each link can strand the next | hub DB rows 6+7 + live/snapshot fetch diff (2026-07-10) |
| F5 | **The editor shows the rules-INPUT, not the served page**: (a) heading rows show mid-chain text no visitor sees (H1 row "This is my best page" vs served "Welcome to Our Best Page"); (b) content existing only inside a section replacement gets NO rows (live: the whole "Tandläkare i Göteborg" section is invisible in the outline); (c) the section P-row preview is `textOf(rule.replacement)` — the replacement's heading concatenates into the paragraph preview | `remote_get_inventory()` applies heading instructions only; live screenshots; `HeadingsPanel.tsx:439` |
| F6 | Site-scope heading rules have no management surface (no list/delete in the UI; revert only by editing back) | `list_dynamic_rules()` is per-post; postId 0 rows appear nowhere |
| F7 | **KEEP (reusable, proven):** the whole serving engine incl. the heading pass and site+post scope storage/push (schema v3) · `save_heading_rule` mechanics (current-value re-edit UPSERT, exact-original revert, push-fail rollback) · `chrome_spans` detection · absorb + re-key + clean-revert laws · the committed harness (37/37) · capability gating · SectionModal + its data contract | cleanup C1–C5 commits, harness green |
| F8 | **Dead after the flip (verified callers):** connector `/replace-heading` route + the heading-only builder walkers (`scan_headings`, `scan_template_headings`, `collect_headings`, `collect_headings_html`, `replace_heading_field`, `set_heading_in_element`) — sole remaining caller is `/replace-heading`'s own resolution; hub: the writer-first branch. `rebuild_heading_html` + `heading_target_post_id` + legacy override path stay ONLY for the 2.x fleet and die in the already-gated convergence commit | grep audit this session |
| F9 | Boundaries (unchanged by design): 2.x fleet keeps today's behavior until convergence; local hub posts have no rules engine → stay source-written; links + meta stay source-written per the routing law | architecture doc routing law |

## PART 2 — TARGET (the confirmed checklist, restated as laws)

1. **One mechanism:** on v3 connectors, every heading edit is a dynamic rule.
   Storage is never rewritten for headings.
2. **Smart identity:** page-content heading → **post-scope** rule keyed
   `{postId, normalized text, level, occurrence-among-page-twins}` — only that
   page, only that twin. Chrome heading (site name/menu/footer) → site-scope,
   honestly labeled. Serving honors occurrence; an explicit
   `allOccurrences: true` keeps override semantics for chrome rules and
   migrated legacy overrides.
3. **One owner per element:** editing a heading that a section rule already
   serves UPDATES that section rule's replacement — a second rule is never
   stacked. Saving a section ABSORBS any heading rule covering its heading
   (extends the existing paragraph-absorb law). Chains become impossible to
   create.
4. **Served-truth editor:** the outline is built from the SERVED page. Every
   served block is a row — including sections that exist only inside a rule's
   replacement. Every row maps to the rule that produced it; clicking opens
   that rule (or its slice) for editing; editing back to the original still
   deletes the rule.
5. **Zero dead code:** the F8 list is deleted in the same pair that obsoletes
   it. Nothing is kept "just in case".

## PART 3 — THE GAPS (current → target)

| # | Gap | The change |
|---|-----|-----------|
| G1 | No chrome/content distinction (F3) | `parse_page_snapshot()` tags each heading row `scope: 'post'|'site'` from the SAME chrome spans paragraphs use; per-scope `sourceLabel` ("Optimized (render-time, this page)" / "Site-wide override (render-time)") |
| G2 | `save_heading_rule` is site-only (F1) | Scope (postId 0/N) + occurrence params; post-scope UPSERT identity {postId, matchText, level, occurrence}; revert/rollback mechanics kept verbatim |
| G3 | Serving ignores occurrence (F2) | Connector **3.0.1**: heading pass targets the occurrence-th match unless `section.allOccurrences`; C4 migration + chrome-scope saves write `allOccurrences: true`; harness fixtures (k-th twin, allOccurrences, coexistence) |
| G4 | Writer-first routing (F1) | v3 branch of `remote_update_heading_apply()`: chrome row → site rule; content row → post rule; section-owned row → G5. The source-write attempt is deleted |
| G5 | Chains creatable (F4) | Edit of a section-owned heading rewrites the owning section rule's FIRST replacement unit (existing `save_section_rule` path → re-key + versions ride along); section save absorbs covering heading rules. Existing chains (test rules 6+7) resolve naturally on next section save via absorb — no one-off migration needed |
| G6 | Editor shows rules-input (F5) | Connector snapshot gains `?mode=served` (loopback WITHOUT the exclusion param; cached separately; busted on save_post + every rules push). Hub `remote_get_inventory()` parses BOTH views: identity (rules-input) + display (served). Rows carry served text + `rule: {id, target} | null` attribution — the hub owns every rule, so attribution = matching served blocks against each rule's replacement units (heading rules by replacement text, sections/inserts by unit fingerprints); ambiguity (identical outputs) resolves by rule order, cosmetic only |
| G7 | Replacement-born content invisible (F5b) | Served rows group into sections by the same owner-locked law. Each section row resolves to: original section (no rule) → today's flow · a section rule's whole output → today's flow · a SLICE of a bigger replacement (e.g. "Tandläkare i Göteborg" inside rule 6) → the modal edits that unit range and Acceptera rewrites the owning rule's units for that slice · an insert's output → today's insert flow. SectionModal gains the slice context (`{ruleId, unitFrom, unitTo}`) |
| G8 | Preview concatenation (F5c) | Row previews come from the served section's PARAGRAPHS only (automatic once G6 rows exist; `HeadingsPanel.tsx:439` branch dies) |
| G9 | No surface for site-scope rules (F6) | Chrome rows badge "site-wide" + tooltip; a compact "Site-wide heading changes" group at the outline top lists postId-0 rules with revert (edit-back) affordance |
| G10 | Dead code (F8) | Delete in the SAME pair: `/replace-heading` route + the six heading walkers (connector 3.0.1) + the hub writer-first branch. Legacy 2.x hub paths stay only as the documented convergence fallback |
| G11 | Contracts | Architecture doc gains **v2.2 (additive)**: occurrence/allOccurrences serving law · scope-decision law (chrome ⇒ site, content ⇒ post) · one-owner law · served-view snapshot mode · slice-edit semantics. Old gap-analysis file deleted (superseded by this one) |

## PART 4 — THE PLAN (pairs, full ritual, local commits only)

- **P1 — scope + flip (data correctness first):** G11 contracts · G1 · G2 ·
  G3 (connector 3.0.1) · G4 · G10 · harness fixtures. After P1, wrong-scope
  rules can no longer be created and the old hack is gone.
- **P2 — one owner per element:** G5 + fixtures (absorb/compaction laws).
- **P3 — served-truth editor:** G6 · G7 · G8 · G9 (the biggest pair: connector
  served-mode + hub dual parse + attribution + slice editing + outline/UI).
- Verification per pair: harness ALL GREEN · php -l + extracted template lint ·
  tsc 59 baseline · build · owner live pass on powerleads after P1 and P3.

## PART 5 — RISKS (named, mitigated)

- **G7 slice editing** is the deepest new engineering; bounded because
  replacement units are already parsed blocks — a slice is a contiguous unit
  range addressed by the served section's identity.
- **Served-view staleness**: same bust law as the snapshot (save_post + every
  rules push); a stale served view can only mis-DISPLAY, never mis-serve.
- **Occurrence shifts** when earlier twins appear/disappear: same existing
  behavior as paragraph occurrence — the stale-flag is the safety net, never a
  wrong swap.
- **Match tolerance** (normalized vs the old exact storage match): documented
  since C3; misses are honest.

## OUT OF SCOPE

Links/meta routing (stay source-written per the routing law) · 2.x fleet
(dies at convergence, already gated) · local hub posts (no engine — boundary)
· Draft→Publish · heading version history (overrides never had one — say if
wanted and it rides the sections mechanism).
