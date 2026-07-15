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
