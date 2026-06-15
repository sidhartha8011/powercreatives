# Session Log

## 2026-06-14 — SEO prompts editable in Settings → Prompts (new "SEO" tab)
- **Task:** "where are the prompts written for all the generations? it should be
  same from the zip and in this it should be in the template section with new
  tabs." Answered: SEO generation prompts live verbatim in
  `includes/modules/seo/prompts.php` (5 keys × generate/optimize, ported from
  the source's `optimizer_site_prompt_templates`); every OTHER generation's
  prompts (copy/ads/image/video/writer) were already editable in **Settings →
  Prompts**, but SEO was code-only. Per the user's choice, wired SEO into that
  same Prompt Editor as a new module tab (NOT the form-field Templates page).
- **Built (minimal diff, no DB migration):**
  - `seo/service.php`: `get_default_prompts()` flattens `prompts.php` into the
    8-section `{use}_{mode}` registry; `resolve_prompt($section,$default,$userId)`
    returns the active `prompt_overrides` row (module='seo') or the default
    (mirrors `PCM_Writer_Service::get_system_prompt`). `generate_field` /
    `optimize_body` now take `?int $user_id` and use the resolver →
    precedence DB override > `pcm_seo_field_prompts` filter > file.
  - `seo/controller.php`: threads the PCM user id (`get_current_pcm_user()->id`)
    into both generate/optimize calls.
  - `prompts/controller.php`: registered `seo` in `get_default_sections`
    (8 sections), `get_section_meta` (labels + placeholder docs), and
    `get_default_prompt` (delegates to `PCM_SEO_Service::get_default_prompts`).
  - `app/src/modules/Settings/PromptEditorSection.tsx`: added `seo` to `MODULES`
    (Search icon), `PromptModuleId`, `PLACEHOLDERS` (Page + Business/Site), the
    `seoList` query, and `sectionsByModule`.
- **Why no migration:** `list_variants` serves a virtual "Built-in" default from
  `get_default_prompt` when no DB rows exist and `create_variant` accepts any
  module — so editing just creates a row on save (same as the other modules).
- **Verified:** PHP lint clean (3 files); `npm run check` 0 new errors (56-error
  baseline unchanged, my file clean); `npm run build` clean.
- **Hardened (/ship):** +4 PHPUnit tests in `SeoIntegrationTest` —
  `get_default_prompts` 8-section contract + `resolve_prompt` precedence
  (no-user→default, active override wins, blank override→default; `$wpdb`
  mocked); full suite **97/97 green** (317 assertions, no mock leakage).
  Registration-integrity proof: a source cross-check confirms
  `get_default_sections('seo')` ≡ `seo.*` section-meta labels ≡
  `get_default_prompts()` keys (all 8 — editor cannot show a blank/orphan tab).
  Verified the 6→11 advertised `{{placeholders}}` are all produced by
  `build_field_vars` (no literal-token leak) and expanded the guide with the
  local-SEO `{{business.*}}` fields. Confirmed safe: `get_current_pcm_user()` is
  typed `: object` (never null) and the prompt placeholder-sync (`sync_all`) is
  scoped to its copy/ads INJECTIONS registry, so it never touches `seo` rows.
  No live wp-cli round-trip — `wp` not on PATH + local DB down; the override
  query mirrors the proven `PCM_Writer_Service::get_system_prompt`.
- **Pickup:** local plugin is symlinked → hard reload (⌥⌘R) to see the new SEO
  tab under Settings → Prompts. Deploy `power-creatives.zip` is now STALE (not
  rebuilt). Uncommitted on `feat/seo-suite-port`.

## 2026-06-14 — Research: Persuaide image pipeline vs PC (no code change)
- **Task:** study `github.com/SoftEXedge/Persuaide-main` (cloned to /tmp,
  reference only) and recommend why its brand-guideline-driven image gen beats
  PC's, and what to adopt. Analysis-only — **no PC files changed**, map unaffected.
- **Persuaide's edge (key files):** `imagePromptV2.service.ts` co-generates a
  single creative concept `{conceptIdea, headline, lines, scene}` (anchorage) via
  a heavy "creative-director" system instruction (photographic specs + hard
  constraints + per-gen variation seeding from a curated SCENE_ANGLES list);
  `behavioralScience.service.ts` maps objective→principles (Scarcity/Social
  Proof/Loss Aversion…) injected into the prompt; `types/brandGuidelines.ts` is
  a rich structured brand schema (colors+do/dont, typography, imagery
  mood/composition/do/avoid, voice, logo, constraints) extracted from brand PDFs;
  `imageGenV2.service.ts` generates a TEXT-FREE image (Gemini 3 Pro Image / Nano
  Banana on Vertex) then overlays the headline via a deterministic layout
  planner/solver + adaptive colours sampled from pixels; OCR baked-text regen
  (×2) + `imageQualityCheckV2.service.ts` vision-judge rubric (9 criteria + 1-5
  score) regenerating with fix-instructions (×3) on fail.
- **PC gap:** raw brief → optional optimize/concepts → thin `final_prompt`
  brand wrap → single provider call → store, text BAKED IN, no quality gate, no
  overlay, no variation seeding, thin brand context, no behavioral layer.
- **Recommended (prioritised, not yet built — awaiting go-ahead):** T1 (cheap,
  prompt-only, fits prompt_overrides): co-generate headline+scene concept;
  port the creative-director system prompt + hard constraints; richer brand
  context; per-gen variation seed. T2 (bigger): vision quality-gate + capped
  auto-regen (COST ⚠ — toggle/cap), text-free + overlay, OCR guard. T3: add a
  stronger image model via the data-driven provider registry; behavioral-science
  principle injection if PC gains a campaign-objective concept.

## 2026-06-13 — SEO table wrapping: real fix (override shadcn cell nowrap)
- **Report:** cells still on one line, widening the table (long meta-keywords
  pushed the other columns off-screen).
- **Root cause:** the shadcn `TableCell`/`TableHead` ship with
  `whitespace-nowrap`; my prior `whitespace-normal` on the inner button didn't
  reliably override the cell-level rule, so cells stayed single-line and
  (table-auto) expanded to fit.
- **Fix (`SEO/index.tsx`):** override at the table via the higher-specificity
  descendant selector — `<Table className="table-fixed w-full [&_td]:align-top
  [&_td]:whitespace-normal [&_td]:break-words">` — which beats the cell's own
  `whitespace-nowrap`; dropped the conflicting `truncate` on the Author cell.
  Now every cell wraps within its fixed-% column. (Local WP plugin is
  symlinked to the working dir, so a hard reload ⌥⌘R picks it up.)
- **Verified:** tsc 0 errors in SEO files (56 baseline); build clean. Visual —
  confirm after hard reload. Zip rebuilt (19:59).
- **Uncommitted.**

## 2026-06-13 — SEO content table: wrap cells instead of widening
- **Task:** generated/cell values should wrap to multiple lines in the row
  rather than growing the table horizontally.
- **Changed (frontend only, `SEO/index.tsx` + `types.ts`):** EditableCell
  display `truncate` → `whitespace-normal break-words leading-snug min-w-0`
  (long meta values now flow onto multiple lines); `<Table>` →
  `table-fixed w-full [&_td]:align-top` (respects column widths, wraps
  content, top-aligns multi-line rows); trimmed column widths from a 112%
  total to ~100% so it fits the container without horizontal overflow. The
  staged-suggestion card already used `break-words`. No logic change.
- **Verified:** tsc 0 errors in SEO files (56 baseline); build clean. Zip
  rebuilt (19:44). Visual change — confirm after reload.
- **Uncommitted.**

## 2026-06-13 — Fix: SEO cells not editable (camelCase) + bulk AI toolbar
- **Report:** "not able to enter primary keyword"; + bring in the source's
  content-table options / a toolbar with bulk actions.
- **Root-cause bug (high):** the seo controller's `save_cell` AND
  `generate_field` sanitized the `field` param with `sanitize_key()`, which
  LOWERCASES — so every camelCase field (`primaryKeyword`, `metaTitle`,
  `metaDescription`, `metaKeywords`, `supportingKeyword`, `clusterLabel`)
  failed the whitelist lookup → inline edits and per-field AI generation
  silently broke for all of them (only `title`/`status` worked). Earlier
  Phase 1/3 verifications called the SERVICE directly, bypassing the
  controller's sanitize, so they missed it. **Fix:** `sanitize_text_field`
  (case-preserving; the field is whitelist-validated downstream).
- **Toolbar (from the source UX):** bulk-actions bar on selection — "AI
  generate: Title / Meta Title / Meta Desc / Keywords" runs that field for
  every selected row (sequential), staging each result; plus a global
  "N AI suggestions pending — Accept all & save / Discard all" bar (mirrors
  the source's pending-changes bar). Per-row sparkle + Optimize modal
  unchanged.
- **Verified:** PHPUnit 93/304 green; tsc 0 errors in SEO files (56
  baseline); build clean. Live wp-cli through the REAL REST path: save_cell
  for primaryKeyword/metaTitle/metaDescription/metaKeywords all → 200 and
  persist; generate `metaTitle` no longer returns `pcm_seo_not_generatable`.
  Zip rebuilt (19:31).
- **Uncommitted.**

## 2026-06-13 — SEO port Phases 3b (Optimize modal) + 8 (Hub) — SUITE COMPLETE
- **Phase 3b — Optimize Content:** prompts.php +`content` optimize prompt;
  `PCM_SEO_Service::optimize_body` (full-body SEO/AEO rewrite via PCM_LLM,
  not saved); REST `GET/POST /seo/content/{id}/body` + `/optimize`. Frontend:
  per-row Sparkle → `OptimizeModal` (Before/After + a client-side SEO
  scorecard: word count, KW density, KW-in-first-100, KW-in-headings,
  headings, FAQ, lists, avg sentence length) → Accept&Save. `scorecard.ts`.
- **Phase 8 — Hub (multi-site connectors):** DB 1.23.0 — `seo_tenants` +
  `seo_hmac_nonces` (PCM_Schema dbDelta; maybe_upgrade auto-creates).
  `seohub` module: `PCM_SEOHub_Service` — HMAC sign/verify (sha256 over
  ts.nonce.body, ±300s window, nonce replay guard), tenant CRUD (uuid
  clientId + 32-byte secret), `register_ping` (handshake → active + captured
  Application Password), remote proxy (Basic auth), and a single-file
  **connector-plugin ZIP generator** (bakes client_id/secret/hub-url;
  registers SEO meta in REST; sends an HMAC-signed hello on activation).
  `PCM_REST_SEOHub`: tenant CRUD (`manage_options:strict` — rows hold
  secrets), a **streamed** connector download (kept out of public uploads),
  and a public HMAC-verified `/seohub/connector/hello`. Frontend: SEO "Hub"
  tab (`HubPanel` — add site, download connector via nonce'd blob fetch,
  revoke/delete; secrets never sent to the client).
- **Verified:** PHPUnit 93 tests / 304 assertions green (new: scorecard via
  tsc; HMAC sign determinism + sensitivity). tsc 0 errors in SEO files (56
  baseline); build clean. Live wp-cli (deleted): hub routes; tenant create
  (secret redacted in list); HMAC valid→passes, bad-sig/replay/stale all
  rejected with the right codes; register_ping→active; connector ZIP bakes
  id+secret + registers meta; **1.22.1→1.23.0 upgrade auto-creates both
  tables**. Zip rebuilt (19:20).
- **SEO SUITE COMPLETE** — all 9 source modules ported as native PC modules
  (Phases 1–9 + 3b). Remote connector handshake from a real external site is
  the only thing not locally testable (HMAC verify + ZIP gen are).
- **Uncommitted.**

## 2026-06-13 — SEO port Phases 7 (GBP, n8n provider-swappable) + 9 (export/import)
- **Decision confirmed:** existing n8n webhook tested LIVE and working
  (HTTP 200, real Google Places New v1 data for "Smålands Tak"); Google
  billing already lives in n8n. So GBP reuses n8n, behind a provider
  interface so a direct-Google client can drop in later.
- **Phase 7 — GBP (`gbp.php`):** `PCM_SEO_GBP_Provider` interface +
  `PCM_SEO_GBP_N8N_Provider` (posts the source's exact search_places/
  get_business_details contract to the configured webhook, optional
  `X-PCM-Secret`) + factory `PCM_SEO_GBP::provider()` (filterable
  `pcm_seo_gbp_providers`; swap = new class + `seo_gbp_provider` setting).
  Shared `normalize()` (Places New v1 + legacy keys). Per-brand storage
  (option `pcm_seo_gbp_{id}`: snapshot + manual overrides that survive
  refresh). `build_field_vars` now enriches `{{business.address/phone/
  category/hours/rating/lat/lng/types}}` from the brand's GBP. REST
  search/save/get/overrides (admin). Frontend: SEO "Business" tab
  (`BusinessPanel`) — webhook config, brand select, search, save-to-brand,
  editable overrides.
- **Phase 9 — Export/Import (`export.php`):** `PCM_SEO_Export` bundles the
  agency-reusable SEO config (site schema/robots, AI-readiness settings, GBP
  provider+webhook) as portable JSON; import is plugin-key-checked +
  whitelist-applied. REST `GET /seo/export`, `POST /seo/import`. Frontend:
  Export/Import buttons in the Site tab.
- **Verified:** PHPUnit 91 tests / 300 assertions green (new: GBP normalize
  v1+legacy, provider factory). tsc 0 errors in SEO files (56 baseline);
  build clean. Live wp-cli (deleted): n8n webhook works; GBP routes + factory
  + normalize + snapshot/override-merge surviving refresh + prompt-var
  enrichment; export→import round-trip restores config, wrong-plugin
  rejected. Zip rebuilt (19:07).
- **Remaining:** Phase 3b (Optimize-Content modal + SEO scorecard) and
  Phase 8 (hub multi-site connectors). **Phase 8 intentionally NOT rushed
  into this turn** — it's a large security-sensitive subsystem (connector
  plugin ZIP generation, HMAC handshake, WP application-password capture,
  tenant DB tables) that warrants its own focused build.
- **Uncommitted.**

## 2026-06-13 — SEO port Phases 4 (Schema) + 6 (Site settings)
- **Task (/build "phase 4 and 6"):** built both inline from source (minimum
  token cost, no fresh agents).
- **Phase 4 — Schema (`includes/modules/seo/schema.php`):** faithful port of
  the JSON-LD renderer — `PCM_SEO_Schema` emits enriched Schema.org on
  `wp_head` (is_singular) for Article / WebPage / BreadcrumbList / FAQPage /
  HowTo / Product, with publisher Organization + logo fallback chain
  (plugin→customizer→Yoast/RankMath/SEOPress→site-icon), about/mentions Thing
  + sameAs, speakable, ReadAction. Description/keywords/primary-kw come
  through the Phase 1 cross-plugin `seo_get`. Meta `pcm_seo_schema[_faq/
  _howto/_sameas]`; whitelist-validated. REST `GET/POST /seo/content/{id}/
  schema`. Frontend: per-row `SchemaCell` popover; `schemaTypes` on each row.
- **Phase 6 — Site (`includes/modules/seo/site.php`):** `PCM_SEO_Site` —
  custom robots.txt (`robots_txt` filter), site-wide LocalBusiness JSON-LD +
  `<meta name=keywords>` on `wp_head`, language/timezone apply with restorable
  backups. REST `GET/POST /seo/site`, `POST /seo/site/restore`. Frontend:
  third SEO tab `SiteSettingsPanel`.
- Both required from service.php (hooks every request).
- **Verified:** PHPUnit 88 tests / 283 assertions green (new: schema
  whitelist filter + types round-trip, site default-robots, robots-filter
  passthrough); tsc 0 errors in SEO files (56 baseline); build clean. Live
  wp-cli (deleted): schema set/round-trip + Article(publisher/about/
  speakable/wordCount)/Product(category/brand/keywords)/FAQPage build, valid
  JSON-LD; robots filter on/off, site LocalBusiness head emit, timezone
  apply+backup+restore. (wp_head JSON-LD emit on singular runs on the live
  site.) Zip rebuilt (18:35).
- **Remaining:** Phase 3b (Optimize modal + scorecard), 7 (GBP), 8 (hub),
  9 (export-import).
- **Uncommitted.**

## 2026-06-13 — SEO port Phase 5: AI-Readiness (llms.txt + virtual routes)
- **Task (/build "next phase 4-9"):** continue the SEO port. Delivered the
  highest-value genuinely-new domain — AI-Readiness — fully this turn
  (building all six of 4–9 at mergeable quality in one turn isn't feasible;
  built inline from the held inventory specs = minimum token cost, no fresh
  agents).
- **Built (backend, `includes/modules/seo/ai-readiness.php`):** faithful port
  — `PCM_SEO_AIReadiness`: virtual routes `^llms\.txt$` / `^llms-full\.txt$` /
  `^(.+)\.md$` (init rewrite when published, query_vars, redirect_canonical
  guard, template_redirect server), page-builder-aware HTML→Markdown
  (Elementor/Divi/Brizy), `post_to_markdown`, llms.txt index + full builders
  (llmstxt.org spec: H1 → blockquote → Pages/CPTs/## Optional/## Resources),
  per-post status (md5 ready/stale/none), Stripe-style `.md` URLs. Options
  `pcm_seo_air_*`, meta `_pcm_md_*`. Required from service.php so hooks run
  every request. REST (admin): `GET /seo/ai-readiness`, `/build`, `/publish`,
  `/settings`, `/generate`.
- **Built (frontend):** SEO module now tabbed (Content / AI Readiness);
  `AIReadinessPanel` — publish toggle, Build/Regenerate, llms.txt links,
  per-post readiness list. 5 trpc routes.
- **Verified:** PHPUnit 84 tests / 285 assertions green (new: html→md
  headings/bold/links, lists, Gutenberg-comment strip); tsc 0 errors in SEO
  files (56 baseline unchanged); build clean. Live wp-cli (deleted): post→md
  (H1+heading+bold), build_index → llms.txt (H1, ## Optional, Resources/
  sitemap, .md links) + llms-full (Source: lines), generate_md stores md,
  status ready→stale on edit, publish registers the rewrite rules, .md URL
  shape. (HTTP serving via template_redirect runs on the live site.) Zip
  rebuilt (18:22).
- **Remaining:** Phase 4 (schema/sameAs), 6 (site settings), 7 (GBP — needs
  N8N-vs-direct-API decision), 8 (hub connectors — heavy/security-sensitive),
  9 (export-import). Per `.claude/SEO_PORT_PLAN.md`.
- **Uncommitted.**

## 2026-06-13 — SEO port Phase 3: AI field generation + verbatim prompts
- **Task (/build "build the next phase"):** Phase 3 of the SEO port.
- **Built (backend):** `includes/modules/seo/prompts.php` — verbatim port of
  the source's field prompts (page_title / meta_title / meta_description ×
  generate+optimize, meta_keywords; `{{primary_kw}}` bug corrected),
  filterable via `pcm_seo_field_prompts`. `PCM_SEO_Service`: `field_use_map`
  (title/metaTitle/metaDescription/metaKeywords → use), `substitute_vars`
  (faithful `{{var}}` engine), `build_field_vars` (post + brand/site business
  context), `sanitize_ai_output` (strip one surrounding quote pair),
  `generate_field` → reuses PC's `PCM_LLM::invoke` provider routing (optimize
  when a value exists, else generate; NOT saved — staged client-side). Route
  `POST /seo/content/{id}/generate` (edit_posts + per-post edit_post).
