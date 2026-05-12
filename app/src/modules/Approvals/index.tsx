/**
 * APPROVALS MODULE — Content Pipeline Kanban
 *
 * Read-only orchestration view showing all articles grouped by status.
 * Provides a bird's-eye view of the content pipeline for team tracking.
 *
 * This module does NOT own any data — it reads from the articles API
 * and allows status updates via PATCH.
 *
 * Columns: Draft → Review → Ready → Published
 *
 * Data source: trpc.articles.list / trpc.articles.update
 */

import { useState, useCallback, useMemo } from 'react';
import {
  KanbanSquare, FileText, ChevronRight,
  ExternalLink,
} from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { colors, typography, shadows, statusColors } from '@/components/shared/design-tokens';
import type { StatusKey } from '@/components/shared/design-tokens';
import { trpc } from '@/lib/trpc';

// ── Types ──
interface Article {
  id: number;
  title: string;
  slug?: string;
  status: StatusKey;
  strategyId?: number;
  brandId?: number;
  publishedUrl?: string;
  createdAt: string;
  updatedAt: string;
}

// ── Constants ──
const STATUS_COLUMNS: { key: StatusKey; label: string }[] = [
  { key: 'draft', label: 'Draft' },
  { key: 'review', label: 'Review' },
  { key: 'ready', label: 'Ready' },
  { key: 'published', label: 'Published' },
];

