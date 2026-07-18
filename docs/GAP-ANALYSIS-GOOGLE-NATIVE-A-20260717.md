# GAP ANALYSIS — GOOGLE NATIVE PHASE A + THE TWO-ROOM CARD — 2026-07-17

Owner orders (same session): (1) replicate the n8n workflow natively —
Places key in the REGULAR Integrations flow, share-URL → full business
details, n8n dies; (2) the two-room card (Business / Local SEO), the
Indexed URL as its own field, the refresh popover that asks before it
scrapes. Reference doc: docs/REFERENCE-GBP-REVIEWS-LOCATIONS-20260717.md
(incl. the three factual causes of the owner's missing-locations problem —
fixed in Phase B, not this pair). Blueprint §10 updated (Google-free
ruling superseded by the owner).

## FACTS

1. n8n workflow decoded: GSC half already native (dead weight); the NEW
   parts are Places searchText/details (API-key auth) + My Business
   locations/reviews (OAuth, Phase B).
2. Provider slug 'google' is TAKEN (Gemini, class-pcm-providers.php:40) →
   the Places entry is **google_places** (key-based, PCM_Providers
   registry drives the Integrations UI picker; get_provider_api_key()
   is the resolution pattern — proranktracker precedent).
3. PCM_SEO_GBP has the provider abstraction (providers() map + provider()
   from hub setting seo_gbp_provider, default 'n8n'; gbp.php:92-106);
   normalize() handles v1 shapes but DROPS addressComponents/reviews/
   googleMapsUri/intl phone (not in the map).
4. The Business card (RemoteBusinessCard, 1f54400): flat registry, refresh
   scrapes site->url blindly, maps-paste = local parse only.
5. mergeFetched door + per-key source tags exist (P1/P2+P3) — the Google
   fill lands through it, manual corrections survive by construction.

## BUILD

**G1 — Integrations entry:** PCM_Providers gains google_places (key
provider, no models; type 'data' like proranktracker's pattern).
**G2 — the native provider:** PCM_SEO_GBP_Google_Provider (same
interface): searchText + details with THE WIDE MASK (reference doc);
key via get_provider_api_key('google_places', user). Hub setting
seo_gbp_provider default flips 'n8n' → 'google'; **the n8n provider class
+ the webhook config UI in BusinessPanel are DELETED in this same pair**
(replacement lands together — owner order "n8n dies"). languageCode =
hub data (existing default_lang()).
**G3 — normalize() widened:** addressComponents → postal/city/region/
country · internationalPhone · googleMapsUri (+ CID extraction via the
existing parser) · shortFormattedAddress · reviews[] (author/rating/text/
time, capped) — additive keys, nothing renamed.
**G4 — share-URL fill:** resolve_share_url(): follow redirects → ChIJ id
→ details; fallback name-segment → searchText best-match. The card's
Maps-paste action upgrades: local cid/geo parse ALWAYS (as today, labeled
maps-paste) + when a google_places key exists → the resolved details
merge tagged 'gbp'. No key = today's behavior, honest.
**G5 — the two rooms:** FIELD_REGISTRY rows gain group: 'business' |
'local'; card renders two sections — Business (white) and Local SEO
(whisper-tint bg, Maps-paste row inside it); indexedurl field added
(Business room, under website).
**G6 — the refresh popover:** Refresh from site opens a small popover,
URL prefilled (indexedurl > website > site url); confirm = scrape THAT
url (business_refresh accepts optional url, esc_url_raw) + the confirmed
url saves as the site's indexedurl override.

## CHECKLIST

1. [ ] PCM_Providers += google_places.
2. [ ] Google provider class; seo_gbp_provider default 'google'; n8n
       class deleted; BusinessPanel webhook UI removed (same pair).
3. [ ] normalize() widened (G3) — additive.
4. [ ] resolve_share_url() + business_maps route upgrade (key-aware,
       honest fallback).
5. [ ] business_refresh accepts url; saves indexedurl override.
6. [ ] Card: rooms + indexedurl + popover; registry group key.
7. [ ] Harness: normalize widening (addressComponents/mapsUri/reviews
       mapping) — pure fn tests.
8. [ ] php -l · 88 · 52+ · 34 · tsc 59 · build · byte-serve.
9. [ ] Changelog + seo 1.0.6 + AFTER commit LOCAL ONLY. Live Google call
       = owner's first key entry (no key exists to test with — honest).
