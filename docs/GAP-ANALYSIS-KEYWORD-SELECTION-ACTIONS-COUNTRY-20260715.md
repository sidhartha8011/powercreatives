# GAP ANALYSIS — KEYWORD SELECTION + SELECTION-SCOPED ACTIONS + COUNTRY (owner orders 2026-07-15) — AWAITING GO

Blueprint: BLUEPRINT-MASTER-OPTIMIZER-20260715.md §10 (appended today).
Supersedes GAP-ANALYSIS-KEYWORD-INJECTION-20260715 (37e3c44) where noted.

## PART 1 — COUNTRY RESOLUTION (the owner's question, answered)

**"How does the keyword search select language/location?" — IT DOESN'T.**

| # | Fact |
|---|---|
| C1 | `ahrefs_enrich(…, $country = 'us')` — hardcoded US default (keywords/service.php:298); the optimizer's volume callback passes NO country (optimizer/controller.php:81). Every drawer volume is the US market |
| C2 | The keywords search endpoint reads a `country` request param defaulting 'us' (keywords/controller.php:129) — no caller ever sends one; nothing anywhere consults site location |
| C3 | The sites table has NO location column (probed: id, userId, name, url, username, appPassword, status, lastSyncAt, createdAt, updatedAt, connectMethod, brandId). The brand's GBP record carries `formattedAddress` free text + lat/lng (gbp.php:148-151) — no structured country field |
| C4 | A language→country map + `get_country()` already exist (keywords/service.php:109-135, sv→se) |
| C5 | The global volume cache is keyed by KEYWORD ONLY (optimizer/service.php:285-345) — with per-site countries, one market's numbers would poison another's. The key MUST gain the country |

### Design (country)
- **DC1 — `PCM_Keywords_Service::resolve_country(object $site): string`** (my
  module): brand's GBP `formattedAddress` matched against a small
  country-name→code data map → hit wins; else the hub option
  `pcm_kw_default_country`, seeded `'se'` (owner ruling: Sweden default),
  hub-editable — never code (standing law).
- **DC2 — country-aware volume cache**: cache key becomes
  `"<country>|<keyword>"` (optimizer/service.php — HIS in-flight file:
  exact spec here, he applies or it queues); old keys age out via the
  30-day TTL, no migration (stated).
- **DC3 — wiring**: the volumes endpoint reads `siteId` (already known to
  the drawer) and resolves server-side; the keywords search endpoint the
  same. Frontend sends `siteId` in both payloads (KeywordsDrawer — mine).

## PART 2 — KEYWORD SELECTION + THE SELECTION-SCOPED ACTION LAW

| # | Fact |
|---|---|
| S1 | Text-selection scoping is ALREADY the law for content: `startAiReview(topic, scope)` narrows the run to the touched sections and the button label transforms (SectionModal.tsx:1081-1096, :1722) — the owner's matrix extends this to keywords, same shape |
| S2 | The shared DataTable has controlled checkbox multi-select (data-table.tsx:144-150) — zero bespoke UI |
| S3 | The drawer's selected rows carry `{kw, role}` P/S/A (KeywordsDrawer.tsx:42-46) — count + roles for the run come free |
| S4 | THE RIDE today joins primary + bucket ONLY (SectionModal.tsx:1069-1075) — SUPPORTING keywords never reach a run. The owner's nothing-selected default (ALL keywords) fixes this omission |
| S5 | Hard lanes: drawer = mine; SectionModal + optimizer controller/service = his (blueprint §9.12 already reserves the button transform for his lane) |
| S6 | Supersession: injection gap 37e3c44 D1 (selection in SectionModal) and D2 (third label state) are replaced by the matrix below; D3 (hierarchy topic) and D4 (ride suppression) STAND |

### Design (THE ACTION MATRIX — owner law, one table)

| Keywords ticked | Text selected | The run |
|---|---|---|
| none | none | ALL keywords (P+S+A) × ENTIRE content (the ride grows to all roles — S4 fixed) |
| none | yes | ALL keywords × the selected sections (existing scope law) |
| some | none | EXACTLY the ticked keywords × entire content — button reads **Insert keywords (N)** |
| some | yes | EXACTLY the ticked keywords × the selected sections — same label, scope stated in the title |

- **DS1 (mine, buildable now):** selection state lives IN the drawer
  (`Set<kw>` + DataTable `selection` on the selected table, count in the
  header); exposed upward through ONE callback prop — the agreed
  crossing. Clears on drawer close.
- **DS2 (his lane, spec ready):** SectionModal consumes the selection:
  button transform per the matrix, injection topic = the hierarchy law
  verbatim with the ticked keywords grouped by role, `suppressKeywordRide`
  on injection runs (37e3c44 D3+D4), ride grows to P+S+A when nothing is
  ticked.
- **DS3:** "Insert into content" IS the transformed button — a named run
  type, red/green as always, no new pipeline.

