# GAP ANALYSIS — REMOTE DELETE 405 (proven live, fix proven live) — 2026-07-14

**Owner report:** bulk delete fails — "14 of 14 item(s) could not be
deleted". Long documented as a fleet mystery (handover: "remote delete
405s — wp-admin removal").

## Facts (reproduced, not theorized)

| # | Fact |
|---|---|
| D1 | The delete chain is correct: hook → `remote_delete` → `PCM_SEO_Service::remote_delete_content` (seo/service.php:1313) → `remote_rest($site,'DELETE','/wp/v2/posts/{id}', {force:false})` — query-only, no body, proper auth |
| D2 | REPRODUCED on disposable post 44: **status 405, body NULL**. WordPress answers REST failures as JSON (code/message) — a bare 405 with an EMPTY body comes from the WEB SERVER in front of WordPress: nginx on the Local site refuses the DELETE METHOD outright. Environment class, same family as the Avast facts — but unlike Avast, this one is fixable FROM OUR SIDE |
| D3 | FIX PROVEN LIVE: the same request sent as POST with WordPress's NATIVE `X-HTTP-Method-Override: DELETE` header → **200, post 44 trashed** (WP core's REST server honors the override; no connector change, no site change) |
| D4 | The bulk toast counts per-item rejections (`useRemoteSeoContent.ts:159-161`) — with D3 fixed, the counting UI is already correct |

## The fix (one place, every consumer)
`PCM_Sites_Service::remote_rest` sends **DELETE as POST + the override
header** — the tunnel is WP-core-native, works on servers that allow
DELETE too (the override wins either way), and every remote DELETE
consumer (post/page delete, and any future one) is fixed at the single
transport choke point. No per-caller patches, no connector update, no
environment edits.

## CHECKLIST
- [ ] This doc committed → implement (owner demanded the fix)
- [ ] remote_rest: DELETE → POST + X-HTTP-Method-Override (one branch, commented with the D2 evidence)
- [ ] Verify: php -l · harness 88 · live probe (delete a second disposable post through the FIXED path) · changelog · commit LOCAL ONLY
