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

      {/* ── Meta Ad Anatomy ── */}
      <div className="flex flex-col flex-1">
        
        {/* 1. Primary Text (Body) - Placed at the top */}
        {text.body && (
          <div className="px-4 py-3 pb-2">
            <p
              className="line-clamp-4 leading-relaxed whitespace-pre-line"
              style={{
                color: colors.text,
                fontSize: typography.sm,
              }}
            >
              {text.body}
            </p>
          </div>
        )}

        {/* 2. Link Preview Box (Grey background) - Placed below */}
        <div 
          className="mt-auto border-t border-b"
          style={{ 
            backgroundColor: '#f0f2f5', 
            borderColor: colors.borderLight,
            padding: '10px 16px' 
          }}
        >
          <div className="flex items-center justify-between gap-4">
            <div className="flex-1 min-w-0">
              <p 
                className="text-[10px] uppercase font-semibold mb-0.5 truncate"
                style={{ color: colors.textFaint }}
              >
                POWERCREATIVES.COM
              </p>
              {text.headline && (
                <h4
                  className="font-bold leading-tight truncate"
                  style={{
                    color: colors.text,
                    fontSize: typography.body,
                  }}
                >
                  {text.headline}
                </h4>
              )}
            </div>
            
            {text.cta && (
              <span
                className="shrink-0 inline-flex items-center justify-center font-semibold px-3 py-1.5 rounded"
                style={{
                  backgroundColor: '#e4e6eb',
                  color: '#050505',
                  fontSize: typography.xs,
                  border: '1px solid #ccd0d5'
                }}
              >
                {text.cta}
              </span>
            )}
          </div>
        </div>

        {/* ── Context Footer (Audience & Angle) ── */}
        {(text.audienceName || text.angleName) && (
          <div className="px-4 py-2.5 flex items-center gap-3" style={{ backgroundColor: '#fff', borderBottomLeftRadius: spacing.radius, borderBottomRightRadius: spacing.radius }}>
            {text.audienceName && (
              <span className="flex items-center gap-1.5 text-[10px] font-medium" style={{ color: colors.textFaint }}>
                <Target className="w-3.5 h-3.5 shrink-0" /> <span className="truncate max-w-[120px]">{text.audienceName}</span>
              </span>
            )}
            {text.angleName && (
              <span className="flex items-center gap-1.5 text-[10px] font-medium" style={{ color: colors.textFaint }}>
                <Zap className="w-3.5 h-3.5 shrink-0" /> <span className="truncate max-w-[120px]">{text.angleName}</span>
              </span>
            )}
          </div>
        )}
      </div>
    </div>
  );
});
