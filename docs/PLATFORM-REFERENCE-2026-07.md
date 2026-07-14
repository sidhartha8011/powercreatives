# Power Creatives — Internal Platform Reference (July 2026)

> Internal document. Technical identifiers, file paths, and honest maturity notes included.
> Every claim below was verified against the codebase on 2026-07-11 (plugin v1.7.0, DB v1.39.0).
> Companion client-facing document: `docs/CASE-STUDY-power-creatives.md`.

## Architecture at a glance

WordPress plugin (PHP 8.1+) wrapping a React 18 / TypeScript (Vite) SPA. **Vertical-slice
modular monolith**: 22 backend modules auto-discovered from `includes/modules/*/config.php`,
shared infrastructure in `includes/core/`, 20 frontend workspaces under `app/src/modules/`.

| Metric | Value | Source |
|---|---|---|
| Backend modules | 22 | `ls includes/modules/*/config.php` |
| Frontend workspaces | 20 | `ls app/src/modules/` |
| Database tables | 27 | `CREATE TABLE` count in `includes/core/db/class-pcm-schema.php` |
| REST route declarations | 278 (~250 real endpoints) | grep across `includes/modules/*/controller.php` |
| PHP | 127 files / 49,161 lines | `find includes -name '*.php'` |
| TypeScript/TSX | 353 files / 76,027 lines | `find app/src` |
| Automated tests | 291 (PHPUnit; process-isolated where fakes share real class names) | `vendor/bin/phpunit --list-tests` |
| Distinct AI-powered features | ~24 | AI inventory below |
| AI providers | 5 (OpenAI, Google/Gemini, Anthropic, Kie.ai, Fal.ai) | `includes/core/llm/`, `includes/core/providers/` |
| Third-party integrations | 9 (the 5 above + Brevo, Google Search Console, ProRankTracker, Ahrefs) | `includes/modules/integrations/`, `includes/core/class-pcm-gsc.php` |
| Registered media models | 60+ (58 Kie.ai seeds + ~6 Fal.ai + OpenAI/Google) | `core/fal/fal-models.json`, Kie seeds |

**Security contract (enforced centrally in `includes/core/base-controller.php`)**: every route
gets nonce (`X-WP-Nonce`) + capability + module-grant + gate-auth checks via
`make_permission_callback()`. Capability variants: `manage_options`, `manage_options:strict`
(real WP admin only), `manage_options:coadmin` (WP admin OR platform admin). Public routes
(client review) are explicit and per-IP rate-limited. All SQL through `$wpdb->prepare()`;
responses through `$this->success()/error()/not_found()`.

**Data hierarchy** (`includes/core/class-pcm-hierarchy.php`): Brand → Delivery → Project →
work items. Brand/delivery are derived **live** up the chain (never copied), so reassigning a
project cascades everywhere. Sites are a publishing *target* (`projects.siteId`, N:1), not a
hierarchy level.

**Migrations**: additive `dbDelta` with versioned inline schema comments; bespoke `migrate_*`
methods gated by `version_compare` in `PCM_Activator::maybe_upgrade()`; all idempotent
(they run on every page load). Current DB version 1.39.0 across 19+ documented migration gates.

---

## 1. Approval workflow (`includes/modules/approvals/`)

**Approval set** = row in `approval_sets` with a frozen JSON `snapshot` of the work under
review in 4 buckets (media / copy / articles / custom cards), linked live into the
Brand→Delivery→Project hierarchy. Sets can be appended to (deduped by item id) until
submitted.

**6-stage lifecycle** (`PCM_Approvals_Service::STATUSES`): `draft → internal → client →
launch → live → archived`, with a `LEGACY_STATUS_MAP` preserving 4 generations of taxonomy
migrations (v1.8→v1.11).

**Client review — public, no login**: each set gets a `set_ + bin2hex(random_bytes(16))`
token; share URL = shortcode page + `?pcm_public_token=`. Public routes (permission
`__return_true`, per-IP rate-limited in-handler): get set, review, draft, comment, approve,
per-asset ops. The client can approve/unapprove per item or approve all, comment per asset,
save a draft, and submit — full approval auto-advances the set to `launch` via the
`approvals.set_fully_approved` trigger.

