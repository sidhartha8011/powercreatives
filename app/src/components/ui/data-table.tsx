/**
 * DataTable — a global, config-driven table for the whole app.
 *
 * Modules describe their table with a `columns` array (header + cell renderer +
 * optional sort accessor) and pass `data`; this component owns the markup,
 * sticky/gridline styling, and sorting. No more hand-rolled <table>/<tr>/<td>
 * per module.
 *
 * It composes the existing shared primitives — `useSortableTable` (sort state)
 * and `SortableTableHead` (clickable sort header) — so behaviour stays identical
 * to the tables already in SEO / Templates / Automations / Projects / Sites.
 *
 * Filtering stays in the consumer (each screen has its own filter bar): pass the
 * already-filtered rows as `data`.
 *
 * Example:
 *   const columns: DataTableColumn<Site>[] = [
 *     { key: 'name', header: 'Name', sortAccessor: (s) => s.name.toLowerCase(),
 *       cell: (s) => <strong>{s.name}</strong> },
 *     { key: 'actions', header: 'Actions', className: 'text-center',
 *       cell: (s) => <RowActions site={s} /> },
 *   ];
 *   <DataTable columns={columns} data={filtered} rowKey={(s) => s.id} />
 */

import { useMemo, type ReactNode, type CSSProperties } from 'react';

import { cn } from '@/lib/utils';
import { SortableTableHead } from '@/components/ui/sortable-table-head';
import { useSortableTable, type SortDirection } from '@/hooks/useSortableTable';

/** Spreadsheet styling shared by every DataTable (matches the SEO/Sites look). */
const GRID_CLASS =
  'w-full border-collapse text-xs bg-card ' +
  '[&_th]:border [&_th]:border-border/60 [&_td]:border [&_td]:border-border/60 ' +
  '[&_th]:px-2 [&_th]:h-9 [&_th]:font-normal [&_th]:text-foreground/80 ' +
  '[&_td]:px-2 [&_td]:h-9 [&_td]:py-0 [&_td]:align-middle ' +
  '[&_td]:whitespace-nowrap [&_td]:overflow-hidden ' +
  '[&_thead_th]:sticky [&_thead_th]:top-0 [&_thead_th]:z-20 [&_thead_th]:bg-card';

export interface DataTableColumn<T> {
  /** Stable column id — also the sort key. */
  key: string;
  /** Header content. */
  header: ReactNode;
  /** Cell renderer for a row. */
  cell: (row: T) => ReactNode;
  /**
   * When provided, the column is sortable: returns the comparable value for a
   * row. Omit for non-sortable columns (e.g. an "Actions" column).
   */
  sortAccessor?: (row: T) => string | number | null;
  /** className applied to BOTH the header and body cells (e.g. 'text-center'). */
  className?: string;
  /** className for the header cell only. */
  headClassName?: string;
  /** className for body cells only. */
  cellClassName?: string;
  /** Fixed column width (px number or any CSS width). */
  width?: number | string;
}

export interface DataTableProps<T> {
  columns: DataTableColumn<T>[];
  /** Rows to display (already filtered by the consumer). */
  data: T[];
  /** Stable key per row. */
  rowKey: (row: T) => string | number;
  /** Initial sort column (defaults to the first sortable column). */
  defaultSortKey?: string;
  defaultSortDir?: SortDirection;
  /** Optional row click handler (adds a pointer cursor). */
  onRowClick?: (row: T) => void;
  /** Shown when there are no rows. */
  emptyMessage?: ReactNode;
  /** Extra className for the scroll wrapper. */
  wrapperClassName?: string;
  /** Extra className merged onto the <table>. */
  className?: string;
}

export function DataTable<T>({
  columns,
  data,
  rowKey,
  defaultSortKey,
  defaultSortDir = 'asc',
  onRowClick,
  emptyMessage = 'No results.',
  wrapperClassName,
  className,
}: DataTableProps<T>) {
  const accessors = useMemo(() => {
    const acc: Record<string, (row: T) => string | number | null> = {};
    for (const c of columns) if (c.sortAccessor) acc[c.key] = c.sortAccessor;
    return acc;
  }, [columns]);

  const firstSortable = columns.find((c) => c.sortAccessor)?.key ?? '';

  const { sortKey, sortDir, toggleSort, sortedData } = useSortableTable<T, string>(data, {
    defaultKey: defaultSortKey ?? firstSortable,
    defaultDir: defaultSortDir,
    accessors,
  });

  const widthStyle = (w?: number | string): CSSProperties | undefined =>
    w == null ? undefined : { width: w };

  return (
    <div className={cn('rounded-md border border-border shadow-sm overflow-auto max-h-[calc(100vh-300px)] bg-card', wrapperClassName)}>
      <table className={cn(GRID_CLASS, className)}>
        <thead>
          <tr>
            {columns.map((c) =>
              c.sortAccessor ? (
                <SortableTableHead
                  key={c.key}
                  columnKey={c.key}
                  label={c.header}
                  currentSortKey={sortKey}
                  currentSortDir={sortDir}
                  onToggle={toggleSort}
                  className={cn(c.className, c.headClassName)}
                  style={widthStyle(c.width)}
                />
              ) : (
                <th key={c.key} className={cn(c.className, c.headClassName)} style={widthStyle(c.width)}>
                  {c.header}
                </th>
              ),
            )}
          </tr>
        </thead>
        <tbody>
          {sortedData.length === 0 ? (
            <tr>
              <td colSpan={columns.length} className="text-center text-muted-foreground">
                {emptyMessage}
              </td>
            </tr>
          ) : (
            sortedData.map((row) => (
              <tr
                key={rowKey(row)}
                className={cn('hover:bg-muted/60', onRowClick && 'cursor-pointer')}
                onClick={onRowClick ? () => onRowClick(row) : undefined}
              >
                {columns.map((c) => (
                  <td key={c.key} className={cn(c.className, c.cellClassName)}>
                    {c.cell(row)}
                  </td>
                ))}
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  );
}
