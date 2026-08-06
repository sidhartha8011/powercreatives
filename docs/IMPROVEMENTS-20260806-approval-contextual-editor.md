# Approval contextual editor — validation log

Date: 2026-08-06

Process note: `@task-process.md` was not present in the repository. The owner-provided implementation process in the task is the active fallback and was reread before each phase.

## Functional checklist

- [x] The permanent top toolbar is absent from editable Approval custom cards.
- [x] The selection toolbar is configured to appear only for non-empty text selections.
- [x] Approval selection formatting no longer duplicates image insertion.
- [x] Typing `/` at the start of a text block opens the block/media command menu.
- [x] Slash commands cover paragraph, H1–H4, bullet list, numbered list, checklist, quote, divider, image, image annotation, and whole-card drawing.
- [x] Arrow keys, Enter, Escape, pointer hover, pointer selection, and IME composition have explicit behavior; keyboard handling runs in a precedence-safe ProseMirror plugin.
- [x] Image insertion reuses the existing WordPress media library path.
- [x] Image annotation reuses the existing uploaded-image pipeline and double-click behavior.
- [x] Whole-card drawing reuses the existing overlay uploader and close-preparation barrier.
- [x] Editor updates still emit the same Tiptap HTML through the existing `onChange` contract.
- [x] Read-only cards render the same document without selection, slash, or annotation controls.
- [x] Browser: run a reversible `/check` edit, restore it, wait for autosave, close/reopen, and confirm the original five checklist items and one image persisted with no temporary query.
- [ ] Browser: edit ordinary prose, click outside, reopen, and confirm persistence. Existing persistence is unchanged; owner confirmation remains required before the VERIFIED commit.
- [ ] Browser: execute every remaining text block type through `/`. The full 13-command palette and filtered checklist command were verified; destructive coverage of every transform was intentionally not performed on owner data.
- [ ] Browser: insert an image through `/`, close/reopen, and confirm persistence. The existing handler is connected, but the WordPress media picker was avoided because the owner's upload path was previously blocked by Avast.
- [ ] Browser: annotate an existing image and confirm the flattened image persists. Existing double-click and upload handlers are preserved; no owner image was modified during validation.

## Code-alignment checklist

- [x] One shared Tiptap extension stack remains the source of document nodes and HTML.
- [x] One existing image picker/upload path remains the source of media persistence.
- [x] One Approval-owned portal/scroll resolver positions both contextual menus.
- [x] Writer’s default toolbar behavior is unchanged; Approvals uses an explicit opt-in prop.
- [x] Slash-command behavior is isolated in its own Approval component instead of growing the 400-line editor.
- [x] Every editor subscription and registered ProseMirror plugin has deterministic cleanup.
- [x] Slash matching is constrained to the beginning and end of the current text block, preventing accidental deletion of prose or URLs.
- [x] Long command lists and narrow property rows have explicit overflow behavior.
- [x] No REST route, database schema, autosave contract, or public-review payload changed.
- [x] No quick fix, copied renderer, duplicated persistence path, or new dependency was introduced.
- [x] Static: TypeScript check passes (`tsc --noEmit`).
- [x] Static: production build passes (2,179 modules transformed; Vite exit code 0).
- [x] Static: diff whitespace check passes.
- [x] Runtime: the built Approval editor completed slash, undo, autosave, close, reopen, and selection-menu checks with zero browser warnings or errors.

## UX and visual checklist

| Area | Expected result | Initial result | Regression result |
|---|---|---|---|
| Card chrome | No permanent editor toolbar or form-style divider above prose | Live pass: fixed toolbar buttons `0`; property divider `0px` | Owner visual confirmation pending |
| Selection | Formatting popover appears only after selecting text | Live pass: compact popover visible with 23 formatting actions and `0` image actions | Owner visual confirmation pending |
| Slash discovery | `/` opens a named, described block menu at the caret | Live pass: 13 commands; `/check` filters to Checklist | Owner visual confirmation pending |
| Keyboard | Up/down selection remains visible; Enter runs; Escape returns query to text | Enter live pass after native ProseMirror integration; remaining keys code-audited | Owner keyboard confirmation pending |
| Typography | 40px responsive title, 13px meta, 14px properties, 15/24px body | Live pass: measured 40/48 title, 13/19.5 meta, 14/21 property, 15/24 body | Owner visual confirmation pending |
| Checklist | 14px checkbox is centered in the 24px text line and does not inherit WordPress chrome | Live pass: checkbox measured 14x14 in a 24px line; reversible command created one task item | Owner visual confirmation pending |
| Responsive menu | Palette flips/shifts within the viewport and scrolls internally | Live desktop pass; Floating UI and bounded overflow config verified | Narrow viewport owner test pending |
| Media | Image insertion and annotation remain understandable without fixed chrome | Existing handlers reused; image action present in slash palette | Avast-sensitive upload test pending |
| Read-only | Public/internal read view shows no authoring controls | Render guard verified; editable internal surface live-tested | Public-link owner test pending |
| Loading | Approval data and preview should become interactive promptly | Cold Approvals load still measured roughly 20-30s; warm set preview about 5s | Pre-existing performance issue, outside this editor-only change |

## Shortcuts audit

No shortcut has been accepted. The implementation adds no dependency, does not fork the editor renderer, does not duplicate media persistence, and keeps the new interaction logic in a bounded 300-line Approval component. `CustomCardEditor` remains 404 lines and the pre-existing shared `WriterBubbleMenu` is 407; the new behavior was kept outside both files rather than increasing either one materially. Media mutation, every slash transform, narrow viewport, and public-link checks remain explicitly owner-verifiable before the VERIFIED commit.
