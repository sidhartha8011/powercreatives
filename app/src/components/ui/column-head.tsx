/**
 * ColumnHead — a spreadsheet-style table header cell:
 *   [Title (click = sort, hover reveals arrow)] [Filter funnel] … [Generate ✦]
 *
 * Minimal chrome (PO 2026-07-08, applies to EVERY consumer — SEO +
 * Deliveries): no left filter dot / reserved space. At rest the header is
 * just the label. Hover/keyboard-focus reveals the sort arrow (title click
 * sorts) and the filter funnel (click opens the filter menu). Active states
 * stay visible in primary blue — an ACTIVE FILTER lights the TITLE text
 * itself. CSS hover reveal — known coarse-pointer gotcha, accepted.
 *
 * Global/reusable: pairs with the shared `useColumnLayout` (order + widths) and
 * `useColumnFilters` (per-column filters). Any module can compose a SEO-style
 * spreadsheet table from these. The `generate` slot is optional (used by SEO's
 * per-column AI generation; omit it elsewhere).
 */

import { useState, type DragEvent, type PointerEvent } from 'react';
import { ArrowUp, ArrowDown, ArrowUpDown, ArrowLeftRight, ChevronLeft, ChevronRight, ListFilter, X, Sparkles, Loader2, type LucideIcon } from 'lucide-react';
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { SortDirection } from '@/hooks/useSortableTable';
import { parseNumberFilter, type FilterDef } from '@/hooks/useColumnFilters';

/** The `number` kind's filter box: above / below / between as ICONS (no
 *  words — PO ruling 2026-07-14) + the numeric input(s). Emits the shared
 *  encoded value (`gt:N` · `lt:N` · `bt:N:M`, '' = inactive). */
function NumberFilterBox({ value, onChange }: { value: string; onChange: (value: string) => void }) {
  const parsed = parseNumberFilter(value);
  const [op, setOp] = useState<'gt' | 'lt' | 'bt'>(parsed?.op ?? 'gt');
  const [a, setA] = useState(parsed ? String(parsed.a) : '');
  const [b, setB] = useState(parsed?.b != null ? String(parsed.b) : '');

  const emit = (nextOp: 'gt' | 'lt' | 'bt', nextA: string, nextB: string) => {
    const na = Number(nextA);
    if (nextA.trim() === '' || Number.isNaN(na)) {
      onChange('');
      return;
    }
    if (nextOp === 'bt') {
      const nb = Number(nextB);
      onChange(nextB.trim() === '' || Number.isNaN(nb) ? '' : `bt:${na}:${nb}`);
    } else {
      onChange(`${nextOp}:${na}`);
    }
  };

  const ops: Array<{ id: 'gt' | 'lt' | 'bt'; icon: LucideIcon; title: string }> = [
    { id: 'gt', icon: ChevronRight, title: 'Above' },
    { id: 'lt', icon: ChevronLeft, title: 'Below' },
    { id: 'bt', icon: ArrowLeftRight, title: 'Between' },
  ];

  return (
    <div className="space-y-1.5 px-2 py-1.5" onClick={(e) => e.stopPropagation()}>
      <div className="flex gap-1">
        {ops.map(({ id, icon: Icon, title }) => (
          <button
            key={id}
            type="button"
            title={title}
            onClick={() => { setOp(id); emit(id, a, b); }}
            className={`flex h-6 flex-1 items-center justify-center rounded-sm transition-colors ${
              op === id ? 'bg-primary/10 text-primary' : 'bg-muted/50 text-muted-foreground hover:text-foreground'
            }`}
          >
            <Icon className="h-3.5 w-3.5" />
          </button>
        ))}
      </div>
      <div className="flex items-center gap-1">
        <input
          autoFocus
          type="number"
          value={a}
          onChange={(e) => { setA(e.target.value); emit(op, e.target.value, b); }}
          className="h-7 w-full rounded-sm border-0 bg-muted/50 px-2 text-sm outline-none"
        />
        {op === 'bt' && (
          <input
            type="number"
            value={b}
            onChange={(e) => { setB(e.target.value); emit(op, a, e.target.value); }}
            className="h-7 w-full rounded-sm border-0 bg-muted/50 px-2 text-sm outline-none"
          />
        )}
      </div>
    </div>
  );
}

interface SortState {
  active: boolean;
  dir: SortDirection;
  onToggle: () => void;
}

