/**
 * useViews — saved SEO table "Views" (per user, persisted via the seo REST API).
 *
 * A view captures the table's column visibility + active per-column filters so
 * the user can re-apply a named configuration. Backed by `pcm/v1/seo/views`.
 */

import { useCallback, useMemo } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { trpc } from '@/lib/trpc';

export interface ViewConfig {
  /** columnKey → visible (missing = visible). */
  columns?: Record<string, boolean>;
  /** columnKey → active filter value. */
  filters?: Record<string, string>;
}

export interface SeoView {
  id: number;
  name: string;
  config: ViewConfig;
  /** Whether this is the user's default view (auto-applied on load). */
  isDefault: boolean;
  isPinned: boolean;
}

const LIST_KEY = ['seo', 'listViews'] as const;

export interface UseViewsResult {
  views: SeoView[];
  isLoading: boolean;
  saveView: (name: string, config: ViewConfig) => Promise<SeoView | null>;
  removeView: (id: number) => Promise<void>;
  setDefaultView: (id: number, isDefault: boolean) => Promise<void>;
  renameView: (id: number, name: string) => Promise<void>;
  setPinnedView: (id: number, isPinned: boolean) => Promise<void>;
}

export function useViews(): UseViewsResult {
  const queryClient = useQueryClient();

  const listQuery = trpc.seo.listViews.useQuery() as { data?: unknown; isLoading: boolean };
  const createMutation = trpc.seo.createView.useMutation();
  const deleteMutation = trpc.seo.deleteView.useMutation();
  const setDefaultMutation = trpc.seo.setDefaultView.useMutation();
  const renameMutation = trpc.seo.renameView.useMutation();
  const pinMutation = trpc.seo.setPinnedView.useMutation();

  const views = useMemo<SeoView[]>(() => {
    if (!Array.isArray(listQuery.data)) return [];
    return (listQuery.data as SeoView[]).map((v) => ({
      id: Number(v.id),
      name: String(v.name ?? ''),
      config: (v.config ?? {}) as ViewConfig,
      isDefault: Boolean((v as { isDefault?: unknown }).isDefault),
      isPinned: Boolean((v as { isPinned?: unknown }).isPinned),
    }));
  }, [listQuery.data]);

  const invalidate = useCallback(
    () => queryClient.invalidateQueries({ queryKey: LIST_KEY }),
    [queryClient],
  );

  const saveView = useCallback(
    (name: string, config: ViewConfig): Promise<SeoView | null> =>
      createMutation
        .mutateAsync({ name, config })
        .then((v: any) => {
          void invalidate();
          toast.success(`View “${name}” saved`);
          return v as SeoView;
        })
        .catch((err: unknown) => {
          toast.error(err instanceof Error ? err.message : 'Failed to save view');
          return null;
        }),
    [createMutation, invalidate],
  );

  const removeView = useCallback(
    (id: number): Promise<void> =>
      deleteMutation
        .mutateAsync({ id })
        .then(() => {
          void invalidate();
          toast.success('View deleted');
        })
        .catch((err: unknown) => {
          toast.error(err instanceof Error ? err.message : 'Failed to delete view');
        }),
    [deleteMutation, invalidate],
  );

  const setDefaultView = useCallback(
    (id: number, isDefault: boolean): Promise<void> =>
      setDefaultMutation
        .mutateAsync({ id, isDefault })
        .then(() => {
          void invalidate();
          toast.success(isDefault ? 'Default view set' : 'Default view cleared');
        })
        .catch((err: unknown) => {
          toast.error(err instanceof Error ? err.message : 'Failed to update default view');
        }),
    [setDefaultMutation, invalidate],
  );

  const renameView = useCallback(
    (id: number, name: string): Promise<void> =>
      renameMutation
        .mutateAsync({ id, name })
        .then(() => {
          void invalidate();
          toast.success('View renamed');
        })
        .catch((err: unknown) => {
          toast.error(err instanceof Error ? err.message : 'Failed to rename view');
        }),
    [renameMutation, invalidate],
  );

  const setPinnedView = useCallback(
    (id: number, isPinned: boolean): Promise<void> =>
      pinMutation
        .mutateAsync({ id, isPinned })
        .then(() => { void invalidate(); })
        .catch((err: unknown) => {
          toast.error(err instanceof Error ? err.message : 'Failed to pin view');
        }),
    [pinMutation, invalidate],
  );

  return { views, isLoading: listQuery.isLoading, saveView, removeView, setDefaultView, renameView, setPinnedView };
}