**Comments** (`reviewFeedback.comments`): threaded per asset —
`{id, author, text, createdAt, status(New|Team reply|Done), parentId, attachments[], readBy[]}`.
Team replies are authenticated + view-scoped; commenting stays open after sign-off.

**Invites**: `share_set()` persists `clientEmail` (backfills the brand for autofill), fires
`approvals.set_shared`, and the Brevo email channel sends the `client_invite` template.
Entering `client` stamps `clientSentAt` — the anchor for the reminder scanner.

**Honest gaps**: no asset versioning/revision history; audit relies on the automation log
rather than a dedicated approvals audit table.

## 2. Automation engine (`includes/modules/automations/`)

Two paths in `PCM_Automation_Engine`:
1. Legacy `dispatch(event…)` — 5 canonical events fan out to channels.
2. `fire_trigger()` — the user-editable **trigger → condition → action** model with per-rule
   `inputMapping` resolved against context (`PCM_Automation_Mapping`).

- **11 registered triggers** (8 functional: 6 approvals + `brands.brand_created` +
  `strategy.completed`; 3 declared "coming soon"), **9 actions** (5 functional: webhook,
  `email.send`, `notifications.create`, `approvals.move_to_lane`,
  `strategy.publish_on_approval`; 4 stubs). Each module registers its own in
  `includes/modules/{id}/automations.php`, loaded by glob.
- **2 channels**: HMAC-signed webhook + Brevo transactional email (per-user key, rule-level
  sender override).
- **Async seam**: handlers declare `mode()`; async ones schedule via
  `wp_schedule_single_event` (currently dormant — the only real handler is sync).
- **Dedupe**: `automation_logs.dedupeKey` with two modes (sent-only-blocks vs any-status) —
  keeps transient failures retryable while making cron loops idempotent.
- **Daily reminder scanner** (`pcm_automation_check_pending_approvals`): finds sets stuck in
  `client` ≥ minDays (default 3), fires on a cycle-anchored dedupe key
  (`pending:set:{id}:rule:{id}:day:{cycle}`) so missed cron days catch up without double-sends.
- **9 seeded default rules** per user (idempotent via `__seedKey`): sent-to-client lane move,
  comment/approval notifications, fully-approved→launch, publish-on-approval, launch webhook,
  2 pending-client reminders (email + webhook), strategy-completed notification.
- **Log** (`automation_logs`): rule id, trigger/event, channel/action, target, dedupeKey,
  **sha256 payload hash** (raw payload never stored), status, httpCode, error.

**Honest gaps**: condition matching is string-equality only; 3 triggers + 4 actions are inert
stubs; cross-module reach beyond Approvals is small.

## 3. SEO module (`includes/modules/seo/` — the largest slice)

~6,100 LOC PHP (9 files) + ~4,800 LOC TS (16 files). **74 REST routes** — the largest
controller in the plugin.

**Content workbench**: Airtable-style grid over posts/pages — **24 toggleable columns**, 4
AI-editable meta fields (metaTitle, metaDescription, primaryKeyword, supportingKeyword…),
click-to-edit inline cells, header ✦ bulk-generate (fill a whole column via template), saved
views, quick-create, bulk delete/duplicate. Built on a deliberate raw-`<table>` + shared grid
class pattern (`seo-table.tsx`) — the house style for new tables (NOT the TanStack DataTable).

**Cross-plugin core**: detects Yoast / Rank Math / SEOPress / built-in and reads/writes each
plugin's **native meta keys**, so edits round-trip regardless of the client's SEO stack.

**Google Search Console** (`includes/core/class-pcm-gsc.php`, zero-dependency): two auth
modes (service-account JWT signed locally via openssl, or OAuth "Connect with Google");
token caching + 401/429 retry; property matching (sc-domain > url-prefix > www variant);
auto add-property → META token → verify pipeline pushed through the site connector. Pulls
per-page clicks / impressions / CTR / avg position / top-5 queries (28-day default) with URL
normalization to join GSC pages to rows.

**Rank tracking**: ProRankTracker (X-TOKEN auth) — current/yesterday/week/month ranks +
volume; the grid shows GSC position and PRT rank side-by-side.

