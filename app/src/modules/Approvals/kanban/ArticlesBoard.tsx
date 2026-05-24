/**
 * ArticlesBoard — orchestrator for the Articles tab.
 *
 * Same shape as SetsBoard: data hook + declarations + shared chrome.
 * Proves the kanban primitive is genuinely reusable.
 */

import { useCallback } from 'react';
import { FileText } from 'lucide-react';

import {
  DefaultEmptyState,
  KanbanBoard,
  KanbanToolbar,
  useListState,
} from '@/components/shared/Kanban';
import type { StatusKey } from '@/components/shared/design-tokens';

import { ArticleCard } from './ArticleCard';
import { articleColumns } from './articleColumns';
import { articleFilters } from './articleFilters';
import { DEFAULT_ARTICLE_SORT, articleSorts } from './articleSorts';
import { useApprovalArticles } from '../hooks/useApprovalArticles';
import type { Article } from '../types';

export function ArticlesBoard() {
  const { articles, isLoading, error, changeStatus } = useApprovalArticles();

  const listState = useListState<Article>(articles, articleFilters, articleSorts, {
    persistKey: 'pcm.approvals.articles',
    defaultSortId: DEFAULT_ARTICLE_SORT,
  });

  const getColumnId = useCallback((a: Article): StatusKey => a.status, []);

  const renderCard = useCallback(
    (article: Article) => (
      <ArticleCard article={article} onStatusChange={changeStatus} />
    ),
    [changeStatus]
  );

  return (
    <div className="flex-1 flex flex-col min-h-0">
      <KanbanToolbar
        items={articles}
        filters={articleFilters}
        sorts={articleSorts}
        state={listState}
        ariaLabel="Filter and sort articles"
      />

      <div className="flex-1 min-h-0 overflow-x-auto">
        <KanbanBoard<Article>
          columns={articleColumns}
          items={listState.filteredItems}
          getColumnId={getColumnId}
          renderCard={renderCard}
          isLoading={isLoading}
          error={error}
          ariaLabel="Articles pipeline"
          emptyState={
            <DefaultEmptyState
              icon={<FileText className="h-8 w-8" />}
              title="No articles in pipeline"
              description="Generate content from a strategy to see it here."
            />
          }
        />
      </div>
    </div>
  );
}
