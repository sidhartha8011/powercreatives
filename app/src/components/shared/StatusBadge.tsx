/**
 * STATUS BADGE
 *
 * Renders a semantic status indicator in two forms:
 *   - `dot`  — small circle (8px) with background + border, for list items
 *   - `pill` — rounded-full text badge with status name, for headers/bars
 *
 * Consumes `statusColors` from design-tokens for consistent app-wide styling.
 * Primarily used by the Writer module's document queue and action bar.
 *
 * Usage:
 *   <StatusBadge status="draft" />                  // dot (default)
 *   <StatusBadge status="ready" variant="pill" />   // pill with text
 */

import { statusColors, typography, type StatusKey } from './design-tokens';

interface StatusBadgeProps {
  status: StatusKey;
  /** 'dot' renders a small circle, 'pill' renders a text badge */
  variant?: 'dot' | 'pill';
  /** Override display label for pill variant (defaults to capitalized status) */
  label?: string;
  className?: string;
}

export function StatusBadge({
  status,
  variant = 'dot',
  label,
  className = '',
}: StatusBadgeProps) {
  const palette = statusColors[status];

  if (variant === 'dot') {
    return (
      <span
        className={`shrink-0 ${className}`}
        style={{
          width: 8,
          height: 8,
          borderRadius: '50%',
          backgroundColor: palette.bg,
          border: `1px solid ${palette.border}`,
          display: 'inline-block',
        }}
        title={status}
      />
    );
  }

  // Pill variant — shows status text in a colored badge
  const displayLabel = label ?? status.charAt(0).toUpperCase() + status.slice(1);

  return (
    <span
      className={`inline-flex items-center capitalize ${className}`}
      style={{
        fontSize: typography.micro,
        fontWeight: typography.medium,
        color: palette.text,
        backgroundColor: palette.bg,
        border: `1px solid ${palette.border}`,
        padding: '1px 8px',
        borderRadius: '999px',
        lineHeight: '1.6',
      }}
    >
      {displayLabel}
    </span>
  );
}