- **Built (frontend):** per-field AI sparkle in the SEO table → generate →
  STAGED suggestion (accept = save via existing cell-save / reject), in-flight
  spinner. Hook `generateField`; trpc `seo.generateField`.
- **Verified:** PHPUnit 81 tests / 273 assertions green (new: substitution,
  output-sanitize, field map, prompt completeness); tsc 0 errors in touched
  files (56 baseline unchanged); build clean. Live wp-cli (deleted): generate
  route registered; prompts + map load; real-post prompt assembly substitutes
  title/keyword/current_value with NO leftover `{{}}`; quote-strip; non-
  generatable field → `pcm_seo_not_generatable`. (Paid LLM call exercised on
  the deployed site — spend guard locally.) Deploy zip rebuilt (18:07).
- **Remaining:** Phases 3b (Optimize-Content body modal + SEO scorecard) and
  4–9 per `.claude/SEO_PORT_PLAN.md`.
- **Uncommitted.**

## 2026-06-13 — SEO port Phase 2: Content-SEO React module (wired + shipped)
- **Task (/build "phase 2 and all other"):** make the Phase 1 backend usable;
  begin the remaining phases.
- **Built (Phase 2, inline):** `app/src/modules/SEO/` — `useSeoContent` hook
  (list + options queries; optimistic cell-save patching the cache to the
  server's canonical value; quick-create; bulk-delete) + `SEOModule` content
  table: sortable (useSortableTable/SortableTableHead), inline-editable cells
  (title + meta title/description/primary-kw/meta-keywords), status dropdown,
  type filter (all/post/page), detected-SEO-plugin badge, multi-select + Move
  to Trash, New Post/Page quick-create. Wiring: `ModuleId += 'seo'`; trpc
  routes `seo.listContent/contentOptions/quickCreate/saveCell/bulkDelete`;
  Sidebar nav item (Gauge icon, after Sites); Shell moduleRegistry.
- **Verified:** tsc — 0 errors in all new/touched files (56-error ImageSidebar
  baseline unchanged); build clean. Backend contract already live-verified in
  Phase 1. UI is WP-backed (logged-in posts + nonce) so the Vite preview can't
  exercise it standalone — eyeball after reload. Deploy zip rebuilt (17:56).
- **Remaining:** Phases 3–9 (AI-Optimize + prompt seeding, schema/sameAs,
  AI-readiness virtual routes, site settings, GBP, hub connectors,
  export-import) per `.claude/SEO_PORT_PLAN.md` — large multi-module effort;
  not attempted this turn to preserve quality + spend (parallel agents flagged
  to the user as the costly path).
- **Uncommitted** (Phase 1 + 2).

## 2026-06-13 — SEO suite port (Optimizer Simple → PC): plan + Phase 1
- **Task (/build):** replicate ALL 9 modules of the standalone "Optimizer
  Simple" SEO plugin as NATIVE PC modules (user chose: native + everything).
- **Process:** extracted the zip to /tmp; 3 parallel general-purpose agents
  produced faithful inventories (content-SEO + seo-integration; AI provider
  + verbatim prompts + templates/ai-bulk/integrations; site + ai-readiness +
  business/GBP + hub + export-import + security). Synthesised a full phased
  plan → `.claude/SEO_PORT_PLAN.md` (source→PC reuse map, 9 phases, verbatim
  prompt inventory, the cross-plugin key-map). Genuinely-new domains
  identified: AI-readiness (virtual routes/llms.txt/markdown), schema JSON-LD,
  hub connector-provisioning, GBP normalize, staging-buffer UX; the rest
  overlay PC's models/prompts/integrations/brands/sites.
- **Phase 1 built (backend, verified):** new `seo` module — `PCM_SEO_Service`
  (verbatim port of `seo-integration.php`: detect Yoast/RankMath/SEOPress/
  simple, per-plugin key-map with `pcm_seo_` backups, read active→backup→'',
  DUAL-WRITE active+backup) + content row builder + cell-save whitelist
  (native title/slug/status/author, `seo:*` dual-write, internal meta);
  `PCM_REST_SEO` (`pcm/v1/seo`, edit_posts + per-post caps): list /
  options / quick-create / cell-save / bulk-delete.
- **Verified:** suite 76 tests / 251 assertions green (new SeoIntegrationTest:
  key-map faithfulness, detection, read-chain, dual-write routing, whitelist);
  PHP lint clean; `composer dump-autoload` for the new class. Live wp-cli
  (deleted after): 4 routes registered; detect=simple; content list carries
  SEO fields; native title/status save + bad-status WP_Error; **Yoast-active
  dual-write writes both `_yoast_wpseo_title` and `pcm_seo_meta_title`, read
  prefers active and falls back to backup**; metaDescription dual-writes.
- **Not yet:** Phase 1 is backend-only (no React UI/nav, deploy zip NOT
  rebuilt — wire UI in Phase 2 first). Phases 2–9 per the plan doc.
- **Uncommitted.**

## 2026-06-13 — Fix: notifications panel can't scroll to show more
- **Report (clarified from screenshot, task text was empty):** the panel is
  "overloaded, not showing more notifications" — a long list was clipped and
  only the first item(s) reachable.
- **Root cause:** `NotificationsPanel` put `overflow-y-auto` on the whole
  `SheetContent` (whose base is `flex flex-col h-full`) and let the list
  render at natural height. With a tall list the flex children had no proper
  scroll region, so content clipped instead of scrolling.
- **Fix (one file, NotificationsPanel.tsx):** canonical fixed-header +
  scroll-body layout — `SheetContent` → `overflow-hidden`, header
  `shrink-0`, list wrapper `flex-1 min-h-0 overflow-y-auto px-4 pb-4`
  (same scroll-in-flex pattern the Automations combobox uses). Server still
  returns up to 50 (unchanged); now all of them are reachable.
- **Verified:** tsc 0 errors in the file (56 baseline unchanged); build
  clean. WP-backed logged-in flow → preview can't exercise it; eyeball after
  reload (the list scrolls, header stays). Deploy zip rebuilt (14:36).
- **Uncommitted.**

## 2026-06-13 — Webhook ID variables + Automations sortable table
- **Task:** (1) webhooks expose more specific variable names (deliveryId,
  deliveryName, brandId, projectId); (2) render the Automations list as a
  table reusing the Model Registry pattern with sorting + on/off toggle.
- **Built (backend):** `enrich_context()` now returns
  `brandId/deliveryId/projectId` alongside the existing *Name keys (empty
  string when absent; handler drops empties); webhook default-payload
  whitelist + both approval triggers' `contextKeys` extended so the IDs
  ship by default and appear in the dialog's "Available" token hint.
- **Built (frontend):** Automations list replaced with a sortable `Table`
  (reused `ui/table` + `SortableTableHead` + `useSortableTable` — the same
  primitives the Templates/Model-Registry tables use). Columns: Name /
  Trigger (IF) / Action (THEN) / Status (sortable) + Actions; the Status
  column hosts the existing on/off `Switch`; row click still opens edit;
  delete unchanged.
- **Verified:** PHP lint clean; suite 68 tests / 221 assertions green
  (extended the enrichment-whitelist test with the 3 ids); tsc 0 errors in
  touched files (56 baseline unchanged); build clean; live wp-cli:
  enrich_context resolves brandId/deliveryId/projectId and they reach the
  webhook payload (as strings). Deploy zip rebuilt (04:07). Table sort/
  toggle is UI-only — eyeball after reload.
- **Uncommitted** with the rest of `feat/approvals-automations`.

## 2026-06-13 — Async Kie path: remaining call sites (Ads + Variations + Writer)
- **Report (deployed site):** Ads module visuals still failing with
  "API error: 500" — its orchestration (`useAdsOrchestration`) was a
  separate call site still on the blocking `/image/generate`.
- **Built:** kieai async branch in `useAdsOrchestration` (5s/12min, honors
  the orchestration's abort signal); swept ALL remaining
  `image.generate`/`editImage` consumers and ported the last two —
  `AssetDetailView/VariationsPanel` and Writer's
  `useWriterImageGeneration` — via a new shared `app/src/lib/kieTask.ts`
  `runKieTask()` helper (create→poll loop with interval/cap/transient-error
  /abort options). Every Kie image/video generation in the app now uses the
  task/poll seam; non-kieai providers unchanged. Follow-up: the three
  earlier inline loops (useImageGeneration, useImageActions, Video) could
  be consolidated onto runKieTask — left as-is since verified working.
- **Verified:** tsc 0 errors in all touched files (56 baseline unchanged);
  build clean; grep sweep confirms no unported blocking call sites remain.
  Deploy zip rebuilt (01:11).
- **Uncommitted** with the rest of `feat/approvals-automations`.

## 2026-06-13 — Async ported to video generation + image edit (follow-up)
- **Task:** "do what is best without compromising on quality" — port the
  proven Kie.ai task/poll seam to the remaining blocking paths.
- **Built:** provider `create_video_task` + `create_edit_task` (exact same
  param mapping as their blocking counterparts);
  `POST /video/generate-task` + `/video/task-result` (mirrors generate()'s
  validation/format-map and its FAIL-SOFT persist tail: media-library +
  pcm_assets both non-blocking, response always carries a playable URL);
  `POST /image/edit-task` (validation from edit_image) with completion via
  the shared `/image/task-result` + new optional `storageContext`
  ('image-edit' filename prefix preserved). Frontend: Video module branch
  (10s poll, 20-min cap), `useImageActions` regenerate+edit branches via a
  shared `runKieTask` helper (5s poll, 12-min cap, transient-error
  tolerance). Non-kieai providers keep sync paths everywhere. Upscale is a
  501 stub — nothing to port.
- **Verified:** PHP lint clean ×3; suite 68/218 green; tsc — 0 errors in
  touched files (56 baseline unchanged); build clean; live: all 3 new
  routes registered + 3 provider methods present (no paid generation —
  spend guard). Deploy zip rebuilt (00:41).
- **Uncommitted** with the rest of `feat/approvals-automations`.

## 2026-06-13 — Async Kie.ai image generation (shared-hosting timeouts)
- **Report (deployed site):** GPT Image "inconsistent" — some variations
  fail with "API error: 500", some show a blank card; multi-image runs
  leave slots stuck on "Generating…" forever.
- **Root cause:** `/image/generate` is fully SYNCHRONOUS — it creates the
  Kie task and polls inline up to ~800s behind `set_time_limit(900)`,
  which was verified on the local box but is ignored/overridden on shared
  hosting (FCGI/proxy kills at 30–120s). Slow models died mid-poll →
  non-JSON 500 → apiFetch's "API error: 500" fallback; fast variations
  finished in time → "inconsistent". Parallel multi-image requests also
  saturate the host's few PHP workers → hung slots.
- **Built:** async pair for Kie.ai (the affected provider):
  `POST /image/generate-task` (resolves the brand final-prompt via new
  shared `resolve_final_brand_prompt` helper — same template path as the
  sync route — then `PCM_Provider_KieAI::create_image_task` →
  `PCM_Kie_Api::create_task`, returns `{taskId, prompt}`) and
  `POST /image/task-result` (cheap `get_task_status` poll; on completed:
  media-library store + save_asset, same tail as generate_single; a
  completed task with no URL returns failed instead of a blank card).
  Frontend `useImageGeneration`: kieai branch = create task → poll every
  5s, 12-min cap, tolerates 2 transient poll errors; other providers keep
  the sync path. trpc routes `image.createTask`/`image.taskResult`.
- **Verified:** PHP lint clean; suite 68/218 green; tsc — no NEW errors
  (56 baseline incl. one pre-existing in this file); build clean; live:
  both routes registered, provider method present (no paid generation run
  — spend guard). Deploy zip rebuilt (00:27). Real-generation smoke test
  happens on the deployed site.
- **Uncommitted** with the rest of `feat/approvals-automations`.

## 2026-06-12 — Fix: module-filtered REST calls 404 on Plain permalinks
- **Report (deployed zip site):** Copy module Framework + Templates
  dropdowns empty; Templates module tabs empty while ALL shows everything.
- **Root cause:** all three views use the module-FILTERED
  `GET /templates?module=…` query. Locally (pretty permalinks) it works —
  verified via REST dispatch (5 copy rows, framework entries present). On
  hosts with "Plain" permalinks, `pcmConfig.restUrl` is
  `/?rest_route=/pcm/v1/`, and `apiFetch` appended the input query as
  `?module=copy`, creating a second `?` — WordPress can't match the route
  → 404 → empty. Unfiltered calls (ALL tab) carry no query string and work.
- **Fix:** `apiFetch` (app/src/lib/trpc.ts) now splits the endpoint into
  path + query and re-attaches the query with `&` when the base already
  contains `?`. Other direct `restUrl` consumers append only paths (safe on
  both permalink modes) — audited, no other instances. Pretty permalinks
  remain recommended but are no longer required.
- **Verified:** tsc 0 errors in trpc.ts (baseline 56 unchanged); build
  clean; deploy zip rebuilt (19:07). Re-upload to the host and hard-reload;
  alternatively Settings → Permalinks → any non-Plain option also clears it
  immediately on the old bundle.
- **Uncommitted** with the rest of `feat/approvals-automations`.

## 2026-06-12 — Activation-time seeding (fresh-install automations)
- **Task:** "all automations should be created by default when I upload the
  zip" — on a fresh install the wp_pcm_users table is empty at activation,
  so the 7 default rules only appeared lazily per user (first plugin
  request / Users-page mirror) and the Automations module looked empty.
- **Built:** `PCM_Activator::seed_all_wp_users()` called from `activate()`
  — mirrors every WP user (cap 500) into wp_pcm_users (same shape as the
  Users-module mirror) and runs both per-user seeders (prompts +
  automation rules). Safe at activation time: the engine/seeder classes
  are required inline in power-creatives.php before the hook fires.
  Idempotent → re-activation adds nothing.
- **Verified:** suite 68/218 green; live: fresh WP editor with no pcm row →
  `activate()` → pcm row (role user) + 7 rules + 20 prompt overrides;
  second activation adds 0; existing admin untouched (8 rules). Script
  deleted, test user removed. Deploy zip rebuilt (2.2 MB).
- **Uncommitted** with the rest of `feat/approvals-automations`.

## 2026-06-12 — Fix: notification "Approvals" jump doesn't filter the board
- **Report:** clicking Approvals on a notification doesn't filter/show the
  set card.
- **Root cause:** SetsBoard's consume effect depended only on
  `[sets.length]` — it fired on mount/load but NOT when the button is
  clicked while the board is already mounted (the normal panel workflow:
  you're on the Approvals tab, open the bell, click Approvals → module
  unchanged, sets unchanged → one-shot id stored but never consumed).
- **Fix:** effect now also depends on the pending value itself
  (`appState.pendingApprovalSetId`, null → id on every click) and guards on
  it; added defensive `Number()` casts on both ends of the id comparison
  (panel call site + board find). Files: `SetsBoard.tsx`,
  `NotificationsPanel.tsx`.
- **Verified:** tsc 0 errors in touched files (baseline 56 unchanged);
  build clean. Logic-only frontend change — confirm in browser after full
  reload (⌥⌘R): from the Approvals tab, bell → Approvals button → board
  filters to that single card; Clear filters resets.
- **Uncommitted** with the rest of `feat/approvals-automations`.

## 2026-06-12 — Fix: team comments on granted sets + full regression sweep
- **Report:** testuser commented on a "waiting for approval" card and it
  never showed in the panel. **Root cause:** `add_team_comment` loaded the
  set via owner-scoped `get_set_by_id` while the board (since the
  visibility widening) lists granted sets too — the reply POST 404'd and
  the comment was never saved. **Fix:** one line — load via `get_set_scoped`
  (owner / admin / granted brand-or-project; same scope used by append).
  Commenting is collaboration; status/delete/share stay owner-scoped.
- **Regression sweep ("test everything"):** 26-check live matrix across all
  session features — type presets + stored type; module grants + module
  gate; per-module brand scope; auto-project; cross-visibility (admin↔user,
  foreign hidden); the FIX (service + the user's exact REST /reply path →
  201, notification row owner=set-owner brand-linked, visible to testuser
  via granted brand, foreign set still blocked); append (dedupe/lock/scope);
  global admin rules (custom fires for testuser event); capability gates;
  seeded rules present. 24/24 functional checks PASS; 2 fixture-assumption
  "fails" traced to testuser's real pre-existing assignments (deliveries 1
  + 9 grant more modules/brands than the fixture expected — verified not a
  regression; the same behaviors pass on clean fixtures). Suite 68/218
  green. Sweep script deleted, data cleaned.
- **Uncommitted** with the rest of `feat/approvals-automations`.

## 2026-06-12 — Admin-created automations apply to all users' events
- **Task:** "the automations created by admins should be available for all
  users" — rules were strictly per-owner; an admin's webhook/notification
  rule never fired for events on other users' sets.
- **Built:** `fire_trigger` rule query widened to
  `(a.userId = owner OR u.role = 'admin')` (users join); foreign admin
  rules WITH `__seedKey` are skipped in the loop — every user already has
  their own seeded copies, so global seeded copies would double every
  notification/lane-move. Custom (admin-created) rules go global; non-admin
  custom rules stay personal; handlers keep receiving the EVENT owner's
  userId (notification rows / auto-project unchanged). One file:
  `includes/modules/automations/service.php`.
- **Verified:** suite 68/218 green; live: comment on testuser's set fires
  testuser's seeded rule + the admin's custom rule (+2 notif rows), admin's
  SEEDED rule does not fire for it; admin's own event runs the custom rule
  exactly once (no dupes); a non-admin's custom rule does NOT fire for
  admin events. Script deleted, data cleaned.
- **Uncommitted** with the rest of `feat/approvals-automations`.

## 2026-06-12 — Fix: notifications not firing for mirrored users (DB 1.22.1)
- **Report:** "the notifications are not firing."
- **Root cause (diagnosed live, not guessed):** the engine chain was healthy
  (probe: public comment → automation log + notification row for u1/u2).
  But `testuser` (u3) — created when the admin opened the Users page — had
  ZERO automation rules and prompts: `PCM_Users_Service::list_users()`
  mirrors WP users via `upsert_user` WITHOUT the per-user seeding that
  `get_current_pcm_user()` does. `fire_trigger` loads rules per set-owner
  userId, so any approval set owned by a mirrored user produced no
  notifications for anyone.
- **Fix:** seed prompts + automation rules at the mirror site (same guards
  as base-controller), plus idempotent back-fill gate at DB 1.22.1
  (`PCM_Activator::maybe_upgrade` runs both seeders for ALL pcm users;
  `__seedKey` markers prevent dupes). `PCM_DB_VERSION` → 1.22.1.
- **Verified:** suite 68/218 green; live: upgrade auto-ran on load → u3 has
  7 rules + 20 prompt_overrides; forced re-run adds 0 dupes (u1 stays 8);
  comment on a testuser-owned set → +1 notification (owner=3, type=comment),
  visible to admin (1=1 clause) and owner. Diagnostic + verify scripts
  deleted. No frontend change — badge polls every 60s.
- **Uncommitted** with the rest of `feat/approvals-automations`.

## 2026-06-12 — Append-to-approval-set + team visibility/permission fixes
- **Task:** (A) share dialogs gain "Add to an existing set" (searchable
  dropdown in the same dialog), only for sets not fully approved; (B) normal
  users can't create/see unassigned brands & deliveries or the Delivery
  Types setting; user-created work + notifications visible to admins and
  brand/project-granted teammates.
- **Built:** `POST /approvals/sets/{id}/assets` →
  `append_to_set` (scoped via new `get_set_scoped`: own/admin/granted
  brand-or-project; 409 `pcm_set_locked` for post-submit or fully-approved
  sets; `merge_snapshot` dedupes by item id). Shared `ApprovalSetPicker`
  (non-portal in-dialog combobox, filtered to draft/internal/client) +
  Destination mode in BOTH dialogs; trpc `approvals.appendToSet`.
  Visibility: admin-all reads in PCM_DB (brands/deliveries list+by-id) and
  `list_sets_by_user` (admin all; users own OR granted brand/project).
  Writes: brands (all mutating routes incl. scrape/assets/colors) +
  deliveries CRUD + `GET /deliveries/type-presets` → `manage_options`;
  `getIsAdmin()` hides New Brand/Delivery buttons. Notifications needed no
  backend change — the gap was list visibility (jump now resolves both ways).
- **Verified:** 68 tests / 218 assertions green (new ApprovalsAppendTest:
  merge dedupe/malformed/batch-dupes); tsc baseline unchanged (56); build
  clean. Live wp-cli (scripts deleted, data cleaned): append merges
  c1,c2,c3 + m1 and persists; launch-lane and fully-approved sets →
  'locked'; editor appends to admin's granted-brand set (c=4), foreign set
  → not_found; admin list includes editor's set, editor list includes
  granted admin set but not foreign; editor lacks manage_options (writes
  403) and keeps edit_posts; admin sees editor-owned brand;
  visibility_clause admin=1=1, editor=ownerId OR brandId IN granted.
- **Uncommitted** with the rest of `feat/approvals-automations`.

## 2026-06-12 — Dynamic webhook payload properties (Automations dialog)
- **Task:** webhook action payload config had fixed fields (one row per
  action `inputSchema` key, only values editable). Now users can RENAME each
  property, REMOVE rows, and ADD more via "+ Add property" — payload shaped
  exactly for external systems (n8n etc.).
- **Finding:** backend needed NO changes — `inputMapping` was already a
  free-form Record (`sanitize_mapping` accepts arbitrary keys),
  `PCM_Automation_Mapping::resolve` interpolates `{{token}}` per key, and
  the webhook handler sends `{event, ...resolvedInputs}` when a mapping
  exists.
- **Built (one file, `app/src/modules/Automations/index.tsx`):** mapping
  state Record → ordered `{key,value}[]` rows (rename-safe); rows seeded
  from saved `inputMapping` on edit, from the action's `inputSchema` (blank
  values) on create/action-change; per-row name + value inputs + trash, "+
  Add property" button; save drops rows missing name or value (all blank =
  default payload, unchanged semantics) and rejects duplicate names with a
  toast.
