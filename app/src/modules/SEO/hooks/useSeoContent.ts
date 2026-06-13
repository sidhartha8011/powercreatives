/**
 * useSeoContent — data + mutations for the SEO content workbench.
 *
 * Wraps the seo REST endpoints: content list, dropdown options, inline
 * cell-save (optimistic cache patch), quick-create, and bulk-delete.
 * No JSX — pure data + actions.
 */

import { useCallback, useMemo } from 'react';
import { toast } from 'sonner';
import { useQueryClient } from '@tanstack/react-query';

import { trpc } from '@/lib/trpc';
import type { SeoRow, SeoOptions } from '../types';

const LIST_PREFIX = ['seo', 'listContent'] as const;

export interface UseSeoContentResult {
  rows: SeoRow[];
  options: SeoOptions | null;
  isLoading: boolean;
  refetch: () => void;
  /** Persist one cell; patches the cache to the server's canonical value. */
  saveCell: (id: number, field: string, value: string | number) => Promise<void>;
  /** Create a draft post/page and refresh. */
  quickCreate: (type: 'post' | 'page') => Promise<void>;
  /** Trash selected ids and refresh. */
  bulkDelete: (ids: number[]) => Promise<void>;
}

export function useSeoContent(): UseSeoContentResult {
  const queryClient = useQueryClient();

  const listQuery = trpc.seo.listContent.useQuery() as {
    data?: unknown;
    isLoading: boolean;
    refetch: () => void;
  };
  const optionsQuery = trpc.seo.contentOptions.useQuery() as { data?: unknown };

  const rows = useMemo<SeoRow[]>(() => {
    if (!Array.isArray(listQuery.data)) return [];
    return (listQuery.data as SeoRow[]).map((r) => ({ ...r, id: Number(r.id), authorId: Number(r.authorId) }));
  }, [listQuery.data]);

  const options = useMemo<SeoOptions | null>(
    () => (optionsQuery.data ? (optionsQuery.data as SeoOptions) : null),
    [optionsQuery.data],
  );

  const saveCellMutation = trpc.seo.saveCell.useMutation();
  const quickCreateMutation = trpc.seo.quickCreate.useMutation();
  const bulkDeleteMutation = trpc.seo.bulkDelete.useMutation();

  const invalidate = useCallback(
    () => queryClient.invalidateQueries({ queryKey: LIST_PREFIX }),
    [queryClient],
  );

  const saveCell = useCallback(
    (id: number, field: string, value: string | number): Promise<void> =>
      saveCellMutation
        .mutateAsync({ id, field, value })
        .then((res: any) => {
          // Reflect the server's canonical value (e.g. slug dedup) in the cache.
          const stored = res?.value ?? value;
          queryClient.setQueriesData<SeoRow[]>({ queryKey: LIST_PREFIX }, (prev) =>
            Array.isArray(prev)
              ? prev.map((r) => (Number(r.id) === id ? { ...r, [field]: stored } : r))
              : prev,
          );
        })
        .catch((err: unknown) => {
          toast.error(err instanceof Error ? err.message : 'Failed to save');
          void invalidate();
          throw err;
        }),
    [saveCellMutation, queryClient, invalidate],
  );

  const quickCreate = useCallback(
    (type: 'post' | 'page'): Promise<void> =>
      quickCreateMutation
        .mutateAsync({ type })
        .then(() => {
          void invalidate();
          toast.success(`New ${type} created`);
        })
        .catch((err: unknown) => {
          toast.error(err instanceof Error ? err.message : 'Failed to create');
          throw err;
        }),
    [quickCreateMutation, invalidate],
  );

  const bulkDelete = useCallback(
    (ids: number[]): Promise<void> =>
      bulkDeleteMutation
        .mutateAsync({ ids })
        .then((res: any) => {
          void invalidate();
          const n = Array.isArray(res?.deleted) ? res.deleted.length : ids.length;
          toast.success(`Trashed ${n} item(s)`);
        })
        .catch((err: unknown) => {
          toast.error(err instanceof Error ? err.message : 'Failed to delete');
          throw err;
        }),
    [bulkDeleteMutation, invalidate],
  );

  return {
    rows,
    options,
    isLoading: listQuery.isLoading,
    refetch: listQuery.refetch,
    saveCell,
    quickCreate,
    bulkDelete,
  };
}
