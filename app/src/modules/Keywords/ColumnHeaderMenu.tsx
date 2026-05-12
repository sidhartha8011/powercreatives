/**
 * ColumnHeaderMenu — Sort + Filter dropdown for table column headers.
 *
 * Click the column header → DropdownMenu with:
 *   - Sort ascending / descending
 *   - Filter input (text or min/max range)
 *
 * Uses existing DropdownMenu UI components.
 * Filter inputs use native <input> styled to match DropdownMenuItem.
 */

import type { Column } from '@tanstack/react-table';
import { ArrowUp, ArrowDown, ArrowUpDown, Filter, X } from 'lucide-react';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

// ── Meta type extension ──
declare module '@tanstack/react-table' {
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  interface ColumnMeta<TData extends unknown, TValue> {
    filterVariant?: 'text' | 'range';
  }
}

/** Shared classes matching DropdownMenuItem styling exactly */
const filterInputClass =
  'w-full rounded-sm border-0 px-2 py-1.5 text-sm outline-none bg-muted/50 placeholder:text-muted-foreground';

interface ColumnHeaderMenuProps {
  column: Column<any, unknown>;
  label: string;
}

export function ColumnHeaderMenu({ column, label }: ColumnHeaderMenuProps) {
  const isSorted = column.getIsSorted();
  const filterVariant = column.columnDef.meta?.filterVariant;
  const filterValue = column.getFilterValue();
  const isFiltered = filterValue !== undefined && filterValue !== null && filterValue !== '';

  const SortIcon = isSorted === 'asc' ? ArrowUp : isSorted === 'desc' ? ArrowDown : ArrowUpDown;

  return (
    <div className="flex items-center w-full group -ml-1">
      {/* ── Primary Action: Direct Sort ── */}
      <button
        type="button"
        onClick={() => column.toggleSorting()}
        className={`flex flex-1 items-center gap-1 text-left cursor-pointer select-none hover:text-foreground transition-colors px-1 py-0.5 rounded ${
          column.getCanSort() ? '' : 'pointer-events-none'
        }`}
      >
        <span>{label}</span>
        {column.getCanSort() && (
          <SortIcon className={`w-3.5 h-3.5 shrink-0 transition-opacity ${isSorted ? 'opacity-100' : 'opacity-0 group-hover:opacity-40'}`} />
        )}
      </button>

      {/* ── Secondary Action: Filter Menu (Only if filterable) ── */}
      {filterVariant && (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <button
              type="button"
              className={`p-1 rounded shrink-0 transition-colors ${
                isFiltered ? 'text-primary hover:bg-primary/10' : 'text-muted-foreground opacity-0 group-hover:opacity-100 hover:bg-muted'
              }`}
              title="Open filter menu"
            >
              <Filter className="w-3.5 h-3.5" />
            </button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-48">
            <div className="px-2 py-1.5" onClick={(e) => e.stopPropagation()}>
              {filterVariant === 'text' && (
                <input
                  type="text"
                  value={(filterValue as string) ?? ''}
                  onChange={(e) => column.setFilterValue(e.target.value || undefined)}
                  placeholder="Filter..."
                  className={filterInputClass}
                />
              )}
              {filterVariant === 'range' && (
                <div className="flex gap-1">
                  <input
                    type="number"
                    value={(filterValue as [number?, number?])?.[0] ?? ''}
                    onChange={(e) => {
                      const val = e.target.value ? Number(e.target.value) : undefined;
                      const prev = (filterValue as [number?, number?]) ?? [undefined, undefined];
                      column.setFilterValue([val, prev[1]]);
                    }}
                    placeholder="Min"
                    className={filterInputClass}
                  />
                  <input
                    type="number"
                    value={(filterValue as [number?, number?])?.[1] ?? ''}
                    onChange={(e) => {
                      const val = e.target.value ? Number(e.target.value) : undefined;
                      const prev = (filterValue as [number?, number?]) ?? [undefined, undefined];
                      column.setFilterValue([prev[0], val]);
                    }}
                    placeholder="Max"
                    className={filterInputClass}
                  />
                </div>
              )}
              {isFiltered && (
                <button
                  type="button"
                  onClick={() => column.setFilterValue(undefined)}
                  className="mt-1 text-xs text-muted-foreground hover:text-foreground transition-colors"
                >
                  Clear filter
                </button>
              )}
            </div>
          </DropdownMenuContent>
        </DropdownMenu>
      )}
    </div>
  );
}

