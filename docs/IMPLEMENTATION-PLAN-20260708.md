# Implementation Plan — Autonomy Machine, 3 Phases (2026-07-08, rev 3)

> **THIS BUILD (owner-locked 2026-07-08): Phase 1 only — dynamic paragraph
> optimization + dynamic approval. NOT the AI optimizer, NOT SERP, NOT
> licensing, NOT the version-selector UI. Nothing starts without the owner's
> explicit go PER PAIR. See "THIS BUILD — pair plan" below.**

---

## THIS BUILD — pair plan (senior-dev, fact-anchored)

Every fact below is code-verified (file:line), not assumed. Every pair follows
the commit ritual: pull → BEFORE commit (rollback point) → build → verify →
AFTER commit `- UNVERIFIED` → session log → push → owner verifies.

**Pair 1 — Paragraph rows in the outline (read-only).**
- Local: new pure `parse_content_nodes()` beside `parse_heading_details()`
  (seo/service.php:496) → ordered `{index, kind:'heading'|'paragraph', level?,
  text, html}`; new route `GET /seo/content/{id}/content-nodes` + trpc map
  entry. Node identity CONTRACT frozen in this pair (documented in the
  architecture doc).
- Remote: NO connector change yet — reuse the existing fallback pattern
  (`remote_get_headings` fallback parses `content.raw` via context=edit,
  service.php:2379) so pair 1 carries zero connector risk.
- UI: paragraph rows in HeadingsPanel (same `<tr>`/colgroup mechanics,
  paddingLeft indent math at HeadingsPanel.tsx:209, TAG_STYLE-like "P" chip);
  click → HTML popup (Dialog, LinksPopup pattern). Read-only.

**Pair 2 — Rule engine (connector) + rules store (hub). ONE connector bump.**
Owner-approved 2026-07-08: HIGHEST-STANDARD upgrades baked in — (A) the
matcher applies via WordPress core's streaming HTML parser, (B) rules carry
re-anchor context so stale rules get ASSISTED one-click healing in pair 4
(deterministic serving + assisted healing; never fuzzy auto-matching).

**2a. Contracts FIRST (docs before code, architecture doc):**
- Rule schema v1: `{ schemaVersion:1, postId, rules:[{ id, target:
  'paragraph'|'heading'|'anchorText'|'href', match:{ text:<normalized>,
  occurrence }, replacement, active, changesetId?, sourceChangeId?,
  anchor:{ parentHeading?, prevText? } }] }` — `anchor` = the re-anchor
  context for pair 4's assisted healing.
