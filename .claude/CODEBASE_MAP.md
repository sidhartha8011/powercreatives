# Power Creatives — Codebase Map
_Last updated: 2026-07-07 (map re-verified vs code) · NEWEST (2026-07-07, uncommitted): **GSC "no data yet" messaging + white dialog input.** `gsc_provision` now ends with a best-effort 28-day probe (`gsc_has_data()`) and reports `hasData` (true/false/null-on-probe-error) on BOTH the reuse + fresh-verify paths; Sites `reportGsc` appends "Site is added — no data yet (new properties can take a few days to show stats)." when `hasData===false`, and the SEO table's zero-row GSC pull toast says "connected, but has no data yet" instead of sounding like an error (real Google errors still pass through verbatim). The Verify-in-GSC dialog's "Indexed domain" input is `bg-white` (LabeledInput gained a className passthrough). Verified: gsc_provision2_test.php 16/16, tsc 56 baseline. PRIOR — (2026-07-06, uncommitted): **GSC add-site "Verify" now REUSES existing properties + confirms the indexed domain (fixes duplicate/no-data at the source).** `PCM_Sites_Service::gsc_provision($site,$uid,$targetUrl='')` now, before adding anything, calls `PCM_GSC::list_properties`+`match_properties($url)`; on a match it REUSES it (`report.alreadyExists/property/verified`, no duplicate PUT). New `detect_canonical($url)` follows the homepage redirect to auto-detect the indexed www/non-www variant (replaces the manual "Google it & hover"); new `gsc_preview($site,$uid)` returns `{existing[],suggested,canonical,accountEmail}`. New route `POST /sites/{id}/gsc-preview` (`gsc_preview`); `POST /sites/{id}/gsc-verify` now takes optional `{targetUrl}`. Frontend Sites: the "GSC" button opens a **step-0 dialog** (guidance + auto-detected editable "Indexed domain" input + "already in GSC → will reuse" note) → Verify posts `{id,targetUrl}`; tRPC `sites.gscPreview` + `sites.gscVerify` now sends `body.targetUrl`. Verified: gsc_provision2_test.php 13/13 (reuse www/non-www/sc-domain, no-match→add, canonical redirect follow, preview suggest) + 56 GSC regressions + tsc 56 baseline. This supersedes the earlier match_properties candidate-loop stopgap (still in place for stats). PRIOR — NEWEST (2026-07-06, uncommitted): **GSC "Connect with Google" (OAuth) — no per-property service accounts.** User rejected per-property SA setup; added the OAuth authorization-code flow: one agency Google login grants every GSC property that account can see. New routes `POST /integrations/gsc/oauth-start` + PUBLIC `GET /integrations/gsc/oauth-callback` (new `'public'` permission tier in `base-controller.php`, auth = single-use state transient), `PCM_GSC` gained oauth credential type + refresh-token grant + authorize_url/exchange_code, Integrations card gained Client ID/Secret inputs + redirect-URI copy box + Connect button + return-flag toasts. SA JSON paste still works (headless fallback). Verified: 11/11 new OAuth unit tests + 20/20 SA regression + tsc 56 baseline + build. PRIOR — same day: **Google Search Console integration + SEO-table search stats.** New `gsc` provider (service-account JSON as the key; see *External services → gsc*), zero-dep client `includes/core/class-pcm-gsc.php` (RS256 JWT → Search Analytics), routes `GET /integrations/gsc/properties` + `POST /integrations/gsc/stats`, tRPC `integrations.gscProperties/gscStats`. SEO table: the `traffic` placeholder is now live GSC clicks + NEW columns `impressions`/`ctr`/`position`/`gscKeywords` (Top Queries) + a "GSC stats" toolbar button (28-day pull, rows matched by permalink, works local + connected tabs); Integrations card shows GSC 3-step setup help. Loader: `power-creatives.php` require_once's the new class (regen vendor classmap at next zip). PRIOR — NEWEST (2026-07-06, uncommitted): **Connector 2.2.3 — Brizy heading edits now reflect on the live page.** A Brizy heading edit updated the DB (SEO table showed the new text) but the live page stayed old: Brizy serves a cached "compiled HTML" copy it regenerates from its editor data, and `PCM_Conn_B_Brizy::regenerate` only tried Brizy's PHP API (`set_needs_compile`), which silently no-ops on some Brizy versions. Added a recursive raw-meta fallback (`brizy_set_needs_compile`) that flips Brizy's own `needs_compile` flag directly in the `brizy-post`/`brizy` meta → the frontend rebuilds on next view. Sets ONLY the flag (editor_data + compiled cache untouched → no blank-page risk). Connector 2.2.2 → 2.2.3; reinstall on the site. Verified by a unit test (flag flips, editor_data/compiled_html untouched, nested key handled). NB: 2.2.2 was ELEMENTOR-specific (element-scoped path) and did NOT change Brizy — that's why the earlier reinstall didn't fix the Brizy case. If still old after 2.2.3, suspect a server/CDN cache (Cloudflare w/o CF plugin). PRIOR — NEWEST (2026-07-06, uncommitted): **Connector 2.2.2 — element-scoped heading/link edits now apply on Elementor & co.** `replace_link_in_element` (the path used when a heading/link carries an `el_id`, e.g. Elementor inline headings in Text-Editor widgets) skipped its target because a raw-string `strpos($raw,$old)` pre-filter never matched the JSON-escaped stored form (`\"` quotes, `\uXXXX` for å/ä/ö) — so edits to attributed / non-ASCII headings silently didn't reflect on the site. Removed the pre-filter (el_id already narrows the meta; the match runs on the DECODED structure in `replace_in_element`). Connector 2.2.1 → 2.2.2 (`seohub/service.php`); connected sites must reinstall to get it. Verified by a stateful unit test (attributed+Swedish Elementor inline heading now replaces; heading-widget + Brizy base64 paths regression-pass). PRIOR — NEWEST (2026-07-06, committed on `feat/seo-suite-port`): **SEO Headings re-scan on accordion open + connector 2.2.1 (Brizy headings)**. (1) `SEO/HeadingsPanel.tsx` — both heading queries now use `{ staleTime: 0, refetchOnMount: 'always' }`, so a page's headings RE-SCAN every time its accordion is opened (previously served the global 30s cache → stale/read-only on reopen; a `/scan-headings` timeout falls back to raw-content parsing, empty for Elementor pages → read-only). (2) Connector **2.2.0 → 2.2.1** (commit `6f7b572`): base64-aware `collect_headings` + de-dupe of elId-less builder headings + JSON-escaped needle variant in `replace_links` (Brizy headings + attribute-bearing headings now editable — see *SEO Hub → Connector detail*). NB: the whole SEO-suite / Brizy / Approvals batch (the 07-05 block below, previously "uncommitted") is now COMMITTED/merged on branch `feat/seo-suite-port`; the hub zip build + vendor classmap regen are gitignored artifacts (not committed). PRIOR — NEWEST (2026-07-05): **Approvals Custom Card editor overhaul + client-review Approve-in-dialog + Brizy connector**. (1) `Approvals/components/CustomCardEditor.tsx` — top bar reduced to IMAGE + PAINT only (one Insert-image button inserting at `selection.to` so nothing is overwritten; Annotate; Draw). All text formatting moved to the contextual bubble menu, now `selectionOnly` (new prop on `Writer/components/WriterBubbleMenu.tsx`, default off → Writer canvas unchanged) so it shows ONLY on a text selection. Paint on a specific image: double-click an image (or select it + Annotate) → `ImageAnnotator` flattens strokes INTO the image (moves/scales with it, never stretches) → replaces the node in place. Draw button now TOGGLES (click again de-activates). `CardDrawLayer.tsx` paint toolbar is `sticky` (follows scroll). (2) Typography FIX: the editor used Tailwind `prose prose-sm` but `@tailwindcss/typography` is NOT loaded in this v4 build (no `.prose` rules ship) → content rendered at unstyled browser defaults ("bigger/bloaty"); added a scoped `#pcm-root .pcm-card-editor` compact type scale in `app/src/index.css` + swapped the class. (3) `Approvals/components/CreativeAssetCard.tsx` — the opened custom/article peek (`ArticleViewerDialog`) now has an Approve button below the content (`.pcm-notion-approve` in `client-review.css`; colours inlined since the dialog portals to `<body>`, outside the `.pcm-client-review` token scope); wired to the same `handleToggleApprove`. (4) **Connector 2.1.7 → 2.2.0** — Brizy base64-aware link scan + replace (see connector detail below). PRIOR — NEWEST (2026-07-03, uncommitted): **SEO table heading editor** — expand a page row → its H1–H6 outline as subrows (`SEO/HeadingsPanel.tsx`): inline-edit heading text, change tag level H1–H6, AI-Optimize (new `heading` prompt section in `seo/prompts.php`, seeded as a Template). Local + remote. Backend `seo/{controller,service}.php` `…/content/{id}/headings` + `…/sites/{id}/content/{post}/headings` routes (parse/rewrite post_content occurrence-aware; content-builder meta via `replace_url_in_meta`; remote via connector `/scan-headings` + `/replace-heading` for builder-WIDGET text+level, `/replace-url` for content HTML). **Connector bumped 2.1.6 → 2.1.7** (`seohub/service.php`) — connected sites must reinstall it to edit headings. PRIOR — NEWEST (2026-06-29/30, committed `2ba6020`): **Client-invite email path fixed end-to-end** — server-side send on `create_set`, Brevo verified-sender picker on the Integrations card, Email diagnostics panel (`/automations/logs`, `/automations/test`), `settings.get`/`update` routes; **Brand → Delivery → Project** dynamic inheritance via `PCM_Hierarchy` (DB 1.31.0). See *Brand → Delivery → Project* + *Client-invite email & diagnostics*. PRIOR — **SEO link inspector** — bulk "Scan links" button + clickable Internal/External/Dead count cells → `SEO/LinksPopup.tsx` (edit/remove links, rewrites post content), local + remote; see *SEO suite → New local features / Remote-site SEO*. PRIOR SINCE THE LAST MAP: (1) **Global table kit** — `@/components/ui/data-table.tsx` (config-driven DataTable, used by Sites) + the SEO spreadsheet building blocks moved to shared: `@/components/ui/column-head.tsx`, `@/hooks/useColumnLayout.ts`, `@/hooks/useColumnFilters.ts` (generic). (2) **Custom approval cards** (4th asset type). (3) SEO remote `duplicate` route. (4) Shortcode page auto-created. NOTE: SEO + Approvals are under active churn — verify specifics against the code._

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
| DB version (`PCM_DB_VERSION`) | **1.32.0** (separate from plugin version. 1.28.0 `deliveries.seoSiteId`; **1.29.0** `projects.deliveryId` (Brand→Delivery→Project, additive + backfill `class-pcm-schema.php:1033`); **1.30.0** seeded a "email client review link on share" automation; **1.31.0** removes that rule — it double-sent vs the built-in dispatch path — idempotent, `class-pcm-activator.php:282`; **1.32.0** adds `externalId varchar(191)` to brands/deliveries/projects — backs the webhook `brandExtID`/`deliveryExtID`/`projectExtID` tokens, additive via dbDelta; **1.33.0** — further additive dbDelta since the last map edit, no explicit activator gate ≤1.31 so verify exact columns against `PCM_Schema` if needed) |
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
> **Windows dev box `krith` (confirmed live 2026-06-29).** Clean machine — toolchain
> installed from scratch via winget: **Node 24.18.0 / npm 11.16.0** + **Local by
> Flywheel 10.1.1** (`C:\Users\krith\AppData\Local\Programs\Local\Local.exe`). No
> PHP/Composer/wp-cli **on PATH**, so `composer test` can't run here — runtime needs
> NO `vendor/`, the plugin uses its own require chain, so the site runs fine without
> it. **BUT Local bundles a usable PHP (8.2.29):**
> `C:\Users\krith\AppData\Local\Programs\Local\resources\extraResources\lightning-services\php-8.2.29+0\bin\win64\php.exe`
> — use it for `php -l` and standalone PHP scripts (verified 2026-06-30). A bare
> `php -r` loads NO ini (so `mb_*`/PDO are absent); add
> `-d extension_dir="…\bin\win64\ext" -d extension=php_mbstring.dll` when you need
> mbstring (the running site's php-fpm loads it by default — `mb_chr` IS available
> in the real runtime). Class files guard with `if(!defined('ABSPATH'))exit;`, so a
> standalone harness must `define('ABSPATH', …)` before `require`-ing them; you can
> then reflect-invoke methods without a full `wp-load` (no DB needed unless the code
> path hits `$wpdb`). DB-backed bootstrap still needs the site STARTED (MySQL up).
> **Working copy is OneDrive-synced:** `C:\Users\krith\OneDrive\Desktop\Powercreatives\powercreatives`
> (the inner dir holding `power-creatives.php`).
> ⚠️ **TWO Local sites exist — don't confuse them (clarified 2026-07-02):**
> (1) **`power-creatives`** (hyphen) → `http://power-creatives.local`, and
> (2) **`powercreatives`** (NO hyphen) → `http://powercreatives.local`. **The user actively
> uses `powercreatives.local` (no hyphen)** — that's the one to verify against. BOTH now link
> their `plugins/powercreatives` dir as a **directory junction → the SAME OneDrive working
> copy** (junction name `powercreatives` to match the activated entry
> `powercreatives/power-creatives.php`; junction MUST target the inner dir holding
> `power-creatives.php`). Since both junction to one working copy, `git pull` + `npm run build`
> in the OneDrive copy reflects on BOTH sites (DBs are per-site).
> ⚠️ **Two-clones trap — hit TWICE:** each site originally ran its OWN standalone git clone in
> its plugins dir, so builds in the OneDrive copy never reached it. Fixed for `power-creatives`
> 2026-06-29 (backup `…/wp-content/powercreatives-standalone-backup`) and for **`powercreatives`
> 2026-07-02** (its clone was branch `feat/seo-suite-port`, clean, a commit behind at `a56edc6`;
> moved aside to `…/plugins/powercreatives-standalone-backup` and replaced with the junction).
> If a change "doesn't show" on a site, FIRST check `plugins/powercreatives` is a junction
> (`Get-Item … | Select LinkType,Target`), not a real dir. Note also: uncommitted changes in the
> OneDrive copy can only reach a site via the junction — a standalone clone's `git pull` won't see
> them. **Frontend
> bundle has FIXED filenames (`index-writer.js`/`index.css`, no content hash) — after a
> rebuild you MUST hard-refresh (Ctrl+F5) the browser** or WP serves the cached bundle.
> ⚠️ **"I pulled but nothing changed" (seen 2026-07-07):** `.claude/SESSION_LOG.md` is a
> *committed* file that nearly every session also edits locally — so an uncommitted local
> SESSION_LOG edit makes `git pull` **abort** ("Your local changes … would be overwritten")
> since incoming commits touch the same file, and the pull silently never happens (`git status`
> shows `[behind N]`). Fix: `git stash push .claude/SESSION_LOG.md` → `git merge --ff-only
> origin/<branch>` → `git stash pop` (resolve the append-conflict by keeping both blocks).
> THEN rebuild (`cd app && npm run build`) — pulled TS does NOT rebuild `app/dist/` — and hard-refresh.
> Build env on PATH only after refreshing it from the registry in a new shell
> (`$env:Path = [Environment]::GetEnvironmentVariable('Path','Machine')+';'+[Environment]::GetEnvironmentVariable('Path','User')`).
> **Auth-free smoke test (confirmed):** `GET http://power-creatives.local/wp-json/` →
> `namespaces` contains `pcm/v1`; `GET /wp-json/pcm/v1` lists **~204 routes** (grew from
> the older ~165/167 as the SEO suite landed); `GET /wp-json/pcm/v1/brands` → **403**
> (loaded + nonce/cap-guarded, not 404).
>
> **Mac dev box (confirmed live 2026-06-18).** WP **7.0** / PHP
> **8.5.7** served by the **PHP built-in server**: `php -S localhost:8080 router.php`
> (process cwd = docroot). **Docroot:** `~/Desktop/wordpress-local`. **Plugins dir:**
> `~/Desktop/wordpress-local/wp-content/plugins/`. The plugin is installed as a
> **symlink** `…/plugins/power-creatives` → this working copy's INNER dir
> (`…/Landing page -demo/powerplatform/powerplatform`, the one that holds
> `power-creatives.php`). ⚠️ The symlink MUST target the inner dir, not the
> `powerplatform/` wrapper — WP scans only one level deep, so a wrapper-aimed link
> makes the plugin invisible (no `pcm/v1`). `router.php` serves `app/dist/*` static
> assets directly (follows the symlink), so the SPA bundle loads fine. ⚠️ **Mac build
> gotcha:** the copied `app/node_modules/.bin` shims are broken on this volume
> (`npm run build` → "bad interpreter: Operation not permitted"; `chmod +x` does
> NOT fix). Build by calling vite through node directly:
> `cd app && node node_modules/vite/bin/vite.js build --config vite.config.wp.ts`. No `wp-cli`
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
- **Plugin link (verified 2026-06-18):** the active plugin dir is
  `…/wp-content/plugins/`**`powercreatives`** (note the name — no hyphen; the main
  file inside is still `power-creatives.php`, so the activated entry is
  `powercreatives/power-creatives.php`). It is a **directory junction** →
  this working copy **`C:\Users\sanky\Desktop\powercreatives\powercreatives`**
  (the dir that directly holds `power-creatives.php` — junction MUST target the
  inner dir; WP scans one level deep). So `npm run build` in the working copy +
  a browser hard-refresh reflects frontend changes live; PHP changes are picked
  up immediately. Created via PowerShell `New-Item -ItemType Junction` (not
  `mklink`, no admin needed).
  - **History:** the site originally ran a **standalone copy extracted from the
    deploy zip** (a real dir, not a junction) — edits/builds in the Desktop
    working copy did NOT reach it. That copy was moved aside to
    `…/plugins/powercreatives-zip-backup` and replaced with the junction above.
    (An even older junction once pointed at `C:\Users\sanky\Downloads\powerplatform-new\powerplatform`.)
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
| automations | pcm/v1/automations | **Cross-module IF→THEN engine** (triggers → conditions → actions). CRUD + `/catalog` + `/test` + **`GET /automations/logs`** (recent send log, `manage_options`, `controller.php` `list_logs`). See the Automations section below + *Client-invite email & diagnostics*. |
| assets | pcm/v1/assets | Generated asset CRUD, refine, export. **`PATCH /assets/projects/{id}/delivery`** → `set_project_delivery` (reassign a project's delivery; ownership-scoped) — feeds the Brand→Delivery→Project chain. |
| brands | pcm/v1/brands | Business profiles, logos, colors |
| copy | pcm/v1/copy | AI ad copy (SSE streaming, framework-driven) |
| deliveries | pcm/v1/deliveries | Project delivery tracking (v1.6.0+) |
| image | pcm/v1/image | AI image gen/edit/upscale |
| integrations | pcm/v1/integrations | Provider API key management. **`GET /integrations/brevo/senders`** → `brevo_senders` (lists the Brevo account's verified senders via `GET /v3/senders`; powers the "From" picker on the Brevo card). See *Client-invite email & diagnostics*. |
| keywords | pcm/v1/keywords | SEO keyword lists (user meta `pcm_keyword_lists`) |
| models | pcm/v1/models | AI model CRUD + sync from providers |
| notifications | pcm/v1/notifications | In-app notifications (`GET /notifications` → items+unseen; `POST /notifications/seen`). `edit_posts`. See *Notifications (v1.19.0)*. |
| prompts | pcm/v1/prompts | User system-prompt overrides |
| scraper | pcm/v1/scraper | URL scrape + AI vision |
| seo | pcm/v1/seo | Content SEO suite (cross-plugin meta, AI field/body generate, schema, AI-readiness, site, GBP, export, **scan-links**, **llm-info**). `edit_posts` + per-post checks. **Plus a full REMOTE-site suite** under `/seo/sites/{id}/*` (`manage_options`) proxied through the connector — see *SEO suite → Remote-site SEO*. See *SEO suite*. |
| seohub | pcm/v1/seohub | Multi-site connectors. Two connect paths: HMAC-handshake tenant ZIP **and a tenant-free pairing-code "one-paste" connector** (`/seohub/connector-download`). Tenant CRUD `manage_options:strict`; PUBLIC: `/seohub/connector/hello`, **`GET /seohub/connector-manifest` + `GET /seohub/connector-package`** (connector self-update feed, sha256-pinned). See *SEO Hub*. |
| settings | pcm/v1 | Global settings + prompt editor. `GET`/`POST /settings` (`PCM_Settings::set_many`, no key whitelist) now also drives the **email sender** (`automations_from_email` / `_name`) — wired to the frontend via `settings.get` / `settings.update` (previously app settings only persisted to localStorage). See *Client-invite email & diagnostics*. |
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
  **`seo_views`** — per-user saved SEO table Views {columns,filters} JSON + an
  **`isDefault`** tinyint flag (DB **1.27.0**): at most one default per user
  (`PCM_SEO_Service::set_default_view` clears others on set); the SEO module
  auto-applies the default view once on load. Toggle via star icon in the View
  dropdown → `PATCH /seo/views/{id}/default`).
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
- Providers: **openai, google (Veo/Gemini), fal, kieai, anthropic, ahrefs, brevo, proranktracker, gsc**
  (`class-pcm-providers.php`). **gsc** = Google Search Console via SERVICE-ACCOUNT JSON pasted as the
  "API key" (validated by minting a real access token). Client = `includes/core/class-pcm-gsc.php`
  (`PCM_GSC`: RS256 JWT via openssl → token cached 55min in a transient → Search Analytics API; zero deps).
  Routes: `GET /integrations/gsc/properties`, `POST /integrations/gsc/stats {site?, days?}` → per-page
  clicks/impressions/CTR/position/top-5-queries keyed by normalized URL (host+path, no www/trailing slash),
  property auto-matched from the site URL (domain property preferred). SEO table: "GSC stats" button fills
  the Clicks (key still `traffic`)/Impressions/CTR/Position/Top Queries (`gscKeywords`) columns by matching
  rows' `permalink`; works for the local tab ('' → home_url) and connected-site tabs (their URL). These 5
  columns are SORTABLE (accessors close over `gscPages`, no-data→null sorts last) and FILTERABLE (defs built
  inline in `SEO/index.tsx` — numeric cols "No data/Zero/Has value", position "Top 3/4–10/Beyond 10", queries
  free-text — merged into `filterDefs`, since the data isn't on the row so `seoFilters.buildFilterDefs` can't
  see it). **URL matching:** `PCM_GSC::norm_url` + the frontend `normGscUrl` MUST stay byte-identical — they
  now percent-DECODE the path + Unicode-lowercase (host+path, no www/trailing slash) so non-ASCII slugs match
  (GSC returns `/tandv%C3%A5rd`, a permalink may be raw `/tandvård` w/ different hex case → all collapse to
  one key). This was the "GSC stats sometimes works" bug (ASCII pages matched, Swedish å/ä/ö pages didn't).
  The pull toast reports MATCHED-of-returned (`filled 12 of 17`), warns when returned>0 but matched=0, infos
  on 0 returned — so a URL-mismatch vs no-data is visible, not silent. The SA email must be added as a user on
  each GSC property (the card shows the 3-step setup). NB: a plain Google **API key** (`AIza…`) can NOT drive
  the Search Console API (it needs a principal — OAuth/service account); `parse_credentials` detects a pasted
  API key and returns a precise "you need a service account" error rather than "bad JSON".
  **Preferred auth = "Connect with Google" (OAuth)** — one agency login grants every property that Google
  account can already see (no per-property SA additions). Flow: card collects OAuth Client ID + Secret (user
  creates ONE Web-application client; redirect URI shown on the card = `rest_url('pcm/v1/integrations/gsc/
  oauth-callback')`) → `POST /integrations/gsc/oauth-start` (stores creds+user in a single-use `state`
  transient, 10min; returns consent URL w/ access_type=offline&prompt=consent) → Google → `GET
  …/oauth-callback` (**'public' permission tier added to base-controller.php** — authenticated by the state
  transient, not cookies) → `PCM_GSC::exchange_code` → upserts the user's gsc integration with apiKey JSON
  `{type:'oauth', client_id, client_secret, refresh_token}` → bounces back with `?pcm_gsc=connected|error`
  (hash-safe). `PCM_GSC::access_token` branches: oauth → refresh-token grant; service_account → JWT. All
  downstream (list_properties/page_stats/validate) credential-agnostic.
  **Data-quality (aligned to the client's GSC-pipeline doc):** `page_stats` uses a 3-DAY lag +
  `dataState:'final'` (fresher data is partial and shifts), PAGINATES via `sa_rows()` (rowLimit 25000 +
  startRow, ≤4 pages/call, later-page errors degrade to partial); `api()` retries once (0.7s) on
  429/quota/5xx; `access_token` returns a SPECIFIC `pcm_gsc_invalid_grant` error naming the fix (consent
  screen left in "Testing" kills refresh tokens every 7 days → "Publish app" + reconnect) — the card's step 1
  warns about publishing too. ⚠ THE CONSENT SCREEN MUST BE PUBLISHED (In production; no verification needed).
  NOT built (different scope, offer as follow-up): the doc's warehoused ETL (daily cron, 16-month backfill,
  fact table keyed site/date/query/page/country/device, property-diff + zero-row alerting).
  **Add-site auto-verify (n8n-flow port):** adding a connected site auto-provisions it in GSC —
  `PCM_Sites_Service::gsc_provision` (sites/service.php): add property (`PCM_GSC::add_property`, PUT
  sites/{url}) → META token (`verification_token`, Site Verification API, content extracted from the returned
  `<meta>` tag) → push to connector (`POST /pcm-conn/v1/site {gscToken}` → option `pcm_conn_gsc_token`,
  rendered in wp_head; **connector 2.3.0**; older connectors detected via the echo-back and reported) →
  `verify_property` (POST webResource?verificationMethod=META). Best-effort on `create_site` (report in the
  create response under `gsc`) + retry route `POST /sites/{id}/gsc-verify` (tRPC `sites.gscVerify`, "GSC"
  button per site row, toasts via `reportGsc`). ⚠ SCOPE widened to `webmasters + siteverification` —
  connections made under readonly must RECONNECT (403 → `pcm_gsc_scope` error says so); the GCP project also
  needs the "Site Verification API" enabled (setup guide step 2 updated). Verify can fail behind a stale page
  cache — the error says to clear + retry.
  **UI:** the GSC card has NO api-key field (OAuth-only; gated `selectedProvider!=='gsc'`); it shows a
  toggle button → Notion-styled collapsible **`Integrations/GscSetupGuide.tsx`** reproducing the client PDF's
  "Search Console API Setup (from GCP)" steps 1–6 verbatim (+ one blue callout: choose *Web application* not
  *Desktop app* for the in-plugin flow). Valid-key panel shows a single "SEO data" chip for `supportsSeo`
  providers instead of 4 crossed-out AI modality chips.
  **brevo** = transactional email (HTTP API, not SMTP) used by
  the Automations email channel/action; validated via `GET /v3/account` (`api-key` header).
  **proranktracker** (PRT) = SERP rank tracking (commits `5a38adb`/`d329728`/`7b43ac5`): integration
  provider + token validation, a live-test panel on the Integrations card (`Integrations/PrtLiveTestSection.tsx`),
  and an SEO-table **PRT ranking-URL column** with 1/3/6-month history + trend sparklines. NB: this
  Integrations/PRT feature is only lightly mapped — verify routes against `integrations/controller.php`.
  **SEO table "Pos (PRT)" column** (alongside "Pos (GSC)"): new `POST /integrations/proranktracker/page-ranks
  {site?}` (`prt_page_ranks`) auto-matches the PRT project to the site by host, fetches its terms, returns
  `{project, ranks:{<lowercased keyword> → {rank, matchedUrl, engine}}}` (best/lowest rank if a term repeats
  per engine). tRPC `integrations.prtPageRanks`. SEO/index.tsx: "PRT ranks" toolbar button fills `prtPosition`
  by matching each row's **Primary Keyword** (normKw = trim+toLowerCase, parity with backend mb_strtolower);
  sortable (rank asc = best; not-tracked/0 → null last) + filterable (Not tracked/Not ranking/Top 3/4–10/
  Beyond 10). PRT is treated as MORE accurate than GSC’s avg position, so both columns coexist. NB:
  `prt_page_ranks` host matching is SCHEME-TOLERANT (PRT tracked URLs may lack https:// — parse_url then
  yields no host; falls back to text before the first / or ?); its 404 lists the hosts PRT DOES track.
  `PCM_GSC::api()` sends an EMPTY body on bodyless PUT/POST — Google 411s otherwise (the "Search Console:
  HTTP 411" bug on add_property).
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

## Brand → Delivery → Project (dynamic inheritance, DB 1.29.0)
- Canonical chain: **Brand → Delivery → Project → things (e.g. approval sets)**.
  Stored as links: `projects.deliveryId` → `deliveries.brandId`. **`PCM_Hierarchy`**
  (`includes/core/class-pcm-hierarchy.php`) is the single source of truth resolver:
  `for_project(?int): {projectId,deliveryId,brandId}` + `delivery_for_project` /
  `brand_for_project`. Loaded in `power-creatives.php` after `PCM_Access`.
- **Nothing attached to a project stores brand/delivery** — they're derived LIVE via
  `PCM_Hierarchy`, so reassigning a project's delivery (or that delivery's brand) makes
  everything follow. Approvals: `enrich_context()` + `format_set_row()` resolve brand/
  delivery from the set's `projectId` through `PCM_Hierarchy` (the stored `brandId`/
  `deliveryId` are only fallbacks for legacy rows). `assets/controller.php` exposes
  `deliveryId`+`brandId` per project and `set_project_delivery` (PATCH route above).
- Migration: `migrate_backfill_project_delivery()` (`class-pcm-schema.php`) backfills
  `projects.deliveryId` from the delivery that referenced each project; gate at 1.29.0.

## Client-invite email & diagnostics (DB 1.30.0–1.31.0)
- **The client invite email is sent by the built-in dispatch path, NOT an automation rule:**
  `share_set()` → `PCM_Automation_Engine::dispatch(APPROVAL_SET_SHARED)` →
  `automations/service.php::render_email_for_event()` → `PCM_Automation_Templates::client_invite()`
  → Brevo, to `context['clientEmail']`. Do NOT seed an `email.send` rule on
  `approvals.set_shared` — it double-sends (the v1.30.0 seed did; **v1.31.0 removes it**,
  `PCM_Automation_Seeds::remove_seeded_rule` + structural delete in `class-pcm-activator.php`).
- **Send is now server-side on create**: `approvals/controller.php::create_set` reads optional
  `clientEmail`/`clientMessage` and calls `share_set()` — so "create + notify" is one request
  (the old flow needed a separate, easily-missed manual "Send" click).
- **Sender (`automations_from_email`) is required** or every email fails *"No valid from-email
  configured."* It's set via the **Brevo integration card** → `BrevoSenderPicker.tsx` (a verified-
  sender dropdown from `GET /integrations/brevo/senders`), saved through `settings.update`.
  Brevo key is per-user (`PCM_Brevo_Email_Channel::get_brevo_key`); integrations are **per-site**.
