/**
 * APPROVALS MODULE — Content & Ad Sets Pipeline
 *
 * Tabbed orchestration pipeline:
 *  1. Written Articles — Kanban board showing article statuses.
 *  2. Ad Approval Sets — Kanban board showing shared client sets.
 *
 * @package PowerCreatives
 */

import { useState, useCallback, useMemo } from 'react';
import {
  KanbanSquare, FileText, ChevronRight,
  ExternalLink, Copy, Check, MessageSquare, CheckCircle2, Clock, Eye
} from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { Button } from '@/components/ui/button';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
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

interface ApprovalSet {
  id: number;
  userId: number;
  brandId?: number | null;
  projectId?: number | null;
  name: string;
  token: string;
  status: 'draft' | 'review' | 'completed';
  snapshot: {
    brandName?: string;
    brandLogoUrl?: string;
    media: any[];
    copy: any[];
  };
  reviewFeedback?: {
    approvedVisualIds: string[];
    approvedCopyIds: string[];
    comments: Record<string, string>;
  } | null;
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

const SET_STATUS_COLUMNS: { key: 'draft' | 'review' | 'completed'; label: string; scKey: StatusKey }[] = [
  { key: 'draft', label: 'Draft', scKey: 'draft' },
  { key: 'review', label: 'Client Review', scKey: 'review' },
  { key: 'completed', label: 'Completed', scKey: 'ready' },
];

// ── Kanban Column (Articles) ──
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
              <div className="flex items-start gap-2 mb-2">
                <FileText className="w-3.5 h-3.5 shrink-0 mt-0.5" style={{ color: colors.textMuted }} />
                <span
                  className="line-clamp-2"
                  style={{ fontSize: typography.sm, fontWeight: typography.medium, color: colors.text }}
                >
                  {article.title}
                </span>
              </div>

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

              <div className="flex items-center gap-2">
                <select
                  value={article.status}
                  onChange={(e) => onStatusChange(article.id, e.target.value as StatusKey)}
                  className="h-7 text-xs flex-1 rounded px-1"
                  style={{ border: `1px solid ${colors.border}`, background: colors.bgSurface }}
                >
                  {STATUS_COLUMNS.map((col) => (
                    <option key={col.key} value={col.key}>
                      {col.label}
                    </option>
                  ))}
                </select>
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
  // Articles Data
  const { data: articlesRaw, isLoading: articlesLoading, refetch: refetchArticles } = trpc.articles.list.useQuery() as any;
  const articles: Article[] = Array.isArray(articlesRaw) ? articlesRaw : [];

  // Ad Sets Data
  const { data: setsRaw, isLoading: setsLoading, refetch: refetchSets } = trpc.approvals.listSets.useQuery() as any;
  const sets: ApprovalSet[] = Array.isArray(setsRaw) ? setsRaw : [];

  // Feedback Dialog State
  const [selectedFeedbackSet, setSelectedFeedbackSet] = useState<ApprovalSet | null>(null);

  // Status update mutation for Articles
  const updateArticleMutation = trpc.articles.update.useMutation({
    onSuccess: () => { toast.success('Article status updated'); refetchArticles(); },
    onError: (err: any) => { toast.error(err.message || 'Failed to update article'); refetchArticles(); },
  }) as any;

  // Group articles by status
  const groupedArticles = useMemo(() => {
    const map: Record<StatusKey, Article[]> = { draft: [], review: [], ready: [], published: [] };
    for (const article of articles) {
      const key = article.status as StatusKey;
      if (map[key]) map[key].push(article);
      else map.draft.push(article);
    }
    return map;
  }, [articles]);

  // Group Ad Sets by status
  const groupedSets = useMemo(() => {
    const map = { draft: [], review: [], completed: [] };
    for (const set of sets) {
      const key = set.status;
      if (map[key]) map[key].push(set);
      else map.draft.push(set);
    }
    return map;
  }, [sets]);

  const handleArticleStatusChange = useCallback((articleId: number, newStatus: StatusKey) => {
    updateArticleMutation.mutate({ id: articleId, status: newStatus });
  }, [updateArticleMutation]);

  // Copy share link helper
  const handleCopyLink = useCallback((token: string) => {
    const host = window.location.origin;
    navigator.clipboard.writeText(`${host}/public/approval/${token}`);
    toast.success('Client link copied to clipboard!');
  }, []);

