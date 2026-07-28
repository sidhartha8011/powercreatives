# Power Creatives — Codebase Map
_Last updated: 2026-07-28 · NEWEST (2026-07-27/28, UNCOMMITTED — a large Strategies/Keywords UI+config
batch): 2 new item routes (`post-status`, `duplicate`) and 5 new strategy `config` keys — see
**strategy** below for the contract. Frontend highlights, all in `Strategies/index.tsx` +
`Keywords/CreateStrategyDialog.tsx`: the create dialog now doubles as the **full settings editor**
(`editStrategy` prop; the row cog opens it — `ParentSettingsModal` is now unreachable); prompts+models
consolidated into CONTENT with Publishing moved to 2nd and open; **source-aware template mapping**
(`templateSourceFit()` detects `{{ post_* }}` usage to tell RSS/Social templates from keyword ones and
re-maps on source change); typed **primary + supporting keywords** (no table selection needed); per-item
**bulk select** (Linking/Delete/Duplicate) and a **crown parent toggle**; item rows lead with the KEYWORD
and carry a publish-date tag. PRIOR: 2026-07-22 (map re-verified vs `feat/seo-suite-port` code; refreshed
from the 2026-07-08 version after 9 DB-version bumps + module growth)._

> A WordPress plugin (PHP 8.1+) wrapping a React/TypeScript SPA. AI-powered
> creative generation: copy, images, video, brand management, client approval
> boards, SEO suite + multi-site connector hub, content-strategy pipeline.
> Vertical-slice modular monolith. Repo default branch: **`image-features`**
> (remote `origin/HEAD`); **active development branch is `feat/seo-suite-port`**.
> Other branches: `feat/approvals-automations`.
>
> **Machine note:** the *Local WordPress* section below has multiple
> machine-specific setups (a couple of Windows dev boxes + a **Mac**). Live
> in-browser verification IS available. Either way, also run `composer test` +
> `cd app && npm run check && npm run build`.

## Plugin header
| Field | Value |
|---|---|
| Name / slug / text-domain | Power Creatives / `power-creatives` |
| Main file | `power-creatives.php` |
| Version (`PCM_VERSION`) | **1.7.0** |
| DB version (`PCM_DB_VERSION`) | **1.45.0** (separate from plugin version). Recent: 1.37.0 `strategy_items.scheduledDate` + `seo_dynamic_rules`; 1.38.0 `strategy_items.setId` + `seo_rule_versions`; 1.39.0 `strategy_items.volume/difficulty` + `seo_redirects`; 1.40–1.42 convergence bumps (no schema); 1.43.0 `article_revisions` (ours) + `brand_business_units` + `sites.businessUnitId` (theirs, gated GBP move); 1.44.0 data backfill (site→brand auto-map); 1.45.0 catch-up re-run of the GBP→business-units move. |
| Requires WP / PHP | 6.4+ / 8.1+ |
| Const prefix | `PCM_` |
| Composer package | `antigravity/power-creatives` (type `wordpress-plugin`) |

## Stack & build (dual stack)
- **Backend:** PHP 8.1+, WordPress REST API under `pcm/v1/*`. No PSR-4 — Composer
  **classmap** over `includes/`. Class naming `PCM_Like_This`, files
  `class-pcm-{name}.php` (core) / `controller.php`,`config.php`,`service.php` (modules).
- **Frontend:** React 18 + TypeScript + **Vite** SPA in `app/`, mounted into WP
  admin (and a frontend shortcode). Radix/shadcn UI, TanStack Query/Table, TipTap,
  Jotai (Writer). "tRPC" client is a `fetch`-based **proxy adapter** emulating the
  tRPC call shape over REST (`app/src/lib/trpc.ts`); route catalog in `trpc-routes.ts`.
- **DB:** custom `wp_pcm_*` tables via `dbDelta()` (additive only).

## Run / test / lint / build commands
```bash
# PHP tests (PHPUnit 9.6 + Brain Monkey + wp_mock + Mockery)
# NOTE: needs PHP 8.1–8.2 (wp_mock requires <8.3).
composer install
composer test                 # phpunit  (config: phpunit.xml.dist, tests in tests/)
composer test:coverage

# Frontend (run from app/)
cd app && npm install
npm run build                 # vite build --config vite.config.wp.ts  → app/dist/
npm run build:watch
npm run dev
npm run check                 # tsc --noEmit  ← run after every TS change
```
There is **no PHP linter configured** (no `phpcs.xml`); follow WPCS conventions by hand.

## Local WordPress (LIVE verification IS available)
> **Windows dev box `krith` (confirmed live 2026-06-29).** Clean machine — toolchain
> installed from scratch via winget: **Node 24.18.0 / npm 11.16.0** + **Local by
> Flywheel 10.1.1**. No PHP/Composer/wp-cli **on PATH**, so `composer test` can't
> run here — runtime needs NO `vendor/` (the plugin uses its own require chain).
> **Local bundles a usable PHP (8.2.29):**
> `C:\Users\krith\AppData\Local\Programs\Local\resources\extraResources\lightning-services\php-8.2.29+0\bin\win64\php.exe`
> — use it for `php -l` and standalone PHP scripts. A bare `php -r` loads NO ini
> (so `mb_*`/PDO are absent); add `-d extension_dir="…\bin\win64\ext" -d extension=php_mbstring.dll`
> when you need mbstring. Class files guard with `if(!defined('ABSPATH'))exit;`, so a
> standalone harness must `define('ABSPATH', …)` before `require`-ing them.
> ⚠️ **TWO Local sites exist — don't confuse them:** (1) **`power-creatives`**
> (hyphen) → `http://power-creatives.local`, (2) **`powercreatives`** (no hyphen) →
> `http://powercreatives.local`. **The user actively uses `powercreatives.local`
> (no hyphen).** Both junction to ONE OneDrive working copy
> (`C:\Users\krith\OneDrive\Desktop\Powercreatives\powercreatives`, inner dir holding
> `power-creatives.php`). `git pull` + `npm run build` there reflects on both sites.
> ⚠️ **"I pulled but nothing changed":** `.claude/SESSION_LOG.md` is a *committed*
> file that nearly every session also edits locally → an uncommitted local edit
> makes `git pull` abort. Fix: `git stash push .claude/SESSION_LOG.md` →
> `git merge --ff-only origin/<branch>` → `git stash pop`.
> Frontend bundle has FIXED filenames (`index-writer.js`/`index.css`, no hash) —
> after a rebuild you MUST hard-refresh (Ctrl+F5) or WP serves the cached bundle.
> **Auth-free smoke test:** `GET /wp-json/` → `namespaces` contains `pcm/v1`;
> `GET /wp-json/pcm/v1` lists routes; `/wp-json/pcm/v1/brands` → **403** (loaded
> + nonce/cap-guarded, not 404).
>
> **Mac dev box (confirmed live 2026-06-18).** WP **7.0** / PHP **8.5.7** served by
> the **PHP built-in server**: `php -S localhost:8080 router.php` (cwd = docroot).
> **Docroot:** `~/Desktop/wordpress-local`. Plugin installed as a **symlink**
> `…/plugins/power-creatives` → this working copy's INNER dir (the one holding
> `power-creatives.php`; the symlink MUST target the inner dir, not the
> `powerplatform/` wrapper — WP scans one level deep). `router.php` serves
> `app/dist/*` directly. ⚠️ **Mac build gotcha:** the copied
> `app/node_modules/.bin` shims are broken on this volume — build by calling vite
> through node directly: `cd app && node node_modules/vite/bin/vite.js build --config vite.config.wp.ts`.
> No `wp-cli`; activate via a CLI bootstrap. A mu-plugin
> `wp-content/mu-plugins/prevent-loopback-deadlock.php` guards the single-threaded
> server against self-HTTP deadlock.
>
> **Env is machine-specific.** On the other Windows box (`sanky`) the local WP host
> is **Local by Flywheel** — NOT the Mac PHP-built-in-server setup. Host:
> `http://powercreatives.local`. Stack: PHP 8.2.29, nginx 1.26.1, MySQL 8.4.0.
> WP root: `~/Local Sites/powercreatives/app/public`. DB: `local`/`root`/`root`.
> Active plugin dir is `…/wp-content/plugins/`**`powercreatives`** (no hyphen; main
> file inside is `power-creatives.php`). It's a **directory junction** →
> `C:\Users\sanky\Desktop\powercreatives\powercreatives` (inner dir). Start from
> the **Local desktop app**. `composer` and `wp` NOT on PATH.

