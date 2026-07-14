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
import type { KeywordBucket } from './useKeywordBucket';

interface KeywordRow {
  query: string;
  clicks: number;
  impressions: number;
  position: number;
}

interface KeywordsDrawerProps {
  siteId: number;
  postId: number;
  /** The SEO row's post type — the existing cell route needs it. */
  type: string;
  /** The page's live URL — the GSC filter ('' = GSC can't see this page). */
  pageUrl: string;
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

const NOISE_FLOOR = 100; // impressions — the default "no crap" filter, removable

export function KeywordsDrawer({
  siteId, postId, type, pageUrl, primaryKeyword, onPrimaryChange, supportingKeywords, onSupportingChange, bucket, onClose,
}: KeywordsDrawerProps) {
  const [days, setDays] = useState(30);
  const [relatedOnly, setRelatedOnly] = useState(false);
  const [hideNoise, setHideNoise] = useState(true);
  const [rows, setRows] = useState<KeywordRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [newKeyword, setNewKeyword] = useState('');

  // Field saves ride the EXISTING SEO cell route — one write path per field.
  const saveCell = trpc.seo.remoteSaveCell.useMutation();
  const saveField = (field: 'primaryKeyword' | 'supportingKeyword', value: string) => {
    saveCell.mutateAsync({ siteId, postId, field, value, type })
      .then(() => toast.success(field === 'primaryKeyword' ? 'Primary keyword saved.' : 'Supporting keywords saved.'))
      .catch((e: unknown) => toast.error(e instanceof Error ? e.message : 'Could not save the keyword'));
  };

  const statsMutation = trpc.optimizer.keywordStats.useMutation();
  const loading = statsMutation.isPending ?? false;
  const scan = () => {
    setError(null);
    if (pageUrl === '') {
      setError('This page has no public URL — Search Console has nothing to report.');
      setRows([]);
      return;
    }
    statsMutation.mutateAsync({ pageUrl, days })
      .then((res: any) => setRows(Array.isArray(res?.rows) ? res.rows : []))
      .catch((e: unknown) => {
        setRows([]);
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
    if (hideNoise) out = out.filter((r) => r.impressions >= NOISE_FLOOR);
    if (relatedOnly && primaryKeyword.trim() !== '') out = out.filter((r) => isRelated(r.query, primaryKeyword));
    return out;
  }, [rows, hideNoise, relatedOnly, primaryKeyword]);

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
    { key: 'clicks', header: 'Clicks', width: 52, className: 'text-right', cell: (r) => r.clicks, sortAccessor: (r) => r.clicks },
    { key: 'impressions', header: 'Impr.', width: 60, className: 'text-right', cell: (r) => r.impressions, sortAccessor: (r) => r.impressions },
    { key: 'position', header: 'Pos.', width: 48, className: 'text-right', cell: (r) => r.position, sortAccessor: (r) => r.position },
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
        <label className="flex items-center gap-1" title="Only keywords related to the primary keyword">
          <input type="checkbox" checked={relatedOnly} onChange={(e) => setRelatedOnly(e.target.checked)} className="h-3 w-3 accent-[#007bff]" />
          related
        </label>
        <label className="flex items-center gap-1" title={`Hide keywords under ${NOISE_FLOOR} impressions`}>
          <input type="checkbox" checked={hideNoise} onChange={(e) => setHideNoise(e.target.checked)} className="h-3 w-3 accent-[#007bff]" />
          ≥{NOISE_FLOOR} impr.
        </label>
        <div className="flex-1" />
        <button
          type="button"
          onClick={scan}
          title="Re-scan Search Console for this page"
          className="rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-primary"
        >
          {loading ? <Loader2 className="h-3 w-3 animate-spin text-primary" /> : <RotateCw className="h-3 w-3" />}
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
            emptyMessage="No keywords in this window — widen the days or remove the filters."
          />
        )}
      </div>
    </aside>
  );
}
