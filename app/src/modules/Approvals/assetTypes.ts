/**
 * Asset types — ONE registry.
 *
 * An approval card is a collection of small assets, and each asset has a type.
 * Before this file that type was spelled out by hand in ~180 places across PHP
 * and TS, in two different vocabularies, so "add another kind of asset" meant an
 * edit everywhere instead of an entry here.
 *
 * Every NEW consumer reads this. Existing hand-written sites are migrated only
 * when they are independently touched — deliberately, so nothing is rewritten
 * wholesale for its own sake.
 *
 * KEYED ON THE STORAGE BUCKET NAME, which is what the server sends. Note the
 * board's summary emits the bucket (`articles`) while the client view's merged
 * list uses a singular discriminator (`article`); `assetType()` accepts either,
 * so a caller never has to know which vocabulary it is holding.
 */

import { FileText, Image as ImageIcon, Type, type LucideIcon } from 'lucide-react';

export interface AssetTypeDef {
  /** Storage bucket in `snapshot`, and the id the board summary sends. */
  id: 'media' | 'copy' | 'articles' | 'custom';
  /** Singular label shown to a person. English only, per the standing UI law. */
  label: string;
  icon: LucideIcon;
  /** Where this bucket's approved ids live in `reviewFeedback`. */
  approvalKey: 'approvedVisualIds' | 'approvedCopyIds' | 'approvedArticleIds' | 'approvedCustomIds';
}

export const ASSET_TYPES: Readonly<Record<AssetTypeDef['id'], AssetTypeDef>> = {
  media: { id: 'media', label: 'Image', icon: ImageIcon, approvalKey: 'approvedVisualIds' },
  copy: { id: 'copy', label: 'Copy', icon: Type, approvalKey: 'approvedCopyIds' },
  articles: { id: 'articles', label: 'Article', icon: FileText, approvalKey: 'approvedArticleIds' },
  custom: { id: 'custom', label: 'Document', icon: FileText, approvalKey: 'approvedCustomIds' },
};

/** Singular discriminators used by the client view's merged asset list. */
const SINGULAR_ALIASES: Readonly<Record<string, AssetTypeDef['id']>> = {
  article: 'articles',
  image: 'media',
  video: 'media',
};

/**
 * Resolve a type from either vocabulary. Falls back to `custom` rather than
 * throwing: an unknown type must still render as *something* in a list, and a
 * card that silently drops an item is worse than one labelled generically.
 */
export function assetType(type: string | undefined | null): AssetTypeDef {
  const key = String(type ?? '').trim();
  if (key in ASSET_TYPES) return ASSET_TYPES[key as AssetTypeDef['id']];
  const alias = SINGULAR_ALIASES[key];
  if (alias) return ASSET_TYPES[alias];
  return ASSET_TYPES.custom;
}