- **Diagnostics**: `Automations/EmailDiagnostics.tsx` (collapsible panel) = test-send (`/automations/test`)
  + recent send log (`/automations/logs`). Use it to see whether a share fired and Brevo's HTTP result.

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

## Custom approval cards (4th asset type, 2026-06-25/26)
A "Custom" Notion-style document type alongside media / copy / articles. **No new
APIs** — reuses the whole approval lifecycle.
- **Snapshot:** `snapshot.custom: CustomAsset[]` (`{id, type:'custom', title?, content
  (Tiptap HTML), images?, overlay?/annotation?, createdAt, updatedAt}`); review feedback
  gains `approvedCustomIds`. Backend (`approvals/service.php`) extends the feedback
  whitelist + `feedback_struct` + `bucket_for_asset` + `is_fully_approved` + `merge_snapshot`
  + `update_snapshot_asset` for the `custom` bucket. `snapshot`/`reviewFeedback` are
  opaque JSON (longtext) → **no DB migration**.
- **Create:** board "+ Add Approval Set" → `CreateCustomSetDialog` (author with
  `CustomCardEditor` — shared `getEditorExtensions` Tiptap + `wp.media` image insert +
  `ImageAnnotator` zero-dep canvas overlay flattened to a PNG data-URL) → hands the card to
  the SHARED `SendToApprovalSetDialog` (now accepts a `custom` bucket) — exact Copy-module
  flow (name → createSet → 'client' lane → share link + email). Clicking a set on the board
  opens the normal client-preview iframe (custom renders via `ClientReviewPage` /
  `CreativeAssetCard` 'custom' branch).
