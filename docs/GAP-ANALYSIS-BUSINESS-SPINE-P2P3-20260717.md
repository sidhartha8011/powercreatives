# GAP ANALYSIS — BUSINESS SPINE P2+P3 BUNDLED: THE BUSINESS CARD — 2026-07-17

Architecture: docs/ARCHITECTURE-BUSINESS-SPINE-20260717.md. Owner order:
bundle the business build into ONE malleable implementation; REFINED
DEPENDENCY RULING: **brands own brand truth · SITES OWN THE CONNECTION ·
SEO only consumes.** Nothing hardcoded; every piece changeable without
hassle.

## A. FACTS (verified)

1. P1 live (ed74737): units table (fetched/manual/sources), sites.brandId +
   sites.businessUnitId columns, brands static unit API, GBP facade.
2. brands module: POST /brands/scrape-url (controller:91) +
   scrape_and_prepare (LLM website scrape) + find_by_website (domain match,
   service:33) + full create route — create-from-site needs ZERO new brand
   code, only orchestration.
3. sites PATCH allowlist has brandId (controller:380-383) — businessUnitId
   missing (added; the connection stays in SITES per the ruling).
4. SEO surface today: Business tab for a CONNECTED site = placeholder
   (index.tsx:1421-1422); local site = brand-picker BusinessPanel (stays
   this pair; moves to Brands UI in P4 — named scope).
5. Generation consumers resolve brand-only (remote_field_vars :2458,
   optimizer business_context :662) — no unit pinning, no site layer.
6. Hub DB: brand 13 exists, no units, sites 1-2 have brandId=1?/13? —
   backfill will state facts at run time (idempotent either way).

## B. DESIGN — malleable by construction

**B1. THE FIELD REGISTRY (no hardcoded field lists):** one frontend
registry array {key, label, multiline?} drives the card's rows — adding a
field later = one registry row. The server accepts any sanitized scalar
key (kses/sanitize + count/length caps) — adding fields needs NO backend
change.

**B2. THE LADDER as a pure function:** merge_business_ladder(site_overrides,
unit_record, brand_basics, site_basics) → {fields, sources} — harness-
testable, one implementation. business_record_for_site(siteId) = data
reads (site row FK → brands API → SEO's own site-override option) + that
pure call. Site overrides: option pcm_seo_biz_site_{siteId} (autoload
off, sparse) — SEO's one owned layer.
Ladder: site override > unit resolved > brand basics (name/phone/location/
language/website/description from wp_pcm_brands) > site name/url. Sources
tag every field (site/manual/gbp/scrape/maps-paste/brand/site-basics).

**B3. Consumers switch to the site resolver** (remote_field_vars +
optimizer business_context delegate) — unit pinning + site overrides reach
EVERY generation; local-path build_field_vars (brand-picker semantics)
unchanged. Facade untouched.

**B4. Generic unit-merge (multi-source forever):** save_business_unit
gains data.mergeFetched (merges keys into fetched + per-key sourceTag) —
maps-paste today, Google provider tomorrow: same door.

**B5. Maps-URL paste, server-parsed pure fn** parse_maps_url(url):
CID (?cid= / !1s hex), lat/lng (@lat,lng / !3d!4d), embed URL built from
cid-or-query — harness-tested; garbage → named error.

**B6. Routes** (SEO consumes; connection writes go through SITES):
- GET  /seo/sites/{id}/business → {fields, sources, brand, unit,
  suggestion (exact-domain match when unmapped), units[] of the brand}
- POST /seo/sites/{id}/business/overrides {fields} → site layer
- POST /seo/sites/{id}/business/refresh → scrape site url →
  mergeFetched(tag 'scrape') into the mapped unit
- POST /seo/sites/{id}/business/maps {url} → parse → mergeFetched
  (tag 'maps-paste')
- SITES PATCH gains businessUnitId (the connection lives in sites).
- Create-from-site = frontend orchestration: brands scrape-url → brands
  create → sites PATCH brandId. Zero new brand endpoints.

**B7. Auto-map:** site CREATE path: exact-host find_by_website match →
brandId set (deterministic fact, never a guess — non-exact = no link) +
v1.44.0 idempotent backfill for existing unmapped sites. DB 1.44.0.

**B8. THE CARD (RemoteBusinessCard.tsx, replaces the placeholder for
connected sites):** header = brand name · unit label · change-mapping
popover (brand list + unit list; writes via SITES PATCH). Unmapped =
suggestion row ("Link {Brand} — domain match") + brand picker + Create
from this site. Body = registry-driven rows: value inline-editable (blur
saves the SITE layer), per-field source caption on hover. Actions:
Refresh from site · Maps-URL paste field. Shared components only; no
instructional chrome; empty fields render empty.

## C. CHECKLIST

1. [ ] merge_business_ladder + business_record_for_site +
       parse_maps_url (pure, static) in seo service; site-override option
       read/write w/ sanitize+caps.
2. [ ] save_business_unit += mergeFetched (per-key source tags).
3. [ ] remote_field_vars + optimizer business_context delegate to the
       site resolver (shapes preserved).
4. [ ] SEO controller: the four /business routes.
5. [ ] sites PATCH += businessUnitId; site-create auto-map (exact host);
       v1.44.0 backfill + DB bump.
6. [ ] trpc-routes entries; RemoteBusinessCard.tsx (registry-driven);
       index.tsx business tab switch (connected → card).
7. [ ] Harness: ladder precedence tests (site>manual>fetched>brand>site-
       basics, sources tagged, empty stays empty) + parse_maps_url tests.
8. [ ] php -l all · 88/88 · 40+/40+ · 34/34 · tsc 59 · build · byte-serve.
9. [ ] Live: migration probe (DB 1.44.0 + backfill state), card GET probe
       via web context (resolved payload for site 2), cleanup.
10. [ ] Changelog + seo 1.0.5 + AFTER commit LOCAL ONLY.
