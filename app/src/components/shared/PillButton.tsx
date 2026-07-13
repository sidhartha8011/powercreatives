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
  // Rest = gray; hover = blue (PO 2026-07-08) — hover previews the pressed
  // ('active') palette.
  default: {
    bg: 'transparent',
    color: '#555',
    iconColor: colors.textMuted,
    weight: typography.medium,
    hoverBg: colors.primaryLight,
    hoverColor: colors.primary,
  },
  subtle: {
    bg: 'transparent',
    color: colors.textMuted,
    iconColor: colors.textFaint,
    weight: typography.regular,
    hoverBg: colors.primaryLight,
    hoverColor: colors.primary,
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

  const isHovering = hovered && !isDisabled && variant !== 'active';
  const bg = isHovering ? v.hoverBg : v.bg;
  const color = isHovering ? v.hoverColor : v.color;
  // Icon follows the text on hover so the whole pill reads blue at once.
  const iconColor = isHovering ? v.hoverColor : v.iconColor;

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
          style={{ width: spacing.iconSm, height: spacing.iconSm, color: iconColor }}
          className="animate-spin"
        />
      ) : icon ? (
        <span
          style={{
            color: iconColor,
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

/**
 * PILL SPLIT BUTTON — the shared blue pill with a dropdown zone on the right
 * (owner order 2026-07-13: the Generate pill, split — main zone runs the
 * action, the caret zone opens its options). Same tokens as PillButton's
 * 'active' variant so every generate-class action looks identical.
 */
interface PillSplitButtonProps {
  children: React.ReactNode;
  icon?: React.ReactNode;
  onClick?: () => void;
  onCaretClick?: () => void;
  caretActive?: boolean;
  loading?: boolean;
  disabled?: boolean;
  title?: string;
  caretTitle?: string;
}

export function PillSplitButton({
  children,
  icon,
  onClick,
  onCaretClick,
  caretActive = false,
  loading = false,
  disabled = false,
  title,
  caretTitle,
}: PillSplitButtonProps) {
  const isDisabled = disabled || loading;
  const zone = {
    background: 'transparent',
    border: 'none',
    color: colors.primary,
    fontSize: typography.xs,
    fontWeight: typography.semibold,
    cursor: isDisabled ? 'not-allowed' : 'pointer',
  } as const;
  return (
    <div
      className="flex shrink-0 items-stretch overflow-hidden"
      style={{
        borderRadius: spacing.radiusPill,
        background: colors.primaryLight,
        opacity: isDisabled ? 0.5 : 1,
      }}
    >
      <button
        type="button"
        onClick={onClick}
        disabled={isDisabled}
        title={title}
        className="flex items-center"
        style={{ ...zone, padding: '4px 8px 4px 12px', gap: spacing.gap }}
      >
        {loading ? (
          <Loader2 style={{ width: spacing.iconSm, height: spacing.iconSm }} className="animate-spin" />
        ) : icon ? (
          <span
            style={{ display: 'flex', alignItems: 'center', width: spacing.iconSm, height: spacing.iconSm }}
            className="[&>svg]:w-full [&>svg]:h-full"
          >
            {icon}
          </span>
        ) : null}
        {children}
      </button>
      <span aria-hidden style={{ width: 1, background: 'rgba(0, 123, 255, 0.25)', margin: '5px 0' }} />
      <button
        type="button"
        onClick={onCaretClick}
        disabled={isDisabled}
        title={caretTitle}
        className="flex items-center"
        style={{ ...zone, padding: '4px 8px', background: caretActive ? 'rgba(0, 123, 255, 0.12)' : 'transparent' }}
      >
        ▾
      </button>
    </div>
  );
}
