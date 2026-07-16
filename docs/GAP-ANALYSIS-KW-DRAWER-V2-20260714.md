# GAP ANALYSIS — KEYWORD DRAWER V2 (smart button · selected table · discovery) — 2026-07-14 — AWAITING GO

**Owner design (this session):** the Keywords button becomes a live display
(primary · +count · density%), the drawer becomes THREE stacked tables —
SELECTED (the decisions) / GSC (the evidence) / DISCOVERY (the ideas) —
one + gesture, one destination. Compare is always on. Every fact below is
from the code.

## Facts (verified)

| # | Fact |
|---|---|
| K1 | The pieces of the SMART BUTTON already exist in SectionModal: `primaryKw` state, the bucket, AND a re-render on every edit (`onUpdate → setEditorTick`) — live density is computable per render from `editor.getText()` with ZERO new plumbing. The dropdown-family trigger look is a defined constant (ModelDropdown TRIGGER_STYLE: solid white, border, rounded-full, xs) — the button adopts the family look, placed right of Page type |
| K2 | The SELECTED set already lives in exactly three stores with existing write paths: primary → SEO field `primaryKeyword` (remoteSaveCell), supporting → SEO field `supportingKeyword` (comma list, remoteSaveCell), additional → the bucket option map. The selected TABLE is a VIEW over those stores; a role change is a MOVE between them via the same writes — no new storage, the inputs die because the table replaces their function |
| K3 | DENSITY: phrase occurrences counted case-insensitively over `editor.getText()`; density % = occurrences × words-in-phrase ÷ total words × 100 (the standard phrase-density definition — stated so the number has a meaning). Recomputed per keystroke via K1's existing tick |
| K4 | VOLUME: the keyword engine's enrichment EXISTS — `POST /keywords/enrich {keywords[], country}` (keywords/controller.php:120, Ahrefs key via Integrations, batch phase = fast). It COSTS Ahrefs credits → volumes fetch on ADD + on a manual refresh, never on a timer; results cached in an option map (keyword → {volume, fetchedAt}, capped, 30-day TTL — the hub-data pattern) so re-opens are free |
| K5 | DISCOVERY: the engine's search EXISTS — `POST /keywords/search {query, lang, gl}` → suggestions[] (keywords/controller.php:90, Google Autocomplete proxy). Drawer MVP = ONE call per typed seed (the Keywords module's prefix fan-out stays module-only) + ONE batch enrich (capped 20) for volumes. Same DataTable anatomy as the GSC table, + adds to SELECTED as Additional |
| K6 | COMPARE ALWAYS ON: the checkbox + state die; `scan` always sends `compare: true`; the Δ columns become permanent. (Cost note: two Google calls per live refresh — accepted by owner) |
| K7 | AMENDED (owner UX round): NOT three stacked tables — TWO ZONES. Top: the SELECTED table (the decisions, pinned, sized to content). Bottom: ONE FINDER table with a two-tab switch — **Ranking** (the GSC list: days/refresh controls, Δ columns, compare always on) and **Ideas** (a seed input + the keyword engine's suggestions with volumes). Same table anatomy, same + gesture, one scroll area — cart on top, store below |

## The build
1. **Smart button:** family-look trigger right of Page type: `key icon +
   primary + (+N) + density%` (em-dash states when unset); opens the drawer.
2. **Selected table (drawer top):** Keyword · Role tag (click → menu:
   Primary/Supporting/Additional; assigning Primary demotes the old one) ·
   Uses (live) · Density (live) · Volume (K4 cache) · remove. A quiet
   "+ add keyword" row replaces the deleted inputs. All writes through the
   K2 stores.
3. **GSC table:** as-is minus the compare checkbox (K6).
4. **Discovery section (drawer bottom):** one seed input + search → the
   suggestion rows (keyword · volume · +). Honest empty/error states
   (no Ahrefs key → volumes show —, keywords still usable).
5. **Backlog:** the entity/Knowledge-Graph capability (Google KG Search API
   ids + NL entity analysis) logged under the knowledge-graph feature —
   NOT built now (owner order).

## Regression surface
The keyword ride (primary + bucket into every optimize run) unchanged —
same stores · SEO-field writes unchanged (same routes, same fields) · GSC
table logic untouched beyond the checkbox · the Keywords MODULE untouched
(its endpoints consumed read-only) · editor perf: density is O(text) per
tick on one page's text — negligible next to the existing getHTML calls.

## CHECKLIST
- [ ] BEFORE = this doc committed
- [ ] Smart button (family look, live values, placement)
- [ ] Selected table + role moves + live uses/density + volume cache + add-row; inputs die
- [ ] Compare checkbox dies (always on)
- [ ] Discovery section (search + enrich + add)
- [ ] Backlog: entity/KG logged
- [ ] Verify: php -l · harness 88 · tsc 59 · build · changelog · commit LOCAL ONLY