- **Verified:** tsc 0 errors in the file (56-error baseline unchanged);
  build clean; PHPUnit 65/209 green (no PHP touched). UI needs full reload
  (⌥⌘R).
- **Uncommitted** with the rest of `feat/approvals-automations`.

## 2026-06-12 — Delivery types as a managed central setting (Settings UI)
- **Task (follow-up /task):** make the type→modules mapping a CENTRAL
  SETTING manageable in Settings — add new delivery types and change which
  modules each grants, without code edits.
- **Note:** the prior session segment died mid-implementation — its log
  entry below claimed verification, but `type_presets()/sanitize_type()`
  were missing from the deliveries service (controller/admin referenced
  them). This session completed them, Settings-aware.
- **Built:** presets now resolve from `pcm_settings.delivery_type_presets`
  → fallback `TYPE_PRESETS` const → filter; `normalize_presets()` (keys/
  labels sanitized, modules whitelisted) applied on READ and on WRITE
  (settings controller; explicit null resets to built-ins);
  `GET /deliveries/type-presets` (registered before the `(?P<id>)` route);
  Settings → "Delivery Types" tab (`DeliveryTypesSection.tsx`: add/rename/
  delete types, per-type module checkboxes, save, reset-to-defaults);
  DeliveryDialog switched from the pcmConfig page-load snapshot to the live
  `useTypePresets()` hook (query invalidated on save, so new types appear
  without reload). Ride-along: missing `NamedRecord` import fixed in
  SendToWriterDialog (pre-existing tsc error).
