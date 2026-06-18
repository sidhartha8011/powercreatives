# Power Creatives — Codebase Map
_Last updated: 2026-06-14 · verified current 2026-06-18_

> A WordPress plugin (PHP 8.1+) wrapping a React/TypeScript SPA. AI-powered
> creative generation: copy, images, video, brand management, client approval
> boards. Vertical-slice modular monolith. Repo default branch: **`image-features`**
> (remote `origin/HEAD`); **active development branch is `feat/seo-suite-port`**
> (SEO suite work — branch off this for SEO changes). Other branch:
> `feat/approvals-automations`.
>
> **Machine note:** the *Local WordPress* section below has TWO machine-specific
> setups — a Windows dev box (`sanky`, Local by Flywheel) and a **Mac** (this
> session, `/Users/sidharthaparasramka/Desktop/wordpress-local`, PHP built-in
> server on **`localhost:8080`**). Live in-browser verification IS available on
> the Mac (confirmed 2026-06-18). Either way, also run `composer test` +
> `cd app && npm run check && npm run build`.

## Plugin header
| Field | Value |
|---|---|
| Name / slug / text-domain | Power Creatives / `power-creatives` |
| Main file | `power-creatives.php` |
| Version (`PCM_VERSION`) | **1.7.0** |
| DB version (`PCM_DB_VERSION`) | **1.26.0** (separate from plugin version) |
| Requires WP / PHP | 6.4+ / 8.1+ |
| Const prefix | `PCM_` |
| Composer package | `antigravity/power-creatives` (type `wordpress-plugin`) |

## Stack & build (dual stack)
- **Backend:** PHP 8.1+, WordPress REST API under `pcm/v1/*`. No PSR-4 — Composer
  **classmap** over `includes/`. Class naming `PCM_Like_This`, files
  `class-pcm-{name}.php` (core) / `controller.php`,`config.php`,`service.php` (modules).
- **Frontend:** React 18 + TypeScript + **Vite** SPA in `app/`, mounted into WP
  admin (and a frontend shortcode). Radix/shadcn UI, TanStack Query/Table, TipTap,
  Jotai (Writer). State via custom hooks; "dumb UI + hooks" convention.
- **DB:** custom `wp_pcm_*` tables via `dbDelta()` (additive only).

## Run / test / lint / build commands
```bash
# PHP tests (PHPUnit 9.6 + Brain Monkey + wp_mock + Mockery)
# NOTE: needs PHP 8.1–8.2 (wp_mock requires <8.3). composer.json was aligned to
# phpunit ^9.6 (wp_mock 1.x is incompatible with phpunit 10). 42 tests, all green.
composer install
composer test                 # phpunit  (config: phpunit.xml.dist, tests in tests/)
composer test:coverage        # phpunit --coverage-html tests/coverage

# Frontend (run from app/)
cd app && npm install
npm run build                 # vite build --config vite.config.wp.ts  → app/dist/
npm run build:watch
npm run dev
npm run check                 # tsc --noEmit  ← run this after every TS change (tech-debt #15)
```
There is **no PHP linter configured** (no `phpcs.xml`); follow WPCS conventions by hand.

## Local WordPress (LIVE verification IS available)
> **Mac dev box (this machine — confirmed live 2026-06-18).** WP **7.0** / PHP
> **8.5.7** served by the **PHP built-in server**: `php -S localhost:8080 router.php`
> (process cwd = docroot). **Docroot:** `~/Desktop/wordpress-local`. **Plugins dir:**
> `~/Desktop/wordpress-local/wp-content/plugins/`. The plugin is installed as a
> **symlink** `…/plugins/power-creatives` → this working copy's INNER dir
> (`…/Landing page -demo/powerplatform/powerplatform`, the one that holds
> `power-creatives.php`). ⚠️ The symlink MUST target the inner dir, not the
> `powerplatform/` wrapper — WP scans only one level deep, so a wrapper-aimed link
> makes the plugin invisible (no `pcm/v1`). `router.php` serves `app/dist/*` static
> assets directly (follows the symlink), so the SPA bundle loads fine. No `wp-cli`
> here; activate/inspect via a CLI bootstrap (`php -r`/script that `require`s
> `wp-load.php` then `activate_plugin('power-creatives/power-creatives.php')`).
> A mu-plugin `wp-content/mu-plugins/prevent-loopback-deadlock.php` guards against
> the single-threaded server deadlocking on self-HTTP (cron/REST loopbacks).
> **Auth-free smoke test:** `GET http://localhost:8080/wp-json/` → `namespaces`
> contains `pcm/v1`; `GET /wp-json/pcm/v1` lists ~167 routes; a protected route
> (e.g. `/wp-json/pcm/v1/brands`) returns **403** (loaded + nonce/cap-guarded), not 404.
>
> **Env is machine-specific.** On the (other) Windows dev box (`sanky`) the local
> WP host is **Local by Flywheel** — NOT the `~/Desktop/wordpress-local` PHP
> built-in-server setup an earlier (Mac) session documented (that path/port do not
> exist here). Verified running 2026-06-17.
- **Host:** Local by Flywheel site **`powercreatives`** → `http://powercreatives.local`
  (hosts entries for `powercreatives.local` + `www.` already present). Stack:
  PHP 8.2.29, nginx 1.26.1, MySQL 8.4.0, Mailpit — all Local "lightning services".
- **WP root:** `~/Local Sites/powercreatives/app/public`. **DB:** `local` / user
  `root` / pass `root` (MySQL on port 10005 only while the site is running).
- **Plugin link:** `…/wp-content/plugins/power-creatives` is a **directory junction**
  (`mklink /J`, not a symlink — Developer Mode is off, so admin-free symlinks aren't
  available) → repointed to this working copy `C:\Users\sanky\Downloads\powerplatform-new\powerplatform`.
  (An older Jun-5 copy lives at `…\Downloads\powerplatform\powerplatform`; the junction
  used to point there.)
- **Starting it:** Local sites start from the **Local desktop app** (select site →
  *Start site*). No reliable headless start — launching the bundled mysqld/php-fpm/nginx
  by hand bypasses Local's router/orchestration and tends to half-start.
- **Quick liveness check (auth-free):** `GET http://powercreatives.local/wp-json/`
  → `namespaces` contains `pcm/v1`; `GET /wp-json/pcm/v1` lists ~165 routes ⇒ plugin
  booted from the junction. Most routes need `X-WP-Nonce` + capability, so use the
  REST index (not individual routes) for a no-auth smoke test.
