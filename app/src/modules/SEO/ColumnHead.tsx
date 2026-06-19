/**
 * ColumnHead — a SEO-table header cell with an optional sort toggle and an
 * optional per-column filter (funnel icon → dropdown). Used instead of the
 * shared SortableTableHead so sort and filter can sit side-by-side in one
 * <th> (a filter button can't be nested inside the sort <button>).
 */

import type { DragEvent, PointerEvent } from 'react';
import { ArrowUp, ArrowDown, ArrowUpDown, Filter, X, type LucideIcon } from 'lucide-react';
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { SortDirection } from '@/hooks/useSortableTable';
import type { FilterDef } from './seoFilters';

interface SortState {
  active: boolean;
  dir: SortDirection;
  onToggle: () => void;
}

interface FilterState {
  def: FilterDef;
  value: string;
  onChange: (value: string) => void;
}

interface ColumnHeadProps {
  label: string;
  /** Leading field-type icon (Airtable-style), shown muted before the label. */
  icon?: LucideIcon;
  width?: string;
  className?: string;
  sort?: SortState;
  filter?: FilterState;
  // Drag-to-reorder (native HTML5 DnD) — wired by the table.
  draggable?: boolean;
  onDragStart?: (e: DragEvent<HTMLTableCellElement>) => void;
  onDragOver?: (e: DragEvent<HTMLTableCellElement>) => void;
  onDragLeave?: (e: DragEvent<HTMLTableCellElement>) => void;
  onDrop?: (e: DragEvent<HTMLTableCellElement>) => void;
  onDragEnd?: (e: DragEvent<HTMLTableCellElement>) => void;
  /** Highlight this header as the active drop target. */
  isDropTarget?: boolean;
  // Drag-to-resize (pointer) — wired by the table; renders a right-edge handle.
  onResizeStart?: (e: PointerEvent<HTMLSpanElement>) => void;
}

export function ColumnHead({
  label, icon: Icon, width, className, sort, filter,
  draggable, onDragStart, onDragOver, onDragLeave, onDrop, onDragEnd, isDropTarget,
  onResizeStart,
}: ColumnHeadProps) {
  const isFiltered = !!filter?.value;
  const SortIcon = sort && sort.active && sort.dir === 'asc' ? ArrowUp
    : sort && sort.active && sort.dir === 'desc' ? ArrowDown
    : ArrowUpDown;

  return (
    <th
      style={width ? { width } : undefined}
      className={`relative ${draggable ? 'cursor-grab active:cursor-grabbing' : ''} ${isDropTarget ? 'bg-primary/10' : ''} ${className ?? ''}`}
      draggable={draggable}
      onDragStart={onDragStart}
      onDragOver={onDragOver}
      onDragLeave={onDragLeave}
      onDrop={onDrop}
      onDragEnd={onDragEnd}
    >
      {isDropTarget && <span className="pointer-events-none absolute inset-y-0 left-0 z-10 w-0.5 bg-primary" />}
      <div className="flex items-center gap-1 group">
        {Icon && <Icon className="w-3.5 h-3.5 shrink-0 text-muted-foreground/70" />}
        {sort ? (
          <button type="button" onClick={sort.onToggle} className="flex flex-1 min-w-0 items-center gap-1 text-left hover:text-foreground">
            <span className="truncate">{label}</span>
            <SortIcon className={`w-3.5 h-3.5 shrink-0 transition-opacity ${sort.active ? 'opacity-100' : 'opacity-40 group-hover:opacity-70'}`} />
          </button>
        ) : (
          <span className="flex-1 min-w-0 truncate">{label}</span>
        )}

        {filter && (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <button
                type="button"
                title={`Filter ${label}`}
                className={`shrink-0 rounded p-0.5 transition-colors ${isFiltered ? 'text-primary' : 'text-muted-foreground/60 hover:text-foreground'}`}
              >
                <Filter className="w-3 h-3" />
              </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-48">
              <DropdownMenuLabel className="flex items-center justify-between gap-2">
                <span className="truncate">{label}</span>
                {isFiltered && (
                  <button
                    type="button"
                    onClick={() => filter.onChange('')}
                    className="inline-flex items-center gap-0.5 text-xs font-normal text-muted-foreground hover:text-foreground"
                  >
                    <X className="w-3 h-3" /> Clear
                  </button>
                )}
              </DropdownMenuLabel>
              <DropdownMenuSeparator />
              {filter.def.kind === 'text' ? (
                <div className="px-2 py-1.5" onClick={(e) => e.stopPropagation()}>
                  <input
                    autoFocus
                    type="text"
                    value={filter.value}
                    onChange={(e) => filter.onChange(e.target.value)}
                    placeholder="Contains…"
                    className="w-full rounded-sm border-0 bg-muted/50 px-2 py-1.5 text-sm outline-none placeholder:text-muted-foreground"
                  />
                </div>
              ) : (
                (filter.def.options ?? []).map((o) => (
                  <DropdownMenuCheckboxItem
                    key={o.value}
                    checked={filter.value === o.value}
                    onCheckedChange={() => filter.onChange(filter.value === o.value ? '' : o.value)}
                    onSelect={(e) => e.preventDefault()}
                    className="capitalize"
                  >
                    {o.label}
                  </DropdownMenuCheckboxItem>
                ))
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        )}
      </div>
      {onResizeStart && (
        <span
          role="separator"
          aria-orientation="vertical"
          title="Drag to resize"
          draggable={false}
          onPointerDown={onResizeStart}
          onClick={(e) => e.stopPropagation()}
          onDragStart={(e) => { e.preventDefault(); e.stopPropagation(); }}
          className="absolute top-0 right-0 z-20 h-full w-1.5 cursor-col-resize touch-none select-none hover:bg-primary/50"
        />
      )}
    </th>
  );
}
