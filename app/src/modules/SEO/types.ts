/**
 * SEO module — domain types. Mirrors the PCM_SEO_Service row + options shape.
 */

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
  excerpt: string;
  metaTitle: string;
  metaDescription: string;
  primaryKeyword: string;
  metaKeywords: string;
  supportingKeyword: string;
  clusterLabel: string;
}

export interface SeoOptions {
  authors: { id: number; name: string }[];
  statuses: string[];
  types: string[];
  /** Detected active SEO plugin: yoast | rankmath | seopress | simple. */
  seoPlugin: string;
}

/** Editable text fields → their save-cell key. */
export const SEO_TEXT_FIELDS: { key: keyof SeoRow; label: string; width: string }[] = [
  { key: 'metaTitle', label: 'Meta Title', width: '15%' },
  { key: 'metaDescription', label: 'Meta Description', width: '20%' },
  { key: 'primaryKeyword', label: 'Primary KW', width: '11%' },
  { key: 'metaKeywords', label: 'Meta Keywords', width: '13%' },
];

export const SEO_PLUGIN_LABELS: Record<string, string> = {
  yoast: 'Yoast SEO',
  rankmath: 'Rank Math',
  seopress: 'SEOPress',
  simple: 'Built-in (no SEO plugin)',
};
