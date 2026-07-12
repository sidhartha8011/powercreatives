# GAP ANALYSIS — ADD IMAGES (phase 1) + SLUG-CHANGE REDIRECTS (2026-07-12) — AWAITING GO

**Owner order:** both features, senior grade only — zero tech debt, no old
code left behind, no regressions, everything fully working. Every fact below
verified in code this day.

---

## UPDATE A — ADD IMAGES IN THE PAGE EDITOR

### Facts

| # | Fact | Evidence |
|---|------|----------|
| A1 | **The delivery channel already exists and is battle-tested**: `PCM_Sites_Service::remote_upload_media($site, $image_url)` fetches an image by URL and raw-binary POSTs it into the client's `/wp/v2/media` library (the JSON `remote_rest` can't carry files — this one exists precisely for that), returning `{id, url}`. Used in production by the featured-image flow (`remote_set_featured_image`). | sites/service.php remote_upload_media |
| A2 | **The hub-side picker already exists**: the table's featured-image cell opens the hub's native WP media library (`wp.media` frame, enqueued via `wp_enqueue_media`). Same pattern, zero new upload UI to invent. | index.tsx:922-935 |
| A3 | The engine already serves images inside rule replacements (raw units; expand mapping is fixture-pinned) — an added image needs NO engine change. The F9 landmine does not apply to platform-added images: they exist nowhere as between-content, so they can never duplicate. | harness "section expand new kinds"; F9 definition |
| A4 | The ONLY blocker is the editor's strip law: `save_page_edits` strips EVERY `<img>` from saves because all editor images today are locked context copies of originals. An added image must survive the save; AI-hallucinated or foreign images must NOT. | save_page_edits $strip_img_tags |
| A5 | The image-reconciliation arithmetic is unaffected: an added image's src has baseline count 0 and no hide rules — the per-src loop produces no hides/un-hides for it. | save_page_edits reconciliation |

### Gaps → changes

| # | Change |
|---|--------|
| AG1 | Hub endpoint `POST /seo/sites/{id}/media` `{url}` → `remote_upload_media` → `{id, url, alt}` (+ trpc `seo.remoteUploadMedia`). Thin controller over the EXISTING service — no duplication. |
| AG2 | Editor (page mode): **Add image** button → the hub's native `wp.media` picker (A2 pattern) → on select, calls AG1 (hub URL in, client-native URL out) → inserts the image at the cursor marked `data-pcm-added="<attachmentId>"`. Added images are draggable/deletable (they're content); locked originals stay locked. |
| AG3 | **The keep law (replaces the blanket strip):** an `<img>` survives a save only if it carries `data-pcm-added` AND its src host equals the client site's host (server-verified — a marker alone can be forged by pasted/AI content; the host check pins it to files the platform actually delivered). Everything else strips exactly as today. One discriminator, defense in depth, no second code path. |
| AG4 | Alt/title on added images: set in the insert markup itself (they live inside the rule's replacement — no separate image rule needed; the v2.3 panel keeps working for them afterwards since they're real content images once served). |

### Regression surface (named, each covered by a fixture or existing law)
Locked originals still strip (existing fixtures + new one) · AI review output
images still strip (host/marker law) · reconciliation ignores added srcs (A5)
· versions containing added images restore them (the keep law is stateless).

---

## UPDATE B — SLUG-CHANGE REDIRECT OFFER (+ internal-link rewrite)

### Facts

| # | Fact | Evidence |
|---|------|----------|
| B1 | **No redirect capability exists anywhere**: no module, no schema table, no connector handler answering old URLs (verified by sweep — all `redirect` hits are WP internals/OAuth). A changed slug leaves the old URL to WordPress's mercy. | grep sweep 2026-07-12 |
| B2 | **Honesty fact:** WordPress core ALREADY 301-redirects old slugs for published posts it knows about (`wp_old_slug_redirect` via `_wp_old_slug` meta). It is invisible, unmanageable, post-only, always-301, and not guaranteed across permalink setups — the feature's value is visibility, choice (302 etc.), editability, arbitrary targets, and management; answering "No" in our popup does not force a 404 for posts core still covers. Stated so the owner is never surprised. | WP core behavior |
| B3 | The popup's data is already on hand: the row carries the OLD permalink before the save; the refreshed row carries the NEW one. No extra fetches. | index.tsx row.permalink; saveCell flow |
| B4 | **Internal-link rewrite machinery exists**: the connector's builder-aware `/replace-url` rewrites a URL across content + builder data — but per-post only. Site-wide "who links to the old URL" needs one new connector search route. | seohub /replace-url (pid required) |
| B5 | Current `PCM_DB_VERSION` 1.38.0; migrations are additive dbDelta + idempotent per house law. | power-creatives.php:26 |

