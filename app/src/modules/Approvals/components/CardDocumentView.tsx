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
 * It uses a stable portal shell and portals to the host-provided container when
 * nested inside the client preview. The public review surface has no parent
 * modal and uses `document.body`.
 */

import { useCallback, useEffect, useRef, type ComponentType } from 'react';
import { createPortal } from 'react-dom';
import { CheckCircle2, X } from 'lucide-react';
import { toast } from 'sonner';

import { useDebouncedSave } from '@/hooks/useDebouncedSave';
import { CustomCardEditor } from './CustomCardEditor';
import { isApprovalEditorOverlayOpen } from './approvalEditorOverlayBoundary';

/** Everything on this sheet that is stored. One shape, one save. */
export interface CardDocumentDraft {
  title: string;
  content: string;
  overlay: string | null;
}

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
  /** Whether the sheet is visible. Hidden sheets stay mounted only while a background save drains. */
  visible: boolean;
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
  /**
   * Persist the document. MUST return a promise that settles when the write has
   * actually landed — the autosave awaits it to report "Saved" truthfully, and
   * `flush()` awaits it so closing mid-save cannot lose the last change. A
   * fire-and-forget mutation here resolves instantly and the card claims to have
   * saved work that never left the browser.
   */
  onSave?: (doc: CardDocumentDraft) => Promise<unknown>;
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
  /** Hide the sheet immediately so the user can continue elsewhere. */
  onDismiss: () => void;
  /** Restore the retained sheet when its background save fails. */
  onRestore: () => void;
  /** Unmount the retained sheet after its background work has safely completed. */
  onClose: () => void;
  /**
   * Where this sheet is portalled. Defaults to `document.body`.
   *
   * A host that is itself a MODAL dialog must pass its own content element.
   * A modal Radix dialog sets `trapFocus`, `disableOutsidePointerEvents` and
   * `hideOthers()` against everything outside its content — so a sheet living
   * in `document.body` could not be clicked, could not take keyboard focus and
   * was hidden from screen readers. Being a DOM descendant of the dialog puts
   * it inside all three, which is what the Radix and shadcn docs prescribe for
   * a nested overlay: portal it to the dialog content, not to the body.
   *
   * The public client page has no dialog around it and keeps the default.
   */
  container?: HTMLElement | null;
}

