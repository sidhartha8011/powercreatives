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
  saveCell: (id: number, field: string, value: string | number, display?: string) => Promise<void>;
  /** Create a draft post/page and refresh. */
  quickCreate: (type: 'post' | 'page') => Promise<void>;
  /** Trash selected ids and refresh. */
  bulkDelete: (ids: number[]) => Promise<void>;
  /** Duplicate selected ids (each → a draft copy) and refresh. */
  bulkDuplicate: (ids: number[]) => Promise<void>;
  /** AI-suggest a field value (NOT saved — caller stages it). Resolves to the text.
   *  Optional model/provider override routes generation to a specific model. */
  generateField: (id: number, field: string, model?: string, provider?: string, templateId?: number) => Promise<string>;
  /** Scan a row's links (internal/external/broken) and patch the counts into the cache. */
  scanLinks: (id: number) => Promise<void>;
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
    return (listQuery.data as SeoRow[]).map((r) => ({ ...r, id: Number(r.id), authorId: Number(r.authorId), featuredImageId: Number(r.featuredImageId ?? 0) }));
  }, [listQuery.data]);

  const options = useMemo<SeoOptions | null>(
    () => (optionsQuery.data ? (optionsQuery.data as SeoOptions) : null),
    [optionsQuery.data],
  );

  const saveCellMutation = trpc.seo.saveCell.useMutation();
  const quickCreateMutation = trpc.seo.quickCreate.useMutation();
  const bulkDeleteMutation = trpc.seo.bulkDelete.useMutation();
  const duplicateMutation = trpc.seo.duplicateContent.useMutation();
  const generateMutation = trpc.seo.generateField.useMutation();
  const scanMutation = trpc.seo.scanLinks.useMutation();

  const invalidate = useCallback(
    () => queryClient.invalidateQueries({ queryKey: LIST_PREFIX }),
    [queryClient],
  );

  /** The cache patch for a saved cell. `author` is special: its VALUE is a user id,
   *  but the row keeps the id in `authorId` and the display NAME in `author` —
   *  writing the raw value into `author` put "3" in the cell (Filip: "you get just
   *  ones and threes and whatever"). `display` carries the picked option's name. */
  const cellPatch = (field: string, value: string | number, display?: string): Partial<SeoRow> =>
    field === 'author'
      ? { authorId: Number(value), ...(display ? { author: display } : {}) }
      : { [field]: value } as Partial<SeoRow>;

  const saveCell = useCallback(
    (id: number, field: string, value: string | number, display?: string): Promise<void> => {
      // OPTIMISTIC: paint the change immediately — the round trip is what made the
      // dropdown feel dead ("extremely laggy"); a failure rolls back via invalidate.
      queryClient.setQueriesData<SeoRow[]>({ queryKey: LIST_PREFIX }, (prev) =>
        Array.isArray(prev)
          ? prev.map((r) => (Number(r.id) === id ? { ...r, ...cellPatch(field, value, display) } : r))
          : prev,
      );
      return saveCellMutation
        .mutateAsync({ id, field, value })
        .then((res: any) => {
          // Reflect the server's canonical value (e.g. slug dedup) in the cache.
          const stored = res?.value ?? value;
          queryClient.setQueriesData<SeoRow[]>({ queryKey: LIST_PREFIX }, (prev) =>
            Array.isArray(prev)
              ? prev.map((r) => (Number(r.id) === id ? { ...r, ...cellPatch(field, stored, display) } : r))
              : prev,
          );
        })
        .catch((err: unknown) => {
          toast.error(err instanceof Error ? err.message : 'Failed to save');
          void invalidate();
          throw err;
        });
    },
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

  const bulkDuplicate = useCallback(
    async (ids: number[]): Promise<void> => {
      if (ids.length === 0) return;
      let ok = 0;
      for (const id of ids) {
        try {
          await duplicateMutation.mutateAsync({ id });
          ok += 1;
        } catch { /* continue with the rest */ }
      }
      void invalidate();
      if (ok > 0) toast.success(`Duplicated ${ok} item(s)`);
      if (ok < ids.length) toast.error(`Failed to duplicate ${ids.length - ok} item(s)`);
    },
    [duplicateMutation, invalidate],
  );

  const generateField = useCallback(
    (id: number, field: string, model?: string, provider?: string, templateId?: number): Promise<string> =>
      generateMutation
        .mutateAsync({ id, field, ...(model ? { model, provider } : {}), ...(templateId ? { templateId } : {}) })
        .then((res: any) => {
          // The server used the SHIPPED default because the selected template
          // returned a JSON envelope instead of a value — say so once, so the
          // template gets fixed instead of silently costing a second call each time.
          if (res?.healed) {
            toast.warning('The selected template for this column could not produce a value — the shipped default was used instead. Fix it in Templates → SEO.', { id: 'seo-template-healed' });
          }
          // The PICKED template was set aside before any model call (its prompt demands
          // the JSON envelope, which a single-value cell can never hold).
          if (res?.templateIgnored) {
            toast.warning('The template you picked for this column asks for a JSON envelope, not a value, so it was not used — the default ran instead. Fix it in Templates → SEO.', { id: 'seo-template-ignored' });
          }
          return String(res?.value ?? '');
        })
        .catch((err: unknown) => {
          toast.error(err instanceof Error ? err.message : 'AI generation failed');
          throw err;
        }),
    [generateMutation],
  );

  const scanLinks = useCallback(
    (id: number): Promise<void> =>
      scanMutation
        .mutateAsync({ id })
        .then((res: any) => {
          queryClient.setQueriesData<SeoRow[]>({ queryKey: LIST_PREFIX }, (prev) =>
            Array.isArray(prev)
              ? prev.map((r) =>
                  Number(r.id) === id
                    ? {
                        ...r,
                        internalLinks: Number(res?.internal ?? 0),
                        externalLinks: Number(res?.external ?? 0),
                        brokenLinks: Number(res?.broken ?? 0),
                        linksScannedAt: String(res?.scannedAt ?? ''),
                      }
                    : r,
                )
              : prev,
          );
        })
        .catch((err: unknown) => {
          toast.error(err instanceof Error ? err.message : 'Link scan failed');
          throw err;
        }),
    [scanMutation, queryClient],
  );

  return {
    rows,
    options,
    isLoading: listQuery.isLoading,
    refetch: listQuery.refetch,
    saveCell,
    quickCreate,
    bulkDelete,
    bulkDuplicate,
    generateField,
    scanLinks,
  };
}
