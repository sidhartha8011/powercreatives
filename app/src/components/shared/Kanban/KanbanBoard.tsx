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
 *   - Optional drag-and-drop (via @hello-pangea/dnd) when onItemMove is set.
 *
 * No filter / sort UI here — see KanbanToolbar for that.
 */

import { useCallback, useMemo, type ReactNode } from 'react';
import {
  DragDropContext,
  Draggable,
  Droppable,
  type DropResult,
} from '@hello-pangea/dnd';

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
  onItemMove,
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
    }
    return map;
  }, [columns, items, getColumnId]);

  const dndEnabled = Boolean(onItemMove);

  const handleDragEnd = useCallback(
    (result: DropResult) => {
      if (!onItemMove || !result.destination) return;
      const from = result.source;
      const to = result.destination;
      if (from.droppableId === to.droppableId && from.index === to.index) {
        return;
      }
      onItemMove({
        itemId: result.draggableId,
        fromColumnId: from.droppableId,
        toColumnId: to.droppableId,
        fromIndex: from.index,
        toIndex: to.index,
      });
    },
    [onItemMove]
  );

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

  const board = (
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
          dndEnabled={dndEnabled}
        />
      ))}
    </div>
  );

  if (!dndEnabled) {
    return <KanbanErrorBoundary>{board}</KanbanErrorBoundary>;
  }

  return (
    <KanbanErrorBoundary>
      <DragDropContext onDragEnd={handleDragEnd}>{board}</DragDropContext>
    </KanbanErrorBoundary>
  );
}

// ─── Column ──────────────────────────────────────────────────────

interface KanbanColumnProps<T extends { id: string | number }> {
  column: KanbanColumnDef;
  items: T[];
  renderCard: (item: T, ctx: RenderCardContext) => ReactNode;
  dndEnabled: boolean;
}

function KanbanColumn<T extends { id: string | number }>({
  column,
  items,
  renderCard,
  dndEnabled,
}: KanbanColumnProps<T>) {
  const pillStyle = {
    background: column.accentColor ?? undefined,
    color: column.accentText ?? undefined,
  };

  const header = (
    <header className={styles.columnHeader}>
      <span className={styles.columnHeaderPill} style={pillStyle}>
        <span className={styles.columnHeaderLabel}>{column.label}</span>
        {column.dotColor && (
          <span
            className={styles.columnHeaderDot}
            style={{ background: column.dotColor }}
            aria-hidden="true"
          />
        )}
        <span className={styles.columnHeaderCount} aria-hidden="true">
          {items.length}
        </span>
      </span>
    </header>
  );

  if (!dndEnabled) {
    return (
      <section
        className={styles.column}
        role="listitem"
        aria-label={`${column.label} (${items.length})`}
      >
        {header}
        {items.length === 0 ? (
          <div className={styles.columnEmpty}>{column.emptyHint ?? ' '}</div>
        ) : (
          <ol className={styles.columnList} role="list">
            {items.map((item) => (
              <li key={item.id} role="listitem" className={styles.cardWrap}>
                {renderCard(item, { columnId: column.id })}
              </li>
            ))}
          </ol>
        )}
      </section>
    );
  }

  return (
    <section
      className={styles.column}
      role="listitem"
      aria-label={`${column.label} (${items.length})`}
    >
      {header}
      <Droppable droppableId={column.id}>
        {(droppableProvided, droppableSnapshot) => (
          <ol
            ref={droppableProvided.innerRef}
            {...droppableProvided.droppableProps}
            className={`${styles.columnList} ${droppableSnapshot.isDraggingOver ? styles.columnListOver : ''}`}
            role="list"
          >
            {items.length === 0 && (
              <div className={styles.columnEmpty}>{column.emptyHint ?? ' '}</div>
            )}
            {items.map((item, idx) => (
              <Draggable
                key={item.id}
                draggableId={String(item.id)}
                index={idx}
              >
                {(draggableProvided, draggableSnapshot) => (
                  <li
                    ref={draggableProvided.innerRef}
                    {...draggableProvided.draggableProps}
                    {...draggableProvided.dragHandleProps}
                    className={`${styles.cardWrap} ${draggableSnapshot.isDragging ? styles.cardWrapDragging : ''}`}
                    role="listitem"
                  >
                    {renderCard(item, {
                      columnId: column.id,
                      isDragging: draggableSnapshot.isDragging,
                    })}
                  </li>
                )}
              </Draggable>
            ))}
            {droppableProvided.placeholder}
          </ol>
        )}
      </Droppable>
    </section>
  );
}
