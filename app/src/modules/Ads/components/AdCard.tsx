/**
 * AD CARD — Individual ad creative card
 *
 * Displays a composed ad creative: image preview + editable text fields.
 * Follows the same design token system as InlineEditableCard from Copy module.
 *
 * Features:
 * - 16:9 image preview with loading/error states
 * - Inline-editable headline, body, and CTA
 * - Action bar: copy text, download image
 * - Audience/angle badges
 */

import { useState, useCallback, memo } from 'react';
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
  const [editHeadline, setEditHeadline] = useState(text.headline);
  const [editBody, setEditBody] = useState(text.body);
  const [editCta, setEditCta] = useState(text.cta ?? '');

  // ── Start editing ──
  const startEdit = useCallback(() => {
    setEditHeadline(text.headline);
    setEditBody(text.body);
    setEditCta(text.cta ?? '');
    setIsEditing(true);
  }, [text]);

  // ── Save edits ──
  const saveEdit = useCallback(() => {
    if (onTextUpdate) {
      onTextUpdate(creative.id, {
        headline: editHeadline,
        body: editBody,
        cta: editCta || undefined,
      });
    }
    setIsEditing(false);
  }, [creative.id, editHeadline, editBody, editCta, onTextUpdate]);

  // ── Cancel editing ──
  const cancelEdit = useCallback(() => {
    setIsEditing(false);
  }, []);

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
      className="rounded-xl overflow-hidden transition-shadow hover:shadow-md"
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

      {/* ── Content Section ── */}
      <div style={{ padding: '0.875rem 1rem' }}>
        {/* Badges: Audience + Angle */}
        {(text.audienceName || text.angleName) && (
          <div className="flex flex-wrap gap-1.5 mb-2">
            {text.audienceName && (
              <span
                className="flex items-center gap-1 text-[10px] font-medium px-2 py-0.5 rounded-full"
                style={{ background: '#dbeafe', color: '#1d4ed8' }}
              >
                <Target className="w-3 h-3" /> {text.audienceName}
              </span>
            )}
            {text.angleName && (
              <span
                className="flex items-center gap-1 text-[10px] font-medium px-2 py-0.5 rounded-full"
                style={{ background: '#fef3c7', color: '#92400e' }}
              >
                <Zap className="w-3 h-3" /> {text.angleName}
              </span>
            )}
          </div>
        )}

        {/* Text fields — view or edit mode */}
        {isEditing ? (
          <div className="space-y-2">
            {/* Headline edit */}
            <input
              type="text"
              value={editHeadline}
              onChange={(e) => setEditHeadline(e.target.value)}
              className="w-full text-sm font-semibold rounded border px-2 py-1 focus:outline-none focus:ring-1 focus:ring-blue-400"
              style={{ borderColor: colors.border, color: colors.text }}
              placeholder="Headline"
              autoFocus
            />
            {/* Body edit */}
            <textarea
              value={editBody}
              onChange={(e) => setEditBody(e.target.value)}
              className="w-full text-xs rounded border px-2 py-1 resize-none focus:outline-none focus:ring-1 focus:ring-blue-400"
              style={{
                borderColor: colors.border,
                color: colors.textSecondary,
                minHeight: '60px',
              }}
              placeholder="Body text"
            />
            {/* CTA edit */}
            <input
              type="text"
              value={editCta}
              onChange={(e) => setEditCta(e.target.value)}
              className="w-full text-xs font-medium rounded border px-2 py-1 focus:outline-none focus:ring-1 focus:ring-blue-400"
              style={{ borderColor: colors.border, color: '#2563eb' }}
              placeholder="Call to action (optional)"
            />
            {/* Edit actions */}
            <div className="flex gap-1.5 justify-end pt-1">
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
          </div>
        ) : (
          <div>
            {/* Headline */}
            {text.headline && (
              <h4
                className="font-semibold mb-1 leading-tight"
                style={{ color: colors.text, fontSize: typography.sm }}
              >
                {text.headline}
              </h4>
            )}
            {/* Body */}
            {text.body && (
              <p
                className="mb-2 line-clamp-3 leading-relaxed"
                style={{ color: colors.textSecondary, fontSize: typography.xs }}
              >
                {text.body}
              </p>
            )}
            {/* CTA */}
            {text.cta && (
              <span
                className="inline-block text-xs font-medium px-3 py-1 rounded-full mb-2"
                style={{ background: '#eff6ff', color: '#2563eb' }}
              >
                {text.cta}
              </span>
            )}
          </div>
        )}

        {/* ── Action Bar ── */}
        <div
          className="flex items-center justify-between pt-2 mt-2"
          style={{ borderTop: `1px solid ${colors.bgHover}` }}
        >
          {/* Model info */}
          <div className="flex items-center gap-1.5">
            <span
              className="text-[10px] truncate max-w-[100px]"
              style={{ color: colors.textGhost }}
            >
              {media.modelName}
            </span>
          </div>

          {/* Actions */}
          <div className="flex items-center gap-1">
            {/* Edit text */}
            {!isEditing && (
              <button
                onClick={startEdit}
                className="p-1.5 rounded-md transition-colors"
                style={{ color: colors.textFaint }}
                onMouseEnter={(e) => (e.currentTarget.style.background = colors.bgHover)}
                onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
                title="Edit text"
              >
                <Pencil className="w-3.5 h-3.5" />
              </button>
            )}
            {/* Copy text */}
            <button
              onClick={copyText}
              className="p-1.5 rounded-md transition-colors"
              style={{ color: colors.textFaint }}
              onMouseEnter={(e) => (e.currentTarget.style.background = colors.bgHover)}
              onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
              title="Copy text"
            >
              <Copy className="w-3.5 h-3.5" />
            </button>
            {/* Download image */}
            {media.status === 'complete' && media.url && (
              <button
                onClick={downloadImage}
                className="p-1.5 rounded-md transition-colors"
                style={{ color: colors.textFaint }}
                onMouseEnter={(e) => (e.currentTarget.style.background = colors.bgHover)}
                onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
                title="Download image"
              >
                <Download className="w-3.5 h-3.5" />
              </button>
            )}
          </div>
        </div>
      </div>
    </div>
  );
});