interface FilterState {
  // Row-agnostic here — the header only reads `kind`/`options` to render the UI;
  // the row-typed predicate (`match`) is applied by useColumnFilters.
  def: FilterDef<any>;
  value: string;
  onChange: (value: string) => void;
}

/** Generate-with-template for a column: picking a template generates the whole column. */
interface GenerateState {
  templates: { id: number; name: string; sectionIsDefault?: boolean }[];
  /** Generate the entire column using this template (undefined ⇒ section default). */
  onGenerate: (templateId?: number) => void;
  /** True while the column is generating. */
  busy?: boolean;
}

interface ColumnHeadProps {
  label: string;
  /** Accepted for API compatibility; the type icon is intentionally not rendered. */
  icon?: LucideIcon;
  width?: string;
  className?: string;
  sort?: SortState;
  filter?: FilterState;
  generate?: GenerateState;
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
  label, width, className, sort, filter, generate,
  draggable, onDragStart, onDragOver, onDragLeave, onDrop, onDragEnd, isDropTarget,
  onResizeStart,
}: ColumnHeadProps) {
  const isFiltered = !!filter?.value;
  const SortIcon = sort && sort.active && sort.dir === 'asc' ? ArrowUp
    : sort && sort.active && sort.dir === 'desc' ? ArrowDown
    : ArrowUpDown;

  // Icons: invisible at rest → revealed on hover/focus → blue when active.
  const sortIconClass = `w-3.5 h-3.5 shrink-0 transition-opacity ${
    sort?.active ? 'opacity-100 text-primary' : 'opacity-0 group-hover:opacity-70 group-focus-within:opacity-70'
  }`;
  const filterIconClass = `w-3.5 h-3.5 shrink-0 transition-opacity ${
    isFiltered ? 'opacity-100 text-primary' : 'opacity-0 text-muted-foreground group-hover:opacity-70 group-focus-within:opacity-70'
  }`;

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
        {/* Title (click to sort; arrow revealed on hover, blue when sorted).
            An ACTIVE FILTER lights the title text blue — that's the filter's
            persistent indicator; there is no dot. */}
        {sort ? (
          <button
            type="button"
            onClick={sort.onToggle}
            className={`flex min-w-0 items-center gap-1 text-left ${isFiltered ? 'text-primary' : 'hover:text-foreground'}`}
          >
            <span className="truncate">{label}</span>
            <SortIcon className={sortIconClass} />
          </button>
        ) : (
          <span className={`min-w-0 truncate text-left ${isFiltered ? 'text-primary' : ''}`}>{label}</span>
        )}

        {/* Filter — funnel right of the title, revealed on hover; opens the menu. */}
        {filter && (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <button
                type="button"
                title={`Filter ${label}`}
                aria-label={`Filter ${label}`}
                className="shrink-0 rounded p-0.5 transition-colors hover:bg-muted"
              >
                <ListFilter className={filterIconClass} />
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
                    className="h-8 w-full rounded-sm border-0 bg-muted/50 px-2 py-1.5 text-sm outline-none placeholder:text-muted-foreground"
                  />
                </div>
              ) : filter.def.kind === 'number' ? (
                <NumberFilterBox value={filter.value} onChange={filter.onChange} />
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

        {/* Generate — in sequence after the funnel; hover-revealed like the
            other header icons (a busy column stays visible — spinner). */}
        {generate && (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <button
                type="button"
                disabled={generate.busy}
                title={`Generate ${label} — pick a template`}
                className={`shrink-0 rounded p-0.5 text-muted-foreground/60 transition-opacity hover:text-primary ${
                  generate.busy ? 'opacity-100' : 'opacity-0 group-hover:opacity-100 group-focus-within:opacity-100'
                }`}
              >
                {generate.busy
                  ? <Loader2 className="w-3.5 h-3.5 animate-spin text-primary" />
                  : <Sparkles className="w-3.5 h-3.5" />}
              </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
              <DropdownMenuLabel>Generate column with…</DropdownMenuLabel>
              <DropdownMenuSeparator />
              {generate.templates.length === 0 ? (
                <div className="px-2 py-1.5 text-xs text-muted-foreground">No templates yet</div>
              ) : (
                generate.templates.map((t) => (
                  <DropdownMenuItem key={t.id} onSelect={() => generate.onGenerate(t.id)}>
                    {t.name}{t.sectionIsDefault ? ' · default' : ''}
                  </DropdownMenuItem>
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
