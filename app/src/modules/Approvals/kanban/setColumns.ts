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

export const setColumns: ReadonlyArray<ApprovalSetColumn> = [
  {
    id: 'draft',
    label: 'Draft',
    accentColor: '#e9eef5',
    accentText:  '#4b6478',
    emptyHint:   ' ',
  },
  {
    id: 'internal',
    label: 'Internal Approval',
    accentColor: '#fde7d3',
    accentText:  '#9c5314',
    emptyHint:   ' ',
  },
  {
    id: 'client',
    label: 'Client Approval',
    accentColor: '#fce4e4',
    accentText:  '#a83838',
    emptyHint:   ' ',
  },
  {
    id: 'approved',
    label: 'Approved',
    accentColor: '#fbf3d2',
    accentText:  '#856414',
    emptyHint:   ' ',
  },
  {
    id: 'live',
    label: 'Live',
    accentColor: '#dff0db',
    accentText:  '#386c33',
    emptyHint:   ' ',
  },
  {
    id: 'archived',
    label: 'Archived',
    accentColor: '#f5d8d8',
    accentText:  '#8e3636',
    emptyHint:   ' ',
  },
];