**Also in the module**: link scanner (internal/external/broken with HTTP checks + editable
links), heading editor + AI heading optimize, body-optimize modal, schema.org picker with a
JSON-LD renderer (6 types: Article, WebPage, BreadcrumbList, FAQPage, HowTo, Product),
**AI-readiness / llms.txt** (virtual `/llms.txt`, `/llms-full.txt`, per-page `/{slug}.md`
with a page-builder-aware HTML→Markdown converter), site-level settings (robots.txt,
LocalBusiness JSON-LD) with restorable backups, Google Business Profile data for prompt
variables, config export/import, and a full parallel set of `remote_*` routes to inline-edit
a **connected site's** posts through the connector.

**Honest gaps**: GBP goes through an n8n webhook placeholder, some `optimizer_*` legacy
naming, stale "Phase 1" language in config.

## 4. Strategy module (`includes/modules/strategy/` — 13 routes)

The bulk-content engine, brought to full AutoPress parity July 2026 (100+ features audited).
Keywords → strategy → per-keyword articles → publish, with:

- **Generation**: per-item or consolidated batch via `PCM_LLM::invoke_json` (strict JSON
  schema, template + brand context), background queue over wp-cron with atomic item claims,
  optional **live SERP research enrichment** (Gemini grounded search), **featured-image
  generation**, and **in-content images & charts** (`[IMAGE_N]`/`media_assets` convention,
  QuickChart for charts).
- **Scheduling**: drip modes (daily/weekly/…) computed as per-item due dates; daily scan
  arms only due items; native WP `status:'future'` scheduling; a cross-strategy Schedule
  view; per-item due-date editing; **per-site recurring schedules** (rules in the
  `pcm_site_schedules` option → daily scan → `PCM_Topic_Suggester` → new strategy).
- **Interlinking**: phrase-match injection between the batch's own articles with tag-safety
  rails (`find_safe_occurrence` / `is_inside_html_tag` — never inside markup or existing
  anchors), manual anchor→URL rules, per-article caps, AI anchor fallback (LLM proposes a
  verbatim phrase, re-validated through the same rails), parent/child hierarchy links with
  anchor-keyword override.
- **Operations**: pause/resume (honored by every scan), duplicate, WP status pull-sync,
  bulk selection bar, search/filter/sort, per-item overrides (template/mode/approval),
  volume/difficulty metrics carried from Keyword Explorer, publish-on-approval automation
  handler, completion trigger + seeded notification.

## 5. AI platform (core + 7 consuming modules)

**Text gateway** (`includes/core/llm/class-pcm-llm.php`): OpenAI, Gemini (OpenAI-compat
endpoint), Anthropic (native adapter). **Structured-output tiering**: `json_schema` strict →
`json_object` → prompt-only, with per-model rejection memory and output-token-cap memory
(transients) so failing tiers are skipped on later calls. **Grounded search** = Gemini native
`google_search` tool. **SSE streaming** for the Writer. Per-user API keys from the
`integrations` table (keyed by **PCM user id** — all generation paths thread the owner id,
which is what makes cron-driven generation work).

**Media registry** (`includes/core/providers/`): `PCM_Provider_Registry::get(provider,key)`
→ `generate_image()/generate_video()`. OpenAI (gpt-image-1 family with b64→`wp_upload_bits`
persistence), Google (Veo 2/3), **Kie.ai marketplace** (58 seeded models, create-task+poll),
**Fal.ai** (~6 models). Provider routing is data-driven (`model.provider`);
`detect_provider()` deprecated.

**Feature inventory (~24)**: Copy (audiences, grounded audience research, angles,
audience-aware angles, ad/organic copy gen, URL business-info scrape) · Image (concepts,
prompt suggestions, context suggestions, brief optimization, generation, edit/upscale) ·
Video (concepts, prompt enhance, compose-prompt, text/image→video) · Writer (SSE article
generation with media manifest, AI Review with surgical apply) · Strategy (bulk generation,
topic suggester, SERP research, in-content media) · Scraper (vision analysis with quality
scoring, smart creative selection) · SEO (AI-readiness summaries, heading/meta optimize).

**Prompt & template system**: 20 seeded prompt variants (copy 7, ads 7, video 3, image 1,
writer 2) in `prompt_overrides`, fully user-editable with a placeholder registry
(`{{creativeBrief}}`, `{{copyFramework}}`…) that idempotently injects new placeholders into
already-customized prompts at named anchors. **12 seeded creative templates** (6 video
frameworks, 5 copywriting frameworks incl. AIDA/PAS/Hook-Story-Offer, 1 SEO pillar article).
Custom model registration via the models module (`pcm_models` + capability overrides).

