/**
 * The page's keyword bucket — additional keywords picked from GSC that
 * ride EVERY optimize run (owner law 2026-07-13). ONE instance lives in
 * the editor (SectionModal): the drawer edits it, startAiReview reads it —
 * no cache-sync question by construction.
 */

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
  const keywords: string[] = Array.isArray((query.data as any)?.keywords)
    ? ((query.data as any).keywords as string[])
    : [];

  const persist = (next: string[]) => {
    void save.mutateAsync({ siteId, postId, keywords: next }).then(() => query.refetch());
  };

  return {
    keywords,
    add: (keyword) => {
      const kw = keyword.trim();
      if (kw !== '' && !keywords.includes(kw)) persist([...keywords, kw]);
    },
    remove: (keyword) => persist(keywords.filter((k) => k !== keyword)),
    saving: save.isPending ?? false,
  };
}
