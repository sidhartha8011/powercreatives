# Consolidated Implementation Plan — Autonomy Machine (2026-07-08)

Consolidates every agreed-but-unbuilt change from the 2026-07-07/08 sessions plus
the owner's follow-up notes, into one dependency-ordered plan. Companion to
`docs/DYNAMIC-OPTIMIZATION-ARCHITECTURE.md` (the mechanism detail) and
`docs/backlog.md` → "Autonomy roadmap" (the leverage ranking). Where those
documents and this one disagree, THIS file wins (it is newer and integrates the
versioning model).

Objective (owner-stated): the most scalable long-term architecture, proper
implementation, zero technical debt. Concretely: producer → envelope → adapter,
no cross-module hardcoding, no silent fallbacks, everything reversible by
construction, every new reviewable thing = one adapter registration.

---

## 1. Status audit — what is DONE vs NOT (verified against the repo 2026-07-08)

| Item | Status |
|---|---|
| Create-from-delivery (+ icons, PendingCreateContext, 4 consumers) | **BUILT** — UNVERIFIED |
| Approvals count column on delivery sub-rows | **BUILT** — UNVERIFIED |
| Deliveries table/Kanban feature set (07-07/08) | **BUILT** — UNVERIFIED (~15 commits) |
| Live verification pass | **NOT DONE — the gate for everything below** |
| Approval rails (staging table, hold toggle, envelope, adapter registry, Before/After card, apply-on-approve) | NOT built (no `seo_staged_changes`, no registry in approvals — grep-verified) |
| Per-change client comments (`changeId`) | NOT built |
| Dynamic optimization layer (connector rule engine) | NOT built (architecture doc only) |
| On/off switches + original/optimized/stale states | NOT built |
| Licensing heartbeat | NOT built |
| AI page optimizer | NOT built |
| **Site/changes versioning (NEW this session)** | NOT built — integrated below as **changesets** |
| SERP-check row action (new tab) | NOT built (tiny; re-confirm before building — was deferred once) |
| Delivery health fishbone | Parked (socket ≈ zero value until evaluators specced) |
| Email relay / comms-in-platform | ON HOLD (buy-vs-build open) |
| Articles ↔ Projects | Decision pending — column stays honest "—" |
| Copy `handleBrandSaved` apiFetch fix | Flagged, awaiting approval (1 line) |
| Typography cleanup | Opportunistic only |

## 2. The NEW piece: versioning — integrated with zero new subsystems

Owner's idea: every site has versions of its changes; the user can switch
between them; one version can be "the one out for approval"; changes are
trackable.

**Design decision (recommended): a version IS a changeset.** We do NOT build a
parallel versioning system — we make the staging model version-shaped from day
one, so versioning falls out of data we already agreed to store:

- New table `seo_changesets`: `id, siteId, label, status, createdAt, sentAt,
  resolvedAt` — status: `draft → pending_approval → approved → live → retired`.
  (DB bump, additive dbDelta, same migration ritual as always.)
- Every `seo_staged_changes` row carries `changesetId`. A changeset groups the
  edits made in one "hold" session; **the changeset is the unit that gets sent
  for approval, the unit the client sees as a card set, and the unit the
  version switch toggles.**
- **Site version timeline = original (v0) + each live changeset in order.** The
  "simple switch" the owner described is a per-site version selector: pick a
  point on that timeline → the connector activates exactly the rules belonging
  to changesets up to that point (or all off = original). It is the SAME
  mechanism as the already-agreed per-change/per-page/per-site switches — one
  more scope (per-changeset), not a new feature.
- "The version being for approval" = the changeset in `pending_approval` — it
  already has a name, a card set on the board, and an audit trail (before/after
  per row). Nothing extra to build.
- Change tracking = the staged rows themselves (before, after, who, when,
  applied/failed/rejected). Free.

