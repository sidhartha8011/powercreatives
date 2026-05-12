/**
 * CHARACTER COUNTER
 *
 * Displays a "current/max" character count below form inputs.
 * Text turns red when the maximum is exceeded.
 *
 * Primarily used by the Writer module's SEO metadata panel,
 * but available for any input needing length feedback.
 *
 * Usage:
 *   <CharacterCounter value={metaTitle} max={60} />
 */

import { colors, typography } from './design-tokens';

interface CharacterCounterProps {
  /** The current text value to measure */
  value: string;
  /** Maximum character limit */
  max: number;
  className?: string;
}

export function CharacterCounter({ value, max, className = '' }: CharacterCounterProps) {
  const count = value.length;
  const isOver = count > max;

  return (
    <span
      className={className}
      style={{
        fontSize: typography.micro,
        color: isOver ? colors.danger : colors.textMuted,
        fontWeight: isOver ? typography.medium : typography.regular,
        textAlign: 'right',
        display: 'block',
      }}
    >
      {count}/{max}
    </span>
  );
}
