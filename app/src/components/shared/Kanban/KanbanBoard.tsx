/**
 * KanbanBoard — generic, presentation-only kanban primitive.
 *
 * Domain-agnostic. The consumer:
 *   - Owns the columns array (id, label, accent).
 *   - Owns the items list and how each is bucketed (getColumnId).
 *   - Owns the card body (renderCard prop).
 *
 * The board owns:
 *   - Column wrapper, header pill, item count, empty-column hint.
 *   - Card wrapper (background, border, hover transition).
 *   - Error boundary, default loading / empty / error states.
 *   - Token scope (--pck-* CSS variables via the `pck-root` class).
 *
 * No filter / sort UI here — see KanbanToolbar for that.
 */

import { useMemo } from 'react';

import { DefaultEmptyState } from './DefaultEmptyState';
import { DefaultErrorState } from './DefaultErrorState';
import { DefaultLoadingState } from './DefaultLoadingState';
import { KanbanErrorBoundary } from './KanbanErrorBoundary';
import styles from './kanban.module.css';
import './tokens.css';
import type {
  KanbanBoardProps,
  KanbanColumn as KanbanColumnDef,
  RenderCardContext,
} from './types';

export function KanbanBoard<T extends { id: string | number }>({
  columns,
  items,
  getColumnId,
  renderCard,
  isLoading = false,
  error = null,
  emptyState,
  loadingState,
  errorState,
  className,
  style,
  ariaLabel = 'Kanban board',
}: KanbanBoardProps<T>) {
  // Group items by column once per dependency change.
  const grouped = useMemo(() => {
    const map = new Map<string, T[]>();
    for (const col of columns) map.set(col.id, []);
    for (const item of items) {
      const colId = getColumnId(item);
      const bucket = map.get(colId);
      if (bucket) bucket.push(item);
      // Items whose colId doesn't match any declared column are dropped.
      // This is intentional: the consumer is the source of truth for which
      // columns exist; orphan statuses are visible only via filter chips.
    }
    return map;
  }, [columns, items, getColumnId]);

  // Top-level state precedence: error → loading → empty → grid.
  if (error) {
    return (
      <div className={`pck-root ${styles.root} ${className ?? ''}`} style={style}>
        {errorState ? errorState(error) : <DefaultErrorState error={error} />}
      </div>
    );
  }

  if (isLoading) {
    return (
      <div className={`pck-root ${styles.root} ${className ?? ''}`} style={style}>
        {loadingState ?? <DefaultLoadingState />}
      </div>
    );
  }

  if (items.length === 0) {
    return (
      <div className={`pck-root ${styles.root} ${className ?? ''}`} style={style}>
        {emptyState ?? <DefaultEmptyState />}
      </div>
    );
  }

  return (
    <KanbanErrorBoundary>
      <div
        className={`pck-root ${styles.root} ${className ?? ''}`}
        style={style}
        role="list"
        aria-label={ariaLabel}
      >
        {columns.map((col) => (
          <KanbanColumn
            key={col.id}
            column={col}
            items={grouped.get(col.id) ?? []}
            renderCard={renderCard}
          />
        ))}
      </div>
    </KanbanErrorBoundary>
  );
}

// ─── Column ──────────────────────────────────────────────────────

interface KanbanColumnProps<T extends { id: string | number }> {
  column: KanbanColumnDef;
  items: T[];
  renderCard: (item: T, ctx: RenderCardContext) => React.ReactNode;
}

function KanbanColumn<T extends { id: string | number }>({
  column,
  items,
  renderCard,
}: KanbanColumnProps<T>) {
  const pillStyle = {
    background: column.accentColor ?? undefined,
    color: column.accentText ?? undefined,
  };

  return (
    <section
      className={styles.column}
      role="listitem"
      aria-label={`${column.label} (${items.length})`}
    >
      <header className={styles.columnHeader}>
        <span className={styles.columnHeaderPill} style={pillStyle}>
          {column.label}
        </span>
        <span className={styles.columnHeaderCount} aria-hidden="true">
          {items.length}
        </span>
      </header>

      {items.length === 0 ? (
        <div className={styles.columnEmpty}>
          {column.emptyHint ?? ' '}
        </div>
      ) : (
        <ol className={styles.columnList} role="list">
          {items.map((item) => (
            <li
              key={item.id}
              role="listitem"
              className={styles.cardWrap}
            >
              {renderCard(item, { columnId: column.id })}
            </li>
          ))}
        </ol>
      )}
    </section>
  );
}
