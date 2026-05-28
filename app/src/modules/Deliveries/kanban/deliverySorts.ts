/**
 * Delivery sort options.
 *
 * Add a new sort? Append one line.
 */

import { sortBy, type SortDefinition } from '@/components/shared/Kanban';

import type { Delivery } from '../types';

export const deliverySorts: ReadonlyArray<SortDefinition<Delivery>> = [
  sortBy<Delivery>(
    'updated',
    'Recently updated',
    (a, b) => b.updatedAt.localeCompare(a.updatedAt)
  ),
  sortBy<Delivery>(
    'newest',
    'Newest first',
    (a, b) => b.createdAt.localeCompare(a.createdAt)
  ),
  sortBy<Delivery>(
    'oldest',
    'Oldest first',
    (a, b) => a.createdAt.localeCompare(b.createdAt)
  ),
  sortBy<Delivery>(
    'nameAsc',
    'Name A→Z',
    (a, b) => a.name.localeCompare(b.name)
  ),
];

export const DEFAULT_DELIVERY_SORT = 'updated';
