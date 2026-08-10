# Validation — approval document persistence and backdrop — 2026-08-06

## Functional checklist

- [x] Open Approvals, select an approval set, and render the existing client preview.
- [x] Preserve the visible preview at 95vw × 90vh.
- [x] Open a custom document without changing its stored title, body, overlay, properties, approval actions, or toolbar.
- [x] Cover the full viewport with the existing `blur(12px) saturate(1.2)` backdrop.
- [x] Keep clicks inside the document open and interactive.
- [x] Close only the document on backdrop click, Escape, or document X; keep the client preview open.
- [x] Do not save merely because an unchanged document was opened.
- [x] Keep the existing two-second autosave cadence for ordinary editing.
- [x] Serialize writes so a newer draft waits for the active request and is then persisted.
- [x] Ignore stale query echoes while a local draft is dirty or being written.
- [x] On close intent, stop accepting user edits, await pending editor media work, flush the latest draft, and close only after success.
- [x] On an explicit save failure, reject the flush and leave the document open; the background retry uses the existing two-second cadence.
- [x] Persist an edit made immediately before a physical outside click and show it after reopening.
- [x] Restore the disposable test card to `321321321`; confirm a 267-byte snapshot and no `codex-` test marker.
- [x] Preserve the top-level public review document path and its outside-close behavior.

## Architecture checklist

- [x] One Radix dialog primitive owns modality, focus trapping, Escape, scroll locking, and accessibility metadata.
- [x] Visual panel geometry is separated from the fullscreen interaction host; no duplicate layout implementation was introduced.
- [x] Existing dialog consumers keep their defaults; `overlayClassName` and `unstyled` are opt-in shared capabilities.
- [x] One `useDebouncedSave` hook owns debounce, dirty tracking, serialization, retries, reset protection, and flush semantics.
- [x] Every document close path joins one idempotent promise.
- [x] One editor-owned close barrier tracks paste/drop uploads, annotations, and drawing uploads.
- [x] Hydration/extension normalization cannot masquerade as a user edit.
- [x] No backend route, API payload, database schema, workflow, typography, color, spacing, button, or document dimension changed.
- [x] No touched source file exceeds 500 lines (`CustomCardEditor.tsx` is the largest at 421 lines).
- [x] No duplicate editor, save route, overlay CSS, or upload endpoint was introduced.
- [x] No shortcut remains in the implemented scope. Deferred backend authorization/concurrency concerns are separate from this preservation-only repair.

## UX checklist

- [x] Approvals navigation and set selection remain unchanged.
- [x] Client preview remains a centered popup, not a fullscreen visual takeover.
- [x] Document opens centered with the existing appearance and a clearly blurred background.
- [x] The background blur reaches every viewport edge at narrow and desktop viewport sizes.
- [x] Clicking document content or tools does not dismiss it.
- [x] Outside click, Escape, and X behave consistently.
- [x] Save state is absent while unchanged, appears during real persistence, and does not claim completion before the write settles.
- [x] Immediate close after typing does not lose the edit or collapse the parent preview.
- [x] Public top-level review still opens and closes the same document surface.

## Verification evidence

- Production build: 2,176 modules transformed; successful Vite WordPress bundle.
- TypeScript: 59 repository-baseline errors; zero errors in the five touched TypeScript files.
- PHP syntax: all six Approvals PHP files clean. Local PHP emitted its pre-existing missing-Imagick startup warning.
- Standalone regression harness: 95 passed, 0 failed.
- Live narrow viewport: 481 × 918 overlay at x0/y0, 481 × 918; preview panel 456.9375 × 826.1875 (95vw × 90vh); blur exactly `blur(12px) saturate(1.2)`.
- Live desktop public viewport: 1280 × 720 overlay at x0/y0, 1280 × 720; outside click closed only the document.
- Persistence probe: `codex-final-20260806-1313` survived immediate outside-close and reopen; the database was then restored and directly checked.