  const isLoading = articlesLoading || setsLoading;

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
      <div className="flex items-center justify-between mb-4 shrink-0">
        <div className="flex items-center gap-2">
          <KanbanSquare className="w-5 h-5" style={{ color: colors.primary }} />
          <h1 style={{ fontSize: typography.title, fontWeight: typography.bold, color: colors.text }}>
            Approvals Pipeline
          </h1>
        </div>
      </div>

      <Tabs defaultValue="articles" className="flex-1 flex flex-col min-h-0">
        <TabsList className="grid w-full grid-cols-2 max-w-[400px] mb-4">
          <TabsTrigger value="articles" className="text-xs">
            Written Articles ({articles.length})
          </TabsTrigger>
          <TabsTrigger value="adsets" className="text-xs">
            Ad Share Sets ({sets.length})
          </TabsTrigger>
        </TabsList>

        {/* =====================================================================
            Tab 1: Written Articles
            ===================================================================== */}
        <TabsContent value="articles" className="flex-1 flex flex-col min-h-0 m-0">
          {articles.length === 0 ? (
            <div
              className="flex flex-col items-center justify-center flex-1 rounded-lg"
              style={{ border: `1px dashed ${colors.border}`, background: colors.bgSurface }}
            >
              <FileText className="w-12 h-12 mb-3" style={{ color: colors.textMuted }} />
              <p style={{ color: colors.textSecondary, fontSize: typography.body, fontWeight: typography.medium }}>
                No articles in pipeline
              </p>
              <p style={{ color: colors.textMuted, fontSize: typography.sm, marginTop: '4px' }}>
                Generate content from a strategy to see it here
              </p>
            </div>
          ) : (
            <div className="flex-1 flex gap-3 overflow-x-auto min-h-0">
              {STATUS_COLUMNS.map((col) => (
                <KanbanColumn
                  key={col.key}
                  statusKey={col.key}
                  label={col.label}
                  articles={groupedArticles[col.key]}
                  onStatusChange={handleArticleStatusChange}
                />
              ))}
            </div>
          )}
        </TabsContent>