// ── Kanban Column ──
function KanbanColumn({
  statusKey,
  label,
  articles,
  onStatusChange,
}: {
  statusKey: StatusKey;
  label: string;
  articles: Article[];
  onStatusChange: (articleId: number, newStatus: StatusKey) => void;
}) {
  const sc = statusColors[statusKey];

  return (
    <div className="flex-1 min-w-[240px] flex flex-col rounded-lg overflow-hidden"
      style={{ border: `1px solid ${colors.border}`, background: colors.bgPage }}
    >
      {/* Column header */}
      <div
        className="flex items-center justify-between px-3 py-2 shrink-0"
        style={{ borderBottom: `2px solid ${sc.border}`, background: sc.bg }}
      >
        <span style={{ fontSize: typography.sm, fontWeight: typography.semibold, color: sc.text }}>
          {label}
        </span>
        <Badge variant="secondary" className="text-xs">
          {articles.length}
        </Badge>
      </div>

      {/* Cards */}
      <div className="flex-1 overflow-y-auto p-2 space-y-2">
        {articles.length === 0 ? (
          <div
            className="flex items-center justify-center py-8 text-center rounded"
            style={{ border: `1px dashed ${colors.border}` }}
          >
            <span style={{ fontSize: typography.xs, color: colors.textMuted }}>
              No articles
            </span>
          </div>
        ) : (
          articles.map((article) => (
            <div
              key={article.id}
              className="rounded-md p-3 hover:shadow-md transition-shadow"
              style={{
                background: colors.bgSurface,
                border: `1px solid ${colors.borderLight}`,
                boxShadow: shadows.card,
              }}
            >
              {/* Title */}
              <div className="flex items-start gap-2 mb-2">
                <FileText className="w-3.5 h-3.5 shrink-0 mt-0.5" style={{ color: colors.textMuted }} />
                <span
                  className="line-clamp-2"
                  style={{ fontSize: typography.sm, fontWeight: typography.medium, color: colors.text }}
                >
                  {article.title}
                </span>
              </div>

              {/* Published link */}
              {article.publishedUrl && (
                <a
                  href={article.publishedUrl}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex items-center gap-1 mb-2"
                  style={{ fontSize: typography.xs, color: colors.primary, textDecoration: 'none' }}
                >
                  View live <ExternalLink className="w-3 h-3" />
                </a>
              )}

              {/* Status selector */}
              <div className="flex items-center gap-2">
                <Select
                  value={article.status}
                  onValueChange={(val: string) => onStatusChange(article.id, val as StatusKey)}
                >
                  <SelectTrigger variant="surface" className="h-7 text-xs flex-1">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {STATUS_COLUMNS.map((col) => (
                      <SelectItem key={col.key} value={col.key}>
                        {col.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <span style={{ fontSize: typography.xs, color: colors.textFaint }}>
                  {new Date(article.updatedAt).toLocaleDateString()}
                </span>
              </div>
            </div>
          ))
        )}
      </div>
    </div>
  );
}

// ── Main Component ──
export function ApprovalsModule() {
  // Data fetching via tRPC proxy
  const { data: articlesRaw, isLoading, refetch } = trpc.articles.list.useQuery() as any;
  const articles: Article[] = Array.isArray(articlesRaw) ? articlesRaw : [];

  // Status update mutation
  const updateMutation = trpc.articles.update.useMutation({
    onSuccess: () => { toast.success('Status updated'); refetch(); },
    onError: (err: any) => {
      toast.error(err.message ?? 'Failed to update status');
      refetch(); // Revert optimistic update
    },
  }) as any;

  // Group articles by status
  const grouped = useMemo(() => {
    const map: Record<StatusKey, Article[]> = {
      draft: [],
      review: [],
      ready: [],
      published: [],
    };
    for (const article of articles) {
      const key = article.status as StatusKey;
      if (map[key]) {
        map[key].push(article);
      } else {
        map.draft.push(article); // Fallback to draft for unknown statuses
      }
    }
    return map;
  }, [articles]);

  // Status change handler
  const handleStatusChange = useCallback((articleId: number, newStatus: StatusKey) => {
    updateMutation.mutate({ id: articleId, status: newStatus });
  }, [updateMutation]);

  // Stats
  const totalArticles = articles.length;
  const publishedCount = grouped.published.length;

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-full">
        <Spinner className="w-6 h-6" />
      </div>
    );
  }

  return (
    <div className="h-full flex flex-col">
      {/* Header */}
      <div className="flex items-center gap-2 mb-4 shrink-0">
        <KanbanSquare className="w-5 h-5" style={{ color: colors.primary }} />
        <h1 style={{ fontSize: typography.title, fontWeight: typography.bold, color: colors.text }}>
          Approvals
        </h1>
        <Badge variant="secondary" className="ml-2">
          {totalArticles} articles
        </Badge>
        {publishedCount > 0 && (
          <Badge variant="outline" className="ml-1">
            {publishedCount} published
          </Badge>
        )}
      </div>

      {/* Pipeline overview mini-bar */}
      {totalArticles > 0 && (
        <div className="flex items-center gap-1 mb-4 shrink-0">
          {STATUS_COLUMNS.map((col, idx) => {
            const count = grouped[col.key].length;
            const sc = statusColors[col.key];
            return (
              <div key={col.key} className="flex items-center gap-1">
                {idx > 0 && <ChevronRight className="w-3 h-3 shrink-0" style={{ color: colors.textGhost }} />}
                <div className="flex items-center gap-1 px-2 py-1 rounded" style={{ background: sc.bg }}>
                  <span style={{ fontSize: typography.xs, fontWeight: typography.semibold, color: sc.text }}>
                    {count}
                  </span>
                  <span style={{ fontSize: typography.xs, color: sc.text }}>
                    {col.label}
                  </span>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Empty state */}
      {totalArticles === 0 ? (
        <div
          className="flex flex-col items-center justify-center flex-1 rounded-lg"
          style={{ border: `1px dashed ${colors.border}`, background: colors.bgSurface }}
        >
          <KanbanSquare className="w-12 h-12 mb-3" style={{ color: colors.textMuted }} />
          <p style={{ color: colors.textSecondary, fontSize: typography.body, fontWeight: typography.medium }}>
            No articles in pipeline
          </p>
          <p style={{ color: colors.textMuted, fontSize: typography.sm, marginTop: '4px' }}>
            Generate content from a strategy to see it here
          </p>
        </div>
      ) : (
        /* Kanban columns */
        <div className="flex-1 flex gap-3 overflow-x-auto min-h-0">
          {STATUS_COLUMNS.map((col) => (
            <KanbanColumn
              key={col.key}
              statusKey={col.key}
              label={col.label}
              articles={grouped[col.key]}
              onStatusChange={handleStatusChange}
            />
          ))}
        </div>
      )}
    </div>
  );
}
