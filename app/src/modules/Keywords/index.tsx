/**
 * KEYWORD EXPLORER MODULE — Main Orchestrator
 *
 * Progressive loading: fetches prefix list from backend, then
 * calls search endpoint once per prefix. Results populate the
 * table and sidebar live as each prefix completes.
 *
 * Search strategy: server-side proxy first, automatic JSONP
 * fallback when Google rate-limits the server IP.
 *
 * Zero hardcoded values — prefixes come from backend config.
 */

import { useState, useCallback, useMemo, useRef, useEffect } from 'react';
import type { RowSelectionState, Row } from '@tanstack/react-table';
import { Search, Copy, BarChart3, X, ChevronDown, Save, FolderOpen, Trash2, Zap, PenLine } from 'lucide-react';
import { toast } from 'sonner';

import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Badge } from '@/components/ui/badge';
import { BulkActionBar } from '@/components/shared/BulkActionBar';
import { colors, typography } from '@/components/shared';
import { SaveListDialog } from './SaveListDialog';
import { CreateStrategyDialog, type StrategyPayload } from './CreateStrategyDialog';
import { SendToWriterDialog } from './SendToWriterDialog';
import { DataTableViewOptions } from './DataTableViewOptions';
import { DataTableSerpFilter } from './DataTableSerpFilter';

import { trpc } from '@/lib/trpc';
import { useApp, type PendingWriterData } from '@/contexts/AppContext';
import { DataTable } from './data-table';
import { columns, SerpSubRow } from './columns';
import type { KeywordResult } from './types';
import { fetchSuggestionsJsonp } from './googleSuggestFallback';

// ============================================
// Language / Region options
// ============================================

const LANGUAGES = [
  { value: 'en', label: 'English (US)' },
  { value: 'sv', label: 'Swedish (SE)' },
  { value: 'no', label: 'Norwegian (NO)' },
  { value: 'de', label: 'German (DE)' },
  { value: 'fr', label: 'French (FR)' },
  { value: 'es', label: 'Spanish (ES)' },
];

// ============================================
// Module Component
// ============================================

