/**
 * useApprovalArticles — single owner of articles data + status mutation.
 *
 * Wraps the tRPC list query, derives counts, and exposes a typed status-
 * change handler. Cache key preserved (trpc.articles.list.useQuery) so any
 * other consumer keeps sharing the cache entry.
 */

import { useCallback, useMemo } from 'react';
import { toast } from 'sonner';

import type { StatusKey } from '@/components/shared/design-tokens';
import { trpc } from '@/lib/trpc';

import type { Article } from '../types';

export type ArticleCountsByStatus = Record<StatusKey, number>;

export interface UseApprovalArticlesResult {
  articles: Article[];
  isLoading: boolean;
  error: Error | null;
  refetch: () => void;
  countsByStatus: ArticleCountsByStatus;
  changeStatus: (articleId: number, next: StatusKey) => void;
}

function emptyCounts(): ArticleCountsByStatus {
  return { draft: 0, review: 0, ready: 0, published: 0 };
}

export function useApprovalArticles(): UseApprovalArticlesResult {
  const query = trpc.articles.list.useQuery() as {
    data?: unknown;
    isLoading: boolean;
    error: Error | null;
    refetch: () => void;
  };

  const articles = useMemo<Article[]>(
    () => (Array.isArray(query.data) ? (query.data as Article[]) : []),
    [query.data]
  );

  const countsByStatus = useMemo<ArticleCountsByStatus>(() => {
    const counts = emptyCounts();
    for (const article of articles) {
      if (article.status in counts) counts[article.status] += 1;
    }
    return counts;
  }, [articles]);

  const mutation = trpc.articles.update.useMutation({
    onSuccess: () => {
      toast.success('Article status updated');
      query.refetch();
    },
    onError: (err: unknown) => {
      const message = err instanceof Error ? err.message : 'Failed to update article';
      toast.error(message);
      query.refetch();
    },
  });

  const changeStatus = useCallback(
    (articleId: number, next: StatusKey) => {
      mutation.mutate({ id: articleId, status: next });
    },
    [mutation]
  );

  return {
    articles,
    isLoading: query.isLoading,
    error: query.error,
    refetch: query.refetch,
    countsByStatus,
    changeStatus,
  };
}
