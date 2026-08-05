/**
 * Approval-set sharing + list-cache — ONE source for the three things every
 * create flow needs, so Ads / Copy / Image / the custom card can't drift apart.
 *
 *   1. The list query key. Both the board's hook and every create dialog write
 *      the same cache; they must agree on the key or the writes land nowhere.
 *   2. The public client board URL for a token (was hand-built in three files).
 *   3. `useApprovalSetsCache()` — put a freshly created set on the board NOW,
 *      then reconcile with the server.
 *
 * Why both a cache write and an invalidate: the write makes the card appear
 * instantly (the create response already carries the full row, so there is
 * nothing to wait for), and the invalidate is the honest reconcile — it also
 * covers the case where the board has never been mounted, where there is no
 * cache entry for `setQueriesData` to update.
 */

import { useCallback } from 'react';
import { useQueryClient } from '@tanstack/react-query';

/**
 * Cache-key PREFIX for the board's list query. The tRPC adapter builds the full
 * TanStack key as `[...path, input]`, so a no-input call is
 * `['approvals', 'listSets', undefined]` — always operate by PREFIX match.
 */
export const APPROVAL_SETS_QUERY_KEY = ['approvals', 'listSets'] as const;

interface PcmConfigLike {
  shortcodePageUrl?: string;
}

/**
 * The public client-review URL for a share token: the page hosting the
 * [power_creatives] shortcode + ?pcm_public_token=…
 *
 * Mirrors PCM_Approvals_Service::build_share_url() on the server.
 */
export function buildPublicBoardUrl(token: string, assetId?: string | null): string {
  const cfg = (window as unknown as { pcmConfig?: PcmConfigLike }).pcmConfig;
  const explicit = cfg?.shortcodePageUrl;
  // Documented dev fallback — not a silent band-aid.
  const baseUrl =
    typeof explicit === 'string' && explicit.length > 0
      ? explicit
      : `${window.location.origin}/`;
  const separator = baseUrl.includes('?') ? '&' : '?';
  const url = `${baseUrl}${separator}pcm_public_token=${token}`;
  /**
   * Optional deep link to ONE asset within the set.
   *
   * A DEEP LINK, not an access grant: the recipient still opens the whole set
   * under the same token and can see everything in it. Scoping access to a
   * single asset would need a per-asset token and its own storage — a different
   * feature, and not what "share this item" means here.
   */
  return assetId ? `${url}&pcm_asset=${encodeURIComponent(assetId)}` : url;
}

/** Minimum shape the board needs from a created set row. */
export interface CreatedSetRow {
  id: number | string;
  [key: string]: unknown;
}

export interface ApprovalSetsCache {
  /** Prepend a just-created set so the board shows it immediately. */
  addCreatedSet: (row: CreatedSetRow) => void;
  /** Ask the server for the truth (also covers a never-mounted board). */
  invalidate: () => void;
  /** Both, in the order that makes the card appear instantly and stay correct. */
  registerCreated: (row: CreatedSetRow) => void;
}

export function useApprovalSetsCache(): ApprovalSetsCache {
  const queryClient = useQueryClient();

  const addCreatedSet = useCallback(
    (row: CreatedSetRow) => {
      if (row?.id == null) return;
      queryClient.setQueriesData<CreatedSetRow[]>(
        { queryKey: APPROVAL_SETS_QUERY_KEY },
        (prev) => {
          if (!Array.isArray(prev)) return prev;
          // Never double-insert: a refetch may already have landed the row.
          if (prev.some((s) => Number(s.id) === Number(row.id))) return prev;
          // Newest first — matches the board's default sort and the server's
          // `ORDER BY createdAt DESC`.
          return [row, ...prev];
        }
      );
    },
    [queryClient]
  );

  const invalidate = useCallback(() => {
    void queryClient.invalidateQueries({ queryKey: APPROVAL_SETS_QUERY_KEY });
  }, [queryClient]);

  const registerCreated = useCallback(
    (row: CreatedSetRow) => {
      addCreatedSet(row);
      // Mark stale WITHOUT refetching now. The row is already spliced in, so a
      // refetch adds nothing the user can see — it only fires another REST call
      // into the same moment the user clicked, and this box runs two PHP workers,
      // so that request queues behind the one still finishing. The list
      // re-fetches on its next mount/observer instead.
      void queryClient.invalidateQueries({
        queryKey: APPROVAL_SETS_QUERY_KEY,
        refetchType: 'none',
      });
    },
    [addCreatedSet, queryClient]
  );

  return { addCreatedSet, invalidate, registerCreated };
}