export function KeywordsModule() {
  // ── Search State ──
  const [query, setQuery] = useState('');
  const [lang, setLang] = useState('sv');
  const [isSearching, setIsSearching] = useState(false);
  const [statusMessage, setStatusMessage] = useState('');
  const [progress, setProgress] = useState({ current: 0, total: 0, prefix: '' });

  // ── Abort handle for cancellation ──
  const abortRef = useRef(false);

  // ── JSONP fallback: tracks whether server is rate-limited ──
  // When true, all remaining requests in the current search session
  // go directly via client-side JSONP (user's browser IP).
  const useJsonpRef = useRef(false);
  const emptyStreakRef = useRef(0);

  // ── Grouped results: { "what": KeywordResult[], "how": [...], ... } ──
  const [groupedResults, setGroupedResults] = useState<Record<string, KeywordResult[]>>({});
  const [activeCategory, setActiveCategory] = useState('all');

  // ── Table selection state ──
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});

  // ── SERP DR opt-in toggle ──
  const [includeSerpDR, setIncludeSerpDR] = useState(false);

  // ── Total count across all groups ──
  const totalCount = useMemo(
    () => Object.values(groupedResults).reduce((sum, arr) => sum + arr.length, 0),
    [groupedResults],
  );

  // ── SERP Blacklist State ──
  const [serpBlacklist, setSerpBlacklist] = useState<string[]>(() => {
    try {
      const stored = localStorage.getItem('pcm_serp_blacklist');
      if (stored) return JSON.parse(stored);
    } catch {}
    return ['youtube.com', 'facebook.com', 'instagram.com', 'twitter.com', 'pinterest.com', 'tiktok.com', 'linkedin.com'];
  });

  useEffect(() => {
    localStorage.setItem('pcm_serp_blacklist', JSON.stringify(serpBlacklist));
  }, [serpBlacklist]);

  // ── Filtered items based on active category & SERP Data Pipeline ──
  const displayedResults = useMemo(() => {
    const source = activeCategory === 'all' 
      ? Object.values(groupedResults).flat() 
      : (groupedResults[activeCategory] ?? []);
      
    // SERP Filtering Pipeline
    return source.map(row => {
      if (!row.serpResults || row.serpResults.length === 0) return row;
      
      const validSerp = row.serpResults.filter((s: any) => {
        if (!s.url) return false;
        try {
          const hostname = new URL(s.url).hostname.replace(/^www\./, '');
          return !serpBlacklist.some(b => hostname.includes(b) || hostname === b);
        } catch {
          return false;
        }
      });
      
      const top5 = validSerp.slice(0, 5).map((s: any, idx: number) => ({
        ...s,
        position: idx + 1
      }));
      
      const drs = top5.map((s: any) => s.domain_rating).filter((d: any) => d !== undefined && d !== null);
      const avgDR = drs.length > 0 ? Math.round(drs.reduce((a: number, b: number) => a + b, 0) / drs.length) : 0;
      const lowDR = drs.length > 0 ? Math.min(...drs) : 0;
      
      const urs = top5.map((s: any) => s.url_rating).filter((u: any) => u !== undefined && u !== null);
      const avgUR = urs.length > 0 ? Math.round(urs.reduce((a: number, b: number) => a + b, 0) / urs.length) : 0;
      const lowUR = urs.length > 0 ? Math.min(...urs) : 0;
      
      return {
        ...row,
        serpAvgDR: avgDR,
        serpLowDR: lowDR,
        serpAvgUR: avgUR,
        serpLowUR: lowUR,
        serpResults: top5,
      };
    });
  }, [groupedResults, activeCategory, serpBlacklist]);

  // ── Fetch prefix list + country code from backend (reactive to lang changes) ──
  // Backend is the single source of truth for lang→country mapping.
  const { data: prefixData } = trpc.keywords.prefixes.useQuery({ lang });
  const allPrefixes: string[] = (prefixData as any)?.prefixes ?? [];
  const country: string = (prefixData as any)?.country ?? lang;

  // ── Modifier selector state ──
  // Set of selected modifier strings. All selected by default.
  // Auto-resets when language changes (new modifiers loaded).
  const [selectedModifierSet, setSelectedModifierSet] = useState<Set<string>>(new Set());

  // Modifier mode: how words are combined with the seed keyword
  type ModifierMode = 'prefix' | 'suffix' | 'both';
  const [modifierMode, setModifierMode] = useState<ModifierMode>('prefix');

  // Auto-select all modifiers when language changes
  useEffect(() => {
    if (allPrefixes.length > 0) {
      setSelectedModifierSet(new Set(allPrefixes));
    }
  }, [allPrefixes]);

  // Active modifiers = only those selected by user
  const modifiers = useMemo(
    () => allPrefixes.filter((p) => selectedModifierSet.has(p)),
    [allPrefixes, selectedModifierSet],
  );

  // Toggle a single modifier on/off
  const toggleModifier = useCallback((mod: string) => {
    setSelectedModifierSet((prev) => {
      const next = new Set(prev);
      if (next.has(mod)) next.delete(mod);
      else next.add(mod);
      return next;
    });
  }, []);

  // Select all / deselect all
  const toggleAllModifiers = useCallback(() => {
    setSelectedModifierSet((prev) =>
      prev.size === allPrefixes.length ? new Set() : new Set(allPrefixes),
    );
  }, [allPrefixes]);

  // ── tRPC mutation for single search call ──
  const searchSingle = trpc.keywords.search.useMutation();

  // ── Enrich mutation (Ahrefs) — handles both volume + optional SERP DR ──
  const enrichMutation = trpc.keywords.enrich.useMutation({
    onSuccess: (data: any) => {
      const enriched: Record<string, { volume?: number; difficulty?: number; cpc?: number }> = data?.enriched ?? {};
      const serpDR: Record<string, { avgDR: number; lowDR: number; avgUR: number; lowUR: number; serpResults: any[] }> = data?.serpDR ?? {};

      setGroupedResults((prev) => {
        const next = { ...prev };
        for (const cat of Object.keys(next)) {
          next[cat] = next[cat].map((r) => {
            const metrics = enriched[r.keyword];
            const serp = serpDR[r.keyword];
            return {
              ...r,
              ...(metrics ? { ...metrics, enriched: true } : {}),
              ...(serp ? {
                serpAvgDR: serp.avgDR,
                serpLowDR: serp.lowDR,
                serpAvgUR: serp.avgUR,
                serpLowUR: serp.lowUR,
                serpResults: serp.serpResults,
              } : {}),
            };
          });
        }
        return next;
      });

      const volCount = Object.keys(enriched).length;
      const serpCount = Object.keys(serpDR).length;
      const msg = serpCount > 0
        ? `Enriched ${volCount} keywords + ${serpCount} SERP results`
        : `Enriched ${volCount} keywords`;
      toast.success(msg);
    },
    onError: (err: any) => toast.error(err.message ?? 'Enrichment failed'),
  });

  // ── Unified suggestion fetcher: server → JSONP fallback ──
  // Tries server-side proxy first. If 2 consecutive calls return empty
  // (indicates rate-limiting), switches to client-side JSONP for the
  // rest of the session. The search loop doesn't need to know which
  // method is active — this function handles it transparently.
  const fetchSuggestions = useCallback(async (q: string): Promise<string[]> => {
    // Already in fallback mode — go straight to JSONP
    if (useJsonpRef.current) {
      return fetchSuggestionsJsonp(q, lang, country);
    }

    // Try server-side first
    try {
      // `gl` (market) is REQUIRED here, exactly as the JSONP calls below pass it.
      // Omitting it made google_suggest() build a gl-less suggest URL (its
      // array_filter drops the empty value), so Google geolocated the request by
      // the HUB SERVER'S IP instead of the chosen market — a Swedish search
      // returned Indian suggestions. The JSONP fallback never had this bug
      // because it runs in the browser, on the user's own IP, and always sent gl.
      const result: any = await searchSingle.mutateAsync({ query: q, lang, gl: country });
      const suggestions: string[] = result?.suggestions ?? [];

      if (suggestions.length > 0) {
        // Server worked — reset empty streak
        emptyStreakRef.current = 0;
        return suggestions;
      }

      // Empty response — server might be rate-limited.
      // Always try JSONP for THIS request so no data is lost.
      emptyStreakRef.current++;
      if (emptyStreakRef.current >= 2) {
        useJsonpRef.current = true;
        console.info('Google Suggest: server rate-limited, switching to client-side JSONP');
      }
      return fetchSuggestionsJsonp(q, lang, country);
    } catch {
      // Server error — try JSONP for this request
      emptyStreakRef.current++;
      if (emptyStreakRef.current >= 2) {
        useJsonpRef.current = true;
        console.info('Google Suggest: server failed, switching to client-side JSONP');
      }
      try {
        return await fetchSuggestionsJsonp(q, lang, country);
      } catch {
        return [];
      }
    }
  }, [lang, country, searchSingle]);

  // ── Progressive search handler ──
  const handleSearch = useCallback(async () => {
    const trimmed = query.trim();
    if (!trimmed) return;

    // Reset state for new search session
    abortRef.current = false;
    useJsonpRef.current = false;
    emptyStreakRef.current = 0;
    setIsSearching(true);
    setGroupedResults({});
    setActiveCategory('all');
    setRowSelection({});
    setStatusMessage('Starting keyword generation...');

    try {
      // Build query list: direct + modifier combos based on mode
      // Direct search always runs, even with 0 modifiers selected

      const seen = new Set<string>();
      let idCounter = 0;
      let totalFound = 0;

      // Calculate total steps: 1 (direct) + modifiers × mode multiplier
      const modeMultiplier = modifierMode === 'both' ? 2 : 1;
      const totalSteps = 1 + (modifiers.length * modeMultiplier);

      // Step 1: Vanilla/direct search — bare keyword without any modifier
      setProgress({ current: 1, total: totalSteps, prefix: 'direct' });
      setStatusMessage(`Analyzing: "${trimmed}" (direct)...`);
      try {
        const directSuggestions = await fetchSuggestions(trimmed);
        const directUnique: KeywordResult[] = [];

        for (const kw of directSuggestions) {
          const lower = kw.toLowerCase();
          if (!seen.has(lower)) {
            seen.add(lower);
            directUnique.push({
              id: `kw_${Date.now()}_${idCounter++}`,
              keyword: kw,
              category: 'direct',
              enriched: false,
            });
            totalFound++;
          }
        }

        if (directUnique.length > 0) {
          setGroupedResults((prev) => ({ ...prev, direct: directUnique }));
        }
      } catch (err: any) {
        console.warn('Direct search failed:', err.message);
      }

      await new Promise((r) => setTimeout(r, 200));

      // Step 2: Loop through each modifier, fetch suggestions by mode
      let stepIndex = 1; // starts after direct
      for (let i = 0; i < modifiers.length; i++) {
        if (abortRef.current) {
          setStatusMessage(`Cancelled. Found ${totalFound} keywords.`);
          break;
        }

        const mod = modifiers[i];

        // Build queries based on modifier mode
        const queries: { query: string; label: string }[] = [];
        if (modifierMode === 'prefix' || modifierMode === 'both') {
          queries.push({ query: `${mod} ${trimmed}`, label: `${mod} ...` });
        }
        if (modifierMode === 'suffix' || modifierMode === 'both') {
          queries.push({ query: `${trimmed} ${mod}`, label: `... ${mod}` });
        }

        for (const { query, label } of queries) {
          if (abortRef.current) break;
          stepIndex++;

          setProgress({ current: stepIndex, total: totalSteps, prefix: label });
          const modeLabel = useJsonpRef.current ? ' [direct]' : '';
          setStatusMessage(`Analyzing ${stepIndex}/${totalSteps}: "${label}"${modeLabel} (${totalFound} found)`);

          try {
            const suggestions = await fetchSuggestions(query);

            // Deduplicate across all modifiers
            const unique: KeywordResult[] = [];
            for (const kw of suggestions) {
              const lower = kw.toLowerCase();
              if (!seen.has(lower)) {
                seen.add(lower);
                unique.push({
                  id: `kw_${Date.now()}_${idCounter++}`,
                  keyword: kw,
                  category: mod,
                  enriched: false,
                });
                totalFound++;
              }
            }

            // Update state immediately — table renders new results
            if (unique.length > 0) {
              setGroupedResults((prev) => ({
                ...prev,
                [mod]: [...(prev[mod] ?? []), ...unique],
              }));
            }
          } catch (err: any) {
            console.warn(`Modifier "${label}" failed:`, err.message);
          }

          // Polite delay between requests (200ms)
          await new Promise((r) => setTimeout(r, 200));
        }
      }

      setStatusMessage(`Done! Found ${totalFound} keywords across ${Object.keys(seen).length > 0 ? 'multiple' : '0'} categories.`);
    } catch (err: any) {
      toast.error(err.message ?? 'Failed to fetch prefixes');
      setStatusMessage('');
    } finally {
      setIsSearching(false);
    }
  }, [query, lang, modifiers, modifierMode, fetchSuggestions]);

  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent) => {
      if (e.key === 'Enter') handleSearch();
    },
    [handleSearch],
  );

  const handleCancel = useCallback(() => {
    abortRef.current = true;
  }, []);

  // ── Selected keywords ──
  const selectedKeywords = useMemo(() => {
    return Object.keys(rowSelection)
      .filter((key) => rowSelection[key])
      .map((key) => displayedResults[Number(key)])
      .filter(Boolean);
  }, [rowSelection, displayedResults]);

  const selectedCount = selectedKeywords.length;

  // ── Bulk Actions ──
  const handleCopy = useCallback(async () => {
    const text = selectedKeywords.map((k) => k.keyword).join('\n');
    try {
      // Modern Clipboard API — requires HTTPS or localhost
      await navigator.clipboard.writeText(text);
    } catch {
      // Fallback for HTTP sites (e.g. *.local dev environments)
      const textarea = document.createElement('textarea');
      textarea.value = text;
      textarea.style.position = 'fixed';
      textarea.style.opacity = '0';
      document.body.appendChild(textarea);
      textarea.select();
      document.execCommand('copy');
      document.body.removeChild(textarea);
    }
    toast.success(`${selectedCount} keywords copied`);
  }, [selectedKeywords, selectedCount]);

  // Track which keywords are currently being enriched
  const enrichingKeywordsRef = useRef<Set<string>>(new Set());

  const handleEnrich = useCallback(() => {
    const keywords = selectedKeywords.map((k) => k.keyword);
    enrichingKeywordsRef.current = new Set(keywords);
    enrichMutation.mutate({ keywords, country: lang, includeSerpDR });
  }, [selectedKeywords, enrichMutation, lang, includeSerpDR]);

  // ── Saved keyword lists ──
  const { data: savedListsRaw, refetch: refetchLists } = (trpc as any).keywords.listSaved.useQuery();
  const savedLists: any[] = (savedListsRaw as any) ?? [];

  const saveListMutation = (trpc as any).keywords.saveList.useMutation({
    onSuccess: () => {
      toast.success('Keyword list saved');
      refetchLists();
    },
    onError: (err: any) => toast.error(err.message ?? 'Failed to save list'),
  });

  const deleteListMutation = (trpc as any).keywords.deleteList.useMutation({
    onSuccess: () => {
      toast.success('List deleted');
      refetchLists();
    },
    onError: (err: any) => toast.error(err.message ?? 'Failed to delete list'),
  });

  const loadListMutation = (trpc as any).keywords.loadList.useMutation();

  const [saveModalOpen, setSaveModalOpen] = useState(false);

  /** Trigger opening the modal */
  const handleOpenSaveList = useCallback(() => {
    if (selectedKeywords.length === 0) {
      toast.error('No keywords selected');
      return;
    }
    setSaveModalOpen(true);
  }, [selectedKeywords.length]);

  /** Perform actual save from modal */
  const handlePerformSaveList = useCallback((name: string, projectId: number) => {
    // Group selected keywords by category to match groupedResults structure
    const selectedGrouped: Record<string, typeof selectedKeywords> = {};
    for (const kw of selectedKeywords) {
      const cat = kw.category ?? 'direct';
      if (!selectedGrouped[cat]) selectedGrouped[cat] = [];
      selectedGrouped[cat].push(kw);
    }

    saveListMutation.mutate({
      name,
      seed: query,
      lang,
      projectId,
      data: selectedGrouped,
      totalCount: selectedKeywords.length,
    }, {
      onSuccess: () => setSaveModalOpen(false)
    });
  }, [query, lang, selectedKeywords, saveListMutation]);

  // ── Strategy creation ──
  const [strategyDialogOpen, setStrategyDialogOpen] = useState(false);

  const createStrategyMutation = (trpc as any).strategy.create.useMutation({
    onSuccess: (_data: any, variables: any) => {
      // RSS/social strategies arm an immediate first source scan on the
      // backend — the latest existing post is being pulled right now.
      toast.success(variables?.sourceMode === 'rss' || variables?.sourceMode === 'social'
        ? 'Strategy created — pulling the latest posts now'
        : 'Strategy created successfully!');
      setStrategyDialogOpen(false);
      setRowSelection({}); // Clear selection when done
    },
    onError: (err: any) => toast.error(err.message ?? 'Failed to create strategy'),
  });

  // No table selection required any more: the dialog lets you TYPE a primary +
  // supporting keywords (or switch the source to RSS/social), and it blocks its own
  // Create button until at least one keyword exists from either route.
  const handleOpenStrategy = useCallback(() => {
    setStrategyDialogOpen(true);
  }, []);

  const handlePerformCreateStrategy = useCallback((payload: StrategyPayload) => {
    // An RSS/social strategy's items come from its sources — sending the
    // selected keywords would seed keyword items that also consume the weekly
    // backpressure window. Sources only.
    const src = (payload as any).sourceMode;
    if (src === 'rss' || src === 'social') {
      createStrategyMutation.mutate({ ...payload, keywords: [], keywordMeta: [] });
      return;
    }
    // Table selection first, then anything typed into the dialog (already trimmed,
    // primary-first and de-duped against the selection by the dialog itself).
    const manual = ((payload as any).manualKeywords ?? []) as string[];
    const keywords = [...selectedKeywords.map(k => k.keyword), ...manual];
    // F3: carry the display-only SEO metrics (Ahrefs search volume + keyword
    // difficulty) from the selected Keyword Explorer rows onto the strategy's
    // items. `keywords` stays unchanged for backward compat; keywordMeta is
    // additive — one entry per selected row, keyed by keyword on the backend.
    const keywordMeta = selectedKeywords.map(k => ({
      keyword: k.keyword,
      volume: k.volume,
      difficulty: k.difficulty,
    }));
    createStrategyMutation.mutate({
      ...payload,
      keywords,
      keywordMeta,
    });
  }, [selectedKeywords, createStrategyMutation]);

  // ── Send to Writer ──
  const [writerDialogOpen, setWriterDialogOpen] = useState(false);
  const { navigateToWriterWithKeywords } = useApp();

  const handleOpenSendToWriter = useCallback(() => {
    if (selectedKeywords.length === 0) {
      toast.error('No keywords selected');
      return;
    }
    setWriterDialogOpen(true);
  }, [selectedKeywords.length]);

  const handleSendToWriter = useCallback((payload: Omit<PendingWriterData, 'sourceModule'>) => {
    navigateToWriterWithKeywords(payload);
    setRowSelection({});
    toast.success('Keywords sent to Writer!');
  }, [navigateToWriterWithKeywords]);

  /** Load a saved list by ID — populates table from snapshot */
  const handleLoadList = useCallback(async (listId: string) => {
    try {
      const result: any = await loadListMutation.mutateAsync({ id: listId });
      if (result?.data) {
        setGroupedResults(result.data);
        setQuery(result.seed ?? '');
        if (result.lang) setLang(result.lang);
        setRowSelection({});
        toast.success(`Loaded "${result.name}"`);
      }
    } catch (err: any) {
      toast.error(err.message ?? 'Failed to load list');
    }
  }, [loadListMutation]);

  /** Delete a saved list — no confirmation dialog (window.confirm blocked in portal) */
  const handleDeleteList = useCallback((listId: string) => {
    deleteListMutation.mutate({ id: listId });
  }, [deleteListMutation]);

  // ── Expandable row support — rows with serpResults can expand ──
  const getRowCanExpand = useCallback(
    (row: Row<KeywordResult>) => (row.original.serpResults?.length ?? 0) > 0,
    [],
  );

  const renderSubComponent = useCallback(
    ({ row }: { row: Row<KeywordResult> }) => <SerpSubRow row={row} />,
    [],
  );

  // ── Category names sorted by count ──
  const categoryNames = useMemo(
    () => Object.keys(groupedResults).sort((a, b) => (groupedResults[b]?.length ?? 0) - (groupedResults[a]?.length ?? 0)),
    [groupedResults],
  );

  const hasResults = totalCount > 0;

  // Default name for lists
  const now = new Date();
  const datePart = now.toISOString().slice(0, 10);
  const timePart = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  const defaultSaveName = `${query || 'Keywords'} — ${datePart} ${timePart}`;

  return (
    <div className="h-full flex flex-col">

      {/* Module Header */}
      <div className="flex items-center gap-2 mb-4 shrink-0">
        <Search className="w-5 h-5" style={{ color: colors.primary }} />
        <h1 className="text-lg font-bold" style={{ color: colors.text }}>
          Keyword Explorer
        </h1>
        {hasResults && (
          <Badge variant="secondary" className="ml-2">
            {totalCount} results
          </Badge>
        )}
        {/* The source-agnostic "New Strategy" button MOVED to the Strategies page
            (2026-08-08, owner's call). Creating an RSS/Social strategy involves no
            keyword at all, so requiring a trip to the Keyword Explorer was backwards.
            What stays here is the bulk bar's "Create Strategy", which seeds a strategy
            FROM the rows you selected — that one is genuinely keyword work. */}
      </div>

      {/* Search Bar */}
      <div className="flex items-center gap-2 mb-4 shrink-0">
        <div className="relative flex-1 max-w-lg">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
          <Input
            variant="surface"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            onKeyDown={handleKeyDown}
            placeholder="Enter a seed keyword..."
            className="pl-9"
          />
        </div>

        {/* Language selector */}
        <Select value={lang} onValueChange={setLang}>
          <SelectTrigger variant="surface" className="w-[160px]">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {LANGUAGES.map((l) => (
              <SelectItem key={l.value} value={l.value}>
                {l.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        {/* Modifier selector — Standardized DropdownMenu matching Select design */}
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <button
              type="button"
              disabled={isSearching}
              className="border-input data-[placeholder]:text-muted-foreground flex w-[200px] items-center justify-between gap-2 rounded-md border bg-card px-3 py-2 text-sm whitespace-nowrap shadow-xs transition-[color,box-shadow] outline-none h-9 disabled:cursor-not-allowed disabled:opacity-50"
            >
              <span className="truncate">
                {selectedModifierSet.size === allPrefixes.length
                  ? `All modifiers (${allPrefixes.length})`
                  : `${selectedModifierSet.size} of ${allPrefixes.length} modifiers`}
              </span>
              <ChevronDown className="size-4 opacity-50 shrink-0" />
            </button>
          </DropdownMenuTrigger>
          <DropdownMenuContent className="w-[220px]" align="start">
            {/* Modifier mode toggle — prefix / suffix / both */}
            <div className="flex items-center px-1 pb-1 mb-1 border-b gap-0.5">
              {(['prefix', 'suffix', 'both'] as const).map((mode) => (
                <button
                  key={mode}
                  type="button"
                  onClick={() => setModifierMode(mode)}
                  className={`flex-1 text-xs rounded px-2 py-1 capitalize transition-colors ${
                    modifierMode === mode
                      ? 'bg-primary text-primary-foreground font-medium'
                      : 'hover:bg-accent text-muted-foreground'
                  }`}
                >
                  {mode}
                </button>
              ))}
            </div>

            {/* Select All toggle */}
            <DropdownMenuCheckboxItem
              checked={selectedModifierSet.size === allPrefixes.length}
              onSelect={(e) => {
                e.preventDefault(); // Prevent close
                toggleAllModifiers();
              }}
              className="font-medium"
            >
              Select all
            </DropdownMenuCheckboxItem>
            
            <DropdownMenuSeparator />

            {/* Scrollable modifier list */}
            <div className="max-h-[240px] overflow-y-auto">
              {allPrefixes.map((mod) => (
                <DropdownMenuCheckboxItem
                  key={mod}
                  checked={selectedModifierSet.has(mod)}
                  onSelect={(e) => {
                    e.preventDefault(); // Prevent close
                    toggleModifier(mod);
                  }}
                  className="capitalize"
                >
                  {mod}
                </DropdownMenuCheckboxItem>
              ))}
            </div>
          </DropdownMenuContent>
        </DropdownMenu>

        {/* Search / Cancel button */}
        {isSearching ? (
          <Button variant="destructive" onClick={handleCancel}>
            <X className="w-4 h-4" />
            Cancel
          </Button>
        ) : (
          <Button onClick={handleSearch} disabled={!query.trim()}>
            <Search className="w-4 h-4" />
            Generate Keywords
          </Button>
        )}

          {/* Spacer pushes Saved Lists to the right */}
          <div className="flex-1" />

          {/* Saved Lists dropdown — right-aligned */}
          <Popover>
          <PopoverTrigger asChild>
            <button
              type="button"
              className="border-input flex items-center justify-between gap-2 rounded-md border bg-card px-3 py-2 text-sm whitespace-nowrap shadow-xs transition-[color,box-shadow] outline-none h-9"
            >
              <FolderOpen className="w-4 h-4 text-muted-foreground" />
              <span className="truncate">
                Saved Lists{savedLists.length > 0 ? ` (${savedLists.length})` : ''}
              </span>
              <ChevronDown className="size-4 opacity-50 shrink-0" />
            </button>
          </PopoverTrigger>
          <PopoverContent className="w-[280px] p-0" align="end">
            {savedLists.length === 0 ? (
              <div className="px-3 py-4 text-sm text-muted-foreground text-center">
                No saved lists yet
              </div>
            ) : (
              <div className="max-h-[300px] overflow-y-auto p-1">
                {savedLists.map((list: any) => (
                  <div
                    key={list.id}
                    className="flex items-center gap-2 rounded-sm px-2 py-1.5 text-sm hover:bg-accent transition-colors"
                  >
                    <button
                      type="button"
                      onClick={() => handleLoadList(list.id)}
                      className="flex-1 text-left truncate cursor-pointer"
                    >
                      <div className="font-medium truncate">{list.name}</div>
                      <div className="text-xs text-muted-foreground">
                        {list.totalCount} keywords • {new Date(list.createdAt).toLocaleDateString()}
                      </div>
                    </button>
                    <button
                      type="button"
                      onClick={() => handleDeleteList(list.id)}
                      className="shrink-0 p-1 rounded hover:bg-destructive/10 text-muted-foreground hover:text-destructive transition-colors"
                      title="Delete list"
                    >
                      <Trash2 className="w-3.5 h-3.5" />
                    </button>
                  </div>
                ))}
              </div>
            )}
            </PopoverContent>
          </Popover>
        </div>

      {/* Status bar with progress */}
      {statusMessage && (
        <div className="mb-3 shrink-0">
          <div
            className="px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2"
            style={{
              backgroundColor: isSearching ? 'hsl(var(--accent))' : 'hsl(var(--muted))',
              color: isSearching ? 'hsl(var(--accent-foreground))' : 'hsl(var(--muted-foreground))',
            }}
          >
            {isSearching && <Spinner className="w-3.5 h-3.5" />}
            {statusMessage}
          </div>
          {/* Progress bar */}
          {isSearching && progress.total > 0 && (
            <div className="mt-1 h-1 rounded-full bg-muted overflow-hidden">
              <div
                className="h-full bg-primary transition-all duration-300 ease-out rounded-full"
                style={{ width: `${(progress.current / progress.total) * 100}%` }}
              />
            </div>
          )}
        </div>
      )}

      {/* Results Area: Sidebar + Table */}
      <div className="flex-1 overflow-hidden min-h-0 flex">
        {hasResults ? (
          <>
            {/* ── Left sidebar: Category groups ── */}
          <div className="w-48 border rounded-l-lg bg-muted/30 overflow-y-auto shrink-0">
              {/* All Results */}
              <button
                onClick={() => { setActiveCategory('all'); setRowSelection({}); }}
                className={`w-full px-4 py-3 text-sm flex justify-between items-center border-b transition-colors text-left
                  ${activeCategory === 'all'
                    ? 'bg-primary text-primary-foreground font-medium'
                    : 'text-muted-foreground hover:bg-muted'
                  }`}
              >
                <span>All Results</span>
                <span className="text-xs opacity-70 px-1.5 py-0.5 rounded-full bg-white/20 tabular-nums">
                  {totalCount}
                </span>
              </button>

              {/* Per-prefix groups */}
              {categoryNames.map((cat) => (
                <button
                  key={cat}
                  onClick={() => { setActiveCategory(cat); setRowSelection({}); }}
                  className={`w-full px-4 py-2.5 text-sm flex justify-between items-center border-b transition-colors text-left
                    ${activeCategory === cat
                      ? 'bg-primary text-primary-foreground font-medium'
                      : 'text-muted-foreground hover:bg-muted'
                    }`}
                >
                  <span className="capitalize truncate mr-2">{cat}</span>
                  <span className="text-xs opacity-60 tabular-nums">
                    {groupedResults[cat]?.length ?? 0}
                  </span>
                </button>
              ))}
            </div>

            {/* ── Right: Data Table ── */}
            <div className="flex-1 overflow-auto min-w-0">
              <DataTable
                columns={columns}
                data={displayedResults}
                rowSelection={rowSelection}
                onRowSelectionChange={setRowSelection}
                renderSubComponent={renderSubComponent}
                getRowCanExpand={getRowCanExpand}
                tableMeta={{
                  isEnriching: enrichMutation.isPending,
                  enrichingKeywords: enrichingKeywordsRef.current,
                }}
                renderToolbar={(table) => (
                  <div className="flex justify-end gap-2 w-full">
                    <DataTableSerpFilter blacklist={serpBlacklist} setBlacklist={setSerpBlacklist} />
                    <DataTableViewOptions table={table} />
                  </div>
                )}
              />
            </div>
          </>
        ) : (
          <div className="flex-1 flex items-center justify-center">
            <div className="text-center">
              <Search className="w-12 h-12 mx-auto mb-3 text-muted-foreground/30" />
              <p className="text-sm text-muted-foreground">
                Enter a keyword to discover suggestions
              </p>
              <p className="mt-1" style={{ fontSize: typography.micro, color: colors.textSecondary }}>
                Searches multiple prefix variations via Google Autocomplete
              </p>
            </div>
          </div>
        )}
      </div>

      {/* Bulk Action Bar */}
      <BulkActionBar count={selectedCount} onClear={() => setRowSelection({})}>
        <BulkActionBar.Action icon={Copy} label="Copy" onClick={handleCopy} />
        <BulkActionBar.Action
          icon={BarChart3}
          label="Fetch Volume"
          onClick={handleEnrich}
          loading={enrichMutation.isPending}
        />
        {/* SERP DR opt-in toggle */}
        <label className="flex items-center gap-1.5 text-xs text-muted-foreground cursor-pointer select-none ml-1">
          <Checkbox
            checked={includeSerpDR}
            onCheckedChange={(checked) => setIncludeSerpDR(!!checked)}
          />
          Include SERP DR
        </label>
        {/* Save List */}
        <BulkActionBar.Action icon={Save} label="Save List" onClick={handleOpenSaveList} />
        {/* Create Strategy */}
        <BulkActionBar.Action icon={Zap} label="Create Strategy" onClick={handleOpenStrategy} className="bg-primary/10 text-primary hover:bg-primary/20 hover:text-primary transition-colors" />
        {/* Send to Writer */}
        <BulkActionBar.Action icon={PenLine} label="Send to Writer" onClick={handleOpenSendToWriter} className="bg-emerald-500/10 text-emerald-600 hover:bg-emerald-500/20 hover:text-emerald-700 transition-colors" />
      </BulkActionBar>
      {/* Modals */}
      <SaveListDialog
        open={saveModalOpen}
        onOpenChange={setSaveModalOpen}
        defaultName={defaultSaveName}
        onSave={handlePerformSaveList}
        isSaving={saveListMutation.isPending}
      />
      <CreateStrategyDialog
        open={strategyDialogOpen}
        onOpenChange={setStrategyDialogOpen}
        defaultName={selectedCount > 0 ? `${selectedCount} keywords - Strategy` : 'New Strategy'}
        onSave={handlePerformCreateStrategy}
        isSaving={createStrategyMutation.isPending}
        selectedCount={selectedCount}
        selectedKeywords={selectedKeywords.map(k => k.keyword)}
      />
      <SendToWriterDialog
        open={writerDialogOpen}
        onOpenChange={setWriterDialogOpen}
        selectedKeywords={selectedKeywords.map(k => k.keyword)}
        onSend={handleSendToWriter}
      />
    </div>
  );
}