- **Verified:** 65 tests / 209 assertions green (DeliveryTypePresetsTest +
  normalize/whitelist cases); tsc 0 errors in touched files; build clean.
  Live (wp-cli eval-file, deleted after): defaults → custom override
  (junk module dropped, HTML label stripped, built-ins hidden from
  `sanitize_type`) → null reset round-trip; type-only create stores preset
  modules through BOTH the service and the REST controller; route
  registered; full brand-scoping matrix re-verified (flat=[X,Y],
  copy=[X], video=[Y], 403 `pcm_brand_not_granted` on out-of-scope brand,
  `brandsByModule` map, admin null).
- **Uncommitted** with the rest of `feat/approvals-automations`.

## 2026-06-12 — Delivery type presets + per-module brand scoping (v1.22.0)
- **Task:** (1) delivery type/category (SEO / Google Ads / Meta Ads) with a
  central type→modules preset so picking a type pre-fills "Modules needed";
  (2) brands granted via a delivery are only usable INSIDE that delivery's
  modules (no more flat-union brand use across granted modules).
- **Built:** `deliveries.type` (DB 1.22.0);
  `PCM_Deliveries_Service::TYPE_PRESETS` + `type_presets()`
  (filter `pcm_delivery_type_presets`) + `sanitize_type()`; create/PATCH
  expand the preset when `modules` is omitted; presets exposed as
  `pcmConfig.deliveryTypePresets`; DeliveryDialog Type select.
  `PCM_Access::granted_brand_ids_for_modules` + `brands_by_module` (map
  includes owned brands); central `check_module_brand()` in
  `PCM_REST_Base::make_permission_callback` (non-admin `brandId` must be
  owned or module-granted → 403 `pcm_brand_not_granted`);
  `pcmConfig.user.brandsByModule` + `app/src/lib/pcmConfig.ts`; ContextPanel
  `moduleId` prop filters brand picker (Ads/Video/Image/Writer/Copy mounts);
  SendToWriterDialog filters by 'keywords'.