## PART 3 — logged, not gapped here
Review diff consolidation (≥~15 adjacent strikes → one section-rewrite
block) — appended to the blueprint §10; display layer, editor lane, own
gap when its turn comes.

## Checklist (execution split by lane)
**PAIR A (mine, country):** resolve_country + seeded default option +
keywords endpoints resolve from siteId + drawer sends siteId — php -l ·
harness · tsc · build · changelog · pathspec commit.
**PAIR B (mine, selection):** drawer selection UI + header count + the
callback prop — same ritual.
**HIS three edits (spec above):** volume cache country key (DC2) ·
volumes endpoint siteId (DC3 server half) · SectionModal matrix (DS2).

## Regression surface (named)
Volumes: US-cached entries age out via TTL; until re-fetch the drawer
shows the old number (honest, dated by design) · zero-selection behavior
today = unchanged until DS2 lands · no shared component modified ·
compiler/review untouched.

---

# ADDENDUM — THE BACKBONE + THE SMART COUNTRY CHAIN (owner GO 2026-07-15)

**Owner rulings this addendum executes:** (1) the DRAWER is the BACKBONE
of the whole selection mechanism — keyword selection, content, parts of
content — feeding optimize, generate, AND analyze alike; (2) ALL keywords
(P+S+A) join every run; (3) country resolves SMART, three steps: the
site's set location (brand) → a quick AI language check on the site's
actual content → DEFAULT SWEDEN. Lanes: the other dev's arc LANDED, tree
clean (verified) — the SectionModal/optimizer edits below are surgical,
preceded by a clean-tree check each (shared-checkout law).

## Facts re-verified in the LANDED code

| # | Fact |
|---|---|
| A1 | Volume endpoint still reads keywords only — no siteId, no country (optimizer/controller.php:56-74) |
| A2 | Volume cache still keyword-only keys (optimizer/service.php:311-351) |
| A3 | THE RIDE still primary + bucket only — supporting NEVER joins (SectionModal.tsx:1069-1074) |
| A4 | The drawer already holds everything the backbone needs: live content, all three keyword stores, siteId (KeywordsDrawer props) |
| A5 | `invoke_json` exists for the quick language check (class-pcm-llm.php); GBP address exists for step 1 (gbp.php:148) |

## THE SENIOR EXECUTION PLAN (four pairs, clean-code laws binding)

**P1 — COUNTRY (server).**
- `PCM_Keywords_Service::resolve_country(object $site, string $content_sample = ''): array{country, source}`
  — chain: (1) brand GBP address matched against ISO country-name data →
  (2) content sample present → ONE fast `invoke_json` language→country →
  (3) hub option `pcm_kw_default_country` (seeded `'se'`, editable).
  Result CACHED per site (option map siteId→{country, source, resolvedAt})
  — resolved once, source-labeled, never re-guessed per request.
- `keyword_volumes()` gains required `$country`; cache key becomes
  `"{country}|{kw}"`; **old un-prefixed keys are DELETED on first write**
  (they are US-market numbers — wrong data, not kept; honest deletion).
- `ahrefs_enrich` country default REMOVED — every caller passes explicitly
  (the only default lives in the option; no silent 'us' anywhere).
- Volumes + keyword-search endpoints read `siteId` (+ optional
  `contentSample`), resolve server-side.
- Drawer sends `siteId` + a content sample in both payloads.
- DELETED in the same pair: the hardcoded `'us'` fallbacks (C1, C2).

**P2 — THE BACKBONE (drawer).**
- Selection state (`Set<kw>`) + DataTable checkbox selection on the
  SELECTED table + ticked-count in the drawer header.
- ONE upward prop: `onKeywordSelection(kws: {kw, role}[])` — the backbone
  contract every action button consumes (analyze context, optimize,
  insert, generate — same source).
- Clears on drawer close.

**P3 — THE ACTION MATRIX (SectionModal, surgical).**
- THE RIDE grows to ALL keywords: primary + supporting + additional —
  the old two-store line REPLACED, not kept beside (A3 dies).
- Ticked keywords → the split button transforms: **Insert keywords (N)**
  → topic = hierarchy law verbatim with exactly those keywords by role →
  `startAiReview` with ride suppressed (one keyword order per prompt).
- Text selection interplay = the existing scope law (matrix rows 2/4
  come free).
- Analyze context keywords: unchanged path already reads live state —
  the backbone selection ADDITIONALLY narrows what optimize consumes,
  never what analyze sees (analyze always sees all — research is whole-
  page by definition).

**P4 — owner browser pass** (the backbone round: tick keywords → insert
→ red/green; country: volumes re-fetch shows the Swedish market).

Ritual per pair: clean-tree check → build → php -l → harness 88/88 +
research suite → tsc 59/0 new → build "built in" → changelog → pathspec
AFTER commit LOCAL ONLY. No dead code: every superseded line dies in the
pair that replaces it; no compat shims; comments describe the NEW law
only.