        {/* =====================================================================
            Tab 2: Ad Share Sets
            ===================================================================== */}
        <TabsContent value="adsets" className="flex-1 flex flex-col min-h-0 m-0">
          {sets.length === 0 ? (
            <div
              className="flex flex-col items-center justify-center flex-1 rounded-lg"
              style={{ border: `1px dashed ${colors.border}`, background: colors.bgSurface }}
            >
              <KanbanSquare className="w-12 h-12 mb-3" style={{ color: colors.textMuted }} />
              <p style={{ color: colors.textSecondary, fontSize: typography.body, fontWeight: typography.medium }}>
                No shared Ad Sets in pipeline
              </p>
              <p style={{ color: colors.textMuted, fontSize: typography.sm, marginTop: '4px' }}>
                Go to the Ads module, select assets, and click "Share with Client" to create a set.
              </p>
            </div>
          ) : (
            <div className="flex-1 flex gap-3 overflow-x-auto min-h-0">
              {SET_STATUS_COLUMNS.map((col) => {
                const sc = statusColors[col.scKey];
                const columnSets = groupedSets[col.key] || [];

                return (
                  <div key={col.key} className="flex-1 min-w-[280px] flex flex-col rounded-lg overflow-hidden"
                    style={{ border: `1px solid ${colors.border}`, background: colors.bgPage }}
                  >
                    {/* Header */}
                    <div
                      className="flex items-center justify-between px-3 py-2 shrink-0"
                      style={{ borderBottom: `2px solid ${sc.border}`, background: sc.bg }}
                    >
                      <span style={{ fontSize: typography.sm, fontWeight: typography.semibold, color: sc.text }}>
                        {col.label}
                      </span>
                      <Badge variant="secondary" className="text-xs">
                        {columnSets.length}
                      </Badge>
                    </div>

                    {/* Cards Loop */}
                    <div className="flex-1 overflow-y-auto p-2 space-y-3">
                      {columnSets.length === 0 ? (
                        <div
                          className="flex items-center justify-center py-8 text-center rounded"
                          style={{ border: `1px dashed ${colors.border}` }}
                        >
                          <span style={{ fontSize: typography.xs, color: colors.textMuted }}>
                            No sets
                          </span>
                        </div>
                      ) : (
                        columnSets.map((set: ApprovalSet) => {
                          const totalItems = (set.snapshot.media?.length || 0) + (set.snapshot.copy?.length || 0);
                          const approvedItems = (set.reviewFeedback?.approvedVisualIds?.length || 0) + (set.reviewFeedback?.approvedCopyIds?.length || 0);
                          const commentCount = Object.keys(set.reviewFeedback?.comments || {}).length;

                          return (
                            <div
                              key={set.id}
                              className="rounded-md p-3 hover:shadow-md transition-shadow relative group"
                              style={{
                                background: colors.bgSurface,
                                border: `1px solid ${colors.borderLight}`,
                                boxShadow: shadows.card,
                              }}
                            >
                              {/* Title & Brand */}
                              <div className="mb-2">
                                <span
                                  className="line-clamp-2 mb-1"
                                  style={{ fontSize: typography.sm, fontWeight: typography.semibold, color: colors.text }}
                                >
                                  {set.name}
                                </span>
                                {set.snapshot.brandName && (
                                  <Badge variant="outline" className="text-[10px]">
                                    {set.snapshot.brandName}
                                  </Badge>
                                )}
                              </div>

                              {/* Progress bar ratio */}
                              <div className="space-y-1 py-1 mb-3">
                                <div className="flex justify-between text-[11px]" style={{ color: colors.textSecondary }}>
                                  <span>Client Approval Progress:</span>
                                  <span className="font-semibold">{approvedItems} / {totalItems} Approved</span>
                                </div>
                                <div className="w-full h-1.5 rounded-full overflow-hidden" style={{ background: colors.borderLight }}>
                                  <div
                                    className="h-full transition-all duration-300"
                                    style={{
                                      width: `${(approvedItems / (totalItems || 1)) * 100}%`,
                                      background: approvedItems === totalItems ? '#10b981' : colors.primary,
                                    }}
                                  />
                                </div>
                              </div>

                              {/* Comments badge if exists */}
                              {commentCount > 0 && (
                                <div
                                  onClick={() => setSelectedFeedbackSet(set)}
                                  className="flex items-center gap-1.5 px-2 py-1 rounded-md text-[11px] font-medium mb-3 cursor-pointer hover:opacity-85 transition-opacity"
                                  style={{ background: '#fef3c7', color: '#d97706', border: '1px solid #fde68a' }}
                                >
                                  <MessageSquare className="w-3.5 h-3.5" />
                                  <span>{commentCount} Client Feedback Comments</span>
                                  <Eye className="w-3 h-3 ml-auto" />
                                </div>
                              )}

                              {/* Links & Copy Buttons */}
                              <div className="flex items-center gap-1.5 mt-2 pt-2 border-t" style={{ borderColor: colors.borderLight }}>
                                <Button
                                  variant="outline"
                                  size="sm"
                                  className="h-7 text-[11px] flex-1 gap-1"
                                  onClick={() => handleCopyLink(set.token)}
                                >
                                  <Copy className="w-3 h-3" />
                                  Copy Link
                                </Button>
                                <a
                                  href={`${window.location.origin}/public/approval/${set.token}`}
                                  target="_blank"
                                  rel="noopener noreferrer"
                                  className="h-7 px-2.5 rounded flex items-center justify-center gap-1 text-[11px] hover:opacity-90 transition-opacity text-white shrink-0"
                                  style={{ background: colors.primary, textDecoration: 'none' }}
                                >
                                  <Eye className="w-3 h-3" />
                                  Preview
                                </a>
                              </div>
                            </div>
                          );
                        })
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </TabsContent>
      </Tabs>

      {/* =====================================================================
          Client Feedback Comments Viewer Modal
          ===================================================================== */}
      {selectedFeedbackSet && (
        <Dialog open={!!selectedFeedbackSet} onOpenChange={() => setSelectedFeedbackSet(null)}>
          <DialogContent className="sm:max-w-lg max-h-[85vh] flex flex-col p-6">
            <DialogHeader className="shrink-0">
              <DialogTitle className="flex items-center gap-2">
                <MessageSquare className="w-5 h-5 text-amber-500" />
                Client Review Feedback
              </DialogTitle>
              <DialogDescription>
                Detailed comments and approval checklist for set: <b>{selectedFeedbackSet.name}</b>
              </DialogDescription>
            </DialogHeader>

            <div className="flex-1 overflow-y-auto py-4 space-y-4 pr-1 min-h-0">
              {/* Media Comments */}
              <div className="space-y-2">
                <span className="text-xs font-semibold uppercase tracking-wider block" style={{ color: colors.textFaint }}>
                  Media Asset Approvals & Comments
                </span>
                {selectedFeedbackSet.snapshot.media?.map((m) => {
                  const isApproved = selectedFeedbackSet.reviewFeedback?.approvedVisualIds?.includes(m.id);
                  const comment = selectedFeedbackSet.reviewFeedback?.comments?.[m.id];

                  return (
                    <div
                      key={m.id}
                      className="p-3 rounded-lg flex gap-3 items-start border"
                      style={{ background: colors.bgPage, borderColor: colors.border }}
                    >
                      <img
                        src={m.url}
                        alt="Ad Media preview"
                        className="w-16 h-16 rounded object-cover border shrink-0 bg-white"
                      />
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2 mb-1.5">
                          <span className="text-[10px] uppercase font-mono px-1.5 py-0.5 rounded" style={{ background: colors.borderLight, color: colors.textSecondary }}>
                            {m.type}
                          </span>
                          {isApproved ? (
                            <Badge className="bg-emerald-100 text-emerald-700 hover:bg-emerald-100 text-[10px] gap-1 py-0.5 px-2">
                              <CheckCircle2 className="w-3 h-3" /> Approved
                            </Badge>
                          ) : (
                            <Badge className="bg-amber-100 text-amber-700 hover:bg-amber-100 text-[10px] gap-1 py-0.5 px-2">
                              <Clock className="w-3 h-3" /> Commented Only
                            </Badge>
                          )}
                        </div>
                        {comment ? (
                          <p className="text-xs p-2 rounded italic" style={{ background: '#fffbeb', borderLeft: '3px solid #f59e0b', color: colors.text }}>
                            "{comment}"
                          </p>
                        ) : (
                          <p className="text-xs" style={{ color: colors.textMuted }}>No comment left.</p>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>

              {/* Copy Comments */}
              <div className="space-y-2 pt-2">
                <span className="text-xs font-semibold uppercase tracking-wider block" style={{ color: colors.textFaint }}>
                  Ad Copy Approvals & Comments
                </span>
                {selectedFeedbackSet.snapshot.copy?.map((t) => {
                  const isApproved = selectedFeedbackSet.reviewFeedback?.approvedCopyIds?.includes(t.id);
                  const comment = selectedFeedbackSet.reviewFeedback?.comments?.[t.id];

                  return (
                    <div
                      key={t.id}
                      className="p-3 rounded-lg border space-y-2"
                      style={{ background: colors.bgPage, borderColor: colors.border }}
                    >
                      <div className="flex items-center justify-between">
                        <span className="text-xs font-semibold" style={{ color: colors.textSecondary }}>
                          {t.audienceName || 'Ad Variation'} — {t.angleName || 'Angle'}
                        </span>
                        {isApproved ? (
                          <Badge className="bg-emerald-100 text-emerald-700 hover:bg-emerald-100 text-[10px] gap-1 py-0.5 px-2">
                            <CheckCircle2 className="w-3 h-3" /> Approved
                          </Badge>
                        ) : (
                          <Badge className="bg-amber-100 text-amber-700 hover:bg-amber-100 text-[10px] gap-1 py-0.5 px-2">
                            <Clock className="w-3 h-3" /> Commented Only
                          </Badge>
                        )}
                      </div>

                      {/* Snippet preview */}
                      <div className="p-2 rounded bg-white border text-[11px] space-y-1">
                        <p><b>Headline:</b> {t.headline}</p>
                        <p className="line-clamp-2"><b>Body:</b> {t.body}</p>
                      </div>

                      {comment ? (
                        <p className="text-xs p-2 rounded italic" style={{ background: '#fffbeb', borderLeft: '3px solid #f59e0b', color: colors.text }}>
                          "{comment}"
                        </p>
                      ) : (
                        <p className="text-xs text-muted-foreground italic">No comment left.</p>
                      )}
                    </div>
                  );
                })}
              </div>
            </div>

            <DialogFooter className="shrink-0 pt-4 border-t" style={{ borderColor: colors.border }}>
              <Button onClick={() => setSelectedFeedbackSet(null)} className="w-full">
                Close Feedback Viewer
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      )}
    </div>
  );
}
