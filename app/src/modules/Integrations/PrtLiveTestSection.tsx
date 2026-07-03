/**
 * ProRankTracker Live Test Section
 *
 * Provider-specific panel on the ProRankTracker integration card.
 * Proves the connection works end-to-end with REAL account data:
 *  1. "Load my sites"  → GET /integrations/proranktracker/urls     (live PRT call)
 *  2. pick a site      → GET /integrations/proranktracker/ranks    (current view)
 *  3. pick a period    → GET /integrations/proranktracker/history  (1/3/6 months)
 *
 * Current view: keyword, ranking URL, rank, Δ vs yesterday, 7d/30d ago, volume.
 * History view: keyword, ranking URL, rank now, Δ over period, trend sparkline, best.
 *
 * Read-only — never mutates anything in the PRT account.
 */

import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { BarChart3, Loader2, ArrowUp, ArrowDown, Minus, RefreshCw, ExternalLink } from 'lucide-react';
import { trpc } from '@/lib/trpc';

// ============================================
// Types (mirror the REST payloads)
// ============================================

interface PrtSite {
  id: string;
  url: string;
  business_name: string;
}

interface PrtTerm {
  term: string;
  engine: string;
  termType: string;
  rank: number;
  yesterday: number;
  weekAgo: number;
  monthAgo: number;
  topRank: number;
  matchedUrl: string;
  localVolume: number;
  globalVolume: number;
}

interface PrtRanks {
  id: string;
  url: string;
  topRank: number;
  terms: PrtTerm[];
}

interface PrtHistoryPoint {
  date: string;
  rank: number;
}

interface PrtHistoryTerm {
  term: string;
  engine: string;
  rank: number;
  topRank: number;
  matchedUrl: string;
  history: PrtHistoryPoint[];
}

interface PrtHistory {
  id: string;
  url: string;
  from: string;
  to: string;
  terms: PrtHistoryTerm[];
}

// PRT reports "not in top 500" as rank 501+
const NOT_FOUND_RANK = 501;

const isRanked = (rank: number) => rank > 0 && rank < NOT_FOUND_RANK;

/** Render a rank number, or an em dash when the site is not ranked. */
function rankLabel(rank: number): string {
  return isRanked(rank) ? String(rank) : '—';
}

/** Shorten a matched URL to its path for the table (full URL in the link). */
function urlPath(url: string): string {
  if (!url) return '—';
  try {
    const u = new URL(url);
    const path = u.pathname === '/' ? '/' : u.pathname.replace(/\/$/, '');
    return path.length > 34 ? path.slice(0, 32) + '…' : path;
  } catch {
    return url;
  }
}

/** Up/down/equal indicator between two ranks (lower rank = better). */
function DeltaBadge({ current, previous }: { current: number; previous: number }) {
  if (!isRanked(current) || !isRanked(previous)) {
    return <Minus className="w-3 h-3 text-muted-foreground inline" />;
  }
  const diff = previous - current; // positive = improved
  if (diff > 0) {
    return (
      <span className="text-emerald-600 text-xs inline-flex items-center gap-0.5">
        <ArrowUp className="w-3 h-3" />{diff}
      </span>
    );
  }
  if (diff < 0) {
    return (
      <span className="text-red-600 text-xs inline-flex items-center gap-0.5">
        <ArrowDown className="w-3 h-3" />{Math.abs(diff)}
      </span>
    );
  }
  return <Minus className="w-3 h-3 text-muted-foreground inline" />;
}

/** Clickable ranking-URL cell (which page of the site is actually ranking). */
function MatchedUrlCell({ url }: { url: string }) {
  if (!url) return <span className="text-muted-foreground">—</span>;
  return (
    <a
      href={url}
      target="_blank"
      rel="noopener noreferrer"
      title={url}
      className="text-primary hover:underline inline-flex items-center gap-0.5 max-w-[180px]"
    >
      <span className="truncate">{urlPath(url)}</span>
      <ExternalLink className="w-2.5 h-2.5 shrink-0" />
    </a>
  );
}

/**
 * Rank-trend sparkline. One series per row, so identity is carried by the row —
 * the mark uses the theme's primary token via currentColor (light/dark safe).
 * Y is inverted (rank 1 at the top = better). Unranked days break the line
 * rather than plotting a fake value.
 */