- Normalization spec v1 (the matcher's law): decode HTML entities → map NBSP
  to space → collapse all Unicode whitespace runs to one space → trim →
  case-fold. Byte-identical implementation hub + connector.
- scan-content contract: node shape identical to the frozen node-identity
  contract v1 (pair 1) + `source: 'content'|'rendered'`.

**2b. Hub side:**
- `PCM_Text_Matcher`: pure, WP-free class (normalize + occurrence-aware block
  match); fixture corpus from REAL builder HTML (Gutenberg/classic/Elementor/
  Brizy pages) — tests written now, runnable where composer/CI exists.
- `seo_dynamic_rules` indexed table (siteId, postId, target, matchText,
  occurrence, replacement, anchorContext, active, changesetId NULLABLE from
  day one, staleCount, timestamps) — DB bump (`PCM_DB_VERSION` 1.37.0).
- Push path in `PCM_Sites_Service`: capability check FIRST (GET
  /pcm-conn/v1/rules; 404 → honest "connector too old, update via the Sites
  Connector column" error), then POST replaces the post's rule set.
- Remote content-nodes endpoint (`GET /seo/sites/{id}/content/{post}/
  content-nodes`) proxying the connector's scan-content; HeadingsPanel's
  remote path switches to it WHEN the connector answers (older connector →
  current headings-only behavior + honest note, never a fake).

**2c. Connector (ONE version bump, 2.6.3 → 2.7.0, bundles everything):**
- `GET /pcm-conn/v1/scan-content?post_id=` — ordered heading+paragraph nodes:
  parse `content.raw` with the SAME parser rules as local pair 1 when raw is
  non-empty; builder pages (empty raw) → hardened-loopback rendered parse
  (pattern from seohub/service.php:1620), `<body>` only, `<header>/<nav>/
  <footer>/<aside>` regions stripped (deterministic, documented), nodes
  flagged `source:'rendered'`.
- Rule storage per post: `pcm_conn_rules_{postId}` options (autoload OFF —
  render loads only the current post's rules) + `pcm_conn_rules_index` (posts
  with rules; drives site-wide purge/kill-switch iteration). NOT the 200-cap
  overrides list — that stays heading-override-only, untouched.
- **Serving (the high-grade core):** `template_redirect` + `ob_start` prio 1
  (proven pattern :1752), singular requests only, skip admin/feed/REST.
  Application walks the buffered HTML with **`WP_HTML_Tag_Processor` (WP core
  6.2+, maintained by WordPress, built for malformed builder HTML)** — find
  target blocks, compare their VISIBLE text via the normalization spec,
  occurrence-aware, swap inner content (`wp_kses_post`-sanitized replacement).
  Regex fallback path ONLY when the core class is absent (WP < 6.2), flagged
  in the rule status so it's never silent. ENTIRE callback wrapped in
  try/catch — ANY throwable returns the ORIGINAL buffer untouched + error
  counter (a rule can never white-screen a client site).
- Miss → serve original + `staleCount++` on that post's rules (read back by
  pair 4's indicator). Every rule write purges via `pcm_conn_purge_caches`.
- `POST /pcm-conn/v1/rules` validates `schemaVersion === 1` (reject others
  honestly); GET returns rules + serve/miss counters.

**Pair-2 build checklist (in order, owner-approval gate before start):**
1. `git fetch && git pull --ff-only` → BEFORE commit → push.
2. Write 2a contracts into the architecture doc (commit as DOCS).
3. `PCM_Text_Matcher` + normalization impl + fixtures.
4. DB bump: `seo_dynamic_rules` (additive dbDelta, PCM_DB_VERSION 1.37.0).
5. Connector template: scan-content + rule store + buffer application +
   rules routes + version 2.7.0 (artifact regenerates automatically).
6. Hub: capability check + push path + remote content-nodes proxy + trpc map
   + HeadingsPanel remote switch.
7. Verify: `php -l` every touched file; tsc = 59 baseline ZERO new; build.
8. Roll connectors: self-update via the Sites Connector column (sites whose
   self-update is broken — e.g. powerstock.local — need the documented
   one-time manual reinstall).
9. AFTER commit `- UNVERIFIED` + session log + changelog + push.
10. Owner verification: (a) remote site shows paragraph rows in the outline,
    (b) connector column reads 2.7.0, (c) one hand-pushed test rule serves a
    swapped paragraph on the live page, original restored when the rule is
    removed, (d) a deliberately-wrong rule serves the ORIGINAL (fail-safe).

> NOTE: paragraph rows are READ-ONLY through pair 2 BY DESIGN — pair 2 builds
> the engine that edits need to write to. Pair 3 (next, small) adds
> click-to-edit + ✦ AI-optimize on the paragraph rows.

**Pair 2.5 — Scan resilience (connector 2.7.1) — root-cause fix for loopback.**
Owner-approved diagnosis: loopback-per-request is the design weakness (LocalWP
worker starvation; WAF-blocked hosts). Fix at the SOURCE:
- **Scan cache:** connector caches the scan-content result per post (transient,
  ~10 min), busted on `save_post` and on every rules POST/purge — repeat
  outline-opens cost zero loopbacks.
- **Single-flight lock:** max ONE loopback at a time site-wide (transient
  lock); a locked request skips straight to the fallback tiers. Protects
  against ANY concurrency (two tabs, two users), not just our panel. The
  heading scan's rendered pass respects the same lock (its skip path already
  exists and is unchanged behavior).
- **Honest fallback tiers when loopback fails/locked:** ① in-process render
  via WP's `the_content` filter (catches Divi/WPBakery/shortcode builders, no
  HTTP), flagged `source:'content-rendered'` → ② raw parse (wpautop path,
  plain WP/Gutenberg — the powerstock case), flagged `source:'content'`.
  Every tier flagged — never silent.
- **Loopback timeout 20s → 8s** (fast honest degradation; cache+fallbacks make
  slow waiting wrong).
- **Hub panel serializes:** content-nodes query starts only after the headings
  query resolves (belt and braces).
