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

### Normalization spec v1 (FROZEN 2026-07-08, pair 2)

The matcher's law — byte-identical implementation on the hub
(`PCM_Text_Matcher::normalize()`) and in the connector
(`pcm_conn_normalize_text()`); the two MUST stay in sync (fixture-tested):

1. `html_entity_decode(ENT_QUOTES | ENT_HTML5, UTF-8)`
2. NBSP (U+00A0) → regular space
3. every Unicode whitespace run → one space (`/\s+/u`)
4. trim
5. case-fold (`mb_strtolower` UTF-8)

### Rule schema v1 (FROZEN 2026-07-08, pair 2)

Push payload `POST /pcm-conn/v1/rules` (replaces the post's whole set):

```
{ schemaVersion: 1, postId, rules: [ {
    id,                       // hub seo_dynamic_rules.id
    target: 'paragraph',      // enum reserved: paragraph|heading|anchorText|href
    match: { text, occurrence },  // text is ALREADY normalized (spec v1)
    replacement,              // sanitized (wp_kses_post) BEFORE storage
    active: bool,
    changesetId?, sourceChangeId?,
    anchor: { parentHeading?: {level, text} }  // re-anchor context (pair 4 healing)
} ] }
```

- Connector validates `schemaVersion === 1` and rejects others honestly.
- Capability check before every push: `GET /pcm-conn/v1/rules` → 404 = connector
  too old → honest error pointing at the Sites Connector column.
- Storage on the connector: `pcm_conn_rules_{postId}` options (autoload OFF) +
  `pcm_conn_rules_index`; serve/miss counters per post, throttled writes.

### Serving mechanism v1 (verified engineering decision, pair 2)

`template_redirect` + `ob_start`, singular front-end requests only, whole
callback in try/catch → ANY throwable serves the ORIGINAL buffer (a rule can
never break a client page). Per rule: find the target block, compare its
VISIBLE text (strip tags → normalize spec v1) against `match.text`,
occurrence-aware; swap the block's inner content with the pre-sanitized
replacement; no match → original + stale counter.

**Block targets (`paragraph`, later `heading`) use boundary matching on
non-nestable tags** (`<p>`/`<hN>` cannot legally nest — the same proven
pattern as the shipped heading overrides). **NOT** `WP_HTML_Tag_Processor`:
verified against core, its API is tag navigation + attribute writes
(+ per-text-node `set_modifiable_text` in newer WP) and cannot atomically
replace a block's inner HTML — a paragraph containing `<strong>` spans
multiple text nodes. The core parser IS the right tool for the future
`href`/attribute targets (`set_attribute`) and is reserved for exactly that.
This paragraph documents the decision so it is never re-litigated from the
earlier plan wording.

### scan-content v1 (connector route, pair 2)

`GET /pcm-conn/v1/scan-content?post_id=N` → `{ nodes: [...] }` where each node
is `{ kind:'paragraph', index, text, html, occurrence, source:'rendered',
anchor:{level,text}|null }` — paragraphs in TRUE rendered document order
(hardened loopback fetch, `<body>` only, `<header>/<nav>/<footer>/<aside>`
regions stripped). `anchor` = nearest preceding rendered heading, used ONLY
for display interleaving with the heading scan (heading rows + their editing
stay on `/scan-headings`, 100% unchanged). `occurrence` = position among
same-normalized-text paragraphs — the rule-target identity on builder pages.
Rationale: rules act on the SERVED page, so the inventory must come from the
rendered output, not raw storage. Loopback blocked → `{ nodes: [],
error:'loopback_blocked' }` (HTTP 200, honest) → the hub UI keeps the
headings-only view and says so.

### Section contracts v2 (FROZEN 2026-07-09, section-editor phase 1) — ADDITIVE

Owner-locked domain decisions: **every header owns its own section** (a
section = one heading + every following `<p>` until the NEXT heading of ANY
level); **new sections anchor to an existing header** (before/after — pages
with zero headings can't take inserts); per-row heading/paragraph editing
keeps the shipped v1 paths untouched.

**Section identity v2:** `{ headingText (normalized spec v1), headingLevel,
headingOccurrence, fingerprint }`.
- `headingOccurrence` = position among same-normalized-text headings in scan
  order. It is a HINT — the fingerprint is the guard (see serving).
- `fingerprint` = spec-v1 normalization of each of the section's paragraph
  visible texts, joined with `"\n"` (`PCM_Text_Matcher::fingerprint()`).
  Empty string for a heading with no paragraphs (legal).

**Rule schema v2 (additive to v1 — v1 rules and their serving byte-identical):**
- `target:'section'` (replace): `match:{text:<heading normalized>, occurrence:
  <headingOccurrence>}`, `section:{level, fingerprint}`, `replacement` = the
  ENTIRE new section as sibling block HTML (optional heading + `<p>/<hN>/<ul>/
  <ol>` blocks), pre-sanitized `wp_kses_post`.
- `target:'sectionInsert'`: `match:{text:<ANCHOR heading normalized>,
  occurrence}`, `section:{level, position:'before'|'after'}`, `replacement` =
  a complete new section. Identity = hub rule id (several inserts may share an
  anchor; UPSERT by id, never by matchText).
- Push carries `schemaVersion: 2` ONLY when the set contains section targets;
  otherwise v1 exactly as before. Old connectors (≤2.7.1) reject v2 honestly
  (`unsupported_schema`) — zero silent-drop risk. Capability = GET
  /pcm-conn/v1/rules `schemaVersion` (2.8.0 answers 2).

**Section membership (AMENDED 2026-07-09 — root-cause fact from the first
live test):** the paragraphs belonging to a section are taken from the
CONNECTOR SCAN's own anchors (`node.anchor` = nearest preceding rendered
heading — the exact same definition serving uses), NEVER from the outline's
display interleave. The display interleave resolves anchors against the
heading-scan list and parks unmatched paragraphs under the previous row —
correct for DISPLAY, wrong for IDENTITY (proven: a comments-area paragraph
whose anchor heading isn't in the heading scan was folded into the preceding
section → fingerprint had 4 paragraphs, the served page's section has 3 →
honest miss, rule never served). Duplicate anchors: the k-th contiguous RUN
of same-anchor paragraphs in scan order belongs to the k-th matching heading.

**Serving mechanism v2 (connector 2.8.0; chrome scope 2.8.1) — all-or-nothing,
wrapper-safe:**
1. Parse the buffered HTML's top-level `<h1-6>`/`<p>` blocks (same combined
   boundary regex family as scan-content — non-nestable tags, exact), then
   drop blocks inside CHROME (everything before `<body>` +
   `<header>/<nav>/<footer>/<aside>` regions) — the 2.8.1 parity fix: the
   scan strips those regions before fingerprinting, so serving must compare
   against the same block set (a cookie-banner `<p>` must never break a
   match). Offsets stay true to the full buffer; chrome is never edited.
