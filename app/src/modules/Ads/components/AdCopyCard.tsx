import { memo } from 'react';
import { Target, Zap, CheckCircle2 } from 'lucide-react';
import type { TextSlot } from '../types';
import { tokens } from '@/components/shared';

export interface AdCopyCardProps {
  text: TextSlot;
  isSelected?: boolean;
  onSelect?: () => void;
}

export const AdCopyCard = memo(function AdCopyCard({
  text,
  isSelected,
  onSelect,
}: AdCopyCardProps) {
  const { colors, typography, spacing, shadows } = tokens;

  return (
    <div
      onClick={onSelect}
      className={`flex flex-col relative transition-all duration-200 cursor-pointer ${
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
        <div className="absolute top-2 right-2 z-10 text-[#2563eb] bg-white rounded-full">
          <CheckCircle2 className="w-5 h-5" />
        </div>
      )}

      {/* ── Content Section ── */}
      <div style={{ padding: '0.875rem 1rem' }}>
        
        {/* Text Area */}
        <div className="mb-2">
          {text.headline && (
            <h4
              className="font-semibold mb-1 leading-tight"
              style={{
                color: colors.text,
                fontSize: typography.sm,
              }}
            >
              {text.headline}
            </h4>
          )}
          
          {text.body && (
            <p
              className="mb-2 line-clamp-3 leading-relaxed whitespace-pre-line"
              style={{
                color: colors.textSecondary,
                fontSize: typography.xs,
              }}
            >
              {text.body}
            </p>
          )}
          
          {text.cta && (
            <span
              className="inline-block text-xs font-medium px-3 py-1 rounded-full mb-2"
              style={{
                background: '#eff6ff',
                color: '#2563eb',
              }}
            >
              {text.cta}
            </span>
          )}
        </div>

        {/* ── Context Footer (Audience & Angle) ── */}
        {(text.audienceName || text.angleName) && (
          <div className="flex items-center gap-3 mt-1 pt-2 border-t border-slate-100">
            {text.audienceName && (
              <span className="flex items-center gap-1 text-[10px]" style={{ color: colors.textFaint }}>
                <Target className="w-3 h-3 shrink-0" /> <span className="truncate max-w-[120px]">{text.audienceName}</span>
              </span>
            )}
            {text.angleName && (
              <span className="flex items-center gap-1 text-[10px]" style={{ color: colors.textFaint }}>
                <Zap className="w-3 h-3 shrink-0" /> <span className="truncate max-w-[120px]">{text.angleName}</span>
              </span>
            )}
          </div>
        )}
      </div>
    </div>
  );
});
