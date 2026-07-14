# Plan: FULL AutoPress parity for the Strategy module (routed worker plan)

**Task (2026-07-10):** "plan everything that's in it and make it have exactly same things as the
autopress." Source of truth: the 104+-feature parity audit (SESSION_LOG 2026-07-10 entry) — every
remaining `missing`/`partial` item becomes a step here. Reference code:
`/private/tmp/claude-501/.../scratchpad/apx/autopress-intelligence` (extracted zip).

**Dependency mapping (no NEW external services needed — flagged per constraint):**
- Research/SERP enrichment → ported onto **existing** `PCM_LLM::invoke_with_grounding()` (Gemini
  google_search grounding) instead of AutoPress's DataForSEO (would be a new paid dependency).
- Featured/in-content images → **existing** image-provider infra (PCM_Providers: Imagen/DALL-E/Kie/Fal)
  already keyed in the user's Integrations.
- AI-assisted anchors → existing `PCM_LLM`. NOTE: this *supersedes* the earlier "phrase-match only"
  Decision 3 per today's "exactly same as AutoPress" instruction — AI fallback only fires when the
  exact phrase isn't present, so the deterministic path stays primary.
- Two prior deferral decisions are likewise superseded by today's instruction: Decision 5 (research)
  and Decision 6 (AI topics, needed by SiteSchedule). Called out so it's a conscious reversal.

**Routing budget:** 31 steps → 20 sonnet / 8 opus / 3 driver (9.7% driver — under the 20% cap).
Driver justifications inline. Sizes: S(<1h-equiv) M(half-day) L(multi-session).

---

## Phase A — Content output parity (what a published article contains)

| # | Step | Files | Route | Size |
|---|------|-------|-------|------|
| A1 | **Featured-image generation on article creation.** New `generate_featured_image($article, $strategy, $user_id)` in strategy service: build an image prompt from title+keyword, call the user's default image provider via existing provider registry, store URL in `articles.featuredImage` (column exists, never populated). Config flag `config.featuredImages` (default ON to match AutoPress); create-dialog checkbox. Accept: unit test w/ faked provider; real-WP smoke populates the column. | strategy/service.php, CreateStrategyDialog.tsx, controller sanitize | **opus** (multi-file, careful placement in the generation pipeline + failure isolation — an image failure must not fail the article) | M |
| A2 | **Push featured image on publish.** `publish_to_site()`: when `article->featuredImage` set, sideload to remote `/wp/v2/media` (download bytes → multipart upload w/ app-password auth), set `featured_media` on the post. Idempotent (skip if `publishedPostId` already has thumbnail). Accept: unit test w/ mocked wp_remote; real publish smoke deferred to live checklist. | sites/service.php | **opus** (remote-API edge cases: mime, auth, partial failure isolation) | M |
| A3 | **Alt/caption metadata for the featured image** (AutoPress: AI vision alt/title/caption/SEO filename). One extra structured-LLM call producing alt+caption; sent with the media upload. Accept: unit test on payload shape. | sites/service.php, strategy/service.php | sonnet | S |
| A4 | **JSON-LD schema generation + embed.** New `build_article_schema($article, $brand, $site)` producing the AutoPress `@graph` (WebSite, Organization, Person/author from brand, BreadcrumbList, WebPage, Article) — port of `schemaGeneratorService.ts`. Embedded as a `<script type="application/ld+json">` block appended to content AT PUBLISH TIME (renderer-independent on remote sites; hub SEO module untouched). Config flag `config.schemaMarkup` default ON. Accept: unit tests on graph shape (article/org/person nodes, sameAs). | strategy/service.php (or new schema helper), sites/service.php | **opus** (faithful port of a 300-line generator, JSON correctness) | M |
| A5 | **Categories + tags on publish.** `publish_to_site()`: resolve-or-create tags (from item keyword) and one category (from brand niche) on the REMOTE site via `/wp/v2/tags`,`/wp/v2/categories` (find by slug → create if absent → send IDs). Failure-isolated (post still publishes untagged). Accept: unit test on term-resolution logic w/ mocked remotes. | sites/service.php | sonnet | M |
| A6 | **In-content images/charts.** Port `[IMAGE_N]`/`media_assets` convention: article schema gains optional `media_assets[]`; writer prompt instructs placeholders; post-process generates each image (provider infra), uploads on publish, replaces placeholders with `<figure>` HTML. Charts via QuickChart URL (free, no key — flag: external URL render). Config `config.inContentMedia` default ON. Accept: unit test placeholder replacement; provider faked. | strategy/service.php, sites/service.php | **opus** (pipeline + prompt + publish coordination) | L |