Checklist: BEFORE → connector template edits (cache/lock/tiers/timeout, bump
2.7.1) → template-extract lint (the NEW mandatory step: lint the GENERATED
connector source, not just the hub file) → panel serialization → php -l/tsc/
build → AFTER + logs + push → owner reinstalls/updates connectors.

**Pair 3 — Edit + AI-optimize paragraphs from the table (REMOTE sites).**
Scope law: paragraphs are DYNAMIC-only (routing law) — editing works on
connected sites (the rules engine lives on the connector). The LOCAL tab's
paragraph rows stay read-only with an honest tooltip (no engine on the hub's
own site — no fake path).
- **UI (HeadingsPanel, remote paragraph rows):** click-to-edit text (mirrors
  HeadingText) + hover ✦ AI-optimize with the SAME staged Accept/Reject UI.
  Accept (manual or AI) → rule saved + pushed → toast confirms it's serving.
- **Display truth:** the scan shows ORIGINAL text by design (rules match
  originals), so the panel overlays the hub's rule set: a paragraph with an
  active rule shows the SERVED text + a minimal "optimized" mark (no bloat —
  small dot, tooltip carries original). New read endpoint for the overlay.
- **Hub endpoints:** `POST /seo/sites/{id}/content/{post}/paragraph-rule`
  (UPSERT by siteId+postId+matchText+occurrence: edit-again updates the same
  rule, never piles up; replacement identical to original → rule DELETED =
  clean revert, mirroring the shipped override behavior) → writes
  `seo_dynamic_rules` → `push_rules()` (capability-checked). `GET
  /seo/sites/{id}/content/{post}/rules` (hub DB, for the overlay). `POST
  …/paragraph-optimize` (AI suggestion, NOT saved — staged).
- **Template law:** new `paragraph` prompt section in prompts.php
  (optimize + generate) → auto-seeded as user-editable Templates via the
  existing seeding — user controls the prompt from day one.
- **Identity:** matchText normalized SERVER-side (PCM_Text_Matcher::normalize)
  from the scan node's text; occurrence from the node; anchor → anchorContext.
Checklist: BEFORE → prompts section → endpoints + service methods → trpc map
entries → panel edit/optimize/overlay → template-extract lint + php -l + tsc
(59 baseline) + build → AFTER + session log + changelog + push → owner
verifies: edit a paragraph on powerstock → live page serves it → edit back to
original → rule gone, original serves.

**Pair 3.5 — SECTION EDITOR (specced 2026-07-09, AWAITING GO).** Supersedes
the "paragraph UX redesign" discussion: every header owns its section; floating
draggable non-blocking modal presents/edits the whole section; section replace
+ section insert (new FAQ etc.) via rule schema v2 + connector 2.8.0. Full
fact-anchored plan + checklist: `docs/SECTION-EDITOR-PLAN-20260709.md`.

**Pair 4 — Switches + serving indicator.**
- Active toggles: per change / per page / per site (hub UI writes, push
  replaces the set, purge). Three-state row indicator original/optimized(N)/
  STALE — stale read on outline expand via `GET /pcm-conn/v1/rules` status
  (no cron; on-demand, honest).

**Pair 5 — Dynamic approval.**
- DB bump: `seo_changesets` + `seo_staged_changes` (backlog.md:49 shape;
  reject = MARKED). Hold-toggle on paragraph edits → "Send for approval" →
  envelope v1 (CONTRACT frozen: type/version/tags/items) → NEW adapter
  registry in Approvals (registry pattern = automations service.php:102) →
  Before/After card on the share board (approval_sets.snapshot longtext,
  schema:561; per-asset anchors exist, approvals/service.php:722) →
  apply-on-approve via `approvals.asset_approved` (fires :1163-1179 under set
  owner) → activate + push rules. Legacy three bucket types get wrapped as
  adapters in a SCHEDULED follow-up pair (dated debt).

**Definition-of-Done checklist (every pair, no exceptions):**
1. `git fetch && git pull --ff-only` (two machines).
2. BEFORE commit (rollback point, `--allow-empty` on clean tree).
3. Contract doc updated FIRST if the pair freezes/changes an interface.
4. `php -l` every touched PHP file (lightning-services php 8.2).
5. `cd app && npm run check` — baseline 56 errors, ZERO new.
6. `npm run build` — remind owner: Ctrl+F5 (fixed bundle filenames).
7. Matcher changes: fixtures updated + passing (runnable machine/CI).
8. No silent fallbacks introduced; every failure path surfaces honestly.
9. AFTER commit `- UNVERIFIED` + SESSION_LOG entry (+ changelog if
   substantial) + push `origin feat/seo-suite-port`.
