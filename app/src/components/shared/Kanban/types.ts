/**
 * Kanban Primitive — Public Types
 *
 * Domain-agnostic. The board knows nothing about Approvals, Articles, or any
 * other consumer. All domain shape enters through generic <T> and the
 * renderCard prop.
 */

import type { CSSProperties, ReactNode } from 'react';

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
  /**
   * Optional decorative dot rendered to the right of the label. Useful for
   * highlighting active-workflow stages (e.g. "in progress" / "live").
   */
  dotColor?: string;
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
 * Result of a drag-and-drop operation. Identifiers always match what the
 * consumer supplied: item.id (stringified) and KanbanColumn.id.
 */
export interface KanbanMoveEvent {
  itemId: string;
  fromColumnId: string;
  toColumnId: string;
  fromIndex: number;
  toIndex: number;
}

/**
 * Props for <KanbanBoard>.
 *
 * The board renders the lane chrome and the wrapper around each card.
 * Card body, item-to-column mapping, and source data are the consumer's
 * responsibility. The board makes no decisions about "empty" — when
 * `items` is an empty array, the columns still render with their own
 * per-column empty hint. The consumer decides what (if anything) to
 * show above the board.
 *
 * Loading / error chrome render via the built-in default states when the
 * respective props are set. Drag-and-drop is opt-in: pass `onItemMove`
 * to enable. Without the callback, cards render statically with no DnD
 * overhead.
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

  /**
   * Optional drag-and-drop handler. Pass to enable column-to-column moves.
   * Called only when the drop destination differs from the origin.
   */
  onItemMove?: (event: KanbanMoveEvent) => void;

  className?: string;
  style?: CSSProperties;
  ariaLabel?: string;
}
