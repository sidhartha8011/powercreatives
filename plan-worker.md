# Plan: Compact Create-Strategy dialog + structure↔hierarchy coupling (routed worker plan)

User feedback (screenshot): the Create Strategy dialog is too tall — every option is a
full-width block with a paragraph of help text; and **Content Hierarchy ↔ Content per
Keyword are interrelated** but the UI lets you pick contradictory combinations.

## The interrelation (driver decision)

`structure='consolidated'` = ONE article for all keywords → a hierarchy between the
strategy's own articles is meaningless (there are no separate children).
Coupling rule — **two-way auto-correction, no disabled dead-ends**:
- Picking **Consolidated** → hierarchy snaps to **Standalone**; the hierarchy select is
  then disabled with a one-line hint ("Hierarchy applies to per-keyword strategies").
- Picking any **hierarchy mode** (children_only / parent_and_children) while
  consolidated → structure snaps back to **1 per Keyword** (the select stays enabled;
  choosing a hierarchy expresses clear intent for per-keyword articles).
- Server-side guard (defense in depth): a consolidated strategy is persisted with
  `hierarchyMode='standalone'` regardless of what the client sent.

## Compactness direction (executed by the UI worker, taste + baseline-ui loaded)

- Two-column grid rows: Name + Target Site · Workflow + Content per Keyword (already
  paired) · AI Model + Content Hierarchy.
- The 4 feature checkboxes become compact single-line toggle rows: label + a small
  muted one-line hint on the same row block (no full paragraphs); descriptions move to
  `title` tooltips where they can't fit in one short line.
- Conditional sub-sections (parent URL / parent keyword / schedule fields) keep their
  current show-when-relevant behavior but adopt the same compact spacing.
- Target: the default dialog state fits a 900px-tall viewport without scrolling.
- No visual-language change — same shadcn primitives + design tokens, just denser.

## Constraints (echoed to every worker)
- PCM_VERSION 1.7.0 / PCM_DB_VERSION 1.40.0 untouched; no schema change.
- Suite baseline: 315 tests / 3 known pre-existing failures (PlatformRoleInvariantTest,
  TextMatcherTest, SeoIntegrationTest). tsc baseline: **59**. Build must pass.
- Minimal diff; no new dependencies; nothing committed.

## Steps

| # | Step | Files | Route | Acceptance |
|---|---|---|---|---|
| 1 | **Dialog redesign + coupling UX.** Restructure CreateStrategyDialog per the compactness direction; implement the two-way structure↔hierarchy auto-correction + disabled-state hint. Payload shape unchanged (same keys). Worker brief loads `baseline-ui` (deslop/spacing) + `taste-skill` (anti-generic polish) skills. | app/src/modules/Keywords/CreateStrategyDialog.tsx | **opus** | tsc 59; vite build OK; grep shows coupling handlers |
| 2 | **Server guard + test.** In strategy create path (controller `create_strategy` or service `create_from_keywords` — worker picks the single sanest choke point and states why): `structure==='consolidated'` forces `hierarchyMode='standalone'` (and drops parent keys). New test in a new small file or additively in an existing strategy suite: consolidated + parent_and_children in → stored standalone, parent keys absent; individual + hierarchy passes through untouched. | includes/modules/strategy/controller.php OR service.php + one test file | **sonnet** | php -l; new/updated tests green; full suite 3-known-only |
| 3 | **Checkpoint (driver — done-gate contract).** Full gates (suite/tsc/build); render-level sanity via the built bundle; spec-verifier with this plan + diff; SESSION_LOG; report. | — | **driver** | verbatim outputs |

Routing: 1 opus / 1 sonnet / 1 driver. (Driver = the mandated done-gate; the two
delegable work units are single-file and indivisible without artificial splits.)

## EXECUTION LOG
| Step | Status | Notes |
|---|---|---|
| 1 dialog redesign | DONE (opus) | tsc 59 ✓, build ✓; ~26→~15 text rows (fits 900px); coupling + submit-time guard; preserved concurrent recurrence work |
| 2 server guard | DONE (sonnet) | pure helper guards create funnel (covers duplicate+scheduler) + PATCH door on effective state; 4 tests; suite 319/3-known |
| 3 checkpoint | DONE (driver) | gates ✓; real-WP guard smoke exact; spec-verifier APPROVED; driver closed F1 (duplicate door now guarded) and resolved F2 (disabled-select-with-hint chosen over silent two-way snap — plan contained both; the explicit form is clearer UX) |