**Honest gaps**: media registry has no Anthropic implementation; `manus` exists as metadata
only; verbose error_log noise in the Kie client; grounding is Gemini-only.

## 6. Sites & publishing (`includes/modules/sites/`)

- **Connection**: Application Passwords stored AES-256-CBC encrypted (key from
  `wp_salt('auth')`), or the **connector plugin** one-paste pairing flow. Remote calls use
  `?rest_route=` (works on plain-permalink/LiteSpeed hosts) with Basic auth.
- **Connector fleet management** (with the `seohub` module): HMAC-SHA256 registration
  handshake with nonce replay protection (`seo_hmac_nonces`), connector zip generation, and
  **push self-update** (`/pcm-conn/v1/update-now`) across every connected site at once.
- **Publish pipeline** (`publish_to_site`) — each enrichment failure-isolated so publish
  never fatals: remote find-or-create tags/categories → JSON-LD schema embed (outgoing only,
  skip if present) → in-content media sideload (AI images + QuickChart charts become local
  media, src rewritten) → featured image sideload with alt/title → Yoast meta → native
  future-post scheduling → write-back of publishedUrl/postId; plus remote post-status
  reconciliation (detects deletions/unpublishes).
- **GSC provisioning on site-add**: add property → META token → token pushed to connector →
  verify, best-effort with retry route.
- **Per-site recurring content** ("Auto" dialog): see Strategy §4.

## 7. Agency infrastructure

- **Brands** (`includes/modules/brands/`): brand profiles with asset **role contract**
  (logo/certification/reference), color extraction, **onboarding from a URL** via the
  zero-dependency DOM scraper (`class-pcm-website-scraper.php`: images ≥30px sorted by
  resolution, brand colors from CSS custom props/styles, title/meta/h1) with optional LLM
  enrichment, domain-dedupe, duplication.
- **Deliveries** (`includes/modules/deliveries/`): admin-owned client engagements on a
  Kanban board (active/paused/completed), **type presets** mapping engagement type → module
  grants (seo → writer/keywords/strategies/sites; ads → ads/copy/image), assignee lists with
  a lead, delivery logs.
- **Projects/Assets**: project CRUD lives in the assets controller (known smell — no
  dedicated module); assets support refine/variations/export/save-to-project.
- **Access model**: WP users mirrored into `pcm_users` (`openId = wp_{ID}`), roles
  admin/user, per-delivery **module grants** + brand scoping resolved by
  `class-pcm-access.php` (SQL scope clauses, memoized admin check). **Shortcode gate**
  (`class-pcm-gate-auth.php`): platform users (bcrypt hash, no WP account needed) log into
  the `[power_creatives]` frontend; HMAC-signed cookie (7-day default), WP admins bypass.
- **Keywords** (`includes/modules/keywords/`): Google Autocomplete suggestion mining
  (batched) + Ahrefs volume/difficulty enrichment; metrics flow onto strategy items.
- **Notifications / Settings / Integrations / Models / Prompts / Templates / Logs**: in-app
  notification center with brand scoping; global settings; credential vault; model registry;
  prompt overrides; creative templates; automation log viewer.

## 8. Quality engineering notes

- **Tests**: 291 PHPUnit tests, process-isolated where fakes share real class names
  (`@runTestsInSeparateProcesses` + `class_exists(..., false)` guards), shared fake library
  reused across suites. One known unrelated invariant failure tracked separately.
- **Real-API/real-WP smoke discipline**: features exercised against live OpenAI/Gemini and a
  symlinked local WP install caught 3 classes of provider-contract bugs unit fakes couldn't
  (strict-schema shapes, response_format wrappers, b64 image persistence).
- **Frontend conventions**: tsc gate on every change (pinned baseline), `Number()`
  normalization of wpdb string ids, `flushSync` on DnD optimistic updates, hooks-order
  discipline (memo-before-early-return).
- **Known debt** (tracked): scraper module carries the pre-wrapper LLM schema bug (task
  chip open); condition matching is equality-only; per-site schedule rules in an option
  rather than a table; binary roles (admin/user) with grants emulating finer RBAC;
  salt-derived encryption has no re-encryption path if salts rotate; docs drift
  (CLAUDE.md "18 modules" vs 22 actual).
