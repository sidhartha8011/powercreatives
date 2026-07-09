# CLEANUP GAP ANALYSIS — One Brain, Dumb Frozen Connector (2026-07-09) — AWAITING GO

Owner order: NO technical debt. This document states exactly what the cleanup
does, where, and why — so anyone can follow the work. Companion contracts:
`docs/DYNAMIC-OPTIMIZATION-ARCHITECTURE.md`. All facts code-verified this day.

## WHY (the debt, objectively)

| # | Debt | Evidence |
|---|------|----------|
| D1 | The matching/parsing brain exists TWICE: hub `includes/modules/seo/class-pcm-text-matcher.php` (reference) + a mirrored copy inside the connector template (`includes/modules/seohub/service.php`, `pcm_conn_*` functions) held together by a documented "sync contract" | Every engine fix landed twice and shipped as a connector bump: 2.8.0 → 2.8.1 → 2.8.2 → 2.8.3 in ONE day |
| D2 | THREE stacked edit layers on every page render: (a) legacy heading overrides (`pcm_conn_heading_overrides`, own `template_redirect` buffer, prio 1), (b) dynamic-rules engine (prio 0 buffer), (c) source writes. Coupled by buffer ordering | The 2.8.2 bug (rules ran before overrides → permanent miss, proven live) and the 2.8.3 bug (heading scan saw served text) were exactly these layers colliding |
| D3 | TWO scanners with divergent behavior: `/pcm-conn/v1/scan-headings` (storage+rendered, NOT rule-excluded until 2.8.3) vs `/pcm-conn/v1/scan-content` (rendered, rule-excluded, chrome-stripped) | The stranded-row screenshot: heading rows showed SERVED text while paragraph anchors carried originals → grouping keys mismatched |
| D4 | Hardcoded tunables in the connector: scan cache TTL (10 min), loopback timeout (8s), chrome region list (header/nav/footer/aside), stats throttle (5 min), overrides cap (200) | Owner rule: tunables must be hub-pushed data; each change today = a fleet update |
| D5 | Fleet version drift: connected sites run 2.2.x … 2.8.3; every hub feature carries old-version fallbacks (`connector_rules_schema_version`, honest-error paths) | Permanent conditional complexity in `seo/service.php` |

Root cause of D1–D3: the inherited architecture put READING intelligence on the
connector. Reading is just HTML parsing — it belongs in the app.

## TARGET (the no-debt state)

**The hub owns ALL intelligence.** The connector holds exactly FOUR dumb jobs,
then freezes (target: connector 3.0.0, expected to change ~never):

1. **Page snapshot** — return the rendered page HTML (rule-serving disabled,
   overrides… gone, see below). One endpoint replaces both scanners' parsing.
2. **Storage writers** — the EXISTING, proven heading/link/meta source-write
   endpoints (`/replace-heading`, `/replace-url`, meta/site endpoints).
   UNCHANGED — they need WP/builder internals by nature.
3. **Guarded apply** — apply hub-precomputed serving instructions at render;
   refuse-if-unsure stays ON-SITE by necessity (verification must happen at
   serve time). This becomes the ONLY implementation: the hub's mirror is
   DELETED; tests run the connector's REAL extracted code (harness exists —
   `parity_smoke`/template-extraction pattern from this session).
4. **Hub-pushed config** — every tunable from D4 becomes an option the hub
   POSTs (existing `/pcm-conn/v1/site` channel pattern); code keeps defaults.

**One render layer:** legacy heading overrides are COMPILED by the hub into
the same instruction stream (the engine has been target-agnostic since v1 —
`heading` was a reserved target from day one). The overrides option/table
migrates: hub reads each site's `pcm_conn_heading_overrides`, converts to
heading instructions, pushes, connector drops the overrides buffer. D2 dies.

**One parser:** the hub parses the snapshot with the EXISTING fixture-tested
`PCM_Text_Matcher` family — headings hierarchy, paragraphs, sections, links
display data all from one pass. D1/D3 die.

## FUNCTION-INTACT MAP (nothing user-facing changes)

| Function | Today | After | Change for the user |
|---|---|---|---|
| Site title / tagline / meta title / description / keywords / slug / schema / robots / GSC | WP REST + connector meta endpoints | SAME endpoints, untouched | none |
| Heading one-click edit (text + H1–H6, builders incl. Elementor/Brizy) | scan-headings storage handles + writers | SAME writers; handles resolved from hub parse + existing text/elId search | none |
| Rendered-only headings (theme/nav) | override layer | same edits, served via heading instructions | none |
| Sections: editor window, AI, versions, Add section | hub UI + rules push | SAME — only the scan source changes | none |
| Paragraph rules (legacy rows from pair 3) | served by engine | still served; absorbed into sections on next save (existing law) | none |
| Links inspector/editor | scan-links + writers | writers same; display data from hub parse | none |
| Kill switch, counters, purge, self-update | connector | unchanged | none |

## THE WORK (order, each step = full ritual, local commits only)

**C1. Contracts first (docs):** snapshot endpoint v1 · instruction stream v2
(adds `heading` target + compiled-override semantics) · config schema v1 ·
hub-side parse = the single identity source. Freeze in the architecture doc.

**C2. Hub parser consolidation:** `PCM_SEO_Service` gains
`parse_page_snapshot()` (headings + sections + links from one HTML pass,
reusing PCM_Text_Matcher). HeadingsPanel/outline reads ONE endpoint
(`GET /seo/sites/{id}/content/{post}/inventory`). Old proxy endpoints kept
thin-wrapping it for one release (honest deprecation, then deleted).

**C3. Connector 3.0.0:** snapshot endpoint (rule-serving excluded, loopback
hardening kept) · apply engine kept as the ONLY copy (hub mirror class methods
`apply_*`/`parse_*` deleted; `normalize`/`fingerprint` stay hub-side for
identity computation — they are hub intelligence, and the connector's copies
are tested against them via the extraction harness) · overrides buffer removed
after migration · config store. Scanners deleted.

**C4. Override migration:** hub command "convert legacy heading overrides →
instructions" per site; verified per site before the overrides buffer drops.

**C5. Config push (D4)** + **fleet convergence (D5):** one bulk connector
update; hub drops pre-3.0 fallbacks the release after the fleet reads 3.0.0.

**Verification per step:** php -l + extracted-template lint · matcher fixtures
· extracted-REAL-connector behavior tests (no mirror) · tsc 59 baseline ·
build · live end-to-end on powerleads (edit → serves; heading edit → section
follows; snapshot honest under WAF fallback).

## OUT OF SCOPE (unchanged)
Draft/Publish (paused, spec on file) · approvals/changesets (pair 5) ·
switches UI (pair 4) · AI optimizer · licensing.

## RISKS (named, mitigated)
- Sites where the hub cannot fetch pages directly are ALREADY handled: the
  snapshot comes from the connector (loopback machinery kept), not from
  hub-side fetching — WAF behavior unchanged.
- Override migration is per-site and verified before the old layer drops
  (no big-bang).
- One unavoidable fleet update (to 3.0.0) — the LAST routine one.
