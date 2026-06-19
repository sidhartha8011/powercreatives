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
}

export function useRemoteSeoContent(siteId: number | null): UseRemoteSeoContentResult {
  const queryClient = useQueryClient();

  const listQuery = trpc.seo.remoteContent.useQuery(
    { siteId: siteId ?? 0 },
    { enabled: siteId != null },
  ) as { data?: unknown; isLoading: boolean };

  const rows = useMemo<SeoRow[]>(() => {
    if (!Array.isArray(listQuery.data)) return [];
    return (listQuery.data as SeoRow[]).map((r) => ({ ...r, id: Number(r.id), authorId: Number(r.authorId) }));
  }, [listQuery.data]);

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

  return { rows, isLoading: !!listQuery.isLoading, saveCell, generateField };
}