## Phase B — Research enrichment + multi-step prompting

| # | Step | Files | Route | Size |
|---|------|-------|-------|------|
| B1 | **Research step before writing** (port of `researchService` synthesis, grounded): when `config.research` ON (default ON like AutoPress), call `PCM_LLM::invoke_with_grounding()` — "analyze current top results/themes/questions for KEYWORD" — producing `enrichedContext`; inject into `build_prompt()` as a system section. Failure-isolated (falls back to un-enriched prompt). Accept: unit test injects fake grounding output into prompt; real-API smoke one keyword. | strategy/service.php, CreateStrategyDialog.tsx (toggle), controller sanitize | **opus** (prompt coherence + failure isolation + cost gating) | M |
| B2 | **Per-strategy research depth setting** (`config.researchDepth`: off/standard) + surfacing in create dialog advanced section. Accept: tsc/build, sanitize test. | CreateStrategyDialog.tsx, controller | sonnet | S |

## Phase C — Interlinks: full manager parity

| # | Step | Files | Route | Size |
|---|------|-------|-------|------|
| C1 | **Backend: manual rules + caps.** `interlinksConfig` gains `manualRules[] {keyword,url,matchType}`, `maxLinksPerArticle` (default 2). `maybe_inject_interlinks`: manual rules take precedence; per-target-URL cap enforced; returns per-source/per-target result detail array (injected/skipped/failed + reason) in addition to count. Accept: unit tests (manual rule wins, cap respected, detail shape). | strategy/service.php, controller sanitize_config_fields | sonnet | M |
| C2 | **Backend: AI-assisted anchor fallback.** When a target keyword has NO safe exact occurrence, one structured-LLM call picks an existing phrase in the content to serve as anchor (AutoPress `injectLinkSurgically` allowAi path). Only when `interlinksConfig.aiAnchors` ON (default ON = AutoPress parity; supersedes Decision 3 — flagged above). Deterministic guards (is_inside_html_tag, idempotency) still apply to the AI-chosen phrase. Accept: unit test with faked LLM returning a phrase; guard tests still green. | strategy/service.php | **opus** (LLM-in-the-loop mutation of stored HTML — needs the same safety rails) | M |
| C3 | **Interlink Manager modal** (port of `InterlinkManagerModal.tsx`): Auto/Manual tabs, maxLinks + maxLinksPerArticle inputs, include-parent-articles toggle, manual rule rows (keyword/URL/matchType add-remove), Run button showing per-link ✓/⊘/✗ results from C1's detail array. Opens from the row's Interlinks button (current one-click behavior becomes the modal's Run). Accept: tsc 56 baseline, build clean. | app/src/modules/Strategies/index.tsx (+ new InterlinkManagerModal.tsx component) | **opus** (new 300-line component + state wiring) | L |

## Phase D — Scheduling & status parity

| # | Step | Files | Route | Size |
|---|------|-------|-------|------|
| D1 | **Master Content Schedule view**: new "Schedule" tab/page listing ALL scheduled+published items across strategies (join via list endpoint w/ items), columns: date, keyword/title, strategy, site, status, view links; status dropdown + text search filters. Backend: `GET /strategies/schedule` flat feed. Accept: unit test on feed shape; tsc/build. | strategy/controller.php, new app/src/modules/Strategies/ScheduleView.tsx, app nav registration | **opus** (new page + nav integration) | L |
| D2 | **Pause/resume strategy.** `status='paused'` honored: excluded by `get_due_scheduled_strategies`, `run_scheduled_scan`, and `maybe_schedule_queue_continuation`; row Pause/Resume button. Accept: unit tests (paused strategy never scheduled), UI toggle. | class-pcm-db.php, strategy/service.php, Strategies/index.tsx | sonnet | M |
| D3 | **WP status pull-sync** (`syncStatusFromWP` port): "Refresh from site" row action — for published items, GET remote post status; deleted-remotely → mark item back to completed-unpublished (clear publishedUrl); scheduled('future')→reflect. Accept: unit test w/ mocked remote responses. | sites/service.php, strategy/service.php+controller, Strategies/index.tsx | sonnet | M |
| D4 | **Native WP 'future' scheduling on publish** (AutoPress: scheduled items push with `status:'future'`+date when publishing before due date). `publish_to_site()` accepts optional schedule date → posts `status='future','date'=>…`. Wire: manual Publish on a pending-scheduled item publishes as future post. Accept: unit test payload. | sites/service.php, strategy/service.php | sonnet | S |

## Phase E — Strategy operations parity

| # | Step | Files | Route | Size |
|---|------|-------|-------|------|
| E1 | **Duplicate strategy** (`POST /strategies/{id}/duplicate`): copy strategy+config, items reset to pending, schedule recomputed from today, name "(copy)". Row button. Accept: unit test (items pending, dates fresh, config copied). | strategy/service.php+controller, trpc-routes, Strategies/index.tsx | sonnet | M |
| E2 | **Auto-start after creation** (AutoPress auto-processes): `create_from_keywords` ends by arming the background queue (non-schedule modes) — one `maybe_schedule_queue_continuation` call. Create-dialog note text. Accept: unit test (continuation scheduled on create), draft-mode included. | strategy/service.php, CreateStrategyDialog.tsx | sonnet | S |
| E3 | **Completion notification**: fire new automations trigger `strategy.completed` from `finalize_on_completion` + seeded default in-app-notification rule (idempotent seed, existing engine patterns). Accept: unit test trigger fires once; seeds test count bump. | strategy/automations.php, strategy/service.php, class-pcm-automation-seeds.php (+seed test) | sonnet | M |
| E4 | **Bulk selection + action bar**: row checkboxes, floating bar with Generate All / Publish completed / Delete for the selection (loops existing endpoints client-side). Accept: tsc/build. | Strategies/index.tsx | **opus** (large UI state surface in an already-big file) | M |
| E5 | **Search + status filter** over the strategy list (client-side). Accept: tsc/build. | Strategies/index.tsx | sonnet | S |

## Phase F — Item-level parity

| # | Step | Files | Route | Size |
|---|------|-------|-------|------|
| F1 | **Per-item overrides (backend)**: items' existing `config` column stores `{templateId?, publishingMode?, approvalMode?}`; generation resolves item-override → strategy default (AutoPress `item.promptId || strategy.promptId`). Item PATCH accepts sanitized `config`. Accept: unit tests (override wins; absent falls back). | strategy/service.php, controller | sonnet | M |
| F2 | **Per-item override UI**: compact per-item selects (template/mode/approval) in the expanded row, only when values differ or via an "override" affordance. Accept: tsc/build. | Strategies/index.tsx | sonnet | M |
| F3 | **Volume/Difficulty carried + shown**: additive columns `strategy_items.volume`,`difficulty` (dbDelta + PCM_DB_VERSION bump — the plan's ONLY schema change), create payload carries them from Keywords rows, item rows display. Accept: unit + real-WP dbDelta smoke (column exists, idempotent). | class-pcm-schema.php, power-creatives.php, class-pcm-activator.php, strategy/controller+service, Keywords/index.tsx, Strategies/index.tsx | **opus** (schema change discipline across 6 files) | M |

## Phase G — Hierarchy polish

| # | Step | Files | Route | Size |
|---|------|-------|-------|------|
| G1 | **Parent Settings editor post-create** (port of `ParentSettingsModal`): small modal from the row (or name click) editing hierarchyMode/parentKeyword/parentTargetUrl + NEW `parentAnchorKeyword`,`allowAnchorVariations` (stored in config; `inject_parent_link` honors anchor override — exact text when variations off). Accept: unit test anchor override; tsc/build. | Strategies/index.tsx (+modal), strategy/service.php, controller sanitize | sonnet | M |
| G2 | **Row hierarchy badges** (Crown/Link icon + child count on the strategy ROW header). Accept: tsc/build. | Strategies/index.tsx | sonnet | S |

## Phase H — Cosmetics bundle

| # | Step | Files | Route | Size |
|---|------|-------|-------|------|
| H1 | Sortable columns (name/date/status), deep-link expand (`?strategy=ID`), item tree connector styling, inherited-site display on item rows. Accept: tsc/build. | Strategies/index.tsx | sonnet | M |

## Phase I — Site-level automation (LARGE — includes AI topics, reverses Decision 6)

| # | Step | Files | Route | Size |
|---|------|-------|-------|------|
| I1 | **AI topic suggestion service**: `suggest_topics($site/brand, $count)` via grounded LLM (existing infra) returning keyword/topic list. Accept: unit test w/ faked LLM. | strategy/service.php (or topics helper) | sonnet | M |
| I2 | **Per-site recurring schedules** (port of `SiteSchedule`): site-level rule {frequency, count, template, mode} stored per site (config JSON on sites row — additive, no schema), daily cron creates a strategy from I1 topics per cadence. UI: schedule section on Site detail. Accept: unit tests (cron creates once per period, dedupe), real-WP smoke. | sites module + strategy service + cron, Sites UI | **driver** (cross-module subsystem: sites+strategy+cron+seeder coherence — genuine architectural judgment) | L |

## Phase J — Editor-agent review subsystem (LARGE)

| # | Step | Files | Route | Size |
|---|------|-------|-------|------|
| J1 | **Review feedback pipeline** (port of `editorAgentService`+`finderAgent`+`writerAgent`, scoped v1): per-article "AI Review" action → structured suggestions (find text, issue, replacement w/ inflection-safe surgical replace using existing safe-HTML utilities); apply/reject per suggestion in Writer. Accept: unit tests on find+replace precision; faked LLM. | new strategy/review helper + Writer UI hook | **driver** (three-agent port compressed into one safe pipeline over stored HTML — highest corruption risk in the plan; judgment on scope-fidelity tradeoffs) | L |

## Phase K — Verification, packaging, done-gate

| # | Step | Files | Route | Size |
|---|------|-------|-------|------|
| K1 | Per-phase: suites green (204 baseline + new), tsc 56 baseline, build, real-WP smokes for every backend behavior; final `powerplatform/` zip; SESSION_LOG. Done-gate `spec-verifier` per phase-cluster (A+B, C+D, E-H, I+J), P0/P1 fixed ≤3 rounds. | — | **driver** (verification/judgment is never delegated) | M |

---

## Sequencing & sizing honesty
Order: A → B → C → D → E → F → G → H → I → J (K interleaved per cluster). A+B deliver the "articles
look like AutoPress's" outcome first. **This is a multi-session plan** — realistically A–D in the next
1–2 sessions, E–H after, I–J are each near-session-sized on their own. Every phase leaves the plugin
shippable (zip rebuilt at each K checkpoint).

## Routing split
sonnet 20 / opus 8 / driver 3 (I2, J1, K1 — justifications inline). Reroute ladder per /worker:
sonnet×2-fail → opus; opus×2-fail → driver; no silent takeovers.

## Standing constraints
Minimal diff per step; no new external dependencies (mappings above use existing providers/keys —
QuickChart in A6 is the one external URL-based service, free/keyless, flagged for your OK there);
`PCM_VERSION` untouched; only F3 bumps `PCM_DB_VERSION` (additive dbDelta); nothing committed; suite
baseline 204/204 (+1 known unrelated failure) must hold at every step.

---

## EXECUTION LOG (live, 2026-07-10)

| Step | Status | Notes |
|---|---|---|
| A1 featured-image generation | DONE (opus) | +driver fix: default model dall-e-3→gpt-image-1-mini (retired) |
| A2/A3 publish media+alt | DONE (opus) | 6/6 tests, failure-isolated |
| A4 schema builder | DONE (opus) | 6/6 tests; embed wired at publish by A2 worker |
| A5 remote tags/categories | DONE (opus, bundled w/ A2) | find-or-create with race handling |
| A6 in-content images | DONE (opus + driver fix) | 6 tests; driver fixed strict-schema shape (all-required + chart_config as JSON string) after real-API 400 |
| B1/B2 research enrichment | DONE (opus) | grounded-Gemini; 3 tests; dialog toggle default ON |
| C1 interlink options backend | DONE (sonnet) | contract honored; 229-suite green |
| C2 AI anchor fallback | DONE (opus) | 5 tests; rails preserved; shared find_safe_occurrence |
| C3 interlink manager modal | DONE (opus) | pinned contract; results panel |
| D1 master schedule view | DONE (opus) | /strategies/schedule feed + ScheduleView + List/Schedule toggle |
| D2 pause/resume | DONE (opus bundle E1+D2+G1) | keep-paused rule incl. scheduled-scan join |
| D3 WP status pull-sync | DONE (opus, bundled w/ D4) | sync_items_from_wp + Sync button; 9 tests |
| D4 native future scheduling | DONE (opus, bundled w/ D3) | status:'future' + due-date picker + publish_item schedule_date |
| E1 duplicate strategy | DONE (opus bundle) | POST /strategies/{id}/duplicate |
| E2 auto-start on create | DONE (sonnet) | non-schedule modes arm the queue on create |
| E3 completion notification | DONE (sonnet) | strategy.completed trigger + seeded rule (seeds 8→9) |
| E4 bulk selection bar | DONE (opus) | serial bulk generate/publish/delete |
| E5 search+filter | DONE (sonnet) | |
| F1/F2 per-item overrides | DONE (opus) | item_config REPLACE semantics; 7 tests; 264-suite green |
| F3 volume/difficulty columns | DONE (opus) | PCM_DB_VERSION 1.39.0; driver real-WP dbDelta+idempotency smoke passed |
| G1 parent settings modal | DONE (opus bundle) | + anchor keyword config |
| G2 row hierarchy badges | DONE (via H1) | |
| H1 cosmetics | DONE (sonnet) | sort/deep-link/badges/tree |
| I1 topic suggester | DONE (sonnet) | 5/5 tests |
| I2 per-site schedules | DONE (driver) | option-stored rules (sites table has NO config column — plan assumption corrected); GET/POST /sites/{id}/schedule; daily-scan hook; Auto dialog; 7 tests |
| J1 editor review | DONE (driver) | PCM_Article_Review + 2 routes + AI Review panel; 11 tests; tsc 56 |
| K checkpoints | DONE | suite 291/1-known; tsc 56; build ✓; real-WP: F3 dbDelta+idempotency ✓, routes ✓, J1 review live 4.6s/8 suggestions ✓, A6 generation live 86.8s/2 figures/0 leftover ✓. Driver fixes: json_schema wrapper missing in J1+C2 (OpenAI 400), A6 strict-mode schema shape. Scraper has same bare-schema bug (pre-existing) → spawned separate task |

**Driver fixes found by real-API smoke (worker tests couldn't catch):** OpenAI provider sent retired
'style' param (now only forwarded when caller-provided); provider only parsed data[0].url while
current gpt-image-* models return b64_json (now persisted via wp_upload_bits → hosted URL);
PCM_Strategy_Image default model updated. Provider fixes benefit the Image module too.
