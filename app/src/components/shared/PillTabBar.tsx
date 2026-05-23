/**
 * PILL TAB BAR — Reusable tab bar with pill-style buttons
 *
 * A "dumb" presentational component (NO THINKING IN UI).
 * Renders a horizontal bar of pill-shaped tab buttons with optional
 * "All" tab and per-tab count badges.
 *
 * Used by AdsResultsGrid for both concept tabs (visuals) and
 * audience tabs (copy). Can be reused by any module needing
 * the same visual pattern.
 *
 * Design tokens: uses shared colors/typography for consistency.
 */

import { colors, typography } from './design-tokens';

// ============================================================================
// Types
// ============================================================================

export interface PillTabItem {
  /** Unique identifier for this tab */
  id: string;
  /** Display label */
  name: string;
}

export interface PillTabBarProps {
  /** Tab items to render (id + name) */
  items: PillTabItem[];
  /** Currently active tab id. Use 'all' for the All tab. */
  activeId: string;
  /** Callback when a tab is selected */
  onSelect: (id: string) => void;
  /** Total item count (shown in the "All" tab badge) */
  totalCount: number;
  /** Count per tab item (shown in per-item badge) */
  getCount: (id: string) => number;
  /** Whether to show the "All" tab as first tab. Default: true */
  showAllTab?: boolean;
}

// ============================================================================
// Shared styles — computed once, not per-render
// ============================================================================

const BAR_STYLE: React.CSSProperties = {
  padding: '0.5rem',
  background: colors.bgMuted,
};

function pillStyle(isActive: boolean): React.CSSProperties {
  return {
    padding: '0.375rem 0.75rem',
    borderRadius: '9999px',
    background: isActive ? '#fff' : 'transparent',
    color: isActive ? colors.text : colors.textSecondary,
    boxShadow: isActive ? '0 1px 3px rgba(0,0,0,0.1)' : 'none',
    fontWeight: isActive ? typography.semibold : typography.medium,
    fontSize: typography.sm,
  };
}

function badgeStyle(isActive: boolean): React.CSSProperties {
  return {
    minWidth: '1.25rem',
    height: '1.25rem',
    borderRadius: '9999px',
    background: isActive ? colors.bgMuted : 'transparent',
    color: isActive ? colors.textSecondary : colors.textGhost,
  };
}

// ============================================================================
// Component
// ============================================================================

export function PillTabBar({
  items,
  activeId,
  onSelect,
  totalCount,
  getCount,
  showAllTab = true,
}: PillTabBarProps) {
  return (
    <div className="flex items-center gap-1 mb-6 rounded-lg" style={BAR_STYLE}>
      {/* "All" tab — always first when enabled */}
      {showAllTab && (
        <button
          onClick={() => onSelect('all')}
          className="flex items-center gap-2 transition-colors"
          style={pillStyle(activeId === 'all')}
        >
          <span>All</span>
          <span
            className="flex items-center justify-center text-[10px]"
            style={badgeStyle(activeId === 'all')}
          >
            {totalCount}
          </span>
        </button>
      )}

      {/* Per-item tabs */}
      {items.map((item) => {
        const isActive = item.id === activeId;
        return (
          <button
            key={item.id}
            onClick={() => onSelect(item.id)}
            className="flex items-center gap-2 transition-colors"
            style={pillStyle(isActive)}
          >
            <span className="truncate max-w-[150px]">{item.name}</span>
            <span
              className="flex items-center justify-center text-[10px]"
              style={badgeStyle(isActive)}
            >
              {getCount(item.id)}
            </span>
          </button>
        );
      })}
    </div>
  );
}