## Directory layout
```
power-creatives.php        Bootstrap: constants, require chain, hooks
uninstall.php              Drops all wp_pcm_* tables + options (irreversible)
composer.json              classmap autoload over includes/, phpunit scripts
phpunit.xml.dist           PHPUnit config
includes/
  module-loader.php        Auto-discovery: scans modules/*/config.php (scandir, not glob)
  class-pcm-admin.php      Admin menu + enqueues React SPA (id="pcm-root")
  class-pcm-shortcode.php  [power_creatives] frontend render + gate + enqueue
  class-pcm-shortcode-admin.php
  class-pcm-activator.php  activate/deactivate/maybe_upgrade + migrations
  core/                    Shared infra (owned by no module)
    base-controller.php    PCM_REST_Base (auto-routing, nonce+cap, success/error)
    class-pcm-settings.php class-pcm-providers.php class-pcm-gate-auth.php
    class-pcm-access.php   class-pcm-hierarchy.php  class-pcm-gsc.php
    class-pcm-prompt-placeholders.php  *-seeds.php  class-pcm-image-utils.php
    class-pcm-website-scraper.php
    db/  class-pcm-schema.php (dbDelta + ALTER migrations) class-pcm-db.php (CRUD)
    llm/ sse/ storage/ kie/ google/ fal/ providers/ (openai,google,kieai,fal + registry)
  modules/                 23 vertical-slice modules (see registry below)
    {id}/automations.php   OPTIONAL: a module's Automations triggers/actions/handlers
                           (cross-module engine). Loaded by a glob in power-creatives.php.
app/
  vite.config.wp.ts        outDir app/dist/, fixed filenames index-writer.js + index.css
  src/  App.tsx main.tsx modules/ components/ contexts/ hooks/ lib/ pages/ types/ _core/
  shared/  dist/ (built; gitignored / may be absent until built)
docs/                      ARCHITECTURE.md, CONTRIBUTING.md, UPGRADE-SAFETY.md,
                           modules/, dated CHANGELOG-*/HANDOVER-*, tech-debt/, audits/
tests/                     PHPUnit tests
```

## Bootstrap flow (main file → loader → hooks)
1. `power-creatives.php` defines constants, then `require`s the full chain (settings →
   schema/db → access/hierarchy → admin/shortcodes/activator → seeds → placeholders →
   providers/gsc/image-utils/scraper → gate-auth → base-controller → automations engine
   + channels + handlers + mapping + service + seeds → **module-owned `automations.php`
   glob** → storage/llm/sse → kie/google/fal → provider impls + registry → module-loader).
2. `PCM_Module_Loader::discover()` (`module-loader.php`) `scandir`s `includes/modules/`,
   loads each `{name}/config.php`, auto-requires sibling `controller.php` + optional
   `service.php`. The loader does NOT load `automations.php` — a separate
   `glob(includes/modules/*/automations.php)` require at `power-creatives.php:97` does.
3. `register_activation_hook` → `PCM_Activator::activate`; deactivation only flushes
   rewrite rules (**never drops tables**).
4. On `plugins_loaded` → `pcm_init()` (`power-creatives.php:144`): calls
   `PCM_Activator::maybe_upgrade()` (runs migrations every page load if
   `pcm_db_version` < `PCM_DB_VERSION`); instantiates admin/shortcode; registers
   `rest_api_init`; schedules 3 cron events.
5. `rest_api_init` → `pcm_register_rest_routes()` → `PCM_Module_Loader::register_routes()`
   instantiates each controller and calls `->register()`.
- There is **no `load_plugin_textdomain()` call** — relies on WP auto-loading translations.

### Cron events (scheduled in `pcm_init`)
- `pcm_automation_check_pending_approvals` — **daily recurring** (`power-creatives.php:165`).
  Callback `PCM_Automation_Engine::run_pending_client_scan()` powers the
  `approvals.set_pending_in_client` trigger (X-day reminder loop while a set sits in
  the "Sent to Client" lane). Deduped via a cycle-anchored `dedupeKey`.
- `pcm_strategy_scheduled_scan` — **daily recurring** (`power-creatives.php:172`).
  Strategy RSS/social watcher cadence.
- `pcm_sites_health_probe` — **~5min recurring** (`power-creatives.php:190`) on a
  custom `pcm_sites_health_interval` schedule. Batch connector health probe.
- `pcm_automation_run_action` — dormant async seam (one-shot `wp_schedule_single_event`
  for long-running automation actions that declare `mode()==='async'`).

## Surfaces (the route table future sessions rely on)
All routes registered under ONE global namespace **`pcm/v1`** (`base-controller.php:29`).
The `rest_namespace` in each `config.php` is **descriptive metadata only** — the
module prefix is baked into each route's path. Auth tiers:
- **`manage_options`** (default for admin-only modules): nonce + WP-cap OR gate-auth visitor.
- **`edit_posts`** (default for work modules): same gate, usable by assigned non-admin team members.
- **`manage_options:strict`** → real WP admin ONLY, no gate bypass (seohub tenant CRUD).
- **`manage_options:coadmin`** → WP admin OR platform admin (users module).
- **`public`** → `__return_true`; handler self-authenticates (token/HMAC/state).

`approvals` is the ONLY module that overrides `PCM_REST_Base::register()` (for its
public token routes). Every response goes through `$this->success()/error()/not_found()`,
never raw `WP_REST_Response`.

### approvals — `pcm/v1/approvals/*`
| Method | Path | File:Line | Auth/Cap | Purpose |
|---|---|---|---|---|
| GET | /approvals/sets | controller.php:64 | read | List caller's approval sets |
| POST | /approvals/sets | controller.php:65 | edit_posts | Create approval set |
| PATCH | /approvals/sets/{id}/status | controller.php:66 | edit_posts | Update set review status |
| DELETE | /approvals/sets/{id} | controller.php:67 | edit_posts | Delete approval set |
| POST | /approvals/sets/bulk/delete | controller.php:68 | edit_posts | Bulk delete sets |
| GET | /approvals/sets/{token} | controller.php:69 | public | Public client view by token |
| POST | /approvals/sets/{token}/review | controller.php:70 | public | Submit client review |
| POST | /approvals/sets/{token}/draft | controller.php:71 | public | Save client draft |
| POST | /approvals/sets/{token}/comment | controller.php:73 | public | Client comment |
| POST | /approvals/sets/{token}/approve | controller.php:74 | public | Client approve action |
| POST | /approvals/sets/{id}/reply | controller.php:76 | edit_posts | Team reply |
| POST | /approvals/sets/{id}/share | controller.php:77 | edit_posts | Share set with client email |
| POST | /approvals/sets/{id}/assets | controller.php:80 | edit_posts | Append assets to set |
| POST | /approvals/sets/{token}/assets/{asset_id} | controller.php:81 | public | Update public snapshot asset |

### assets — `pcm/v1/assets/*` (default `edit_posts`)
| Method | Path | File:Line | Auth/Cap | Purpose |
|---|---|---|---|---|
| GET | /assets | controller.php:47 | edit_posts | List user assets |
| GET | /assets/{id} | controller.php:48 | edit_posts | Get single asset |
| POST | /assets/refine | controller.php:51 | edit_posts | AI refine asset |
| POST | /assets/variations | controller.php:52 | edit_posts | Create variations |
| POST | /assets/export | controller.php:55 | edit_posts | Export asset |
| POST | /assets/save-to-project | controller.php:56 | edit_posts | Save to project |
| GET | /assets/projects | controller.php:59 | edit_posts | List asset projects |
| POST | /assets/projects | controller.php:60 | edit_posts | Create project |
| DELETE | /assets/projects/{id} | controller.php:61 | edit_posts | Delete project |
| PATCH | /assets/projects/{id} | controller.php:62 | edit_posts | Rename project |
| POST | /assets/projects/{id}/duplicate | controller.php:63 | edit_posts | Duplicate project |
| PATCH | /assets/projects/{id}/delivery | controller.php:64 | edit_posts | Assign project to delivery |
| PATCH | /assets/projects/{id}/site | controller.php:65 | edit_posts | Assign project to site |
| POST | /assets/projects/{id}/images | controller.php:68 | edit_posts | Register media images to project |

### automations — `pcm/v1/automations/*` (default `manage_options`)
| Method | Path | File:Line | Auth/Cap | Purpose |
|---|---|---|---|---|
| GET | /automations | controller.php:33 | manage_options | List automation rules |
| POST | /automations | controller.php:34 | manage_options | Create automation rule |
| PATCH | /automations/{id} | controller.php:35 | manage_options | Update automation rule |
| DELETE | /automations/{id} | controller.php:36 | manage_options | Delete automation rule |
| GET | /automations/catalog | controller.php:37 | manage_options | Builder catalog |
| GET | /automations/logs | controller.php:38 | manage_options | Recent send log |
| POST | /automations/test | controller.php:39 | manage_options | Test-fire automation |

