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

import { useState, useCallback, useRef } from 'react';
import {
  Layers, ChevronRight, ChevronDown, Play, Trash2, Zap, RefreshCw,
  CheckCircle2, Clock, AlertCircle, Loader2, FileText,
} from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { colors, typography, shadows, statusColors } from '@/components/shared/design-tokens';
import { trpc } from '@/lib/trpc';

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
}

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
}

// ── Status indicator — reusable across Strategies + Approvals ──
function StatusBadge({ status }: { status: string }) {
  const config: Record<string, { icon: React.ReactNode; label: string; color: string; bg: string }> = {
    pending:      { icon: <Clock className="w-3 h-3" />, label: 'Pending', color: statusColors.draft.text, bg: statusColors.draft.bg },
    in_progress:  { icon: <Loader2 className="w-3 h-3 animate-spin" />, label: 'In Progress', color: colors.primary, bg: colors.primaryLight },
    completed:    { icon: <CheckCircle2 className="w-3 h-3" />, label: 'Completed', color: statusColors.ready.text, bg: statusColors.ready.bg },
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
  const [expandedId, setExpandedId] = useState<number | null>(null);
  const [generatingItemId, setGeneratingItemId] = useState<number | null>(null);
  const [bulkStrategyId, setBulkStrategyId] = useState<number | null>(null);
  const [retryItemId, setRetryItemId] = useState<number | null>(null);
  // During a "Generate All" run, per-item toasts/refetch are suppressed so the loop
  // isn't noisy — a single summary toast + one refetch fire at the end instead.
  const bulkModeRef = useRef(false);

  // Data fetching via tRPC proxy (uses established apiFetch + react-query)
  const { data: strategies = [], isLoading, refetch } = trpc.strategy.list.useQuery() as any;

  // Mutations
  const generateMutation = trpc.strategy.generate.useMutation({
    onSuccess: (_data: any) => {
      if (bulkModeRef.current) return; // bulk run reports its own summary
      if (_data?.complete) {
        toast.success('All items have been generated!');
      } else {
        toast.success('Article generated successfully');
      }
      refetch();
    },
    onError: (err: any) => {
      if (bulkModeRef.current) return;
      toast.error(err.message ?? 'Generation failed');
    },
    onSettled: () => setGeneratingItemId(null),
  }) as any;

  const deleteMutation = trpc.strategy.delete.useMutation({
    onSuccess: () => { toast.success('Strategy deleted'); refetch(); },
    onError: (err: any) => toast.error(err.message ?? 'Failed to delete'),
  }) as any;

  // Expand/collapse to fetch items for a strategy
  const toggleExpand = useCallback(async (strategyId: number) => {
    if (expandedId === strategyId) {
      setExpandedId(null);
      return;
    }
    setExpandedId(strategyId);
    // Items are fetched inline — strategy.get returns items attached
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

  // Delete strategy
  const handleDelete = useCallback((strategyId: number) => {
    deleteMutation.mutate({ id: strategyId });
  }, [deleteMutation]);

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-full">
        <Spinner className="w-6 h-6" />
      </div>
    );
  }

  // Cast to typed array (trpc proxy returns unknown)
  const strategyList: Strategy[] = Array.isArray(strategies) ? strategies : [];

  return (
    <div className="h-full flex flex-col">
      {/* Header */}
      <div className="flex items-center gap-2 mb-4 shrink-0">
        <Layers className="w-5 h-5" style={{ color: colors.primary }} />
        <h1 style={{ fontSize: typography.title, fontWeight: typography.bold, color: colors.text }}>
          Strategies
        </h1>
        <Badge variant="secondary" className="ml-2">
          {strategyList.length} {strategyList.length === 1 ? 'strategy' : 'strategies'}
        </Badge>
      </div>

      {/* Empty state */}
      {strategyList.length === 0 ? (
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
      ) : (
        /* Strategy list */
        <div className="flex-1 overflow-y-auto space-y-3">
          {strategyList.map((strategy) => (
            <div
              key={strategy.id}
              className="rounded-lg overflow-hidden"
              style={{ border: `1px solid ${colors.border}`, background: colors.bgSurface, boxShadow: shadows.card }}
            >
              {/* Strategy header row */}
              <div
                className="flex items-center gap-3 px-4 py-3 cursor-pointer"
                style={{ borderBottom: expandedId === strategy.id ? `1px solid ${colors.borderLight}` : 'none' }}
                onClick={() => toggleExpand(strategy.id)}
              >
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
                  </div>
                  <div style={{ fontSize: typography.xs, color: colors.textMuted, marginTop: '2px' }}>
                    {strategy.completedItems}/{strategy.totalItems} items completed
                    {strategy.failedItems > 0 && (
                      <span style={{ color: colors.danger }}> · {strategy.failedItems} failed</span>
                    )}
                    <span> · {new Date(strategy.createdAt).toLocaleDateString()}</span>
                  </div>
                </div>

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
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => handleDelete(strategy.id)}
                    className="text-muted-foreground hover:text-destructive"
                  >
                    <Trash2 className="w-3.5 h-3.5" />
                  </Button>
                </div>
              </div>

              {/* Expanded items list */}
              {expandedId === strategy.id && strategy.items && (
                <div style={{ background: colors.bgPage }}>
                  {strategy.items.map((item) => (
                    <div
                      key={item.id}
                      className="flex items-center gap-3 px-6 py-2"
                      style={{ borderBottom: `1px solid ${colors.borderLight}` }}
                    >
                      {/* Position */}
                      <span
                        className="shrink-0 w-6 text-center"
                        style={{ fontSize: typography.xs, color: colors.textMuted, fontWeight: typography.medium }}
                      >
                        {item.position + 1}
                      </span>

                      {/* Keyword / Title */}
                      <div className="flex-1 min-w-0">
                        <span style={{ fontSize: typography.sm, color: colors.text }} className="truncate block">
                          {item.title ?? item.keyword}
                        </span>
                        {item.title && (
                          <span style={{ fontSize: typography.xs, color: colors.textMuted }}>
                            {item.keyword}
                          </span>
                        )}
                      </div>

                      {/* Status */}
                      <StatusBadge status={item.status} />

                      {/* Article link */}
                      {item.articleId && (
                        <Button variant="ghost" size="sm" className="shrink-0">
                          <FileText className="w-3.5 h-3.5" />
                          View
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
                    </div>
                  ))}
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
