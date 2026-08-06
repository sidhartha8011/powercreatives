/**
 * CardDocumentView — the opened approval document, rendered as a Notion page.
 *
 * Extracted verbatim from `ArticleViewerDialog`, which lived privately inside
 * CreativeAssetCard.tsx with a single consumer. It now has two — the client
 * review page and the Approvals board — and rendering the same document from
 * two copies is exactly how two surfaces drift apart.
 *
 * The typography is NOT defined here. The 708px column, the 40px title and the
 * 16px prose come from `--pcm-doc-*` (index.css) via the `.pcm-notion-*` classes
 * in client-review.css. Those numbers are already the Notion reference and are
 * deliberately untouched — the gap was chrome and placement, not type.
 *
 * It portals to `document.body`, i.e. OUTSIDE `#pcm-root`, which is why
 * `--pcm-doc-*` is declared on `:root` as well as `#pcm-root`.
 */

import { useEffect, useRef, type ComponentType } from 'react';
import { createPortal } from 'react-dom';
import { CheckCircle2, X } from 'lucide-react';
import { useEditor, EditorContent } from '@tiptap/react';

import { getEditorExtensions } from '@/components/shared/editorExtensions';
import { CustomCardEditor } from './CustomCardEditor';

/**
 * One Notion property row — the "use our information simply" line.
 *
 * The host builds these from data it already holds and passes them in. The view
 * never reaches for a global or guesses which fields exist: the previous version
 * gated the whole block on `metaTitle || metaDescription`, which are ARTICLE
 * fields, so on a custom document the property area was always empty.
 */
export interface CardDocumentProperty {
  label: string;
  value: string;
  /**
   * Icon for the row, supplied by the host as a component.
   *
   * NOT a label→icon map inside this view: that is an allow-list that silently
   * has no entry the first time a new property is added, and the row then renders
   * a gap where every other row has an icon.
   */
  icon?: ComponentType<{ className?: string }>;
  /** Render the value as a chip (a status, a tag) instead of plain text. */
  pill?: boolean;
}

export interface CardDocumentViewProps {
  /** Tiptap HTML of the document body. */
  content: string;
  title: string;
  /** Property rows. Empty or omitted renders no property block at all. */
  properties?: ReadonlyArray<CardDocumentProperty>;
  /**
   * The quiet line above the title — "Edited 4 minutes ago".
   *
   * Supplied by the host, because only the host knows the timestamp. Omitted
   * renders nothing; the view never writes "Edited just now" on faith.
   */
  meta?: string;
  /**
   * The document is still being fetched. Renders the page shell with a state
   * line instead of content.
   *
   * This exists because the Approvals board cannot know a set is a document
   * without fetching it (the list query omits `snapshot` by design), and on this
   * host that fetch measured 1.4-2.9 s under PHP-FPM's 2 workers. Without a
   * visible state the click looked like it had done nothing at all.
   */
  isLoading?: boolean;
  /** Persistent freehand draw layer rendered on top of the content (custom cards). */
  overlay?: string;
  /**
   * The viewer is interactive — checkboxes tick, text can be edited.
   *
   * Was hardcoded `editable: false`, so TipTap drew the task-list checkboxes as
   * real inputs and then refused every click on them. A capability is not a
   * literal: this comes from whether the viewer may write.
   */
  canEdit?: boolean;
  /**
   * Persist an edited document. Required for `canEdit` to do anything.
   *
   * Takes the whole document — body and draw layer — because both are edited by
   * the same surface in the same sitting, and saving them through two callbacks
   * means two requests racing over one snapshot row.
   */
  onSave?: (doc: { title: string; content: string; overlay: string | null }) => void;
  /** Current approval state — drives the header Approve button's label/colour. */
  isApproved?: boolean;
  /** When submitted (locked lane), approval is disabled — mirrors the grid card. */
  isSubmitted?: boolean;
  /**
   * Toggle approval for this asset. OMITTED renders no Approve button at all —
   * which is how the admin board opens this view. Approving is the client's act;
   * an Approve button on the agency's own screen would let us sign off for them.
   */
  onApprove?: () => void;
  onClose: () => void;
}

