# GAP ANALYSIS — BUSINESS SPINE P1: UNITS + MIGRATION + RESOLVER FACADE — 2026-07-17

Architecture: docs/ARCHITECTURE-BUSINESS-SPINE-20260717.md (§2, §4, P1).
Owner GO ("just get started — the malleable backbone"). Direction law:
sites/SEO consume brands; brands never know sites.

## FACTS (verified)

- Brand table: full record incl. name/website/domain/location/phone/language
  (class-pcm-schema.php:301-328). Sites: brandId FK exists (:479), no unit
  link. PCM_DB_VERSION = 1.42.0 (power-creatives.php:31); activator pattern
  = version_compare gates + idempotent migrate_* (class-pcm-activator.php).
- GBP storage today: option `pcm_seo_gbp_{brandId}` = {gbp, overrides};
  resolved = overrides win (seo/gbp.php:170-184). Consumers of
  get_for_brand (ALL preserved via facade): keywords/service.php:208
  (other lane — untouched), optimizer/service.php:662, seo/controller:1483
  (gbp routes), seo/service.php:2458 + 6576 (both var builders).
- **LIVE DB FACT: zero pcm_seo_gbp_* options exist** — the migration
  no-ops on this install (brand 13 "Profit Media" has NO business record;
  generations currently run on brand-name/site fallback). The migration is
  still written for correctness on any install that has records.

## GAP → BUILD (malleable backbone, additive)

1. **Schema**: new table `wp_pcm_brand_business_units`:
   id · brandId (idx) · label · isPrimary tinyint · **fetched** LONGTEXT
   (auto-sourced field JSON) · **manual** LONGTEXT (human corrections JSON)
   · **sources** LONGTEXT (per-key origin of fetched values: gbp/scrape/
   maps-paste/platform) · createdAt/updatedAt. Two-layer storage = the
   proven refresh-survival semantics per unit; `sources` = provenance for
   the future card. (Named deviation from the architecture sketch's single
   fields+fieldSources pair — two layers preserve the existing
   gbp/overrides contract EXACTLY; doc is malleable by owner directive.)
   Plus `sites.businessUnitId int(11) DEFAULT NULL` (additive, dbDelta;
   null = brand's primary). PCM_DB_VERSION → 1.43.0.
2. **Migration** `migrate_gbp_to_business_units()` (idempotent, v1.43.0
   gate): every `pcm_seo_gbp_%` option → that brand's PRIMARY unit
   (fetched = gbp, manual = overrides, sources = all-'gbp'); skips brands
   that already have units; deletes the option after copy (named deletion,
   lands with its replacement).
3. **Brands service — the unit API (static, additive)**:
   `list_business_units($brand_id)` · `save_business_unit($brand_id,
   $data)` (upsert; setting primary clears siblings) ·
   `delete_business_unit` · `get_business_record($brand_id, $unit_id = 0)`
   → {unitId, label, fetched, manual, sources, resolved} (unit 0 =
   primary; no units = empty record — honest).
4. **PCM_SEO_GBP becomes a facade** (same file, same class, same
   signatures): get_for_brand/save_snapshot/save_overrides read/write the
   PRIMARY unit through the brands API, returning the EXACT old shapes —
   all five consumers (incl. the other lane's) untouched. Provider/
   normalize/search stay as-is.
5. NOT in P1: site-override layer, auto-map, the SEO card, schema builder
   (P2-P5 per architecture); no frontend, no tunables needed.

## VERIFY
php -l each touched file · harnesses 88/88 + 30/30 + 34/34 · live: page
load runs maybe_upgrade → DB probe: table EXISTS, sites.businessUnitId
EXISTS, pcm_db_version = 1.43.0 (no unit rows expected — zero source
options, honest no-op) · facade round-trip probe in web context:
save_overrides(13, …) → get_for_brand(13) returns old shape with the
manual layer resolved on top → row visible in the units table → cleanup
probe row removed · changelog · AFTER commit LOCAL ONLY.
