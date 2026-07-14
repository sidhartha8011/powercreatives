# GAP ANALYSIS — preview behind the modal · Open on drafts — 2026-07-14 — owner order, implement after commit

## Facts (proven)
| # | Fact |
|---|---|
| V1 | The inline preview popup (SEO/index.tsx:1779, `fixed z-50`) is NOT portaled — it lives inside the app's DOM where an ancestor stacking context caps it; the page editor + drawer ARE portaled to document.body (wrapper z-40) — so the body-level editor paints over the app-trapped preview. Fix: portal the preview to document.body — its z-50 then wins globally, preview above the editor as the owner expects |
| V2 | Open showing `?page_id=3`: PROVEN site data, not our bug — Privacy Policy is status DRAFT (live probe) and WordPress gives drafts exactly that link; published pages open pretty slugs (verified: /elementor-25/). A draft's plain link shows nothing to a visitor. Fix: the OPEN anchor appends WP's `preview=true` for non-published rows (opens the REAL draft as a logged-in preview); `page.permalink` itself stays the true permalink — the GSC drawer keeps filtering on the real URL |

## CHECKLIST
- [ ] This doc committed → implement
- [ ] V1 portal the preview popup
- [ ] V2 status rides the page prop; the Open href gains preview=true when not published
- [ ] Verify: tsc 59 · build · changelog · commit LOCAL ONLY
