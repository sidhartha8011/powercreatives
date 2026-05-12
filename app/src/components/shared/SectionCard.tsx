/**
 * SECTION CARD
 *
 * Consistent card wrapper used across all modules.
 * PowerKeys style: white surface, subtle border, 8px radius, light shadow.
 *
 * Usage:
 *   <SectionCard>
 *     <SectionLabel>Audiences</SectionLabel>
 *     ...content...
 *   </SectionCard>
 */

import { colors, spacing, shadows } from './design-tokens';

interface SectionCardProps {
  children: React.ReactNode;
  /** Use 'surface' for white cards, 'muted' for recessed panels */
  variant?: 'surface' | 'muted';
  className?: string;
  noPadding?: boolean;
}

export function SectionCard({
  children,
  variant = 'muted',
  className = '',
  noPadding = false,
}: SectionCardProps) {
  return (
    <div
      className={`rounded-lg border ${className}`}
      style={{
        borderColor: colors.border,
        background: variant === 'surface' ? colors.bgSurface : colors.bgMuted,
        padding: noPadding ? 0 : spacing.cardPadding,
        boxShadow: variant === 'surface' ? shadows.card : 'none',
      }}
    >
      {children}
    </div>
  );
}
