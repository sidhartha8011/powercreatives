/**
 * SEO module — domain types. Mirrors the PCM_SEO_Service row + options shape.
 */

import { type PillVariant } from '@/components/ui/pill';

/** Status → Pill variant (colors live in the global Pill, never here).
 *  ONE source for the table cell AND the editor header dropdown. */
export function statusPillVariant(status: string): PillVariant {
  return (['publish', 'pending', 'private', 'future'] as const).includes(status as any)
    ? (status as PillVariant)
    : 'draft';
}

export interface SeoRow {
  id: number;
  type: string; // 'post' | 'page'
  title: string;
  slug: string;
  status: string;
  date: string;
  authorId: number;
  author: string;
  permalink: string;
  editUrl: string;
  featuredImage: string;
  /** Attachment id of the featured image (0 = none) — preselects the media picker. */
  featuredImageId: number;
  excerpt: string;
  metaTitle: string;
  metaDescription: string;
  /** Which meta cells are DISPLAY-ONLY fallbacks read from the rendered page /
   *  SEO plugin (nothing is stored per post). Bulk "generate where empty" must
   *  treat these as EMPTY, or a rendered title makes the cell look filled and the
   *  run skips it (owner: "it skips or leaves a field empty even if it should have
   *  generated in it"). */
  metaFromHead?: { title?: boolean; description?: boolean };
  primaryKeyword: string;
  metaKeywords: string;
  supportingKeyword: string;
  clusterLabel: string;
  schemaTypes: string[];
  /** Link-scan counts — null until the row has been scanned. */
  internalLinks: number | null;
  externalLinks: number | null;
  brokenLinks: number | null;
  linksScannedAt: string;
}

/** childId → parentId. A page absent from the map is a root. */
export type HierarchyMap = Record<number, number>;

/**
 * One proposed internal link, from the hierarchy.
 *
 * `anchor` empty + `reason` set is an honest refusal, not an error — the
 * generator only ever wraps wording ALREADY on the page, so "no safe
 * occurrence" is a legitimate outcome worth showing rather than hiding.
 */
export interface InterlinkProposal {
  sourceId: number;
  targetId: number;
  sourceTitle: string;
  targetTitle: string;
  url: string;
  /** 'up' = child → its pillar; 'down' = pillar → a child. */
  direction: 'up' | 'down';
  anchor: string;
  reason: string;
  /** Permalink paths — titles alone are not an identity (a post and a page can
   *  share one, which renders as "X → X" and reads like a self-link bug). */
  sourcePath: string;
  targetPath: string;
}

/** Schema.org types a post can advertise (matches PCM_SEO_Schema::TYPES). */
export const SCHEMA_TYPES = ['Article', 'WebPage', 'BreadcrumbList', 'FAQPage', 'HowTo', 'Product'] as const;

export interface SeoOptions {
  authors: { id: number; name: string }[];
  statuses: string[];
  types: string[];
  /** Detected active SEO plugin: yoast | rankmath | seopress | simple. */
  seoPlugin: string;
}

/** Editable text fields → their save-cell key. */
export const SEO_TEXT_FIELDS: { key: keyof SeoRow; label: string; width: string }[] = [
  { key: 'metaTitle', label: 'Meta Title', width: '13%' },
  { key: 'metaDescription', label: 'Meta Description', width: '16%' },
  { key: 'primaryKeyword', label: 'Primary KW', width: '10%' },
  { key: 'metaKeywords', label: 'Meta Keywords', width: '12%' },
];

export const SEO_PLUGIN_LABELS: Record<string, string> = {
  yoast: 'Yoast SEO',
  rankmath: 'Rank Math',
  seopress: 'SEOPress',
  simple: 'Built-in (no SEO plugin)',
};