**Honest limitation to design around (owner decision #4 below):** the two
delivery mechanisms have different revert semantics.
- *Dynamic rules* (paragraphs, rendered-only links): version switching is a
  true instant toggle — original is untouched in the source.
- *Source writes* (meta title/description/keywords/slug/schema/status): these
  are real writes. The before-value in the staged row makes revert POSSIBLE
  (re-write the before value), but it is a new write, not a toggle.

Recommended semantics, per the no-silent-fallback rule: the version switch
directly governs **dynamic rules only**; for changesets containing source
writes it shows an explicit "also revert N source-written fields" step that
performs (and records) the counter-writes. Never pretend a write is a toggle.

## 3. Architecture laws (locked — do not re-litigate per feature)

1. **Producer → envelope → adapter.** SEO (and later the AI optimizer) only
   emits `{type, tags:{siteId,projectId,deliveryId,brandId}, items}`. Approvals
   only consumes registered adapters `{type, toSnapshotItems, renderer,
   onItemApproved, appliable}`. Neither imports the other.
2. **Routing law:** meta fields = source write (existing `save_cell` /
   `remote_save_cell`); paragraphs + `editable:false` rendered-only links =
   dynamic rule; headings + reachable links KEEP the proven 2.2.x source path.
   Rule engine designed target-agnostic so migration later = routing change.
3. **No silent fallbacks:** failed rule match serves original + flags stale;
   unknown health = "Not tracked", never fake green; phantom remote saves
   surface as errors (already the codebase norm).
4. **Local-first serving:** rules live ON the connector; zero runtime
   dependency on the hub; licensing fails closed AND harmless (originals).
5. **Reversible by construction:** originals never move; before-values stored
   for audit/stale-detection/revert.

## 4. The phased plan (dependency order)

**GATE — Owner live verification pass** (before any new build lands on top):
table editing, sub-rows, dynamic lanes drag-writes, bulk actions,
connect/create project + image upload, create-from-delivery into each module,
quiet headers, compact Kanban, pill parity. Ctrl+F5 after builds.

**Phase 0 — Page-content rows in the SEO outline** (medium; connector bump)
- Heading scan → ordered content scan: `{index, kind:'heading'|'paragraph',
  level?, text, html, elId?}`; local parse in `PCM_SEO_Service` + connector
  route (version bump + reinstall ritual).
- Paragraph rows in HeadingsPanel (indented under their heading, standalone
  when orphaned); click → inline HTML popup (LinksPopup pattern). Read-only.
- The node identity `{kind, index, normalized text}` is the contract phases
  1–5 target.

**Phase 1 — Approval rails + changesets** (the 4–5/10 build, ~5 pairs)
- `seo_changesets` + `seo_staged_changes` (with `changesetId`) — DB bump.
- "Hold edits for approval" toggle; staged values shown pending in the table.
- "Send for approval" → envelope per the law; Approvals adapter registry;
  Before/After card (one card per page, one row per column, before gray /
  after black); reject = marked, kept.
- Apply-on-approve via existing `approvals.asset_approved` trigger (owner
  identity — verified approvals/service.php:1163). Apply handler routes per
  the routing law: meta → source write NOW; paragraph → dynamic rule (stub
  until phase 2 — record + mark "awaiting dynamic layer", never fake-apply).
- **Phase 1.5 — Per-change comments:** stable `changeId` per card row;
  comments optionally carry `assetId + changeId`. Small; the AI-revision data
  contract.

**Phase 2 — Connector rule engine** (the core new engineering)
- Rule: `{id, postId, target: paragraph|heading|anchorText|href, match:
  {text, occurrence}, replacement, active, changesetId, sourceChangeId}`.
- Full-response output buffer (`template_redirect` + `ob_start`, NOT
  `the_content`); text-tolerant matcher (normalize whitespace/entities/case);
  fail → original + stale counter; `pcm_conn_purge_caches` on every rule
  change; push via `POST /pcm-conn/v1/rules` (replaces the post's set).
  Connector version bump.

**Phase 3 — Switches, states, and the version selector**
- `active` at four scopes: per change / per changeset (= the version switch) /
  per page / per site (kill switch). Off = stop applying + purge.
- Three-state SEO-table indicator: original / optimized (N active) / STALE.
- Per-site version timeline UI (v0 = original + live changesets); source-write
  revert = the explicit counter-write step from §2.

**Phase 4 — Licensing** (small-medium)
- Daily wp-cron heartbeat, local last-success, ~14d grace → rules
  auto-deactivate to originals; explicit revoke = immediate; hub-side
  license/revoke surface per site.

**Phase 5 — AI page optimizer** (second producer)
- Bulk "Generate optimized page content": page + top-3 SERP + gap/interlink
  analysis → per-paragraph/heading rewrites → `contentRevision` envelope onto
  the same board; send-time card granularity choice; review-only until 2 ships;
  new interlinks ride paragraph rewrites. Thresholds/prompts specced with PO.

**Slot into gaps (tiny / independent):**
- SERP-check row action (new tab; re-confirm first).
- Copy `handleBrandSaved` apiFetch one-liner (awaiting approval).

**Parked (no go yet):** delivery health evaluators (re-rank when first
evaluator is specced), email relay (buy-vs-build first), Articles ↔ Projects
(decision), typography (opportunistic).

## 5. Open owner decisions (needed before the phase named)

1. **Stale-rule expiry** (before phase 3): auto-expire stale rules after N days
   vs. flag forever. *Recommendation: flag forever, no auto-expiry — silent
   expiry is a silent fallback.*
2. **Push timing** (before phase 2): rules push on approval automatically
   (agreed default) vs. explicit per-site "publish rules" step. *Note: if
   versioning should feel like "switch when I say", explicit publish aligns
   better; auto-push still fits since the version switch can deactivate.*
3. **Grace window / heartbeat** (before phase 4): 14 days / daily proposed.
4. **NEW — Version-switch semantics for source writes** (before phase 1 schema
   freeze): recommended = switch toggles dynamic rules only + explicit
   recorded counter-write step for meta fields. Alternative = symmetric
   auto-revert (rejected as too magical).
5. **NEW — Changeset granularity** (before phase 1): per-site changesets with
   page-scoped rows inside (recommended — page filtering is free) vs.
   per-page changesets.
