import { useState, useCallback, useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import { CheckCircle2, MessageSquare, Check, X, Video, Image as ImageIcon, Download, Copy, Info } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { toast } from 'sonner';
import { isVideoAsset } from './ClientReviewPage';
import { TiptapBodyEditor } from '@/components/shared/TiptapBodyEditor';
import { trpc } from '@/lib/trpc';

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
  [key: string]: unknown;
}

interface CreativeAssetCardProps {
  asset: CreativeAsset;
  type: 'media' | 'copy';
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
  onOpenComments
}: CreativeAssetCardProps) {
  const [isExpanded, setIsExpanded] = useState(false);
  const [showLightbox, setShowLightbox] = useState(false);

  // Text Inline Edits state
  const [isEditingText, setIsEditingText] = useState(false);
  const [editedHeadline, setEditedHeadline] = useState(asset.headline || '');
  const [editedBody, setEditedBody] = useState(asset.body || '');
  const [editedDescription, setEditedDescription] = useState(asset.description || '');
  const [isSavingEdits, setIsSavingEdits] = useState(false);

  const containerRef = useRef<HTMLDivElement>(null);

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
    const textToCopy = asset.headline ? `${asset.headline}\n\n` + asset.body : (asset.body || '');
    
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

  const handleCardClick = useCallback(() => {
    if (!isEditingText) {
      setIsExpanded((prev) => !prev);
    }
  }, [isEditingText]);

  const handleDoubleClick = useCallback((e: React.MouseEvent) => {
    if (isTeamMember && !isEditingText && !isSubmitted) {
      e.stopPropagation();
      setIsEditingText(true);
    }
  }, [isTeamMember, isEditingText, isSubmitted]);

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
      className={`pcm-card ${isApproved ? 'approved' : ''} ${type === 'copy' ? 'copy' : ''} ${isExpanded ? 'expanded' : ''} relative`}
      onClick={type === 'copy' ? handleCardClick : undefined}
      onDoubleClick={type === 'copy' ? handleDoubleClick : undefined}
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
          style={{ cursor: isTeamMember ? 'pointer' : 'pointer' }}
          role="button"
          tabIndex={0}
          aria-expanded={isExpanded}
        >
          {/* Audience/angle metadata — shown as tooltip on hover for clean reading */}
          <div
            className="pcm-copy-info-trigger"
            data-tooltip={`${asset.platform || asset.audienceName || 'Ad Copy'} · ${asset.type || asset.angleName || 'Primary Text'}`}
            aria-label={`${asset.platform || asset.audienceName || 'Ad Copy'} · ${asset.type || asset.angleName || 'Primary Text'}`}
          >
            <Info className="w-3.5 h-3.5" />
          </div>
          
          {isEditingText ? (
            <div className="w-full mt-2" onClick={(e) => e.stopPropagation()}>
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
                <div className="flex flex-col gap-1 w-full">
                  <input
                    type="text"
                    value={editedHeadline}
                    onChange={(e) => setEditedHeadline(e.target.value)}
                    className="pcm-copy-headline pcm-edit-input"
                    placeholder="Skriv rubrik..."
                  />
                  <input
                    type="text"
                    value={editedDescription}
                    onChange={(e) => setEditedDescription(e.target.value)}
                    className="pcm-copy-text pcm-edit-input" style={{ marginTop: '4px' }}
                    placeholder="Skriv beskrivning..."
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
          disabled={isSubmitted}
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
    </div>
  );
}
