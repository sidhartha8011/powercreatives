/**
 * useRemoteSeoContent — read + inline-edit a CONNECTED remote site's posts/pages
 * SEO, proxied through the site connector (Phase 1).
 *
 * `siteId = null` disables the query (used when the local site is selected).
 * Title/slug work on any connected site; SEO meta needs the remote to expose those
 * meta keys in REST (the connector plugin, or a REST-aware SEO plugin).
 */

import { useCallback, useMemo } from 'react';
import { toast } from 'sonner';
import { useQueryClient } from '@tanstack/react-query';

import { trpc } from '@/lib/trpc';
import type { SeoRow } from '../types';

const REMOTE_PREFIX = ['seo', 'remoteContent'] as const;

export interface UseRemoteSeoContentResult {
  rows: SeoRow[];
  isLoading: boolean;
  /** Persist one cell to the remote; patches the cache to the server's value. */
  saveCell: (id: number, field: string, value: string | number) => Promise<void>;
  /** AI-suggest a field value on the remote (NOT saved — caller stages it). */
  generateField: (id: number, field: string, model?: string, provider?: string, templateId?: number) => Promise<string>;
  /** Create a draft post/page on the remote and refresh. */
  quickCreate: (type: 'post' | 'page') => Promise<void>;
  /** Scan a remote post's links (analysis on the hub); patches the counts into the cache. */
  scanLinks: (id: number) => Promise<void>;
  /** Set a remote post's Schema.org types; patches the row cache to the server's value. */
  setSchema: (id: number, types: string[]) => Promise<void>;
  /** Trash the given posts/pages on the remote (bulk), then refresh the list. */
  deleteRows: (ids: number[]) => Promise<void>;
  /** Duplicate the given posts/pages on the remote (each → a draft copy), then refresh. */
  bulkDuplicate: (ids: number[]) => Promise<void>;
  /** Upload an image (by URL) to the remote + set it as the post's featured image. */
  setFeaturedImage: (id: number, imageUrl: string) => Promise<void>;
}

