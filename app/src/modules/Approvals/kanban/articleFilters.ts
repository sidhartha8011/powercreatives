/**
 * Article filters.
 *
 * Title text search + "Published only" boolean toggle. The Article type
 * carries limited metadata today (no brandName resolved client-side) — add
 * a brand searchableSelect here once brand resolution lands.
 */

import {
  booleanFilter,
  textFilter,
  type FilterDefinition,
} from '@/components/shared/Kanban';

import type { Article } from '../types';

export const articleFilters: ReadonlyArray<FilterDefinition<Article>> = [
  textFilter<Article>(
    'title',
    'Title',
    (a) => a.title,
    { placeholder: 'Search titles…' }
  ),
  booleanFilter<Article>(
    'live',
    'Has live URL',
    (a) => Boolean(a.publishedUrl),
    { toggleLabel: 'Live only' }
  ),
];