- ⚠ Share link needs the `[power_creatives]` shortcode page; `PCM_Admin::ensure_public_page()`
  now AUTO-CREATES a published "Power Creatives" page if missing (on the plugin admin page)
  so links/preview resolve instead of hitting `home_url` → theme "nothing found".

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
  - **Spreadsheet column resize + reorder** (now GLOBAL: `@/hooks/useColumnLayout.ts`
    + `@/hooks/useColumnFilters.ts` (generic `<T>`) + `@/components/ui/column-head.tsx`
    — moved out of SEO so any module can build this style of table; SEO passes its
    storageKey `'pcm:seo:col-layout:v1'`. `seoFilters.ts` keeps the SEO-specific
    `buildFilterDefs` + `FilterDef = FilterDef<SeoRow>`): the
    table is rendered data-driven from an ordered column list + a `<colgroup>`
    of px widths. Drag a header's right edge to resize (pointer events); drag a
    header to reorder (native HTML5 DnD). Order+widths persist to **localStorage**
    (`pcm:seo:col-layout:v1`) — per-browser, NOT in the View/server. Reset via
    Columns menu → "Reset column sizes & order". The leading selection column is
    fixed. NB: reorder uses native DnD (not `@hello-pangea/dnd`) so no
    `flushSync` dance; the resize handle cancels its own dragstart so it doesn't
    trigger a column move.
