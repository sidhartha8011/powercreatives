/**
 * AD CARD — Individual ad creative card
 *
 * Displays a composed ad creative: image preview + editable text fields.
 * Follows the same design token system as InlineEditableCard from Copy module.
 *
 * Features:
 * - 16:9 image preview with loading/error states
 * - Inline-editable headline, body, and CTA using contentEditable
 * - Action bar: copy text, download image
 * - Audience/angle badges
 */

import { useState, useCallback, useRef, useEffect, memo } from 'react';
import {
  ImageIcon,
  Copy,
  Download,
  Pencil,
  Check,
  X,
  AlertCircle,
  Loader2,
  Video,
  Target,
  Zap,
} from 'lucide-react';
import { toast } from 'sonner';
import { colors, typography, spacing } from '@/components/shared';
import type { AdCreative } from '../types';

// ============================================================================
// Props
// ============================================================================

interface AdCardProps {
  /** The ad creative to render */
  creative: AdCreative;
  /** Called when text fields are edited */
  onTextUpdate?: (
    creativeId: string,
    updates: { headline?: string; body?: string; cta?: string },
  ) => void;
  /** Called when the image is clicked (e.g., open detail view) */
  onImageClick?: (creative: AdCreative) => void;
}

// ============================================================================
// Component
// ============================================================================

