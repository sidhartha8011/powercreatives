# Strategy Module — Remaining Work (Plan)

_Created 2026-07-08. Planning-only; no code changed. Grounded in the current code (post
model-selection / Generate-All / Retry), `docs/modules/strategy.md` (target architecture +
4-phase plan + AutoPress mapping), the roadmap doc, AutoPress `strategyService.ts`, and a survey
of PC infrastructure that already exists to build on._

## Where the module stands now

Working today: create a strategy from selected keywords + a Writer template + brand → one
`strategy_items` row per keyword → per-item AI generation into `pcm_articles` (draft). Recent
work added **selectable model/provider**, **Generate All** (client-side loop), **Retry a failed
item**, and **retry-safe counters**. The real item status machine is only
`pending → generating → completed | error`.

The docs envision far more (scheduling, publishing, approvals, hierarchy, a richer status machine,
a Workflows module). That orchestration layer is the remaining work.

## Key insight: most of it is wiring, not greenfield

PC already ships the hard infrastructure each remaining feature needs:

- **Publish path** — `POST /sites/{id}/publish` → `PCM_Sites_Service::publish_to_site($site, $article, $uid)`
  publishes an article to a connected WP site via WP REST + Application Passwords. `pcm_articles`
  already has `siteId / publishedUrl / publishedPostId / publishedAt`.
- **Approvals seam** — `SendToApprovalSetDialog` + `approvals.createSet` (+ public share link + Brevo
  email), and the `approvals.set_fully_approved` automation trigger already fires on full approval.
- **Cron + async** — `wp_schedule_event('daily')`, the `run_scheduled_action` async seam
  (`wp_schedule_single_event`), and `run_pending_client_scan()` as a ready template for a due-item scanner.

## Remaining workstreams

| # | Workstream | What's missing | Reuse (already in PC) | Effort | Deps |
|---|---|---|---|---|---|
| 1 | **Auto-publish to a site** | `publishingMode` stored but never acted on | `publish_to_site()` + `pcm_articles` publish columns | S–M | — |
| 2 | **Server-side queue/batch** | "Generate All" is a client loop (dies if tab closes); doc wants `strategy.processAll` | `run_scheduled_action` async seam | M | — |
| 3 | **Scheduling execution** | `frequency`/`startDate` stored, no due-date processor | daily cron + `run_pending_client_scan()` template | M–L | 1, 2 |
| 4 | **Approval gates** | `approvalMode` stored, not enforced; no review states | `approvals.createSet` + `set_fully_approved` trigger | L | 1 |
| 5 | **Hierarchy + interlink** | `hierarchyMode`/`interlinksConfig` stored, not acted on | SEO `scan-links`/`replace` plumbing (selection logic is new) | L | 2 |
| 6 | **Small gaps (quick wins)** | `updateItemStatus` route; dead "View" button → open in Writer; `consolidated` mode; AI topic suggestion; use dead `strategy_items.config` for per-item overrides | Writer module + existing routes | S each (topic-suggest M) | — |
| 7 | **Workflows module** _(decision, not a task)_ | Docs' Lego architecture wants a multi-step research→generate→enrich engine — **it doesn't exist** | strategy runs on Writer templates today | XL | — |

## Detail per workstream

- **1 · Auto-publish** — highest-value, lowest-risk. Add a site picker to the create dialog (store
  `siteId`); after generation (or on schedule) call the existing `publish_to_site()`, set the item to
  `published` with its URL. Delivers the "content machine" promise.
- **2 · Queue** — one-item-per-tick, self-rescheduling via `wp_schedule_single_event` (NOT one long
  job — avoids REST/PHP timeouts). Substrate for scheduling.
- **3 · Scheduling** — needs a new **`strategy_items.scheduledDate` column** (additive `dbDelta` +
  `PCM_DB_VERSION` bump; the cron scanner must query "items due ≤ now" on an indexed column, not JSON).
  Caveat: PC's cron is **daily**, so "every-other-day / time-of-day" needs a more frequent schedule or
  accepting daily granularity — a decision.
- **4 · Approvals** — widen the status machine to `internal_review`/`client_review`, package generated
  articles into an approval set, gate publishing on the already-emitted `set_fully_approved` trigger.
  Store `setId` on the item (strategy-item ↔ approval-set mapping).
- **5 · Hierarchy/interlink** — generate the pillar first, inject parent links into children
  (post-process step already in `docs/modules/strategy.md`), then phrase-match interlinks across a
  generated batch respecting `max links`. Rewrite plumbing exists; the "which article links to which
  anchor" selection is the new part.

## Recommended sequence

1. **Quick wins from #6** (item-status route + wire the "View" button) — cheap, useful, low risk.
2. **#1 Auto-publish** — highest value, reuses existing infra.
3. **#2 Queue runner** — makes generation robust (survives tab close) + substrate for scheduling.
4. **#3 Scheduling** on top of #1 + #2.
5. **#4 Approval gates** — reuse the approvals seam.
6. **#5 Hierarchy + interlink** — SEO depth / polish.

## Open decision

**Workflows module (#7).** The docs lean on a multi-step pipeline engine that doesn't exist. Every
workstream above can ship **without it** on the current template-based generation. Recommendation:
**defer** — build it only if multi-step pipelines (research → write → enrich) are actually needed later.

## DB changes implied

- `strategy_items.scheduledDate` (workstream 3) — additive `dbDelta`, indexed, + `PCM_DB_VERSION` bump.
- `siteId` for the strategy (workstream 1) — a column, or stored in the existing `strategies.config` JSON
  (config is read per-strategy, not queried, so JSON is acceptable there).
- Everything else fits the existing `strategies.config` JSON (already persists model/provider + the
  interlink/schedule/approval/hierarchy fields) or existing columns.

## Cross-references

- `docs/modules/strategy.md` — target architecture, full data model, generation pipeline, AutoPress mapping.
- `docs/roadmaps/implementation-of-content-strategy-workflows-and-writer.md` — the Lego-architecture vision.
- `.claude/CODEBASE_MAP.md` → "Content Strategies pipeline" — current, verified state.
