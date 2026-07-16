/**
 * INTERLINK MANAGER MODAL — Strategies
 *
 * Configures and runs internal-link injection for a strategy's generated
 * articles. Two modes:
 *   • Auto   — the AI picks link opportunities across the strategy's articles,
 *              bounded by per-article caps (maxLinks / maxLinksPerArticle) and
 *              an optional AI-anchor fallback (aiAnchors).
 *   • Manual — an explicit rule list (keyword → target URL, phrase/exact match).
 *
 * Posts to POST /strategies/{id}/interlinks (see trpc-routes.ts →
 * strategy.injectInterlinks, which now passes the whole input as the body).
 * An empty body keeps today's auto-with-defaults behaviour on the backend.
 * After a run, per-row results are shown inline and the total is toasted;
 * onDone() lets the parent refetch.
 */

import React, { useEffect, useState } from 'react';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Loader2, Zap, Settings, Plus, Trash2, CheckCircle2, Ban, XCircle } from 'lucide-react';
import { toast } from 'sonner';
import { trpc } from '@/lib/trpc';

// ── Types ──
type MatchType = 'phrase' | 'exact';
/** Anchor-text strategy for auto-mode links: exact destination keyword, a
 *  synonym of it, or let the AI decide. */
type AnchorMode = 'keyword' | 'synonym' | 'ai';

interface ManualRule {
  id: string;
  keyword: string;
  url: string;
  matchType: MatchType;
}

interface InterlinkResult {
  source: string;
  target: string;
  status: 'injected' | 'skipped' | 'failed';
  reason: string;
}

interface InterlinkManagerModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  strategyId: number;
  strategyName: string;
  /** Called after a successful run so the parent can refetch the strategy list. */
  onDone: () => void;
  /** Stored strategy interlink config, if any. When it carries an `anchorMode`
   *  key that wins on open; otherwise the legacy `aiAnchors` boolean (true →
   *  'ai') decides the default. */
  interlinksConfig?: { anchorMode?: AnchorMode; aiAnchors?: boolean } | null;
}

const newRule = (): ManualRule => ({
  id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
  keyword: '',
  url: '',
  matchType: 'phrase',
});