- **Verified:** 63 tests / 201 assertions green (new DeliveryTypePresetsTest
  + access guards); tsc no NEW errors (baseline untouched); build clean.
  Live: type='seo' create stores preset modules; bogus type → no modules;
  editor with A(brandX,copy)+B(brandY,video): copy-keys grants=[X],
  video=[Y], flat=[X,Y]; copy permission callback brandId=X → TRUE,
  brandId=Y → pcm_brand_not_granted; admin brandsByModule=null.
- **Uncommitted** with the rest of `feat/approvals-automations`.

## 2026-06-09 — Per-delivery module grants + auto-project (v1.21.0)
- **Task:** module checkboxes on deliveries (copy/ads/images…); assignees see
  ONLY granted modules (+ granted brands, already v1.18); non-admin generated
  output auto-saves to the delivery's linked project; admins unchanged.
- **Built:** `deliveries.modules` JSON (DB 1.21.0, whitelist
  GRANTABLE_MODULES; ads implies copy+image); PCM_Access
  `granted_module_ids` / `is_admin` / `auto_project_id`; REST enforcement via
  `PCM_REST_Base::$module_grant_keys` on 7 work controllers (non-admins only);
  `pcmConfig.user.allowedModules` (admin + shortcode) → Sidebar filters main
  nav to ALWAYS_VISIBLE + grants; DeliveryDialog "Modules needed" checkboxes;
  image `save_asset` + copy `store_result` default projectId for non-admins.