- **Toolchain present:** PHP 8.1.25 on PATH (XAMPP), Node 22 / npm 11, Docker 28.
  **`composer` and `wp` (wp-cli) are NOT on PATH** here.
- **Build gotcha (this copied checkout):** `app/node_modules/` was **copied**, not
  installed — its `.bin` shims and the platform-specific `@rollup/rollup-win32-x64-msvc`
  native binary were missing, so `npm run build` failed (`vite not recognized` →
  rollup `MODULE_NOT_FOUND`). Fix once with **`cd app && npm install`** (materializes
  the win32 binaries + `.bin`); after that `npm run build`/`npm run check` work. `vendor/`
  + `app/dist/` are present and built.

## Directory layout
```
power-creatives.php        Bootstrap: constants, require chain, hooks
uninstall.php              Drops all wp_pcm_* tables + options (irreversible)
composer.json              classmap autoload over includes/, phpunit scripts
phpunit.xml.dist           PHPUnit config
includes/
  module-loader.php        Auto-discovery: scans modules/*/config.php
  class-pcm-admin.php      Admin menu + enqueues React SPA (id="pcm-root")
  class-pcm-shortcode.php  [power_creatives] frontend render + gate + enqueue
  class-pcm-shortcode-admin.php
  class-pcm-activator.php  activate/deactivate/maybe_upgrade + migrations
  core/                    Shared infra (owned by no module)
    base-controller.php    PCM_REST_Base (auto-routing, nonce+cap, success/error)
    class-pcm-settings.php class-pcm-providers.php class-pcm-gate-auth.php
    class-pcm-prompt-placeholders.php  *-seeds.php  class-pcm-website-scraper.php
    db/  class-pcm-schema.php (dbDelta + ALTER migrations) class-pcm-db.php (CRUD)
    llm/ sse/ storage/ kie/ google/ fal/ providers/ (openai,google,kieai,fal + registry)
  modules/                 18 vertical-slice modules (see registry below)
    {id}/automations.php   OPTIONAL: a module's Automations triggers/actions/handlers
                           (cross-module engine). Loaded by a glob in power-creatives.php.
app/
  vite.config.wp.ts        outDir app/dist/, fixed filenames index-writer.js + index.css
  src/  App.tsx main.tsx modules/ pages/ components/ contexts/ hooks/ lib/ types/
  shared/  dist/ (built; gitignored / may be absent until built)
docs/                      ARCHITECTURE.md, CONTRIBUTING.md, UPGRADE-SAFETY.md,
                           modules/, dated CHANGELOG-*/HANDOVER-*, tech-debt/, audits/
tests/                     PHPUnit tests
```

## Bootstrap flow (main file → loader → hooks)
1. `power-creatives.php` defines constants, then `require`s the full chain (core →
   admin/shortcodes/activator → seeds → providers/utils → gate-auth → base-controller
   → storage/llm/sse → kie/google/fal → provider impls + registry → module-loader).
2. At file-load: `PCM_Module_Loader::discover()` scans `includes/modules/*/config.php`,
   validates (`id`+`controller` required), then `require`s sibling `controller.php`
   and optional `service.php`. **Automations**: the loader does NOT load
   `automations.php`; instead `power-creatives.php` has a `glob(includes/modules/*/automations.php)`
   require so each module registers its triggers/actions/handlers before `rest_api_init`.
3. `register_activation_hook` → `PCM_Activator::activate`; deactivation →
   `PCM_Activator::deactivate` (only flushes rewrite rules — **never drops tables**).
4. On `plugins_loaded` → `pcm_init()`: `PCM_Activator::maybe_upgrade()` (runs DB
   migrations every page load if `pcm_db_version` < `PCM_DB_VERSION`); instantiates
   `PCM_Admin` + `PCM_Shortcode_Admin` (admin only) and `PCM_Shortcode`; registers
   `rest_api_init`.
5. `rest_api_init` → `pcm_register_rest_routes()` → `PCM_Module_Loader::register_routes()`
   instantiates each controller and calls `->register()`.

> **Note:** there is **no `load_plugin_textdomain()` call** — relies on WP auto-loading
> translations from `/languages`.

## Module registry (22 modules)
All route under the shared `pcm/v1` namespace + a path segment. The `rest_namespace`
in each `config.php` is **declarative metadata only** — the base `register()` loop
uses `pcm/v1` + the path in `routes()`.

| Module | rest_namespace (declared) | Purpose |
|---|---|---|
| approvals | pcm/v1/approvals | Client approval boards + public share links. Public routes (`/sets/{token}/comment`,`/approve`,`/review`,`/draft`) + auth routes (`/sets/{id}/reply`,`/share`,`/status`). Emits `approvals.set_status_changed` / `set_shared` / `set_fully_approved` automation triggers (the lane move on share/full-approval is rule-driven, not hardcoded). |
| automations | pcm/v1/automations | **Cross-module IF→THEN engine** (triggers → conditions → actions). CRUD + `/catalog` + `/test`. See the Automations section below. |
| assets | pcm/v1/assets | Generated asset CRUD, refine, export |
| brands | pcm/v1/brands | Business profiles, logos, colors |
| copy | pcm/v1/copy | AI ad copy (SSE streaming, framework-driven) |
| deliveries | pcm/v1/deliveries | Project delivery tracking (v1.6.0+) |
| image | pcm/v1/image | AI image gen/edit/upscale |
| integrations | pcm/v1/integrations | Provider API key management |
| keywords | pcm/v1/keywords | SEO keyword lists (user meta `pcm_keyword_lists`) |
| models | pcm/v1/models | AI model CRUD + sync from providers |
| notifications | pcm/v1/notifications | In-app notifications (`GET /notifications` → items+unseen; `POST /notifications/seen`). `edit_posts`. See *Notifications (v1.19.0)*. |
| prompts | pcm/v1/prompts | User system-prompt overrides |
| scraper | pcm/v1/scraper | URL scrape + AI vision |
| seo | pcm/v1/seo | Content SEO suite (cross-plugin meta, AI field/body generate, schema, AI-readiness, site, GBP, export). `edit_posts` + per-post checks. See *SEO suite*. |
| seohub | pcm/v1/seohub | Multi-site connectors (HMAC handshake, tenant CRUD, connector-ZIP, remote proxy). Tenant CRUD `manage_options:strict`; public `/seohub/connector/hello`. See *SEO Hub*. |
| settings | pcm/v1 | Global settings + prompt editor |
| sites | pcm/v1/sites | Connected WP site metadata |
| strategy | pcm/v1/strategies | Strategic planning |
| templates | pcm/v1/templates | Reusable form templates / frameworks |
| users | pcm/v1/users | WP-user mirror + delivery assignment (`manage_options`). See *Team access model*. |
| video | pcm/v1/video | Async AI video generation |
| writer | pcm/v1/articles | Multi-article content editor (`wp_pcm_articles`) |

