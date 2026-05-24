/**
 * Approval-set sort options.
 *
 * Add a new sort? Append one line.
 */

import { sortBy, type SortDefinition } from '@/components/shared/Kanban';

import type { ApprovalSet } from '../types';

export const setSorts: ReadonlyArray<SortDefinition<ApprovalSet>> = [
  sortBy<ApprovalSet>(
    'newest',
    'Newest first',
    (a, b) => b.createdAt.localeCompare(a.createdAt)
  ),
  sortBy<ApprovalSet>(
    'oldest',
    'Oldest first',
    (a, b) => a.createdAt.localeCompare(b.createdAt)
  ),
  sortBy<ApprovalSet>(
    'updated',
    'Recently updated',
    (a, b) => b.updatedAt.localeCompare(a.updatedAt)
  ),
];

export const DEFAULT_SET_SORT = 'newest';
