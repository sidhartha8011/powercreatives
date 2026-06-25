import { useState, useCallback, useEffect, useRef, useMemo } from 'react';
import { createPortal } from 'react-dom';
import { CheckCircle2, MessageSquare, Check, X, Video, Image as ImageIcon, Download, Copy, FileText } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { toast } from 'sonner';
import { isVideoAsset } from './ClientReviewPage';
import { TiptapBodyEditor } from '@/components/shared/TiptapBodyEditor';
import { useAutoResizeTextarea } from '@/hooks/useAutoResizeTextarea';
import { trpc } from '@/lib/trpc';
import { useEditor, EditorContent } from '@tiptap/react';
import { getEditorExtensions } from '@/components/shared/editorExtensions';

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
  [key: string]: unknown;
}

/** Read-only article viewer using the full shared Tiptap extension set */
function ArticleViewerDialog({ content, title, metaTitle, metaDescription, onClose }: {
  content: string;
  title: string;
  metaTitle?: string;
  metaDescription?: string;
  onClose: () => void;
}) {
  const editor = useEditor({
    extensions: getEditorExtensions({ placeholder: '' }),
    content: content || '<p></p>',
    editable: false,
    editorProps: {
      attributes: { class: 'outline-none prose prose-sm max-w-none' },
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

  return createPortal(
    <div
      className="pcm-lightbox"
      onClick={onClose}
      role="dialog"
      aria-label="Article preview"
      style={{ alignItems: 'flex-start', paddingTop: '3vh' }}
    >
      <div
        className="pcm-article-viewer"
        onClick={(e) => e.stopPropagation()}
        style={{
          background: '#111214',
          borderRadius: '16px',
          maxWidth: '780px',
          width: '90vw',
          maxHeight: '90vh',
          overflow: 'auto',
          padding: '2.5rem 2rem',
          position: 'relative',
          border: '1px solid rgba(255,255,255,0.06)',
          boxShadow: '0 40px 80px rgba(0,0,0,0.6)',
        }}
      >
        <button
          type="button"
          className="pcm-lightbox-close"
          onClick={onClose}
          aria-label="Close preview"
          style={{ position: 'absolute', top: '1rem', right: '1rem' }}
        >
          <X className="w-5 h-5" />
        </button>

        {/* Article title */}
        <h1 style={{
          fontSize: '1.65rem',
          fontWeight: 700,
          color: '#f0f0f0',
          lineHeight: 1.3,
          marginBottom: '0.5rem',
        }}>{title}</h1>

        {/* Meta info bar */}
        {(metaTitle || metaDescription) && (
          <div style={{
            fontSize: '0.75rem',
            color: 'rgba(255,255,255,0.4)',
            marginBottom: '1.5rem',
            borderBottom: '1px solid rgba(255,255,255,0.06)',
            paddingBottom: '1rem',
          }}>
            {metaTitle && <div><strong>Meta Title:</strong> {metaTitle}</div>}
            {metaDescription && <div style={{ marginTop: '0.25rem' }}><strong>Meta Description:</strong> {metaDescription}</div>}
          </div>
        )}

        {/* Tiptap read-only rendered content */}
        <div className="pcm-article-content" style={{ color: '#d4d4d4', lineHeight: 1.7, fontSize: '0.95rem' }}>
          {editor && <EditorContent editor={editor} />}
        </div>
      </div>
    </div>,
    document.body
  );
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
  pairedMediaUrl?: string | null;
  isSubmitted?: boolean;
  isTeamMember?: boolean;
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
  pairedMediaUrl,
  isSubmitted = false,
  isTeamMember = false,
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
    // Retrieve token from query string
    const token = new URLSearchParams(window.location.search).get('pcm_public_token') || '';

    updateMutation.mutate(
      {
        token,
        assetId: asset.id,
        headline: editedHeadline,
        body: editedBody,
        description: editedDescription
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
  }, [editedHeadline, editedBody, editedDescription, asset.headline, asset.body, asset.description, asset.id, onAssetUpdate, updateMutation]);

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
                placeholder="Skriv brödtext..."
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
                    placeholder="Skriv rubrik..."
                    rows={1}
                  />
                  {/* Description — textarea for multi-line support matching <p> view mode */}
                  <textarea
                    ref={descriptionRef}
                    value={editedDescription}
                    onChange={(e) => setEditedDescription(e.target.value)}
                    className="pcm-copy-text pcm-edit-input mt-1"
                    placeholder="Skriv beskrivning..."
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
            Klicka för att läsa hela artikeln →
          </span>
        </div>
      )}

      {/* ─── CUSTOM CARD LAYOUT (Notion-style document) ─── */}
      {type === 'custom' && (
        <div
          className="pcm-copy-body select-none flex flex-col flex-1 min-h-0"
          style={{ cursor: 'pointer' }}
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

      {/* ─── ARTICLE / CUSTOM VIEWER DIALOG (full read-only Tiptap) ─── */}
      {showArticleViewer && (type === 'article' || type === 'custom') && (
        <ArticleViewerDialog
          content={asset.content || ''}
          title={asset.title || (type === 'custom' ? 'Untitled Document' : 'Untitled Article')}
          metaTitle={asset.metaTitle}
          metaDescription={asset.metaDescription}
          onClose={() => setShowArticleViewer(false)}
        />
      )}
    </div>
  );
}
