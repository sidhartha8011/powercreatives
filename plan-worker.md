# Plan: Writer UX round — shared accordions, panel-state bug, Publish CTA, real Revisions (routed worker plan)

Four owner asks on the Writer module. Facts from recon:

- **Shared accordion exists**: `components/shared/AccordionSection.tsx` — the collapsible card
  Video/Ads/Copy/Image already use. Writer's `ContextGenerationPanel` instead uses its own grey
  SectionLabel-banner blocks (the "grey ones" in the screenshot). Shared dropdowns also exist
  (`AsyncSelectField`, `TemplateDropdown`, `ContextPanel` brand section) — Writer partially uses
  them already; the section *chrome* is what diverges.
- **Panel-state bug is mechanical**: the three panels are react-resizable-panels with
  collapse-by-size. Collapsing one redistributes the group layout, which can re-inflate an
  already-collapsed sibling (its onExpand fires → its flag flips → "Settings opens again").
  The flags must be authoritative: after any collapse/expand, re-assert the other panels'
  desired collapsed state.
- **Publish**: the header's only CTA today is "Send to Approvals" (primary blue). The sites
  module already has `POST /sites/{id}/publish` (used by Strategies). The Writer doc carries a
  Target Site (`settings.siteId`).
- **Revisions**: clicking "Revisions" opens the AI Review panel (it replaced the old empty
  placeholder). There is NO revision storage anywhere — a real "history queue" requires
  capturing snapshots. Plan adds a small `article_revisions` table (THE schema change of this
  round → PCM_DB_VERSION 1.42.0 → **1.43.0**).

## Driver decisions (approved by presenting this plan)

1. **"Approval required" signal**: Writer docs have no explicit requires-approval flag. The
   Send-to-Approvals button renders **subtle/outline by default** and lights up primary ONLY
   when the active doc's status is `review` (the one status that means "in an approval flow").
   Publish is ALWAYS the primary blue CTA.
2. **Revisions data**: capture a snapshot (title+content) every time an article's content
   actually changes via the Writer save path; keep the most recent **10** per article, pruned;
   each entry restorable. This is what makes the History panel real instead of an empty shell.
3. **AI Review stays but moves behind a tab**: the right panel becomes two tabs — **History**
   (default, the revisions queue) and **AI Review** (existing feature, unchanged) — so the
   owner's "not the AI thing right there" is honored without deleting a shipped feature.

## Constraints (echoed to every worker)
- PCM_VERSION 1.7.0 untouched. PCM_DB_VERSION 1.42.0 → **1.43.0 ONLY in step 3** (new table,
  additive dbDelta, no bespoke migrate needed — new tables need no gate).
