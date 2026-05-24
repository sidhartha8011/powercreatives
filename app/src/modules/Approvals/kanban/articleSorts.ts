/**
 * Article sort options.
 */

import { sortBy, type SortDefinition } from '@/components/shared/Kanban';

import type { Article } from '../types';

export const articleSorts: ReadonlyArray<SortDefinition<Article>> = [
  sortBy<Article>('updated', 'Recently updated', (a, b) => b.updatedAt.localeCompare(a.updatedAt)),
  sortBy<Article>('newest',  'Newest first',     (a, b) => b.createdAt.localeCompare(a.createdAt)),
  sortBy<Article>('oldest',  'Oldest first',     (a, b) => a.createdAt.localeCompare(b.createdAt)),
  sortBy<Article>('title',   'Title A→Z',        (a, b) => a.title.localeCompare(b.title)),
];

export const DEFAULT_ARTICLE_SORT = 'updated';