export function useRemoteSeoContent(siteId: number | null): UseRemoteSeoContentResult {
  const queryClient = useQueryClient();

  // SWR (gap fad81ea P4): the STORED copy renders the table instantly
  // (hub-side cache, milliseconds); the live fetch runs in parallel and
  // its rows SWAP IN when they land — the live truth always wins, the
  // stored copy only ever bridges the wait. A cache miss answers
  // {miss:true} (not an array) and the normal loading state shows.
  const cachedQuery = trpc.seo.remoteContent.useQuery(
    { siteId: siteId ?? 0, cached: 1 },
    { enabled: siteId != null, staleTime: Infinity },
  ) as { data?: unknown };
  const listQuery = trpc.seo.remoteContent.useQuery(
    { siteId: siteId ?? 0 },
    { enabled: siteId != null },
  ) as { data?: unknown; isLoading: boolean };

  const rows = useMemo<SeoRow[]>(() => {
    const source = Array.isArray(listQuery.data)
      ? listQuery.data
      : (Array.isArray(cachedQuery.data) ? cachedQuery.data : null);
    if (!source) return [];
    return (source as SeoRow[]).map((r) => ({ ...r, id: Number(r.id), authorId: Number(r.authorId), featuredImageId: Number(r.featuredImageId ?? 0) }));
  }, [listQuery.data, cachedQuery.data]);

  const saveMutation = trpc.seo.remoteSaveCell.useMutation();

  const saveCell = useCallback(
    (id: number, field: string, value: string | number): Promise<void> => {
      const type = rows.find((r) => Number(r.id) === id)?.type === 'page' ? 'page' : 'post';
      return saveMutation
        .mutateAsync({ siteId: siteId ?? 0, postId: id, field, value, type })
        .then((res: any) => {
          const stored = res?.value ?? value;
          queryClient.setQueriesData<SeoRow[]>({ queryKey: REMOTE_PREFIX }, (prev) =>
            Array.isArray(prev) ? prev.map((r) => (Number(r.id) === id ? { ...r, [field]: stored } : r)) : prev,
          );
        })
        .catch((err: unknown) => {
          toast.error(err instanceof Error ? err.message : 'Failed to save to the remote site');
          throw err;
        });
    },
    [rows, saveMutation, siteId, queryClient],
  );

  const generateMutation = trpc.seo.remoteGenerateField.useMutation();

  const generateField = useCallback(
    (id: number, field: string, model?: string, provider?: string, templateId?: number): Promise<string> => {
      const type = rows.find((r) => Number(r.id) === id)?.type === 'page' ? 'page' : 'post';
      return generateMutation
        .mutateAsync({ siteId: siteId ?? 0, postId: id, field, type, model, provider, templateId })
        .then((res: any) => String(res?.value ?? ''));
    },
    [rows, generateMutation, siteId],
  );

  const createMutation = trpc.seo.remoteCreate.useMutation();
  const quickCreate = useCallback(
    (type: 'post' | 'page'): Promise<void> =>
      createMutation
        .mutateAsync({ siteId: siteId ?? 0, type })
        .then(() => {
          queryClient.invalidateQueries({ queryKey: REMOTE_PREFIX });
          toast.success(`New ${type} created`);
        })
        .catch((err: unknown) => {
          toast.error(err instanceof Error ? err.message : 'Failed to create');
          throw err;
        }),
    [createMutation, siteId, queryClient],
  );

  const scanMutation = trpc.seo.remoteScanLinks.useMutation();
  const scanLinks = useCallback(
    (id: number): Promise<void> => {
      const type = rows.find((r) => Number(r.id) === id)?.type === 'page' ? 'page' : 'post';
      return scanMutation
        .mutateAsync({ siteId: siteId ?? 0, postId: id, type })
        .then((res: any) => {
          queryClient.setQueriesData<SeoRow[]>({ queryKey: REMOTE_PREFIX }, (prev) =>
            Array.isArray(prev)
              ? prev.map((r) => (Number(r.id) === id ? {
                  ...r,
                  internalLinks: Number(res?.internal ?? 0),
                  externalLinks: Number(res?.external ?? 0),
                  brokenLinks: Number(res?.broken ?? 0),
                  linksScannedAt: String(res?.scannedAt ?? ''),
                } : r))
              : prev,
          );
        })
        .catch((err: unknown) => {
          toast.error(err instanceof Error ? err.message : 'Link scan failed');
          throw err;
        });
    },
    [rows, scanMutation, siteId, queryClient],
  );

  const schemaMutation = trpc.seo.remoteSetSchema.useMutation();
  const setSchema = useCallback(
    (id: number, types: string[]): Promise<void> => {
      const type = rows.find((r) => Number(r.id) === id)?.type === 'page' ? 'page' : 'post';
      return schemaMutation
        .mutateAsync({ siteId: siteId ?? 0, postId: id, type, types })
        .then((res: any) => {
          const saved = Array.isArray(res?.types) ? (res.types as string[]) : types;
          queryClient.setQueriesData<SeoRow[]>({ queryKey: REMOTE_PREFIX }, (prev) =>
            Array.isArray(prev) ? prev.map((r) => (Number(r.id) === id ? { ...r, schemaTypes: saved } : r)) : prev,
          );
        })
        .catch((err: unknown) => {
          toast.error(err instanceof Error ? err.message : 'Failed to save schema');
          throw err;
        });
    },
    [rows, schemaMutation, siteId, queryClient],
  );

  const deleteMutation = trpc.seo.remoteDelete.useMutation();
  const deleteRows = useCallback(
    (ids: number[]): Promise<void> => {
      if (ids.length === 0) return Promise.resolve();
      const jobs = ids.map((id) => {
        const type = rows.find((r) => Number(r.id) === id)?.type === 'page' ? 'page' : 'post';
        return deleteMutation.mutateAsync({ siteId: siteId ?? 0, postId: id, type });
      });
      return Promise.allSettled(jobs).then((results) => {
        queryClient.invalidateQueries({ queryKey: REMOTE_PREFIX });
        const failed = results.filter((r) => r.status === 'rejected').length;
        if (failed > 0) {
          toast.error(`${failed} of ${ids.length} item(s) could not be deleted`);
        } else {
          toast.success(`${ids.length} item(s) moved to trash`);
        }
      });
    },
    [rows, deleteMutation, siteId, queryClient],
  );

  const duplicateMutation = trpc.seo.remoteDuplicate.useMutation();
  const bulkDuplicate = useCallback(
    (ids: number[]): Promise<void> => {
      if (ids.length === 0) return Promise.resolve();
      const jobs = ids.map((id) => {
        const type = rows.find((r) => Number(r.id) === id)?.type === 'page' ? 'page' : 'post';
        return duplicateMutation.mutateAsync({ siteId: siteId ?? 0, postId: id, type });
      });
      return Promise.allSettled(jobs).then((results) => {
        queryClient.invalidateQueries({ queryKey: REMOTE_PREFIX });
        const failed = results.filter((r) => r.status === 'rejected').length;
        const ok = ids.length - failed;
        if (ok > 0) toast.success(`Duplicated ${ok} item(s)`);
        if (failed > 0) toast.error(`${failed} of ${ids.length} item(s) could not be duplicated`);
      });
    },
    [rows, duplicateMutation, siteId, queryClient],
  );

  const featuredMutation = trpc.seo.remoteSetFeatured.useMutation();
  const setFeaturedImage = useCallback(
    (id: number, imageUrl: string): Promise<void> => {
      const type = rows.find((r) => Number(r.id) === id)?.type === 'page' ? 'page' : 'post';
      return featuredMutation
        .mutateAsync({ siteId: siteId ?? 0, postId: id, type, imageUrl })
        .then((res: any) => {
          queryClient.setQueriesData<SeoRow[]>({ queryKey: REMOTE_PREFIX }, (prev) =>
            Array.isArray(prev)
              ? prev.map((r) => (Number(r.id) === id ? {
                  ...r,
                  featuredImage: String(res?.featuredImage ?? ''),
                  featuredImageId: Number(res?.featuredImageId ?? 0),
                } : r))
              : prev,
          );
          toast.success('Featured image set on the connected site');
        })
        .catch((err: unknown) => {
          toast.error(err instanceof Error ? err.message : 'Failed to set featured image');
          throw err;
        });
    },
    [rows, featuredMutation, siteId, queryClient],
  );

  // Loading shows ONLY when neither copy exists — a cache hit renders the
  // table instantly while the live fetch keeps running silently.
  return { rows, isLoading: !!listQuery.isLoading && !Array.isArray(cachedQuery.data), saveCell, generateField, quickCreate, scanLinks, setSchema, deleteRows, bulkDuplicate, setFeaturedImage };
}
