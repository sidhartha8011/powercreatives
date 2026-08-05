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

import { useEffect } from 'react';
import { createPortal } from 'react-dom';
import { CheckCircle2, X } from 'lucide-react';
import { useEditor, EditorContent } from '@tiptap/react';

import { getEditorExtensions } from '@/components/shared/editorExtensions';

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
}

export interface CardDocumentViewProps {
  /** Tiptap HTML of the document body. */
  content: string;
  title: string;
  /** Property rows. Empty or omitted renders no property block at all. */
  properties?: ReadonlyArray<CardDocumentProperty>;
  /** Persistent freehand draw layer rendered on top of the content (custom cards). */
  overlay?: string;
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

export function CardDocumentView({
  content,
  title,
  properties,
  overlay,
  isApproved,
  isSubmitted,
  onApprove,
  onClose,
}: CardDocumentViewProps) {
  const editor = useEditor({
    extensions: getEditorExtensions({ placeholder: '' }),
    content: content || '<p></p>',
    editable: false,
    editorProps: {
      attributes: { class: 'outline-none' },
    },
  });

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

  const rows = properties ?? [];

  return createPortal(
    <div
      className="pcm-notion-overlay"
      onClick={onClose}
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
            <h1 className="pcm-notion-title">{title}</h1>

            {rows.length > 0 && (
              <div className="pcm-notion-props">
                {rows.map((row) => (
                  <div className="pcm-notion-prop" key={row.label}>
                    <span className="pcm-notion-prop-label">{row.label}</span>
                    <span className="pcm-notion-prop-value">{row.value}</span>
                  </div>
                ))}
              </div>
            )}

            {/* Tiptap read-only rendered content (+ persistent draw layer on top) */}
            <div className="pcm-notion-prose pcm-notion-prose-wrap">
              {editor && <EditorContent editor={editor} />}
              {overlay && (
                <img src={overlay} alt="" aria-hidden className="pcm-notion-draw" />
              )}
            </div>
          </div>
        </div>
      </div>
    </div>,
    document.body
  );
}