### Other hook surfaces
- **REST**: `register_rest_route` only in `base-controller.php` (shared loop) +
  `approvals/controller.php` (overrides `register()` for public token routes). Public
  routes opt in via `'public'` + are rate-limited; all others carry nonce + capability.
- **AJAX**: none (no `wp_ajax_*`). Everything is REST.
- **Cron**: two hooks in `automations/service.php`:
  - `pcm_automation_run_action` — dormant async seam (`wp_schedule_single_event`)
    for long-running automation actions.
  - `pcm_automation_check_pending_approvals` — **recurring daily** event scheduled
    in `pcm_init()` via `wp_schedule_event(..., 'daily', ...)`. Callback
    `PCM_Automation_Engine::run_pending_client_scan()` powers the
    `approvals.set_pending_in_client` trigger (X-day reminder loop while a set
    sits in the "Sent to Client for Approval" lane).
- **Shortcodes**: 1 — `add_shortcode('power_creatives', …)` in `class-pcm-shortcode.php`.
- **Blocks / widgets**: none.

## Database
- Prefix `wp_pcm_`. `PCM_Schema::create_tables()` runs `dbDelta` on ~21 tables:
  `users, integrations, projects, assets, scraped_collections, scraped_images, models,
  copy_jobs, copy_results, templates, brands, brand_assets, prompt_overrides, strategies,
  strategy_items, articles, sites, deliveries, approval_sets, automations, automation_logs`
  (+ `seo_tenants`, `seo_hmac_nonces`, `notifications`, `delivery_assignments`, and
  **`seo_views`** — per-user saved SEO table Views {columns,filters} JSON, DB **1.26.0**).
- Because `dbDelta` can't rename/alter-null/drop, explicit one-off `ALTER`/backfill
  migrations live in `PCM_Schema` (`migrate_assets_columns`, `migrate_brands_columns`,
  `migrate_brands_domain`, `migrate_brand_assets_role`, `migrate_brand_svg_to_png`,
  `migrate_approval_set_statuses`, `migrate_automations_columns`) and are gated in
  `PCM_Activator::maybe_upgrade()` by `version_compare` against stored `pcm_db_version`.