### brands — `pcm/v1/brands/*` (reads `edit_posts`; writes `manage_options`)
| Method | Path | File:Line | Auth/Cap | Purpose |
|---|---|---|---|---|
| GET | /brands | controller.php:64 | edit_posts | List brands |
| GET | /brands/{id} | controller.php:65 | edit_posts | Get single brand |
| GET | /brands/by-website | controller.php:66 | edit_posts | Find brand by URL |
| POST | /brands | controller.php:70 | manage_options | Create brand |
| PATCH | /brands/{id} | controller.php:71 | manage_options | Update brand |
| DELETE | /brands/{id} | controller.php:72 | manage_options | Delete brand |
| POST | /brands/bulk/delete | controller.php:75 | manage_options | Bulk delete |
| POST | /brands/bulk/duplicate | controller.php:76 | manage_options | Bulk duplicate |
| POST | /brands/{id}/fetch-assets | controller.php:79 | manage_options | Auto-fetch assets |
| POST | /brands/{id}/assets | controller.php:80 | manage_options | Add brand asset |
| POST | /brands/{id}/assets/from-url | controller.php:81 | manage_options | Add asset from URL |
| DELETE | /brands/{id}/assets | controller.php:82 | manage_options | Remove brand asset |
| POST | /brands/{id}/assets/reorder | controller.php:83 | manage_options | Reorder assets |
| POST | /brands/{id}/assets/set-logo | controller.php:84 | manage_options | Set asset as logo |
| POST | /brands/{id}/colors | controller.php:87 | manage_options | Update colors |
| POST | /brands/scrape-url | controller.php:91 | manage_options | Unified brand scrape |

### copy — `pcm/v1/copy/*` (default `edit_posts`, grants `['copy','ads']`)
| Method | Path | File:Line | Auth/Cap | Purpose |
|---|---|---|---|---|
| POST | /copy/generate | controller.php:60 | edit_posts | Generate ad copy |
| POST | /copy/regenerate | controller.php:61 | edit_posts | Regenerate single card |
| POST | /copy/regenerate-batch | controller.php:62 | edit_posts | Regenerate batch |
| POST | /copy/suggest | controller.php:63 | edit_posts | Suggest angles/audiences |
| POST | /copy/duplicate-audience | controller.php:66 | edit_posts | Duplicate audience |
| POST | /copy/rename-audience | controller.php:67 | edit_posts | Rename audience |
| POST | /copy/update-result | controller.php:70 | edit_posts | Update copy result |
| GET | /copy/jobs/{jobId} | controller.php:71 | edit_posts | Get job results |
| POST | /copy/save-to-project | controller.php:72 | edit_posts | Save copy to project |
| GET | /copy/project/{projectId} | controller.php:73 | edit_posts | Get project copy |
| POST | /copy/scrape-url | controller.php:76 | edit_posts | Scrape business info |

### deliveries — `pcm/v1/deliveries/*` (reads `edit_posts`; writes `manage_options`)
| Method | Path | File:Line | Auth/Cap | Purpose |
|---|---|---|---|---|
| GET | /deliveries/type-presets | controller.php:53 | manage_options | Type→modules map |
| GET | /deliveries | controller.php:54 | edit_posts | List deliveries |
| GET | /deliveries/{id} | controller.php:55 | edit_posts | Get single delivery |
| GET | /deliveries/{id}/logs | controller.php:58 | edit_posts | List work logs |
| POST | /deliveries/{id}/logs | controller.php:59 | edit_posts | Append work log |
| POST | /deliveries | controller.php:61 | manage_options | Create delivery |
| PATCH | /deliveries/{id} | controller.php:62 | manage_options | Update delivery |
| PATCH | /deliveries/{id}/lead | controller.php:66 | manage_options | Set delivery lead |
| DELETE | /deliveries/{id} | controller.php:67 | manage_options | Delete delivery |

### image — `pcm/v1/image/*` (default `edit_posts`, grants `['image','ads']`)
| Method | Path | File:Line | Auth/Cap | Purpose |
|---|---|---|---|---|
| POST | /image/concepts | controller.php:59 | edit_posts | Suggest concepts |
| POST | /image/generate | controller.php:60 | edit_posts | Generate single image |
| POST | /image/generate-task | controller.php:63 | edit_posts | Async gen task (Kie.ai) |
| POST | /image/task-result | controller.php:64 | edit_posts | Poll async task |
| POST | /image/generate-batch | controller.php:65 | edit_posts | Generate batch |
| POST | /image/edit | controller.php:66 | edit_posts | Edit image |
| POST | /image/edit-task | controller.php:67 | edit_posts | Async edit task |
| POST | /image/upscale | controller.php:68 | edit_posts | Upscale image |
| POST | /image/suggestions | controller.php:71 | edit_posts | Image suggestions |
| POST | /image/context-suggestions | controller.php:72 | edit_posts | Context suggestions |
| POST | /image/optimize-brief | controller.php:73 | edit_posts | Optimize brief |
| POST | /image/session-upload | controller.php:74 | edit_posts | Session-scoped upload |

### integrations — `pcm/v1/integrations/*` (default `manage_options`)
| Method | Path | File:Line | Auth/Cap | Purpose |
|---|---|---|---|---|
| GET | /integrations | controller.php:38 | manage_options | List integrations |
| GET | /integrations/providers | controller.php:39 | manage_options | Provider catalog |
| GET | /integrations/providers/details | controller.php:40 | manage_options | Provider detail/schema |
| GET | /integrations/brevo/senders | controller.php:41 | manage_options | Brevo verified senders |
| GET | /integrations/proranktracker/urls | controller.php:42 | manage_options | PRT tracked URLs |
| GET | /integrations/proranktracker/ranks | controller.php:43 | manage_options | PRT ranks |
| GET | /integrations/proranktracker/history | controller.php:44 | manage_options | PRT rank history |
| POST | /integrations/proranktracker/page-ranks | controller.php:45 | manage_options | PRT page-level ranks |
| GET | /integrations/gsc/properties | controller.php:46 | manage_options | GSC properties |
| POST | /integrations/gsc/stats | controller.php:47 | manage_options | GSC search stats |
| POST | /integrations/gsc/oauth-start | controller.php:48 | manage_options | Start GSC OAuth flow |
| GET | /integrations/gsc/oauth-callback | controller.php:51 | public | GSC OAuth redirect callback |
| POST | /integrations/validate | controller.php:54 | manage_options | Validate provider key |
| POST | /integrations | controller.php:57 | manage_options | Create integration |
| PATCH | /integrations/{id} | controller.php:58 | manage_options | Update integration |
| POST | /integrations/{id}/toggle | controller.php:59 | manage_options | Toggle active |
| DELETE | /integrations/provider/{provider} | controller.php:60 | manage_options | Delete by provider |
| POST | /integrations/sync | controller.php:63 | manage_options | Sync from localStorage |

### keywords — `pcm/v1/keywords/*` (default `edit_posts`, grants `['keywords']`)
| Method | Path | File:Line | Auth/Cap | Purpose |
|---|---|---|---|---|
| GET | /keywords/prefixes | controller.php:36 | edit_posts | Language prefix list |
| POST | /keywords/search | controller.php:39 | edit_posts | Google Autocomplete proxy |
| POST | /keywords/enrich | controller.php:42 | edit_posts | Ahrefs volume/KD/CPC enrich |
| GET | /keywords/lists | controller.php:45 | edit_posts | List saved lists |
| POST | /keywords/lists | controller.php:46 | edit_posts | Save a list |
| GET | /keywords/lists/{id} | controller.php:47 | edit_posts | Load a list |
| DELETE | /keywords/lists/{id} | controller.php:48 | edit_posts | Delete a list |

### models — `pcm/v1/models/*` (default `edit_posts`)
| Method | Path | File:Line | Auth/Cap | Purpose |
|---|---|---|---|---|
| GET | /models | controller.php:65 | edit_posts | List models |
| GET | /models/{id} | controller.php:66 | edit_posts | Get single model |
| GET | /models/provider/{provider} | controller.php:67 | edit_posts | Models by provider |
| GET | /models/generation/{type} | controller.php:68 | edit_posts | Models for generation type |
| GET | /models/editing/{type} | controller.php:69 | edit_posts | Models for editing type |
| POST | /models | controller.php:72 | edit_posts | Create model |
| PATCH | /models/{id} | controller.php:73 | edit_posts | Update model |
| DELETE | /models/{id} | controller.php:74 | edit_posts | Delete model |
| POST | /models/{id}/capability | controller.php:77 | edit_posts | Update capability |
| POST | /models/{id}/confirm | controller.php:78 | edit_posts | Confirm model status |
| POST | /models/{id}/toggle-module | controller.php:79 | edit_posts | Toggle module visibility |
| POST | /models/sync | controller.php:80 | edit_posts | Sync from integrations |
| POST | /models/resync | controller.php:81 | edit_posts | Resync single provider |
| POST | /models/bulk/delete | controller.php:84 | edit_posts | Bulk delete |
| POST | /models/bulk/tier | controller.php:85 | edit_posts | Bulk change tier |
| POST | /models/bulk/toggle-module | controller.php:86 | edit_posts | Bulk toggle module |
| POST | /models/bulk/toggle-enabled | controller.php:87 | edit_posts | Bulk toggle enabled |
| POST | /models/auto-detect | controller.php:90 | edit_posts | Auto-detect capabilities |
| POST | /models/confirm-all-suggested | controller.php:91 | edit_posts | Confirm all suggested |

