/**
 * THE KEYWORD DRAWER V2 (owner design 2026-07-14): TWO ZONES.
 *
 * TOP — SELECTED: the page's keywords as ONE table (role · live uses ·
 * live density · search volume · remove) — a VIEW over the three stores
 * that already exist (primary field, supporting field, the bucket); a
 * role change is a MOVE between them through the same write paths.
 *
 * BOTTOM — THE FINDER: one table, two tabs. RANKING = what the page
 * already ranks for (GSC, compare always on). IDEAS = the keyword
 * engine's suggestions for a typed seed. Same anatomy, same + gesture,
 * one destination: the selected zone. Cart on top, store below.
 */

import { useEffect, useMemo, useState } from 'react';
import { Loader2, Plus, RotateCw, Search, TrendingUp, X } from 'lucide-react';
import { toast } from 'sonner';
import { trpc } from '@/lib/trpc';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { numberMatch, type FilterDef } from '@/hooks/useColumnFilters';
import { ModelDropdown } from '@/components/shared';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { KeywordBucket } from './useKeywordBucket';
import { keywordUses } from './keywordStats';

interface KeywordRow {
  query: string;
  clicks: number;
  impressions: number;
  /** null = not seen this period (a vanished keyword under compare). */
  position: number | null;
  prev?: { clicks: number; impressions: number; position: number } | null;
  d?: { clicks: number; impressions: number; position: number | null } | null;
}

type Role = 'primary' | 'supporting' | 'additional';
interface SelectedRow {
  kw: string;
  role: Role;
}
const ROLE_TAG: Record<Role, string> = { primary: 'P', supporting: 'S', additional: 'A' };
const ROLE_LABEL: Record<Role, string> = { primary: 'Primary', supporting: 'Supporting', additional: 'Additional' };
/** Sort rank — the hierarchy IS the order (owner law: P → S → A). */
const ROLE_RANK: Record<Role, number> = { primary: 0, supporting: 1, additional: 2 };

