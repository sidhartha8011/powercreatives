/**
 * Kanban Primitive — Public Types
 *
 * Domain-agnostic. The board knows nothing about Approvals, Articles, or any
 * other consumer. All domain shape enters through generic <T> and the
 * renderCard prop.
 *
 * Stability: this is a versioned contract. Breaking changes bump the major
 * via the `KANBAN_CONTRACT_VERSION` constant — consumers can assert it.
 */

import type { CSSProperties, ReactNode } from 'react';

export const KANBAN_CONTRACT_VERSION = 1 as const;

/**
 * Single column declaration. The consumer owns the column list (labels,
 * order, accent colors). The board never invents columns.
 */
export interface KanbanColumn {
  /** Stable identifier used by getColumnId() to bucket items. */
  id: string;
  /** Human-readable label rendered in the column header pill. */
  label: string;
  /** Optional pill background color (hex/var/css). Defaults to neutral. */
  accentColor?: string;
  /** Optional pill text color. Defaults to neutral. */
  accentText?: string;
  /** Optional hint shown when the column is empty. */
  emptyHint?: string;
}

/**
 * Context passed to renderCard. DnD-ready slots are declared now so adding
 * drag-and-drop later does not require every card consumer to change.
 */
export interface RenderCardContext {
  columnId: string;
  /**
   * Reserved for drag-and-drop wiring. Cards should spread this onto their
   * root element when present. v1 is undefined — DnD is out of scope.
   */
  dragHandleProps?: Record<string, unknown>;
  /** True while the card is being dragged. v1 is always false. */
  isDragging?: boolean;
}

/**
 * Props for <KanbanBoard>. Empty/loading/error states are required props on
 * the contract — consumers cannot accidentally ship an undefined empty UI.
 * Defaults are exported separately (DefaultEmptyState, DefaultLoadingState,
 * DefaultErrorState) so simple consumers can pass them through.
 */
export interface KanbanBoardProps<T extends { id: string | number }> {
  columns: ReadonlyArray<KanbanColumn>;
  items: ReadonlyArray<T>;
  /** Maps an item to its column id. */
  getColumnId: (item: T) => string;
  /** Renders the domain-specific card body. The board provides the wrapper. */
  renderCard: (item: T, ctx: RenderCardContext) => ReactNode;

  isLoading?: boolean;
  error?: Error | null;

  /** Override the default empty-board state (no items at all). */
  emptyState?: ReactNode;
  /** Override the default loading skeleton. */
  loadingState?: ReactNode;
  /** Override the default error UI. */
  errorState?: (err: Error) => ReactNode;

  className?: string;
  style?: CSSProperties;
  ariaLabel?: string;
}
