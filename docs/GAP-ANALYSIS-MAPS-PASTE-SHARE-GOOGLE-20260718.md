# GAP ANALYSIS — MAPS PASTE: THE share.google WALL — 2026-07-18

Owner live report: pasted https://share.google/3FDmpuANSqIFRtyvl → "Nothing
recognizable". Three live probes run through the hub's real code with the
owner's real Apify key (probe files deleted after use).

## FACTS (all live-proven 2026-07-18)

1. **The Apify chain WORKS.** details() with a real place id
   (ChIJX1ZCHXHzT0YRcIG6W_VHXqM) returned HTTP 201 with title
   "Estetikcenter Göteborg", placeId, **kgmid /g/11hdpmd271**, cid, fid,
   city, reviews — via BOTH input forms (our `placeIds` implementation and
   the `place_id:` search form). Nothing wrong with the integration.
2. **share.google is a wall.** Server-side resolution is impossible:
   redirect walk → 302 → 301 → share.google/error; full-follow with a
   Chrome UA → the same 1.7KB error page (no ChIJ/maps-URL/ftid in body).
   It is a JavaScript-only app-share domain. Apify's actor ALSO fails on
   it as a startUrl (run FAILED, HTTP 400). No machine path exists.
3. **MY FLOW-ORDER BUG (the actual toast the owner saw):** business_maps
   calls parse_maps_url FIRST and returns its `pcm_seo_maps_unparsed`
   error FATALLY — the resolver + Apify never even ran. Short links
   (maps.app.goo.gl too) legitimately contain nothing locally; killing
   the request there was wrong.
4. The provider run() accepts 200/201 (Apify returns 201) — verified.

## FIX (checklist)

1. [ ] business_maps: `pcm_seo_maps_unparsed` becomes NON-fatal — store
       {mapsShareUrl} and continue to the resolver; only
       `pcm_seo_maps_not_maps` (foreign host) stays fatal. share.google
       host counts as a maps host (contains 'google').
2. [ ] resolve_share_url: when the redirect walk lands on share.google/
       error (or stays on share.google), return the SPECIFIC named error:
       "share.google app links can't be read by servers — in Google Maps
       use Share → Copy link, or paste the address-bar URL." (An error
       message carrying its fix — the named-errors law, not UI chrome.)
       When resolution SUCCEEDS, also run parse_maps_url on the RESOLVED
       long URL and merge those local fields (cid/geo ride along).
3. [ ] NEW consume route POST /seo/sites/{id}/business/place {placeId} →
       provider details → mergeFetched 'gbp' — the share-link-independent
       fill (search → pick → fill).
4. [ ] Card, Local SEO room: a compact "Find on Google" search (existing
       seo.gbpSearch) listing candidates (name + address) → pick = the
       new business/place fill. The paste row stays for URLs.
5. [ ] Verify: lint · 88/65/34 · tsc 59 · build · byte-serve · LIVE
       re-probe: (a) share link through the fixed flow = mapsShareUrl
       stored + the NAMED share.google guidance in reply.google.error;
       (b) business/place fill proven against a THROWAWAY brand+unit
       (created, verified incl. kgid, DELETED — brand 13 never touched).
6. [ ] Changelog + seo 1.0.8 + AFTER commit LOCAL ONLY.
