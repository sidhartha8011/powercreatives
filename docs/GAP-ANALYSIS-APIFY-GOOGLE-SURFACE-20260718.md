# GAP ANALYSIS — APIFY: THE GOOGLE SURFACE, ONE KEY — 2026-07-18

Owner GO: Apify replaces the need for more Google Cloud projects. ONE actor
(`compass/crawler-google-places`, verified: 514k users, 4.73★) returns the
place record + ALL FOUR schema IDs (placeId, cid, fid, **kgmid**) + reviews
(≤5,000/place) in one run. Key input = the regular Integrations flow; the
SEO module consumes through the EXISTING provider abstraction. NOTHING
hardcoded: actor ids, caps, timeouts, language = hub data.

## FACTS

1. Provider abstraction live (gbp.php): PCM_SEO_GBP_Provider interface
   (search/details), providers() map + hub setting `seo_gbp_provider`
   selects, per-user key resolution (Phase A, 8d0ef19). The native
   `google` Places provider stays registered — data-selectable alternative,
   not deleted (it works when its key exists; the abstraction's purpose).
2. Integrations pattern: PCM_Providers registry drives the UI picker;
   apiKey per user in wp_pcm_integrations (isActive=1); resolution =
   one prepared query (Phase A precedent).
3. Apify API: `POST https://api.apify.com/v2/acts/{actorId}/
   run-sync-get-dataset-items?token={key}` — body = actor input JSON,
   reply = dataset items array. Actor input (documented): searchStringsArray
   OR placeIds/startUrls · maxCrawledPlacesPerSearch · maxReviews ·
   language · reviewsSort. Output per place: title, address parts
   (street/city/postalCode/state/countryCode), phone, location{lat,lng},
   website, totalScore, reviewsCount, categoryName, openingHours,
   url (maps), **placeId, cid, fid, kgmid**, reviews[{name, stars, text,
   publishedAtDate, …}].
4. The card + unit already carry cid/kgid keys (registry rows exist;
   kgid was manual-only until now); mergeFetched tags per key; the
   share-URL resolver + Maps-paste flow call provider->details — a
   provider swap upgrades the whole chain untouched.
5. Sync-run duration: tens of seconds per run — a held PHP worker on the
   2-worker machine; the timeout MUST be tunable data, defaults modest.

## THE PLAN (all data-driven, zero hardcode)

- **P1 — Integrations entry `apify`** in PCM_Providers (key provider,
  supportsSeo; console URL apify.com → Settings → API tokens).
- **P2 — Tunables** seeded read-through option `pcm_seo_apify`
  (array_replace_recursive merge law): `actorId`
  ('compass~crawler-google-places'), `reviewsActorId`
  ('compass~google-maps-reviews-scraper' — the future cheap top-up tap,
  stored NOW so it's data when wanted), `timeoutS` (90), `searchLimit`
  (5), `maxReviews` (20), `storedReviewsCap` (20), `language` ('' = the
  existing default_lang()).
- **P3 — PCM_SEO_GBP_Apify_Provider** (same interface): search(query) =
  one bounded run (searchStringsArray, searchLimit, NO reviews);
  details(place_id) = one run (placeIds, maxReviews) → first item RAW.
  Key via provider 'apify'; named errors (no key / HTTP / empty) with the
  fix in the message.
- **P4 — normalize_apify()** pure fn → THE SAME normalized record shape
  (name/address/postal/city/region/country/phone/geo/website/category/
  hours/rating/reviews-count/description) PLUS `kgid` (from kgmid),
  `fid`, `cid`, mapsShareUrl (url), mapsEmbedUrl (built from cid),
  publicReviews (capped storedReviewsCap). Defensive ?? mapping;
  HARNESS-PINNED (new suite section).
- **P5 — selection**: hub setting `seo_gbp_provider` default becomes
  'apify' (read-through default — installs that explicitly chose
  'google' keep it; the setting IS the switch, no code edits to change
  provider ever).
- **P6 — card**: registry gains the `fid` row (Local SEO room) — one
  line, per the registry law. Everything else (paste flow, refresh,
  search panel) upgrades by the provider swap alone.
- **P7 — verify**: php -l · harness (normalize_apify section) 58→64+ ·
  88 · 34 · tsc 59 · build · byte-serve · changelog · seo 1.0.7 · AFTER
  commit. Live Apify run = the owner's key entry (none on this install —
  honest); the request/parse path is harness-pinned.

## EXPLICITLY NOT

No schema builder yet (P5 of the Business Spine — the IDs now flow so it
unblocks); no weekly review cron (ICE/reviews-plugin later, reference doc
stands); no deletion of the google provider (data-selectable, documented).