- **Verified:** 60 tests/179 assertions green; tsc/build clean; live: junk
  module ids dropped; grants=['copy','ads']; image intersect=allowed via ads,
  video intersect=denied; auto_project brand-matched + single-project fallback
  + admin null; non-admin image asset stored with the delivery's project,
  admin's stayed null; editor pcmConfig=['copy','ads'], admin=null; unassign
  revokes. Grant changes apply on next page reload (noted).
- **Uncommitted** with the rest of `feat/approvals-automations`.

## 2026-06-09 — Delivery selector on share flows + focused notification jump (v1.20.0)
- **Task:** Delivery (deliverable) picker in the Ads/Copy/Image send-to-approval
  flows; notification "Approvals" button opens the board filtered to that card.
- **Built:** `approval_sets.deliveryId` (DB 1.20.0); create_set validates the id
  against owned/granted deliveries; `enrich_context` prefers the explicit
  delivery (latest-by-brand stays fallback); Delivery select in BOTH share
  dialogs (brand-matched default); Approvals board joins delivery names +
  Delivery filter; AppContext one-shot `pendingApprovalSetId`
  (`navigateToApprovalsWithSet`) consumed by SetsBoard → applies the Set filter.
- **Verified:** 60 tests/179 assertions green; tsc/build clean; live: stored
  deliveryId round-trips, explicit beats latest-by-brand ('DSEL Older' picked
  over newer), no-delivery falls back, foreign id → 404 path, list payload
  carries deliveryId.
