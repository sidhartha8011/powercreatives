/**
 * STRATEGIES MODULE — Strategy List & Management
 *
 * Displays all content strategies with real-time status.
 * Allows creating, viewing, and managing strategy items.
 * Integrates with the Keywords module for strategy creation
 * and the Writer module for article viewing.
 *
 * Data source: trpc.strategy.list / trpc.strategy.get
 */

import { useState, useCallback, useMemo, useRef, useEffect } from 'react';
import {
  Layers, ChevronRight, ChevronDown, Play, Pause, Trash2, Zap, RefreshCw,
  CheckCircle2, Clock, AlertCircle, Loader2, FileText, ExternalLink,
  Link2, Send, Crown, X, List, CalendarClock, Copy, Settings2, SlidersHorizontal,
} from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { colors, typography, shadows, statusColors } from '@/components/shared/design-tokens';
import { trpc } from '@/lib/trpc';
import { useApp } from '@/contexts/AppContext';
import { InterlinkManagerModal } from './InterlinkManagerModal';
import { ScheduleView } from './ScheduleView';
import { ParentSettingsModal } from './ParentSettingsModal';
import { RecurrenceEditor, recurrenceFromConfig, recurrenceToConfig, type ScheduleRecurrence } from './RecurrenceEditor';

// ── Types ──
interface StrategyItem {
  id: number;
  keyword: string;
  title?: string;
  slug?: string;
  status: string;
  articleId?: number;
  position: number;
  errorMessage?: string;
  /** Where the linked article actually published, if it has (backend LEFT JOIN). */
  articlePublishedUrl?: string;
  /** Due date for schedule-mode strategies (Y-m-d H:i:s), null otherwise. */
  scheduledDate?: string;
  /** JSON string — per-item overrides (templateId/publishingMode/approvalMode).
   *  Absent keys inherit the strategy's own value. Parse with parseStrategyConfig(). */
  config?: string;
  /** Display-only SEO metrics carried from the Keyword Explorer (F3). Ahrefs
   *  monthly search volume + keyword difficulty; null when never enriched. */
  volume?: number;
  difficulty?: number;
}

/** Item-level publishingMode override options (plus 'inherit' — no override key at all). */
const ITEM_MODE_LABELS: Record<string, string> = {
  inherit: 'Inherit',
  draft: 'Draft',
  publish: 'Auto-publish',
  schedule: 'Scheduled',
};

/** Item-level approvalMode override options (plus 'inherit'). */
const ITEM_APPROVAL_LABELS: Record<string, string> = {
  inherit: 'Inherit',
  none: 'None',
  internal: 'Internal',
  client: 'Client',
  both: 'Internal + Client',
};

interface Strategy {
  id: number;
  name: string;
  status: string;
  brandId?: number;
  templateId?: number;
  hierarchyMode: string;
  publishingMode: string;
  totalItems: number;
  completedItems: number;
  failedItems: number;
  createdAt: string;
  updatedAt: string;
  items?: StrategyItem[];
  /** JSON string — target site (siteId) + generation options. Parse with parseStrategyConfig(). */
  config?: string;
}

