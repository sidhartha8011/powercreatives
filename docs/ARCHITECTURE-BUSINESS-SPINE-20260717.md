# ARCHITECTURE — THE BUSINESS SPINE — 2026-07-17

Owner-confirmed direction (session 2026-07-17). Goal: every generation and
every schema we place carries the right business facts automatically — map
once, lock to the site, never re-map. Lean: NO Google Cloud project, NO n8n
dependency. The SEO team works ONLY inside the SEO module.

## 1. OWNERSHIP LAW

Everything that describes a brand lives in the **Brands module** — including
business locations. The SEO module CONSUMES the record and owns exactly one
layer of its own: per-site SEO overrides. (Modular boundary law.)

## 2. BUSINESS UNITS (multi-location, one primary)

- A brand has 0..n **Business Units**; exactly one marked PRIMARY.
- New table `wp_pcm_brand_business_units`: id · brandId · label ·
  isPrimary · fields (JSON: the full record below) · fieldSources (JSON:
  per-field provenance) · createdAt/updatedAt.
- MIGRATION: existing `pcm_seo_gbp_{brandId}` options → each becomes that
  brand's primary unit (idempotent migrate_* + PCM_DB_VERSION bump; old
  options deleted in the same migration, named).
- A brand with no unit stays legal forever.

## 3. THE UNIT RECORD — the full agency cheat sheet, one card

NAP+ : businessName · address · postal · city · region · country ·
phone · email · hours · lat/lng · serviceAreas[] · primaryLocation ·
language.
Google-surface: mapsShareUrl · mapsEmbedUrl (BUILT from the share URL) ·
CID (PARSED from the share URL) · categories[] · shortName · kgId.
Content: description · services[] · socialProfiles[] · logoUrl ·
reviews[] (text/author/rating/date — content ingredient ONLY) ·
competitors[] (auto-suggested from the OPTIMIZER's SERP/mention output).
Agency: owner · startDate · employees · videoUrls[] · assetsFolderUrl.

**Field sources (per-field provenance, always labeled):**
- `scrape` — visible-HTML scrape of the site (footer/contact NAP, socials,
  email, logo, description) via the EXISTING brands scraper, extended.
  ⚠ The site's own JSON-LD is NEVER a source — WE author the schema; a
  deficient site's markup is the thing we fix, not a truth to read back.
- `maps-paste` — ONE human paste of the Google Maps share URL → CID +
  lat/lng + embed URL parsed locally. Zero API.
- `platform` — competitors from the optimizer's existing research output.
- `manual` — typed in the card (kgId, categories, serviceAreas, agency
  fields, reviews if wanted).
- FUTURE DOOR (not built): Google Places as a proper Integrations provider
  can later auto-fill reviews/categories/kgId. The record shape already
  fits it — adding it is a new source label, not a redesign.

## 4. THE RESOLUTION LADDER — one resolver, every consumer

```
site SEO override → unit field → brand basics (name/phone/location/
language from wp_pcm_brands) → site name/url (no brand = still works)
```
- Implemented ONCE; the seven existing generation call sites
  (build_field_vars ×4, remote_field_vars ×3) + optimizer
  business_context() all switch to it — consumers untouched.
- Site SEO overrides: sparse per-site fields (SEO module's layer) — the
  "Avenyn → Göteborg" case: brand keeps the true address, THIS site
  generates and schemas with the SEO-correct location.
- Empty fields render NOTHING (no hallucination bait — standing law).

## 5. TWO OUTPUTS OF ONE RECORD

1. **Generation context** — {{business.*}} vars + the optimizer context
   package (existing paths, upgraded ladder).
2. **THE SCHEMA WE PLACE** — LocalBusiness/Organization JSON-LD built from
   the resolved record and pushed via the connector's EXISTING jsonld
   option. NAP in schema must equal NAP in content — one record guarantees
   it. Policy law: NO self-serving review/aggregateRating markup ever
   (Google policy); reviews are prose material only.

## 6. AUTO-MAPPING (fewest manual steps)

- Site added/connected → domain matched against brand websites
  (find_by_website EXISTS) → exact host match = auto-link, labeled
  "auto-matched by domain". One-time backfill for existing sites.
- No brand → one click "Create from this site": the existing
  scrape_and_prepare(url) builds the brand (+ its primary unit skeleton)
  and links it.
- Site → brand link (exists: sites.brandId) + NEW sites.businessUnitId
  (nullable → brand's primary).

## 7. THE SEO SURFACE (the only place the SEO team ever works)

SEO module → site selected → **Business tab = THIS site's business card**:
- Mapped: the resolved card, every field inline-editable → edits write the
  SITE layer, quiet "this site only" source label. Per-field source
  visible on hover (scrape / maps-paste / manual / brand).
- Unmapped: honest one-liner + [auto-match suggestion] [Pick brand]
  [Create from this site].
- Card actions: "Refresh from site" (re-scrape, overrides survive) ·
  Maps-URL paste field · "Edit brand record" writes UNIT fields (the
  brand-wide truth) — distinct from the site layer, both reachable here.
- The current brand-picker BusinessPanel is SUPERSEDED: unit editing moves
  to Brands module UI (units list + primary toggle); the SEO tab becomes
  per-site. Old panel deleted in the same commit its replacement lands.

## 8. USER STORY (Sara, SEO team, never leaves SEO)

Connect site → auto-linked by domain (or one click create-from-site) →
Business tab: card filled by scrape → paste the Maps URL once (CID/geo/
embed land) → type the two manual fields she cares about → fix location
"Avenyn" → "Göteborg" (site-only override) → done. Every generation and
the schema use it automatically, forever. A change next year = edit the
same card.

## 9. BUILD ORDER (each = own gap → owner GO → pair)

P1 units table + migration + THE RESOLVER swap (consumers untouched).
P2 auto-map on add + backfill + create-from-site + businessUnitId.
P3 the SEO per-site Business card (map/create/paste/edit, supersede old
   panel same commit).
P4 Brands module units UI (list, primary, unit editing).
P5 schema builder: resolved record → JSON-LD → connector jsonld push.
P6 (later door) Google Places as an Integrations provider (reviews/kgId
   auto-fill) — owner decision when wanted.

## 10. BINDING LAWS
Gap before every pair · tunables = hub data · sources labeled, empty stays
empty, no silent fallbacks · overrides never clobbered by refresh · WE
author site schema, never read it as truth · no new external dependencies
without owner GO · Keywords-lane untouched.