- **Phase 3 (AI generate)**: `prompts.php` (verbatim field prompts, filter
  `pcm_seo_field_prompts`) + `generate_field` (substitute_vars + build_field_vars
  + sanitize_ai_output → `PCM_LLM::invoke`); `POST /seo/content/{id}/generate`
  accepts optional `model` + `provider` (data-driven routing — no detect_provider).
  **Generation UI (current — THREE entry points; churned a lot, verify live):**
  (1) **per-cell ✦** — each editable cell has a Sparkles button (`handleGenerate`;
  restored in commit `b49d5f0` after a brief bulk-only period — the EditableCell
  docstring still says "no longer per-cell", that comment is stale);
  (2) **header per-column ✦** — `ColumnHead.generate` prop → `handleColumnGenerate`
  (template picker → generate the whole column);
  (3) **bulk "Generate all" split button** in a FLOATING bar (`fixed bottom-center`,
  shown when rows are selected; left = all generatable columns for selected rows,
  ▾ dropdown = column checklist `genCols` + overwrite/empty mode → `runBulk`).
  All stage suggestions for accept/reject/re-generate. A **model picker** in the
  content header (next to Post/Page) picks any text model
  (`trpc.models.getForGeneration {type:'text'}`); choice (id+provider) sent with
  every generate call, persisted in localStorage (`pcm:seo:gen-model`).
  Next to the split button, a **"Bulk actions" menu** (Change status / Duplicate /
  Delete). Remote sites also have **bulk status + delete** (`b49d5f0`;
  `POST /seo/sites/{id}/content/{post}/delete` → `remote_delete`).
  Next to the split button is a **"Bulk actions" menu** (local only): **Change
  status** (submenu of `options.statuses` → bulk `saveCell(id,'status',…)`),
  **Duplicate** (bulk → new `POST /seo/content/{id}/duplicate`), **Delete**
  (bulk-delete). The standalone Trash button was folded into this menu.
  **Duplicate** = `PCM_SEO_Service::duplicate()` clones a post as a DRAFT copying
  content + all post meta (SEO keys + pcm_seo_ backups) + taxonomy terms;
  hook `useSeoContent.bulkDuplicate(ids)` loops the route.
  **Slug generation** (`prompts.php` `slug`): builds the slug from the page's
  **keywords** (`{{primary_keyword}}`/`{{supporting_keyword}}`/`{{meta_keywords}}`,
  title fallback); `generate_field` runs the slug output through `sanitize_title()`
  so the staged value is always a valid slug (local + remote generate). NB:
  `build_field_vars` now also exposes `{{meta_keywords}}`. (⚠ pre-existing: the
  editor `get_default_sections('seo')` list does NOT include `slug` even though
  `prompts.php`/`get_default_prompts` do — slug isn't exposed in the prompt editor.)
  Tests:
  `SeoIntegrationTest` (key-map, detection, read-chain, dual-write, whitelist,
  substitution, output-sanitize, field map, prompt completeness).
  **⚠ Prompt editing moved (post remote-SEO refactor):** SEO prompts are NO
  longer in the Prompt editor — `PCM_REST_Prompts::get_default_sections('seo')`
  now returns `[]` (deregistered). They live as **Templates (module=seo)**, edited
  in **Settings → Templates → SEO**. `templates/controller.php` calls
  `PCM_SEO_Service::seed_seo_templates()` when listing `module=seo` (seeds one
  system default per section from `get_default_prompts()`), and
  `resolve_prompt($section,$default,$userId)` reads the user's template (else the
  `prompts.php` default). Adding a `prompts.php` entry auto-creates an editable
  template. (The text below describing a "Settings → Prompts → SEO tab" is the
  OLD pre-1.23 design — kept for context, but the editor path is now Templates.)
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
  `/settings`,`/generate`, and (post-1.27, optimizer-parity / `fb1281f`)
  `/summarize`, `/save-llms`, `/site-desc`, `/delete-all`. Frontend: SEO module
  tabbed (Content / AI Readiness), `AIReadinessPanel`.
