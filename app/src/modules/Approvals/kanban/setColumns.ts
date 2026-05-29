/**
 * Approval-set columns declaration.
 *
 * One line per column. Adding / removing / re-ordering columns happens
 * here and only here. The board reads this verbatim — no other file
 * changes.
 *
 * Status IDs match PCM_Approvals_Service::STATUSES (PHP). Labels + pill
 * colors + status dots are pixel-matched to the reference design.
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
    accentColor: '#f0eee9',
    accentText:  '#6e6259',
  },
  {
    id: 'internal',
    label: 'Awaiting Internal Approval',
    accentColor: '#f7e0e5',
    accentText:  '#9c4255',
    dotColor:    '#3b6ad8',
  },
  {
    id: 'client',
    label: 'Awaiting Client Approval',
    accentColor: '#faf3d4',
    accentText:  '#9b7c1e',
  },
  {
    id: 'launch',
    label: 'Launch',
    accentColor: '#ddd3f4',
    accentText:  '#6948b8',
    dotColor:    '#e88b3a',
  },
  {
    id: 'live',
    label: 'Live',
    accentColor: '#d6e0f4',
    accentText:  '#4a6da8',
    dotColor:    '#4a9d4a',
  },
  {
    id: 'archived',
    label: 'Archived/Paused',
    accentColor: '#e7edf3',
    accentText:  '#6e7a8a',
  },
];
