import { memo } from 'react';
import { Download, CheckCircle2 } from 'lucide-react';
import type { MediaSlot } from '../types';
import { tokens } from '@/components/shared';

export interface AdVisualCardProps {
  media: MediaSlot;
  isSelected?: boolean;
  onSelect?: () => void;
  onDownload?: () => void;
}

export const AdVisualCard = memo(function AdVisualCard({
  media,
  isSelected,
  onSelect,
  onDownload,
}: AdVisualCardProps) {
  const { colors, typography, spacing, shadows } = tokens;

  return (
    <div
      onClick={onSelect}
      className={`flex flex-col relative overflow-hidden transition-all duration-200 cursor-pointer ${
        isSelected ? 'ring-2 ring-[#2563eb] ring-offset-2' : ''
      }`}
      style={{
        background: '#fff',
        borderRadius: spacing.radius,
        border: `1px solid ${isSelected ? '#2563eb' : colors.borderLight}`,
        boxShadow: shadows.card,
      }}
    >
      {isSelected && (
        <div className="absolute top-2 left-2 z-10 text-[#2563eb] bg-white rounded-full">
          <CheckCircle2 className="w-5 h-5" />
        </div>
      )}

      {/* ── Image Area ── */}
      <div
        className="w-full relative bg-slate-100 flex items-center justify-center overflow-hidden"
        style={{ aspectRatio: '1 / 1' }}
      >
        {media.status === 'pending' || media.status === 'processing' ? (
          <div className="flex flex-col items-center gap-2">
            <div className="w-5 h-5 rounded-full border-2 border-slate-300 border-t-blue-600 animate-spin" />
            <span
              className="text-xs font-medium animate-pulse"
              style={{ color: colors.textFaint }}
            >
              Generating visual...
            </span>
          </div>
        ) : media.status === 'failed' ? (
          <div className="text-center p-4">
            <span className="text-xs font-semibold text-red-500">Failed</span>
            <p className="text-[10px] text-red-400 mt-1">{media.errorMessage}</p>
          </div>
        ) : (
          <img
            src={media.thumbnailUrl || media.url}
            alt="Ad Visual"
            className="w-full h-full object-cover"
            loading="lazy"
          />
        )}
      </div>

      {/* ── Action Bar ── */}
      <div
        className="px-3 py-2 flex items-center justify-between"
        style={{ borderTop: `1px solid ${colors.bgHover}` }}
      >
        <span
          className="text-[10px] truncate max-w-[150px]"
          style={{ color: colors.textGhost }}
        >
          {media.modelName}
        </span>

        {media.status === 'complete' && media.url && (
          <button
            onClick={(e) => {
              e.stopPropagation(); // Don't trigger selection
              onDownload?.();
            }}
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
  );
});