/**
 * The document rendered for READING.
 *
 * Its own component so its editor is built ONLY when it is shown. As a branch
 * inside the parent it could not be: hooks do not take conditions, so a
 * read-only Tiptap instance was constructed on every open and then thrown away
 * unrendered whenever the document was opened for editing.
 *
 * Tiptap rather than raw HTML because `wp_kses_post` strips `<input>` on save —
 * a checklist's boxes are re-drawn by the task-item node view from
 * `data-checked`, which does survive.
 *
 * The typography here is `.pcm-notion-prose`, the document scale. The EDITING
 * surface reads at the Writer pad scale instead. That difference is the owner's
 * decision of 2026-08-05, recorded at index.css:1022 — authoring and reading are
 * deliberately not the same size, so these two must not be collapsed into one.
 */
function CardDocumentProse({ content, overlay }: { content: string; overlay?: string }) {
  const editor = useEditor({
    extensions: getEditorExtensions({ placeholder: '' }),
    content: content || '<p></p>',
    editable: false,
    editorProps: {
      attributes: { class: 'outline-none' },
    },
  });

  return (
    <div className="pcm-notion-prose pcm-notion-prose-wrap">
      {editor && <EditorContent editor={editor} />}
      {overlay && <img src={overlay} alt="" aria-hidden className="pcm-notion-draw" />}
    </div>
  );
}

