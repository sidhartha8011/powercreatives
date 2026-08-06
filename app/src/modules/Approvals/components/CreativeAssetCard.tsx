import { useState, useCallback, useEffect, useRef, useMemo } from 'react';
import { createPortal } from 'react-dom';
import { CheckCircle2, MessageSquare, Check, X, Video, Image as ImageIcon, Download, Copy, FileText, Tag, FolderOpen, CalendarDays, Type, AlignLeft } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { toast } from 'sonner';
import { isVideoAsset } from './ClientReviewPage';
import { TiptapBodyEditor } from '@/components/shared/TiptapBodyEditor';
import { escapeAstral } from '@/lib/escapeAstral';
import { useAutoResizeTextarea } from '@/hooks/useAutoResizeTextarea';
import { trpc } from '@/lib/trpc';
import { CardDocumentView, type CardDocumentProperty } from './CardDocumentView';

export interface CreativeAsset {
  id: string;
  url?: string;
  body?: string;
  headline?: string;
  description?: string;
  cta?: string;
  mimeType?: string;
  name?: string;
  platform?: string;
  audienceName?: string;
  angleName?: string;
  type?: string;
  tone?: string;
  toneOfVoice?: string;
  aspectRatio?: string;
  width?: number;
  height?: number;
  duration?: string;
  // Article-specific fields (from Writer module snapshot)
  title?: string;
  content?: string;
  slug?: string;
  metaTitle?: string;
  metaDescription?: string;
  schemaType?: string;
  featuredImage?: string;
  /** Custom card persistent draw layer (transparent PNG data-URL). */
  overlay?: string;
  [key: string]: unknown;
}


interface CreativeAssetCardProps {
  asset: CreativeAsset;
  type: 'media' | 'copy' | 'article' | 'custom';
  isApproved: boolean;
  /** Number of comments for this asset (0 means no comments yet) */
  commentCount: number;
  hasNewComment?: boolean;
  onApprove: (id: string) => void;
  brandLogoUrl?: string | null;
  brandName?: string;
  /**
   * RAW set mapping for the opened document's property rows.
   *
   * Deliberately separate from `brandName`: that one carries a display fallback
   * ('Client Board' at the page level, 'Brand' here) which is fine as chrome but
   * would print as a fact in a property row. These two are unresolved — absent
   * means the row is not rendered at all.
   */
  setBrandName?: string | null;
  setProjectName?: string | null;
  pairedMediaUrl?: string | null;
  isSubmitted?: boolean;
  isTeamMember?: boolean;
  /**
   * The set's share token, supplied by the host.
   *
   * The asset-update route is token-scoped. Reading the token from the URL only
   * worked on the public client page; in wp-admin the parameter does not exist,
   * so admin edits sent an empty token and failed.
   */
  publicToken?: string;
  onAssetUpdate?: () => void;
  onOpenComments: (id: string) => void;
  /** Zero-based index for copy card numbering ("Copy 1", "Copy 2", etc.) */
  copyIndex?: number;
}

