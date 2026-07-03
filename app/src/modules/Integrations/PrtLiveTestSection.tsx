/**
 * ProRankTracker Live Test Section
 *
 * Provider-specific panel on the ProRankTracker integration card.
 * Proves the connection works end-to-end with REAL account data:
 *  1. "Load my sites"  → GET /integrations/proranktracker/urls   (live PRT call)
 *  2. pick a site      → GET /integrations/proranktracker/ranks  (live PRT call)
 *  3. table of current ranks (keyword, rank, Δ yesterday, week/month ago, volume)
 *
 * Read-only — never mutates anything in the PRT account.
 */

import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { BarChart3, Loader2, ArrowUp, ArrowDown, Minus, RefreshCw } from 'lucide-react';
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

// PRT reports "not in top 500" as rank 501+
const NOT_FOUND_RANK = 501;

/** Render a rank number, or an em dash when the site is not ranked. */
function rankLabel(rank: number): string {
  if (!rank || rank >= NOT_FOUND_RANK) return '—';
  return String(rank);
}

/** Up/down/equal indicator vs yesterday (lower rank = better). */
function DeltaBadge({ current, previous }: { current: number; previous: number }) {
  const hasBoth = current > 0 && previous > 0 && current < NOT_FOUND_RANK && previous < NOT_FOUND_RANK;
  if (!hasBoth) return <Minus className="w-3 h-3 text-muted-foreground inline" />;
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

// ============================================
// Component
// ============================================

export function PrtLiveTestSection() {
  const [isOpen, setIsOpen] = useState(false);
  const [selectedSite, setSelectedSite] = useState<string>('');

  // Sites load only after the panel is opened (no background PRT calls)
  const sitesQuery = trpc.integrations.prtUrls.useQuery(undefined, { enabled: isOpen });
  const sites: PrtSite[] = sitesQuery.data ?? [];

  // Ranks load only once a site is picked
  const ranksQuery = trpc.integrations.prtRanks.useQuery(
    { urlId: selectedSite },
    { enabled: isOpen && selectedSite !== '' }
  );
  const ranks: PrtRanks | undefined = ranksQuery.data;

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

      {/* Step 1 — site picker */}
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
          <Button
            variant="ghost"
            size="icon"
            className="h-8 w-8"
            title="Refresh"
            onClick={() => { sitesQuery.refetch(); if (selectedSite) ranksQuery.refetch(); }}
          >
            <RefreshCw className="w-3.5 h-3.5" />
          </Button>
        </div>
      )}

      {/* Step 2 — ranks table */}
      {selectedSite !== '' && ranksQuery.isLoading && (
        <p className="text-xs text-muted-foreground flex items-center gap-1.5">
          <Loader2 className="w-3 h-3 animate-spin" /> Pulling current ranks…
        </p>
      )}
      {selectedSite !== '' && ranksQuery.isError && (
        <p className="text-xs text-destructive">
          Failed to load ranks: {(ranksQuery.error as Error)?.message ?? 'Unknown error'}
        </p>
      )}
      {ranks && !ranksQuery.isLoading && (
        <div className="max-h-64 overflow-y-auto rounded border bg-background">
          <table className="w-full text-xs">
            <thead className="sticky top-0 bg-muted">
              <tr className="text-left">
                <th className="px-2 py-1.5 font-medium">Keyword</th>
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
                  <td colSpan={7} className="px-2 py-3 text-center text-muted-foreground">
                    No terms tracked for this site yet.
                  </td>
                </tr>
              )}
              {ranks.terms.map((t, i) => (
                <tr key={i} className="border-t">
                  <td className="px-2 py-1.5 font-medium" title={t.matchedUrl}>{t.term}</td>
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
      )}
      {ranks && ranks.terms.length > 0 && (
        <p className="text-[10px] text-muted-foreground">
          {ranks.terms.length} terms · live from api.proranktracker.com · best rank: {rankLabel(ranks.topRank)}
        </p>
      )}
    </div>
  );
}
