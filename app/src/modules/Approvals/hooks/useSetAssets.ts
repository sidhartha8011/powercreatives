/**
 * useSetAssets — what is inside an approval card, derived once.
 *
 * An approval card is a COLLECTION: its `snapshot` holds four buckets, and both
 * the public client page and the admin modal need the same merged, ordered view
 * of them plus the same counts. This is that derivation, in one place, so the
 * two surfaces cannot drift.
 *
 * Order is media → copy → articles → custom, matching the order the board's
 * SQL item summary emits, so a card's expanded list and its opened contents
 * agree on sequence.
 */

import { useMemo } from 'react';

import type { ApprovalSet } from '../types';

/** One item of a card, with its bucket discriminator and raw payload. */
export interface MergedAsset {
  id: string;
  type: 'media' | 'copy' | 'article' | 'custom';
  data: any;
}

export interface SetAssetCounts {
  all: number;
  images: number;
  videos: number;
  copy: number;
  articles: number;
  custom: number;
}

export interface SetAssets {
  mediaAssets: any[];
  copyAssets: any[];
  articleAssets: any[];
  customAssets: any[];
  allMergedAssets: MergedAsset[];
  counts: SetAssetCounts;
  /** First media URL — the fallback pairing for a copy card with no partner. */
  primaryMediaUrl: string;
}

/**
 * `isVideo` is injected rather than imported so this hook stays free of the
 * client page it was extracted from — importing it back would recreate the
 * circular dependency the extraction exists to remove.
 */
export function useSetAssets(
  set: ApprovalSet | null | undefined,
  isVideo: (item: any) => boolean
): SetAssets {
  const mediaAssets = useMemo(() => set?.snapshot?.media || [], [set]);
  const copyAssets = useMemo(() => set?.snapshot?.copy || [], [set]);
  const articleAssets = useMemo(() => set?.snapshot?.articles || [], [set]);
  const customAssets = useMemo(() => set?.snapshot?.custom || [], [set]);

  const counts = useMemo<SetAssetCounts>(() => {
    const videos = mediaAssets.filter((item: any) => isVideo(item)).length;
    return {
      all: mediaAssets.length + copyAssets.length + articleAssets.length + customAssets.length,
      images: mediaAssets.length - videos,
      videos,
      copy: copyAssets.length,
      articles: articleAssets.length,
      custom: customAssets.length,
    };
  }, [mediaAssets, copyAssets, articleAssets, customAssets, isVideo]);

  const allMergedAssets = useMemo<MergedAsset[]>(() => [
    ...mediaAssets.map((item: any) => ({ id: item.id, type: 'media' as const, data: item })),
    ...copyAssets.map((item: any) => ({ id: item.id, type: 'copy' as const, data: item })),
    ...articleAssets.map((item: any) => ({ id: item.id, type: 'article' as const, data: item })),
    ...customAssets.map((item: any) => ({ id: item.id, type: 'custom' as const, data: item })),
  ], [mediaAssets, copyAssets, articleAssets, customAssets]);

  return {
    mediaAssets,
    copyAssets,
    articleAssets,
    customAssets,
    allMergedAssets,
    counts,
    primaryMediaUrl: mediaAssets[0]?.url || '',
  };
}
