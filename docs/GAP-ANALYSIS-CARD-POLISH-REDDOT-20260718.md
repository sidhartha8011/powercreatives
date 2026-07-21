# GAP ANALYSIS — CARD POLISH (SENIOR PASS) + THE RANDOM RED DOT — 2026-07-18

Owner orders (confirmed list + two corrections): no debt, no dead code,
production-grade only.

## FACTS

1. **THE RANDOM RED DOT — ROOT CAUSE FOUND (in code, this pair fixes it):**
   SiteStatusDot is RED only when stored health says failed (dot has no
   green; healthy-active = blue — matches the owner's "eventually blue").
   record_heartbeat (sites/service.php:194-210) OVERWRITES the verdict on
   EVERY remote call — ONE transport error flips the dot red instantly.
   This machine's proven intermittent Avast SSL-timeouts + slow-site cURL
   28s make single-failure flips routine → random red, healed by the next
   successful call. FIX: consecutive-failure threshold — the map entry
   gains a `fails` counter (additive); ok=true resets it and heals
   instantly; ok=false increments and flips the stored verdict ONLY at
   `failThreshold` (hub data: pcm_sites_health_check += failThreshold: 2,
   merge law). One blip can never paint a healthy site red again.
2. **Shadows root cause:** my hand-rolled `shadow-sm` field classes in
   RemoteBusinessCard — a shared-components-law violation (the shared
   Input idiom exists and is used in the SAME file). Dies here; grep
   confirms no other user.
3. **"Apify" in the working line is hardcoded** — the owner's correction:
   the ACTUAL configured provider name must show, dynamically:
   seo_gbp_provider setting → PCM_Providers registry display name →
   business_card reply `providerName` → the working text. Never generic,
   never hardcoded.
4. **CreatableCombobox exists** (components/ui) — the searchable dropdown
   for the brand mapping, pre-selected with the mapped brand.
5. Brand info flowing from Brands: VERIFIED (ladder brand layer, live).

## PLAN

**C1 — fields to the owner's spec:** both rooms = light-gray boxes (same
tone; the amber tint retires); every value = WHITE field, HAIRLINE
light-gray border, rounded, NO shadow — one field style from the shared
idiom; text sizes unchanged.
**C2 — real room headers:** clear title row ("Business" / "Local SEO") +
hairline divider; the room's WORKING STATE lives in this header row
(small spinner + "Fetching — up to a minute… via {providerName}") in
RESERVED space — zero layout shift, status where the change lands (Local
room for find/pick/paste; both rooms for refresh). The global banner is
DELETED (dead code law: replacement lands same commit).
**C3 — dynamic provider name:** business_card reply += providerName
(registry lookup, server-side); frontend uses it verbatim.
**C4 — brand mapping = searchable dropdown:** CreatableCombobox listing
brands, PRE-SELECTED with the mapped brand; choosing remaps via
sites.update (the connection stays in sites); unlink stays as a small
adjacent control; the unit Select stays for multi-unit brands.
**C5 — the heartbeat threshold (F1 above):** record_heartbeat counter +
tunable; verified live by probe (fail once → still healthy w/ fails=1;
fail again → red; success → healed + reset).

## CHECKLIST
1. [ ] sites/service record_heartbeat threshold + pcm_sites_health_check
       merge key.
2. [ ] business_card reply providerName (dynamic registry lookup).
3. [ ] Card: gray rooms, hairline no-shadow fields, header rows w/
       in-header working state, banner deleted, brand CreatableCombobox
       pre-selected + unlink, unit Select kept.
4. [ ] php -l · 88/67/34 · tsc 59 · build · byte-serve.
5. [ ] LIVE probe: heartbeat threshold behavior (fail/fail/success cycle
       on a throwaway site id in the map, cleaned) + card GET carries
       providerName "Apify" from the registry.
6. [ ] Changelog + seo 1.0.10 + AFTER commit LOCAL ONLY.
