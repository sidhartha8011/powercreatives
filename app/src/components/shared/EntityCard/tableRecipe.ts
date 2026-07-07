/**
 * Card table recipe — the feather-light table look every table inside an
 * EntityCard shares: light-grey header row (never bold), hairline borders
 * (including verticals), compact 36px rows, no shadows. Text comes from
 * CARD_TYPE roles (headers = LABEL, cells = BODY) — see cardTokens.ts.
 *
 * Apply on top of the ui/table primitives; the classes win over the
 * primitives' defaults via cn()/tailwind-merge.
 */

import { CARD_TYPE } from './cardTokens';

/** Wrapper div around <Table>. */
export const CARD_TABLE_WRAPPER =
  'overflow-hidden rounded-md border border-slate-200';

/** <TableHead> cells. */
export const CARD_TABLE_HEAD =
  `h-9 border-slate-200 bg-slate-50 px-3 [&:not(:last-child)]:border-r ${CARD_TYPE.LABEL}`;

/** <TableRow> (body). */
export const CARD_TABLE_ROW =
  'border-slate-200 last:border-b-0 hover:bg-transparent';

/** <TableCell> cells. */
export const CARD_TABLE_CELL =
  `h-9 border-slate-200 p-1 align-middle [&:not(:last-child)]:border-r ${CARD_TYPE.BODY}`;
