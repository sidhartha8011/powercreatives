# GAP ANALYSIS — load speed: site click + page open — 2026-07-14 — AWAITING GO

**Owner ask:** make clicking a SITE (the table) and opening a PAGE (the
editor) faster. Facts measured, not guessed.

## Facts (measured on powerleads, this machine)

| # | Fact |
|---|---|
| L1 | The hub has 2 PHP workers (pm.max_children = 2, proven earlier). EVERY interaction that fires parallel requests serializes them in pairs behind those two workers — this multiplies every other number below |
| L2 | SITE CLICK server time: `remote_list_content` = **1.5–2.9s**, and it makes its two connector calls (posts, pages) SEQUENTIALLY — the total is the SUM of two round trips |
| L3 | PAGE OPEN server time: `remote_get_inventory` = **2.7s** (one connector round trip + served-view assembly on the client site). The modal ALSO fires `remotePageVersions` (connector round trip) and `remoteGetParagraphRules` (connector round trip) ON MOUNT — three connector-bound requests racing for two workers |
| L4 | WordPress bundles the Requests library with `request_multiple` (true parallel HTTP) — the sequential pair in L2 can run concurrently: the sum becomes the MAX |
| L5 | The frontend cache already softens repeats: the table keeps rows per site (revisits paint instantly, refresh happens silently) and the editor guards against content clobber on refetch — the pain is the FIRST open per session, which is exactly where L2/L3 live |
| L6 | `remotePageVersions` is consumed by the versions dropdown + the Original row; `remoteGetParagraphRules` only powers the image-rule badge once an image is SELECTED — neither is needed to SHOW the page. Both are deferrable: fetch on first use, not on mount |

## The plan (each lever quantified)

| # | Lever | Effect |
|---|---|---|
| R1 | Parallelize `remote_list_content`'s two connector calls via `Requests::request_multiple` (L4) | site click server time: sum → max (≈ halved) |
| R2 | Defer `remotePageVersions` (fetch when the versions dropdown opens) and `remoteGetParagraphRules` (fetch when an image is selected) — L6 | page open = ONE connector call (≈ 2.7s total instead of 3 calls fighting 2 workers); versions/rules load invisibly later |
| R3 | Table rows: `staleTime` 60s on `remoteContent` | site revisits within a minute = zero refetch, instant |
| R4 | **ENVIRONMENT (owner's call):** raise the hub's PHP pool (2 → 6–8) in Local's config | the multiplier on EVERYTHING — parallel requests stop queueing; also what made Analyze stall the whole app. Note: Local may reset it on updates |

Honest bound: the ~1.5–2.7s per connector round trip is hub→site HTTP +
the client site's own render time — R1–R3 remove round trips and overlap
them; making a single round trip faster is connector-side work (separate
scope if ever needed).

## CHECKLIST
- [ ] BEFORE = this doc committed
- [ ] R1 parallel list fetch (request_multiple, one function)
- [ ] R2 defer versions + paragraph-rules queries to first use
- [ ] R3 staleTime on the table rows
- [ ] Verify: php -l · harness 88 · tsc 59 · build · probe re-measure R1 · changelog · commit LOCAL ONLY
- [ ] R4 = owner decision (Local config edit, documented here, not touched by me)