### notifications — `pcm/v1/notifications/*` (default `edit_posts`)
| Method | Path | File:Line | Auth/Cap | Purpose |
|---|---|---|---|---|
| GET | /notifications | controller.php:35 | edit_posts | List + unseen count |
| POST | /notifications/seen | controller.php:36 | edit_posts | Reset unseen badge |
| POST | /notifications/clear | controller.php:37 | edit_posts | Hide all past |

### optimizer — `pcm/v1/optimizer/*` (default `edit_posts`)
| Method | Path | File:Line | Auth/Cap | Purpose |
|---|---|---|---|---|
| GET | /optimizer/teachers | controller.php:32 | edit_posts | List optimizer teachers |
| POST | /optimizer/analyze | controller.php:33 | edit_posts | Analyze content |
| POST | /optimizer/compile | controller.php:34 | edit_posts | Compile optimization rules |
| POST | /optimizer/keywords/stats | controller.php:36 | edit_posts | GSC per-query keyword stats |
| GET | /optimizer/keywords | controller.php:37 | edit_posts | Get keyword bucket |
| POST | /optimizer/keywords | controller.php:38 | edit_posts | Save keywords |
| POST | /optimizer/keywords/volumes | controller.php:39 | edit_posts | Fetch keyword volumes |
| POST | /optimizer/history | controller.php:41 | edit_posts | Stamp optimization event |
| GET | /optimizer/history | controller.php:42 | edit_posts | Read optimization history |

### prompts — `pcm/v1/prompts/*` (default `manage_options`)
| Method | Path | File:Line | Auth/Cap | Purpose |
|---|---|---|---|---|
| GET | /prompts/{module} | controller.php:37 | manage_options | List prompt sections |
| GET | /prompts/{module}/{section}/variants | controller.php:38 | manage_options | List variants |
| GET | /prompts/{module}/{section} | controller.php:43 | manage_options | Get active prompt |
| POST | /prompts | controller.php:50 | manage_options | Create variant |
| POST | /prompts/{id}/duplicate | controller.php:51 | manage_options | Duplicate variant |
| PATCH | /prompts/{id} | controller.php:52 | manage_options | Update variant |
| DELETE | /prompts/{id} | controller.php:53 | manage_options | Delete variant |
| POST | /prompts/{id}/default | controller.php:56 | manage_options | Set default variant |
| POST | /prompts/sync-placeholders | controller.php:57 | manage_options | Sync placeholders |

### scraper — `pcm/v1/scraper/*` (default `manage_options`, SSRF surface)
| Method | Path | File:Line | Auth/Cap | Purpose |
|---|---|---|---|---|
| GET | /scraper/collections | controller.php:58 | manage_options | List collections |
| GET | /scraper/collections/{id} | controller.php:59 | manage_options | Get collection |
| DELETE | /scraper/collections/{id} | controller.php:60 | manage_options | Delete collection |
| PATCH | /scraper/images/{id} | controller.php:63 | manage_options | Toggle image exclusion |
| POST | /scraper/scrape | controller.php:66 | manage_options | Scrape URL |
| POST | /scraper/analyze | controller.php:67 | manage_options | Analyze page images |
| POST | /scraper/smart-select | controller.php:68 | manage_options | Smart-select images |
| POST | /scraper/extract-text | controller.php:69 | manage_options | Extract text from URL |

