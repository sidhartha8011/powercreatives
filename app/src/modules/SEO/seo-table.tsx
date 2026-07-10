/**
 * Shared SEO spreadsheet table — the house table style for the SEO module and any
 * table that should match it (e.g. the Links popup). Bare <table> primitives (NOT
 * shadcn's Table, which forces h-12/p-4/border-b-only) + a single grid className so
 * every consumer renders the identical fully-controlled spreadsheet grid.
 *
 * Combine SEO_TABLE_GRID with a layout class on the <Table>:
 *   • `table-fixed` (+ a <colgroup> of widths) — the main SEO content table, or
 *   • `w-full` — auto-width tables like the Links popup.
 */

import { useState, type KeyboardEvent, type TableHTMLAttributes, type HTMLAttributes, type ThHTMLAttributes, type TdHTMLAttributes } from 'react';
import { Input } from '@/components/ui/input';
import { CELL_EDIT_INPUT, CELL_EMPTY_TEXT, CELL_VIEW_TEXT } from '@/components/ui/table-cell-recipes';

/** Gridlines on every cell, compact h-9 single-line cells, and a sticky header row. */
export const SEO_TABLE_GRID =
  'border-collapse text-xs bg-card ' +
  '[&_th]:border [&_th]:border-border/60 [&_td]:border [&_td]:border-border/60 ' +
  '[&_th]:px-2 [&_th]:h-9 [&_th]:font-normal [&_th]:text-foreground/80 ' +
  '[&_td]:px-2 [&_td]:h-9 [&_td]:py-0 [&_td]:align-middle ' +
  '[&_td]:whitespace-nowrap [&_td]:overflow-hidden ' +
  '[&_thead_th]:sticky [&_thead_th]:top-0 [&_thead_th]:z-20 [&_thead_th]:bg-card';

/** THE house pill (Airtable-style single-select chip) — one source of rendering
 *  for every small value chip: the table's status column, the outline's H1–H6 /
 *  P / NEW chips. Pass a soft `bg-*-100 text-*-700` pair via className. */
export const PILL_CLASS =
  'inline-flex items-center rounded-full px-1.5 py-0 text-[9px] font-medium whitespace-nowrap';

export function Pill({ className = '', ...p }: HTMLAttributes<HTMLSpanElement>) {
  return <span {...p} className={`${PILL_CLASS} ${className}`} />;
}

export const Table = (p: TableHTMLAttributes<HTMLTableElement>) => <table {...p} />;
export const TableHeader = (p: HTMLAttributes<HTMLTableSectionElement>) => <thead {...p} />;
export const TableBody = (p: HTMLAttributes<HTMLTableSectionElement>) => <tbody {...p} />;
export const TableRow = (p: HTMLAttributes<HTMLTableRowElement>) => <tr {...p} />;
export const TableHead = (p: ThHTMLAttributes<HTMLTableCellElement>) => <th {...p} />;
export const TableCell = (p: TdHTMLAttributes<HTMLTableCellElement>) => <td {...p} />;

/**
 * Click-to-edit text cell matching the SEO table's editable cells: shows a truncated
 * value; click to edit; Enter/blur saves (only when changed), Esc cancels. Keeps rows the
 * same compact h-9 height as the SEO grid (no always-on input boxes).
 */
export function EditableTextCell({ value, placeholder, onSave }: {
  value: string;
  placeholder?: string;
  onSave: (next: string) => void;
}) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(value);
  const commit = () => { setEditing(false); if (draft !== value) onSave(draft); };
  const onKey = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') { e.preventDefault(); commit(); }
    if (e.key === 'Escape') { setDraft(value); setEditing(false); }
  };
  if (editing) {
    return <Input autoFocus value={draft} onChange={(e) => setDraft(e.target.value)} onBlur={commit} onKeyDown={onKey} className={CELL_EDIT_INPUT} />;
  }
  return (
    <button
      type="button"
      onClick={() => { setDraft(value); setEditing(true); }}
      className={CELL_VIEW_TEXT}
      title={value || placeholder}
    >
      {value || <span className={CELL_EMPTY_TEXT}>{placeholder ?? '—'}</span>}
    </button>
  );
}