- **Recent migrations** (all additive / SQLite-safe):
  - **1.14.0** — `wp_pcm_automations` + `wp_pcm_automation_logs`; `clientEmail` on
    `brands` + `approval_sets`.
  - **1.15.0** — `automations.{name,triggerId,conditions,actionId}`; relax legacy
    `event/channel` to nullable (`migrate_automations_columns`).
  - **1.16.0** — `automations.inputMapping` (trigger-context → action-input mapping).
  - **1.17.0** — `approval_sets.clientSentAt` (stable anchor for the pending-client
    reminder scanner). The 1.17.0 activator gate ALSO one-shot seeds default
    automation rules for every existing PCM user (idempotent — re-running is a
    no-op via the `__seedKey` marker in each rule's `config`).
- **`wp_pcm_automations`** = automation rules: `triggerId, conditions(JSON), actionId,
  config(JSON), inputMapping(JSON), brandId, isActive` (+ legacy `event/channel` mirrored).
  **`wp_pcm_automation_logs`** = append-only dispatch audit (stores `payloadHash`, never secrets).
- **Options owned**: only `pcm_db_version` and `pcm_settings` (single settings blob,
  `PCM_Settings::OPTION_KEY`). New settings keys: `global_webhook_url`,
  `automations_webhook_secret`, `automations_from_email`, `automations_from_name`.
- **User meta**: `pcm_keyword_lists`. **Post meta**: attachment metadata written in
  `core/storage/class-pcm-storage.php` and `video/service.php`.
- **No custom capabilities or roles** — uses built-in `manage_options` / `edit_posts`.

## External services & credentials
- Providers: **openai, google (Veo/Gemini), fal, kieai, anthropic, ahrefs, brevo**
  (`class-pcm-providers.php`). **brevo** = transactional email (HTTP API, not SMTP) used by
  the Automations email channel/action; validated via `GET /v3/account` (`api-key` header).
- Per-user API keys stored in the **`wp_pcm_integrations` table** (`apiKey` column),
  retrieved via `PCM_REST_Base::get_provider_api_key($provider, $user_id)`.
- Global `forge_api_key` / `forge_api_url` live in the `pcm_settings` option (empty by default).
- Connected-site app passwords (`wp_pcm_sites.appPassword`) noted as openssl-encrypted.

## Automations engine (cross-module IF → THEN)
A small rules engine: a **trigger** (something happens in module A) + **conditions**
(filters) → an **action** (do something, possibly in module B). All in
`includes/modules/automations/`.
- **Engine** `service.php` (`PCM_Automation_Engine`): `fire_trigger($triggerId,$context,$userId)`
  loads active rules (`wp_pcm_automations`), evaluates conditions (`{key:value}` equality),
  resolves `inputMapping` → action inputs, dispatches to the action **handler**, logs to
  `wp_pcm_automation_logs`. **Rule scope**: fire_trigger runs the event owner's
  rules PLUS admin-owned CUSTOM rules (no `__seedKey` in config) — automations
  created by admins apply to every user's events; seeded defaults stay
  per-user (each user has their own copies — global seeded copies would
  duplicate notifications/lane-moves). Handlers still receive the EVENT
  owner's userId. inputMapping is free-form key→template; the rule dialog
  edits it as dynamic key/value rows (rename / remove / "+ Add property") with the
  action's `inputSchema` only seeding starter rows — webhook payload = `{event, ...inputs}`.
  Also `register_action_handler()`, rule CRUD, and the
  `run_scheduled_action()` wp-cron callback (async seam). Legacy `dispatch($event,…)` path
  still powers the built-in approval notifications (comment webhooks / share+reply emails).
- **Registries** `class-pcm-automation-triggers.php` / `-actions.php` (`register/all/get/is_valid`).
  No hardcoded built-ins — each module registers its own in `includes/modules/{id}/automations.php`
  (e.g. `approvals/automations.php` → `approvals.set_status_changed`; `brands/automations.php`
  → `brands.brand_created`; `automations/automations.php` → the core `webhook`+`email.send`
  actions + inert `implemented:false` "coming soon" catalog entries).
- **Action handlers** `handlers/` implement `PCM_Automation_Action_Handler`
  (`id()`, `mode()` sync|async, `run($config,$inputs,$context,$userId)`). Built-ins:
  `PCM_Webhook_Action_Handler` (HMAC-signed, wraps `PCM_Webhook_Channel`),
  `PCM_Email_Action_Handler` (wraps `PCM_Brevo_Email_Channel`). Actions with no handler /
  `implemented:false` log `skipped`.
- **Channels** `channels/` (`PCM_Automation_Channel`): `PCM_Webhook_Channel` (signed POST,
  non-blocking), `PCM_Brevo_Email_Channel` (Brevo `/v3/smtp/email`).
- **Input mapping** `class-pcm-automation-mapping.php` (`{{context.key}}` placeholders →
  action inputs; literals supported; read-only, never `eval`).
- **Webhook payload**: default `{event,name,link,status,setId,timestamp}`; signed header
  `X-PCM-Signature: sha256=hmac(secret, body)` when a secret is set; `X-PCM-Event` always.
- **Dedupe** (`already_sent`): a log row with the given `dedupeKey` for the user — in
  **any** status (`sent`/`skipped`/`failed`) — short-circuits a repeat call within
  that slice. Callers pick a fresh key for the next slice (e.g. day:N+1).
- **Pending-client reminder scanner** `run_pending_client_scan()` — wp-cron `daily`
  callback. Reads rules with trigger `approvals.set_pending_in_client`, scans
  `client`-lane sets, computes `daysSinceSent = floor((now − clientSentAt)/DAY_IN_SECONDS)`,
  and fires the trigger when `days ≥ minDays`, deduped per minDays-CYCLE via
  `pending:set:{id}:rule:{ruleId}:day:{cycleStart}` (cycle start = `intdiv(days,min)*min`),
  so reminders repeat every X days AND a missed cron day fires a catch-up instead of
  skipping the cycle. Pure helpers (`pending_should_fire`, `pending_cycle_day`,
  `pending_dedupe_key`) — unit-tested.
- **Default-rule seeder** `class-pcm-automation-seeds.php` (`PCM_Automation_Seeds::seed_for_user`)
  — idempotent (each seeded rule carries a `__seedKey` in its `config` JSON). Five
  defaults, all `isActive=true`: **set shared → move to `client` lane**; **set fully
  approved → move to `launch` lane** (these two are the formerly-hardcoded approval-flow
  transitions, now editable rules); lane=Launch → webhook (URL blank → user fills in);
  pending-client → email client (mapping uses `{{clientEmail}}`); pending-client →
  webhook team. Invoked from `PCM_REST_Base::get_current_pcm_user()` (new users) AND
  the 1.17.0 activator gate (existing users).
- **Frontend** `app/src/modules/Automations/index.tsx`: catalog-driven builder with a
  **searchable Combobox** (shadcn `Command` + `Popover`) — module-grouped, "coming
  soon" disabled, filter-as-you-type. List + create/**edit** dialog + active toggle/delete.
- **Live today** (functional triggers/actions):
  - Triggers: `approvals.set_status_changed`, `approvals.set_shared`,
    `approvals.set_fully_approved`, `approvals.set_pending_in_client`,
    `brands.brand_created`.
  - Actions: `webhook` (HMAC-signed, non-blocking), `email.send` (Brevo),
    `approvals.move_to_lane` (set status to a configurable lane via
    `update_status`; handler in `approvals/class-pcm-move-lane-action-handler.php`).
  - Everything else is registered but `implemented:false` (catalog-visible, blocked
    by the controller, no emitter/handler).
- **Send-to-approval flow (cross-module)**: `app/src/components/shared/SendToApprovalSetDialog.tsx`
  — generic "package selected items into an approval set" dialog (create →
  move-to-`client` lane → share link / email invite with an **editable message**).
  Used by Copy (`ResultsPanel.tsx` + bottom `BulkActionBar`) and Image
  (`Image/index.tsx` bulk bar). Ads keeps its own near-identical
  `Ads/components/CreateApprovalSetDialog.tsx` (dup noted for future unify).
  Backend: `POST /approvals/sets/{id}/share` accepts `{ email, message? }` —
  `message` is `sanitize_textarea_field`'d and rendered escaped (nl2br) by
  `PCM_Automation_Templates::client_invite` (blank → default template).
- **Brand website scraper** (`includes/core/class-pcm-website-scraper.php`):
  `fetch_html` sends a browser-like UA + Accept headers (bare bot UAs get 403'd
  by WAFs); 401/403/429 surface a "site has bot protection" message.

## Team access model (v1.18.0, DB 1.18.0 — 19 modules)
- **Users module** (`includes/modules/users/`, routes `manage_options`-only):
  `GET /users` mirrors every WP user into `wp_pcm_users` (openId `wp_{ID}`) with
  LIVE access level (`manage_options` → admin, else user; re-synced on each
  request in `get_current_pcm_user`); `PUT /users/{id}/deliveries` replaces a
  user's assignment set (deliveries must be OWNED by the caller).
- **Assignments**: `wp_pcm_delivery_assignments` (deliveryId, userId=assignee,
  assignedBy; UNIQUE pair). Deliveries carry nullable `brandId`/`projectId` —
  assigning a delivery grants **view + use** (never edit/delete) of the delivery
  + its linked brand + project. `PCM_Access` (includes/core/class-pcm-access.php)
  returns granted ids + builds the "(owned OR granted)" `scope_clause`; reads in
  `PCM_DB::get_user_deliveries/get_delivery_by_id/get_user_brands/get_brand_by_id`
  and assets `get_projects` are scope-widened; ALL writes stay `WHERE userId`.
  Assignment changes must call `PCM_DB::invalidate('deliveries'|'brands', $assignee)`
  (lists are transient-cached).
- **Capabilities**: work modules (brands, assets/projects, deliveries, copy,
  image, video, writer, keywords, strategy, sites, scraper, models) override
  `protected string $default_capability = 'edit_posts'`; admin-only modules
  (users, settings, integrations, automations, templates) keep `manage_options`.
- **Frontend**: `users` ModuleId → `app/src/modules/Users/index.tsx` (table +
  AssignDeliveriesDialog); sidebar entry admin-gated via `pcmConfig.user.role`;
  DeliveryDialog has Brand/Project selects.

## Notifications (v1.19.0, DB 1.19.0 — 20 modules)
- **Events → rules**: triggers `approvals.comment_added` (fired from
  `append_comment`, covers client comments AND team replies) and
  `approvals.asset_approved` (fired from `approve_assets` on approve
  transitions only; `assetId='all'` for approve-all). Both carry an
  ENRICHMENT block built by `PCM_Approvals_Service::enrich_context()`:
  `brandId/brandName, deliveryId/deliveryName, projectId/projectName,
  projectAssignee` (specific IDs exposed next to names so webhook consumers
  key off stable identifiers; latest delivery linked to the set's brand + its
  assignees), `commentUrl` (share URL `#asset-{id}`), `dashboardUrl`
  (`admin.php?page=power-creatives`). The webhook handler's default-payload
  whitelist + each trigger's `contextKeys` include all of these.
  Seeded editable rules `approvals.notify.comment` / `approvals.notify.approval`
  → action `notifications.create` (handler in
  `approvals/class-pcm-notification-action-handler.php`) → seeder now 7 defaults.
- **Storage**: `wp_pcm_notifications` — ONE row per event
  (ownerId/brandId/setId/type/title/excerpt/link); read-state =
  `users.notificationsSeenAt` anchor (no per-recipient fan-out).
- **Notifications module** (`includes/modules/notifications/`, `edit_posts`):
  `GET /notifications` → `{items(≤50), unseen}` with server-side visibility
  (admin → all; user → owned OR brandId ∈ granted brands;
  `PCM_Notifications_Service::visibility_clause` is unit-tested);
  `POST /notifications/seen` sets the seen anchor.
  **`POST /notifications/clear`** (v1.25.0) sets a SECOND per-user anchor
  `users.notificationsClearedAt`; `list_for_user` filters to `createdAt >
  clearedAt`, so "Clear all" empties the feed **per-user without deleting the
  shared rows** (other recipients keep seeing the events). Frontend: a "Clear
  all" button in `NotificationsPanel.tsx` (shown when items exist) →
  `trpc.notifications.clear` → refetch. Additive column via dbDelta (DB 1.25.0).
- **Webhook default payload** (`class-pcm-webhook-action-handler.php`): when a
  rule has no inputMapping, whitelisted enrichment keys are merged in
  (brandName/deliveryName/projectName/projectAssignee/commentUrl/dashboardUrl/
  author/body/assetId) — additive, backwards-compatible.
- **Frontend**: red count bubble on the Approvals nav item + a "Notifications"
  bell row in `Sidebar.tsx` (60s poll), opening
  `components/shared/NotificationsPanel.tsx` (right Sheet; marks all seen on
  open; per-item "Open board" deep link + "Approvals" focused jump).

## Delivery linkage on approval sets (v1.20.0, DB 1.20.0)
- `wp_pcm_approval_sets.deliveryId` (nullable). Both share dialogs
  (`SendToApprovalSetDialog` for Copy/Image, Ads' `CreateApprovalSetDialog`)
  have a **Delivery select** (pre-picked by brand match) → `createSet` payload;
  controller validates via owned-OR-granted `get_delivery_by_id` (404 otherwise).
  `enrich_context()` prefers the explicit delivery; latest-by-brand stays the
  fallback for old sets. Approvals board: sets are joined with delivery names
  client-side (`SetsBoard.tsx`) and `setFilters.ts` gained a **Delivery**
  searchable filter (+ search includes deliveryName).
- **Notification → focused card**: `AppContext` one-shot
  `pendingApprovalSetId` (`navigateToApprovalsWithSet` /
  `consumePendingApprovalSetId`, mirrors pendingVideoData);
  NotificationsPanel's "Approvals" button uses it; `SetsBoard` consumes it once
  sets load and applies the existing Set filter to that set's name.

## Per-delivery module grants (v1.21.0, DB 1.21.0)
- `wp_pcm_deliveries.modules` (JSON of nav ids; whitelist
  `PCM_Deliveries_Service::GRANTABLE_MODULES` =
  copy/image/video/writer/keywords/strategies/sites/ads — **ads implies the
  copy+image backends**). "Modules needed" checkbox group in DeliveryDialog.
- `PCM_Access::granted_module_ids(uid)` (union over assigned deliveries),
  `is_admin(uid)`, `auto_project_id(uid, brandId?)` (brand-matched assigned
  delivery's project, else single-project fallback, else null).
- **Enforcement**: `PCM_REST_Base::$module_grant_keys` — set on the 7 work
  controllers (copy `['copy','ads']`, image `['image','ads']`, video, writer,
  keywords, strategy→'strategies', sites); checked in
  `make_permission_callback` for logged-in NON-admins only (admins + gate
  visitors bypass). Sidebar mirrors it via `pcmConfig.user.allowedModules`
  (null = unrestricted; computed at page load by
  `PCM_Admin::allowed_modules_for_current_user`, also used by the shortcode);
  non-admins see ALWAYS_VISIBLE (brands/deliveries/projects/assets/approvals)
  + granted modules.
- **Auto-project**: non-admin generated output defaults `projectId` to the
  assigned delivery's project — image `save_asset` (brandId param) and copy
  `store_result` (job's brandId, cached per job). Admin saves unchanged.

## Delivery type presets + per-module brand scoping (v1.22.0, DB 1.22.0)
- `wp_pcm_deliveries.type` (varchar 64, preset key or NULL). **Central
  type→modules mapping, admin-editable**: stored in
  `pcm_settings.delivery_type_presets` (Settings → "Delivery Types" tab,
  saved via `POST /settings` which normalizes on write); resolved by
  `PCM_Deliveries_Service::type_presets()` = stored setting (normalized via
  `normalize_presets()`: keys/labels sanitized, modules whitelisted) →
  fallback `TYPE_PRESETS` const (seo / google_ads / meta_ads) → filter
  `pcm_delivery_type_presets`. Saving `null` resets to the built-ins.
  Create/PATCH with `type` but no explicit `modules` expands the preset
  server-side; `sanitize_type()` whitelists against the resolved set.
  `GET /deliveries/type-presets` serves the live map; the frontend reads it
  via `useTypePresets()` (Deliveries hook, falls back to
  `pcmConfig.deliveryTypePresets` while loading); DeliveryDialog "Type"
  select pre-fills the module checkboxes (still editable);
  `Settings/DeliveryTypesSection.tsx` is the management UI (add/rename/
  delete types, per-type module checkboxes, reset to defaults).
- **Per-module brand scoping**: a granted brand is only usable inside the
  modules of the delivery that granted it.
  `PCM_Access::granted_brand_ids_for_modules(uid, keys)` (memoized) +
  `brands_by_module(uid)` (map incl. OWNED brands). Enforced centrally in
  `PCM_REST_Base::make_permission_callback` → `check_module_brand()`: on
  controllers with `$module_grant_keys`, a non-admin's `brandId` param must be
  owned or module-granted, else 403 `pcm_brand_not_granted`. Flat
  `granted_brand_ids` still drives read visibility (Brands page).
- Frontend UX mirror: `pcmConfig.user.brandsByModule` (null = unrestricted;
  `PCM_Admin::brands_by_module_for_current_user`), read via
  `app/src/lib/pcmConfig.ts` (`getBrandsForModule`, `getDeliveryTypePresets`).
  `ContextPanel` takes `moduleId` (set at the Ads/Video/Image/Writer/Copy
  mounts) and filters its brand picker; Keywords' SendToWriterDialog filters
  by `'keywords'`.

## Append-to-set + team visibility model (post-1.22, no DB change)
- **Append**: `POST /approvals/sets/{id}/assets` (edit_posts) →
  `PCM_Approvals_Service::append_to_set` — scoped read via `get_set_scoped`
  (own / admin / granted brand-or-project), rejects post-submit lanes
  (launch/live/archived) and fully-approved sets (409 `pcm_set_locked`),
  merges snapshot buckets via `merge_snapshot` (dedupe by item id, existing
  wins). Frontend: both share dialogs have a Destination select
  ("Create new" / "Add to existing") with the shared searchable
  `ApprovalSetPicker` (non-portal popover — the in-dialog pattern) filtered
  to draft/internal/client; trpc `approvals.appendToSet`.
- **Visibility**: ADMINS see ALL brands / deliveries / approval sets
  (`PCM_Access::is_admin` branch in `PCM_DB::get_user_brands/
  get_brand_by_id/get_user_deliveries/get_delivery_by_id` and
  `list_sets_by_user`); non-admins keep owned-OR-granted, and approval sets
  widen to own OR granted brandId/projectId — so user-created sets appear
  for admins + brand-granted teammates and notification jumps resolve.
  Team replies (`add_team_comment`) are view-scoped via `get_set_scoped` —
  anyone who can see the set can comment. Destructive set ops
  (status/delete/share) stay owner-scoped via `get_set_by_id`.
- **Writes admin-only**: brands POST/PATCH/DELETE/bulk/assets/colors/
  scrape-url and deliveries POST/PATCH/DELETE carry per-route
  `'manage_options'`; `GET /deliveries/type-presets` is manage_options too.
  UI: `getIsAdmin()` (`app/src/lib/pcmConfig.ts`, pcmConfig.user.role) hides
  the New Brand / New Delivery buttons for non-admins.
- Flagged: admin-all lists share the per-user transient cache semantics
  (another user's change appears after TTL/invalidation).
- **DB 1.22.1**: pcm rows have TWO creation paths and BOTH must seed —
  `get_current_pcm_user()` (self-login) and the Users-module mirror
  (`PCM_Users_Service::list_users` → upsert_user). The mirror used to skip
  seeding, leaving mirrored users with zero automation rules (their approval
  sets produced NO notifications) and zero prompt overrides. Fixed at the
  mirror site + idempotent back-fill gate (`< 1.22.1` → both seeders for all
  users) in `PCM_Activator::maybe_upgrade`.

## Async Kie.ai generation (shared-hosting safe)
The original `/image/generate`, `/image/edit`, and `/video/generate` block
the HTTP request while polling Kie.ai (up to 600–800s) — shared hosts kill
those requests (opaque 500s / stuck "Generating…"). The async seam:
- Create: `POST /image/generate-task`, `/image/edit-task`,
  `/video/generate-task` → provider `create_image_task/create_edit_task/
  create_video_task` (`class-pcm-provider-kieai.php`, identical param
  mapping as the blocking methods) → `PCM_Kie_Api::create_task` →
  `{taskId, prompt}` (prompt = resolved brand template, echoed back).
- Poll: `POST /image/task-result` (optional `storageContext` for the
  filename prefix) / `POST /video/task-result` — cheap
  `PCM_Kie_Api::get_task_status`; on completed they run the SAME
  store/save tails as the sync handlers; completed-without-URL → failed.
- Frontend branches on `provider === 'kieai'`: `useImageGeneration`
  (5s/12min), `useImageActions.runKieTask` (5s/12min, regenerate+edit),
  Video module (10s/20min); all tolerate 2 transient poll errors; other
  providers keep the sync endpoints. Kie-only — extend per provider if
  fal/google queue APIs are ever needed.

## SEO suite (port from "Optimizer Simple" — in progress)
Full phased plan: `.claude/SEO_PORT_PLAN.md` (9 source modules → native PC
modules; verbatim prompt inventory; reuse map). **Phase 1 shipped**: new
`seo` module (`includes/modules/seo/`) — content-SEO backend.
- `PCM_SEO_Service`: cross-plugin SEO meta (faithful port of the source's
  `seo-integration.php`). `detect_seo_plugin()` (Yoast `WPSEO_VERSION` >
  RankMath `class RankMath` > SEOPress `seopress_init` > `simple`);
  `seo_key_map()` (per-plugin title/description/keyword keys + `pcm_seo_`
  prefixed backups); `seo_get` (active → backup → '') / `seo_update`
  (DUAL-WRITE: active key + internal backup so values survive a plugin
  switch). Content rows (posts/pages, SEO field set), `save_cell` whitelist
  (native title/slug/status/author + `seo:*` dual-write + internal meta).
- `PCM_REST_SEO` (`pcm/v1/seo`, edit_posts + per-post `edit_post`/`delete_post`
  checks): `GET /seo/content`, `GET /seo/content/options`,
  `POST /seo/content` (quick-create), `POST /seo/content/{id}/cell`,
  `POST /seo/content/bulk-delete`.
- **Phase 2 (frontend)**: `app/src/modules/SEO` content table (inline edit,
  status dropdown, sortable, type filter, plugin badge, bulk trash,
  quick-create) wired via ModuleId/trpc-routes/Sidebar/Shell.
- **Phase 3 (AI generate)**: `prompts.php` (verbatim field prompts, filter
  `pcm_seo_field_prompts`) + `generate_field` (substitute_vars + build_field_vars
  + sanitize_ai_output → `PCM_LLM::invoke`); `POST /seo/content/{id}/generate`;
  frontend per-field sparkle → STAGED suggestion (accept/reject). Tests:
  `SeoIntegrationTest` (key-map, detection, read-chain, dual-write, whitelist,
  substitution, output-sanitize, field map, prompt completeness).
  **Prompts are user-editable in Settings → Prompts → SEO tab** (post-1.23):
  the `prompts` module registers an `seo` module with 10 sections (`{use}_{mode}`:
  page_title/meta_title/meta_description/primary_keyword generate+optimize,
  meta_keywords generate, content_optimize). The editor section list in
  `PCM_REST_Prompts::get_default_sections('seo')` MUST mirror
  `PCM_SEO_Service::get_default_prompts()` (a unit test enforces it).
  `PCM_SEO_Service::get_default_prompts()` flattens
  `prompts.php` into that section registry (the editor's "Built-in" default);
  `resolve_prompt($section,$default,$userId)` returns the user's active
  `prompt_overrides` row (module='seo') or the default. `generate_field` /
  `optimize_body` take the PCM `$user_id` (threaded from the controller) and
  call the resolver, so DB override > `pcm_seo_field_prompts` filter > file. No
  new table/migration — the editor serves a virtual default + creates rows on
  save (mirrors copy/image/video/writer).
- **Phase 5 (AI-Readiness)**: `ai-readiness.php` (`PCM_SEO_AIReadiness`) —
  virtual routes `/llms.txt`, `/llms-full.txt`, `/{slug}.md` (rewrite when
  `pcm_seo_air_published`; template_redirect server), page-builder-aware
  HTML→Markdown, llms.txt index+full builders, per-post status (md5
  ready/stale/none via `_pcm_md_*`). Required from service.php (hooks every
  request). REST (admin): `GET /seo/ai-readiness` + `/build`,`/publish`,
  `/settings`,`/generate`. Frontend: SEO module tabbed (Content / AI
  Readiness), `AIReadinessPanel`.
- **Phase 4 (Schema)**: `schema.php` (`PCM_SEO_Schema`) — JSON-LD on wp_head
  (Article/WebPage/BreadcrumbList/FAQPage/HowTo/Product, publisher+logo chain,
  about/mentions+sameAs, speakable). Meta `pcm_seo_schema[_faq/_howto/
  _sameas]`. REST `GET/POST /seo/content/{id}/schema`. Frontend `SchemaCell`.
- **Phase 6 (Site)**: `site.php` (`PCM_SEO_Site`) — robots_txt filter,
  site-wide LocalBusiness JSON-LD + meta-keywords head, language/timezone
  with restorable backups. REST `GET/POST /seo/site` + `/restore`. Frontend
  third tab `SiteSettingsPanel`.
- **Phase 7 (GBP)**: `gbp.php` — `PCM_SEO_GBP_Provider` interface +
  `PCM_SEO_GBP_N8N_Provider` (n8n webhook, swappable for direct-Google via
  the `providers()` map + `seo_gbp_provider` setting) + shared `normalize()`
  (Places New v1) + per-brand option storage (snapshot + overrides). Enriches
  the `{{business.*}}` prompt vars. REST `/seo/gbp/*`; frontend Business tab.
- **Phase 9 (export/import)**: `export.php` (`PCM_SEO_Export`) — portable
  SEO-config JSON (plugin-key-checked, whitelist-applied). REST
  `/seo/export`,`/seo/import`; Export/Import buttons in the Site tab.
- **Phase 3b (Optimize)**: `optimize_body` (PCM_LLM full-body rewrite) + REST
  `/seo/content/{id}/body`,`/optimize`; frontend `OptimizeModal` + client
  `scorecard.ts` (word/density/KW-placement/headings/FAQ/lists/sentence-len).
- **SEO SUITE COMPLETE (Phases 1–9 + 3b).** All 9 source modules ported native.

## SEO Hub (multi-site connectors, v1.23.0, DB 1.23.0)
Separate `seohub` module (`pcm/v1/seohub`). Tables `seo_tenants` +
`seo_hmac_nonces` (PCM_Schema dbDelta; `maybe_upgrade` auto-creates).
`PCM_SEOHub_Service`: HMAC sign/verify (sha256 `ts.nonce.body`, ±300s window,
nonce replay guard), tenant CRUD (uuid clientId + 32-byte secret),
`register_ping` (connector handshake → active + captured WP Application
Password), Basic-auth remote proxy, and a single-file connector-plugin **ZIP
generator** (bakes client_id/secret/hub-url; registers Yoast/RankMath/SEOPress/
pcm_seo_* meta in REST; HMAC-signed hello on activation). `PCM_REST_SEOHub`:
tenant CRUD `manage_options:strict` (rows hold secrets), **streamed** connector
download (not public uploads), public HMAC-verified `/seohub/connector/hello`.
Frontend: the **Sites module** is the single unified connection home
(`app/src/modules/Sites/index.tsx`; `HubPanel.tsx` was folded in and removed).
The `seohub` backend stays a standalone REST module (`pcm/v1/seohub/*`) — kept
separate because the connector ZIP bakes in `/seohub/connector/hello`.
**Unified connections (v1.24.0):** one Sites list + an **"Add Site" popup that
chooses between two methods** — *Application Password* (manual `sites.create`)
or *Connector plugin* (admin-only `seohub.createSite` + download). Both end up
in `wp_pcm_sites` and are **publishable**: on `register_ping` the connector is
**mirrored into `wp_pcm_sites`** (encrypted password, `connectMethod='connector'`,
owned by `seo_tenants.createdBy`) via `PCM_SEOHub_Service::mirror_to_sites()`
(pure decision in `mirror_row()`, unit-tested). Idempotent on re-ping (dedup by
owner+url); revoke/delete cascade to the mirrored row. Schema additions (DB
1.24.0, additive dbDelta): `sites.connectMethod`, `seo_tenants.createdBy`.
Pending (not-yet-registered) connector tenants show in a "Pending connections"
section. `seo_tenants` still keeps the handshake secret + its own proxy creds.
- ⚠️ **Connector handshake requires the hub to be PUBLICLY reachable.** The
  connector ZIP bakes `PCM_CONN_HUB_URL = rest_url('pcm/v1/seohub/connector/hello')`
  **at generation time** = the hub's `home_url` then. A hub on `localhost` (the
  Mac dev env, `http://localhost:8080`) bakes a localhost URL the remote site can
  never reach, so the tenant stays `status='pending'` with `lastPingAt=NULL`
  forever. The connector pings **only on activation** (`register_activation_hook`,
  one-shot) and **ignores the `wp_remote_post` result** (always sets its local
  `pcm_conn_status='registered'`), so a failed handshake is silent. To connect a
  remote site: generate/download the connector from a publicly-reachable hub (prod
  domain, or tunnel localhost via ngrok/cloudflared + set `home_url` to the tunnel),
  then on the remote **deactivate → delete → reinstall → re-activate** to re-fire
  the handshake. HMAC has a ±300s timestamp window, so hub/remote clock skew must
  be < 5 min. Hub side is healthy when an unsigned `POST /seohub/connector/hello`
  returns `403 pcm_invalid_nonce` (not 404).

## Where to add a <thing>
- **New REST module** (the standard way to add a feature):
  1. `includes/modules/{name}/config.php` → return `['id','name','version','controller'=>'PCM_REST_Name','rest_namespace'=>'pcm/v1/name']`
  2. `includes/modules/{name}/controller.php` extends `PCM_REST_Base`, define `routes()`
     returning `[METHOD, '/path', 'callback', ?args, ?capability]`. Put logic in `service.php`.
  3. (frontend) `app/src/modules/{Name}/` dumb UI + register routes in `app/src/lib/trpc-routes.ts`.
  No edits to `power-creatives.php` — the loader auto-discovers it.
- **New single route on an existing module**: add to that controller's `routes()`.
- **New custom table / column**: add the table to `PCM_Schema::create_tables()` (dbDelta);
  for renames/null-changes/backfills add a `migrate_*` method + a `version_compare` gate in
  `PCM_Activator::maybe_upgrade()`, and bump `PCM_DB_VERSION`. Add a matching `drop_tables()` entry.
- **New AI provider**: `includes/core/providers/class-pcm-provider-{name}.php` implementing
  `PCM_Provider_Interface`, register in the provider registry. Models sync via Integrations.
- **New admin settings**: extend the `settings` module / `PCM_Settings` blob (one option key).
- **New automation TRIGGER**: in the source module, (1) create/extend
  `includes/modules/{id}/automations.php` → `PCM_Automation_Triggers::register([... 'implemented'=>true,
  'contextKeys', 'conditionFields ...])`; (2) at the state-change point call
  `PCM_Automation_Engine::fire_trigger('{id}.verb_noun', $context, $userId)` (guard with
  `class_exists`). See `approvals/service.php::fire_status_trigger` + `brands/controller.php`.
- **New automation ACTION**: (1) add a handler class in `automations/handlers/` implementing
  `PCM_Automation_Action_Handler`; (2) register it + its catalog metadata
  (`implemented:true`, `configFields`, `inputSchema`) in a module's `automations.php` via
  `PCM_Automation_Engine::register_action_handler()` + `PCM_Automation_Actions::register()`.
  Long-running? return `mode()==='async'` and the engine schedules it via wp-cron. Mirror
  `class-pcm-webhook-action-handler.php` / `class-pcm-email-action-handler.php`.
- **AJAX**: none exist today — adding one is greenfield; prefer REST to match conventions.

## Conventions
- PHP 8.1 typed code, `snake_case` functions/vars, PHPDoc on public methods,
  `class-pcm-*.php` filenames, `PCM_` class prefix.
- All user-facing strings use text-domain **`power-creatives`** (`__()`, `esc_html__()`, …).
- SQL **only** via `$wpdb->prepare()`; table names from `PCM_Schema::table()` (never user input).
- REST responses via `$this->success()/error()/not_found()` — never raw `WP_REST_Response`.
- Frontend: dumb components + custom hooks, config-driven (no hardcoded menus/frameworks),
  ≤500 lines/file (enforced by architecture audit), TanStack Query + tRPC-style client.
- **Provider routing is data-driven**: frontend always sends `model.provider`; backend
  must require it and fail fast. `detect_provider()` is deprecated — do not use in new code.

## Security checklist (apply to every handler)
1. **Nonce**: `PCM_REST_Base` requires `X-WP-Nonce` verified against `wp_verify_nonce($n,'wp_rest')`.
2. **Capability**: default `manage_options`; or a valid shortcode-gate cookie limited to
   `manage_options|read|edit_posts` (`PCM_Gate_Auth::is_authenticated()`). Public routes must be explicit.
3. **Sanitize in**: `sanitize_text_field` / `sanitize_textarea_field` / `esc_url_raw` / `absint`
   (and the `string_arg`/`int_arg`/`bool_arg` route-arg helpers).
4. **Prepare SQL**: every `$wpdb` call parameterized; no string interpolation of user data.
5. **Escape out** when rendering to admin/frontend HTML (`esc_html`, `esc_attr`, `wp_kses`).

## Known risks / gotchas
- **MySQL BIGINT → JSON string coercion**: `wpdb` returns all columns as PHP strings;
  IDs arrive as `{"id":"6"}`. TS expects `number` → `===` breaks. Normalize with
  `Number(raw.id)` in every consumer hook AND in React-Query `setQueriesData` predicates.
- **`flushSync()` required** around optimistic `setQueriesData` for `@hello-pangea/dnd`
  drag-end, or cards snap back (see `docs/HANDOVER-DnD-jump-back-bug.md`).
- **`wp_pcm_assets` table is OFF-LIMITS** for the Approvals domain — per-item metadata
  goes in the approval-set snapshot JSON, not new columns (PO hard boundary).
- **`maybe_upgrade()` runs on every page load** — keep migrations idempotent and cheap.
- **No PHP linter / no textdomain load call / no custom caps** — by design today.
  (There IS cron: a daily `pcm_automation_check_pending_approvals` event + a dormant
  `pcm_automation_run_action` async seam — see *Other hook surfaces → Cron*.)
- PHP↔TS enum drift (statuses duplicated by hand), order-fragile `LEGACY_STATUS_MAP`,
  `useApprovalSets` god-hook — see `docs/tech-debt/deliveries-approvals-debt.md`.
- Default branch is **`image-features`**, not `main`.

## Open questions for the user
1. Is **`image-features`** the branch to build on, or should new work branch off something else?
2. ~~Is there a local WP env for running & verifying?~~ **Answered:** Local by Flywheel
   site `powercreatives` → `http://powercreatives.local` (see *Local WordPress* above).
   Still open: **where are provider API keys configured for testing** (per-user
   `wp_pcm_integrations.apiKey` rows — which provider keys, if any, are seeded in the
   local DB)?
3. Git/commit ritual in `AGENTS.md` (BEFORE/AFTER/VERIFIED empty commits, dated CHANGELOG
   per change) — should I follow it for every change, and do you want commits at all
   (the workspace rule says don't commit unless asked)?