### seo — `pcm/v1/seo/*` (default `edit_posts`; remote suite + AI/llm-info/site/GBP/export `manage_options`)
The largest module (~86 routes). Local content + the remote `/seo/sites/{id}/*`
suite (proxied through the SEO Hub connector via `PCM_SEOHub_Service::remote_request`,
Basic auth with the connected site's WP Application Password). Remote reads use a
`?cached=1` SWR pattern: instant from the hub store, live-fetch revalidates.

**Local content (default `edit_posts`):**
| Method | Path | File:Line | Auth/Cap | Purpose |
|---|---|---|---|---|
| GET | /seo/content/options | controller.php:42 | edit_posts | Dropdown data + SEO plugin |
| POST | /seo/content/bulk-delete | controller.php:43 | edit_posts | Bulk delete |
| GET | /seo/content | controller.php:44 | edit_posts | List posts/pages w/ SEO |
| POST | /seo/content | controller.php:45 | edit_posts | Quick-create draft |
| POST | /seo/content/{id}/duplicate | controller.php:46 | edit_posts | Duplicate item |
| POST | /seo/content/{id}/cell | controller.php:47 | edit_posts | Save grid cell |
| POST | /seo/content/{id}/generate | controller.php:48 | edit_posts | Generate SEO field |
| POST | /seo/content/{id}/scan-links | controller.php:49 | edit_posts | Scan links |
| GET | /seo/content/{id}/links | controller.php:50 | edit_posts | Get links |
| POST | /seo/content/{id}/links/{idx} | controller.php:51 | edit_posts | Update link |
| POST | /seo/content/{id}/links/{idx}/remove | controller.php:52 | edit_posts | Remove link |
| GET | /seo/content/{id}/headings | controller.php:53 | edit_posts | Get headings |
| GET | /seo/content/{id}/content-nodes | controller.php:54 | edit_posts | Get content nodes |
| POST | /seo/content/{id}/headings/{idx} | controller.php:55 | edit_posts | Update heading |
| POST | /seo/content/{id}/headings/{idx}/optimize | controller.php:56 | edit_posts | Optimize heading |
| GET | /seo/content/{id}/body | controller.php:57 | edit_posts | Get body |
| POST | /seo/content/{id}/body | controller.php:58 | edit_posts | Save body |
| POST | /seo/content/{id}/optimize | controller.php:59 | edit_posts | Optimize body |
| GET | /seo/content/{id}/schema | controller.php:130 | edit_posts | Get post schema |
| POST | /seo/content/{id}/schema | controller.php:131 | edit_posts | Set post schema |
| GET | /seo/views | controller.php:111 | edit_posts | List saved views |
| POST | /seo/views | controller.php:112 | edit_posts | Create view |
| PATCH | /seo/views/{id}/default | controller.php:113 | edit_posts | Set default view |
| DELETE | /seo/views/{id} | controller.php:114 | edit_posts | Delete view |

**Remote connected-site suite (all `manage_options`) — selected:**
| Method | Path | File:Line | Purpose |
|---|---|---|---|
| GET | /seo/sites/{id}/content | controller.php:62 | List remote content |
| POST | /seo/sites/{id}/content | controller.php:63 | Create remote content |
| POST | /seo/sites/{id}/content/{post}/cell | controller.php:64 | Save remote cell |
| POST | /seo/sites/{id}/content/{post}/generate | controller.php:65 | Generate remote field |
| POST | /seo/sites/{id}/content/{post}/scan-links | controller.php:66 | Scan remote links |
| GET | /seo/sites/{id}/site | controller.php:67 | Get remote site SEO |
| POST | /seo/sites/{id}/site | controller.php:68 | Save remote site SEO |
| GET | /seo/sites/{id}/ai | controller.php:70 | Get remote AI readiness |
| POST | /seo/sites/{id}/ai/build | controller.php:72 | Build remote AI readiness |
| POST | /seo/sites/{id}/content/{post}/schema | controller.php:75 | Set remote schema |
| POST | /seo/sites/{id}/content/{post}/delete | controller.php:76 | Delete remote content |
| POST | /seo/sites/{id}/content/{post}/duplicate | controller.php:77 | Duplicate remote content |
| GET | /seo/sites/{id}/content/{post}/links | controller.php:78 | Remote links |
| GET | /seo/sites/{id}/content/{post}/headings | controller.php:81 | Remote headings |
| GET | /seo/sites/{id}/content/{post}/inventory | controller.php:83 | Remote inventory |
| POST | /seo/sites/{id}/push-config | controller.php:85 | Push config to remote |
| GET | /seo/sites/{id}/content/{post}/rules | controller.php:86 | List remote section rules |
| POST | /seo/sites/{id}/content/{post}/section-rule | controller.php:87 | Save remote section rule |
| POST | /seo/sites/{id}/content/{post}/page-edits | controller.php:88 | Save remote page edits |
| POST | /seo/sites/{id}/content/{post}/image-rule | controller.php:89 | Save remote image rule |
| GET | /seo/sites/{id}/redirects | controller.php:91 | List remote redirects |
| POST | /seo/sites/{id}/redirects | controller.php:92 | Save remote redirect |
| GET | /seo/sites/{id}/content/{post}/page-state | controller.php:98 | Remote page-state fingerprint |
| GET | /seo/sites/{id}/content/{post}/section-versions | controller.php:99 | Remote section versions |
| POST | /seo/sites/{id}/content/{post}/headings/{idx} | controller.php:102 | Update remote heading |
| POST | /seo/sites/{id}/content/{post}/featured | controller.php:104 | Set remote featured image |
| POST | /seo/sites/{id}/preview | controller.php:105 | Remote preview render |
| GET | /seo/sites/{id}/llm-info | controller.php:106 | Remote llm-info |
| (full remote list at controller.php:62–109, 142–146) | | | |

**Local AI-readiness / llm-info / site / GBP / export (all `manage_options`):**
| Method | Path | File:Line | Purpose |
|---|---|---|---|
| GET | /seo/ai-readiness | controller.php:116 | Local AI-readiness status |
| POST | /seo/ai-readiness/build | controller.php:117 | Build AI-readiness |
| POST | /seo/ai-readiness/publish | controller.php:118 | Publish AI-readiness |
| GET | /seo/llm-info | controller.php:125 | Get local llm-info |
| POST | /seo/llm-info/build | controller.php:127 | Build local llm-info |
| GET | /seo/site | controller.php:133 | Get local site SEO |
| POST | /seo/site | controller.php:134 | Save local site SEO |
| GET | /seo/sites/{id}/business | controller.php:142 | Get site business card |
| POST | /seo/gbp/search | controller.php:147 | Search Google Business Profiles |
| GET | /seo/gbp/brand/{brand} | controller.php:149 | Get brand GBP record |
| GET | /seo/export | controller.php:152 | Export SEO config |
| POST | /seo/import | controller.php:153 | Import SEO config |

### seohub — `pcm/v1/seohub/*` (tenant CRUD `manage_options:strict`; handshake `public`)
| Method | Path | File:Line | Auth/Cap | Purpose |
|---|---|---|---|---|
| GET | /seohub/sites | controller.php:28 | manage_options:strict | List connector tenants |
| POST | /seohub/sites | controller.php:29 | manage_options:strict | Create tenant |
| POST | /seohub/sites/{id}/revoke | controller.php:30 | manage_options:strict | Revoke tenant |
| DELETE | /seohub/sites/{id} | controller.php:31 | manage_options:strict | Delete tenant |
| GET | /seohub/sites/{id}/connector | controller.php:32 | manage_options:strict | Download site connector zip |
| GET | /seohub/connector-download | controller.php:33 | manage_options:strict | Download generic connector zip |
| POST | /seohub/connector/hello | controller.php:35 | public | Connector HMAC handshake |
| GET | /seohub/connector-manifest | controller.php:38 | public | Connector self-update manifest |
| GET | /seohub/connector-package | controller.php:39 | public | Connector zip package |

### settings — `pcm/v1/settings` (read `read`; write `manage_options`)
| Method | Path | File:Line | Auth/Cap | Purpose |
|---|---|---|---|---|
| GET | /settings | controller.php:26 | read | Get all settings |
| POST | /settings | controller.php:29 | manage_options | Update settings |

### sites — `pcm/v1/sites/*` (default `edit_posts`, grants `['sites']`)
| Method | Path | File:Line | Auth/Cap | Purpose |
|---|---|---|---|---|
| GET | /sites | controller.php:33 | edit_posts | List connected sites |
| POST | /sites | controller.php:34 | edit_posts | Connect a site |
| GET | /sites/{id} | controller.php:35 | edit_posts | Get site |
| PATCH | /sites/{id} | controller.php:36 | edit_posts | Update site |
| DELETE | /sites/{id} | controller.php:37 | edit_posts | Delete site |
| POST | /sites/{id}/publish | controller.php:38 | edit_posts | Publish article to site |
| POST | /sites/{id}/test | controller.php:39 | edit_posts | Test connector |
| GET | /sites/health | controller.php:41 | edit_posts | Batch connector health |
| POST | /sites/{id}/gsc-verify | controller.php:42 | edit_posts | GSC verification |
| POST | /sites/{id}/gsc-preview | controller.php:43 | edit_posts | Preview GSC data |
| POST | /sites/update-connectors | controller.php:44 | edit_posts | Batch update connectors |
| GET | /sites/{id}/schedule | controller.php:45 | edit_posts | Get content schedule |
| POST | /sites/{id}/schedule | controller.php:46 | edit_posts | Set content schedule |
| GET | /sites/{id}/connector-version | controller.php:47 | edit_posts | Get connector version |
| POST | /sites/{id}/update-connector | controller.php:48 | edit_posts | Push connector update |

### strategy — `pcm/v1/strategies/*` (default `edit_posts`, grants `['strategies']`)
| Method | Path | File:Line | Auth/Cap | Purpose |
|---|---|---|---|---|
| GET | /strategies | controller.php:41 | edit_posts | List strategies |
| GET | /strategies/schedule | controller.php:46 | edit_posts | Scheduled-strategy feed |
| POST | /strategies | controller.php:47 | edit_posts | Create strategy |
| GET | /strategies/{id} | controller.php:48 | edit_posts | Get strategy |
| PATCH | /strategies/{id} | controller.php:49 | edit_posts | Update strategy |
| DELETE | /strategies/{id} | controller.php:50 | edit_posts | Delete strategy |
| POST | /strategies/{id}/generate | controller.php:51 | edit_posts | Generate item (or retry `{itemId}`) |
| POST | /strategies/{id}/duplicate | controller.php:52 | edit_posts | Duplicate strategy |
| PATCH | /strategies/{id}/items/{itemId} | controller.php:53 | edit_posts | Update item status |
| DELETE | /strategies/{id}/items/{itemId} | controller.php:54 | edit_posts | Delete item |
| POST | /strategies/{id}/items/{itemId}/publish | controller.php:55 | edit_posts | Publish item |
| POST | /strategies/{id}/items/{itemId}/post-status | controller.php:57 | edit_posts | Set the LIVE post's status on the site: draft \| publish \| trash (trash = recoverable, `force=false`) |
| POST | /strategies/{id}/items/{itemId}/duplicate | controller.php:58 | edit_posts | Re-queue the item as a FRESH pending copy (no articleId/setId/scheduledDate — it regenerates, never clones output) |
| POST | /strategies/{id}/interlinks | controller.php:56 | edit_posts | Run interlink pass |
| POST | /strategies/{id}/sync-status | controller.php:57 | edit_posts | Sync publish status |
| POST | /strategies/{id}/scan | controller.php:60 | edit_posts | Manual scan-now (RSS/Social) |
| POST | /strategies/{id}/reapply-parent | controller.php:61 | edit_posts | Re-apply parent links |
| GET | /strategies/keepalive | controller.php:67 | public | Keep-alive chain link |
| POST | /strategies/keepalive | controller.php:68 | public | Keep-alive chain link |
| GET | /strategies/cron-info | controller.php:70 | edit_posts | Background-scan cron health |

**Strategy `config` keys added 2026-07-27/28** (all whitelisted in `sanitize_config_fields()`, merged not
replaced by `merge_strategy_config()`, so a partial PATCH preserves everything else):
- **Per-JOB models.** `model`/`provider` = TEXT, `imageModel`/`imageProvider` = IMAGES, and NEW
  `researchModel`/`researchProvider` = the grounded/deep RESEARCH passes. Research was previously a
  hardcoded `gemini-2.5-flash` inside `run_grounding_call()`; it now resolves via
  `resolve_research_model()` (same shape as `resolve_model()`), still defaulting to that model.
- **`imageTemplateId`** — an `image`-module template that writes the FEATURED-image prompt via
  `build_image_prompt()` (`{{ title }}` / `{{ keyword }}` / `{{ brand_language }}`). Every failure path
  (unset, id 0, deleted template, empty render) falls back to the original hardcoded sentence, so an
  image prompt can never come out blank.
- **`rssCadence.unit`** (`day|week|month`, default `week`). `perWeek` keeps its historical key name but is
  now just the COUNT; the trailing backpressure window follows the unit via
  `rss_cadence_window_days()` → 1/7/30. Missing/unknown → 7, so pre-existing configs are unchanged.
- **`scheduleConfig.byMonthDay`** (1–31, monthly only). `calculate_recurrence_dates()`'s month branch is now
  computed from the START anchor with a `min(day, days_in_month)` clamp instead of chaining
  `strtotime('+N month')` — which OVERFLOWED (Jan 31 → Mar 3) and drifted permanently. Day 31 therefore
  means "last day" for 28/29/30-day months.
- **Language.** `build_prompt()` exposes `{{ brand_language }}` and, when the brand sets a language,
  appends an explicit instruction so **title/metaTitle/metaDescription** are written in it — previously the
  brand block only language-locked the article BODY, so the JSON fields came back English.

### templates — `pcm/v1/templates/*` (default `manage_options`)
| Method | Path | File:Line | Auth/Cap | Purpose |
|---|---|---|---|---|
| GET | /templates | controller.php:46 | manage_options | List templates |
| GET | /templates/{id} | controller.php:47 | manage_options | Get template |
| POST | /templates | controller.php:50 | manage_options | Create template |
| PATCH | /templates/{id} | controller.php:51 | manage_options | Update template |
| DELETE | /templates/{id} | controller.php:52 | manage_options | Delete template |
| POST | /templates/bulk/delete | controller.php:55 | manage_options | Bulk delete |
| POST | /templates/bulk/duplicate | controller.php:56 | manage_options | Bulk duplicate |
| POST | /templates/{id}/default | controller.php:59 | manage_options | Set default |
| POST | /templates/reseed | controller.php:60 | manage_options | Reseed defaults |

### users — `pcm/v1/users/*` (all `manage_options:coadmin`)
| Method | Path | File:Line | Auth/Cap | Purpose |
|---|---|---|---|---|
| GET | /users | controller.php:43 | manage_options:coadmin | List platform users |
| POST | /users | controller.php:44 | manage_options:coadmin | Create platform user |
| PUT | /users/{id}/password | controller.php:45 | manage_options:coadmin | Reset password |
| PUT | /users/{id}/role | controller.php:46 | manage_options:coadmin | Change role |
| DELETE | /users/{id} | controller.php:47 | manage_options:coadmin | Delete user |
| PUT | /users/{id}/deliveries | controller.php:48 | manage_options:coadmin | Assign deliveries |

### video — `pcm/v1/video/*` (default `edit_posts`, grants `['video']`)
| Method | Path | File:Line | Auth/Cap | Purpose |
|---|---|---|---|---|
| POST | /video/concepts | controller.php:129 | edit_posts | Suggest concepts |
| POST | /video/enhance-prompt | controller.php:130 | edit_posts | Enhance brief |
| POST | /video/compose-prompt | controller.php:131 | edit_posts | Compose scene prompt |
| POST | /video/generate | controller.php:132 | edit_posts | Generate video |
| POST | /video/generate-task | controller.php:136 | edit_posts | Async video task |
| POST | /video/task-result | controller.php:137 | edit_posts | Poll async task |
| POST | /video/status | controller.php:138 | edit_posts | Check status |
| GET | /video/capabilities | controller.php:139 | edit_posts | List model capabilities |

### writer — `pcm/v1/articles/*` (default `edit_posts`, grants `['writer']`)
| Method | Path | File:Line | Auth/Cap | Purpose |
|---|---|---|---|---|
| GET | /articles | controller.php:32 | edit_posts | List articles |
| POST | /articles | controller.php:33 | edit_posts | Create article |
| GET | /articles/{id} | controller.php:34 | edit_posts | Get article |
| PATCH | /articles/{id} | controller.php:35 | edit_posts | Update article |
| DELETE | /articles/{id} | controller.php:36 | edit_posts | Delete article |
| POST | /articles/generate | controller.php:37 | edit_posts | Generate via AI |
| POST | /articles/upload-image | controller.php:38 | edit_posts | Upload image |
| POST | /articles/{id}/ai-review | controller.php:39 | edit_posts | AI review |
| POST | /articles/{id}/ai-review/apply | controller.php:40 | edit_posts | Apply suggestions |
| GET | /articles/{id}/revisions | controller.php:41 | edit_posts | List revisions |
| GET | /articles/{id}/revisions/{revId} | controller.php:42 | edit_posts | Get revision |
| POST | /articles/{id}/revisions/{revId}/restore | controller.php:43 | edit_posts | Restore revision |

**Public routes total: 12** — approvals `/sets/{token}/*` (6), seohub `/connector/hello` + `/connector-manifest` + `/connector-package` (3), integrations `/gsc/oauth-callback` (1), strategy `/keepalive` GET+POST (2). **Total routes: ~330** (seo alone is 86).

## Database
- Prefix `wp_pcm_`. `PCM_Schema::create_tables()` runs `dbDelta` on ~30 tables:
  `users, integrations, projects, assets, scraped_collections, scraped_images, models,
  copy_jobs, copy_results, templates, brands, brand_business_units (1.43.0),
  brand_assets, prompt_overrides, strategies, strategy_items, articles,
  article_revisions (1.43.0), sites, deliveries, notifications, delivery_assignments,
  delivery_logs, approval_sets, automations, automation_logs, seo_tenants, seo_hmac_nonces,
  seo_views, seo_dynamic_rules (1.37.0), seo_rule_versions (1.38.0), seo_redirects (1.39.0)`.
- Because `dbDelta` can't rename/alter-null/drop, explicit one-off `ALTER`/backfill
  migrations live in `PCM_Schema` (brand column renames, GBP→business-units move,
  SVG→PNG rasterize, approval-status taxonomy, automations nullability, etc.) and are
  gated in `PCM_Activator::maybe_upgrade()` by `version_compare` against stored `pcm_db_version`.
- **Recent migrations (1.37–1.45):**
  - **1.37.0** — `strategy_items.scheduledDate` + `seo_dynamic_rules` (hub render-rules source of truth).
  - **1.38.0** — `strategy_items.setId` (links item→Approvals set) + `seo_rule_versions`. Gate also seeds `strategy.publish_on_approval` automation rule per user.
  - **1.39.0** — `strategy_items.volume/difficulty` (Ahrefs display metrics) + `seo_redirects`.
  - **1.40–1.42** — convergence bumps (no schema); merge stabilization.
  - **1.43.0** — `article_revisions` (Writer history; `PCM_DB::add_article_revision` prunes to 10 newest) + `brand_business_units` + `sites.businessUnitId`. Gated: `migrate_gbp_to_business_units()` moves `pcm_seo_gbp_{brandId}` options into the PRIMARY unit.
  - **1.44.0** — gated data backfill: exact-host site→brand auto-map (www-insensitive).
  - **1.45.0** — gated catch-up: re-runs `migrate_gbp_to_business_units()` for installs already on the parallel-branch 1.43.0.
- **Options owned**: only `pcm_db_version` and `pcm_settings` (single settings blob, `PCM_Settings::OPTION_KEY`). Settings keys include `global_webhook_url`, `automations_webhook_secret`, `automations_from_email`, `automations_from_name`, `module_email_senders`. Plus per-feature options: `pcm_seo_air_published`, `pcm_seo_llminfo`, `pcm_seo_apify`, `pcm_seohub_conn_pkg` (sha256-pinned connector artifact cache).
- **User meta**: `pcm_keyword_lists`. **Post meta**: attachment metadata written in scraper/storage; `pcm_seo_*` keys (metaTitle, metaDescription, primaryKeyword, schema) cross-plugin-mapped via `PCM_SEO_Service::seo_key_map()` (Yoast/RankMath/SEOPress + native).

## Core infrastructure (one line each)
| Class | File | Responsibility |
|---|---|---|
| `PCM_Settings` | `core/class-pcm-settings.php` | Single-`wp_options` wrapper (`OPTION_KEY='pcm_settings'`); get/set/set_many/install_defaults. |
| `PCM_Schema` | `core/db/class-pcm-schema.php` | dbDelta DDL for all tables; `prefix()='wp_pcm_'`, `table($short)`; migration helpers. |
| `PCM_DB` | `core/db/class-pcm-db.php` | Typed CRUD (users/integrations/models/brands/deliveries/templates/strategies+items/articles+revisions/sites); 5-min transient cache + invalidation; atomic claim/complete/reclaim for strategy items. |
| `PCM_Hierarchy` | `core/class-pcm-hierarchy.php` | Source of truth for Brand→Delivery→Project→Site chain (all derived LIVE, never stored). `for_project()` returns `{projectId,deliveryId,brandId,siteId}`. |
| `PCM_GSC` | `core/class-pcm-gsc.php` | Zero-dep Google Search Console client (SA-JWT OR OAuth refresh-token); page/query stats, list/match/add/verify property. |
| `PCM_Providers` | `core/class-pcm-providers.php` | Static provider metadata registry (apiKeyUrl, knownModels, capability flags). |
| `PCM_Access` | `core/class-pcm-access.php` | Team-access grants via delivery assignments; `scope_clause()` for "owned OR granted" SQL. |
| `PCM_Gate_Auth` | `core/class-pcm-gate-auth.php` | HMAC-signed cookie gate auth (`pcm_shortcode_auth`); platform users only (bcrypt). |
| `PCM_Template_Seeds` / `PCM_Prompt_Seeds` | `core/class-pcm-*-seeds.php` | Seed default templates + prompt variants (userId=0; idempotent on name+module). |
| `PCM_LLM` | `core/llm/class-pcm-llm.php` | Central text-LLM dispatcher: `invoke`/`invoke_json`/`invoke_with_grounding`. 3-tier JSON fidelity (json_schema → json_object → prompt-only) with refusal/truncation/repair/salvage recovery. |

**Provider system** — `PCM_Provider_Registry` (`core/providers/`): generation-capable impls `openai`, `google`, `kieai`, `fal` implementing `PCM_Provider_Interface` (`generate_image/generate_video/edit_image/validate_key`). `detect_provider()` is **deprecated** — routing is data-driven via `model.provider`. Provider metadata registry also carries: `ahrefs`, `proranktracker`, `gsc`, `apify` (supportsSeo + supportsSocial), `google_places`, `brevo` (supportsEmail).

## External services & credentials
All per-user provider API keys live in **`wp_pcm_integrations`** (`apiKey` text), keyed by `(provider, userId, isActive)`. Unified lookup: `PCM_LLM::get_api_key($provider,$user_id)` (LLMs) / Integrations controller `get_provider_api_key()`.
| Service | Impl / endpoint | Key location |
|---|---|---|
| OpenAI | `PCM_Provider_OpenAI` | integrations `openai` |
| Google (Gemini/Veo) | `PCM_Provider_Google`, `PCM_Google_Veo_API` | integrations `google` |
| Kie.ai | `PCM_Provider_KieAI`, `PCM_Kie_API` | integrations `kieai` |
| Fal | `PCM_Provider_Fal`, `PCM_Fal_API` | integrations `fal` |
| Brevo (email) | `api.brevo.com/v3/smtp/email` + `/v3/senders` | integrations `brevo`; sender = rule → module → global `automations_from_email` |
| Google Search Console | `class-pcm-gsc.php` (oauth2/token) | integrations `gsc` — TWO cred types: SA-JSON paste `{type:service_account,…}` OR OAuth `{type:oauth,client_id,client_secret,refresh_token}` |
| Apify (social/GBP scraping) | `strategy/class-pcm-apify.php` (`api.apify.com/v2/acts/{actor}/run-sync-get-dataset-items`) | integrations `apify`; `PCM_Apify::get_token($uid)` |
| Google Places (GBP) | `seo/gbp.php` `PCM_SEO_GBP_Google_Provider` | integrations `google_places` |
| ProRankTracker | `api.proranktracker.com/v3` | integrations `proranktracker` |
| SEO Hub connector | `seohub/service.php` | Per-tenant `clientId`+`clientSecret` in `wp_pcm_seo_tenants` + the connected site's WP Application Password (Basic-auth REST callbacks back to the site) |
| Webhooks | `PCM_Webhook_Channel` | URL: rule → brand → `global_webhook_url`; secret: rule → `automations_webhook_secret` |

## Automations engine (cross-module IF→THEN)
- Core: `includes/modules/automations/service.php` (`PCM_Automation_Engine`). Rules in `wp_pcm_automations`; dispatch audit in `wp_pcm_automation_logs` (stores `payloadHash`, never secrets).
- **`fire_trigger($triggerId, $context, $userId [, $opts])`** — opts: `ruleId` (fire one rule), `dedupeKey` (caller idempotency slice — matches any prior log row in that slice), `skipConditions` (bypass equality matcher — used by the time-based scanner).
- **Registration seams**: `register_channel()` (built-ins: webhook, Brevo email), `register_action_handler()` (modules register their own in `automations.php`), `PCM_Automation_Triggers::register()` / `PCM_Automation_Actions::register()`. Conditions evaluated by `conditions_match()` (MVP equality; empty = "any").
- **Modules shipping `automations.php`** (globbed at `power-creatives.php:97`): `approvals`, `brands`, `strategy`, `models`, `automations`.
  - approvals emits: `set_status_changed`, `set_shared`, `set_fully_approved`, `comment_added`, `asset_approved`, `set_pending_in_client` (time-based). Handlers: `PCM_Move_Lane_Action_Handler`, `PCM_Notification_Action_Handler`.
  - strategy: action `strategy.publish_on_approval` (handler `PCM_Publish_On_Approval_Action_Handler`) + trigger `strategy.completed`.
  - brands: `brands.brand_created`.
- **Async actions** declare `mode()==='async'` → engine schedules via `wp_schedule_single_event('pcm_automation_run_action', …)` and returns immediately. Currently dormant (webhook is sync).
- The fully-approved→Launch and shared→'Sent to Client' lane moves are **editable automation rules**, NOT hardcoded business logic. **Don't hardcode** business detail in the seeder — URLs, days, recipients stay user-editable on the rule.

## Frontend (`app/src/`)
- **Build**: `app/package.json` scripts — `dev`/`build`/`build:watch` = `vite --config vite.config.wp.ts`; `check` = `tsc --noEmit`. `vite.config.wp.ts`: outDir `app/dist`, **fixed filenames** `index-writer.js` + `index.css` (no hash so PHP can reference them), `__PCM_BUILD__` stamp, aliases `@`→`src/` + `@shared`→`shared/`. **Mac build gotcha:** use `node node_modules/vite/bin/vite.js build --config vite.config.wp.ts` (the copied `.bin` shims are broken).
- **State**: Jotai (Writer only, `modules/Writer/store.ts`); TanStack Query (`main.tsx`, staleTime 30s) + TanStack Table everywhere. The "tRPC" client (`lib/trpc.ts`) is a `fetch`-based **proxy adapter** over REST — `trpc.<router>.<proc>.useQuery/.useMutation()` → `X-WP-Nonce`-authed REST calls. Route catalog: `lib/trpc-routes.ts`. `window.pcmConfig` (restUrl/nonce/user) injected by `class-pcm-admin.php`.
- **Mounting**: admin SPA at `toplevel_page_power-creatives` — `class-pcm-admin.php:78` enqueues `app/dist/index.css` + `index-writer.js` (version=`time()` cache-bust), `wp_localize_script('pcm-app','pcmConfig',…)` renders `<div id="pcm-root">`. Shortcode `[power_creatives]` (`class-pcm-shortcode.php:34`) — fullscreen (`.pcm-fs-wrap`) or inline (`.pcm-inline-wrap`) modes + password gate. `App.tsx` short-circuits to `<ClientReviewPage token>` when `?pcm_public_token=` is present.
- **Modules** (`app/src/modules/`, 20 dirs → backend module): Ads, Approvals (client-review portal), Assets, Automations (rule builder), Brands (flat inline table), Copy (batch gen), Deliveries (logs/lead), Image, Integrations (keys + GSC + Brevo + PRT), Keywords, Logs (→automations/logs), Projects (→assets/projects), SEO (spreadsheet, local + remote), Settings (+prompts), Sites (connector mgmt, GSC, publish), Strategies (schedule, scan), Templates (flat table), Users, Video, Writer (Jotai+TipTap rich editor).
- ⛔ **UI HARD RULES (owner mandate — read CLAUDE.md § "HARD RULES — UI" before any UI change).**
  (1) Design comes from the shared layer only: `@/components/ui/*` primitives,
  `@/components/shared/*` components, and the `colors`/`typography`/`spacing`/`shadows`/
  `statusColors` tokens in `@/components/shared/design-tokens.ts`. No transparent backgrounds,
  no wp-admin style leakage, no raw hex, no inline `style={{}}` for color/spacing/size, no
  local re-implementation — if it doesn't exist, ADD it to the shared layer and reuse it.
  (2) Functionality reuses the shared component's built-in behavior (state, keyboard, a11y,
  search, empty/loading); extend the shared component rather than forking or hand-rolling.