/** Safely parse a strategy's stored config JSON (malformed/absent → {}). */
function parseStrategyConfig(raw?: string): Record<string, any> {
  if (!raw) return {};
  try {
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch {
    return {};
  }
}

const PUBLISHING_MODE_LABELS: Record<string, string> = {
  draft: 'Draft',
  publish: 'Auto-publish',
  schedule: 'Scheduled',
};

/** 3-letter, Mon-first day names — index by ISO 1(Mon)–7(Sun). */
const DAY_SHORT_NAMES = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/** Compact, truncatable summary of a ScheduleRecurrence for the row button label. */
function summarizeRecurrence(r: ScheduleRecurrence): string {
  let base: string;
  if (r.unit === 'day') base = r.interval === 1 ? 'Daily' : `Every ${r.interval} days`;
  else if (r.unit === 'week') base = r.interval === 1 ? 'Weekly' : `Every ${r.interval} weeks`;
  else base = r.interval === 1 ? 'Monthly' : `Every ${r.interval} months`;

  const parts = [base];
  if (r.byDays.length > 0) {
    parts.push(r.byDays.map((iso) => DAY_SHORT_NAMES[iso - 1]).join(', '));
  }
  if (r.ends.type === 'on') parts.push(`ends ${r.ends.date}`);
  else if (r.ends.type === 'after') parts.push(`×${r.ends.count}`);
  return parts.join(' · ');
}

// ── Status indicator — reusable across Strategies + Approvals ──
function StatusBadge({ status }: { status: string }) {
  const config: Record<string, { icon: React.ReactNode; label: string; color: string; bg: string }> = {
    pending:      { icon: <Clock className="w-3 h-3" />, label: 'Pending', color: statusColors.draft.text, bg: statusColors.draft.bg },
    in_progress:  { icon: <Loader2 className="w-3 h-3 animate-spin" />, label: 'In Progress', color: colors.primary, bg: colors.primaryLight },
    completed:    { icon: <CheckCircle2 className="w-3 h-3" />, label: 'Completed', color: statusColors.ready.text, bg: statusColors.ready.bg },
    paused:       { icon: <Pause className="w-3 h-3" />, label: 'Paused', color: colors.textSecondary, bg: colors.bgHover },
    error:        { icon: <AlertCircle className="w-3 h-3" />, label: 'Error', color: colors.danger, bg: colors.dangerLight },
    generating:   { icon: <Loader2 className="w-3 h-3 animate-spin" />, label: 'Generating...', color: colors.accent, bg: colors.accentLight },
  };

  const c = config[status] ?? config.pending;

  return (
    <span
      className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full"
      style={{ fontSize: typography.xs, fontWeight: typography.medium, color: c.color, backgroundColor: c.bg }}
    >
      {c.icon}
      {c.label}
    </span>
  );
}

// ── Main Component ──
export function StrategiesModule() {
  const { navigateToWriterArticle } = useApp();
  // Header toggle between the strategy list and the cross-strategy master
  // Content Schedule. The header/toolbar stays put; only the body swaps.
  const [view, setView] = useState<'list' | 'schedule'>('list');
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [sortBy, setSortBy] = useState('newest');
  const [expandedId, setExpandedId] = useState<number | null>(null);

  // Deep-link expand — e.g. a notification/email link to ?strategy=123 opens
  // straight to that strategy's items. Read once on mount; never pushed back
  // to the URL (this isn't a controlled route param).
  useEffect(() => {
    const raw = new URLSearchParams(window.location.search).get('strategy');
    const id = raw ? parseInt(raw, 10) : NaN;
    if (!Number.isNaN(id)) setExpandedId(id);
  }, []);
  const [generatingItemId, setGeneratingItemId] = useState<number | null>(null);
  const [bulkStrategyId, setBulkStrategyId] = useState<number | null>(null);
  const [retryItemId, setRetryItemId] = useState<number | null>(null);
  // Bulk selection (AutoPress parity) — a Set of selected strategy ids drives the
  // floating action bar; bulkBusy gates the bar's buttons during a batch run.
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
  const [bulkBusy, setBulkBusy] = useState(false);

  const toggleSelected = useCallback((id: number) => {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }, []);
  const clearSelection = useCallback(() => setSelectedIds(new Set()), []);
  // During a "Generate All" run, per-item toasts/refetch are suppressed so the loop
  // isn't noisy — a single summary toast + one refetch fire at the end instead.
  const bulkModeRef = useRef(false);

  // Data fetching via tRPC proxy (uses established apiFetch + react-query).
  // While anything is actively generating (here or in the background wp-cron
  // queue), the list re-polls so background completions appear on their own —
  // without this, cron-generated articles only show up on a manual refresh,
  // which reads as "it generated by itself".
  const { data: strategies = [], isLoading, refetch } = trpc.strategy.list.useQuery(undefined, {
    refetchInterval: (query: any) => {
      const list = query?.state?.data;
      const active = Array.isArray(list) && list.some((s: any) =>
        s?.status === 'in_progress'
        || (Array.isArray(s?.items) && s.items.some((it: any) => it?.status === 'generating')));
      return active ? 5000 : false;
    },
  }) as any;
  const { data: sitesRaw } = trpc.sites.list.useQuery();
  const sites = useMemo(() => (Array.isArray(sitesRaw) ? sitesRaw : []), [sitesRaw]) as any[];
  const { data: templatesRaw } = trpc.templates.list.useQuery({ module: 'writer' });
  const templates = useMemo(() => (Array.isArray(templatesRaw) ? templatesRaw : []), [templatesRaw]) as any[];

  // Mutations
  const updateStrategyMutation = trpc.strategy.update.useMutation({
    onSuccess: () => { toast.success('Strategy updated'); refetch(); },
    onError: (err: any) => toast.error(err.message ?? 'Failed to update strategy'),
  }) as any;
  const publishItemMutation = trpc.strategy.publishItem.useMutation({
    onSuccess: (data: any) => {
      toast.success(data?.message ?? 'Published to site');
      refetch();
    },
    onError: (err: any) => toast.error(err.message ?? 'Publish failed'),
  }) as any;
  const generateMutation = trpc.strategy.generate.useMutation({
    onSuccess: (_data: any) => {
      // Surfaced regardless of bulk mode — the draft still saved, but a publish
      // failure to the external site is a distinct, actionable outcome that a
      // bulk-run's "N generated" summary wouldn't otherwise mention.
      if (_data?.publish?.success === false) {
        toast.error(_data.publish.message ?? 'Auto-publish failed for a generated article.');
      }
      if (bulkModeRef.current) return; // bulk run reports its own generation summary
      if (_data?.complete) {
        toast.success('All items have been generated!');
      } else {
        toast.success('Article generated successfully');
      }
      refetch();
    },
    onError: (err: any) => {
      if (bulkModeRef.current) return;
      // A gateway timeout on a slow LLM call aborts the REQUEST, not the
      // server-side generation — and the background queue keeps draining
      // pending items either way. Say so, or a timeout reads as total failure
      // right before articles "mysteriously" appear.
      toast.error(
        (err.message ?? 'Generation failed')
        + ' — generation may still be running in the background; this list refreshes automatically.'
      );
    },
    onSettled: () => setGeneratingItemId(null),
  }) as any;

  const deleteMutation = trpc.strategy.delete.useMutation({
    onSuccess: () => { toast.success('Strategy deleted'); refetch(); },
    onError: (err: any) => toast.error(err.message ?? 'Failed to delete'),
  }) as any;

  // Duplicate a strategy (Task E1) — the copy is created with fresh pending items
  // and does NOT auto-start generation (backend deviation from create's auto-arm).
  const duplicateMutation = trpc.strategy.duplicate.useMutation({
    onSuccess: () => { toast.success('Strategy duplicated'); refetch(); },
    onError: (err: any) => toast.error(err.message ?? 'Failed to duplicate strategy'),
  }) as any;
  const handleDuplicate = useCallback((strategyId: number) => {
    duplicateMutation.mutate({ id: strategyId });
  }, [duplicateMutation]);

  // Pull published items' status from WordPress (Task D3) — reconciles our
  // stored record when a post was deleted/unpublished directly on the site.
  const syncStatusMutation = trpc.strategy.syncStatus.useMutation({
    onSuccess: (data: any) => {
      const checked = data?.checked ?? 0;
      const updated = data?.updated ?? 0;
      const deleted = data?.deleted ?? 0;
      toast.success(`Checked ${checked}, updated ${updated}, cleared ${deleted}`);
      refetch();
    },
    onError: (err: any) => toast.error(err.message ?? 'Sync failed'),
  }) as any;
  const handleSyncStatus = useCallback((strategyId: number) => {
    syncStatusMutation.mutate({ id: strategyId });
  }, [syncStatusMutation]);

  // Pause / resume generation (Task D2) — a status flip through the existing
  // PATCH /strategies/{id} whitelist. Paused strategies are skipped by the
  // background queue + daily scheduled scan; resuming re-opens them.
  const handleTogglePause = useCallback((strategyId: number, currentStatus: string) => {
    const next = currentStatus === 'paused' ? 'in_progress' : 'paused';
    updateStrategyMutation.mutate({ id: strategyId, status: next });
  }, [updateStrategyMutation]);

  // Expand/collapse to fetch items for a strategy
  const toggleExpand = useCallback(async (strategyId: number) => {
    if (expandedId === strategyId) {
      setExpandedId(null);
      return;
    }
    setExpandedId(strategyId);
    // Items ship with the list response (list_strategies attaches them), so
    // expanding just reveals strategy.items — no per-strategy fetch needed.
  }, [expandedId]);

  // Trigger generation for next pending item
  const handleGenerate = useCallback((strategyId: number) => {
    setGeneratingItemId(strategyId);
    generateMutation.mutate({ id: strategyId });
  }, [generateMutation]);

  // Generate EVERY remaining pending item, one at a time. Each call advances the
  // next pending item (completed → or error → both reduce the pending set), so the
  // loop terminates; the totalItems+1 cap is a belt-and-braces runaway guard.
  const handleGenerateAll = useCallback(async (strategy: Strategy) => {
    setBulkStrategyId(strategy.id);
    bulkModeRef.current = true;
    let ok = 0;
    let fail = 0;
    try {
      for (let i = 0; i < Number(strategy.totalItems) + 1; i++) {
        try {
          const res: any = await generateMutation.mutateAsync({ id: strategy.id });
          if (res?.complete) break;
          ok++;
        } catch {
          fail++; // item is marked 'error' server-side; continue to the next pending
        }
      }
    } finally {
      bulkModeRef.current = false;
      setBulkStrategyId(null);
      refetch();
      if (fail > 0) {
        toast.error(`Generated ${ok}, ${fail} failed — retry the failed item(s).`);
      } else {
        toast.success(ok > 0 ? `Generated ${ok} article${ok === 1 ? '' : 's'}` : 'Nothing left to generate');
      }
    }
  }, [generateMutation, refetch]);

  // Retry / regenerate a single item (e.g. one that errored).
  const handleRetry = useCallback(async (strategyId: number, itemId: number) => {
    setRetryItemId(itemId);
    try {
      await generateMutation.mutateAsync({ id: strategyId, itemId });
    } catch {
      // error toast already surfaced by the mutation's onError
    } finally {
      setRetryItemId(null);
    }
  }, [generateMutation]);

  // Delete strategy — confirmed first (AutoPress parity: it uses a confirm
  // modal; one accidental click here would otherwise drop the whole queue).
  const handleDelete = useCallback((strategyId: number, name: string) => {
    if (!window.confirm(`Delete strategy "${name}" and all its items? Generated articles stay in Writer.`)) return;
    deleteMutation.mutate({ id: strategyId });
  }, [deleteMutation]);

  // Change a strategy's target site — a PARTIAL config update (backend merges
  // this onto the existing config rather than replacing it, so approvalMode/
  // scheduleConfig/interlinksConfig/model survive untouched).
  const handleSiteChange = useCallback((strategyId: number, siteId: string) => {
    updateStrategyMutation.mutate({
      id: strategyId,
      config: { siteId: siteId ? parseInt(siteId, 10) : 0 },
    });
  }, [updateStrategyMutation]);

  // Change publishing mode inline (draft / publish / schedule) — top-level
  // whitelisted field on PATCH /strategies/{id}.
  const handlePublishingModeChange = useCallback((strategyId: number, mode: string) => {
    updateStrategyMutation.mutate({ id: strategyId, publishingMode: mode });
  }, [updateStrategyMutation]);

  // Inline approval-mode change — a partial config merge, like the site select.
  const handleApprovalChange = useCallback((strategyId: number, mode: string) => {
    updateStrategyMutation.mutate({ id: strategyId, config: { approvalMode: mode } });
  }, [updateStrategyMutation]);

  // Inline template (prompt) change — top-level whitelisted field.
  const handleTemplateChange = useCallback((strategyId: number, templateId: string) => {
    updateStrategyMutation.mutate({ id: strategyId, templateId: parseInt(templateId, 10) });
  }, [updateStrategyMutation]);

  // Recurrence dialog (replaces the old bare frequency Select) — scheduleConfig
  // is replaced WHOLE inside the config merge (the merge is shallow), so the
  // existing startDate must be carried along — the backend then redistributes
  // the PENDING items' due dates from the new recurrence.
  const [recurrenceDialog, setRecurrenceDialog] = useState<{ strategyId: number; value: ScheduleRecurrence } | null>(null);
  const handleRecurrenceSave = useCallback((strategyId: number, r: ScheduleRecurrence, existingStartDate: string) => {
    updateStrategyMutation.mutate({
      id: strategyId,
      config: { scheduleConfig: { ...recurrenceToConfig(r), startDate: existingStartDate || '' } },
    });
    setRecurrenceDialog(null);
  }, [updateStrategyMutation]);

  // Per-item due-date edit (pending items on schedule-mode strategies).
  const updateItemMutation = trpc.strategy.updateItem.useMutation({
    onSuccess: () => { toast.success('Due date updated'); refetch(); },
    onError: (err: any) => toast.error(err.message ?? 'Failed to update due date'),
  }) as any;
  const handleItemDateChange = useCallback((strategyId: number, itemId: number, date: string) => {
    if (!date) return;
    updateItemMutation.mutate({ id: strategyId, itemId, scheduledDate: date });
  }, [updateItemMutation]);

  // Per-item overrides row (Task F2) — templateId/publishingMode/approvalMode,
  // toggled open per item (any number of rows can be open at once).
  const [openOverridesItemId, setOpenOverridesItemId] = useState<number | null>(null);
  const itemOverridesMutation = trpc.strategy.updateItem.useMutation({
    onSuccess: () => { refetch(); },
    onError: (err: any) => toast.error(err.message ?? 'Failed to update item overrides'),
  }) as any;
  // Sends the FULL desired override set each time (only non-"Inherit" keys
  // present) — the backend's set_item_config() REPLACES (not merges) an
  // item's stored config, so this is the correct, simplest way to let a
  // Select going back to "Inherit" actually clear that key.
  const handleItemOverrideChange = useCallback((
    strategyId: number,
    itemId: number,
    currentConfig: Record<string, any>,
    key: 'templateId' | 'publishingMode' | 'approvalMode',
    value: string,
  ) => {
    const next = { ...currentConfig };
    if (value === 'inherit') {
      delete next[key];
    } else if (key === 'templateId') {
      next[key] = Number(value);
    } else {
      next[key] = value;
    }
    itemOverridesMutation.mutate({ id: strategyId, itemId, config: next });
  }, [itemOverridesMutation]);

  // Per-item delete (the article, if any, stays in Writer).
  const deleteItemMutation = trpc.strategy.deleteItem.useMutation({
    onSuccess: () => { toast.success('Item removed'); refetch(); },
    onError: (err: any) => toast.error(err.message ?? 'Failed to remove item'),
  }) as any;
  const handleDeleteItem = useCallback((strategyId: number, itemId: number, keyword: string) => {
    if (!window.confirm(`Remove "${keyword}" from this strategy? Its generated article (if any) stays in Writer.`)) return;
    deleteItemMutation.mutate({ id: strategyId, itemId });
  }, [deleteItemMutation]);

  // Manually publish one completed item's article to the strategy's site —
  // the retro-publish path for items generated in draft mode (or before a
  // site was set).
  const [publishingItemId, setPublishingItemId] = useState<number | null>(null);
  const handlePublishItem = useCallback(async (strategyId: number, itemId: number) => {
    setPublishingItemId(itemId);
    try {
      await publishItemMutation.mutateAsync({ id: strategyId, itemId });
    } catch {
      // toast handled by the mutation's onError
    } finally {
      setPublishingItemId(null);
    }
  }, [publishItemMutation]);

  // Interlink injection is configured & run in a modal (auto/manual modes).
  // The button below opens it; the modal owns the injectInterlinks mutation and
  // calls back onDone → refetch so completions land in the list.
  const [interlinkModalStrategy, setInterlinkModalStrategy] = useState<{ id: number; name: string; interlinksConfig?: any } | null>(null);

  // Parent-settings editor (Task G1) — edits hierarchyMode + parent-link/anchor
  // config for one strategy in a modal (opened from the row's Settings button).
  const [parentSettingsStrategy, setParentSettingsStrategy] = useState<Strategy | null>(null);

  // Cast to typed array (trpc proxy returns unknown)
  const strategyList: Strategy[] = Array.isArray(strategies) ? strategies : [];

  // Client-side search + status filter (AutoPress parity) — name match (also
  // checked against item keywords when items are already attached) plus an
  // exact status match; both are optional and combine with AND.
  // NOTE: this useMemo must stay ABOVE the isLoading early return — a hook
  // after a conditional return changes the hook count between renders and
  // crashes with React error #310 the moment loading flips to false.
  const visibleList = useMemo(() => {
    const q = search.trim().toLowerCase();
    const filtered = strategyList.filter((strategy) => {
      if (statusFilter !== 'all' && strategy.status !== statusFilter) return false;
      if (!q) return true;
      const nameMatch = strategy.name?.toLowerCase().includes(q);
      const keywordMatch = Array.isArray(strategy.items)
        && strategy.items.some((it) => it.keyword?.toLowerCase().includes(q));
      return nameMatch || keywordMatch;
    });
    // Sort a COPY — filtered is a fresh array from .filter() already, but stay
    // explicit so a future refactor to .some()-based filtering can't mutate strategyList.
    const sorted = [...filtered];
    if (sortBy === 'name') {
      sorted.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
    } else if (sortBy === 'status') {
      sorted.sort((a, b) => (a.status || '').localeCompare(b.status || ''));
    }
    // 'newest' (default) — current order (createdAt desc, as returned by the API).
    return sorted;
  }, [strategyList, search, statusFilter, sortBy]);

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-full">
        <Spinner className="w-6 h-6" />
      </div>
    );
  }

  // "Select all visible" — checked = every visible row selected. Toggling clears
  // the visible ids when already all-selected, otherwise adds them (leaving any
  // off-screen/ filtered selections untouched).
  const allVisibleSelected = visibleList.length > 0 && visibleList.every((s) => selectedIds.has(s.id));
  const toggleSelectAllVisible = () => {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (visibleList.length > 0 && visibleList.every((s) => prev.has(s.id))) {
        visibleList.forEach((s) => next.delete(s.id));
      } else {
        visibleList.forEach((s) => next.add(s.id));
      }
      return next;
    });
  };

  // Bulk actions operate only on selected ids still present in strategyList
  // (deleted/filtered-away selections are ignored, not errored on). One bulkBusy
  // flag disables the whole bar while any batch is in flight.
  const handleBulkGenerate = async () => {
    const targets = strategyList.filter((s) => selectedIds.has(s.id) && s.status !== 'completed');
    setBulkBusy(true);
    let done = 0;
    try {
      for (const s of targets) {
        await handleGenerateAll(s); // reuse per-strategy serial flow; iterate serially across strategies too
        done++;
      }
    } finally {
      setBulkBusy(false);
      toast.success(done > 0 ? `Generated all for ${done} strateg${done === 1 ? 'y' : 'ies'}` : 'Nothing to generate');
    }
  };

  const handleBulkPublish = async () => {
    const targets = strategyList.filter((s) => selectedIds.has(s.id));
    setBulkBusy(true);
    let published = 0;
    let failed = 0;
    try {
      for (const s of targets) {
        const cfg = parseStrategyConfig(s.config);
        if (!cfg.siteId) continue; // skip strategies without a configured site
        const items = Array.isArray(s.items) ? s.items : [];
        for (const it of items) {
          if (it.status === 'completed' && it.articleId && !it.articlePublishedUrl) {
            try {
              await publishItemMutation.mutateAsync({ id: s.id, itemId: it.id });
              published++;
            } catch {
              failed++;
            }
          }
        }
      }
    } finally {
      setBulkBusy(false);
      if (failed > 0) toast.error(`Published ${published}, ${failed} failed`);
      else toast.success(published > 0 ? `Published ${published} article${published === 1 ? '' : 's'}` : 'Nothing to publish');
    }
  };

  const handleBulkDelete = async () => {
    const targets = strategyList.filter((s) => selectedIds.has(s.id));
    const n = targets.length;
    if (n === 0) return;
    if (!window.confirm(`Delete ${n} strateg${n === 1 ? 'y' : 'ies'} and all their items? Generated articles stay in Writer.`)) return;
    setBulkBusy(true);
    let deleted = 0;
    let failed = 0;
    try {
      for (const s of targets) {
        try {
          await deleteMutation.mutateAsync({ id: s.id });
          deleted++;
        } catch {
          failed++;
        }
      }
    } finally {
      setBulkBusy(false);
      clearSelection();
      if (failed > 0) toast.error(`Deleted ${deleted}, ${failed} failed`);
      else toast.success(`Deleted ${deleted} strateg${deleted === 1 ? 'y' : 'ies'}`);
    }
  };

  return (
    <div className="h-full flex flex-col">
      {/* Header */}
      <div className="flex items-center gap-2 mb-4 shrink-0">
        <Layers className="w-5 h-5" style={{ color: colors.primary }} />
        <h1 style={{ fontSize: typography.title, fontWeight: typography.bold, color: colors.text }}>
          Strategies
        </h1>
        <Badge variant="secondary" className="ml-2">
          {search.trim() || statusFilter !== 'all'
            ? `${visibleList.length} of ${strategyList.length}`
            : `${strategyList.length} ${strategyList.length === 1 ? 'strategy' : 'strategies'}`}
        </Badge>

        {/* List | Schedule view toggle */}
        <div className="flex items-center gap-1 ml-auto rounded-md p-0.5" style={{ border: `1px solid ${colors.border}` }}>
          <Button
            variant={view === 'list' ? 'default' : 'ghost'}
            size="sm"
            className="h-7"
            onClick={() => setView('list')}
          >
            <List className="w-3.5 h-3.5" />
            List
          </Button>
          <Button
            variant={view === 'schedule' ? 'default' : 'ghost'}
            size="sm"
            className="h-7"
            onClick={() => setView('schedule')}
          >
            <CalendarClock className="w-3.5 h-3.5" />
            Schedule
          </Button>
        </div>

        {/* Search + status filter toolbar (AutoPress parity) — list view only */}
        {view === 'list' && (
        <div className="flex items-center gap-2">
          <span className="flex items-center pr-1" title="Select all visible">
            <Checkbox
              checked={allVisibleSelected}
              onCheckedChange={toggleSelectAllVisible}
              aria-label="Select all visible strategies"
            />
          </span>
          <Input
            placeholder="Search strategies…"
            className="h-8 w-56 text-xs bg-background"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
          <Select value={statusFilter} onValueChange={setStatusFilter}>
            <SelectTrigger className="h-8 w-36 text-xs bg-background">
              <SelectValue placeholder="All statuses" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All statuses</SelectItem>
              <SelectItem value="pending">Pending</SelectItem>
              <SelectItem value="in_progress">In Progress</SelectItem>
              <SelectItem value="completed">Completed</SelectItem>
              <SelectItem value="error">Error</SelectItem>
            </SelectContent>
          </Select>
          <Select value={sortBy} onValueChange={setSortBy}>
            <SelectTrigger className="h-8 w-28 text-xs bg-background">
              <SelectValue placeholder="Sort" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="newest">Newest</SelectItem>
              <SelectItem value="name">Name (A–Z)</SelectItem>
              <SelectItem value="status">Status</SelectItem>
            </SelectContent>
          </Select>
        </div>
        )}
      </div>

      {/* Schedule view — cross-strategy master table (replaces the list body;
          the header/toolbar above stays). */}
      {view === 'schedule' ? (
        <ScheduleView />
      ) : strategyList.length === 0 ? (
        <div
          className="flex flex-col items-center justify-center flex-1 rounded-lg"
          style={{ border: `1px dashed ${colors.border}`, background: colors.bgSurface }}
        >
          <Layers className="w-12 h-12 mb-3" style={{ color: colors.textMuted }} />
          <p style={{ color: colors.textSecondary, fontSize: typography.body, fontWeight: typography.medium }}>
            No strategies yet
          </p>
          <p style={{ color: colors.textMuted, fontSize: typography.sm, marginTop: '4px' }}>
            Select keywords in the Keyword Explorer and create a strategy
          </p>
        </div>
      ) : visibleList.length === 0 ? (
        <div
          className="flex items-center justify-center flex-1 rounded-lg"
          style={{ border: `1px dashed ${colors.border}`, background: colors.bgSurface }}
        >
          <p style={{ color: colors.textMuted, fontSize: typography.sm }}>
            No strategies match.
          </p>
        </div>
      ) : (
        /* Strategy list */
        <div className="flex-1 overflow-y-auto space-y-3">
          {visibleList.map((strategy) => {
            const config = parseStrategyConfig(strategy.config);
            const siteIdValue = config.siteId ? String(config.siteId) : '';
            return (
            <div
              key={strategy.id}
              className="rounded-lg overflow-hidden"
              style={{ border: `1px solid ${colors.border}`, background: colors.bgSurface, boxShadow: shadows.card }}
            >
              {/* Strategy header row */}
              <div
                className="flex flex-wrap items-center gap-3 px-4 py-3 cursor-pointer"
                style={{ borderBottom: expandedId === strategy.id ? `1px solid ${colors.borderLight}` : 'none' }}
                onClick={() => toggleExpand(strategy.id)}
              >
                {/* Bulk-select checkbox — leftmost; stops row-toggle propagation */}
                <span className="shrink-0" onClick={(e) => e.stopPropagation()}>
                  <Checkbox
                    checked={selectedIds.has(strategy.id)}
                    onCheckedChange={() => toggleSelected(strategy.id)}
                    aria-label={`Select ${strategy.name}`}
                  />
                </span>

                {/* Expand icon */}
                {expandedId === strategy.id
                  ? <ChevronDown className="w-4 h-4 shrink-0" style={{ color: colors.textMuted }} />
                  : <ChevronRight className="w-4 h-4 shrink-0" style={{ color: colors.textMuted }} />
                }

                {/* Name + meta */}
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2">
                    <span style={{ fontSize: typography.body, fontWeight: typography.semibold, color: colors.text }} className="truncate">
                      {strategy.name}
                    </span>
                    <StatusBadge status={strategy.status} />
                    {config.parentKeyword && (
                      <span title="Has a parent (hub) article" className="shrink-0">
                        <Crown className="w-3.5 h-3.5 shrink-0 text-amber-500" />
                      </span>
                    )}
                    {strategy.hierarchyMode === 'children_only' && (
                      <span title="Children link to an external parent" className="shrink-0">
                        <Link2 className="w-3.5 h-3.5 shrink-0" style={{ color: colors.textMuted }} />
                      </span>
                    )}
                  </div>
                  <div style={{ fontSize: typography.xs, color: colors.textMuted, marginTop: '2px' }}>
                    {strategy.completedItems}/{strategy.totalItems} items completed
                    {strategy.failedItems > 0 && (
                      <span style={{ color: colors.danger }}> · {strategy.failedItems} failed</span>
                    )}
                    <span> · {new Date(strategy.createdAt).toLocaleDateString()}</span>
                  </div>
                </div>

                {/* Publishing Mode — inline-editable (AutoPress row parity).
                    Switching an existing draft strategy to Auto-publish makes
                    FUTURE generations publish; already-completed items get the
                    per-item Publish button below. */}
                <div className="w-32 shrink-0" onClick={(e) => e.stopPropagation()}>
                  <Select
                    value={strategy.publishingMode || 'draft'}
                    onValueChange={(value) => handlePublishingModeChange(strategy.id, value)}
                  >
                    <SelectTrigger className="h-8 text-xs bg-background">
                      <SelectValue placeholder="Draft" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="draft">{PUBLISHING_MODE_LABELS.draft}</SelectItem>
                      <SelectItem value="publish">{PUBLISHING_MODE_LABELS.publish}</SelectItem>
                      <SelectItem value="schedule">{PUBLISHING_MODE_LABELS.schedule}</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                {/* Target Site — inline-editable, merges onto the strategy's
                    existing config (see handleSiteChange) rather than replacing it. */}
                <div className="w-36 shrink-0" onClick={(e) => e.stopPropagation()}>
                  <Select value={siteIdValue} onValueChange={(value) => handleSiteChange(strategy.id, value)}>
                    <SelectTrigger className="h-8 text-xs bg-background">
                      <SelectValue placeholder="No site" />
                    </SelectTrigger>
                    <SelectContent>
                      {sites.length > 0 ? (
                        sites.map((s: any) => (
                          <SelectItem key={s.id} value={String(s.id)}>{s.name || s.url}</SelectItem>
                        ))
                      ) : (
                        <div className="p-2 text-xs text-muted-foreground text-center">
                          No sites — connect one in Sites
                        </div>
                      )}
                    </SelectContent>
                  </Select>
                </div>

                {/* Template (prompt) — inline-editable; drives generation for
                    items generated AFTER the change. */}
                <div className="w-36 shrink-0" onClick={(e) => e.stopPropagation()}>
                  <Select
                    value={strategy.templateId ? String(strategy.templateId) : ''}
                    onValueChange={(value) => handleTemplateChange(strategy.id, value)}
                  >
                    <SelectTrigger className="h-8 text-xs bg-background">
                      <SelectValue placeholder="Template" />
                    </SelectTrigger>
                    <SelectContent>
                      {templates.length > 0 ? (
                        templates.map((t: any) => (
                          <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>
                        ))
                      ) : (
                        <div className="p-2 text-xs text-muted-foreground text-center">
                          No Writer templates
                        </div>
                      )}
                    </SelectContent>
                  </Select>
                </div>

                {/* Approval mode — inline-editable (partial config merge).
                    Affects items generated AFTER the change. */}
                <div className="w-28 shrink-0" onClick={(e) => e.stopPropagation()}>
                  <Select
                    value={config.approvalMode || 'none'}
                    onValueChange={(value) => handleApprovalChange(strategy.id, value)}
                  >
                    <SelectTrigger className="h-8 text-xs bg-background">
                      <SelectValue placeholder="Approvals" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">No approval</SelectItem>
                      <SelectItem value="internal">Internal</SelectItem>
                      <SelectItem value="client">Client</SelectItem>
                      <SelectItem value="both">Internal + Client</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                {/* Frequency — schedule mode only; opens the recurrence dialog.
                    Saving redistributes the PENDING items' due dates server-side. */}
                {strategy.publishingMode === 'schedule' && (
                  <div className="w-32 shrink-0" onClick={(e) => e.stopPropagation()}>
                    <Button
                      variant="outline"
                      size="sm"
                      className="h-8 w-full justify-start text-xs font-normal bg-background overflow-hidden"
                      title="Posting schedule"
                      onClick={() => setRecurrenceDialog({
                        strategyId: strategy.id,
                        value: recurrenceFromConfig(config.scheduleConfig ?? {}),
                      })}
                    >
                      <CalendarClock className="w-3.5 h-3.5 shrink-0" />
                      <span className="truncate">{summarizeRecurrence(recurrenceFromConfig(config.scheduleConfig ?? {}))}</span>
                    </Button>
                  </div>
                )}

                {/* Progress bar mini */}
                <div className="w-20 shrink-0">
                  <div className="h-1.5 rounded-full overflow-hidden" style={{ background: colors.bgHover }}>
                    <div
                      className="h-full rounded-full transition-all duration-300"
                      style={{
                        width: `${strategy.totalItems > 0 ? (strategy.completedItems / strategy.totalItems) * 100 : 0}%`,
                        background: strategy.status === 'completed' ? statusColors.ready.text : colors.primary,
                      }}
                    />
                  </div>
                </div>

                {/* Actions */}
                <div className="flex items-center gap-2 shrink-0" onClick={(e) => e.stopPropagation()}>
                  {strategy.status !== 'completed' && (
                    <Button
                      variant="default"
                      size="sm"
                      disabled={bulkStrategyId === strategy.id || generatingItemId === strategy.id}
                      onClick={() => handleGenerateAll(strategy)}
                    >
                      {bulkStrategyId === strategy.id ? (
                        <Loader2 className="w-3.5 h-3.5 animate-spin" />
                      ) : (
                        <Zap className="w-3.5 h-3.5" />
                      )}
                      Generate All
                    </Button>
                  )}
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={strategy.status === 'completed' || generatingItemId === strategy.id || bulkStrategyId === strategy.id}
                    onClick={() => handleGenerate(strategy.id)}
                  >
                    {generatingItemId === strategy.id ? (
                      <Loader2 className="w-3.5 h-3.5 animate-spin" />
                    ) : (
                      <Play className="w-3.5 h-3.5" />
                    )}
                    Generate
                  </Button>
                  {Number(strategy.completedItems) >= 2 && (
                    <Button
                      variant="outline"
                      size="sm"
                      title="Insert internal links between this strategy's generated articles"
                      onClick={() => setInterlinkModalStrategy({ id: strategy.id, name: strategy.name, interlinksConfig: parseStrategyConfig(strategy.config)?.interlinksConfig })}
                    >
                      <Link2 className="w-3.5 h-3.5" />
                      Interlinks
                    </Button>
                  )}
                  {/* Pause / Resume generation (D2) — shown while the strategy is
                      actively working, queued, or already paused. */}
                  {['in_progress', 'pending', 'paused'].includes(strategy.status) && (
                    <Button
                      variant="ghost"
                      size="sm"
                      title={strategy.status === 'paused' ? 'Resume generation' : 'Pause generation'}
                      onClick={() => handleTogglePause(strategy.id, strategy.status)}
                    >
                      {strategy.status === 'paused' ? (
                        <Play className="w-3.5 h-3.5" />
                      ) : (
                        <Pause className="w-3.5 h-3.5" />
                      )}
                      {strategy.status === 'paused' ? 'Resume' : 'Pause'}
                    </Button>
                  )}
                  {/* Parent / anchor settings editor (G1) */}
                  <Button
                    variant="ghost"
                    size="sm"
                    title="Parent & anchor settings"
                    onClick={() => setParentSettingsStrategy(strategy)}
                  >
                    <Settings2 className="w-3.5 h-3.5" />
                  </Button>
                  {/* Duplicate strategy (E1) */}
                  <Button
                    variant="ghost"
                    size="sm"
                    title="Duplicate this strategy"
                    disabled={duplicateMutation.isPending}
                    onClick={() => handleDuplicate(strategy.id)}
                  >
                    <Copy className="w-3.5 h-3.5" />
                  </Button>
                  {/* Sync published status from WordPress (D3) — only worth
                      offering once at least one item has actually published. */}
                  {(strategy.items ?? []).some((it) => !!it.articlePublishedUrl) && (
                    <Button
                      variant="ghost"
                      size="sm"
                      title="Check published items' status on WordPress"
                      disabled={syncStatusMutation.isPending}
                      onClick={() => handleSyncStatus(strategy.id)}
                    >
                      <RefreshCw className={`w-3.5 h-3.5 ${syncStatusMutation.isPending ? 'animate-spin' : ''}`} />
                      Sync
                    </Button>
                  )}
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => handleDelete(strategy.id, strategy.name)}
                    className="text-muted-foreground hover:text-destructive"
                  >
                    <Trash2 className="w-3.5 h-3.5" />
                  </Button>
                </div>
              </div>

              {/* Expanded items list */}
              {expandedId === strategy.id && strategy.items && (
                <div style={{ background: colors.bgPage }}>
                  {strategy.items.map((item) => {
                    const itemConfig = parseStrategyConfig(item.config);
                    return (
                    <div key={item.id}>
                    <div
                      className="flex items-center gap-3 pl-7 pr-6 py-2"
                      style={{
                        borderBottom: `1px solid ${colors.borderLight}`,
                        borderLeft: `2px solid ${colors.borderLight}`,
                      }}
                    >
                      {/* Position */}
                      <span
                        className="shrink-0 w-6 text-center"
                        style={{ fontSize: typography.xs, color: colors.textMuted, fontWeight: typography.medium }}
                      >
                        {Number(item.position) + 1}
                      </span>

                      {/* Keyword / Title (+ parent crown for hierarchy strategies) */}
                      <div className="flex-1 min-w-0">
                        <span style={{ fontSize: typography.sm, color: colors.text }} className="truncate block">
                          {config.parentKeyword && item.keyword === config.parentKeyword && (
                            <Crown className="w-3.5 h-3.5 inline-block mr-1 text-amber-500" aria-label="Parent (hub) article" />
                          )}
                          {item.title ?? item.keyword}
                        </span>
                        {item.title && (
                          <span style={{ fontSize: typography.xs, color: colors.textMuted }}>
                            {item.keyword}
                          </span>
                        )}
                        {/* F3: compact display-only SEO metrics carried from the
                            Keyword Explorer — shown only when at least one is set. */}
                        {(item.volume != null || item.difficulty != null) && (
                          <span
                            className="ml-2"
                            style={{ fontSize: typography.xs, color: colors.textMuted }}
                          >
                            {item.volume != null && `Vol ${item.volume}`}
                            {item.volume != null && item.difficulty != null && ' · '}
                            {item.difficulty != null && `KD ${item.difficulty}`}
                          </span>
                        )}
                      </div>

                      {/* Due date — editable while pending on a schedule-mode
                          strategy; read-only stamp otherwise. */}
                      {strategy.publishingMode === 'schedule' && item.status === 'pending' ? (
                        <Input
                          type="date"
                          className="h-7 w-36 shrink-0 text-xs bg-background"
                          defaultValue={item.scheduledDate ? item.scheduledDate.slice(0, 10) : ''}
                          onChange={(e) => handleItemDateChange(strategy.id, item.id, e.target.value)}
                          onClick={(e) => e.stopPropagation()}
                        />
                      ) : item.scheduledDate ? (
                        <span className="shrink-0" style={{ fontSize: typography.xs, color: colors.textMuted }}>
                          Due {new Date(item.scheduledDate.replace(' ', 'T')).toLocaleDateString()}
                        </span>
                      ) : null}

                      {/* Status */}
                      <StatusBadge status={item.status} />

                      {/* Article link */}
                      {item.articleId && (
                        <Button
                          variant="ghost"
                          size="sm"
                          className="shrink-0"
                          onClick={() => navigateToWriterArticle(Number(item.articleId))}
                        >
                          <FileText className="w-3.5 h-3.5" />
                          View
                        </Button>
                      )}

                      {/* View on the destination site — where it was actually published */}
                      {item.articlePublishedUrl && (
                        <Button
                          variant="ghost"
                          size="sm"
                          className="shrink-0"
                          onClick={() => window.open(item.articlePublishedUrl, '_blank', 'noopener,noreferrer')}
                        >
                          <ExternalLink className="w-3.5 h-3.5" />
                          View on site
                        </Button>
                      )}

                      {/* Publish a completed-but-unpublished item to the target
                          site — the retro-publish path for draft-mode articles. */}
                      {item.status === 'completed' && item.articleId && !item.articlePublishedUrl && (
                        <Button
                          variant="outline"
                          size="sm"
                          className="shrink-0"
                          disabled={publishingItemId === item.id || !siteIdValue}
                          title={siteIdValue ? 'Publish this article to the target site' : 'Select a Target Site on the strategy row first'}
                          onClick={() => handlePublishItem(strategy.id, item.id)}
                        >
                          {publishingItemId === item.id ? (
                            <Loader2 className="w-3.5 h-3.5 animate-spin" />
                          ) : (
                            <Send className="w-3.5 h-3.5" />
                          )}
                          Publish
                        </Button>
                      )}

                      {/* Retry a failed item */}
                      {item.status === 'error' && (
                        <Button
                          variant="ghost"
                          size="sm"
                          className="shrink-0"
                          disabled={retryItemId === item.id || bulkStrategyId === strategy.id}
                          onClick={() => handleRetry(strategy.id, item.id)}
                        >
                          {retryItemId === item.id ? (
                            <Loader2 className="w-3.5 h-3.5 animate-spin" />
                          ) : (
                            <RefreshCw className="w-3.5 h-3.5" />
                          )}
                          Retry
                        </Button>
                      )}

                      {/* Error message */}
                      {item.errorMessage && (
                        <span
                          className="text-xs truncate max-w-[200px]"
                          style={{ color: colors.danger }}
                          title={item.errorMessage}
                        >
                          {item.errorMessage}
                        </span>
                      )}

                      {/* Per-item overrides toggle (Task F1/F2) — templateId/
                          publishingMode/approvalMode, inline below this row. */}
                      <Button
                        variant="ghost"
                        size="sm"
                        className="shrink-0"
                        title="Per-item overrides"
                        onClick={() => setOpenOverridesItemId(openOverridesItemId === item.id ? null : item.id)}
                      >
                        <SlidersHorizontal className="w-3.5 h-3.5" />
                      </Button>

                      {/* Remove this item from the strategy (article stays in
                          Writer). Hidden while it's actively generating. */}
                      {item.status !== 'generating' && (
                        <Button
                          variant="ghost"
                          size="sm"
                          className="shrink-0 text-muted-foreground hover:text-destructive"
                          title="Remove this keyword from the strategy"
                          disabled={bulkStrategyId === strategy.id}
                          onClick={() => handleDeleteItem(strategy.id, item.id, item.keyword)}
                        >
                          <Trash2 className="w-3.5 h-3.5" />
                        </Button>
                      )}
                    </div>

                    {/* Inline overrides row (Task F2) — Template / Mode / Approval,
                        each defaulting to "Inherit" (absent key = strategy's own
                        value). Saves on change via the item PATCH endpoint, sending
                        the FULL desired override set each time (REPLACE semantics —
                        see handleItemOverrideChange()'s docblock). */}
                    {openOverridesItemId === item.id && (
                      <div
                        className="flex items-center gap-3 pl-16 pr-6 py-2"
                        style={{
                          background: colors.bgHover,
                          borderBottom: `1px solid ${colors.borderLight}`,
                          borderLeft: `2px solid ${colors.borderLight}`,
                        }}
                        onClick={(e) => e.stopPropagation()}
                      >
                        <span style={{ fontSize: typography.xs, color: colors.textMuted }}>Template</span>
                        <Select
                          value={itemConfig.templateId ? String(itemConfig.templateId) : 'inherit'}
                          onValueChange={(value) => handleItemOverrideChange(strategy.id, item.id, itemConfig, 'templateId', value)}
                        >
                          <SelectTrigger className="h-7 w-40 text-xs"><SelectValue /></SelectTrigger>
                          <SelectContent>
                            <SelectItem value="inherit">Inherit</SelectItem>
                            {templates.map((t: any) => (
                              <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>
                            ))}
                          </SelectContent>
                        </Select>

                        <span style={{ fontSize: typography.xs, color: colors.textMuted }}>Mode</span>
                        <Select
                          value={itemConfig.publishingMode ?? 'inherit'}
                          onValueChange={(value) => handleItemOverrideChange(strategy.id, item.id, itemConfig, 'publishingMode', value)}
                        >
                          <SelectTrigger className="h-7 w-36 text-xs"><SelectValue /></SelectTrigger>
                          <SelectContent>
                            {Object.entries(ITEM_MODE_LABELS).map(([value, label]) => (
                              <SelectItem key={value} value={value}>{label}</SelectItem>
                            ))}
                          </SelectContent>
                        </Select>

                        <span style={{ fontSize: typography.xs, color: colors.textMuted }}>Approval</span>
                        <Select
                          value={itemConfig.approvalMode ?? 'inherit'}
                          onValueChange={(value) => handleItemOverrideChange(strategy.id, item.id, itemConfig, 'approvalMode', value)}
                        >
                          <SelectTrigger className="h-7 w-32 text-xs"><SelectValue /></SelectTrigger>
                          <SelectContent>
                            {Object.entries(ITEM_APPROVAL_LABELS).map(([value, label]) => (
                              <SelectItem key={value} value={value}>{label}</SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </div>
                    )}
                    </div>
                    );
                  })}
                </div>
              )}
            </div>
            );
          })}
        </div>
      )}

      {/* Floating bulk action bar — bottom-center; visible while any strategy is
          selected. Count is derived from the selection; actions operate only on
          selected ids still present in strategyList. */}
      {view === 'list' && selectedIds.size > 0 && (
        <div
          className="fixed left-1/2 -translate-x-1/2 bottom-6 z-50 flex items-center gap-2 px-4 py-2.5 rounded-full"
          style={{ background: colors.bgSurface, border: `1px solid ${colors.border}`, boxShadow: shadows.dropdown }}
        >
          <span style={{ fontSize: typography.sm, fontWeight: typography.medium, color: colors.text }}>
            {selectedIds.size} selected
          </span>
          <Button variant="default" size="sm" disabled={bulkBusy} onClick={handleBulkGenerate}>
            {bulkBusy ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Zap className="w-3.5 h-3.5" />}
            Generate All
          </Button>
          <Button variant="outline" size="sm" disabled={bulkBusy} onClick={handleBulkPublish}>
            <Send className="w-3.5 h-3.5" />
            Publish completed
          </Button>
          <Button
            variant="ghost"
            size="sm"
            disabled={bulkBusy}
            onClick={handleBulkDelete}
            className="text-muted-foreground hover:text-destructive"
          >
            <Trash2 className="w-3.5 h-3.5" />
            Delete
          </Button>
          <Button variant="ghost" size="sm" disabled={bulkBusy} onClick={clearSelection} title="Clear selection">
            <X className="w-3.5 h-3.5" />
          </Button>
        </div>
      )}

      {/* Interlink configuration + run modal (auto / manual) */}
      <InterlinkManagerModal
        open={interlinkModalStrategy !== null}
        onOpenChange={(o) => { if (!o) setInterlinkModalStrategy(null); }}
        strategyId={interlinkModalStrategy?.id ?? 0}
        strategyName={interlinkModalStrategy?.name ?? ''}
        interlinksConfig={interlinkModalStrategy?.interlinksConfig}
        onDone={refetch}
      />

      {/* Parent / anchor settings editor (G1) */}
      <ParentSettingsModal
        open={parentSettingsStrategy !== null}
        onOpenChange={(o) => { if (!o) setParentSettingsStrategy(null); }}
        strategyId={parentSettingsStrategy?.id ?? 0}
        strategyName={parentSettingsStrategy?.name ?? ''}
        hierarchyMode={parentSettingsStrategy?.hierarchyMode ?? 'standalone'}
        config={parseStrategyConfig(parentSettingsStrategy?.config)}
        onDone={refetch}
      />

      {/* Posting schedule (recurrence) editor — replaces the old bare
          frequency Select on the strategy row. */}
      <Dialog open={recurrenceDialog !== null} onOpenChange={(o) => { if (!o) setRecurrenceDialog(null); }}>
        <DialogContent className="sm:max-w-[420px]">
          <DialogHeader>
            <DialogTitle>Posting schedule</DialogTitle>
          </DialogHeader>
          {recurrenceDialog && (
            <RecurrenceEditor
              value={recurrenceDialog.value}
              onChange={(next) => setRecurrenceDialog({ strategyId: recurrenceDialog.strategyId, value: next })}
            />
          )}
          <DialogFooter className="pt-4 border-t">
            <Button variant="outline" size="sm" onClick={() => setRecurrenceDialog(null)}>
              Cancel
            </Button>
            <Button
              variant="default"
              size="sm"
              onClick={() => {
                if (!recurrenceDialog) return;
                const strategy = strategyList.find((s) => s.id === recurrenceDialog.strategyId);
                const existingStartDate = parseStrategyConfig(strategy?.config).scheduleConfig?.startDate || '';
                handleRecurrenceSave(recurrenceDialog.strategyId, recurrenceDialog.value, existingStartDate);
              }}
            >
              Save
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