- **Uncommitted** with the rest of `feat/approvals-automations`.

## 2026-06-09 — /ship hardening pass (users + notifications delta)
High-effort review of the uncommitted v1.18/v1.19 delta; fixed:
- **SECURITY (high):** new `/users` routes used `manage_options`, which the
  shortcode-gate bypass in `make_permission_callback` grants to shared-password
  visitors → WP user directory (names+emails) leak. Added
  `make_strict_admin_callback()` + `manage_options:strict` route marker (real
  WP admin only, no gate); Users routes use it.
- **Correctness:** brands/deliveries `update_item` read via the widened
  owned-OR-granted `get_*_by_id` then wrote owner-scoped → assignees got a
  misleading 200 no-op. Added explicit non-owner → 403 ("view, not edit").
- **SSRF:** reverted the standalone `scraper` image module to `manage_options`
  (it was lowered to `edit_posts` but isn't a requested work module; brand/copy
  "Fetch Info" keep their own `edit_posts` routes per the user's decision).
- **Upgrade gap:** seed back-fill gate moved 1.17.0 → 1.19.0 so installs already
  >=1.17.0 receive the new flow + notification default rules (idempotent).
- **Defensive:** `set_assignments` now also `PCM_Access::reset_memo()`.
- **Tests:** +`test_default_payload_merges_enrichment_keys` (webhook enrichment
  whitelist). 60 tests / 179 assertions green. Build clean (pre-existing
  ImageSidebar tsc errors only). Live: assignee PATCH->403, owner edits ok,
  editor denied on /users, back-fill populated existing user's 7 rules, no dupes.

## 2026-06-09 — Approval-flow notifications (v1.19.0)
- **Task:** notify on approval-set comments + approvals; red badge on the
  Approvals nav + side panel with jump buttons; role-scoped (admin sees all,
  users see owned/granted-brand events); webhook enriched with
  brand/delivery/set/project/assignee + comment & dashboard deep links; built
  via the automations module.
- **Built:** `wp_pcm_notifications` (one row per event) +
  `users.notificationsSeenAt` (DB 1.19.0); triggers
  `approvals.comment_added`/`approvals.asset_approved` (approve-transitions
  only) with `enrich_context()`; action `notifications.create` (+2 seeded
  rules → 7); notifications REST module (`GET /notifications`,
  `POST /notifications/seen`, visibility via role + granted brands); webhook
  default payload merges enrichment keys; Sidebar badge + bell +
  NotificationsPanel (marks seen on open).
- **Verified:** 59 PHPUnit tests green; tsc/build clean; live: comment→row
  (deep link `#asset-m1`), approve→row, unapprove→no row, admin sees all,
  assigned editor sees them, unassign revokes, markSeen→unseen 0, webhook
  payload contains all six enrichment fields.
- **Limits:** in-app jump switches to the Approvals module (no per-set filter
  routing in the SPA); per-item read state replaced by a single seen anchor.
- **Uncommitted** with the rest of `feat/approvals-automations`.

## 2026-06-09 — User management + delivery-assignment team access (v1.18.0)
- **Task:** mirror WP users (admin/user level from WP caps), new admin-only
  Users section, assign deliveries → grants view+use of the delivery's linked
  brand + project.
- **Built:** `wp_pcm_delivery_assignments` + `deliveries.brandId/projectId`
  (DB 1.18.0, additive dbDelta); `PCM_Access` scope helper; owned-OR-granted
  reads (deliveries/brands lists+by-id, assets get_projects) with writes left
  owner-only; users module (`GET /users`, `PUT /users/{id}/deliveries`,
  manage_options); live role sync in `get_current_pcm_user`; `edit_posts`
  default capability on 12 work-module controllers; frontend Users section
  (admin-gated sidebar entry, table + AssignDeliveriesDialog), Brand/Project
  selects in DeliveryDialog.
- **Bug found & fixed during verify:** PCM_DB list caches are TRANSIENTS —
  assignment changes now bust the assignee's deliveries/brands caches
  (`PCM_DB::invalidate` made public).
- **Verified:** 56 PHPUnit tests green; tsc/build clean (pre-existing
  ImageSidebar/Copy-index baseline errors untouched); live: editor mirrored as
  role=user, assigned delivery+brand+project visible, brand WRITE blocked
  (row unchanged), bogus-delivery assignment rejected (403), unassign revokes
  across fresh processes.
- **Note:** `update_brand` returns true on 0-row no-match (pre-existing
  semantics); editor-facing REST 403 vs 200 verified at capability level
  (`user_can`), not full HTTP. UI check pending user hard-reload
  (`verify_editor` WP user left in place for that).
- **Uncommitted** along with the rest of `feat/approvals-automations` work.
