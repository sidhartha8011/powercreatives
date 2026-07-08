/**
 * Delivery filters.
 *
 * The search filter is UNIVERSAL and DYNAMIC: it walks every value on the
 * delivery row itself (strings, numbers, arrays, nested objects — e.g.
 * assignee names), so any column/field added later is searchable with ZERO
 * extra wiring. Labels that are displayed but don't live on the row (brand
 * NAME, type LABEL) are appended through the resolvers the board supplies
 * from its live lookups. Client select unchanged.
 */

import {
  searchableSelect,
  textFilter,
  type FilterDefinition,
} from '@/components/shared/Kanban';

import type { Delivery } from '../types';

/** Flatten every primitive on (and nested under) a row into one haystack. */
function walkValues(value: unknown, parts: string[]): void {
  if (value == null) return;
  if (typeof value === 'string' || typeof value === 'number') {
    parts.push(String(value));
    return;
  }
  if (Array.isArray(value)) {
    for (const item of value) walkValues(item, parts);
    return;
  }
  if (typeof value === 'object') {
    for (const nested of Object.values(value as Record<string, unknown>)) {
      walkValues(nested, parts);
    }
  }
}

/** Row → display-label lookups the search should also match (not on the row). */
export interface DeliverySearchResolvers {
  brandName: (d: Delivery) => string | null;
  typeLabel: (d: Delivery) => string | null;
}

export function buildDeliveryFilters(
  resolve: DeliverySearchResolvers
): ReadonlyArray<FilterDefinition<Delivery>> {
  return [
    textFilter<Delivery>(
      'search',
      'Search',
      (d) => {
        const parts: string[] = [];
        walkValues(d, parts);
        const brand = resolve.brandName(d);
        if (brand) parts.push(brand);
        const type = resolve.typeLabel(d);
        if (type) parts.push(type);
        return parts.join(' ');
      },
      { placeholder: 'Search deliveries…' }
    ),
    searchableSelect<Delivery>(
      'client',
      'Client',
      (d) => d.clientName ?? null,
      { searchPlaceholder: 'Search clients…' }
    ),
  ];
}
