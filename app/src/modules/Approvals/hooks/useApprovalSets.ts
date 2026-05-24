/**
 * useApprovalSets — single owner of approval-set data + side effects.
 *
 * Wraps the tRPC query, derives counts, exposes typed handlers, and owns
 * the feedback-dialog open state. Cache key is preserved (the underlying
 * trpc.approvals.listSets.useQuery() is called verbatim) so any other
 * consumer reading the same query sees the same data.
 *
 * No JSX. No global side effects. Pure data + actions.
 */

import { useCallback, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { trpc } from '@/lib/trpc';

import { APPROVAL_STATUSES, type ApprovalSet, type ApprovalStatus } from '../types';

export type ApprovalSetCountsByStatus = Record<ApprovalStatus, number>;

export interface UseApprovalSetsResult {
  /** Always an array; never undefined. */
  sets: ApprovalSet[];
  isLoading: boolean;
  /** Network or parse error from the tRPC query, or null. */
  error: Error | null;
  refetch: () => void;

  /** Counts grouped by status — useful for tab headers / sidebar badges. */
  countsByStatus: ApprovalSetCountsByStatus;

  /** Build the public client-facing review URL for a set's token. */
  getPublicBoardUrl: (token: string) => string;
  /** Copy the public URL to the clipboard with a success toast. */
  copyShareLink: (token: string) => void;

  /** Currently-open feedback set, or null when the dialog is closed. */
  feedbackSet: ApprovalSet | null;
  openFeedback: (set: ApprovalSet) => void;
  closeFeedback: () => void;
}

/**
 * Site config injected by PCM_Admin into window.pcmConfig.
 * Documented in trpc.ts. We narrow it here without trusting the global.
 */
interface PcmConfig {
  shortcodePageUrl?: string;
}

function readShortcodePageUrl(): string {
  const cfg = (window as unknown as { pcmConfig?: PcmConfig }).pcmConfig;
  const explicit = cfg?.shortcodePageUrl;
  if (typeof explicit === 'string' && explicit.length > 0) return explicit;
  // Not a silent fallback — this is the documented default when the host
  // page hasn't injected its public board URL (e.g. dev/test environments).
  return `${window.location.origin}/`;
}

function emptyCounts(): ApprovalSetCountsByStatus {
  return APPROVAL_STATUSES.reduce(
    (acc, status) => {
      acc[status] = 0;
      return acc;
    },
    {} as ApprovalSetCountsByStatus
  );
}

export function useApprovalSets(): UseApprovalSetsResult {
  // tRPC query — adapter returns unknown; one boundary cast, documented.
  const query = trpc.approvals.listSets.useQuery() as {
    data?: unknown;
    isLoading: boolean;
    error: Error | null;
    refetch: () => void;
  };

  const sets = useMemo<ApprovalSet[]>(() => {
    return Array.isArray(query.data) ? (query.data as ApprovalSet[]) : [];
  }, [query.data]);

  const countsByStatus = useMemo<ApprovalSetCountsByStatus>(() => {
    const counts = emptyCounts();
    for (const set of sets) {
      if (set.status in counts) counts[set.status] += 1;
    }
    return counts;
  }, [sets]);

  const getPublicBoardUrl = useCallback((token: string): string => {
    const baseUrl = readShortcodePageUrl();
    const separator = baseUrl.includes('?') ? '&' : '?';
    return `${baseUrl}${separator}pcm_public_token=${token}`;
  }, []);

  const copyShareLink = useCallback(
    (token: string) => {
      const url = getPublicBoardUrl(token);
      void navigator.clipboard
        .writeText(url)
        .then(() => toast.success('Client link copied to clipboard!'))
        .catch(() => toast.error('Failed to copy link.'));
    },
    [getPublicBoardUrl]
  );

  const [feedbackSet, setFeedbackSet] = useState<ApprovalSet | null>(null);
  const openFeedback = useCallback((set: ApprovalSet) => setFeedbackSet(set), []);
  const closeFeedback = useCallback(() => setFeedbackSet(null), []);

  return {
    sets,
    isLoading: query.isLoading,
    error: query.error,
    refetch: query.refetch,
    countsByStatus,
    getPublicBoardUrl,
    copyShareLink,
    feedbackSet,
    openFeedback,
    closeFeedback,
  };
}
