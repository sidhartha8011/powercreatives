/**
 * KanbanBoard — generic, presentation-only kanban primitive.
 *
 * Domain-agnostic. The consumer:
 *   - Owns the columns array (id, label, accent).
 *   - Owns the items list and how each is bucketed (getColumnId).
 *   - Owns the card body (renderCard prop).
 *   - Owns the empty-state decision (e.g. distinguishing data-empty from
 *     filter-empty). The board never hides itself based on item count —
 *     when items is empty, the columns still render with their own
 *     per-column empty hint.
 *
 * The board owns:
 *   - Column wrapper, header pill, item count, empty-column hint.
 *   - Card wrapper (background, border, hover transition).
 *   - Error boundary, default loading / error chrome.
 *   - Token scope (--pck-* CSS variables via the `pck-root` class).
 *   - Optional drag-and-drop (via @hello-pangea/dnd) when onItemMove is set.
 */

import { useCallback, useMemo, useState, type DragEvent, type ReactNode } from 'react';
import {
  DragDropContext,
  Draggable,
  Droppable,
  type DropResult,
} from '@hello-pangea/dnd';

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
  onItemMove,
  onColumnReorder,
  onColumnCreate,
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

  // Top-level state precedence: error → loading → grid (always).
  if (error) {
    return (
      <div className={`pck-root ${styles.root} ${className ?? ''}`} style={style}>
        <DefaultErrorState error={error} />
      </div>
    );
  }
  if (isLoading) {
    return (
      <div className={`pck-root ${styles.root} ${className ?? ''}`} style={style}>
        <DefaultLoadingState />
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
          onColumnReorder={onColumnReorder}
          onColumnCreate={onColumnCreate}
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
  onColumnReorder?: (fromColumnId: string, toColumnId: string) => void;
  onColumnCreate?: (columnId: string) => void;
}

/** Custom MIME type so lane drops never react to foreign drags. */
const COLUMN_DRAG_TYPE = 'text/pck-column';

function KanbanColumn<T extends { id: string | number }>({
  column,
  items,
  renderCard,
  dndEnabled,
  onColumnReorder,
  onColumnCreate,
}: KanbanColumnProps<T>) {
  const pillStyle = {
    background: column.accentColor ?? undefined,
    color: column.accentText ?? undefined,
  };

  // Lane reorder (native HTML5 drag on the header — separate element and
  // mechanism from the hello-pangea card DnD, so the two never interfere).
  const reorderable = Boolean(onColumnReorder);
  const [isColumnDropTarget, setColumnDropTarget] = useState(false);

  const handleHeaderDragStart = (e: DragEvent<HTMLElement>) => {
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData(COLUMN_DRAG_TYPE, column.id);
    try {
      e.dataTransfer.setData('text/plain', column.id);
    } catch {
      /* older engines */
    }
  };
  const handleHeaderDragOver = (e: DragEvent<HTMLElement>) => {
    if (e.dataTransfer.types.includes(COLUMN_DRAG_TYPE)) {
      e.preventDefault();
      setColumnDropTarget(true);
    }
  };
  const handleHeaderDrop = (e: DragEvent<HTMLElement>) => {
    e.preventDefault();
    setColumnDropTarget(false);
    const from = e.dataTransfer.getData(COLUMN_DRAG_TYPE) || e.dataTransfer.getData('text/plain');
    if (from && from !== column.id && onColumnReorder) onColumnReorder(from, column.id);
  };

  const header = (
    <header
      className={styles.columnHeader}
      draggable={reorderable || undefined}
      onDragStart={reorderable ? handleHeaderDragStart : undefined}
      onDragOver={reorderable ? handleHeaderDragOver : undefined}
      onDragLeave={reorderable ? () => setColumnDropTarget(false) : undefined}
      onDrop={reorderable ? handleHeaderDrop : undefined}
      style={reorderable ? { cursor: 'grab' } : undefined}
      title={reorderable ? 'Drag to reorder lanes' : undefined}
    >
      <span
        className={styles.columnHeaderPill}
        style={{
          ...pillStyle,
          ...(isColumnDropTarget
            ? { outline: '2px solid var(--pck-border-focus)', outlineOffset: 2 }
            : {}),
        }}
      >
        <span className={styles.columnHeaderLabel}>{column.label}</span>
      </span>

      {onColumnCreate && (
        <button
          type="button"
          className={styles.columnCreate}
          // Native drag on the header would otherwise start from the button too.
          draggable={false}
          onDragStart={(e) => e.preventDefault()}
          onClick={() => onColumnCreate(column.id)}
          aria-label={`Create in ${column.label}`}
          title={`Create in ${column.label}`}
        >
          <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true" focusable="false">
            <path
              d="M7 2.5v9M2.5 7h9"
              stroke="currentColor"
              strokeWidth="1.6"
              strokeLinecap="round"
            />
          </svg>
        </button>
      )}
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
        {items.length > 0 && (
          <ol className={styles.columnList} role="list">
            {items.map((item) => (
              <li key={item.id} role="listitem" className={styles.cardWrap}>
                {renderCard(item, { columnId: column.id })}
              </li>
            ))}
          </ol>
        )}
        {items.length === 0 && column.emptyHint && (
          <div className={styles.columnEmpty}>{column.emptyHint}</div>
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
            {items.length === 0 && column.emptyHint && (
              <div className={styles.columnEmpty}>{column.emptyHint}</div>
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
