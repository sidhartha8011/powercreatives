/**
 * Article columns declaration — mirrors the existing 4-status taxonomy
 * (StatusKey from design-tokens). Pulls accent colors from `statusColors`
 * so the look stays in sync with the rest of the app.
 */

import type { KanbanColumn } from '@/components/shared/Kanban';
import { statusColors, type StatusKey } from '@/components/shared/design-tokens';

export interface ArticleColumn extends KanbanColumn {
  id: StatusKey;
}

export const articleColumns: ReadonlyArray<ArticleColumn> = [
  { id: 'draft',     label: 'Draft',     accentColor: statusColors.draft.bg,     accentText: statusColors.draft.text,     emptyHint: ' ' },
  { id: 'review',    label: 'Review',    accentColor: statusColors.review.bg,    accentText: statusColors.review.text,    emptyHint: ' ' },
  { id: 'ready',     label: 'Ready',     accentColor: statusColors.ready.bg,     accentText: statusColors.ready.text,     emptyHint: ' ' },
  { id: 'published', label: 'Published', accentColor: statusColors.published.bg, accentText: statusColors.published.text, emptyHint: ' ' },
];
