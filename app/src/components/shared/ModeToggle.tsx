/**
 * MODE TOGGLE — Manual / Auto pill toggle
 *
 * Compact inline toggle for switching between Manual and Auto modes.
 * When Auto is selected, an optional number input appears.
 * Uses PowerKeys design tokens for consistent styling.
 */

import { colors, typography, spacing } from './design-tokens';

export type GenerationMode = 'manual' | 'auto';

interface ModeToggleProps {
  mode: GenerationMode;
  onModeChange: (mode: GenerationMode) => void;
  /** Only used when mode is 'auto' */
  count?: number;
  /** Only used when mode is 'auto' */
  onCountChange?: (count: number) => void;
  /** Min value for count input (default: 1) */
  min?: number;
  /** Max value for count input (default: 10) */
  max?: number;
}

export function ModeToggle({
  mode,
  onModeChange,
  count = 3,
  onCountChange,
  min = 1,
  max = 10,
}: ModeToggleProps) {
  return (
    <div className="flex items-center gap-2">
      {/* Toggle pill: Manual | Auto */}
      <div
        className="flex items-center rounded-full overflow-hidden"
        style={{ border: `1px solid ${colors.border}` }}
      >
        {(['manual', 'auto'] as const).map((m) => (
          <button
            key={m}
            type="button"
            onClick={() => onModeChange(m)}
            className="transition-all duration-150"
            style={{
              padding: '3px 10px',
              fontSize: typography.xs,
              fontWeight: mode === m ? typography.semibold : typography.regular,
              color: mode === m ? colors.primary : colors.textMuted,
              background: mode === m ? colors.primaryLight : 'transparent',
            }}
          >
            {m === 'manual' ? 'Manual' : 'Auto'}
          </button>
        ))}
      </div>

      {/* Count input — only visible in Auto mode */}
      {mode === 'auto' && onCountChange && (
        <input
          type="number"
          min={min}
          max={max}
          value={count}
          onChange={(e) => {
            const val = Math.max(min, Math.min(max, parseInt(e.target.value) || min));
            onCountChange(val);
          }}
          className="text-center focus:outline-none focus:ring-1 focus:ring-blue-300"
          style={{
            width: '40px',
            padding: '3px 4px',
            borderRadius: spacing.radiusPill,
            border: `1px solid ${colors.border}`,
            fontSize: typography.xs,
            fontWeight: typography.medium,
            color: colors.text,
          }}
        />
      )}
    </div>
  );
}