- **Reusable table kit** — DON'T hand-roll `<table>`: (1) `@/components/ui/data-table.tsx` (config-driven, simple) — ref `modules/Sites`; (2) rich spreadsheet → `@/components/ui/column-head.tsx` + `@/hooks/useColumnLayout.ts` (unique storageKey) + `@/hooks/useColumnFilters.ts` — ref `modules/SEO`.

## SEO suite detail
The SEO capability spans `includes/modules/seo/` (hub-local + remote proxy, service is the largest file ~365KB) and `includes/modules/seohub/` (connector distribution). **Cross-plugin meta**: `seo_key_map()` / `detect_seo_plugin()` supports Yoast/RankMath/SEOPress + native `pcm_seo_*`. **Schema** (`seo/schema.php`): 6 JSON-LD types (Article, WebPage, BreadcrumbList, FAQPage, HowTo, Product), emitted `wp_head` priority 1. **AI Readiness** (`seo/ai-readiness.php`): virtual `/llms.txt`, `/llms-full.txt`, `/{slug}.md` served via `template_redirect` when published (option `pcm_seo_air_published`); page-builder-aware (Elementor/Divi/Brizy). **GBP** (`seo/gbp.php`): provider behind `PCM_SEO_GBP_Provider` — Google Places (native) or Apify (`compass~crawler-google-places`); stored on the brand's PRIMARY business unit (`brand_business_units`), fetched layer + manual overrides.

