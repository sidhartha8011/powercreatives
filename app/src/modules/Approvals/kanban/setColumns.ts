/**
 * Approval-set columns declaration.
 *
 * One line per column. Adding / removing / re-ordering columns happens
 * here and only here. The board reads this verbatim — no other file
 * changes.
 *
 * Accent colors follow the Notion-style soft pastel header convention.
 */

import type { KanbanColumn } from '@/components/shared/Kanban';

import type { ApprovalStatus } from '../types';

export interface ApprovalSetColumn extends KanbanColumn {
  id: ApprovalStatus;
}

/**
 * Column accent palette — warm Manus-inspired pastels.
 *
 * Each pill is saturated enough to be scannable in peripheral vision but
 * desaturated enough to sit calmly on the warm-gray column bg. Sequence
 * encodes lifecycle state from neutral → active → done → archived:
 *   draft      ⟶ warm taupe (passive, no action yet)
 *   internal   ⟶ amber       (needs internal team action)
 *   client     ⟶ coral       (waiting on external client)
 *   approved   ⟶ warm yellow (client signed off, ready to ship)
 *   live       ⟶ moss green  (in market / production)
 *   archived   ⟶ dusty mauve (closed, historical)
 */
export const setColumns: ReadonlyArray<ApprovalSetColumn> = [
  {
    id: 'draft',
    label: 'Draft',
    accentColor: '#e8e4dc',
    accentText:  '#5d564b',
    emptyHint:   ' ',
  },
  {
    id: 'internal',
    label: 'Internal Approval',
    accentColor: '#f9d9b4',
    accentText:  '#8a4a14',
    emptyHint:   ' ',
  },
  {
    id: 'client',
    label: 'Client Approval',
    accentColor: '#f5c7c2',
    accentText:  '#933326',
    emptyHint:   ' ',
  },
  {
    id: 'approved',
    label: 'Approved',
    accentColor: '#f3e4a8',
    accentText:  '#735410',
    emptyHint:   ' ',
  },
  {
    id: 'live',
    label: 'Live',
    accentColor: '#c8dfb6',
    accentText:  '#365e26',
    emptyHint:   ' ',
  },
  {
    id: 'archived',
    label: 'Archived',
    accentColor: '#dfc9d2',
    accentText:  '#6f3c52',
    emptyHint:   ' ',
  },
];