interface KeywordsDrawerProps {
  siteId: number;
  postId: number;
  /** The SEO row's post type — the existing cell route needs it. */
  type: string;
  /** The page's live URL — the GSC filter ('' = GSC can't see this page). */
  pageUrl: string;
  /** The site's pages — the Ranking tab's picker (edited page = default). */
  pages: Array<{ id: number; title: string; permalink: string }>;
  primaryKeyword: string;
  onPrimaryChange: (value: string) => void;
  /** Supporting keywords — the SEO table's own `supportingKeyword` field. */
  supportingKeywords: string;
  onSupportingChange: (value: string) => void;
  bucket: KeywordBucket;
  /** The editor's live text — uses/density recompute per keystroke. */
  contentText: string;
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

/** Signed delta cell — green = improving, red = declining (`invert` for
 *  position, where DOWN is the win). A missing delta is a dash. */
const deltaCell = (n: number | null | undefined, invert = false) => {
  if (n == null) return <span className="text-slate-300">—</span>;
  const good = invert ? n < 0 : n > 0;
  const cls = n === 0 ? 'text-slate-400' : good ? 'text-green-600' : 'text-red-600';
  return <span className={cls}>{n > 0 ? `+${n}` : String(n)}</span>;
};

/** Shared column widths — the three tables read as ONE system (owner law:
 *  same features, same sizes, everywhere). */
const COL = { add: 28, vol: 56 } as const;

/** Ranking-tab header filters (number kind: above / below / between icons). */
const RANKING_FILTER_DEFS: Record<string, FilterDef<KeywordRow>> = {
  query: { key: 'query', kind: 'text', match: (r, v) => r.query.toLowerCase().includes(v.toLowerCase()) },
  clicks: { key: 'clicks', kind: 'number', match: numberMatch((r) => r.clicks) },
  impressions: { key: 'impressions', kind: 'number', match: numberMatch((r) => r.impressions) },
  position: { key: 'position', kind: 'number', match: numberMatch((r) => r.position) },
  dClicks: { key: 'dClicks', kind: 'number', match: numberMatch((r) => r.d?.clicks ?? null) },
  dImpressions: { key: 'dImpressions', kind: 'number', match: numberMatch((r) => r.d?.impressions ?? null) },
  dPosition: { key: 'dPosition', kind: 'number', match: numberMatch((r) => r.d?.position ?? null) },
};

export function KeywordsDrawer({
  siteId, postId, type, pageUrl, pages, primaryKeyword, onPrimaryChange, supportingKeywords, onSupportingChange, bucket, contentText, onClose,
}: KeywordsDrawerProps) {
  // ── THE SELECTED ZONE: a view over the three existing stores. ──
  const primary = primaryKeyword.trim();
  const supportingList = useMemo(
    () => supportingKeywords.split(',').map((s) => s.trim()).filter(Boolean),
    [supportingKeywords],
  );
  const selectedRows = useMemo<SelectedRow[]>(() => [
    ...(primary !== '' ? [{ kw: primary, role: 'primary' as Role }] : []),
    ...supportingList.filter((k) => k !== primary).map((kw) => ({ kw, role: 'supporting' as Role })),
    ...bucket.keywords
      .filter((k) => k !== primary && !supportingList.includes(k))
      .map((kw) => ({ kw, role: 'additional' as Role })),
  ], [primary, supportingList, bucket.keywords]);

  // Field saves ride the EXISTING SEO cell route — one write path per field.
  const saveCell = trpc.seo.remoteSaveCell.useMutation();
  const saveField = (field: 'primaryKeyword' | 'supportingKeyword', value: string) => {
    saveCell.mutateAsync({ siteId, postId, field, value, type })
      .catch((e: unknown) => toast.error(e instanceof Error ? e.message : 'Could not save the keyword'));
  };
  const savePrimary = (kw: string) => {
    onPrimaryChange(kw);
    saveField('primaryKeyword', kw);
  };
  const saveSupporting = (list: string[]) => {
    onSupportingChange(list.join(', '));
    saveField('supportingKeyword', list.join(', '));
  };
  const detach = (kw: string, from: Role) => {
    if (from === 'primary') savePrimary('');
    else if (from === 'supporting') saveSupporting(supportingList.filter((k) => k !== kw));
    else bucket.remove(kw);
  };
  /** A role change is a MOVE between the three stores — the old primary is
   *  never dropped, it demotes to Additional (owner spec). */
  const setRole = (kw: string, from: Role, to: Role) => {
    if (from === to) return;
    const oldPrimary = primary;
    detach(kw, from);
    if (to === 'primary') {
      if (oldPrimary !== '' && oldPrimary !== kw) bucket.add(oldPrimary);
      savePrimary(kw);
    } else if (to === 'supporting') {
      saveSupporting([...supportingList.filter((k) => k !== kw), kw]);
    } else {
      bucket.add(kw);
    }
  };

  // ── Search volumes: cache-first server endpoint (Ahrefs credits
  //    respected: cachedOnly on scans, refresh only via the update button). ──
  const volumesMutation = trpc.optimizer.keywordVolumes.useMutation();
  const [volumes, setVolumes] = useState<Record<string, number | null>>({});
  const [hasAhrefs, setHasAhrefs] = useState(true);
  /** Which BUTTON is fetching (owner law 2026-07-15: tables fetch
   *  independently — each button animates only its own press; the free
   *  auto-fills animate neither). */
  const [pendingSource, setPendingSource] = useState<'selected' | 'finder' | null>(null);
  const fetchVolumes = (kws: string[], opts: { refresh?: boolean; cachedOnly?: boolean; source?: 'selected' | 'finder' } = {}) => {
    const list = [...new Set(kws.map((k) => k.trim()).filter(Boolean))];
    const wanted = opts.refresh ? list : list.filter((k) => !(k in volumes));
    if (wanted.length === 0) return;
    if (opts.source) setPendingSource(opts.source);
    volumesMutation.mutateAsync({ keywords: wanted.slice(0, 20), refresh: !!opts.refresh, cachedOnly: !!opts.cachedOnly })
      .then((res: any) => {
        setVolumes((v) => ({ ...v, ...(res?.volumes ?? {}) }));
        if (res?.hasKey === false) setHasAhrefs(false);
      })
      .catch(() => { /* volumes are enrichment — their absence is visible as dashes */ })
      .finally(() => setPendingSource(null));
  };
  /** The volume cell — one renderer for all three tables. The dash is
   *  honest about WHY: a fetched keyword Ahrefs has no data for is final;
   *  a never-fetched one names its trigger. */
  const volCell = (kw: string) =>
    (volumes[kw] != null
      ? volumes[kw]
      : (
        <span
          className="text-slate-300"
          title={!hasAhrefs
            ? 'Add an Ahrefs key in Integrations for search volumes'
            : kw in volumes
              ? 'Ahrefs has no volume data for this keyword'
              : 'No volume data — press the volume button to fetch it'}
        >
          —
        </span>
      ));
  const selectedKey = selectedRows.map((r) => r.kw).join('|');
  useEffect(() => {
    fetchVolumes(selectedRows.map((r) => r.kw));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedKey]);

  const [newKeyword, setNewKeyword] = useState('');

  // FULL table parity (owner law: never a lesser table): the selected
  // table gets the same header filters as every other — live-value
  // predicates, so the defs live here with their dependencies.
  const selectedFilterDefs = useMemo<Record<string, FilterDef<SelectedRow>>>(() => ({
    kw: { key: 'kw', kind: 'text', match: (r, v) => r.kw.toLowerCase().includes(v.toLowerCase()) },
    uses: { key: 'uses', kind: 'number', match: numberMatch((r) => keywordUses(contentText, r.kw).uses) },
    density: { key: 'density', kind: 'number', match: numberMatch((r) => keywordUses(contentText, r.kw).density) },
    volume: { key: 'volume', kind: 'number', match: numberMatch((r) => volumes[r.kw] ?? null) },
  }), [contentText, volumes]);

  const selectedColumns: DataTableColumn<SelectedRow>[] = [
    { key: 'kw', header: 'Keyword', cell: (r) => <span title={r.kw}>{r.kw}</span>, sortAccessor: (r) => r.kw },
    {
      key: 'role',
      header: 'Role',
      width: 44,
      sortAccessor: (r) => ROLE_RANK[r.role],
      cell: (r) => (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <button
              type="button"
              title={ROLE_LABEL[r.role]}
              className={`rounded px-1.5 py-px text-[10px] font-semibold ${
                r.role === 'primary' ? 'bg-[#e7f5ff] text-primary' : r.role === 'supporting' ? 'bg-slate-100 text-slate-700' : 'bg-white text-slate-500 border border-slate-200'
              }`}
            >
              {ROLE_TAG[r.role]}
            </button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="start" className="w-32">
            {(['primary', 'supporting', 'additional'] as Role[]).map((role) => (
              <DropdownMenuItem key={role} onClick={() => setRole(r.kw, r.role, role)}>
                {ROLE_LABEL[role]}
              </DropdownMenuItem>
            ))}
          </DropdownMenuContent>
        </DropdownMenu>
      ),
    },
    { key: 'uses', header: 'Uses', width: 42, className: 'text-right', cell: (r) => keywordUses(contentText, r.kw).uses, sortAccessor: (r) => keywordUses(contentText, r.kw).uses },
    { key: 'density', header: '%', width: 44, className: 'text-right', cell: (r) => `${keywordUses(contentText, r.kw).density}%`, sortAccessor: (r) => keywordUses(contentText, r.kw).density },
    {
      key: 'volume',
      header: 'Vol.',
      width: COL.vol,
      className: 'text-right',
      cell: (r) => volCell(r.kw),
      sortAccessor: (r) => volumes[r.kw] ?? -1,
    },
    {
      key: 'remove',
      header: '',
      width: 26,
      cell: (r) => (
        <button
          type="button"
          onClick={() => detach(r.kw, r.role)}
          title="Remove this keyword from the page"
          className="rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-destructive"
        >
          <X className="h-3 w-3" />
        </button>
      ),
    },
  ];

  // ── THE FINDER: one table, two sources. ──
  const [tab, setTab] = useState<'ranking' | 'ideas'>('ranking');

  // RANKING (GSC) — compare ALWAYS on (owner ruling).
  const [days, setDays] = useState(30);
  const [relatedOnly, setRelatedOnly] = useState(false);
  const [rows, setRows] = useState<KeywordRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [target, setTarget] = useState({ postId, pageUrl });
  const statsMutation = trpc.optimizer.keywordStats.useMutation();
  const loading = statsMutation.isPending ?? false;
  const [storedAt, setStoredAt] = useState<number | null>(null);
  const scan = (t: { postId: number; pageUrl: string } = target) => {
    setError(null);
    if (t.pageUrl === '') {
      setError('This page has no public URL — Search Console has nothing to report.');
      setRows([]);
      return;
    }
    statsMutation.mutateAsync({ siteId, postId: t.postId, pageUrl: t.pageUrl, days, compare: true })
      .then((res: any) => {
        const fetched: KeywordRow[] = Array.isArray(res?.rows) ? res.rows : [];
        setRows(fetched);
        setStoredAt(res?.source === 'stored' ? Number(res?.fetchedAt ?? 0) : null);
        // Volume auto-fill from the CACHE only — scans stay credit-free.
        fetchVolumes(fetched.map((r) => r.query), { cachedOnly: true });
      })
      .catch((e: unknown) => {
        setRows([]);
        setStoredAt(null);
        setError(e instanceof Error ? e.message : 'Search Console request failed');
      });
  };
  useEffect(() => {
    scan();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  const visible = useMemo(() => {
    let out = rows ?? [];
    if (relatedOnly && primary !== '') out = out.filter((r) => isRelated(r.query, primary));
    return out;
  }, [rows, relatedOnly, primary]);

  const addButton = (kw: string) => (
    <button
      type="button"
      onClick={() => bucket.add(kw)}
      disabled={selectedRows.some((s) => s.kw === kw)}
      title="Add to this page's keywords — rides every optimization"
      className="rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-primary disabled:opacity-30"
    >
      <Plus className="h-3 w-3" />
    </button>
  );

  const rankingFilterDefs = useMemo<Record<string, FilterDef<KeywordRow>>>(() => ({
    ...RANKING_FILTER_DEFS,
    volume: { key: 'volume', kind: 'number', match: numberMatch((r) => volumes[r.query] ?? null) },
  }), [volumes]);
  const rankingColumns: DataTableColumn<KeywordRow>[] = [
    { key: 'add', header: '', width: COL.add, cell: (r) => addButton(r.query) },
    { key: 'query', header: 'Keyword', cell: (r) => <span title={r.query}>{r.query}</span>, sortAccessor: (r) => r.query },
    { key: 'volume', header: 'Vol.', width: COL.vol, className: 'text-right', cell: (r) => volCell(r.query), sortAccessor: (r) => volumes[r.query] ?? -1 },
    { key: 'clicks', header: 'Clicks', width: 52, className: 'text-right', cell: (r) => r.clicks, sortAccessor: (r) => r.clicks },
    { key: 'impressions', header: 'Impr.', width: 60, className: 'text-right', cell: (r) => r.impressions, sortAccessor: (r) => r.impressions },
    { key: 'position', header: 'Pos.', width: 48, className: 'text-right', cell: (r) => r.position ?? <span className="text-slate-300">—</span>, sortAccessor: (r) => r.position },
    { key: 'dClicks', header: 'Δ Clicks', width: 58, className: 'text-right', cell: (r) => deltaCell(r.d?.clicks), sortAccessor: (r) => r.d?.clicks ?? 0 },
    { key: 'dImpressions', header: 'Δ Impr.', width: 62, className: 'text-right', cell: (r) => deltaCell(r.d?.impressions), sortAccessor: (r) => r.d?.impressions ?? 0 },
    { key: 'dPosition', header: 'Δ Pos.', width: 54, className: 'text-right', cell: (r) => deltaCell(r.d?.position, true), sortAccessor: (r) => r.d?.position ?? 0 },
  ];

  // IDEAS — the keyword engine's suggestions for a typed seed.
  const [seed, setSeed] = useState('');
  const [ideas, setIdeas] = useState<string[] | null>(null);
  const [ideasError, setIdeasError] = useState<string | null>(null);
  const searchMutation = trpc.keywords.search.useMutation();
  const searching = searchMutation.isPending ?? false;
  const runIdeas = () => {
    const q = seed.trim();
    if (q === '') return;
    setIdeasError(null);
    searchMutation.mutateAsync({ query: q })
      .then((res: any) => {
        const list: string[] = (Array.isArray(res?.suggestions) ? res.suggestions : []).slice(0, 20);
        setIdeas(list);
        // Volume auto-fill from the CACHE only — searches stay credit-free
        // (the finder's volume button is the deliberate fetch).
        fetchVolumes(list, { cachedOnly: true });
      })
      .catch((e: unknown) => {
        setIdeas([]);
        setIdeasError(e instanceof Error ? e.message : 'Keyword search failed');
      });
  };
  const ideaFilterDefs = useMemo<Record<string, FilterDef<{ kw: string }>>>(() => ({
    kw: { key: 'kw', kind: 'text', match: (r, v) => r.kw.toLowerCase().includes(v.toLowerCase()) },
    volume: { key: 'volume', kind: 'number', match: numberMatch((r) => volumes[r.kw] ?? null) },
  }), [volumes]);
  const ideaColumns: DataTableColumn<{ kw: string }>[] = [
    { key: 'add', header: '', width: COL.add, cell: (r) => addButton(r.kw) },
    { key: 'kw', header: 'Keyword', cell: (r) => <span title={r.kw}>{r.kw}</span>, sortAccessor: (r) => r.kw },
    { key: 'volume', header: 'Vol.', width: COL.vol, className: 'text-right', cell: (r) => volCell(r.kw), sortAccessor: (r) => volumes[r.kw] ?? -1 },
  ];

  // THE FINDER'S GET (owner order 2026-07-14): finder rows never spend on
  // their own — this press fetches the ACTIVE tab's volumes, cache-first
  // (credits only for keywords the hub lacks or whose cache expired).
  const finderKeywords = tab === 'ranking' ? (rows ?? []).map((r) => r.query) : (ideas ?? []);

  return (
    <aside className="flex h-full w-[520px] shrink-0 flex-col border border-r-0 border-slate-200 bg-white">
      <div className="flex items-center gap-1.5 border-b border-slate-200 px-2.5 py-1.5">
        <div className="min-w-0 flex-1 truncate text-[11px] font-medium text-slate-700">Keywords</div>
        {/* THE VOLUME UPDATE (owner independence law 2026-07-15): one
            deliberate press re-fetches Ahrefs volumes for the SELECTED
            table only — the finder has its own button, tables never
            cross. Never on a timer, credits respected. */}
        <button
          type="button"
          onClick={() => fetchVolumes(selectedRows.map((r) => r.kw), { refresh: true, source: 'selected' })}
          disabled={selectedRows.length === 0}
          title="Update search volumes from Ahrefs for the selected keywords (uses Ahrefs credits)"
          className="shrink-0 rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-primary disabled:opacity-30"
        >
          {pendingSource === 'selected'
            ? <Loader2 className="h-3 w-3 animate-spin text-primary" />
            : <TrendingUp className="h-3 w-3" />}
        </button>
        <button
          type="button"
          onClick={onClose}
          title="Close the keyword drawer"
          className="shrink-0 rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
        >
          <X className="h-3 w-3" />
        </button>
      </div>

      {/* ── SELECTED: the page's keywords — the decisions, pinned. ── */}
      <div className="max-h-[45%] shrink-0 overflow-auto border-b border-slate-200">
        <DataTable<SelectedRow>
          columns={selectedColumns}
          data={selectedRows}
          rowKey={(r) => r.kw}
          defaultSortKey="role"
          defaultSortDir="asc"
          layoutKey="optimizer-kw-selected"
          filterDefs={selectedFilterDefs}
          emptyMessage="No keywords yet — add them below with +, or type one here."
        />
        <div className="px-2.5 py-1.5">
          <input
            value={newKeyword}
            onChange={(e) => setNewKeyword(e.target.value)}
            onKeyDown={(e) => {
              if (e.key !== 'Enter' || newKeyword.trim() === '') return;
              bucket.add(newKeyword);
              setNewKeyword('');
            }}
            placeholder="Add keyword…"
            title="Rides every optimization — Enter adds"
            className="h-6 w-full rounded border border-slate-200 bg-white px-1.5 text-[11px] text-slate-800 outline-none focus:border-primary"
          />
        </div>
      </div>

      {/* ── THE FINDER: one table, two sources. ── */}
      <div className="flex items-center gap-1 border-b border-slate-200 px-2.5 py-1.5">
        {(['ranking', 'ideas'] as const).map((t) => (
          <button
            key={t}
            type="button"
            onClick={() => setTab(t)}
            className={`rounded-full px-2.5 py-0.5 text-[11px] font-medium ${
              tab === t ? 'bg-[#e7f5ff] text-primary' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-800'
            }`}
          >
            {t === 'ranking' ? 'Ranking' : 'Ideas'}
          </button>
        ))}
        <div className="flex-1" />
        <button
          type="button"
          onClick={() => fetchVolumes(finderKeywords, { source: 'finder' })}
          disabled={finderKeywords.length === 0}
          title="Get search volumes for this list — only keywords without a cached volume use Ahrefs credits"
          className="shrink-0 rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-primary disabled:opacity-30"
        >
          {pendingSource === 'finder'
            ? <Loader2 className="h-3 w-3 animate-spin text-primary" />
            : <TrendingUp className="h-3 w-3" />}
        </button>
      </div>

      {tab === 'ranking' ? (
        <>
          <div className="flex items-center gap-1.5 border-b border-slate-200 px-2.5 py-1.5 text-[10px] text-slate-600">
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
            <label className="flex items-center gap-1" title="How many days back Search Console looks (compared against the same period before it)">
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
            <div className="flex-1" />
            {/* Freshness lives ON its remedy: amber = showing the saved copy. */}
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
                columns={rankingColumns}
                data={visible}
                rowKey={(r) => r.query}
                defaultSortKey="impressions"
                defaultSortDir="desc"
                layoutKey="optimizer-kw-drawer"
                filterDefs={rankingFilterDefs}
                emptyMessage="No keywords in this window — widen the days or remove the filters."
              />
            )}
          </div>
        </>
      ) : (
        <>
          <div className="flex items-center gap-1.5 border-b border-slate-200 px-2.5 py-1.5">
            <input
              value={seed}
              onChange={(e) => setSeed(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter') runIdeas(); }}
              placeholder="Search keyword ideas…"
              className="h-6 min-w-0 flex-1 rounded border border-slate-200 bg-white px-1.5 text-[11px] text-slate-800 outline-none focus:border-primary"
            />
            <button
              type="button"
              onClick={runIdeas}
              title="Find keyword ideas for this seed"
              className="rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-primary"
            >
              {searching ? <Loader2 className="h-3 w-3 animate-spin text-primary" /> : <Search className="h-3 w-3" />}
            </button>
          </div>
          <div className="min-h-0 flex-1 overflow-auto">
            {ideasError !== null && <div className="px-2.5 py-1.5 text-[10px] text-red-600">{ideasError}</div>}
            {ideas !== null && (
              <DataTable<{ kw: string }>
                columns={ideaColumns}
                data={ideas.map((kw) => ({ kw }))}
                rowKey={(r) => r.kw}
                defaultSortKey="volume"
                defaultSortDir="desc"
                layoutKey="optimizer-kw-ideas"
                filterDefs={ideaFilterDefs}
                emptyMessage="No ideas for this seed — try another word."
              />
            )}
          </div>
        </>
      )}
    </aside>
  );
}
