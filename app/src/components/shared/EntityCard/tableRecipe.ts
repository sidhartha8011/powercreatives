/**
 * Card table recipe — the feather-light table look every table inside an
 * EntityCard shares: light-grey header row (never bold), hairline borders
 * (including verticals), compact 36px rows, 13px text, no shadows.
 *
 * Apply on top of the ui/table primitives; the classes win over the
 * primitives' defaults via cn()/tailwind-merge.
 */

/** Wrapper div around <Table>. */
export const CARD_TABLE_WRAPPER =
  'overflow-hidden rounded-md border border-slate-200';

/** <TableHead> cells. */
export const CARD_TABLE_HEAD =
  'h-9 border-slate-200 bg-slate-50 px-3 text-xs font-normal text-muted-foreground [&:not(:last-child)]:border-r';

/** <TableRow> (body). */
export const CARD_TABLE_ROW =
  'border-slate-200 last:border-b-0 hover:bg-transparent';

/** <TableCell> cells. */
export const CARD_TABLE_CELL =
  'h-9 border-slate-200 p-1 text-[13px] align-middle [&:not(:last-child)]:border-r';