export function InterlinkManagerModal({
  open,
  onOpenChange,
  strategyId,
  strategyName,
  onDone,
  interlinksConfig,
}: InterlinkManagerModalProps) {
  const [activeTab, setActiveTab] = useState<'auto' | 'manual'>('auto');

  // Auto-mode config
  const [maxLinksPerArticle, setMaxLinksPerArticle] = useState(2);
  const [maxLinks, setMaxLinks] = useState(3);
  // Default resolution: an explicit anchorMode on the stored config wins;
  // otherwise the legacy aiAnchors boolean (true → 'ai'), else 'keyword'.
  const [anchorMode, setAnchorMode] = useState<AnchorMode>(
    interlinksConfig?.anchorMode ?? (interlinksConfig?.aiAnchors ? 'ai' : 'keyword'),
  );

  // Manual-mode config
  const [manualRules, setManualRules] = useState<ManualRule[]>([newRule()]);

  // Results
  const [results, setResults] = useState<InterlinkResult[] | null>(null);

  // Reset everything when (re)opened.
  useEffect(() => {
    if (open) {
      setActiveTab('auto');
      setMaxLinksPerArticle(2);
      setMaxLinks(3);
      setAnchorMode(interlinksConfig?.anchorMode ?? (interlinksConfig?.aiAnchors ? 'ai' : 'keyword'));
      setManualRules([newRule()]);
      setResults(null);
    }
  }, [open, interlinksConfig]);

  const interlinksMutation = trpc.strategy.injectInterlinks.useMutation({
    onSuccess: (data: any) => {
      const rows: InterlinkResult[] = Array.isArray(data?.results) ? data.results : [];
      const n = Number(data?.injected ?? rows.filter((r) => r.status === 'injected').length);
      setResults(rows);
      toast.success(
        n > 0
          ? `${n} internal link${n === 1 ? '' : 's'} injected`
          : 'No new interlink opportunities found',
      );
      onDone();
    },
    onError: (err: any) => toast.error(err?.message ?? 'Interlink injection failed'),
  }) as any;

  const running: boolean = !!interlinksMutation.isPending;

  const addRule = () => setManualRules((rules) => [...rules, newRule()]);
  const removeRule = (id: string) =>
    setManualRules((rules) => (rules.length > 1 ? rules.filter((r) => r.id !== id) : rules));
  const updateRule = (id: string, field: keyof ManualRule, value: string) =>
    setManualRules((rules) => rules.map((r) => (r.id === id ? { ...r, [field]: value } : r)));

  const handleRun = () => {
    if (running) return;
    setResults(null);

    if (activeTab === 'manual') {
      const cleaned = manualRules
        .filter((r) => r.keyword.trim() && r.url.trim())
        .map((r) => ({ keyword: r.keyword.trim(), url: r.url.trim(), matchType: r.matchType }));
      if (cleaned.length === 0) {
        toast.error('Add at least one rule with a keyword and target URL.');
        return;
      }
      interlinksMutation.mutate({ id: strategyId, manualRules: cleaned });
    } else {
      interlinksMutation.mutate({
        id: strategyId,
        maxLinks,
        maxLinksPerArticle,
        anchorMode,
        aiAnchors: anchorMode === 'ai', // legacy back-compat key
      });
    }
  };

  const injectedCount = results?.filter((r) => r.status === 'injected').length ?? 0;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[560px] max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Interlink Manager — {strategyName}</DialogTitle>
        </DialogHeader>

        <div className="py-2 space-y-5">
          {/* Mode toggle — plain button pair, no extra tab dependency */}
          <div className="grid grid-cols-2 gap-2 p-1 rounded-lg bg-muted/40">
            <Button
              type="button"
              variant={activeTab === 'auto' ? 'default' : 'ghost'}
              size="sm"
              className="justify-center"
              onClick={() => setActiveTab('auto')}
            >
              <Zap className="w-3.5 h-3.5" />
              Auto
            </Button>
            <Button
              type="button"
              variant={activeTab === 'manual' ? 'default' : 'ghost'}
              size="sm"
              className="justify-center"
              onClick={() => setActiveTab('manual')}
            >
              <Settings className="w-3.5 h-3.5" />
              Manual
            </Button>
          </div>

          {activeTab === 'auto' ? (
            <div className="space-y-5">
              <p className="text-[0.8rem] text-muted-foreground">
                Auto mode scans this strategy's generated articles and inserts internal links
                where they fit naturally, respecting the caps below.
              </p>

              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="max-links-per-article">Max links per article</Label>
                  <Input
                    id="max-links-per-article"
                    type="number"
                    min={1}
                    max={10}
                    value={maxLinksPerArticle}
                    onChange={(e) => setMaxLinksPerArticle(parseInt(e.target.value, 10) || 1)}
                    className="w-full bg-background"
                  />
                  <p className="text-[0.8rem] text-muted-foreground">
                    Cap on links pointing at any single destination article.
                  </p>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="max-links">Max links to insert per article</Label>
                  <Input
                    id="max-links"
                    type="number"
                    min={1}
                    max={20}
                    value={maxLinks}
                    onChange={(e) => setMaxLinks(parseInt(e.target.value, 10) || 1)}
                    className="w-full bg-background"
                  />
                  <p className="text-[0.8rem] text-muted-foreground">
                    Total links inserted into each source article.
                  </p>
                </div>
              </div>

              <div className="space-y-2">
                <Label>Anchor text</Label>
                <div className="flex flex-col space-y-1.5" role="radiogroup" aria-label="Anchor text">
                  <label htmlFor="anchor-keyword" className="flex items-center gap-2 text-sm cursor-pointer">
                    <input
                      type="radio"
                      id="anchor-keyword"
                      name="anchor-mode"
                      className="h-3.5 w-3.5"
                      checked={anchorMode === 'keyword'}
                      onChange={() => setAnchorMode('keyword')}
                    />
                    Destination keyword (exact)
                  </label>
                  <label htmlFor="anchor-synonym" className="flex items-center gap-2 text-sm cursor-pointer">
                    <input
                      type="radio"
                      id="anchor-synonym"
                      name="anchor-mode"
                      className="h-3.5 w-3.5"
                      checked={anchorMode === 'synonym'}
                      onChange={() => setAnchorMode('synonym')}
                    />
                    Synonym of the keyword
                  </label>
                  <label htmlFor="anchor-ai" className="flex items-center gap-2 text-sm cursor-pointer">
                    <input
                      type="radio"
                      id="anchor-ai"
                      name="anchor-mode"
                      className="h-3.5 w-3.5"
                      checked={anchorMode === 'ai'}
                      onChange={() => setAnchorMode('ai')}
                    />
                    Let AI decide
                  </label>
                </div>
              </div>
            </div>
          ) : (
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <Label>Link rules</Label>
                <Button type="button" variant="outline" size="sm" onClick={addRule}>
                  <Plus className="w-3.5 h-3.5" />
                  Add rule
                </Button>
              </div>

              <div className="space-y-2">
                {manualRules.map((rule) => (
                  <div
                    key={rule.id}
                    className="flex items-start gap-2 p-2 rounded-md border bg-muted/20"
                  >
                    <div className="flex-1 grid grid-cols-1 gap-2">
                      <Input
                        placeholder="Keyword to link…"
                        value={rule.keyword}
                        onChange={(e) => updateRule(rule.id, 'keyword', e.target.value)}
                        className="w-full bg-background h-8 text-sm"
                      />
                      <div className="flex gap-2">
                        <Input
                          placeholder="https://target-url…"
                          value={rule.url}
                          onChange={(e) => updateRule(rule.id, 'url', e.target.value)}
                          className="flex-1 bg-background h-8 text-sm"
                        />
                        <Select
                          value={rule.matchType}
                          onValueChange={(v) => updateRule(rule.id, 'matchType', v as MatchType)}
                        >
                          <SelectTrigger className="w-28 h-8 text-sm bg-background">
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="phrase">Phrase</SelectItem>
                            <SelectItem value="exact">Exact</SelectItem>
                          </SelectContent>
                        </Select>
                      </div>
                    </div>
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      className="shrink-0 text-muted-foreground hover:text-destructive"
                      disabled={manualRules.length <= 1}
                      onClick={() => removeRule(rule.id)}
                    >
                      <Trash2 className="w-3.5 h-3.5" />
                    </Button>
                  </div>
                ))}
              </div>
              <p className="text-[0.8rem] text-muted-foreground">
                Each rule links its keyword to the target URL. “Phrase” allows light
                rewrites for flow; “Exact” only links a literal match.
              </p>
            </div>
          )}

          {/* Results panel */}
          {results && (
            <div className="space-y-2 pt-2 border-t">
              <div className="flex items-center justify-between">
                <Label>Results</Label>
                <span className="text-[0.8rem] text-muted-foreground">
                  {injectedCount} injected · {results.length} processed
                </span>
              </div>
              {results.length === 0 ? (
                <p className="text-[0.8rem] text-muted-foreground">
                  No interlink opportunities found.
                </p>
              ) : (
                <div className="space-y-1 max-h-56 overflow-y-auto">
                  {results.map((r, i) => (
                    <div key={i} className="flex items-start gap-2 text-sm">
                      {r.status === 'injected' ? (
                        <CheckCircle2 className="w-4 h-4 mt-0.5 shrink-0 text-green-600" />
                      ) : r.status === 'skipped' ? (
                        <Ban className="w-4 h-4 mt-0.5 shrink-0 text-amber-600" />
                      ) : (
                        <XCircle className="w-4 h-4 mt-0.5 shrink-0 text-red-600" />
                      )}
                      <span className="min-w-0">
                        {r.status === 'injected' ? (
                          <span className="text-foreground">
                            {r.source} → {r.target}
                          </span>
                        ) : (
                          <span className="text-muted-foreground">
                            {r.source} → {r.target} — {r.status}
                            {r.reason ? ` (${r.reason})` : ''}
                          </span>
                        )}
                      </span>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}
        </div>

        <DialogFooter className="pt-4 border-t">
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={running}>
            Cancel
          </Button>
          <Button onClick={handleRun} disabled={running}>
            {running ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Zap className="mr-2 h-4 w-4" />}
            Run Interlinks
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
