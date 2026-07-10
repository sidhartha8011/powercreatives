# GAP ANALYSIS — Headings go FULLY DYNAMIC (no more hard rewriting) — 2026-07-10 — AWAITING GO

Owner order: heading edits must stop rewriting stored content/builder data and
serve as DYNAMIC render-time instructions instead — the same engine sections
and paragraphs already use. All facts below code-verified today. Companion:
architecture doc → "Cleanup contracts v3" + "The routing law" (which
anticipated this exact flip: *"headings can migrate to dynamic as a routing
change, not an engineering project"*).

## WHERE HEADING EDITS GO TODAY (verified)

| Path | Mechanism | Storage touched |
|---|---|---|
| v3 connector, content/builder heading | WRITER-FIRST source write: identity-only `/replace-heading` → builder widget fields → inline markup deep-replace → shared template posts | post_content / builder meta — **the hard rewriting** |
| v3 connector, theme/menu heading (no storage form) | `save_heading_rule()` → SITE-scope heading instruction | none (already dynamic) |
| 2.x fleet | handle-based source writes + legacy `/override-heading` | post_content / builder meta / options |
| Local (hub-own) posts | `update_post_heading()` writes post_content | post_content |

## TARGET

**One mechanism on v3 connectors: every heading edit = a heading instruction**
(rule schema v2.1 heading target), served render-time, reversible by editing
back, absorbed into the existing serving pass, push-fail rollback. Source
writes for headings die on the v3 path. Links + meta stay source-written
(unchanged). Local hub posts stay source-written (no connector engine exists
there — boundary, not debt).

**Accepted trade-off (owner, 2026-07-10):** stored content keeps the ORIGINAL
text — the client's WP/builder editor shows the old heading while the live
page serves the new one. (Same trade the override layer always made; now it
applies to every heading edit on v3 sites.)

## THE GAPS (what the change actually needs to do)

| # | Gap | Evidence (code today) | Fix |
|---|---|---|---|
| G1 | **Scope decision is impossible today**: the snapshot inventory can't tell a theme/nav heading (renders on EVERY page → needs site-scope) from a page's own heading (needs post-scope, or the edit would leak to other pages with the same heading text) | `parse_page_snapshot()` inventories all body headings with no chrome tag | Tag each heading row `chrome: bool` from the SAME chrome spans paragraphs use. Chrome heading → site-scope rule; content heading (incl. the theme-rendered post-title H1 — it renders only on its page) → post-scope rule |
| G2 | **Serving is not occurrence-aware for headings**: the pass replaces EVERY matching `<hN>` (compiled-override semantics) — editing one of two identical H2s on a page would change both; source writes were occurrence-aware | Connector heading pass (seohub/service.php ~2250): `preg_replace_callback` with no occurrence check | Connector **3.0.1**: heading pass honors `match.occurrence` unless the rule carries `section.allOccurrences:true`. Site-scope/compiled-override rules set `allOccurrences:true` (semantics preserved byte-identically); post-scope edits target the k-th twin. + harness fixtures. Contract note (v2.2, additive) |
| G3 | **`save_heading_rule()` is site-scope-only** (postId 0, occurrence 0 hardcoded) | service.php `save_heading_rule` | Add scope (postId 0/N) + occurrence params; post-scope UPSERT keys on (postId, matchText, occurrence); same current-value re-edit match, exact-original revert, rollback (helpers already postId-parametrized) |
| G4 | **The routing flip itself** | `remote_update_heading_apply()` v3 branch does writer-first | Delete the writer-first branch; v3 heading edits → `save_heading_rule` with G1 scope + row occurrence. Re-key of section rules already fires on any successful edit and identities already compute on display state (`heading_instructions` covers postId IN (0,N)) — carries over unchanged |
| G5 | **UI truth**: instructed rows are labeled "Site-wide override (render-time)" — wrong for post-scope | `parse_page_snapshot()` sourceLabel | Label per scope: "Optimized (render-time, this page)" vs "Site-wide override (render-time)". No component change — the badge reads sourceLabel |
| G6 | **Dead code after the flip** (no-dead-code law) | — | Connector 3.0.1: delete `/replace-heading` route + the heading-only builder walkers (`scan_headings`, `scan_template_headings`, `collect_headings`, `collect_headings_html`, `replace_heading_field`, `set_heading_in_element`) — verified their ONLY remaining caller is the identity resolution inside `/replace-heading`; link walkers untouched (links stay source-written). Hub: the writer-first branch. The 2.x legacy hub paths stay until fleet convergence (already-documented follow-up) |

## FUNCTION-INTACT MAP

| Function | Today | After | Change for the user |
|---|---|---|---|
| Heading edit in the outline (text + H1–H6) | source write when reachable, else dynamic | ALWAYS dynamic (v3) | live page: none; client's builder/editor shows original text (accepted above) |
| Edit one of two identical headings | occurrence-aware source write | occurrence-aware rule (G2) | none |
| Edit back to original | source write back / override entry dropped | rule deleted (existing clean revert) | none |
| Theme/menu headings | dynamic (site-scope) | same | none |
| Sections / paragraphs / links / meta | — | untouched | none |
| `/{slug}.md` + llms.txt renditions | read raw storage | same — they show ORIGINAL headings for dynamically-edited pages (was already true for override-edited headings) | documented, honest |
| Local hub posts | source write | same (boundary) | none |

## THE WORK (order, full ritual, local commits only)

1. **Contract note v2.2** (architecture doc, additive): heading `match.occurrence`
   honored at serve; `section.allOccurrences` flag; scope decision law (chrome
   ⇒ site, content ⇒ post).
2. **Hub**: `parse_page_snapshot()` chrome tag + per-scope labels ·
   `save_heading_rule()` scope/occurrence · routing flip in
   `remote_update_heading_apply()` (v3 branch only) · migration `C4` sets
   `allOccurrences:true` on converted overrides.
3. **Connector 3.0.1**: occurrence-aware heading pass · `/replace-heading` +
   heading walkers deleted.
4. **Harness**: fixtures for occurrence-aware pass (k-th twin, allOccurrences,
   site+post coexistence) — extending the committed 37.
5. Verification: harness ALL GREEN · php -l + extracted template lint · tsc 59
   baseline · build · then your live pass on powerleads 3.0.1.

## RISKS (named)

- **Match tolerance**: rules match on normalization spec v1 (the old source
  write matched exact stored markup) — an already-documented C3 semantic;
  misses are honest (original serves + stale count), never wrong content.
- **Loopback-blocked sites** (snapshot tier 2/3): theme headings absent from
  the inventory there — pre-existing since C3, unchanged by this flip.
- **Rule accumulation**: every heading edit is now a stored rule; clean revert
  deletes on edit-back, and rules are a few rows per post — no cap needed yet.

## OUT OF SCOPE

Version history for headings (overrides never had one — ask if wanted) ·
links/meta routing (stay source-written per the routing law) · 2.x fleet
behavior (dies at fleet convergence, already gated) · Draft→Publish.