- **Phase 4 (Schema)**: `schema.php` (`PCM_SEO_Schema`) — JSON-LD on wp_head
  (Article/WebPage/BreadcrumbList/FAQPage/HowTo/Product, publisher+logo chain,
  about/mentions+sameAs, speakable). Meta `pcm_seo_schema[_faq/_howto/
  _sameas]`. REST `GET/POST /seo/content/{id}/schema`. Frontend `SchemaCell`.
- **Phase 6 (Site)**: `site.php` (`PCM_SEO_Site`) — robots_txt filter,
  site-wide LocalBusiness JSON-LD + meta-keywords head, language/timezone
  with restorable backups. REST `GET/POST /seo/site` + `/restore`. Frontend
  third tab `SiteSettingsPanel`.
  - **One-click "Optimize" (post-1.27):** Site tab has an **Optimize** button +
    brand picker that AI-generates **robots.txt** + **LocalBusiness schema** from
    editable prompts, fills the form, enables both (user reviews → Save). Backend
    `POST /seo/site/generate` → `PCM_SEO_Service::generate_site_field(field,
    brandId,…)` (field = `robots`|`schema`; uses `build_field_vars(0,$brand)` for
    business/site vars; multi-line-safe fence-strip, NOT sanitize_ai_output). The
    two prompts live in `prompts.php` (`robots`, `site_schema`) → auto-seeded as
    editable **SEO Templates** (`Robots — Generate`, `Site Schema — Generate`) and
    honored via `resolve_prompt`. trpc `seo.siteGenerate`.
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
- **New local features (post-1.27):**
  - **`scan-links`** `POST /seo/content/{id}/scan-links` (`PCM_SEO_Service::scan_links`)
    — scans a post's links (internal/external/dead). As of 2026-06-26 it ALSO stores
    **per-link details** in post meta `pcm_seo_links` (JSON: anchor/from/to/html/status/
    kind/broken) via `scan_link_details()` (HTTP status checked, capped at 30/scan), not
    just the counts.
  - **Link inspector** (2026-06-26) — `GET /seo/content/{id}/links` (`get_post_links`),
    `POST /seo/content/{id}/links/{idx}` (`update_post_link` — rewrites the `<a>` href/
    anchor in the post content via preg + `wp_update_post`, re-scans), `POST
    /seo/content/{id}/links/{idx}/remove` (`remove_post_link` — unwraps the `<a>`, keeps
    text). `edit_posts` + `edit_post`. Frontend: a **"Scan links"** bulk PillButton
    (scans every visible row), the Internal/External/Dead **count cells are clickable**
    (open `SEO/LinksPopup.tsx` — a 7-col table select/anchor/from/to/html/status/action
    with inline edit→Save, Redirect=open in new tab, Remove, bulk-remove, + a "Re-scan
    this page" empty-state action). Same grid styling as the SEO table.
  - **Body editor** `GET/POST /seo/content/{id}/body` (+ `/optimize`) — read/save the
    full post body (the OptimizeModal flow now has explicit get/save body routes).
  - **LLM-info** `GET/POST /seo/llm-info` + `/seo/llm-info/build` + **`/seo/llm-info/keywords`**
    (`manage_options`; remote mirror `/seo/sites/{id}/llm-info/{build,keywords}`) — generates
    an LLM-facing site info doc (served at `/llm-info/`, distinct from AI-readiness's `/llms.txt`);
    takes model+provider like field-gen. Prompt (`llm_info_prompt`) is keyword-optimized +
    positively framed (authority, years-in-business, local-to-area) from facts you provide.
    **Keyword auto-detection (2026-07-02):** `PCM_SEO_Service::top_keywords($pages,$limit)`
    (pure — unigram+bigram frequency over the content corpus, minus stopwords, count≥2) +
    `keywords_to_string()`; the `/keywords` routes return `{keywords, list:[{term,count}]}` and
    `build_llm_info` **auto-fills** target keywords from the corpus when the field is left blank
    (local uses `local_content_corpus`, remote uses `remote_content_corpus`). Unit-tested in
    `tests/unit/SeoIntegrationTest.php` (`test_top_keywords_*`, `test_keywords_to_string_*`).
    Editor UI has a "Detect from site" (ScanSearch) button + clickable frequency chips. **UI: `LlmInfoSection`
    (`SEO/LlmInfoEditor.tsx`) renders at the TOP of the AI Readiness tab**, mounted in
    `SEO/index.tsx` under `tab === 'air'` (ABOVE `AIReadinessPanel`/`RemoteAIReadinessPanel`)
    — deliberately OUTSIDE those panels so it stays usable even when the llms.txt status/
    form fails to load (e.g. older connector on a remote site). Self-wires local vs remote
    by the optional `siteId` prop. Nav path: SEO module → *This Site* (or a connected-site
    tab) → left nav **AI Readiness**. (It was fully built — routes + trpc + component — but
    orphaned/unrendered until 2026-07-02; first wired inside the two panels, then lifted to
    the tab top the same day for discoverability + decoupling.)
  - **AI field generation** now also accepts a `template_id` (prompt template) in
    addition to `model`+`provider`.
- **Remote-site SEO (via the connector — major addition, was "coming soon"):**
  Connected remote sites are now managed end-to-end through the hub's
  `/seo/sites/{id}/*` routes (ALL `manage_options`), which proxy to the remote
  connector plugin's `/pcm-conn/v1/*` endpoints (connector **v1.3.1** required on
  the remote; v1.3.1 adds front-end app-password auth for the page preview).
  Surfaces:
  - Content: `GET/POST /seo/sites/{id}/content`, `.../content/{post}/cell`,
    `.../generate`, `.../scan-links`, `.../schema`, `.../delete`,
    `.../content/{post}/featured` (set featured image; `fb1281f`),
    `.../content/{post}/links` (GET, on-demand parse of remote raw content) +
    `.../links/{idx}` (update) + `.../links/{idx}/remove` — `remote_get_links` /
    `remote_update_link` / `remote_remove_link` (fetch via connector `context=edit`,
    rewrite the `<a>`, PUT back); same `LinksPopup` UI, 2026-06-26. Each link carries an
    `editable` flag (remote: true only when it lives in `content.raw`; rendered-only links
    from page-builder/block layouts are listed `editable:false` and shown read-only in the
    popup — 2026-06-29 fix for "remote edit not working"),
    `.../content/{post}/duplicate` → `remote_duplicate` (clone as draft "(Copy)"
    with content + SEO meta; bulk Duplicate now works on remote, 2026-06-26)
    (`remote_*` methods in `seo/service.php`; frontend `hooks/useRemoteSeoContent.ts`).
  - **Authenticated page preview:** `POST /seo/sites/{id}/preview` →
    `remote_preview` → `PCM_SEO_Service::remote_preview_html` GETs the page with
    the connector's app-password Basic auth (host-guarded), injects `<base href>`,
    returns `{html}`; `SEO/index.tsx` renders it via `srcDoc` (same-origin → WP
    admin bar shows), FALLING BACK to a direct `<iframe src>` if the fetch fails.
    Requires connector **v1.3.1** (front-end app-password auth) on the remote.
  - Site SEO (robots + JSON-LD): `GET/POST /seo/sites/{id}/site` + `/site/generate`
    (one-click optimize; `fb1281f`) → `RemoteSiteSettingsPanel.tsx` → connector
    `/pcm-conn/v1/site`.
  - AI Readiness (llms.txt build/edit/toggle): `GET/POST /seo/sites/{id}/ai` +
    `/ai/build`, `/ai/posts`, `/ai/site-desc` (`fb1281f`) →
    `RemoteAIReadinessPanel.tsx` → connector `/pcm-conn/v1/ai`.
  - LLM-info: `GET/POST /seo/sites/{id}/llm-info` + `/build`.
  - `RemoteSitePlaceholder` still renders as the FALLBACK for site tabs/sections
    not yet wired for a given remote; content/site/AI/llm-info tabs now show real
    management panels when the connector supports them.
- **Model picker (header):** the content tab has a text-model dropdown next to
  Post/Page (always visible; "Default model" + registered text models via
  `trpc.models.getForGeneration {type:'text'}`, persisted to localStorage
  `pcm:seo:gen-model`). NB: only populates when the registry has **text-capable**
  models (`wp_pcm_models.canGenerateText=1`) — an image-only registry shows just
  "Default model".

## SEO Hub (multi-site connectors, v1.23.0+, DB 1.23.0)
> **Pairing-code "one-paste" connect (newer, preferred path):** alongside the
> original HMAC-handshake tenant ZIP, there's now a **generic, tenant-free
> connector** ZIP (`GET /seohub/connector-download`, `manage_options:strict`;
> built by a `PCM_SEOHub_Service` generic-source builder ~line 357). The remote
> connector (Power Creatives Connector **v1.3.1**) exposes SEO meta in REST,
> manages site-wide robots.txt + JSON-LD, serves `/llms.txt` + `/llm-info/`,
> enables app-password auth on FRONT-END requests
> (`application_password_is_api_request → __return_true`, v1.3.1 — lets the hub's
> authenticated page preview render the WP admin bar), and shows a **one-paste
> connection code** in its admin — the user pastes that code into the hub's "Add
> Site" to pair (no per-tenant ZIP, no activation handshake). NOTE: connector
> source changes require the user to RE-DOWNLOAD + reinstall the connector.
> This is what powers the remote-SEO suite above (`/pcm-conn/v1/*`).

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

### ⚑ Current connection flow = pairing code (supersedes the HMAC handshake above)
The HMAC handshake / `register_ping` / per-tenant connector / "Pending connections" path
above is **legacy and frontend-orphaned** (kept in the backend, unused by the UI). The live
flow is a **one-paste pairing code**, which sidesteps the public-hub requirement entirely:
- **Connector = `PCM_SEOHub_Service::connector_php_simple()`** (generic, no handshake), now
  **v2.6.0 (was v2.0.0 = universal builder-aware link replacement)**: pluggable handler architecture
  inside the connector — `PCM_Conn_Builder_Handler` interface + `PCM_Conn_B_{Elementor,Bricks,Divi,
  WPBakery,Oxygen,Breakdance,Brizy}` (detect() via builder meta/content signals + regenerate() its CSS/cache) +
  `PCM_Conn_Builder_Manager` (register/detect/replace_links) + `pcm_conn_builder_manager()` registry.
  **2.2.0 = BASE64-aware scan + replace (Brizy).** Brizy stores its page as base64-encoded `editor_data`
  (JSON) + `compiled_html` in post meta, invisible to the plain string pass — so links neither scanned nor
  edited. Fix: `pcm_conn_replace_in` decodes a pure-base64 leaf, replaces inside, re-encodes (only on a
  real, valid-UTF-8 hit — never corrupts); `pcm_conn_meta_may_contain` gates the replace-loop prefilter on
  plain-OR-base64 presence (else Brizy metas were skipped); `collect_links` decodes base64 leaves to recover
  `<a href>` (compiled HTML) / `url` fields (editor JSON). `PCM_Conn_B_Brizy` has no source_keys (content-based
  scan of all meta) + regenerate() forces a Brizy recompile via `Brizy_Editor_Post::set_needs_compile(true)`.
  Existing builders unaffected (base64 path is a strict fallback + pure-base64 guard). Brizy links carry no
  elId → hub uses the GLOBAL replace path. **2.2.1 (commit `6f7b572`) extended base64 to HEADINGS:**
  `collect_headings` is now base64-aware (decode → JSON recurse / inline `<hN>` extraction), de-dupes
  elId-less builder headings (Brizy stores each heading in BOTH the compiled-HTML + editor-JSON metas), and
  `replace_links` gained a fully JSON-escaped needle variant (escapes `"` AND `/`) so a heading with class
  attributes matches inside a base64(JSON) blob (for a bare URL this equals the slash variant → link
  behaviour unchanged). **2.2.2 fixed the ELEMENT-SCOPED path** (`replace_link_in_element`, used for any
  heading/link that carries an `el_id` — e.g. Elementor inline headings in Text-Editor widgets): it had a raw
  `strpos($raw,$old)` pre-filter that missed the JSON-escaped stored form (`\"`, `\uXXXX`), so headings with
  attributes or non-ASCII text (Swedish å/ä/ö) were silently skipped (replaced=0 → edit didn't reflect). The
  pre-filter is removed — the `el_id` already narrows the meta and the real match runs on the DECODED
  structure in `replace_in_element` (literal quotes/slashes/unicode). `replace_anchor_in_element` already
  guarded on `el_id` only, so it was unaffected. **2.2.3 = Brizy recompile reliability.** Brizy serves a
  CACHED "compiled HTML" copy and regenerates it from its editor data; after a heading edit the DB was correct
  (editor data updated → SEO table showed the new text) but the live page kept the old text because
  `PCM_Conn_B_Brizy::regenerate` only tried Brizy's PHP API (`Brizy_Editor_Post::get()->set_needs_compile()`),
  whose signature/method names vary by version and silently no-op'd. Added a recursive raw-meta fallback
  (`brizy_set_needs_compile`) that flips Brizy's own `needs_compile` flag straight in the `brizy-post`/`brizy`
  meta so the frontend rebuilds on next view — sets ONLY the flag (never touches editor_data or blanks the
  compiled cache → no blank-page risk). ⚠ If still old after reinstall, the remaining suspect is a server/CDN
  page cache (Cloudflare without the CF plugin) that `pcm_conn_purge_caches` can't reach → purge manually.
  **2.4.0 = SELF-UPDATING connector + GSC token host.** The generated connector now ships an
  `Update URI:` header + a self-update block (baked via `strtr` placeholders — the heredoc is a NOWDOC,
  no interpolation): `update_plugins_<hub-host>` filter polls the hub's PUBLIC
  `GET /seohub/connector-manifest` (version + package URL + sha256), `upgrader_pre_download` verifies the
  sha256 of the PUBLIC `GET /seohub/connector-package` zip, `auto_update_plugin` filter opts in → WP
  auto-updates it on its twice-daily check. Manifest/package come from
  `PCM_SEOHub_Service::connector_artifact()` — the zip is CACHED in option `pcm_seohub_conn_pkg` keyed by
  `md5(source)` so the manifest sha256 always equals the served bytes. Instant push: connector route
  `POST /pcm-conn/v1/update-now` (app-password auth; busts the 12h transient, `wp_update_plugins()`,
  `Plugin_Upgrader`) called per-site by hub `POST /sites/update-connectors` (Sites → "Update connectors"
  toolbar button). ⚠ Connectors <2.4.0 predate the mechanism — each needs ONE manual reinstall to seed it;
  the bulk push reports those as "predates self-update". Also 2.3.0+: `POST /pcm-conn/v1/site` accepts
  `gscToken` (option `pcm_conn_gsc_token`, echoed back as store-proof) rendered in `wp_head` as
  `<meta name="google-site-verification">` — the hub's GSC verify flow pushes it (see *sites → gsc_provision*).
  2.1.x added connector routes **`/replace-anchor`** (edit a link's anchor text, incl. builder widget
  labels + Elementor `el_id`), **`/scan-links`**, **`/scan-headings`** + **`/replace-heading`** (H1–H6
  scan/edit, builder-widget text+level), used by the hub's `remote_replace_anchor` /
  `remote_scan_headings` / `remote_update_heading` and the SEO **Headings** editor (`SEO/HeadingsPanel.tsx`
  + `/seo/{content,sites/{id}/content/{post}}/headings[…]` routes). **Read-only heading UX (2026-07-07):**
  the scan marks all headings `editable:true`, so "can't edit here" surfaces at SAVE time — `HeadingsPanel`
  now branches on the WP error CODE: `pcm_seo_heading_stale` → toast with a **Re-scan** action
  (`query.refetch()`), `pcm_seo_heading_not_found`/`_not_editable` → flips that row to read-only with a `Lock`
  + reason tooltip (mirrors the LinksPopup read-only affordance). This relies on **`app/src/lib/trpc.ts` now
  preserving `error.code` + `.status` on the thrown Error** (additive; any caller can branch on `e.code`).
  **Shared-template heading editing (2026-07-07, connector 2.4.0 → 2.5.0):** headings that live in a
  SHARED source rendered on many pages — Elementor Theme Builder templates (`elementor_library`) +
  Gutenberg reusable blocks (`wp_block`) — are now scanned + editable, not just the page's own. Connector
  `scan_template_headings()` (new) walks those posts (skips Elementor types page/section/container/popup/kit/widget),
  tags each heading `source`/`sourcePostId`/`sourceType`/`sourceLabel`; the `/scan-headings` route merges them
  after the page's own + re-indexes. Hub: `remote_get_headings` passes the fields through; **`PCM_SEO_Service::heading_target_post_id($h,$pagePid)`**
  (pure, unit-tested) picks `sourcePostId` (>0) else the page, and `remote_update_heading` sends that as `post_id`
  to `/replace-heading` + `/replace-url` — the existing routes are post-id-generic + capability-check `edit_post`
  on the passed id (no priv-esc). Frontend: template/block rows show an amber `LayoutTemplate` "lives in: …"
  badge whose tooltip warns the edit changes EVERY page using that source (rows stay editable). Connector
  self-updates from the hub (twice-daily) once the hub rebuilds the connector zip — no manual reinstall needed.
  **Render-time heading OVERRIDES (2026-07-07, connector 2.5.0 → 2.6.0) — theme-PHP-hardcoded headings are
  now editable too.** `/scan-headings` additionally loopback-fetches the page's RENDERED HTML
  (`wp_remote_get(get_permalink)`, 8s, skipped silently if the host blocks loopback) and appends `<hN>`s not
  found in any DB source, tagged `source:'rendered'` ("Theme / hardcoded") — or `source:'override'` when the
  text matches an ACTIVE override's current value (keeps them re-editable). Editing one goes hub
  `remote_update_heading` (new first branch on source rendered|override) → connector
  **`POST /pcm-conn/v1/override-heading`** {oldText, oldLevel, newText, newLevel, post_id} (`manage_options`),
  which stores {original→new} pairs in option `pcm_conn_heading_overrides` (keyed by ORIGINAL text/level:
  re-edits match the entry's CURRENT value and update in place; editing back to the original DELETES the
  entry = clean revert; 200-entry cap) + purges caches. A `template_redirect` (prio 1) output buffer then
  rewrites matching `<hN attrs>visible-text</hN>` (match = stripped inner text, so spans/links don't block;
  attrs preserved; inner replaced with esc_html(new)) on EVERY frontend page — SITE-WIDE by design (theme
  headings render identically everywhere; the badge tooltip warns). PCRE failure → page passes through
  untouched. Verified: override_test.php 20/20 (lifecycle create→render→re-edit→revert, scan tagging,
  guards) + heading/brizy connector regressions 3/4/4.
  `POST /pcm-conn/v1/replace-url` {post_id, old, new | replacements{}} → manager replaces the URL in
  post_content AND **every custom field** (serialization-safe via `pcm_conn_replace_in` recursion over
  strings/arrays/objects + `update_metadata_by_mid(wp_slash())`, matching plain + JSON `\/` forms) →
  regenerates detected builders' caches → `pcm_conn_purge_caches($id)` (WP Rocket/W3TC/WP Super/WP
  Fastest/LiteSpeed/Cache Enabler/SG/Nginx-Helper/Super-Page-Cache-for-Cloudflare) → VERIFIES no old
  URL remains → returns `{replaced, where[], builders[], steps[], verified, remaining[]}`. Also a
  `wp_after_insert_post` cache-purge hook. The hub's `remote_rewrite_link_content` calls it after a
  href edit; the rendered-check message uses the result + `remote_connector_version()` (reads the
  connector's installed version via remote `/wp/v2/plugins`): replaced>0 but cached → "clear cache
  (builders)"; replaced=0 → "link is in theme/menu/widget"; connector <2.0.0 → "reinstall v2.0.0+".
  Bare Cloudflare proxy w/o a WP plugin still needs manual purge / official CF plugin. ⚠ NB: connector
  version is SEPARATE from `PCM_VERSION` (main plugin = 1.7.0). **Connector changes require re-download
  + reinstall on each connected site.**),
  downloaded via `GET /seohub/connector-download` (`download_connector_generic`,
  streamed). On activation it self-creates one WP Application Password and its admin page shows a
  **`base64(JSON{url,user,pass})`** connection code. It registers Yoast/RankMath/SEOPress/`pcm_seo_*`
  meta (incl. **`pcm_seo_schema`**) in REST.
- **Connector REST surface `pcm-conn/v1`** (app-password authed, `permission_callback = manage_options`,
  no nonce needed under Basic auth): `/site` GET/POST (custom robots.txt via `robots_txt` filter +
  site-wide JSON-LD via `wp_head`) and `/ai` GET/POST (llms.txt content + enabled; serves a virtual
  **`/llms.txt`** and per-page **`/{slug}.md`** via `template_redirect`, `pcm_conn_html_to_md`).
- **Hub Sites UI** (`app/src/modules/Sites/index.tsx`): `Add Site` → admins get the **'choose'** dialog
  (download connector + paste code → `sites.create`), non-admins get **'password'** (manual App-Password).
  AddStep is `null|'choose'|'password'`.
- **Remote SEO management** (manage a connected site's SEO **from the hub**) lives in the **seo module**:
  `PCM_SEO_Service::remote_*` + routes `/seo/sites/{id}/content[...]`, `.../content/{post}/{cell,generate,scan-links,schema}`,
  `.../site` (GET/POST), `.../ai` (GET/POST), `.../ai/build`. All proxy via `PCM_Sites_Service::remote_rest`
  (Basic auth, permalink-agnostic `?rest_route=` form). Frontend: `useRemoteSeoContent` +
  `RemoteSiteSettingsPanel` + `RemoteAIReadinessPanel`; the SEO module's Content/AI-Readiness/Site tabs
  branch local vs remote (Business is hub/brand-level). Site/AI tabs need connector **v1.2.0+** (else 422).

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
- **New table UI** — DON'T hand-roll `<table>`. Two reusable tiers:
  - Simple/standard table → `@/components/ui/data-table.tsx` `<DataTable columns data rowKey>`
    (config-driven: per-column `{key, header, cell, sortAccessor?, width?, className?}`; owns
    SEO-style grid styling + sorting via `useSortableTable`). Reference: `modules/Sites/index.tsx`.
  - Rich spreadsheet (resize/reorder/persist/inline-edit/per-column filter+generate) → compose
    `@/components/ui/column-head.tsx` + `@/hooks/useColumnLayout.ts` (pass a unique storageKey) +
    `@/hooks/useColumnFilters.ts<Row>` + your own `FilterDef<Row>` map. Reference: `modules/SEO/index.tsx`.
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
- **Tailwind v4 gates `hover:`/`group-hover:` behind `@media (hover: hover)`** — these
  utilities are NO-OPS on touch-capable / coarse-pointer devices (common on Windows
  laptops; Brave can report it too). So hover-reveal UI (e.g. show-checkbox-on-row-hover)
  built purely with `group-hover:` silently fails for those users. Drive such reveals
  with JS hover state (`onMouseEnter`/`onMouseLeave`) instead. Fixed this way in
  `SEO/index.tsx` row-number→checkbox swap (2026-06-18).
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
