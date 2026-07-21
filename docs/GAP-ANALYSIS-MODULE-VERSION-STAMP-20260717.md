# GAP ANALYSIS — MODULE VERSION STAMP — 2026-07-17

Owner order (direct, same day as the stale-tab incident): every module shows
its own version, bottom-left, light grey/low opacity. Each module versions
independently. Purpose: end the "which code is this tab running" blindness
that burned the whole day.

## FACTS (verified)

- Frontend modules render through ONE spot: Shell.tsx:37-59 moduleRegistry
  (Record<ModuleId, Component>), picked by activeModule (:66-69); TWO layout
  branches (full-bleed :86-96, padded :97-114) inside ONE <main> (:85).
  `text` is an alias of `copy` (:43).
- ModuleId union: app/src/types/index.ts:60 (21 ids incl. the alias).
- NO version exists anywhere per frontend module today; the only global
  version is the plugin header (PCM_VERSION 1.7.0 — backend, not per-module,
  not bumped without owner ask per CLAUDE.md).
- Build identity: none in the bundle — the exact gap that made today's
  stale-tab impossible to see. vite.config.wp.ts:17 defineConfig has no
  `define` block; vite-env.d.ts is the one-line vite/client reference.
- Laws honored: state statements are sanctioned UI (no-instructional-chrome
  law allows them); hover/title tooltips sanctioned; shared-component law →
  ONE stamp implementation in the Shell, never per-module copies.

## GAP → TARGET

1. **ONE registry** `app/src/lib/module-versions.ts`:
   `MODULE_VERSIONS: Record<ModuleId, string>` — all seeded `1.0.0` today
   (the alias `text` reads copy's entry). PROCESS LAW ADDED to the ritual:
   any pair that touches a module's frontend BUMPS that module's version in
   the same commit — the stamp is only worth what the discipline keeps it.
2. **Build stamp = machine truth**: `__PCM_BUILD__` define (build datetime,
   vite.config.wp.ts) + declaration in vite-env.d.ts. Human version +
   machine stamp together — a stale tab is visible on hover regardless of
   whether anyone remembered a bump.
3. **ONE mount** in Shell.tsx `<main>` (made relative): absolute bottom-left,
   `text-[10px] text-slate-400/60`, non-blocking (tiny corner element),
   `title` = "build …" tooltip. Renders `{module} v{version}` for the
   active module. Both layout branches covered by the single mount. No
   per-module edits, no duplicates.
4. NOT touched: PCM_VERSION (owner-ask law), backend module configs,
   sidebar, any module internals.

## VERIFY
tsc 59 zero new → build ("built in") → served bundle byte-identical →
the stamp string present in the served JS (grep) → changelog → AFTER
commit LOCAL ONLY.
