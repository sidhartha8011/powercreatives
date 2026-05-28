/**
 * Delivery filters.
 *
 * One text filter (combined across delivery name + client name) plus a
 * single searchable client dropdown. Each is a single declaration.
 * Append to extend, delete to remove — the toolbar reads this list and
 * generates its UI in order.
 */

import {
  searchableSelect,
  textFilter,
  type FilterDefinition,
} from '@/components/shared/Kanban';

import type { Delivery } from '../types';

export const deliveryFilters: ReadonlyArray<FilterDefinition<Delivery>> = [
  textFilter<Delivery>(
    'search',
    'Search',
    (d) =>
      [d.name, d.clientName]
        .filter((v): v is string => Boolean(v))
        .join(' '),
    { placeholder: 'Search deliveries…' }
  ),
  searchableSelect<Delivery>(
    'client',
    'Client',
    (d) => d.clientName ?? null,
    { searchPlaceholder: 'Search clients…' }
  ),
];
