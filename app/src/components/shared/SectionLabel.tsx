/**
 * SECTION LABEL
 *
 * Tiny uppercase label used for section headers across all modules.
 * PowerKeys style: 0.625rem, semibold, uppercase, tracking-wide, muted color.
 *
 * Usage:
 *   <SectionLabel>Audiences</SectionLabel>
 *   <SectionLabel count={3}>Angles</SectionLabel>
 */

import { colors, typography } from './design-tokens';

interface SectionLabelProps {
  children: React.ReactNode;
  /** Optional count badge shown after the label */
  count?: number;
  className?: string;
}

export function SectionLabel({ children, count, className = '' }: SectionLabelProps) {
  return (
    <span
      className={`flex items-center gap-1.5 ${className}`}
      style={{
        fontSize: '0.65rem',
        fontWeight: typography.medium,
        color: colors.textMuted,
        textTransform: 'uppercase',
        letterSpacing: '0.05em',
      }}
    >
      {children}
      {count !== undefined && (
        <span
          style={{
            fontSize: typography.micro,
            fontWeight: typography.medium,
            color: colors.textFaint,
            background: colors.bgHover,
            padding: '1px 6px',
            borderRadius: '999px',
          }}
        >
          {count}
        </span>
      )}
    </span>
  );
}