function RankSparkline({ history }: { history: PrtHistoryPoint[] }) {
  const W = 96;
  const H = 24;
  const PAD = 2;

  const ranks = history.map((h) => h.rank);
  const valid = ranks.filter(isRanked);
  if (valid.length === 0) {
    return <span className="text-xs text-muted-foreground">not ranked</span>;
  }

  const min = Math.min(...valid); // best rank in window
  const max = Math.max(...valid); // worst rank in window
  const span = Math.max(max - min, 1);
  const n = history.length;
  const x = (i: number) => (n === 1 ? W / 2 : PAD + (i * (W - 2 * PAD)) / (n - 1));
  // Inverted: best (min) at the top
  const y = (rank: number) => PAD + ((rank - min) / span) * (H - 2 * PAD);

  // Build line segments, breaking on unranked days
  const segments: string[] = [];
  let current: string[] = [];
  history.forEach((h, i) => {
    if (isRanked(h.rank)) {
      current.push(`${x(i).toFixed(1)},${y(h.rank).toFixed(1)}`);
    } else if (current.length) {
      segments.push(current.join(' '));
      current = [];
    }
  });
  if (current.length) segments.push(current.join(' '));

  // Last ranked point gets an end dot
  let lastIdx = -1;
  for (let i = n - 1; i >= 0; i--) {
    if (isRanked(history[i].rank)) { lastIdx = i; break; }
  }

  const first = history.find((h) => isRanked(h.rank));
  const last = lastIdx >= 0 ? history[lastIdx] : undefined;

  return (
    <svg
      width={W}
      height={H}
      viewBox={`0 0 ${W} ${H}`}
      className="text-primary"
      role="img"
      aria-label={`Rank trend, best ${min}, worst ${max}`}
    >
      <title>
        {`${first ? `${first.date}: #${first.rank}` : ''}${last ? ` → ${last.date}: #${last.rank}` : ''} (best #${min}, worst #${max})`}
      </title>
      {segments.map((points, i) => (
        <polyline
          key={i}
          points={points}
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
        />
      ))}
      {last && (
        <circle cx={x(lastIdx)} cy={y(last.rank)} r="2.5" fill="currentColor" />
      )}
    </svg>
  );
}

// ============================================
// Component
// ============================================

type Period = 'current' | '30' | '90' | '180';

export function PrtLiveTestSection() {
  const [isOpen, setIsOpen] = useState(false);
  const [selectedSite, setSelectedSite] = useState<string>('');
  const [period, setPeriod] = useState<Period>('current');

  // Sites load only after the panel is opened (no background PRT calls)
  const sitesQuery = trpc.integrations.prtUrls.useQuery(undefined, { enabled: isOpen });
  const sites: PrtSite[] = sitesQuery.data ?? [];

  // Current ranks (rich snapshot: volumes + yesterday/7d/30d deltas)
  const ranksQuery = trpc.integrations.prtRanks.useQuery(
    { urlId: selectedSite },
    { enabled: isOpen && selectedSite !== '' && period === 'current' }
  );
  const ranks: PrtRanks | undefined = ranksQuery.data;

  // Rank history (1 / 3 / 6 months) with day-by-day trend
  const historyQuery = trpc.integrations.prtHistory.useQuery(
    { urlId: selectedSite, days: period },
    { enabled: isOpen && selectedSite !== '' && period !== 'current' }
  );
  const history: PrtHistory | undefined = historyQuery.data;

  const activeQuery = period === 'current' ? ranksQuery : historyQuery;

  if (!isOpen) {
    return (
      <Button variant="outline" size="sm" className="mt-2 gap-1.5 text-xs" onClick={() => setIsOpen(true)}>
        <BarChart3 className="w-3 h-3" />
        Live Test — Pull My Rankings
      </Button>
    );
  }

  return (
    <div className="mt-3 border rounded-lg p-3 space-y-3 bg-muted/30">
      <div className="flex items-center justify-between">
        <span className="text-xs font-medium flex items-center gap-1.5">
          <BarChart3 className="w-3.5 h-3.5" />
          Live rankings from your ProRankTracker account
        </span>
        <Button variant="ghost" size="sm" className="h-6 text-xs" onClick={() => setIsOpen(false)}>
          Close
        </Button>
      </div>

      {/* Step 1 — site + period pickers, one filter row */}
      {sitesQuery.isLoading && (
        <p className="text-xs text-muted-foreground flex items-center gap-1.5">
          <Loader2 className="w-3 h-3 animate-spin" /> Loading your tracked sites…
        </p>
      )}
      {sitesQuery.isError && (
        <p className="text-xs text-destructive">
          Failed to load sites: {(sitesQuery.error as Error)?.message ?? 'Unknown error'}
        </p>
      )}
      {!sitesQuery.isLoading && !sitesQuery.isError && (
        <div className="flex items-center gap-2">
          <Select value={selectedSite} onValueChange={setSelectedSite}>
            <SelectTrigger className="h-8 text-xs flex-1">
              <SelectValue placeholder={sites.length ? `Select a site (${sites.length} tracked)` : 'No sites in this account'} />
            </SelectTrigger>
            <SelectContent>
              {sites.map((site) => (
                <SelectItem key={site.id} value={site.id}>
                  {site.url}{site.business_name ? ` — ${site.business_name}` : ''}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Select value={period} onValueChange={(v) => setPeriod(v as Period)}>
            <SelectTrigger className="h-8 text-xs w-[130px]">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="current">Current</SelectItem>
              <SelectItem value="30">Last month</SelectItem>
              <SelectItem value="90">Last 3 months</SelectItem>
              <SelectItem value="180">Last 6 months</SelectItem>
            </SelectContent>
          </Select>
          <Button
            variant="ghost"
            size="icon"
            className="h-8 w-8"
            title="Refresh"
            onClick={() => { sitesQuery.refetch(); if (selectedSite) activeQuery.refetch(); }}
          >
            <RefreshCw className="w-3.5 h-3.5" />
          </Button>
        </div>
      )}

      {/* Step 2 — loading / error state for the active view */}
      {selectedSite !== '' && activeQuery.isLoading && (
        <p className="text-xs text-muted-foreground flex items-center gap-1.5">
          <Loader2 className="w-3 h-3 animate-spin" />
          {period === 'current' ? 'Pulling current ranks…' : 'Pulling rank history…'}
        </p>
      )}
      {selectedSite !== '' && activeQuery.isError && (
        <p className="text-xs text-destructive">
          Failed to load: {(activeQuery.error as Error)?.message ?? 'Unknown error'}
        </p>
      )}

      {/* Current snapshot view */}
      {period === 'current' && ranks && !ranksQuery.isLoading && (
        <>
          <div className="max-h-64 overflow-y-auto rounded border bg-background">
            <table className="w-full text-xs">
              <thead className="sticky top-0 bg-muted">
                <tr className="text-left">
                  <th className="px-2 py-1.5 font-medium">Keyword</th>
                  <th className="px-2 py-1.5 font-medium">Ranking URL</th>
                  <th className="px-2 py-1.5 font-medium">Engine</th>
                  <th className="px-2 py-1.5 font-medium text-right">Rank</th>
                  <th className="px-2 py-1.5 font-medium text-right">Δ 1d</th>
                  <th className="px-2 py-1.5 font-medium text-right">7d ago</th>
                  <th className="px-2 py-1.5 font-medium text-right">30d ago</th>
                  <th className="px-2 py-1.5 font-medium text-right">Volume</th>
                </tr>
              </thead>
              <tbody>
                {ranks.terms.length === 0 && (
                  <tr>
                    <td colSpan={8} className="px-2 py-3 text-center text-muted-foreground">
                      No terms tracked for this site yet.
                    </td>
                  </tr>
                )}
                {ranks.terms.map((t, i) => (
                  <tr key={i} className="border-t">
                    <td className="px-2 py-1.5 font-medium">{t.term}</td>
                    <td className="px-2 py-1.5"><MatchedUrlCell url={t.matchedUrl} /></td>
                    <td className="px-2 py-1.5 text-muted-foreground">{t.engine}</td>
                    <td className="px-2 py-1.5 text-right font-semibold">{rankLabel(t.rank)}</td>
                    <td className="px-2 py-1.5 text-right"><DeltaBadge current={t.rank} previous={t.yesterday} /></td>
                    <td className="px-2 py-1.5 text-right text-muted-foreground">{rankLabel(t.weekAgo)}</td>
                    <td className="px-2 py-1.5 text-right text-muted-foreground">{rankLabel(t.monthAgo)}</td>
                    <td className="px-2 py-1.5 text-right text-muted-foreground">
                      {t.localVolume ? t.localVolume.toLocaleString() : '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {ranks.terms.length > 0 && (
            <p className="text-[10px] text-muted-foreground">
              {ranks.terms.length} terms · live from api.proranktracker.com · best rank: {rankLabel(ranks.topRank)}
            </p>
          )}
        </>
      )}

      {/* History view with trend sparklines */}
      {period !== 'current' && history && !historyQuery.isLoading && (
        <>
          <div className="max-h-64 overflow-y-auto rounded border bg-background">
            <table className="w-full text-xs">
              <thead className="sticky top-0 bg-muted">
                <tr className="text-left">
                  <th className="px-2 py-1.5 font-medium">Keyword</th>
                  <th className="px-2 py-1.5 font-medium">Ranking URL</th>
                  <th className="px-2 py-1.5 font-medium text-right">Now</th>
                  <th className="px-2 py-1.5 font-medium text-right">Δ period</th>
                  <th className="px-2 py-1.5 font-medium">Trend</th>
                  <th className="px-2 py-1.5 font-medium text-right">Best</th>
                </tr>
              </thead>
              <tbody>
                {history.terms.length === 0 && (
                  <tr>
                    <td colSpan={6} className="px-2 py-3 text-center text-muted-foreground">
                      No history for this site in the selected period.
                    </td>
                  </tr>
                )}
                {history.terms.map((t, i) => {
                  const firstRanked = t.history.find((h) => isRanked(h.rank));
                  return (
                    <tr key={i} className="border-t">
                      <td className="px-2 py-1.5 font-medium">{t.term}</td>
                      <td className="px-2 py-1.5"><MatchedUrlCell url={t.matchedUrl} /></td>
                      <td className="px-2 py-1.5 text-right font-semibold">{rankLabel(t.rank)}</td>
                      <td className="px-2 py-1.5 text-right">
                        <DeltaBadge current={t.rank} previous={firstRanked?.rank ?? 0} />
                      </td>
                      <td className="px-2 py-1.5"><RankSparkline history={t.history} /></td>
                      <td className="px-2 py-1.5 text-right text-muted-foreground">{rankLabel(t.topRank)}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          {history.terms.length > 0 && (
            <p className="text-[10px] text-muted-foreground">
              {history.terms.length} terms · {history.from} → {history.to} · live from api.proranktracker.com
            </p>
          )}
        </>
      )}
    </div>
  );
}