10. Owner live-verifies before the next pair starts.

---

Owner-approved consolidation of every agreed-but-unbuilt change. Companion to
`docs/DYNAMIC-OPTIMIZATION-ARCHITECTURE.md` (mechanism detail) and
`docs/backlog.md` → "Autonomy roadmap". Where documents disagree, THIS file
wins (newest; integrates the merged feature list + 3-phase packing).

Objective: the most scalable long-term architecture, zero technical debt.
Laws: producer → envelope → adapter (no cross-module hardcoding) · routing law
(meta = source write, paragraphs + rendered-only links = dynamic, headings +
reachable links keep the proven 2.2.x source path) · no silent fallbacks ·
local-first serving · reversible by construction.

**GATE — CLEARED by owner 2026-07-08:** the 07-07/08 work is declared working
in practice; the `- UNVERIFIED` commit labels are historical, not blocking.

**SCOPE CUT by owner 2026-07-08:** everything SERP is OUT of this build — no
SERP API purchase, AI optimizer v1 runs WITHOUT competitor data (page +
structure/interlink analysis only), and the Google-results row action is
dropped from this build.

---

## The features (owner-ranked by agency value, canonical wording)

1. **Paragraphs become first-class, editable objects in the SEO table** — the
   way headings already are.
   - *See it:* expand a page in the SEO outline and, alongside the H1–H6 rows,
     every paragraph appears as its own row in natural document order —
     indented under its heading, or standalone if it has no heading. Click a
     row → inline popup showing that paragraph's actual HTML.
   - *Change it:* paragraph edits (eventually AI-drafted rewrites) don't touch
     the page's source at all. They're served as dynamic rules by the
     connector at render time — the builder (Elementor, Brizy, whatever)
     outputs its HTML, and our layer swaps the old paragraph for the approved
     new one just before it's sent. One mechanism for every builder, instantly
     reversible, and visible to Google and AI crawlers (which don't run
     JavaScript — that's why it's server-side).
   - *Control it:* each change goes through the client approval board
     (before/after per page), has an on/off switch, and if the client later
     edits the original text so our rule no longer matches, we serve the
     original and flag it — never guess.
   - *Why paragraphs specifically:* meta fields and headings already have
     working edit paths; paragraphs are the one content type with no reliable
     solution today, because they live inside per-builder storage formats.
     The render-time approach sidesteps that entirely.
   - *Bonus:* the row inventory is the exact unit the future AI optimizer will
     rewrite — building the display is simultaneously building the optimizer's
     data contract.
2. **Client approval workflow** — stage changes, client gets Before/After
   cards on their share link, approved changes execute themselves with a full
   record; deletes the "client said yes → someone implements it" handover.
3. **AI full-page optimizer** — one button: AI reads the page plus its top
   Google competitors and drafts improved paragraphs/headings straight onto
   the client's approval board; deletes the hours an SEO specialist bills for.
4. **Off switches for everything** — turn any optimization off per change,
   page, or site with one click, with an honest original/optimized/stale
   indicator; the trust feature that lets an agency safely run client sites.
5. **Site version history and rollback** — every batch of approved changes
   becomes a version you can view, compare, and switch back from.
6. **Client comments pinned to a specific change** — "not this one" lands on
   the exact paragraph/title; later feeds the AI revision loop directly.
7. **Protection if a client cancels** — the site serves optimizations
   independently of the platform but checks a license daily and quietly
   reverts to originals if the subscription ends.
8. **Google results button** — row action opens the live Google search for
   the page's primary keyword in a new tab.
9. **Copy module bug fix** — one-line repair of the dormant broken server
   call in Copy's brand-save path.

---

## PHASE 1 — The Paragraph Engine (feature 1 + 4's foundations)

Ships the moat feature end-to-end for operators: see paragraphs, change them
dynamically, switch them off. Builds the rule engine that Phases 2 and 3
consume — nothing here is throwaway.

**1a. Content inventory (display).**
- Extend the heading scan into a content scan returning ordered nodes
  `{index, kind:'heading'|'paragraph', level?, text, html, elId?}` — local
  parse in `PCM_SEO_Service`, connector sibling route for remote sites.