export function CardDocumentView({
  content,
  title,
  properties,
  meta,
  isLoading = false,
  overlay,
  isApproved,
  isSubmitted,
  canEdit = false,
  onSave,
  onApprove,
  onClose,
}: CardDocumentViewProps) {
  // Close on Escape + prevent body scroll
  useEffect(() => {
    const handleKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', handleKey);
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', handleKey);
      document.body.style.overflow = '';
    };
  }, [onClose]);

  /**
   * Live edit buffer. `CustomCardEditor` is uncontrolled after mount (it seeds
   * from `content` once), so the current HTML is held here and written back on
   * close.
   */
  const draft = useRef({ title, content, overlay: overlay ?? null });
  useEffect(() => { draft.current = { title, content, overlay: overlay ?? null }; }, [title, content, overlay]);

  /**
   * Persist on the way out. Ticking a checkbox is an edit like any other, so it
   * has to survive closing the card.
   *
   * The work is read through a ref and the effect runs ONCE, on unmount. With
   * `onSave` in a dependency array the effect re-subscribes on every render —
   * `useMutation` hands back a fresh object each time, so the callback is never
   * identity-stable — and each re-subscribe runs the cleanup, i.e. a save per
   * render instead of a save per close.
   */
  const saveRef = useRef<() => void>(() => {});
  saveRef.current = () => {
    if (!canEdit || !onSave) return;
    const next = draft.current;
    if (next.title !== title || next.content !== content || next.overlay !== (overlay ?? null)) {
      onSave(next);
    }
  };
  useEffect(() => () => saveRef.current(), []);

  const rows = properties ?? [];

  /**
   * Stop the parent dialog closing when you click this viewer's backdrop.
   *
   * This portals to `document.body`, so it sits OUTSIDE the Radix Dialog that
   * hosts the client view. Radix detects dismissal on pointerdown-outside-itself
   * and saw every click here as exactly that — so clicking away from the
   * document closed the document AND the card behind it. The viewer owns its own
   * dismissal; the dialog must not also act on it.
   *
   * BUBBLE PHASE ONLY. There was a second binding of this on
   * `onPointerDownCapture`, and capture runs document → target: it discarded
   * every pointerdown on this overlay BEFORE the event could descend into the
   * sheet. ProseMirror sets the caret from that event and a task-list checkbox
   * is an `<input>` activated by it, so the whole document went dead — no
   * caret, no ticking. In the bubble phase the target has already handled the
   * event and this still stops it short of `document`.
   */
  const stopDialogDismiss = (e: React.PointerEvent) => e.stopPropagation();

  return createPortal(
    <div
      className="pcm-notion-overlay"
      onClick={onClose}
      onPointerDown={stopDialogDismiss}
      role="dialog"
      aria-label="Document preview"
    >
      <div className="pcm-notion-modal" onClick={(e) => e.stopPropagation()}>
        {/* Top bar — actions only. It used to carry a FileText icon plus the
            title, which repeated the 40px title sitting directly below it. */}
        <div className="pcm-notion-topbar">
          {/* Approve lives HERE, in the sticky bar, so it follows on scroll.
              It used to sit after the prose, inside the scrolling column, and
              scrolled out of reach on any document of real length. */}
          {onApprove && (
            <button
              type="button"
              disabled={isSubmitted}
              className={`pcm-notion-approve ${isApproved ? 'is-approved' : ''}`}
              onClick={onApprove}
            >
              <CheckCircle2 className="pcm-notion-approve-icon" />
              {isApproved ? 'Approved' : 'Approve'}
            </button>
          )}
          <button
            type="button"
            className="pcm-notion-iconbtn"
            onClick={onClose}
            aria-label="Close preview"
          >
            <X className="w-[18px] h-[18px]" />
          </button>
        </div>

        {/* Page */}
        <div className="pcm-notion-page">
          <div className="pcm-notion-col">
            {meta && <p className="pcm-notion-meta">{meta}</p>}
            {/* The title is edited in place, like the body. `update_snapshot_asset`
                already accepted `title` for both buckets (service.php:1625, :1647),
                so this is an affordance that was missing, not a capability.
                Plain contentEditable rather than an input: an input would need a
                second set of type rules to look like the 40px title it replaces,
                and two definitions of one heading is how they drift. */}
            <h1
              className="pcm-notion-title"
              contentEditable={canEdit}
              suppressContentEditableWarning
              spellCheck={false}
              onInput={(e) => {
                draft.current = { ...draft.current, title: e.currentTarget.textContent ?? '' };
              }}
              /* Enter would insert a <br> in a heading. It means "done" here. */
              onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); e.currentTarget.blur(); } }}
            >
              {title}
            </h1>

            {isLoading && (
              <p className="pcm-notion-loading">Opening document…</p>
            )}

            {!isLoading && rows.length > 0 && (
              <div className="pcm-notion-props">
                {rows.map(({ label, value, icon: Icon, pill }) => (
                  <div className="pcm-notion-prop" key={label}>
                    <span className="pcm-notion-prop-label">
                      {Icon && <Icon className="pcm-notion-prop-icon" />}
                      {label}
                    </span>
                    <span className="pcm-notion-prop-value">
                      {pill ? <span className="pcm-notion-pill">{value}</span> : value}
                    </span>
                  </div>
                ))}
              </div>
            )}

            {/* EDITING is the authoring surface that already exists — the same
                `CustomCardEditor` the create dialog uses, which is itself the
                Writer's editor: the shared Tiptap extensions, the Writer's
                bubble menu, the checklist / image / annotate / draw tools. No
                second editor, no second set of extensions, no second toolbar.
                `bare` drops its own card chrome because the Notion sheet around
                it already IS the surface. */}
            {isLoading ? null : canEdit ? (
              <CustomCardEditor
                bare
                scale="document"
                content={content}
                overlay={overlay ?? null}
                onChange={(html) => { draft.current = { ...draft.current, content: html }; }}
                onOverlayChange={(url) => { draft.current = { ...draft.current, overlay: url }; }}
              />
            ) : (
              <CardDocumentProse content={content} overlay={overlay} />
            )}
          </div>
        </div>
      </div>
    </div>,
    document.body
  );
}
