# UX validation improvements — approval document — 2026-08-06

| Check | Initial result | Exact reason | Improvement | Regression / final result |
|---|---|---|---|---|
| Full-viewport blur | Failed before Gate 1 | The document portal was inside a transformed 95vw × 90vh dialog, so fixed positioning resolved against that popup. | Separated the transparent viewport host from the unchanged visible preview panel and used a nested Radix dialog. | Pass: overlay exactly matches both tested viewports and remains 12px. |
| Physical backdrop click | Failed in the inherited build | The sharp outer margin belonged to the parent preview and bypassed the document close/save path. | The fullscreen document interaction host owns outside clicks; the visible modal stops propagation. | Pass: document closes and parent preview remains open. |
| Escape and X | Inconsistent risk before Gate 1/2 | Separate/manual dismissal paths could bypass or duplicate persistence. | Routed all dismissal paths through one idempotent close promise. | Pass: each closes only the document. |
| Immediate edit then outside click | Failed in the inherited build | Unmount cleared the debounce timer before the final value was written. | Close awaits the serialized saver drain before unmounting. | Pass: marker persisted after immediate physical click and reopen. |
| Overlapping autosaves | Architectural failure | A timer could start another request while a prior request was active. | Replaced independent writes with a single-writer latest-value drain. | Pass by code-path review, TypeScript, build, and live close-time persistence. |
| Save failure semantics | Architectural failure | `flush()` swallowed rejection and `.finally(onClose)` closed after failure. | Explicit flush rejects; close invokes `onClose` only from the success branch and background retry keeps the prior cadence. | Pass by code-path review; no destructive live failure injection was used. |
| Pending media work | Architectural failure | Paste/drop, annotation, and drawing uploads were fire-and-forget and could finish after unmount. | Added one editor close barrier that tracks uploads and commits an active drawing before flush. | Pass by code-path review and production build; upload routes and UI are unchanged. |
| Unchanged open | Failed during final visual validation | Tiptap construction/normalization emitted update transactions and produced a ghost save. | Suppressed hydration emission and accepted editor updates only after the first interactive frame. | Pass: repeated open shows no save state and does not write. |
| Inside document click | Pass | Modal propagation is isolated from the fullscreen close host. | None. | Pass after all fixes. |
| Visible preview size/design | Pass | Visual shell remained the existing 95vw × 90vh classes and styles. | None. | Pass at 481 × 918 and 1280 × 720 test contexts. |
| Disposable database state | Pass after cleanup | Test mutations were confined to the named disposable set. | Restored original body and queried the row directly. | Pass: 267 bytes, original `321321321`, zero `codex-` markers. |

