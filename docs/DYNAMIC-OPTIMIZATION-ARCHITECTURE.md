# Dynamic Optimization — Architecture (agreed 2026-07-07/08, pre-implementation)

Entry point: the SEO outline's **page-content rows** ("point 5"). That feature is
phase 0 of the dynamic-optimization system — it builds the paragraph inventory
everything else consumes. Read together with `docs/backlog.md` → "Autonomy
roadmap" (items 1–4 there are the pipeline this plugs into).

## The routing law (decided — do not re-litigate per feature)

| Content kind | Mechanism | Why |
|---|---|---|
| Meta fields (title, description, keywords, slug, schema, status) | **Source write** (existing `save_cell` / `remote_save_cell`) | Standard WP storage — never had the builder problem |
| Headings, links reachable in stored content | **Source write** (connector 2.2.x heading/link paths) | Proven through the 2.2.0–2.2.3 hardening; permanent edits the client's editor sees |
| **Paragraphs** (on-page body text) | **Dynamic rule** (render-time, this doc) | No source solution exists; builder storage is per-builder pain |
| `editable:false` rendered-only links | **Dynamic rule** | Source-edit literally cannot reach them — first legitimate gap-closer |
| New interlinks from the AI optimizer | **Dynamic rule via paragraph rewrite** | An "after" paragraph containing the anchor |

The rule engine is designed **target-agnostic** (a rule = swap a text node /
attribute), so headings/links can migrate to dynamic later as a routing change,
not an engineering project. Migration happens only if 2.2.x maintenance cost
ever flips — deliberately, never silently.

## Phase 0 — Page-content rows in the SEO outline (the "point 5" feature)

**Data.** The heading scan grows into a content scan returning the page as an
ordered list of nodes: `{ index, kind: 'heading'|'paragraph', level?, text,
html, elId? }` — document order, headings exactly as today, paragraphs sliced
between them. Local: parse rendered/post content in `PCM_SEO_Service`. Remote:
connector `/scan-headings` extended (or sibling `/scan-content`) → **connector
version bump + reinstall on connected sites** (known ritual).

**UI (agreed layout).** Inside the existing expanded outline (HeadingsPanel),
natural document order: heading rows as today; paragraph rows indented beneath
their heading, standalone paragraph rows (no fake header) when orphaned. Each
paragraph row = one-line text preview; **click → inline popup showing the HTML**
of that block (LinksPopup is the in-house pattern). Read-only in phase 0.

**Why phase 0 matters:** node `{kind, index, normalized text}` is the exact
identity a dynamic rule targets and the exact unit the AI optimizer rewrites.
The inventory IS the shared contract.

### Node identity contract v1 (FROZEN 2026-07-08, pair 1)

Implemented by `PCM_SEO_Service::parse_content_nodes()` / `get_post_content_nodes()`
(`GET /seo/content/{id}/content-nodes` → `{ nodes: [...] }`).

- **Node** = `{ index, kind: 'heading'|'paragraph', level? (headings), text,
  html, source, elId, editable }`.
- **`index`** = the node's position in the ordered node list — the address a
  dynamic rule targets and the AI optimizer rewrites. Heading nodes ALSO carry
  **`headingIndex`** (position in the headings-only list) — the edit handle for
  the EXISTING heading endpoints, which stay unchanged.
- **Ordering:** strict document order; one combined parse (headings exactly as
  `parse_heading_details` — identical skip rule — plus every `<p>`).
- **Skip rules:** headings with empty stripped text; paragraphs empty after
  tag-strip + entity-decode (a lone `&nbsp;` spacer is not content).
- **Classic content:** when the content has no `<p>` at all, `wpautop` is
  applied for the parse (mirrors what the site serves; headings unaffected).
- **Text normalization (match side, pair 2):** collapse whitespace incl. NBSP,
  decode entities, case-insensitive compare — specced with the matcher.
- Any change to ordering/skip/shape = contract version bump, documented here.

## Phase 1 — Approval rails (backlog item 1, unchanged)

Staging table → envelope → approvals adapter registry → Before/After cards →
apply-on-approve via `approvals.asset_approved` (owner identity verified at
service.php:1163). The apply handler routes each change per the table above:
meta → source write; paragraph → **create a dynamic rule** (phase 2).

## Phase 2 — The connector rule engine (render-time)

**Rule shape** (stored ON the connector, pushed from the hub):
```
{ id, postId, target: 'paragraph'|'heading'|'anchorText'|'href',
  match: { text: <normalized before-text>, occurrence: n },
  replacement: <after html/text>, active: bool, sourceChangeId }
```
**Serving:** full-response output buffer (`template_redirect` + `ob_start` —
NOT `the_content`; some builders bypass it). At render, active rules for the
post run a **text-tolerant matcher** (normalize whitespace/entities/case,
match text content not markup — builders wrap text in their own spans).
Cached pages store the optimized output; every rule change triggers the
existing `pcm_conn_purge_caches`.

**No silent fallback:** a rule whose match fails serves the ORIGINAL and marks
itself stale (counter on the connector, reported on heartbeat) — never a guess.

**Push:** hub → `POST /pcm-conn/v1/rules` (app-password auth, replaces the
post's rule set). Connector version bump.

## Phase 3 — Switches + serving states (agreed)

- `active` toggles at three scopes: per change, per page (all rules), per site
  (kill switch). Off = stop applying + purge; nothing to restore — the source
  is always the original.
- Three-state indicator in the SEO table: **original** (no rules) /
  **optimized** (N active) / **stale** (active but not matching → serving
  original, flagged). Before-values already stored by the staging rows power
  stale detection and before/after display.

## Phase 4 — Licensing (agreed: local-first + heartbeat)

Rules live on the client site → serving has zero runtime dependency on the
hub. Daily wp-cron heartbeat ("am I licensed?"), last-success stored locally;
grace window ~14 days then rules auto-deactivate to originals (fail-closed AND
harmless); explicit revoke = immediate deactivation; hub-side license/revoke
surface per site. Honest limit: PHP on their server — deters passive
freeloading, not determined engineers; the retention moat is that the
intelligence (staging/approval/AI/rule management) lives in the platform.

## Phase 5 — AI page optimizer (backlog item 4)

Second producer into the same rails: SERP-gap analysis → per-paragraph/heading
before/after cards (send-time choice: one card per page or per change) →
approved content changes become dynamic rules. Review-only until phase 2
ships; thresholds/prompts specced with the PO, never guessed.

## Build order & sizing

0. Phase 0 (content scan + outline rows + HTML popup) — medium; connector bump.
1. Rails — the 4–5/10 build (backlog item 1).
2. Rule engine + push + purge — the core new engineering (largest single piece).
3. Switches/states — small once 2 exists.
4. Heartbeat licensing — small-medium; mostly hub-side surface.
5. AI optimizer — after 1 (cards) and 2 (apply).

## Open decisions for the PO (flagged, not blocking phases 0–1)

- Paragraph identity under client edits: match text changed → stale (agreed);
  do we auto-expire stale rules after N days or keep flagging forever?
- Rules pushed on approval automatically (agreed default) vs. an explicit
  per-site "publish rules" step?
- Grace window length (default proposal: 14 days) + heartbeat frequency (daily).