**SEO Hub connector** — two connect paths: (1) HMAC tenant ZIP (`POST /seohub/sites` → `clientId`/`clientSecret` baked in); (2) pairing-code "one-paste" generic connector (`/seohub/connector-download`) that creates a WP Application Password on the connected site and shows a base64 code. HMAC-SHA256 auth (±300s window + nonce replay guard via `seo_hmac_nonces`). Connector self-update feed: `/connector-manifest` + `/connector-package` (sha256-pinned, byte-identical to the manifest hash; connector verifies download sha256 via `hash_equals` — supply-chain protection, never skipped). **Connector version is currently 3.0.8** (NOT 2.x — the old "2.2.3" references are stale; the Brizy/Elementor builder handling is now first-class in the connector template at `seohub/service.php:760+`). ⚠ **Connector version is SEPARATE from `PCM_VERSION`** — connector changes require re-download + reinstall on each connected site.

## Content Strategies pipeline (`includes/modules/strategy/`)
Pipeline: keyword list (or RSS/social source) → per-item AI article generation → `wp_pcm_articles`. `generate_next_item()` (`service.php:1225`) is the heart: atomic claim→generate→complete with stale-reclaim guards. **Selectable AI model** stored in `config.model`/`config.provider`, passed data-driven to `PCM_LLM::invoke_json()`. **Auto-publish** (`maybe_auto_publish()`, `service.php:2584`): when `publishingMode` is publish/schedule + `config.siteId` resolves → reuses `PCM_Sites_Service::publish_to_site()` verbatim. **Social source via Apify** (`class-pcm-apify.php`, `class-pcm-social-source.php`): RSS watcher + Apify account-watching (instagram/tiktok/x/facebook require the user's Apify token). **"Scan now"** = `POST /strategies/{id}/scan` (bypasses the 4h cadence, runs synchronously ~40-60s). **Keep-alive chain** (`/strategies/keepalive`, public token-auth) bootstraps background processing on any visit. **Wedge recovery**: `reclaim_stale_generating()` (10-min, per-strategy) + `reclaim_wedged_items()` (90-min global sweep). `{{ post_content }}`/`{{ post_title }}`/`{{ post_link }}` template vars + post-image reuse + `strip_emoji()` on social-sourced articles (carves out 12 ordinary-typography chars ✓✗♠♥♦♣♪♫✂✈✉✏ via private-use sentinel swap).

## Approvals (`includes/modules/approvals/`)
Client approval boards + public share links. Four asset buckets: `media`, `copy`, `articles`, `custom` (Notion-style custom card). Snapshot is the source of truth (`{media:[],copy:[],articles:[],custom:[]}`) — client edits mutate it in place. **`wp_pcm_assets` is OFF-LIMITS** to the Approvals domain (per-item metadata → snapshot JSON). Triggers emitted (see Automations engine). **Brand→Delivery→Project chain**: `PCM_Hierarchy::for_project()` derives all LIVE from `projects.deliveryId` → `deliveries.brandId`; `enrich_context()` (`service.php:863`) builds the webhook/automation token map (setID/setName/setLink/brandName/brandExtID/deliveryName/projectAssignee etc.). Reassigning a project moves everything with it (never store/hardcode brandId/deliveryId on attached entities).

## Where to add a <thing>
- **New REST module** (the standard way to add a feature):
  1. `includes/modules/{name}/config.php` → return `['id','name','version','controller'=>'PCM_REST_Name','rest_namespace'=>'pcm/v1/name']`
  2. `includes/modules/{name}/controller.php` extends `PCM_REST_Base`, define `routes()` returning `[METHOD,'/path','callback',?args,?capability]`. Logic in `service.php`.
  3. (frontend) `app/src/modules/{Name}/` dumb UI + register routes in `app/src/lib/trpc-routes.ts`.
  No edits to `power-creatives.php` — the loader auto-discovers it.
- **New single route on an existing module**: add to that controller's `routes()`.
- **New custom table/column**: add to `PCM_Schema::create_tables()` (dbDelta); for renames/null-changes/backfills add a `migrate_*` method + `version_compare` gate in `PCM_Activator::maybe_upgrade()` and bump `PCM_DB_VERSION`. Add a matching `drop_tables()` entry.
- **New AI provider**: `includes/core/providers/class-pcm-provider-{name}.php` implementing `PCM_Provider_Interface`, register in `PCM_Provider_Registry::PROVIDERS`. Models sync via Integrations.
- **New admin settings**: extend the `settings` module / `PCM_Settings` blob (one option key).
- **New automation TRIGGER**: in the source module's `automations.php` → `PCM_Automation_Triggers::register([... 'implemented'=>true,'contextKeys','conditionFields'])`; at the state-change point call `PCM_Automation_Engine::fire_trigger('{id}.verb_noun',$context,$userId)` (guard with `class_exists`). Ref `approvals/service.php::fire_status_trigger`.
- **New automation ACTION**: handler class in `automations/handlers/` implementing `PCM_Automation_Action_Handler`; register + catalog metadata in a module's `automations.php` via `register_action_handler()` + `PCM_Automation_Actions::register()`. Long-running? return `mode()==='async'`. Ref `class-pcm-webhook-action-handler.php`.
- **AJAX**: none exist — prefer REST to match conventions.

## Conventions
- PHP 8.1 typed, `snake_case` functions/vars, PHPDoc on public methods, `class-pcm-*.php` filenames, `PCM_` class prefix.
- All user-facing strings use text-domain **`power-creatives`**.
- SQL **only** via `$wpdb->prepare()`; table names from `PCM_Schema::table()` (never user input).
- REST responses via `$this->success()/error()/not_found()` — never raw `WP_REST_Response`.
- Frontend: dumb components + custom hooks, config-driven, ≤500 lines/file, TanStack Query + tRPC-style client.
- **Provider routing is data-driven**: frontend sends `model.provider`; backend requires it and fails fast. `detect_provider()` is deprecated.

## Security checklist (apply to every handler)
1. **Nonce**: `PCM_REST_Base` requires `X-WP-Nonce` verified against `wp_verify_nonce($n,'wp_rest')`.
2. **Capability**: default `manage_options`; or a valid shortcode-gate cookie limited to `manage_options|read|edit_posts`. Public routes MUST be explicit.
3. **Sanitize in**: `sanitize_text_field`/`sanitize_textarea_field`/`esc_url_raw`/`absint` (+ `string_arg`/`int_arg`/`bool_arg` route-arg helpers).
4. **Prepare SQL**: every `$wpdb` call parameterized; no string interpolation of user data.
5. **Escape out**: `esc_html`/`esc_attr`/`wp_kses` when rendering to admin/frontend HTML.

## Known risks / gotchas
- **MySQL BIGINT → JSON string coercion**: `wpdb` returns all columns as PHP strings; IDs arrive `{"id":"6"}`. TS expects `number` → `===` breaks. Normalize with `Number(raw.id)` in every consumer hook AND in React-Query `setQueriesData` predicates.
- **`flushSync()` required** around optimistic `setQueriesData` for `@hello-pangea/dnd` drag-end, or cards snap back.
- **Tailwind v4 gates `hover:`/`group-hover:` behind `@media (hover:hover)`** — these are NO-OPS on coarse-pointer devices. Drive hover-reveals with JS hover state instead.
- **`wp_pcm_assets` is OFF-LIMITS** to the Approvals domain (per-item metadata → snapshot JSON).
- **`maybe_upgrade()` runs on every page load** — keep migrations idempotent and cheap. The 1.45.0 catch-up and the 1.43.0 GBP gate call the SAME idempotent method.
- **No PHP linter / no textdomain load call / no custom caps** — by design.
- **Connector version ≠ `PCM_VERSION`** — connector is 3.0.8; plugin is 1.7.0. Connector changes require re-download + reinstall on each connected site.
- **`detect_provider()` is deprecated** — routing is data-driven via `model.provider`.
- Default branch is **`image-features`**, not `main`.

## Open questions
1. The git/commit ritual in `AGENTS.md` (BEFORE/AFTER/VERIFIED empty commits, dated CHANGELOG per change) — should it be followed for every change, or only when explicitly requested? (The workspace rule says don't commit unless asked.)
2. Where are provider API keys configured for local testing — which provider keys, if any, are seeded in the local DB's `wp_pcm_integrations`?
