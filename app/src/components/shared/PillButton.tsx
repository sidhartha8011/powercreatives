/**
 * PILL BUTTON
 *
 * The foundational interactive element of Creative Machine.
 * Mirrors the sidebar nav-item style: pill shape, light tints, thin icons.
 *
 * Variants:
 *   active  — blue bg (#e7f5ff), blue text (#007bff), semibold
 *   default — transparent bg, muted text (#555), medium weight
 *   subtle  — transparent bg, faint text (#888), regular weight
 *
 * Usage:
 *   <PillButton onClick={fn} icon={<PenLine />}>Generate</PillButton>
 *   <PillButton variant="active" icon={<Check />}>Selected</PillButton>
 */

import { useState } from 'react';
import { Loader2 } from 'lucide-react';
import { colors, typography, spacing } from './design-tokens';

export type PillButtonVariant = 'active' | 'default' | 'subtle';

interface PillButtonProps {
  children: React.ReactNode;
  onClick?: () => void;
  icon?: React.ReactNode;
  variant?: PillButtonVariant;
  loading?: boolean;
  disabled?: boolean;
  className?: string;
  /** Optional trailing element (badge, count, chevron) */
  trailing?: React.ReactNode;
}

const variantStyles: Record<
  PillButtonVariant,
  { bg: string; color: string; iconColor: string; weight: number; hoverBg: string; hoverColor: string }
> = {
  active: {
    bg: colors.primaryLight,
    color: colors.primary,
    iconColor: colors.primary,
    weight: typography.semibold,
    hoverBg: colors.primaryLight,
    hoverColor: colors.primary,
  },
  default: {
    bg: 'transparent',
    color: '#555',
    iconColor: colors.textMuted,
    weight: typography.medium,
    hoverBg: colors.bgHover,
    hoverColor: '#333',
  },
  subtle: {
    bg: 'transparent',
    color: colors.textMuted,
    iconColor: colors.textFaint,
    weight: typography.regular,
    hoverBg: colors.bgHover,
    hoverColor: '#555',
  },
};

export function PillButton({
  children,
  onClick,
  icon,
  variant = 'default',
  loading = false,
  disabled = false,
  className = '',
  trailing,
}: PillButtonProps) {
  const [hovered, setHovered] = useState(false);
  const v = variantStyles[variant];
  const isDisabled = disabled || loading;

  const bg = hovered && !isDisabled && variant !== 'active' ? v.hoverBg : v.bg;
  const color = hovered && !isDisabled && variant !== 'active' ? v.hoverColor : v.color;

  return (
    <button
      onClick={onClick}
      disabled={isDisabled}
      onMouseEnter={() => setHovered(true)}
      onMouseLeave={() => setHovered(false)}
      className={`flex items-center shrink-0 ${className}`}
      style={{
        padding: '4px 10px',
        borderRadius: spacing.radiusPill,
        fontSize: typography.xs,
        fontWeight: v.weight,
        background: bg,
        color,
        border: '1px solid transparent',
        gap: spacing.gap,
        transition: 'background-color 0.2s, color 0.2s',
        opacity: isDisabled ? 0.5 : 1,
        cursor: isDisabled ? 'not-allowed' : 'pointer',
      }}
    >
      {loading ? (
        <Loader2
          style={{ width: spacing.iconSm, height: spacing.iconSm, color: v.iconColor }}
          className="animate-spin"
        />
      ) : icon ? (
        <span
          style={{
            color: v.iconColor,
            display: 'flex',
            alignItems: 'center',
            width: spacing.iconSm,
            height: spacing.iconSm,
          }}
          className="[&>svg]:w-full [&>svg]:h-full"
        >
          {icon}
        </span>
      ) : null}
      {children}
      {trailing}
    </button>
  );
}
