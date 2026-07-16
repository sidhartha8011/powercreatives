/**
 * The page's keyword bucket — additional keywords that ride EVERY optimize
 * run (owner law 2026-07-13). ONE instance lives in the editor
 * (SectionModal): the drawer edits it, startAiReview reads it.
 *
 * OPTIMISTIC BY LAW (owner live find 2026-07-14: deletes failed with zero
 * feedback): every add/remove updates the UI IMMEDIATELY via a local
 * mirror; the save then confirms against the server — success re-syncs,
 * failure reverts AND says so out loud. A silent no-op cannot happen.
 */

import { useState } from 'react';
import { toast } from 'sonner';
import { trpc } from '@/lib/trpc';

export interface KeywordBucket {
  keywords: string[];
  add: (keyword: string) => void;
  remove: (keyword: string) => void;
  saving: boolean;
}

export function useKeywordBucket(siteId: number, postId: number, enabled: boolean): KeywordBucket {
  const query = trpc.optimizer.keywords.useQuery({ siteId, postId }, { enabled, staleTime: 0 });
  const save = trpc.optimizer.keywordsSave.useMutation();
  const serverKeywords: string[] = Array.isArray((query.data as any)?.keywords)
    ? ((query.data as any).keywords as string[])
    : [];
  // The optimistic mirror: non-null while an edit is in flight (or failed
  // and reverted); null = the server list is the truth.
  const [local, setLocal] = useState<string[] | null>(null);
  const keywords = local ?? serverKeywords;

  const persist = (next: string[], verb: string) => {
    setLocal(next); // the UI moves NOW
    save.mutateAsync({ siteId, postId, keywords: next })
      .then(() => query.refetch())
      .then(() => setLocal(null)) // server truth confirmed — mirror retires
      .catch((e: unknown) => {
        setLocal(null); // revert to the server truth
        toast.error(`Could not ${verb} the keyword — ${e instanceof Error ? e.message : 'the save failed'}`);
      });
  };

  return {
    keywords,
    add: (keyword) => {
      const kw = keyword.trim();
      if (kw !== '' && !keywords.includes(kw)) persist([...keywords, kw], 'add');
    },
    remove: (keyword) => persist(keywords.filter((k) => k !== keyword), 'remove'),
    saving: save.isPending ?? false,
  };
}