### Gaps → changes

| # | Change |
|---|--------|
| BG1 | **Hub store:** table `seo_redirects` (userId, siteId, fromPath, toUrl, code [301/302/307/308], active, createdAt) — DB 1.39.0, additive. Service: save (UPSERT on siteId+fromPath), list, delete — each pushes the site's full redirect set (push-fail rollback, the atomicity law verbatim). |
| BG2 | **Connector 3.0.5:** `POST/GET /pcm-conn/v1/redirects` (hub-managed store, sanitized: path-normalized from, absolute to, whitelisted codes) + an early `template_redirect` handler: exact path match (case-preserving, query-string passthrough) → `wp_redirect(to, code)` + exit. Capability = the route's existence (honest 404 pre-3.0.5). Kill switch rides the existing rules-off option. Fixture-pinned in the harness (handler logic extracted-testable like the rules passes). |
| BG3 | **Connector 3.0.5:** `GET /pcm-conn/v1/url-usage?url=` → post ids + counts containing the URL (content + builder meta, bounded LIKE query) — powers "also update N internal links". |
| BG4 | **The popup:** after a successful slug save where the slug actually changed (connected sites, published posts): modal prefilled From = old permalink · To = new permalink · type dropdown default 301 — all three editable — plus "Also update N internal links pointing at the old URL" checkbox (shown only when N > 0 from BG3; unchecked runs nothing). **Yes** = BG1 save (+ per-post `/replace-url` calls for the checkbox). **No** = nothing. Esc/outside = No. |
| BG5 | **Management (no black boxes):** the site settings panel lists the site's redirects (from → to, code, delete). Create-only features that can't be seen or undone are not senior grade. |

### Regression surface (named)
Slug saves where the slug did NOT change → no popup (exact compare) · local
tab → never (no engine) · redirect handler runs only on 404-bound requests it
matches — zero cost on every normal page view (early return on empty store) ·
`/replace-url` calls are the existing per-post writer path (storage writes — the
one mechanism that is ALLOWED to write, links are writer domain by the routing law).

---

## THE CHECKLIST (two pairs, full ritual each, owner GO per pair or both)

**Pair 1 `[add-images]`** — hub + editor, no connector change:
- [ ] BEFORE commit
- [ ] AG1 endpoint + trpc route (thin over `remote_upload_media`)
- [ ] AG2 Add-image button + `wp.media` picker + insert with `data-pcm-added`
- [ ] AG3 keep law in `save_page_edits` (marker + host check; blanket strip retired — no old code left)
- [ ] Verify: php -l · harness (engine untouched, stays green) · tsc 59 · build · LIVE: upload via hub → file lands in powerleads' media library → save → image serves inside its section on the full render → remove in editor + save → stops serving, file still in library
- [ ] Changelog + AFTER commit

**Pair 2 `[slug-redirects]`** — schema + connector 3.0.5 + popup:
- [ ] BEFORE commit
- [ ] BG1 table (DB 1.39.0) + service save/list/delete with push-rollback
- [ ] BG2 connector redirects store + handler + BG3 url-usage route (+ version 3.0.5)
- [ ] Harness: redirect handler fixtures (exact match + query passthrough, code whitelist, no-match passthrough, empty-store zero-cost) + url-usage fixture
- [ ] BG4 popup wired into the slug save path (old/new prefill, editable, N-links checkbox) + BG5 settings-panel list/delete + trpc routes
- [ ] Verify: php -l · harness ALL GREEN · tsc 59 · build · LIVE: change a slug on a disposable published post → popup → Yes → old URL 301s to new (curl -I proof) → link-rewrite updates a seeded internal link → delete the redirect in settings → old URL stops redirecting (or falls to WP core's own post fallback, B2 stated) → restore slug, remove test post
- [ ] Changelog + AFTER commit

Fleet: powerleads picks up 3.0.5 by the ritual; powerstock unchanged (owner-gated).

## OUT OF SCOPE (named)
Hub asset library with cross-site placement (images phase 2, parked) ·
regex/wildcard redirects · redirect hit counters · imports from redirect
plugins · automatic redirects without the popup (owner wants the choice).