2. Candidates = heading blocks whose normalized visible text + level match.
   For each candidate, its section = the following `<p>` blocks up to the next
   heading block; VERIFY fingerprint. Exactly one verified candidate → apply
   there; several (identical twin sections) → occurrence picks; none → apply
   NOTHING for that rule + stale count. Chrome-duplicate headings therefore
   can never cause a wrong swap.
3. Apply as per-block ops (builder wrapper divs are NEVER touched):
   replacement blocks map 1:1 onto original blocks (`[heading, p1..pn]`) —
   same tag → keep the original block's attributes, swap inner (styling
   survives); different tag → whole-block swap; surplus NEW blocks ride as
   siblings after the last mapped block; surplus ORIGINAL blocks are removed
   whole. Non-block content between blocks (images, divs, shortcodes output)
   is untouched by construction.
4. `sectionInsert`: locate the anchor section the same way (NO fingerprint
   gate — inserts key on the heading only). `before` = at the anchor heading
   block; `after` = before the NEXT section's heading block when one exists
   (top-level placement, outside the anchor's builder wrappers) — only the
   page's LAST section falls back to after-its-last-block. Anchor missing →
   nothing inserted + stale.
5. Order inside the serving callback: section replaces → section inserts →
   v1 paragraph rules. Same try/catch fail-to-original, same kill switch,
   same purge + stats pipeline, same `?pcm_cscan` exclusion.
5b. **Buffer order (AMENDED 2.8.2 — proven root cause):** the rules buffer
   hooks at priority 0 (OUTER), so its callback runs AFTER the heading-
   override layer (priority 1). Rules therefore match the OVERRIDE-
   TRANSFORMED page — the same page the scan inventories (`?pcm_cscan`
   disables rules only, never overrides) and the same page the user sees.
   Proven on a live case: a section whose heading had a render-time override
   could never match at the old priority (identity = displayed text, raw
   buffer = old text). Consequence on the hub: a heading edit through
   EITHER layer (source write or override) re-keys the section rules
   anchored to it.
6. Reference implementation lives in `PCM_Text_Matcher`
   (`fingerprint`/`parse_blocks`/`apply_section_rule`/`apply_section_insert`),
   fixture-tested; the connector's single-file mirror MUST stay
   behavior-identical (same sync contract as v1).

**Hub storage (NO DB bump — seo_dynamic_rules fits):** `target`
'section'/'sectionInsert'; `matchText` = normalized (anchor) heading text;
`occurrence` = headingOccurrence; `replacement` = section block HTML;
`anchorContext` JSON = `{level, fingerprint, paragraphs:[{text,occurrence}]}`
(replace — paragraphs kept for the absorb rule + stale UI) or
`{level, position}` (insert).

**Interaction laws (no mixed states, ever):**
- **Absorb:** saving a section rule DELETES the hub's paragraph rules whose
  matchText+occurrence fall inside that section (the UI folds their served
  text into the section's initial state first — nothing lost, and a paragraph
  rule can never fight a section rule over the same block).
- **Re-key on heading edit:** a successful remote heading source-edit
  re-keys any section/sectionInsert rules matched on that heading (matchText,
  occurrence, level) and re-pushes — push-fail rolls the re-key back. A
  heading fix must never strand its section rule stale.
- **Clean revert:** a section replacement whose parsed heading + paragraph
  texts normalize back to the original identity (heading unchanged AND
  fingerprint identical) deletes the rule. An insert saved with an empty
  replacement deletes the insert.
- Fingerprint covers paragraph texts ONLY (not the heading), so a heading
  re-key never invalidates the section body match.

**Documented limitations (honest, never silent):** removed `<p>`s can leave
empty builder wrappers (possible spacing); count-changing rewrites on
multi-column builder sections re-flow into the surviving blocks; inserted
sections inherit the container they land in. The authenticated preview modal
shows the served truth immediately.

## Cleanup contracts v3 (FROZEN 2026-07-09 — one brain, dumb frozen connector)

Companion: `docs/CLEANUP-GAP-ANALYSIS-20260709.md`. Target connector: 3.0.0
(four dumb jobs, then frozen). Everything below is the frozen law for steps
C1–C5; hub keeps pre-3.0 fallbacks until the fleet reads 3.0.0, then drops
them in one gated follow-up commit.

### Snapshot endpoint v1 (connector 3.0.0 — replaces BOTH scanners' parsing)

`GET /pcm-conn/v1/snapshot?post_id=N` → `{ html, tier }`.

- **Definition: the snapshot is the page as RULES-INPUT** — the exact HTML the
  rules layer would receive at serve time (rule serving disabled on the
  snapshot request via `?pcm_snap`, same exclusion law as the old scan params).
  Today (overrides still on-site) that is raw + override layer; after a site's
  C4 migration it is the raw render. The definition survives the migration
  unchanged because it names the LAYER, not the content.
- Tiers preserved verbatim from scan-content v1 (they are WAF/host survival,
  not parsing): `rendered` (hardened single-flight loopback, 8s, `<body>` on),
  `content-rendered` (in-process `the_content`), `content` (raw storage +
  `wpautop`), else `{ html:'', tier:'content', error:'loopback_blocked' }` —
  honest, HTTP 200.
- The connector does NOT parse. No node lists, no chrome stripping, no anchor
  computation — the hub does all of it (`PCM_SEO_Service::parse_page_snapshot`
  using the fixture-tested `PCM_Text_Matcher` family). Chrome stripping,
  paragraph/heading/link inventory, section membership, occurrences and
  fingerprints are computed hub-side FROM THIS ONE DOCUMENT — one parse, one
  identity source. `/scan-headings` and `/scan-content` are DELETED in 3.0.0
  (hub falls back to them only for pre-3.0 connectors).
- Snapshot response is cached connector-side under the same transient/busting
  law as scan-content (save_post + rules push bust it); TTL is config-driven
  (see config schema v1).

### Instruction stream v2.1 (ADDITIVE to rule schema v2)

- **`target:'heading'` is now served** (it was reserved since v1). Semantics =
  compiled legacy override, EXACTLY: `match:{text:<normalized ORIGINAL text>,
  occurrence:0}` (heading rules apply to EVERY match — override semantics,
  occurrence carried but ignored), `section:{level:<original level>,
  newLevel:<target level>}`, `replacement` = the new heading TEXT (plain;
  serving emits `esc_html(replacement)` inside `<h{newLevel}>`, preserving the
  original block's attributes — byte-identical output to the old override
  buffer).
- **Scope:** rules now carry `scope:'post'|'site'` (absent = 'post', fully
  backward-compatible). Site-scope rules are pushed with `postId: 0`, stored
  connector-side in `pcm_conn_rules_site`, and served on EVERY front-end
  render (not just singular) — the old override layer's reach. Only 'heading'
  targets may be site-scope (a site-wide paragraph/section rule is undefined
  and rejected).
- **Order inside the ONE serving pass:** heading rules FIRST (site-scope, then
  post-scope), then section replaces → section inserts → paragraph rules.
  Rationale (5b's successor): section/paragraph identities are computed by the
  hub on the DISPLAY state (headings as instructed), so serving must transform
  headings before matching sections. One buffer, priority 0; the prio-1
  override buffer becomes inert per-site after C4 (empty list early-return)
  and its code is deleted in the C5 follow-up.
- Capability: connector 3.0.0 answers `schemaVersion: 3` on GET /rules. Hub
  pushes v3 only when the set contains heading targets or site scope;
  section-only sets keep v2; paragraph-only sets keep v1 (each older shape
  byte-identical — zero regression on an un-updated fleet).
- Hub storage: heading rules live in `seo_dynamic_rules` like every rule
  (`target` 'heading', `matchText` = normalized original, `occurrence` 0,
  `replacement` = new text, `anchorContext` JSON `{level, newLevel,
  scope:'site'}`, `postId` 0 for site scope). NO DB bump.
- **Display-state law (one brain):** the hub's snapshot parse applies the
  site's ACTIVE heading rules to the parsed inventory in memory before
  computing section membership/fingerprints and before returning rows to the
  UI (heading rows show current text, tagged source 'override' exactly as the
  old scan-side collapse did). The connector never reports display state; it
  only serves it.

### Config schema v1 (connector 3.0.0 — hub-pushed tunables, D4 dies)

`GET|POST /pcm-conn/v1/config` (same auth as every route).

- POST body (partial updates merge): `{ snapshotCacheTtl: sec,
  loopbackTimeout: sec, loopbackLockTtl: sec, chromeRegions: [tag,…],
  statsThrottle: sec, overridesCap: n }` → stored in option
  `pcm_conn_config`; every consumer reads via `pcm_conn_cfg(key)` = stored
  value else CODE DEFAULT (today's literals become the defaults — a connector
  that never received config behaves byte-identically).
- GET returns `{ config: <effective>, overrides: [...],
  version: PCM_CONN_VERSION }` — `overrides` EXPOSES the legacy
  `pcm_conn_heading_overrides` list (the C4 migration's read path; today
  nothing can read it).
- POST also accepts `{ clearOverrides: true }` — executed ONLY by the C4
  migration after per-site verification; an empty overrides list makes the
  legacy buffer a no-op by its existing early-return.
- The hub is the single editor of config values (hub option
  `pcm_seo_connector_config`, seeded with the same defaults); pushes ride the
  existing site channel pattern.

### Handle-resolution law (why the builder walkers survive in a dumb connector)

Storage writers (`/replace-heading`, `/replace-url`, `/replace-anchor`, meta)
are job 2 and KEEP their builder-walking code (Elementor/Bricks/…/Brizy
handlers) — reading/writing builder STORAGE is on-site intelligence by nature
and was never mirrored hub-side. What dies is only their REST scanning surface
(`/scan-headings`, `/scan-content`) and every rendered-HTML parse on the
connector. Edit handles (`elId`/`textKey`/`tagKey`/`sourcePostId`) that the
heading scan used to provide are resolved AT EDIT TIME by the writers' own
text/elId search (the exact matching they already perform to apply an edit);
the hub's inventory supplies `{text, level, occurrence}` identity only.

### Deletions ledger (C3 — nothing else changes behavior)

1. Connector: `/scan-headings`, `/scan-content` routes + `pcm_conn_parse_paragraph_nodes`.
2. Hub `PCM_Text_Matcher`: `replace_block`, `apply_section_rule`,
   `apply_section_insert`, `locate_section`, `section_body_indices` — serving
   logic, connector-only from 3.0.0. The PARSING/identity primitives
   (`normalize`, `visible_text`, `fingerprint`, `parse_blocks`,
   `chrome_spans`, `content_blocks`, `parse_replacement_units`,
   `occurrence_of`) STAY hub-side — the hub parses snapshots and computes
   identity; the connector's serving copies of the primitives are enforced
   behavior-identical by the COMMITTED extraction harness
   (`tests/standalone/`), which runs the connector's REAL extracted source
   against the hub fixtures. The hand-maintained "sync contract" is replaced
   by machine enforcement.
3. Hub seohub: the fossil v1.0.1 `connector_php()` template — the per-tenant
   download route serves the REAL connector artifact instead.
4. After C4 per-site verification + C5 fleet convergence (owner-gated
   follow-up): the connector's override buffer + `/override-heading` route,
   the hub's pre-3.0 fallback paths (`remote_get_headings` scan/raw-parse
   fallbacks, `remote_get_content_nodes`, schema v1/v2 push shapes), and
   `remote_apply_heading_override` (heading edits then route via heading
   instructions).

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
