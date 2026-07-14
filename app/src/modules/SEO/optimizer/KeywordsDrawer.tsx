/**
 * THE KEYWORD DRAWER (owner spec 2026-07-13, MVP cut): the page editor's
 * LEFT drawer. Top: the page's primary + supporting keywords (the SEO
 * table's own fields, saved through the existing cell route). Middle: the
 * additional-keywords BUCKET — fills via + on rows, rides EVERY optimize
 * run. Bottom: the queries THIS page is already seen for (GSC), in the
 * shared DataTable — default sorted by impressions, noise filtered,
 * everything removable. Deferred per the MVP cut: the all-pages switcher,
 * the domain-wide related scan.
 */

import { useEffect, useMemo, useState } from 'react';
import { Loader2, Plus, RotateCw, X } from 'lucide-react';
import { toast } from 'sonner';
import { trpc } from '@/lib/trpc';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { numberMatch, type FilterDef } from '@/hooks/useColumnFilters';
import { ModelDropdown } from '@/components/shared';
import type { KeywordBucket } from './useKeywordBucket';

interface KeywordRow {
  query: string;
  clicks: number;
  impressions: number;
  /** null = not seen this period (a vanished keyword under compare). */
  position: number | null;
  /** Compare mode only: the previous period + the per-keyword deltas.
   *  d.position null = no previous rank (a dash, never a fake zero). */
  prev?: { clicks: number; impressions: number; position: number } | null;
  d?: { clicks: number; impressions: number; position: number | null } | null;
}

interface KeywordsDrawerProps {
  siteId: number;
  postId: number;
  /** The SEO row's post type — the existing cell route needs it. */
  type: string;
  /** The page's live URL — the GSC filter ('' = GSC can't see this page). */
  pageUrl: string;
  /** The site's pages — the picker's choices (the edited page is the default). */
  pages: Array<{ id: number; title: string; permalink: string }>;
  primaryKeyword: string;
  onPrimaryChange: (value: string) => void;
  /** Supporting keywords — the SEO table's own `supportingKeyword` field. */
  supportingKeywords: string;
  onSupportingChange: (value: string) => void;
  bucket: KeywordBucket;
  onClose: () => void;
}

/** Related-keywords heuristic v1 (named, transparent): a query is related
 *  when it shares one meaningful token (≥3 chars, not a stopword) with the
 *  primary keyword. The reality-derived version belongs to later phases. */
const STOPWORDS = new Set([
  'och', 'att', 'med', 'som', 'för', 'den', 'det', 'una', 'till', 'inte',
  'the', 'and', 'for', 'with', 'you', 'your', 'from', 'near', 'best', 'how',
]);
const tokens = (s: string): string[] =>
  s.toLowerCase().split(/[^\p{L}\p{N}]+/u).filter((t) => t.length >= 3 && !STOPWORDS.has(t));
const isRelated = (query: string, primary: string): boolean => {
  const p = new Set(tokens(primary));
  return p.size > 0 && tokens(query).some((t) => p.has(t));
};

/** Signed delta — green = improving, red = declining (`invert` for
 *  position, where DOWN is the win). A missing delta is a dash. */
const deltaSpan = (n: number | null | undefined, invert = false) => {
  if (n == null) return <span className="text-slate-300">—</span>;
  const good = invert ? n < 0 : n > 0;
  const cls = n === 0 ? 'text-slate-400' : good ? 'text-green-600' : 'text-red-600';
  return <span className={cls}>{n > 0 ? `+${n}` : String(n)}</span>;
};

/** TREND CELL (compare mode, owner UX 2026-07-14): the columns TRANSFORM
 *  instead of multiplying — the change is the loud number, the current
 *  value the quiet second line. Five columns always, no sideways scroll. */
const metricCell = (trend: boolean, value: number | null, delta: number | null | undefined, invert = false) => {
  if (!trend) return value ?? <span className="text-slate-300">—</span>;
  return (
    <span className="flex flex-col items-end leading-tight">
      {deltaSpan(delta, invert)}
      <span className="text-[9px] text-slate-400">now {value ?? '—'}</span>
    </span>
  );
};