- Paragraph rows in HeadingsPanel: natural document order, indented under
  their heading, standalone when orphaned; click → inline HTML popup
  (LinksPopup pattern). Read-only at this step.
- Node identity `{kind, index, normalized text}` frozen here — it is the
  contract rules (1c), approvals (Phase 2), and the optimizer (Phase 3) target.

**1b. Connector rule engine (render-time serving).**
- Rule: `{id, postId, target: paragraph|heading|anchorText|href, match:{text,
  occurrence}, replacement, active, changesetId, sourceChangeId}` — stored ON
  the connector (local-first).
- Full-response output buffer (`template_redirect` + `ob_start`, NOT
  `the_content` — some builders bypass it); text-tolerant matcher (normalize
  whitespace/entities/case, match text content not markup).
- Failed match → serve original + mark stale (counter, reported on heartbeat
  later). Every rule change fires `pcm_conn_purge_caches`.
- Push: hub → `POST /pcm-conn/v1/rules` (app-password auth, replaces the
  post's rule set).
- Engine is target-agnostic by design: headings/links can migrate later as a
  pure routing change; `editable:false` rendered-only links are the first
  legitimate extra consumer.

**1c. Edit + optimize paragraphs from the table, with switches.**
- Click-to-edit + per-row ✦ AI optimize on paragraph rows (same staged
  accept/reject UI as every other cell); accepting creates/updates a dynamic
  rule and pushes it.
- On/off switches at three scopes (per change / per page / per site kill
  switch) + the three-state SEO-table indicator: original / optimized (N
  active) / STALE. Built NOW, not retrofitted — reversibility is part of the
  feature's definition.
- DB: rules/changes rows carry `changesetId` from day one (nullable until
  Phase 2 populates it) — the version model is in the schema before any data
  exists, so Phase 2 is additive, never a migration of meaning.

**Connector impact:** one version bump covering 1a's scan route + 1b's engine
(bundle deliberately — every bump = reinstall ritual on connected sites).
**DB impact:** one `PCM_DB_VERSION` bump (rules/staging-precursor tables).

## PHASE 2 — The Approval & Version Machine (features 2 + 5 + 6)

Wraps governance around the engine: client-gated deployment, versions,
per-change feedback. This is the autonomy handover-deleter.

**2a. Changesets + staging rails.**
- `seo_changesets` (`id, siteId, label, status: draft → pending_approval →
  approved → live → retired`) + `seo_staged_changes` (site, page, column/node,
  before, after, status, changesetId). Reject = MARKED, never discarded.
- "Hold edits for approval" toggle on the SEO table: edits record instead of
  apply; staged values shown pending.
- "Send for approval" → generic envelope `{type:'seoChanges',
  tags:{siteId,projectId,deliveryId,brandId}, items per page}`.
- Approvals gains the asset-type ADAPTER REGISTRY (`{type, toSnapshotItems,
  renderer, onItemApproved, appliable}`) — no hardcoding either direction.
- Before/After card: one card per page, one row per change, before gray /
  after black.
- Apply-on-approve via the existing `approvals.asset_approved` trigger (owner
  identity — verified approvals/service.php:1163), routed per the routing
  law: meta → existing source writes; paragraph → dynamic rule via Phase 1's
  engine (already live, so nothing is stubbed).

**2b. Version history + rollback switch.**
- A version IS a changeset — no parallel subsystem. Site timeline = original
  (v0) + each live changeset; the version selector activates exactly the
  rules belonging to changesets up to the chosen point (per-changeset scope on
  Phase 1's switch mechanism).
- Source-write semantics (honest split): the switch toggles dynamic rules
  directly; changesets containing meta writes surface an explicit "also
  revert N source-written fields" step performing recorded counter-writes.
  Never pretend a write is a toggle.

**2c. Per-change client comments.**
- Stable `changeId` per card row; comments optionally carry
  `assetId + changeId` → feedback machine-mapped to the exact change. The AI
  revision loop's data contract.

**DB impact:** one bump (changesets + staged changes + comment columns).
**Connector impact:** none expected (engine + push shipped in Phase 1).

## PHASE 3 — Autonomy at Scale (features 3 + 7)

The labor-deleter and the revenue protector — both pure consumers of Phases
1–2's sockets.

**3a. AI page optimizer (second producer).**
- Bulk action "Generate optimized page content": AI reads the page (Phase 1's
  node inventory), top-3 SERP competitors, structure/interlink gaps →
  per-paragraph + per-heading before/after rewrites.
- Emits a `contentRevision` envelope through the SAME adapter pipeline; send-
  time choice of one card per page or per change; new interlinks ride
  paragraph rewrites (an "after" paragraph containing the anchor).
- Auto-apply on approval via the rule engine from day one (Phase 1 exists) —
  the "review-only first" constraint from earlier planning dissolves because
  the apply path ships before the optimizer.
- Thresholds/prompts specced with the PO — never guessed.

**3b. Licensing heartbeat.**
- Daily wp-cron heartbeat from connector → hub; last-success stored locally;
  grace window (~14d, PO decision) then rules auto-deactivate to originals
  (fail-closed AND harmless); explicit revoke = immediate; hub-side
  license/revoke surface per site. Stale counters ride the heartbeat.

**Connector impact:** one bump (heartbeat + license state).

## Slots (tiny, drop into any phase gap — each needs its own go)

- **Google results button** (feature 8): row action, new tab (Google can't be
  iframed). Minutes.
- **Copy `handleBrandSaved` fix** (feature 9): 1-line apiFetch swap. Flagged,
  awaiting approval.

## Parked (explicitly out of this plan)

Delivery health evaluators · email relay (buy-vs-build first) · Articles ↔
Projects (a decision, not a build) · typography (opportunistic only).

## Zero-debt engineering additions (senior-dev requirements, 2026-07-08)

1. **Contracts frozen before code, versioned:** node identity (`kind + index +
   normalized text`), envelope (carries `version`), rule schema (connector
   capability-check before push — "do you accept rule schema v1?"). Contract
   changes = version bump, documented in this repo.
2. **The matcher is a pure, WP-free PHP class** with a written normalization
   spec (whitespace/NBSP/entities/case) and a fixture corpus of REAL builder
   HTML (Elementor/Brizy/Divi pages) as unit tests.
3. **No parallel paths:** new asset types go through the adapter registry; the
   three legacy approvals types (visual/copy/article buckets) get WRAPPED as
   adapters over their existing data in a SCHEDULED follow-up pair — one render
   path, debt with a date.
4. **Touched debt is cleaned in-stream, never swept:** the 3 hardcoded prompts
   → templates (Brand & Template workstream); rule storage = a proper indexed
   table (the 200-cap options list must NOT be extended); Copy's
   `handleBrandSaved` 1-liner when approved.
5. **Definition of Done per pair:** `php -l` + `npm run check` (56-error
   baseline, zero new) + build + session log + BEFORE/AFTER commits + contract
   doc updated if an interface changed + owner verification.

## Brand & Template Foundation (cross-cutting workstream, lands before/with 1c)

1. Template law: every LLM call resolves through a user-editable Template;
   migrate the 3 hardcoded prompts (llm-info builder, remote site-desc,
   AI-readiness page summary) into seeded templates.
2. `sites.brandId` (optional FK, DB bump) + brand picker in site settings
   (hierarchy as suggestion); local-site tab gets a brand picker too.
3. Unified `{{brand.*}}` variable namespace: every brands column (phone,
   niche, location, tonOfVoice, targetAudience, uniqueSellingPoints,
   businessSummary, language…) becomes a template variable, brand-row-first
   with GBP snapshot as overlay; variables VISIBLE in the Templates UI.
4. Auto brand resolution in every SEO generation (table cells, headings,
   paragraphs, bulk/column, remote — `remote_generate_field` gains brandId and
   `remote_field_vars` builds from the brand).

## Open PO decisions (mapped to the phase that needs them)

1. **Before Phase 1c:** stale-rule policy — auto-expire after N days vs. flag
   forever. *Recommendation: flag forever; silent expiry = silent fallback.*
2. **Before Phase 1c:** rules push immediately on accept (operator edits) —
   assumed yes; confirm.
3. **Before Phase 2a:** changeset granularity — per-site changesets with
   page-scoped rows (recommended) vs. per-page changesets.
4. **Before Phase 2a:** push timing under approvals — auto-push on client
   approval (agreed default) vs. explicit per-site "publish" step.
5. **Before Phase 2b:** version-switch semantics for source writes —
   recommended split above (toggle dynamic, explicit counter-write for meta).
6. **Before Phase 3b:** grace window length (14d proposed) + heartbeat
   frequency (daily proposed).
