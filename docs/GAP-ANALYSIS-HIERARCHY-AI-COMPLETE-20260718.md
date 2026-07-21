# GAP ANALYSIS — HIERARCHY-TRUE MAPPING + AI-COMPLETE + CARD FINISH — 2026-07-18

Owner GO (iterate, stop planning). One bundled pair.

## FACTS — WHERE WE ARE

1. The card maps site→brand DIRECTLY (sites.brandId) — bypasses the
   platform hierarchy. The REAL chain exists in schema and law:
   projects.siteId + projects.deliveryId (schema:120-136) →
   deliveries.brandId (:523-540); PCM_Hierarchy resolves delivery→project→
   site already (CLAUDE.md); deliveries.seoSiteId is DEPRECATED (never
   reintroduce); a delivery belongs to ONE brand (owner law).
2. My room gray (bg-slate-50) is NOT the platform gray — the shell standard
   is #f8f9fa (Shell.tsx:77). Owner: use the platform's gray.
3. Address mapping bug: normalize_apify sets address = the FULL formatted
   string while postal/city have their own fields — Apify delivers a clean
   `street` we ignore for address.
4. Field order (owner spec): niche directly under name, description under
   niche, language up; city stays.
5. No AI completion exists; PCM_LLM::invoke_json + per-user provider
   routing exist (revise-scope precedent).
6. Missing-fields fact base: service areas/primary location/email/socials
   are NOT in Google's public data (live-proven) — the client's WEBSITE is
   the source; the owner chose AI extraction with a user-picked fill mode.

## WHERE WE WANT TO BE — THE PLAN

**P1 — the chain anchor:** sites gains `projectId` (the site's chosen
chain; the connection lives in SITES — standing ruling). DB 1.45.0 +
idempotent migration: every site with legacy brandId gets a REAL chain —
reuse the newest matching project/delivery under that brand or create
them (delivery name = site name, clientName = brand name; project name =
site name, siteId + deliveryId) — then sites.projectId is set.
sites.brandId becomes LEGACY: no writer remains; resolver reads it ONLY
when projectId is null (labeled fallback). businessUnitId pin unchanged.

**P2 — the resolver walks the chain:** business_record_for_site: brand :=
site.projectId → project.deliveryId → delivery.brandId (fallback legacy).
Reply gains the chain (project/delivery/brand ids+names) + the brand's
deliveries[] + the delivery's projects[] for the dropdowns.

**P3 — the mapping route (sites module):** POST /sites/{id}/business-map
{brandId | deliveryId | projectId | unlink} — each level edits ITS OWN
upward link per the owner's locking logic: brandId → find-or-create the
chain under that brand (newest delivery reused, created if none);
deliveryId → find-or-create this site's project under it; projectId →
sites.projectId directly; unlink → projectId (and legacy brandId) null.
Top-down changes legitimately move what hangs below — shown, never silent.

**P4 — the three dropdowns:** Brand FIRST (searchable combobox,
pre-selected, create guarded — the owner's primary flow), then Delivery
(the brand's deliveries + the mapped one selected), then Project (the
delivery's projects, mapped one selected). Derived values display locked-
but-changeable: changing any level calls P3 with that level.

**P5 — Complete with AI:** POST /seo/sites/{id}/business/ai-complete
{mode: fill|overwrite}: the platform fetches the client's pages itself
(indexedurl > website > site url + tunable paths — merge-seeded option
pcm_seo_ai_complete {paths:[/kontakt,/contact,/om-oss,/about],
maxPages:3, maxCharsPerPage:20000, timeoutS:20}) → text → ONE
PCM_LLM::invoke_json call (per-user provider routing; contract in prompt:
extract ONLY what the pages state — email, social profiles, niche,
service areas, primary location, region, phone — evidence per field,
empty when absent, NEVER invent) → merge tag 'ai': fill = only keys empty
in the resolved record; overwrite = all returned keys; the manual layer
survives both (law). Card: "Complete with AI" + the two-mode choice.

**P6 — the brand-update OFFER (never auto):** "Update brand record"
button → POST /seo/sites/{id}/business/brand-sync — copies resolved
{phone, description→businessSummary, language, address→location} onto the
brand row via the existing update path. Owner-clicked only.

**P7 — card finish:** rooms = the PLATFORM gray (#f8f9fa); field order
name→niche→description→language→website→indexedurl→email→phone→address
block→hours→socials; address = STREET line (normalize fix + harness).

## CHECKLIST
1. [ ] Schema sites.projectId + DB 1.45.0 migration (idempotent).
2. [ ] Resolver chain walk + labeled legacy fallback + reply chain data.
3. [ ] sites business-map route (4 modes).
4. [ ] seo ai-complete route + tunables + contract; brand-sync route.
5. [ ] Card: 3 dropdowns, AI button + mode, platform gray, field order.
6. [ ] normalize address←street + harness test.
7. [ ] lint · suites · tsc 59 · build · byte · LIVE probes: migration
       (site 2 gets a real chain from legacy brandId 13), resolver via
       chain, business-map e2e, ai-complete on the real site (one bounded
       run — key exists).
8. [ ] Changelog + seo 1.0.11 + AFTER commit LOCAL ONLY.