export function CreativeAssetCard({
  asset,
  type,
  isApproved,
  commentCount,
  hasNewComment = false,
  onApprove,
  brandLogoUrl,
  brandName = 'Brand',
  setBrandName,
  setProjectName,
  pairedMediaUrl,
  isSubmitted = false,
  isTeamMember = false,
  publicToken,
  onAssetUpdate,
  onOpenComments,
  copyIndex
}: CreativeAssetCardProps) {
  // isExpanded state removed — copy cards are always fully expanded now
  const [showLightbox, setShowLightbox] = useState(false);
  const [showArticleViewer, setShowArticleViewer] = useState(false);

  // Extract first image URL from article/custom HTML content for thumbnail preview
  const articleThumbnail = useMemo(() => {
    if (type !== 'article' && type !== 'custom') return null;
    if (asset.featuredImage) return asset.featuredImage;
    // Extract first <img src> from HTML content
    const match = asset.content?.match(/<img[^>]+src=["']([^"']+)["']/);
    return match?.[1] ?? null;
  }, [type, asset.featuredImage, asset.content]);

  // Extract plain-text snippet from article/custom HTML for preview card
  const articleSnippet = useMemo(() => {
    if ((type !== 'article' && type !== 'custom') || !asset.content) return '';
    const text = asset.content.replace(/<[^>]*>/g, '').trim();
    return text.length > 120 ? text.slice(0, 120) + '…' : text;
  }, [type, asset.content]);

  /**
   * Notion property rows for the opened document.
   *
   * A `custom` document has no meta fields — `CustomAsset` is
   * `{ id, type, title, content, images, annotation, createdAt, updatedAt }` —
   * so the old `metaTitle || metaDescription` gate was ALWAYS false and the
   * property area never rendered anything on a custom card. These rows are our
   * own information instead: who it is for, what it belongs to, when it was made.
   * Only rows with a real value are emitted; nothing is invented to fill space.
   */
  const documentProperties = useMemo<CardDocumentProperty[]>(() => {
    const rows: CardDocumentProperty[] = [];
    const push = (
      label: string,
      value: string | null | undefined,
      icon: CardDocumentProperty['icon'],
      pill = false
    ) => {
      const clean = typeof value === 'string' ? value.trim() : '';
      if (clean) rows.push({ label, value: clean, icon, pill });
    };

    if (type === 'article') {
      // Unchanged for articles — these are the fields that surface actually has.
      push('Meta title', asset.metaTitle, Type);
      push('Meta description', asset.metaDescription, AlignLeft);
      return rows;
    }

    // Brand reads as a tag in the reference layout, so it renders as a chip.
    push('Brand', setBrandName, Tag, true);
    push('Project', setProjectName, FolderOpen);
    const created = typeof asset.createdAt === 'string' ? asset.createdAt : '';
    if (created) {
      const d = new Date(created.replace(' ', 'T'));
      if (!Number.isNaN(d.getTime())) {
        push('Created', new Intl.DateTimeFormat('en-GB', {
          year: 'numeric', month: 'short', day: 'numeric',
        }).format(d), CalendarDays);
      }
    }
    return rows;
  }, [type, asset.metaTitle, asset.metaDescription, asset.createdAt, setBrandName, setProjectName]);

  /**
   * "Edited 4 minutes ago" — the grey line above the title.
   *
   * Built from `updatedAt` only. When the snapshot carries no timestamp the line
   * is absent rather than guessed: a card that claims it was edited just now
   * because we had nothing to print is worse than a card with no line.
   */
  const documentMeta = useMemo<string | undefined>(() => {
    const raw = typeof asset.updatedAt === 'string' ? asset.updatedAt : '';
    if (!raw) return undefined;
    const then = new Date(raw.replace(' ', 'T'));
    if (Number.isNaN(then.getTime())) return undefined;

    const mins = Math.round((Date.now() - then.getTime()) / 60000);
    const rtf = new Intl.RelativeTimeFormat('en-GB', { numeric: 'auto' });
    const ago =
      mins < 1 ? 'just now'
      : mins < 60 ? rtf.format(-mins, 'minute')
      : mins < 1440 ? rtf.format(-Math.round(mins / 60), 'hour')
      : rtf.format(-Math.round(mins / 1440), 'day');
    return `Edited ${ago}`;
  }, [asset.updatedAt]);

  // Text Inline Edits state
  const [isEditingText, setIsEditingText] = useState(false);
  const [editedHeadline, setEditedHeadline] = useState(asset.headline || '');
  const [editedBody, setEditedBody] = useState(asset.body || '');
  const [editedDescription, setEditedDescription] = useState(asset.description || '');
  const [isSavingEdits, setIsSavingEdits] = useState(false);

  const containerRef = useRef<HTMLDivElement>(null);

  /* Auto-resize textarea height to match content (mirrors <h4>/<p> view mode) */
  const headlineRef = useAutoResizeTextarea(editedHeadline);
  const descriptionRef = useAutoResizeTextarea(editedDescription);

  const updateMutation = trpc.approvals.updateSnapshotAsset.useMutation();

  // Close lightbox on Escape + prevent body scroll while open
  useEffect(() => {
    if (!showLightbox) return;
    const handleKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setShowLightbox(false);
    };
    document.addEventListener('keydown', handleKey);
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', handleKey);
      document.body.style.overflow = '';
    };
  }, [showLightbox]);

  // Detect video content using shared utility
  const isVideo = type === 'media' && isVideoAsset(asset);

  const handleToggleApprove = useCallback(() => {
    if (isSubmitted) return;
    onApprove(asset.id);
  }, [asset.id, onApprove, isSubmitted]);

  // CORS-resilient downloading system
  const handleDownload = useCallback(async (e: React.MouseEvent) => {
    e.stopPropagation();
    if (!asset.url) return;
    
    const extension = isVideo ? 'mp4' : 'jpg';
    const filename = asset.name 
      ? asset.name.endsWith(`.${extension}`) ? asset.name : `${asset.name}.${extension}`
      : `creative-asset.${extension}`;

    try {
      const response = await fetch(asset.url, { mode: 'cors' });
      if (!response.ok) throw new Error();
      const blob = await response.blob();
      const blobUrl = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = blobUrl;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      window.URL.revokeObjectURL(blobUrl);
    } catch {
      // Direct tab fallback if CORS is strict
      window.open(asset.url, '_blank');
    }
  }, [asset, isVideo]);

  // Cross-browser clipboard copier with fallback
  const handleCopyToClipboard = useCallback((e: React.MouseEvent) => {
    e.stopPropagation();
    // Copy ONLY the ad-copy body — never prepend the headline/title. The body is
    // what gets pasted straight into the ad platform; the headline is a separate field.
    const textToCopy = asset.body || '';
    
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(textToCopy)
        .then(() => {
          toast.success('Ad copy copied to clipboard!');
        })
        .catch(() => {
          toast.error('Failed to copy text.');
        });
      return;
    }

    // Classic fallback for non-secure HTTP or older browsers
    const textArea = document.createElement("textarea");
    textArea.value = textToCopy;
    textArea.style.position = "fixed";
    textArea.style.left = "-999999px";
    textArea.style.top = "-999999px";
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    try {
      document.execCommand('copy');
      toast.success('Ad copy copied to clipboard!');
    } catch {
      toast.error('Failed to copy to clipboard.');
    } finally {
      textArea.remove();
    }
  }, [asset]);

  /* Single-click enters edit mode for team members.
     No expand/collapse step — copy cards are always fully visible.
     Editing is NOT gated by isSubmitted: team members can keep refining copy in
     any lane, including post-submit lanes (launch/live/archived). Only client
     approval stays locked after sign-off (the Approve button is disabled via
     isSubmitted); the snapshot-update endpoint allows edits in every lane. */
  const handleCardClick = useCallback(() => {
    if (isTeamMember && !isEditingText) {
      setIsEditingText(true);
    }
  }, [isTeamMember, isEditingText]);

  /**
   * Persist the opened document — body and draw layer, in one request.
   *
   * Same token-scoped route as the inline copy edits: `update_snapshot_asset`
   * already accepted `content` for the `articles` and `custom` buckets, so no
   * new endpoint. Ticking a checkbox is an edit of `content` like any other —
   * Tiptap writes the state onto `data-checked` on the `<li>`, which
   * `wp_kses_post` keeps.
   */
  const handleSaveDocument = useCallback((doc: { title: string; content: string; overlay: string | null }) => {
    if (!publicToken) {
      toast.error('Cannot save — this card has no share token.');
      return;
    }
    updateMutation.mutate(
      { token: publicToken, assetId: asset.id, title: doc.title, content: doc.content, overlay: doc.overlay },
      {
        onSuccess: () => { onAssetUpdate?.(); },
        onError: () => { toast.error('Failed to save document.'); },
      }
    );
  }, [publicToken, asset.id, updateMutation, onAssetUpdate]);

  // Save inline text edits to snapshot and DB
  const handleSaveTextEdits = useCallback(async () => {
    // If text hasn't changed, just close editing mode
    if (
      editedHeadline === (asset.headline || '') && 
      editedBody === (asset.body || '') && 
      editedDescription === (asset.description || '')
    ) {
      setIsEditingText(false);
      return;
    }

    setIsSavingEdits(true);

    // The HOST supplies the token. This used to read it from
    // window.location.search, which exists on the public client page and NOT in
    // wp-admin — so an edit made from the admin card sent an empty token and the
    // save failed silently. A component that reaches for the URL only works on
    // the one surface it was written for.
    if (!publicToken) {
      setIsSavingEdits(false);
      toast.error('Cannot save — this card has no share token.');
      return;
    }
    const token = publicToken;

    updateMutation.mutate(
      {
        token,
        assetId: asset.id,
        // Escape emoji to ASCII so a request-stripping WAF/security layer can't
        // drop them in transit; the server decodes them back before storing.
        headline: escapeAstral(editedHeadline),
        body: escapeAstral(editedBody),
        description: escapeAstral(editedDescription)
      },
      {
        onSuccess: () => {
          setIsSavingEdits(false);
          setIsEditingText(false);
          toast.success('Edits saved successfully!');
          onAssetUpdate?.();
        },
        onError: () => {
          setIsSavingEdits(false);
          toast.error('Failed to save edits.');
        }
      }
    );
  }, [editedHeadline, editedBody, editedDescription, asset.headline, asset.body, asset.description, asset.id, onAssetUpdate, updateMutation, publicToken]);

  // Handle click outside container card to trigger autosave
  useEffect(() => {
    if (!isEditingText) return;

    const handleOutsideClick = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        handleSaveTextEdits();
      }
    };

    document.addEventListener('mousedown', handleOutsideClick);
    return () => document.removeEventListener('mousedown', handleOutsideClick);
  }, [isEditingText, handleSaveTextEdits]);

  // Keep local state in sync if asset properties update from query invalidation
  useEffect(() => {
    setEditedHeadline(asset.headline || '');
    setEditedBody(asset.body || '');
    setEditedDescription(asset.description || '');
  }, [asset.headline, asset.body, asset.description]);

  return (
    <div 
      ref={containerRef} 
      // Anchor for "open this sub-asset" — the board can scroll straight to one
      // item in a card that may hold many. An attribute rather than a wrapper
      // div, so the grid's children stay the cards themselves.
      data-asset-id={asset.id}
      className={`pcm-card ${isApproved ? 'approved' : ''} ${type === 'copy' ? 'copy' : ''} relative`}
      onClick={type === 'copy' ? handleCardClick : undefined}
    >
      
      {/* Glassmorphic Saving Overlay */}
      {isSavingEdits && (
        <div className="pcm-saving-overlay">
          <div className="pcm-saving-pill">
            <span className="pcm-saving-spinner" />
            <span className="pcm-saving-text">Saving edits...</span>
          </div>
        </div>
      )}

      {/* ─── MEDIA CARD LAYOUT ─── */}
      {type === 'media' && (
        <div className="pcm-card-media select-none">
          {isVideo ? (
            <video 
              src={asset.url} 
              controls 
              className="w-full h-full object-cover"
              preload="metadata"
            />
          ) : (
            <img
              src={asset.url}
              alt={asset.name || 'Creative asset preview'}
              onClick={() => setShowLightbox(true)}
              style={{ cursor: 'pointer' }}
            />
          )}

          {/* Video duration badge */}
          {isVideo && asset.duration && <span className="pcm-duration">{asset.duration}</span>}
        </div>
      )}

      {/* ─── AD COPY CARD LAYOUT (Apple Minimalist style) ─── */}
      {type === 'copy' && (
        <div
          className="pcm-copy-body select-none flex flex-col flex-1 min-h-0"
          style={{ cursor: isTeamMember ? 'pointer' : 'default' }}
          role="button"
          tabIndex={0}
        >
          {/* Short label: "Copy 1: [angle]" */}
          <div className="pcm-copy-platform">
            Copy {(copyIndex ?? 0) + 1}: {asset.angleName || asset.type || 'Primary Text'}
          </div>
          
          {isEditingText ? (
            <div className="w-full" onClick={(e) => e.stopPropagation()}>
              <TiptapBodyEditor
                content={editedBody}
                editable={true}
                onChange={setEditedBody}
                placeholder="Write body text..."
                className="pcm-inline-editor"
              />
            </div>
          ) : (
            <p className="pcm-copy-text mb-4">
              {asset.body}
            </p>
          )}

          {/* Minimalist Apple-Style Titel & Beskrivning */}
          {(asset.headline || asset.description || isEditingText) && (
            <div className="pcm-copy-footer" onClick={(e) => e.stopPropagation()}>
              {isEditingText ? (
                <div className="flex flex-col gap-0.5 w-full">
                  {/* Headline — textarea for proper text wrapping (input is single-line only) */}
                  <textarea
                    ref={headlineRef}
                    value={editedHeadline}
                    onChange={(e) => setEditedHeadline(e.target.value)}
                    className="pcm-copy-headline pcm-edit-input"
                    placeholder="Write headline..."
                    rows={1}
                  />
                  {/* Description — textarea for multi-line support matching <p> view mode */}
                  <textarea
                    ref={descriptionRef}
                    value={editedDescription}
                    onChange={(e) => setEditedDescription(e.target.value)}
                    className="pcm-copy-text pcm-edit-input mt-1"
                    placeholder="Write description..."
                    rows={1}
                  />
                </div>
              ) : (
                <div className="flex flex-col gap-0.5 w-full">
                  {asset.headline && (
                    <h4 className="pcm-copy-headline">
                      {asset.headline}
                    </h4>
                  )}
                  {asset.description && (
                    <p className="pcm-copy-text mt-1">
                      {asset.description}
                    </p>
                  )}
                </div>
              )}
            </div>
          )}
        </div>
      )}

      {/* ─── ARTICLE CARD LAYOUT ─── */}
      {type === 'article' && (
        <div
          className="pcm-copy-body select-none flex flex-col flex-1 min-h-0"
          style={{ cursor: 'pointer' }}
          role="button"
          tabIndex={0}
          onClick={() => setShowArticleViewer(true)}
        >
          {/* Thumbnail or icon */}
          {articleThumbnail ? (
            <div className="pcm-card-media select-none" style={{ maxHeight: '160px' }}>
              <img src={articleThumbnail} alt={asset.title || 'Article'} style={{ objectFit: 'cover', width: '100%', height: '100%' }} />
            </div>
          ) : (
            <div style={{
              display: 'flex', alignItems: 'center', justifyContent: 'center',
              height: '80px', background: 'rgba(255,255,255,0.03)', borderRadius: '8px', marginBottom: '0.75rem',
            }}>
              <FileText className="w-8 h-8" style={{ color: 'rgba(255,255,255,0.15)' }} />
            </div>
          )}

          {/* Article label */}
          <div className="pcm-copy-platform">Article</div>

          {/* Title */}
          <h4 className="pcm-copy-headline" style={{ marginBottom: '0.25rem' }}>
            {asset.title || 'Untitled Article'}
          </h4>

          {/* Text snippet */}
          {articleSnippet && (
            <p className="pcm-copy-text" style={{ fontSize: '0.8rem', opacity: 0.6 }}>
              {articleSnippet}
            </p>
          )}

          {/* Click hint */}
          <span style={{ fontSize: '0.7rem', color: 'rgba(255,255,255,0.3)', marginTop: 'auto', paddingTop: '0.5rem' }}>
            Read the full article →
          </span>
        </div>
      )}

      {/* ─── CUSTOM CARD LAYOUT (Notion-style document) ─── */}
      {type === 'custom' && (
        <div
          className="pcm-copy-body select-none flex flex-col flex-1 min-h-0"
          style={{ cursor: 'pointer', position: 'relative' }}
          role="button"
          tabIndex={0}
          onClick={() => setShowArticleViewer(true)}
        >
          {articleThumbnail ? (
            <div className="pcm-card-media select-none" style={{ height: '150px', overflow: 'hidden' }}>
              <img src={articleThumbnail} alt={asset.title || 'Custom document'} style={{ objectFit: 'cover', objectPosition: 'center top', width: '100%', height: '100%' }} />
            </div>
          ) : (
            <div style={{
              display: 'flex', alignItems: 'center', justifyContent: 'center',
              height: '90px', background: 'rgba(128,128,128,0.06)', borderRadius: '10px', marginBottom: '0.75rem',
            }}>
              <FileText className="w-8 h-8" style={{ opacity: 0.25 }} />
            </div>
          )}

          {/* Padded text area — the image above stays full-bleed. */}
          <div style={{ display: 'flex', flexDirection: 'column', flex: 1, minHeight: 0, padding: '14px 16px 14px' }}>
            {/* "Custom" badge — distinguishes this asset type at a glance. */}
            <div
              className="pcm-copy-platform"
              style={{
                display: 'inline-flex', alignItems: 'center', gap: '0.3rem', alignSelf: 'flex-start',
                padding: '0.12rem 0.55rem', borderRadius: '9999px',
                border: '1px solid rgba(128,128,128,0.28)', marginBottom: '0.5rem',
              }}
            >
              <FileText style={{ width: '0.72rem', height: '0.72rem' }} aria-hidden="true" />
              Custom
            </div>

            <h4 className="pcm-copy-headline" style={{ marginBottom: '0.3rem' }}>
              {asset.title || 'Untitled Document'}
            </h4>

            {articleSnippet && (
              <p
                className="pcm-copy-text"
                style={{
                  fontSize: '0.82rem', opacity: 0.7, lineHeight: 1.5,
                  display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden',
                }}
              >
                {articleSnippet}
              </p>
            )}

            <span style={{ fontSize: '0.72rem', opacity: 0.45, marginTop: 'auto', paddingTop: '0.6rem' }}>
              Open document →
            </span>
          </div>

          {/* Persistent draw layer — shown on top of the card preview on the board / review grid. */}
          {asset.overlay && (
            <img
              src={asset.overlay}
              alt=""
              aria-hidden
              style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', pointerEvents: 'none' }}
            />
          )}
        </div>
      )}

      {/* Card actions (shared for media + copy) */}
      <div className="pcm-card-actions select-none">
        <button
          type="button"
          disabled={isSubmitted}
          className="pcm-btn pcm-btn-approve"
          onClick={handleToggleApprove}
        >
          <CheckCircle2 className="w-[13px] h-[13px]" />
          Approve
        </button>
        
        <button
          type="button"
          /* Comments stay open after submit/approval so the client and team can
             keep the conversation going — only approving is locked. */
          className={`pcm-btn pcm-btn-comment ${commentCount > 0 ? 'has-comments' : ''} ${hasNewComment ? 'has-new-comments' : ''}`}
          onClick={() => onOpenComments(asset.id)}
        >
          <span className="pcm-btn-comment-icon-wrapper">
            <MessageSquare className="w-[13px] h-[13px]" />
            {hasNewComment && (
              <span className="pcm-comment-new-dot pulse-blue" />
            )}
          </span>
          Comment
          {commentCount > 0 && (
            <span className={`pcm-comment-count-badge ${hasNewComment ? 'is-new' : ''}`}>
              {commentCount}
            </span>
          )}
        </button>

          {isTeamMember && (
            type === 'media' ? (
              <button
                type="button"
                className="pcm-btn pcm-btn-download"
                onClick={handleDownload}
                title="Download"
                aria-label="Download"
              >
                <Download className="w-[13px] h-[13px]" />
              </button>
            ) : (
              <button
                type="button"
                className="pcm-btn pcm-btn-copy"
                onClick={handleCopyToClipboard}
                title="Copy Text"
                aria-label="Copy Text"
              >
                <Copy className="w-[13px] h-[13px]" />
              </button>
            )
          )}

          <span className="pcm-status">
            <span className="pulse" />
            {isApproved ? 'Approved' : 'Awaiting'}
          </span>
        </div>
      {/* ─── IMAGE LIGHTBOX OVERLAY (portal to body) ─── */}
      {showLightbox && asset.url && createPortal(
        <div
          className="pcm-lightbox"
          onClick={() => setShowLightbox(false)}
          role="dialog"
          aria-label="Image preview"
        >
          <img
            src={asset.url}
            alt={asset.name || 'Full size preview'}
            className="pcm-lightbox-img"
            onClick={(e) => e.stopPropagation()}
          />
          <button
            type="button"
            className="pcm-lightbox-close"
            onClick={() => setShowLightbox(false)}
            aria-label="Close preview"
          >
            <X className="w-5 h-5" />
          </button>
        </div>,
        document.body
      )}

      {/* ─── ARTICLE / CUSTOM DOCUMENT VIEW (full read-only Tiptap) ─── */}
      {showArticleViewer && (type === 'article' || type === 'custom') && (
        <CardDocumentView
          content={asset.content || ''}
          title={asset.title || (type === 'custom' ? 'Untitled Document' : 'Untitled Article')}
          properties={documentProperties}
          meta={documentMeta}
          overlay={type === 'custom' ? asset.overlay : undefined}
          isApproved={isApproved}
          isSubmitted={isSubmitted}
          /* Same gate as the inline copy editor above: the team writes, the
             client reads. Without this the document was hardcoded read-only,
             so its task-list checkboxes rendered and then ignored every click. */
          canEdit={isTeamMember}
          onSave={handleSaveDocument}
          onApprove={handleToggleApprove}
          onClose={() => setShowArticleViewer(false)}
        />
      )}
    </div>
  );
}
