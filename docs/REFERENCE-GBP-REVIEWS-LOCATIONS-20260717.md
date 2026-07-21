# REFERENCE — GBP REVIEWS + LOCATIONS (native replication) — 2026-07-17

Decoded from the owner's n8n workflow before its deletion. NO keys, NO
account ids in this file — credentials live in Integrations, the GBP
account id becomes an Integrations SETTING when Phase B builds. Future
consumer: a reviews plugin / review-fetch feature (owner idea, logged).

## The endpoints (all Google OAuth — the same connection GSC uses)

1. **Accounts** — `GET https://mybusinessaccountmanagement.googleapis.com/v1/accounts`
   → the user's accounts AND location groups. MUST be enumerated — listings
   are spread across location groups; querying one account misses the rest
   (owner-observed missing locations, cause #3).
2. **Locations per account** —
   `GET https://mybusinessbusinessinformation.googleapis.com/v1/{account}/locations`
   `?readMask=name,title,metadata,storefrontAddress&pageSize=100`
   ⚠ PAGINATED: read `nextPageToken` and LOOP — the n8n flow did not
   (cause #1 of the missing locations). ⚠ Unverified/pending/duplicate
   locations may carry NO `metadata.placeId` — a placeId equality filter
   silently drops them (cause #2); match on title+address as fallback and
   surface unmatched rows honestly.
3. **Place↔location resolve** — filter the enumerated locations where
   `metadata.placeId == {ChIJ place id}` (the Places-side id from the
   share-URL resolver / searchText).
4. **Reviews (the full list — beats the public top-5)** —
   `GET https://mybusiness.googleapis.com/v4/{account}/{location}/reviews?pageSize=N`
   (v4 API — still the only reviews list endpoint). Paginated the same way.
   Owner ruling: reviews are CONTENT material only, never schema markup
   (Google policy on self-serving review markup).

## The Places side (API key — Integrations provider `google_places`)

- Search: `POST https://places.googleapis.com/v1/places:searchText`
  headers `X-Goog-Api-Key`, `X-Goog-FieldMask`; body `{"textQuery": "..."}`.
- Details: `GET https://places.googleapis.com/v1/places/{place_id}`
  headers as above + `languageCode` (hub data, e.g. 'sv') +
  `reviews_no_translations: true`.
- THE WIDE MASK (the agency cheat sheet, public-data half):
  `id,displayName,formattedAddress,shortFormattedAddress,addressComponents,
  nationalPhoneNumber,internationalPhoneNumber,location,websiteUri,types,
  primaryTypeDisplayName,rating,userRatingCount,regularOpeningHours,
  editorialSummary,googleMapsUri,reviews`
  (addressComponents → postal/city/region/country; reviews = public top 5
  with text/author/rating/time; googleMapsUri → CID extraction).

## Share-URL → place id (no API)

Follow the share link's redirects (maps.app.goo.gl → full URL), then
extract in order: `ChIJ[\w-]+` place id (often after `!19s`) → done;
else `!1s0x…:0x…` hex ftid → CID (exact 64-bit math — implemented:
PCM_SEO_Service::parse_maps_url/hex_to_dec); else the `/maps/place/{name}/`
segment → places:searchText by name. Coordinates from `@lat,lng` or
`!3d…!4d…`.

## Status

Phase A (Places key provider + share-URL fill) — building 2026-07-17.
Phase B (accounts walk + paginated locations + full reviews, account id as
an Integrations setting) — next gap, this file is its spec seed.