export function CardDocumentView({
  visible,
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
  onDismiss,
  onRestore,
  onClose,
  container,
}: CardDocumentViewProps) {
  /**
   * Autosave, on the app-wide contract (`useDebouncedSave`) — the same one the
   * Writer has always used.
   *
   * This used to persist ONLY in an unmount cleanup, and that lost work: an
   * image upload finishing 14 seconds after the card closed was handed to a
   * destroyed editor while the card had already written its old content over
   * itself (approval set 25, 2026-08-06). A document is saved while it is being
   * written, not at the single instant it goes away.
   */
  const doc = useRef({ title, content, overlay: overlay ?? null });
  const visibleRef = useRef(visible);
  const closingRef = useRef<Promise<void> | null>(null);
  const prepareEditorCloseRef = useRef<(() => Promise<void>) | null>(null);
  const overlayRef = useRef<HTMLDivElement | null>(null);
  const closeButtonRef = useRef<HTMLButtonElement | null>(null);
  const registerClosePreparation = useCallback((prepare: (() => Promise<void>) | null) => {
    prepareEditorCloseRef.current = prepare;
  }, []);

  useEffect(() => { visibleRef.current = visible; }, [visible]);

  const saver = useDebouncedSave<CardDocumentDraft>({
    enabled: canEdit && !!onSave,
    save: async (value) => { await onSave?.(value); },
  });

  // Adopt what the host says is stored. On mount that is the loaded document;
  // later it is the result of a refetch. `reset` marks it clean, so a refetch
  // never looks like an edit and never bounces straight back to the server.
  useEffect(() => {
    // A completed older write invalidates the query while a newer local edit
    // may already be queued. Its refetch acknowledges the older revision; it
    // must not replace the newer draft held in `doc`. `reset` applies the same
    // dirty guard inside the saver, so keep both sources of truth aligned.
    if (saver.isDirty) return;
    doc.current = { title, content, overlay: overlay ?? null };
    saver.reset(doc.current);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [title, content, overlay, saver.isDirty]);

  /** One place every edit on this sheet flows through. */
  const edit = (patch: Partial<CardDocumentDraft>) => {
    doc.current = { ...doc.current, ...patch };
    saver.change(doc.current);
  };

  /**
   * Dismiss immediately, then drain media work and the document save while the
   * editor remains mounted but non-modal. Keeping the editor alive is essential:
   * annotation uploads finish by replacing a Tiptap image node with the hosted
   * URL. Unmounting here would destroy that editor and lose the replacement.
   */
  const closeInBackground = () => {
    visibleRef.current = false;
    onDismiss();
    if (closingRef.current) return;

    const toastId = toast.loading('Saving changes in the background…');
    let request: Promise<void>;
    request = (async () => {
      await prepareEditorCloseRef.current?.();
      await saver.flush();
      toast.success('Changes saved', { id: toastId });
      if (!visibleRef.current) onClose();
    })()
      .catch(() => {
        toast.error('Could not save. The document has been reopened.', { id: toastId });
        visibleRef.current = true;
        onRestore();
      })
      .finally(() => {
        if (closingRef.current === request) closingRef.current = null;
      });
    closingRef.current = request;
  };

  const closeInBackgroundRef = useRef(closeInBackground);
  closeInBackgroundRef.current = closeInBackground;

  // The retained background editor cannot use a modal primitive: changing a
  // modal dialog into a non-modal one remounts its content and destroys the
  // exact Tiptap instance whose pending image work must survive. Preserve the
  // visible dialog's Escape and scroll-lock behaviour without changing that
  // component identity during dismissal.
  useEffect(() => {
    if (!visible) return;
    const previousOverflow = document.body.style.overflow;
    const previousFocus = document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null;
    const overlayElement = overlayRef.current;
    const isolatedSiblings = overlayElement?.parentElement
      ? Array.from(overlayElement.parentElement.children)
        .filter((element): element is HTMLElement => element instanceof HTMLElement && element !== overlayElement)
        .map((element) => ({ element, inert: element.inert }))
      : [];
    const handleKeyDown = (event: KeyboardEvent) => {
      if (
        event.key === 'Escape'
        && !event.defaultPrevented
        && !isApprovalEditorOverlayOpen()
      ) {
        event.preventDefault();
        closeInBackgroundRef.current();
      }
    };
    isolatedSiblings.forEach(({ element }) => { element.inert = true; });
    document.body.style.overflow = 'hidden';
    window.addEventListener('keydown', handleKeyDown);
    closeButtonRef.current?.focus();
    return () => {
      window.removeEventListener('keydown', handleKeyDown);
      document.body.style.overflow = previousOverflow;
      isolatedSiblings.forEach(({ element, inert }) => { element.inert = inert; });
      if (previousFocus?.isConnected) previousFocus.focus();
    };
  }, [visible]);

  const rows = properties ?? [];

  return createPortal(
    <div
      ref={overlayRef}
      className={`pcm-notion-overlay ${visible ? '' : 'pcm-notion-overlay--background-save'}`}
      aria-hidden={!visible}
      onClick={closeInBackground}
    >
      <div
        role={visible ? 'dialog' : undefined}
        aria-modal={visible ? true : undefined}
        aria-label={title || 'Document preview'}
        className="pcm-notion-modal pointer-events-auto"
        onClick={(event) => event.stopPropagation()}
      >
        {/* Top bar — actions only. It used to carry a FileText icon plus the
            title, which repeated the 40px title sitting directly below it. */}
        <div className="pcm-notion-topbar">
          {/* Save state — a statement of what is happening, which then stops.
              Not instructional chrome: it says nothing while there is nothing to
              say, so the bar is empty on a document no one is editing. */}
          {canEdit && saver.state !== 'idle' && (
            <span className="pcm-notion-savestate" role="status">
              {saver.state === 'error' ? 'Not saved — retrying'
                : saver.state === 'saved' ? 'Saved'
                : 'Saving…'}
            </span>
          )}
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
            ref={closeButtonRef}
            type="button"
            className="pcm-notion-iconbtn"
            onClick={closeInBackground}
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
              contentEditable={canEdit && visible}
              suppressContentEditableWarning
              spellCheck={false}
              onInput={(e) => edit({ title: e.currentTarget.textContent ?? '' })}
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

            {/* ONE editor, two modes.
                This is `CustomCardEditor` — the same surface the "New approval
                set" dialog authors on, which is itself the Writer's editor: the
                shared Tiptap extensions and the Writer's bubble menu. A client
                gets the identical component with `editable={false}`, so reading
                and writing can never drift apart. There used to be a second,
                read-only Tiptap component beside it doing the same job. */}
            {isLoading ? null : (
              <CustomCardEditor
                bare
                editable={canEdit}
                content={content}
                overlay={overlay ?? null}
                onChange={(html) => edit({ content: html })}
                onOverlayChange={(url) => edit({ overlay: url })}
                registerClosePreparation={registerClosePreparation}
              />
            )}
          </div>
        </div>
      </div>
    </div>,
    container ?? document.body
  );
}
