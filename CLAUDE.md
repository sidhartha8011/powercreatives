# CLAUDE.md — Power Creatives

## Onboarding
See [`.claude/CODEBASE_MAP.md`](.claude/CODEBASE_MAP.md) for the full architecture map.
Re-run onboarding if the plugin's architecture or build system changes.

Also authoritative (read before changing anything): `docs/ARCHITECTURE.md`,
`docs/CONTRIBUTING.md`, `docs/UPGRADE-SAFETY.md`, `AGENTS.md`, and the newest
`docs/CHANGELOG-*` / `docs/HANDOVER-*`.

## What this is
WordPress plugin (PHP 8.1+, slug/text-domain `power-creatives`, v1.7.0, DB v1.17.0)
wrapping a React/TypeScript (Vite) SPA. Vertical-slice modular monolith: 18 REST
modules auto-discovered from `includes/modules/*/config.php`; shared infra in
`includes/core/`; frontend in `app/`. Includes a cross-module **Automations**
engine (trigger → condition → action) with WP-cron-driven daily reminders.

## Non-negotiables for this plugin
- **Every REST handler**: nonce check + capability check + sanitize input + escape
  output. `PCM_REST_Base` enforces nonce (`X-WP-Nonce`) + capability (default
  `manage_options`) — keep new routes on that base; make public routes explicit.
- **All user-facing strings** use the text-domain `power-creatives`.
- **No direct SQL without `$wpdb->prepare()`**; table names come from `PCM_Schema::table()`,
  never from user input.
- **REST responses** go through `$this->success()/error()/not_found()`, never raw `WP_REST_Response`.
- **Add features as modules** (`config.php` + `controller.php` + `service.php`) — never
  hand-register routes in `power-creatives.php`.
- **DB changes**: `dbDelta` is additive; for renames/backfills add a `migrate_*` method +
  `version_compare` gate in `PCM_Activator::maybe_upgrade()` and bump `PCM_DB_VERSION`.
  Keep migrations idempotent (they run on every page load).
- **Don't bump** the `Version:` header / `PCM_VERSION` / changelog unless explicitly asked.
- **Don't hand-edit** `vendor/`, `node_modules/`, or built `app/dist/` assets.
- **Frontend**: run `npm run check` (tsc) after every TS change; normalize numeric IDs
  with `Number()` (wpdb returns strings); wrap DnD optimistic updates in `flushSync()`.
- **`wp_pcm_assets` is off-limits** to the Approvals domain (per-item metadata → snapshot JSON).
- Provider routing is **data-driven** — send/require `model.provider`; `detect_provider()` is deprecated.
- **Automations**: a module's triggers/actions/handlers go in `includes/modules/{id}/automations.php`
  (loaded by the `power-creatives.php` glob). Emit via
  `PCM_Automation_Engine::fire_trigger($triggerId, $context, $userId [, $opts])`; use the
  optional `dedupeKey` for time-sliced loops (matches any prior log row in that slice).
  Long-running actions declare `mode() === 'async'`; the engine schedules them via
  `wp_schedule_single_event`. **Don't hardcode** business detail in the seeder — URLs,
  days, recipients stay user-editable on the rule. See `.claude/CODEBASE_MAP.md` →
  *Automations engine*.

## Verify
- PHP: `composer test` (PHPUnit + Brain Monkey + wp_mock). Frontend: `cd app && npm run check && npm run build`.
- No PHP linter is configured; follow WPCS by hand.
