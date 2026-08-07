/**
 * STRATEGIES — Master Content Schedule
 *
 * Cross-strategy flat view of every scheduled or already-published item,
 * sorted by due date (ascending, unscheduled-but-live rows last). A read-only
 * companion to the strategy list: one clean table instead of a per-strategy
 * accordion, so a team can see "what's due next" across the whole account.
 *
 * Data source: trpc.strategy.scheduleFeed (GET /strategies/schedule).
 */

import { useMemo, useState } from 'react';
import {
  CalendarClock, FileText, ExternalLink, Clock, CheckCircle2,
  AlertCircle, Loader2,
} from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { colors, typography, shadows, statusColors } from '@/components/shared/design-tokens';
import { trpc } from '@/lib/trpc';
import { useApp } from '@/contexts/AppContext';

// ── Types — matches the backend schedule_feed() row shape ──
interface ScheduleRow {
  itemId: number;
  strategyId: number;
  strategyName: string;
  keyword: string;
  title?: string;
  status: string;
  /** 'Y-m-d H:i:s' or null when the item is published without a due date. */
  scheduledDate?: string | null;
  articlePublishedUrl?: string | null;
  articleId?: number | null;
  publishingMode: string;
}

const PUBLISHING_MODE_LABELS: Record<string, string> = {
  draft: 'Draft',
  publish: 'Auto-publish',
  schedule: 'Scheduled',
};

