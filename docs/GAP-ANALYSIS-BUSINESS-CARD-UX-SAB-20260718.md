# GAP ANALYSIS — BUSINESS CARD: NATIONWIDE LISTINGS + THE UX PASS — 2026-07-18

Owner live run (real fill on brand 13 — WORKING: kgid/cid/fid/phone/
rating/16 reviews stored) surfaced four items. DB facts read from the unit.

## FACTS

1. **Nationwide (service-area) listings carry NO location.** Brand 13's
   fetched layer has zero address keys (no street/city/postal/region/
   country) — Google publishes none for SAB listings; our empty-never-lands
   law already refuses to invent a city (the owner's exact worry). BUT
   lat/lng landed as 62.0329767/17.3787426 — **Google's Sweden country
   centroid**, a placeholder point, not a place. Stored as if real geo =
   future schema poison.
2. The Address the owner sees ("Sweden (nationwide)") comes from the BRAND
   row via the ladder — correct layering, proven.
3. Fetch duration: an Apify run takes 20-60s+; the card shows only a tiny
   button spinner — no visible working state.
4. Layout: the two rooms stack vertically (owner: should sit side by
   side); the inline inputs are border-transparent until hover —
   effectively INVISIBLE fields (owner: unprofessional, hard to use).
   Text sizes are right and stay (owner ruling).

## PLAN

**S1 — the centroid guard (data honesty for nationwide):**
normalize_apify: when NO address component exists (no street/city/postal),
DROP lat/lng — a country-centroid is not a place; geo lands only with a
real address. Harness-pinned. Service areas + Primary location remain the
owner-declared SEO truth for SAB clients (already editable fields; the
Göteborg-override law covers per-site needs).

**S2 — the working state:** one slim status line at the card top while any
fetch runs (find/place/maps/refresh): "Working — fetching from Google via
Apify; this can take up to a minute…" (a state statement — sanctioned);
action buttons stay disabled as today.

**S3 — the two rooms side by side:** container max-w-5xl; `md:grid-cols-2`
grid, one room per column (stack on narrow screens). The Local room keeps
its whisper tint.

**S4 — visible, professional fields:** every value cell becomes a REAL
field: white background, border-slate-200, rounded, focus ring — clear
affordance on both room backgrounds; label column fixed; source caption
unchanged; TEXT SIZES UNCHANGED. Shared Input styling idiom (the app's
input look), no new components needed — the registry rows already render
from one place.

## CHECKLIST
1. [ ] normalize_apify centroid guard + 2 harness tests (SAB drops geo;
       real address keeps it).
2. [ ] Card: status line (S2), grid layout (S3), visible fields (S4).
3. [ ] lint · 88/67/34 · tsc 59 · build · byte-serve.
4. [ ] Changelog + seo 1.0.9 + AFTER commit LOCAL ONLY. Owner check:
       reload → rooms side by side, fields visible, working line during
       fetch; nationwide fills never invent a city or a fake point.
