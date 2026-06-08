# Power Creatives — Codebase Map
_Last updated: 2026-06-08_

> A WordPress plugin (PHP 8.1+) wrapping a React/TypeScript SPA. AI-powered
> creative generation: copy, images, video, brand management, client approval
> boards. Vertical-slice modular monolith. Repo default branch: **`image-features`**.

## Plugin header
| Field | Value |
|---|---|
| Name / slug / text-domain | Power Creatives / `power-creatives` |
| Main file | `power-creatives.php` |
| Version (`PCM_VERSION`) | **1.7.0** |
| DB version (`PCM_DB_VERSION`) | **1.16.0** (separate from plugin version) |
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

## Module registry (18 modules)
All route under the shared `pcm/v1` namespace + a path segment. The `rest_namespace`
in each `config.php` is **declarative metadata only** — the base `register()` loop
uses `pcm/v1` + the path in `routes()`.

| Module | rest_namespace (declared) | Purpose |
|---|---|---|
| approvals | pcm/v1/approvals | Client approval boards + public share links. Public routes (`/sets/{token}/comment`,`/approve`,`/review`,`/draft`) + auth routes (`/sets/{id}/reply`,`/share`,`/status`). Emits the `approvals.set_status_changed` automation trigger. |
| automations | pcm/v1/automations | **Cross-module IF→THEN engine** (triggers → conditions → actions). CRUD + `/catalog` + `/test`. See the Automations section below. |
| assets | pcm/v1/assets | Generated asset CRUD, refine, export |
| brands | pcm/v1/brands | Business profiles, logos, colors |
| copy | pcm/v1/copy | AI ad copy (SSE streaming, framework-driven) |
| deliveries | pcm/v1/deliveries | Project delivery tracking (v1.6.0+) |
| image | pcm/v1/image | AI image gen/edit/upscale |
| integrations | pcm/v1/integrations | Provider API key management |
| keywords | pcm/v1/keywords | SEO keyword lists (user meta `pcm_keyword_lists`) |
| models | pcm/v1/models | AI model CRUD + sync from providers |
| prompts | pcm/v1/prompts | User system-prompt overrides |
| scraper | pcm/v1/scraper | URL scrape + AI vision |
| settings | pcm/v1 | Global settings + prompt editor |
| sites | pcm/v1/sites | Connected WP site metadata |
| strategy | pcm/v1/strategies | Strategic planning |
| templates | pcm/v1/templates | Reusable form templates / frameworks |
| video | pcm/v1/video | Async AI video generation |
| writer | pcm/v1/articles | Multi-article content editor (`wp_pcm_articles`) |

### Other hook surfaces
- **REST**: `register_rest_route` only in `base-controller.php` (shared loop) +
  `approvals/controller.php` (overrides `register()` for public token routes). Public
  routes opt in via `'public'` + are rate-limited; all others carry nonce + capability.
- **AJAX**: none (no `wp_ajax_*`). Everything is REST.
- **Cron**: one hook — `add_action('pcm_automation_run_action', …)` in
  `automations/service.php` (the dormant async seam for long-running automation actions
  via `wp_schedule_single_event`). No recurring cron.
- **Shortcodes**: 1 — `add_shortcode('power_creatives', …)` in `class-pcm-shortcode.php`.
- **Blocks / widgets**: none.

## Database
- Prefix `wp_pcm_`. `PCM_Schema::create_tables()` runs `dbDelta` on ~21 tables:
  `users, integrations, projects, assets, scraped_collections, scraped_images, models,
  copy_jobs, copy_results, templates, brands, brand_assets, prompt_overrides, strategies,
  strategy_items, articles, sites, deliveries, approval_sets, automations, automation_logs`.
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
  `wp_pcm_automation_logs`. Also `register_action_handler()`, rule CRUD, and the
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
- **Frontend** `app/src/modules/Automations/index.tsx`: catalog-driven builder (triggers/actions
  grouped by module, "coming soon" disabled), list + create/**edit** dialog + active toggle/delete.
- **Live today**: only `approvals.set_status_changed` (→ webhook/email) and `brands.brand_created`
  (→ webhook/email) are functional; everything else is registered-but-inert.

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
- **No PHP linter / no textdomain load call / no cron / no custom caps** — by design today.
- PHP↔TS enum drift (statuses duplicated by hand), order-fragile `LEGACY_STATUS_MAP`,
  `useApprovalSets` god-hook — see `docs/tech-debt/deliveries-approvals-debt.md`.
- Default branch is **`image-features`**, not `main`.

## Open questions for the user
1. Is **`image-features`** the branch to build on, or should new work branch off something else?
2. Is there a local WP env (Local/wp-env/DDEV) for running & verifying? (docs reference
   `http://powercreatives.local`). Where are provider API keys configured for testing?
3. Git/commit ritual in `AGENTS.md` (BEFORE/AFTER/VERIFIED empty commits, dated CHANGELOG
   per change) — should I follow it for every change, and do you want commits at all
   (the workspace rule says don't commit unless asked)?