export function KeywordsDrawer({
  siteId, postId, type, pageUrl, pages, primaryKeyword, onPrimaryChange, supportingKeywords, onSupportingChange, bucket, onClose,
}: KeywordsDrawerProps) {
  const [days, setDays] = useState(30);
  const [compare, setCompare] = useState(false);
  const [relatedOnly, setRelatedOnly] = useState(false);
  const [rows, setRows] = useState<KeywordRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [newKeyword, setNewKeyword] = useState('');
  /** WHICH page the GSC data is pulled for — defaults to the edited page;
   *  the bucket stays bound to the EDITED page regardless. */
  const [target, setTarget] = useState({ postId, pageUrl });

  // Field saves ride the EXISTING SEO cell route — one write path per field.
  const saveCell = trpc.seo.remoteSaveCell.useMutation();
  const saveField = (field: 'primaryKeyword' | 'supportingKeyword', value: string) => {
    saveCell.mutateAsync({ siteId, postId, field, value, type })
      .then(() => toast.success(field === 'primaryKeyword' ? 'Primary keyword saved.' : 'Supporting keywords saved.'))
      .catch((e: unknown) => toast.error(e instanceof Error ? e.message : 'Could not save the keyword'));
  };

  const statsMutation = trpc.optimizer.keywordStats.useMutation();
  const loading = statsMutation.isPending ?? false;
  /** When the rows are STORED (Google unreachable), the drawer says so —
   *  a status fact, never data passed off as live. */
  const [storedAt, setStoredAt] = useState<number | null>(null);
  const scan = (t: { postId: number; pageUrl: string } = target, cmp: boolean = compare) => {
    setError(null);
    if (t.pageUrl === '') {
      setError('This page has no public URL — Search Console has nothing to report.');
      setRows([]);
      return;
    }
    statsMutation.mutateAsync({ siteId, postId: t.postId, pageUrl: t.pageUrl, days, compare: cmp })
      .then((res: any) => {
        setRows(Array.isArray(res?.rows) ? res.rows : []);
        setStoredAt(res?.source === 'stored' ? Number(res?.fetchedAt ?? 0) : null);
      })
      .catch((e: unknown) => {
        setRows([]);
        setStoredAt(null);
        setError(e instanceof Error ? e.message : 'Search Console request failed');
      });
  };
  // The drawer opens WITH data — one scan on mount; Days changes rescan
  // via the button (explicit, never a surprise request per keystroke).
  useEffect(() => {
    scan();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const visible = useMemo(() => {
    let out = rows ?? [];
    if (relatedOnly && primaryKeyword.trim() !== '') out = out.filter((r) => isRelated(r.query, primaryKeyword));
    return out;
  }, [rows, relatedOnly, primaryKeyword]);

  // TREND MODE: compare data present → the metric columns transform (the
  // change is shown, sorted and filtered; the value rides as a quiet second
  // line). Five columns in BOTH modes — never a sideways scroll.
  const trend = (rows ?? []).some((r) => r.d != null);
  const filterDefs = useMemo<Record<string, FilterDef<KeywordRow>>>(() => ({
    query: { key: 'query', kind: 'text', match: (r, v) => r.query.toLowerCase().includes(v.toLowerCase()) },
    clicks: { key: 'clicks', kind: 'number', match: numberMatch((r) => (trend ? r.d?.clicks ?? null : r.clicks)) },
    impressions: { key: 'impressions', kind: 'number', match: numberMatch((r) => (trend ? r.d?.impressions ?? null : r.impressions)) },
    position: { key: 'position', kind: 'number', match: numberMatch((r) => (trend ? r.d?.position ?? null : r.position)) },
  }), [trend]);
  const columns: DataTableColumn<KeywordRow>[] = [
    {
      key: 'add',
      header: '',
      width: 28,
      cell: (r) => (
        <button
          type="button"
          onClick={() => bucket.add(r.query)}
          disabled={bucket.keywords.includes(r.query)}
          title="Add to the additional keywords — rides every optimization"
          className="rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-primary disabled:opacity-30"
        >
          <Plus className="h-3 w-3" />
        </button>
      ),
    },
    { key: 'query', header: 'Keyword', cell: (r) => <span title={r.query}>{r.query}</span>, sortAccessor: (r) => r.query },
    {
      key: 'clicks',
      header: trend ? 'Δ Clicks' : 'Clicks',
      width: 56,
      className: 'text-right',
      cell: (r) => metricCell(trend, r.clicks, r.d?.clicks),
      sortAccessor: (r) => (trend ? r.d?.clicks ?? 0 : r.clicks),
    },
    {
      key: 'impressions',
      header: trend ? 'Δ Impr.' : 'Impr.',
      width: 62,
      className: 'text-right',
      cell: (r) => metricCell(trend, r.impressions, r.d?.impressions),
      sortAccessor: (r) => (trend ? r.d?.impressions ?? 0 : r.impressions),
    },
    {
      key: 'position',
      header: trend ? 'Δ Pos.' : 'Pos.',
      width: 52,
      className: 'text-right',
      cell: (r) => metricCell(trend, r.position, r.d?.position, true),
      sortAccessor: (r) => (trend ? r.d?.position ?? 0 : r.position),
    },
  ];

  return (
    <aside className="flex h-full w-[360px] shrink-0 flex-col border border-r-0 border-slate-200 bg-white">
      <div className="flex items-center gap-1.5 border-b border-slate-200 px-2.5 py-1.5">
        <div className="min-w-0 flex-1 truncate text-[11px] font-medium text-slate-700">Keywords</div>
        <button
          type="button"
          onClick={onClose}
          title="Close the keyword drawer"
          className="shrink-0 rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
        >
          <X className="h-3 w-3" />
        </button>
      </div>

      <div className="space-y-1.5 border-b border-slate-200 px-2.5 py-1.5">
        <label className="block">
          <span className="text-[10px] font-medium text-slate-500">Primary keyword</span>
          <input
            value={primaryKeyword}
            onChange={(e) => onPrimaryChange(e.target.value)}
            onBlur={(e) => saveField('primaryKeyword', e.target.value.trim())}
            placeholder="The keyword this page targets"
            className="mt-0.5 h-6 w-full rounded border border-slate-200 bg-white px-1.5 text-[11px] text-slate-800 outline-none focus:border-primary"
          />
        </label>
        <label className="block">
          <span className="text-[10px] font-medium text-slate-500">Supporting keywords</span>
          <input
            value={supportingKeywords}
            onChange={(e) => onSupportingChange(e.target.value)}
            onBlur={(e) => saveField('supportingKeyword', e.target.value.trim())}
            placeholder="Comma-separated"
            className="mt-0.5 h-6 w-full rounded border border-slate-200 bg-white px-1.5 text-[11px] text-slate-800 outline-none focus:border-primary"
          />
        </label>
        <div>
          <span className="text-[10px] font-medium text-slate-500">Additional keywords</span>
          <input
            value={newKeyword}
            onChange={(e) => setNewKeyword(e.target.value)}
            onKeyDown={(e) => {
              if (e.key !== 'Enter' || newKeyword.trim() === '') return;
              bucket.add(newKeyword);
              setNewKeyword('');
            }}
            placeholder="Add keyword…"
            title="Rides every optimization — Enter adds; + in the list below adds too"
            className="mt-0.5 h-6 w-full rounded border border-slate-200 bg-white px-1.5 text-[11px] text-slate-800 outline-none focus:border-primary"
          />
          {bucket.keywords.length > 0 && (
            <div className="mt-1 flex flex-wrap gap-1">
              {bucket.keywords.map((kw) => (
                <span key={kw} className="inline-flex items-center gap-0.5 rounded-full border border-primary/40 bg-white px-1.5 py-px text-[10px] text-primary">
                  {kw}
                  <button
                    type="button"
                    onClick={() => bucket.remove(kw)}
                    title="Remove from the additional keywords"
                    className="rounded-full hover:bg-slate-100"
                  >
                    <X className="h-2.5 w-2.5" />
                  </button>
                </span>
              ))}
            </div>
          )}
        </div>
      </div>

      <div className="flex items-center gap-1.5 border-b border-slate-200 px-2.5 py-1.5 text-[10px] text-slate-600">
        {/* WHICH page the data is pulled for (the + always feeds the edited
            page's bucket) — searchable, defaults to the edited page. */}
        <ModelDropdown
          searchable
          modelGroups={[{ label: 'Page', models: pages.map((p) => ({ id: String(p.id), name: p.title })) }]}
          selectedModel={String(target.postId)}
          onModelChange={(id) => {
            const p = pages.find((x) => String(x.id) === id);
            if (!p) return;
            const t = { postId: p.id, pageUrl: p.permalink };
            setTarget(t);
            scan(t);
          }}
        />
        <label className="flex items-center gap-1" title="How many days back Search Console looks">
          <input
            type="number"
            min={1}
            max={180}
            value={days}
            onChange={(e) => setDays(Math.max(1, Math.min(180, Number(e.target.value) || 30)))}
            className="h-5 w-12 rounded border border-slate-200 bg-white px-1 text-[10px] outline-none focus:border-primary"
          />
          days
        </label>
        <label className="flex items-center gap-1" title="Compare with the previous period of the same length — adds the Δ trend columns">
          <input
            type="checkbox"
            checked={compare}
            onChange={(e) => { setCompare(e.target.checked); scan(target, e.target.checked); }}
            className="h-3 w-3 accent-[#007bff]"
          />
          compare
        </label>
        <label className="flex items-center gap-1" title="Only keywords related to the primary keyword">
          <input type="checkbox" checked={relatedOnly} onChange={(e) => setRelatedOnly(e.target.checked)} className="h-3 w-3 accent-[#007bff]" />
          related
        </label>
        <div className="flex-1" />
        {/* Freshness lives ON its remedy (UX ruling 2026-07-14): live data =
            a silent normal refresh; saved-copy data = the icon turns amber
            with a dot, the plain-words story in the tooltip. No chip. */}
        <button
          type="button"
          onClick={() => scan()}
          title={storedAt !== null
            ? `Google couldn't be reached — showing the last saved results${storedAt > 0 ? ` from ${new Date(storedAt * 1000).toISOString().slice(0, 10)}` : ''}. Click to retry.`
            : 'Re-scan Search Console for this page'}
          className={`relative rounded p-0.5 hover:bg-slate-100 ${storedAt !== null ? 'text-amber-500 hover:text-amber-600' : 'text-slate-400 hover:text-primary'}`}
        >
          {loading ? <Loader2 className="h-3 w-3 animate-spin text-primary" /> : <RotateCw className="h-3 w-3" />}
          {storedAt !== null && !loading && (
            <span className="absolute -right-0.5 -top-0.5 h-1.5 w-1.5 rounded-full bg-amber-500" />
          )}
        </button>
      </div>

      <div className="min-h-0 flex-1 overflow-auto">
        {error !== null && <div className="px-2.5 py-1.5 text-[10px] text-red-600">{error}</div>}
        {rows === null && loading && (
          <div className="flex items-center justify-center gap-1.5 py-4 text-[10px] text-slate-500">
            <Loader2 className="h-3 w-3 animate-spin text-primary" /> Reading Search Console…
          </div>
        )}
        {rows !== null && (
          <DataTable<KeywordRow>
            columns={columns}
            data={visible}
            rowKey={(r) => r.query}
            defaultSortKey="impressions"
            defaultSortDir="desc"
            layoutKey="optimizer-kw-drawer"
            filterDefs={filterDefs}
            emptyMessage="No keywords in this window — widen the days or remove the filters."
          />
        )}
      </div>
    </aside>
  );
}