export const AdCard = memo(function AdCard({
  creative,
  onTextUpdate,
  onImageClick,
}: AdCardProps) {
  const { media, text } = creative;

  // ── Inline edit state ──
  const [isEditing, setIsEditing] = useState(false);

  // Refs for in-place contentEditable fields
  const headlineRef = useRef<HTMLHeadingElement>(null);
  const bodyRef = useRef<HTMLParagraphElement>(null);
  const ctaRef = useRef<HTMLSpanElement>(null);

  // Sync DOM content from variation when in read mode
  useEffect(() => {
    if (!isEditing) {
      if (headlineRef.current) headlineRef.current.innerText = text.headline ?? '';
      if (bodyRef.current) bodyRef.current.innerText = text.body ?? '';
      if (ctaRef.current) ctaRef.current.innerText = text.cta ?? '';
    }
  }, [text, isEditing]);

  // ── Start editing ──
  const startEdit = useCallback(() => {
    setIsEditing(true);
  }, []);

  // ── Save edits ──
  const saveEdit = useCallback(() => {
    const newHeadline = headlineRef.current?.innerText?.trim() ?? text.headline;
    const newBody = bodyRef.current?.innerText ?? text.body;
    const newCta = ctaRef.current?.innerText?.trim() ?? text.cta ?? '';

    if (onTextUpdate) {
      onTextUpdate(creative.id, {
        headline: newHeadline,
        body: newBody,
        cta: newCta || undefined,
      });
    }
    setIsEditing(false);
  }, [creative.id, text, onTextUpdate]);

  // ── Cancel editing ──
  const cancelEdit = useCallback(() => {
    // Reset DOM content to original text values
    if (headlineRef.current) headlineRef.current.innerText = text.headline ?? '';
    if (bodyRef.current) bodyRef.current.innerText = text.body ?? '';
    if (ctaRef.current) ctaRef.current.innerText = text.cta ?? '';
    setIsEditing(false);
  }, [text]);

  // ── Copy all text to clipboard ──
  const copyText = useCallback(() => {
    const parts = [text.headline, text.body, text.cta].filter(Boolean);
    navigator.clipboard.writeText(parts.join('\n\n'));
    toast.success('Text copied to clipboard');
  }, [text]);

  // ── Download image (fetch→blob for cross-origin compatibility) ──
  const downloadImage = useCallback(async () => {
    if (!media.url) return;
    try {
      const response = await fetch(media.url);
      const blob = await response.blob();
      const blobUrl = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = blobUrl;
      link.download = `ad-${creative.id}.png`;
      link.click();
      URL.revokeObjectURL(blobUrl);
      toast.success('Downloading image');
    } catch {
      // Fallback: open in new tab if fetch fails (e.g. CORS)
      window.open(media.url, '_blank');
      toast.info('Opened image in new tab');
    }
  }, [media.url, creative.id]);

  return (
    <div
      className={`rounded-xl overflow-hidden transition-all duration-200 ${
        isEditing ? 'ring-2 ring-amber-400/40 shadow-md' : 'hover:shadow-md'
      }`}
      style={{
        background: colors.bgSurface,
        border: `1px solid ${colors.borderLight}`,
      }}
    >
      {/* ── Image Preview (16:9 aspect ratio) ── */}
      <div
        className="relative w-full cursor-pointer group"
        style={{ aspectRatio: '16/9', background: colors.bgMuted }}
        onClick={() => onImageClick?.(creative)}
      >
        {/* Loading state */}
        {media.status === 'processing' && (
          <div className="absolute inset-0 flex items-center justify-center">
            <Loader2
              className="w-8 h-8 animate-spin"
              style={{ color: colors.textFaint }}
            />
          </div>
        )}

        {/* Error state */}
        {media.status === 'failed' && (
          <div className="absolute inset-0 flex flex-col items-center justify-center gap-2">
            <AlertCircle className="w-6 h-6" style={{ color: '#ef4444' }} />
            <span className="text-xs text-center px-4" style={{ color: colors.textFaint }}>
              {media.errorMessage || 'Image generation failed'}
            </span>
          </div>
        )}

        {/* Pending state */}
        {media.status === 'pending' && (
          <div className="absolute inset-0 flex items-center justify-center">
            {media.type === 'video' ? (
              <Video className="w-8 h-8" style={{ color: colors.textGhost }} />
            ) : (
              <ImageIcon className="w-8 h-8" style={{ color: colors.textGhost }} />
            )}
          </div>
        )}

        {/* Completed image */}
        {media.status === 'complete' && media.url && (
          <img
            src={media.url}
            alt={text.headline || 'Generated ad image'}
            className="w-full h-full object-cover transition-transform group-hover:scale-[1.02]"
            loading="lazy"
          />
        )}

        {/* Media type badge */}
        {media.type === 'video' && (
          <div
            className="absolute top-2 right-2 flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold"
            style={{ background: 'rgba(0,0,0,0.6)', color: '#fff' }}
          >
            <Video className="w-3 h-3" />
            Video
          </div>
        )}
      </div>

      {/* ── Content Section (The Canvas) ── */}
      <div style={{ padding: '1.25rem' }}>
        
        {/* WYSIWYG Editable Text */}
        <div className="mb-2">
          {/* Headline */}
          {(text.headline || isEditing) && (
            <h4
              ref={headlineRef}
              contentEditable={isEditing || undefined}
              suppressContentEditableWarning
              className="font-semibold mb-2 leading-snug"
              style={{
                color: colors.text,
                fontSize: typography.base,
                outline: 'none',
                cursor: isEditing ? 'text' : 'default',
                minHeight: isEditing ? '1.25em' : undefined,
              }}
            >
              {text.headline}
            </h4>
          )}
          
          {/* Body */}
          {(text.body || isEditing) && (
            <p
              ref={bodyRef}
              contentEditable={isEditing || undefined}
              suppressContentEditableWarning
              className="mb-3 leading-relaxed whitespace-pre-line"
              style={{
                color: colors.textSecondary,
                fontSize: typography.sm,
                outline: 'none',
                cursor: isEditing ? 'text' : 'default',
                minHeight: isEditing ? '1.25em' : undefined,
              }}
            >
              {text.body}
            </p>
          )}
          
          {/* CTA */}
          {(text.cta || isEditing) && (
            <span
              ref={ctaRef}
              contentEditable={isEditing || undefined}
              suppressContentEditableWarning
              className="inline-block text-sm font-semibold mb-1"
              style={{
                color: '#2563eb', // Trustworthy blue
                outline: 'none',
                cursor: isEditing ? 'text' : 'default',
                minHeight: isEditing ? '1.25em' : undefined,
                minWidth: isEditing ? '60px' : undefined,
              }}
            >
              {text.cta}
            </span>
          )}
        </div>

        {/* Save/Cancel buttons when editing */}
        {isEditing && (
          <div className="flex gap-1.5 justify-end pt-1 mb-2">
            <button
              onClick={cancelEdit}
              className="flex items-center gap-1 text-[11px] px-2 py-1 rounded-md transition-colors"
              style={{ color: colors.textFaint, background: colors.bgHover }}
            >
              <X className="w-3 h-3" /> Cancel
            </button>
            <button
              onClick={saveEdit}
              className="flex items-center gap-1 text-[11px] px-2 py-1 rounded-md transition-colors"
              style={{ color: '#fff', background: '#2563eb' }}
            >
              <Check className="w-3 h-3" /> Save
            </button>
          </div>
        )}

      </div>

      {/* ── The Chrome (Metadata & Toolbar Footer) ── */}
      <div 
        className="px-4 py-3 flex flex-col gap-2"
        style={{ 
          background: '#f8fafc', // slate-50
          borderTop: `1px solid ${colors.borderLight}` 
        }}
      >
        {/* Context Row (Audience & Angle) */}
        {(text.audienceName || text.angleName) && (
          <div className="flex items-center gap-4 w-full">
            {text.audienceName && (
              <div className="flex items-center gap-1.5 flex-1 min-w-0" title={text.audienceName}>
                <Target className="w-3.5 h-3.5 shrink-0" style={{ color: colors.textGhost }} />
                <span className="text-[11px] truncate font-medium" style={{ color: colors.textFaint }}>
                  {text.audienceName}
                </span>
              </div>
            )}
            {text.angleName && (
              <div className="flex items-center gap-1.5 flex-1 min-w-0" title={text.angleName}>
                <Zap className="w-3.5 h-3.5 shrink-0" style={{ color: colors.textGhost }} />
                <span className="text-[11px] truncate font-medium" style={{ color: colors.textFaint }}>
                  {text.angleName}
                </span>
              </div>
            )}
          </div>
        )}

        {/* Action Row */}
        <div className="flex items-center justify-between pt-1">
          {/* Model info */}
          <div className="flex items-center gap-1.5">
            <span
              className="text-[10px] uppercase tracking-wider font-semibold"
              style={{ color: colors.textGhost }}
            >
              {media.modelName}
            </span>
          </div>

          {/* Actions */}
          <div className="flex items-center gap-0.5">
            {/* Edit text */}
            {!isEditing && (
              <button
                onClick={startEdit}
                className="p-1.5 rounded-md transition-colors"
                style={{ color: colors.textFaint }}
                onMouseEnter={(e) => (e.currentTarget.style.background = '#e2e8f0')} // slate-200 hover
                onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
                title="Edit text"
              >
                <Pencil className="w-4 h-4" />
              </button>
            )}
            {/* Copy text */}
            <button
              onClick={copyText}
              className="p-1.5 rounded-md transition-colors"
              style={{ color: colors.textFaint }}
              onMouseEnter={(e) => (e.currentTarget.style.background = '#e2e8f0')}
              onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
              title="Copy text"
            >
              <Copy className="w-4 h-4" />
            </button>
            {/* Download image */}
            {media.status === 'complete' && media.url && (
              <button
                onClick={downloadImage}
                className="p-1.5 rounded-md transition-colors"
                style={{ color: colors.textFaint }}
                onMouseEnter={(e) => (e.currentTarget.style.background = '#e2e8f0')}
                onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
                title="Download image"
              >
                <Download className="w-4 h-4" />
              </button>
            )}
          </div>
        </div>
      </div>
    </div>
  );
});