// ── Status pill — a compact local mirror of the Strategies list badge
// (that one isn't exported), so this view has no cross-module coupling. ──
function StatusBadge({ status, published }: { status: string; published?: boolean }) {
  const config: Record<string, { icon: React.ReactNode; label: string; color: string; bg: string }> = {
    pending:     { icon: <Clock className="w-3 h-3" />, label: 'Pending', color: statusColors.draft.text, bg: statusColors.draft.bg },
    in_progress: { icon: <Loader2 className="w-3 h-3 animate-spin" />, label: 'In Progress', color: colors.primary, bg: colors.primaryLight },
    generating:  { icon: <Loader2 className="w-3 h-3 animate-spin" />, label: 'Generating…', color: colors.accent, bg: colors.accentLight },
    completed:   { icon: <CheckCircle2 className="w-3 h-3" />, label: 'Completed', color: statusColors.ready.text, bg: statusColors.ready.bg },
    publishedOk: { icon: <CheckCircle2 className="w-3 h-3" />, label: 'Published', color: statusColors.ready.text, bg: statusColors.ready.bg },
    written:     { icon: <FileText className="w-3 h-3" />, label: 'Written', color: statusColors.published.text, bg: statusColors.published.bg },
    error:       { icon: <AlertCircle className="w-3 h-3" />, label: 'Error', color: colors.danger, bg: colors.dangerLight },
  };
  // Mirrors the Strategies list badge: done-but-not-live reads "Written" (blue),
  // only a live post reads "Published" (green). `statusColors.ready` is GREEN and
  // `statusColors.published` is BLUE despite the names.
  const isDone = status === 'completed' || status === 'complete';
  const key = isDone && published !== undefined ? (published ? 'publishedOk' : 'written') : status;
  const c = config[key] ?? config.pending;
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

/** Due date formatted; '—' when the item has no scheduled date. */
function formatDue(raw?: string | null): string {
  if (!raw) return '—';
  const d = new Date(raw.replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return '—';
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

export function ScheduleView() {
  const { navigateToWriterArticle } = useApp();
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');

  // Read-only feed — no auto-refresh (the backend already sorts + flattens).
  const { data, isLoading } = trpc.strategy.scheduleFeed.useQuery() as any;
  const rows: ScheduleRow[] = Array.isArray(data) ? data : [];

  // Client-side text search (keyword / title / strategy) + exact status match,
  // mirroring the Strategies list toolbar. Both optional, combined with AND.
  const visible = useMemo(() => {
    const q = search.trim().toLowerCase();
    return rows.filter((r) => {
      if (statusFilter !== 'all' && r.status !== statusFilter) return false;
      if (!q) return true;
      return (
        r.keyword?.toLowerCase().includes(q)
        || r.title?.toLowerCase().includes(q)
        || r.strategyName?.toLowerCase().includes(q)
      );
    });
  }, [rows, search, statusFilter]);

  if (isLoading) {
    return (
      <div className="flex items-center justify-center flex-1">
        <Spinner className="w-6 h-6" />
      </div>
    );
  }

  const thStyle: React.CSSProperties = {
    fontSize: typography.xs,
    fontWeight: typography.semibold,
    color: colors.textMuted,
    textTransform: 'uppercase',
    letterSpacing: '0.03em',
  };

  return (
    <div className="flex-1 flex flex-col min-h-0">
      {/* Filters */}
      <div className="flex items-center gap-2 mb-3 shrink-0">
        <Input
          placeholder="Search keyword, title, or strategy…"
          className="h-8 w-64 text-xs bg-card"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <Select value={statusFilter} onValueChange={setStatusFilter}>
          <SelectTrigger className="w-36">
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
        <span className="ml-auto" style={{ fontSize: typography.xs, color: colors.textMuted }}>
          {search.trim() || statusFilter !== 'all'
            ? `${visible.length} of ${rows.length}`
            : `${rows.length} ${rows.length === 1 ? 'item' : 'items'}`}
        </span>
      </div>

      {rows.length === 0 ? (
        <div
          className="flex flex-col items-center justify-center flex-1 rounded-lg"
          style={{ border: `1px dashed ${colors.border}`, background: colors.bgSurface }}
        >
          <CalendarClock className="w-12 h-12 mb-3" style={{ color: colors.textMuted }} />
          <p style={{ color: colors.textSecondary, fontSize: typography.body, fontWeight: typography.medium }}>
            Nothing scheduled yet
          </p>
          <p style={{ color: colors.textMuted, fontSize: typography.sm, marginTop: '4px' }}>
            Schedule a strategy's items, or publish an article, to see it here
          </p>
        </div>
      ) : visible.length === 0 ? (
        <div
          className="flex items-center justify-center flex-1 rounded-lg"
          style={{ border: `1px dashed ${colors.border}`, background: colors.bgSurface }}
        >
          <p style={{ color: colors.textMuted, fontSize: typography.sm }}>No items match.</p>
        </div>
      ) : (
        <div
          className="flex-1 overflow-auto rounded-lg"
          style={{ border: `1px solid ${colors.border}`, background: colors.bgSurface, boxShadow: shadows.card }}
        >
          <table className="min-w-full border-collapse">
            <thead>
              <tr style={{ background: colors.bgPage, borderBottom: `1px solid ${colors.border}` }}>
                <th className="px-4 py-2.5 text-left" style={thStyle}>Due</th>
                <th className="px-4 py-2.5 text-left" style={thStyle}>Keyword / Title</th>
                <th className="px-4 py-2.5 text-left" style={thStyle}>Strategy</th>
                <th className="px-4 py-2.5 text-left" style={thStyle}>Status</th>
                <th className="px-4 py-2.5 text-left" style={thStyle}>Mode</th>
                <th className="px-4 py-2.5 text-right" style={thStyle}>Actions</th>
              </tr>
            </thead>
            <tbody>
              {visible.map((row) => (
                <tr
                  key={`${row.strategyId}-${row.itemId}`}
                  style={{ borderBottom: `1px solid ${colors.borderLight}` }}
                >
                  {/* Due date */}
                  <td className="px-4 py-3 whitespace-nowrap" style={{ fontSize: typography.sm, color: colors.textSecondary }}>
                    {formatDue(row.scheduledDate)}
                  </td>

                  {/* TARGET KEYWORD leads, article title beneath — same ordering as the
                      Strategies list, so one item reads identically in both views. The
                      sub-line is skipped when it would just repeat the line above. */}
                  <td className="px-4 py-3">
                    <div style={{ fontSize: typography.sm, color: colors.text }} className="truncate max-w-[320px]">
                      {(row.keyword ?? '').trim() !== '' ? row.keyword : row.title}
                    </div>
                    {row.title && row.title !== row.keyword && (
                      <div style={{ fontSize: typography.xs, color: colors.textMuted }} className="truncate max-w-[320px]">
                        {row.title}
                      </div>
                    )}
                  </td>

                  {/* Strategy name */}
                  <td className="px-4 py-3 whitespace-nowrap" style={{ fontSize: typography.sm, color: colors.textSecondary }}>
                    {row.strategyName}
                  </td>

                  {/* Status */}
                  <td className="px-4 py-3 whitespace-nowrap">
                    <StatusBadge status={row.status} published={!!row.articlePublishedUrl} />
                  </td>

                  {/* Publishing mode */}
                  <td className="px-4 py-3 whitespace-nowrap" style={{ fontSize: typography.xs, color: colors.textMuted }}>
                    {PUBLISHING_MODE_LABELS[row.publishingMode] ?? row.publishingMode}
                  </td>

                  {/* Actions */}
                  <td className="px-4 py-3 whitespace-nowrap text-right">
                    <div className="flex items-center justify-end gap-1">
                      {row.articleId ? (
                        <Button
                          variant="ghost"
                          size="sm"
                          className="shrink-0"
                          onClick={() => navigateToWriterArticle(Number(row.articleId))}
                        >
                          <FileText className="w-3.5 h-3.5" />
                          View
                        </Button>
                      ) : null}
                      {row.articlePublishedUrl ? (
                        <Button
                          variant="ghost"
                          size="sm"
                          className="shrink-0"
                          onClick={() => window.open(row.articlePublishedUrl!, '_blank', 'noopener,noreferrer')}
                        >
                          <ExternalLink className="w-3.5 h-3.5" />
                          See live
                        </Button>
                      ) : null}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
