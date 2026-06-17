/**
 * seoFilters — per-column filter definitions for the SEO content table.
 *
 * Each column gets a filter tailored to its meaning (not just a text box):
 *  - Type / Status / Author → exact-match choice (from server options)
 *  - Title                  → free-text "contains"
 *  - Meta Title / Desc      → Missing / Present / Too long (length-aware)
 *  - Primary KW / Keywords  → Missing / Present
 *  - Schema                 → Has schema / No schema
 *
 * A FilterDef carries its own `match(row, value)` predicate so the apply loop
 * stays generic. Option lists that depend on server data are built from
 * `SeoOptions`, so this is a builder, not a static const.
 */

import type { SeoRow, SeoOptions } from './types';

export type FilterKind = 'text' | 'choice';

export interface FilterOption {
  value: string;
  label: string;
}

export interface FilterDef {
  key: string;
  kind: FilterKind;
  /** Choices for a 'choice' filter (omitted for 'text'). */
  options?: FilterOption[];
  /** True when the row passes this filter's active value. */
  match: (row: SeoRow, value: string) => boolean;
}

/** Recommended max lengths (SEO best practice) used by the "Too long" filter. */
export const META_TITLE_MAX = 60;
export const META_DESCRIPTION_MAX = 160;

/** Presence (+ optional length) choices for a text-ish SEO field. */
function presenceOptions(limit?: number): FilterOption[] {
  return [
    { value: 'missing', label: 'Missing' },
    { value: 'present', label: 'Present' },
    ...(limit ? [{ value: 'too_long', label: `Too long (>${limit})` }] : []),
  ];
}

/** Match a presence/length value against a row's text field. */
function presenceMatch(field: keyof SeoRow, limit?: number) {
  return (row: SeoRow, value: string): boolean => {
    const v = String(row[field] ?? '');
    if (value === 'missing') return v.trim() === '';
    if (value === 'present') return v.trim() !== '';
    if (value === 'too_long') return limit != null && v.length > limit;
    return true;
  };
}

/**
 * Build the { columnKey → FilterDef } map. Choice lists for type/status/author
 * come from the server `options` payload (fall back to sensible defaults).
 */
export function buildFilterDefs(options: SeoOptions | null): Record<string, FilterDef> {
  const typeOpts: FilterOption[] = (options?.types ?? ['post', 'page']).map((t) => ({ value: t, label: t }));
  const statusOpts: FilterOption[] = (options?.statuses ?? ['publish', 'draft', 'pending', 'private', 'future']).map((s) => ({ value: s, label: s }));
  const authorOpts: FilterOption[] = (options?.authors ?? []).map((a) => ({ value: String(a.id), label: a.name }));

  return {
    type: { key: 'type', kind: 'choice', options: typeOpts, match: (r, v) => r.type === v },
    title: { key: 'title', kind: 'text', match: (r, v) => r.title.toLowerCase().includes(v.toLowerCase()) },
    status: { key: 'status', kind: 'choice', options: statusOpts, match: (r, v) => r.status === v },
    metaTitle: { key: 'metaTitle', kind: 'choice', options: presenceOptions(META_TITLE_MAX), match: presenceMatch('metaTitle', META_TITLE_MAX) },
    metaDescription: { key: 'metaDescription', kind: 'choice', options: presenceOptions(META_DESCRIPTION_MAX), match: presenceMatch('metaDescription', META_DESCRIPTION_MAX) },
    primaryKeyword: { key: 'primaryKeyword', kind: 'choice', options: presenceOptions(), match: presenceMatch('primaryKeyword') },
    metaKeywords: { key: 'metaKeywords', kind: 'choice', options: presenceOptions(), match: presenceMatch('metaKeywords') },
    schema: {
      key: 'schema',
      kind: 'choice',
      options: [{ value: 'has', label: 'Has schema' }, { value: 'none', label: 'No schema' }],
      match: (r, v) => (v === 'has' ? (r.schemaTypes?.length ?? 0) > 0 : (r.schemaTypes?.length ?? 0) === 0),
    },
    author: { key: 'author', kind: 'choice', options: authorOpts, match: (r, v) => String(r.authorId) === v },
  };
}