- Baseline: suite 341 tests / 3 known pre-existing failures (PlatformRoleInvariantTest,
  TextMatcherTest, SeoIntegrationTest); tsc 59; build green. DO NOT touch
  includes/modules/optimizer or includes/modules/seo (teammate's arc).
- Minimal diff; no new deps; nothing committed. Concurrent workers on disjoint files — read fresh.

## Steps

| # | Step | Files | Route | Acceptance |
|---|---|---|---|---|
| 1 | **Shared accordions in Writer.** Restyle `ContextGenerationPanel` to use the shared `AccordionSection` (same chrome as Ads/Video): Brand, Target Site, Templates, Keywords, Prompt/Brief, Advanced, SEO Metadata each become AccordionSections (sane defaultOpen: Keywords+Prompt open, rest closed); DELETE the grey SectionLabel banner blocks; keep every existing field/handler/dropdown (AsyncSelectField/TemplateDropdown/ContextPanel) — chrome only, zero behavior change. Brief loads `baseline-ui` skill. | app/src/modules/Writer/components/ContextGenerationPanel.tsx (+ WriterDynamicSection.tsx if its wrapper carries grey chrome) | **opus** | tsc 59; build OK; grep shows AccordionSection used, SectionLabel banners gone |
| 2 | **Panel-state bug.** In Writer/index.tsx: make the three collapse flags authoritative — after any panel collapse/expand (button handlers AND onCollapse/onExpand callbacks), re-assert the OTHER panels' desired state (if flag says collapsed but layout re-inflated it, call .collapse() again; use a ref-held desired-state map + a guarded onLayout or effect so no loops). Repro to kill: collapse Settings → collapse Queue → Settings must STAY collapsed (all 6 orderings stable). | app/src/modules/Writer/index.tsx | **sonnet** | tsc 59; build OK; explain the fix mechanism + why loop-free |
| 3 | **Revisions backend.** New `article_revisions` table (id, articleId FK-style int, userId, title, content longtext, source varchar e.g. 'editor'/'ai-review'/'restore', createdAt) via dbDelta in class-pcm-schema.php + PCM_DB_VERSION 1.43.0 + activator note (additive, no gate — new-table precedent). Capture: in the writer update path where content actually changes, snapshot the PREVIOUS title+content, prune to 10/article. Routes on writer controller: GET /articles/{id}/revisions (list, no content — id/title/source/createdAt + excerpt), GET one w/ content, POST /articles/{id}/revisions/{revId}/restore (snapshots current first, then restores; returns updated article). PCM_DB helpers with $wpdb->prepare. Unit tests (capture-on-change only, prune, restore-roundtrip). | includes/core/db/class-pcm-schema.php, class-pcm-db.php, includes/class-pcm-activator.php (note), power-creatives.php (DB ver), includes/modules/writer/controller.php, 1 new test file | **opus** | php -l; new tests green; suite 3-known-only; grep PCM_VERSION unchanged |
| 4 | **Publish CTA + approvals demotion.** Writer header: new **Publish** button — always primary/blue, publishes the ACTIVE doc to its selected Target Site via the existing sites.publish trpc route (disabled w/ tooltip when: no server-persisted doc, empty content, or no Target Site selected); on success toast w/ link + update doc status to 'published'. "Send to Approvals": variant subtle/outline by default, primary ONLY when activeDoc.status==='review'; label/dialog flow unchanged. Order: Publish first (rightmost primary), approvals beside it. | app/src/modules/Writer/index.tsx, app/src/lib/trpc-routes.ts (only if the sites.publish transform needs an article-shaped body — read the sites controller handler first) | **opus** | tsc 59; build OK; document the exact publish request body used |
| 5 | **Right panel: History default + AI Review tab.** AiRevisionsPanel becomes a two-tab panel: **History** (default) — fetches GET /articles/{id}/revisions, lists entries (time-ago, source badge, excerpt) with a Restore button per row (confirm + refetch + editor sync via updateDoc, same pattern as AI-review apply); honest empty state "No revisions yet — they're captured every time you save changes". **AI Review** tab = the existing content verbatim. New trpc routes writer.revisions / writer.revisionRestore. Toolbar button keeps its Revisions label. | app/src/modules/Writer/components/AiRevisionsPanel.tsx, app/src/lib/trpc-routes.ts | **sonnet** | tsc 59; build OK |
| 6 | **Checkpoint (driver — done-gate contract).** Full gates; real-WP smoke: update an article twice → 2 revisions listed, restore returns prior content and itself creates a snapshot; publish route sanity; spec-verifier with this plan + diff; SESSION_LOG; zip (DB 1.43.0 stamp check). | — | **driver** | verbatim outputs |

Routing: **3 opus / 2 sonnet / 1 driver** (17% ≤ 20% ✓).
Sequencing: 1 ∥ 2-then-4 (both touch Writer/index.tsx — serialized) ∥ 3-then-5 (5 needs 3's routes).

## EXECUTION LOG
| Step | Status | Notes |
|---|---|---|
| 1 shared accordions | DONE (opus, 1st try) | 9 AccordionSections, PanelHeader/grey chrome gone from both files; hideBrandHeader (pre-existing prop) avoids double Brand header; tsc 59, build ✓. Caveat: TemplateDropdown (Copy/, off-limits) keeps one inner border |
| 2 panel-state bug | DONE (sonnet, 1st try) | desiredCollapsed ref authoritative; onExpand/onCollapse guards re-assert; tsc 59, build ✓ |
| 3 revisions backend | DONE (opus, 1st try) | table+helpers+3 routes+capture (editor/ai-review/restore); 6 new tests; suite 347/3-known; DB 1.43.0 |
| 4 publish CTA | DONE (opus, 1st try) | Publish always-primary (Rocket, rightmost); body {articleId} per sites publish_article handler; success→status 'published'+postUrl toast; approvals variant subtle unless status==='review'; trpc-routes untouched; tsc 59, build ✓ |
| 5 history panel | DONE (sonnet, 1st try) | Tabs (History default + AI Review verbatim); writer.revisions/revisionRestore trpc; restore confirms→updateDoc sync→refetch; tsc 59, build ✓ |
| 6 checkpoint | DONE (driver) | tsc 59; build ✓; suite 347/3-known; real-WP smoke all-green (2 revisions, restore+snapshot, prune 10, ownership, routes live); spec-verifier APPROVED (0 P0/P1, 3 P3); SESSION_LOG appended; zip rebuilt (DB 1.43.0 stamped) |
