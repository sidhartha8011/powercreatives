/**
 * WRITER MODULE — AI Review Panel
 *
 * Right-side panel hosting the per-article "AI Review" pipeline: the user
 * optionally types editorial feedback, runs a review, and gets structured
 * suggestions ({find, issue, replacement}) back — each individually
 * applyable (server-side surgical replace) or rejectable.
 *
 * Architecture:
 * - Suggestions come pre-validated from POST /articles/{id}/ai-review — every
 *   `find` was located verbatim in the stored content, so Apply should only
 *   fail if the article changed after the review ran (server returns 409).
 * - Applying goes through POST /articles/{id}/ai-review/apply; the server
 *   persists and returns the updated article, and we push its content into
 *   the active document atom — ReviewEditorCanvas re-syncs external content
 *   changes via setContent.
 * - Reviews target the SAVED article, so the run button requires a
 *   server-persisted document (numeric id from useWriterPersistence).
 * - Accepts an `onCollapse` callback to allow the parent layout to toggle
 *   this panel's visibility (same header pattern as ContextGenerationPanel).
 */

import { useState } from 'react';
import { Check, Clock, History, Loader2, PanelLeftClose, RotateCcw, Sparkles, X } from 'lucide-react';
import { useAtomValue, useSetAtom } from 'jotai';
import { colors, typography, SectionLabel } from '@/components/shared';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { trpc } from '@/lib/trpc';
import { toast } from 'sonner';
import { activeDocumentAtom, updateActiveDocumentAtom } from '../store';

interface Props {
  onCollapse?: () => void;
}

interface ReviewSuggestion {
  find: string;
  issue: string;
  replacement: string;
  status: 'pending' | 'applied' | 'rejected';
}

interface RevisionRow {
  id: number;
  articleId: number;
  title: string;
  source: 'editor' | 'ai-review' | 'restore';
  createdAt: string;
  excerpt: string;
}

// Small inline relative-time helper — no new dependency.
function timeAgo(dateStr: string): string {
  const then = new Date(dateStr).getTime();
  if (Number.isNaN(then)) return '';
  const diffSec = Math.floor((Date.now() - then) / 1000);
  if (diffSec < 5) return 'just now';
  if (diffSec < 60) return `${diffSec}s ago`;
  const diffMin = Math.floor(diffSec / 60);
  if (diffMin < 60) return `${diffMin}m ago`;
  const diffHr = Math.floor(diffMin / 60);
  if (diffHr < 24) return `${diffHr}h ago`;
  const diffDay = Math.floor(diffHr / 24);
  if (diffDay < 30) return `${diffDay}d ago`;
  const diffMonth = Math.floor(diffDay / 30);
  if (diffMonth < 12) return `${diffMonth}mo ago`;
  const diffYear = Math.floor(diffDay / 365);
  return `${diffYear}y ago`;
}

const SOURCE_LABELS: Record<RevisionRow['source'], string> = {
  editor: 'Edited',
  'ai-review': 'AI review',
  restore: 'Restored',
};

export function AiRevisionsPanel({ onCollapse }: Props) {
  const activeDoc = useAtomValue(activeDocumentAtom);
  const updateDoc = useSetAtom(updateActiveDocumentAtom);

  const [activeTab, setActiveTab] = useState<'history' | 'ai-review'>('history');

  const [feedback, setFeedback] = useState('');
  const [suggestions, setSuggestions] = useState<ReviewSuggestion[]>([]);
  const [hasRun, setHasRun] = useState(false);
  const [applyingIndex, setApplyingIndex] = useState<number | null>(null);

  // Reviews run against the stored article — local-only drafts (UUID ids from
  // the persistence hook) have nothing server-side to review yet.
  const serverDocId = activeDoc && /^\d+$/.test(activeDoc.id) ? Number(activeDoc.id) : null;

  const revisionsQuery = trpc.writer.revisions.useQuery(
    { articleId: serverDocId as number },
    { enabled: !!serverDocId },
  );
  const restoreMutation = trpc.writer.revisionRestore.useMutation();
  const [restoringId, setRestoringId] = useState<number | null>(null);

  const revisions: RevisionRow[] = Array.isArray(revisionsQuery.data) ? revisionsQuery.data : [];

  const handleRestore = (revisionId: number) => {
    if (!serverDocId || restoringId !== null) return;
    const confirmed = window.confirm(
      'Restore this revision? Your current version will be saved to history first.',
    );
    if (!confirmed) return;

    setRestoringId(revisionId);
    restoreMutation.mutate(
      { articleId: serverDocId, revisionId },
      {
        onSuccess: (article: any) => {
          if (article && typeof article.content === 'string') {
            updateDoc({
              content: article.content,
              ...(typeof article.title === 'string' ? { title: article.title } : {}),
            });
          }
          toast.success('Revision restored.');
          revisionsQuery.refetch();
          setRestoringId(null);
        },
        onError: (err: any) => {
          toast.error(err.message || 'Could not restore the revision.');
          setRestoringId(null);
        },
      },
    );
  };

  const reviewMutation = trpc.writer.aiReview.useMutation({
    onSuccess: (data: any) => {
      const rows = Array.isArray(data?.suggestions) ? data.suggestions : [];
      setSuggestions(rows.map((r: any) => ({ ...r, status: 'pending' as const })));
      setHasRun(true);
      if (rows.length === 0) {
        toast.info('AI review found nothing to change.');
      }
    },
    onError: (err: any) => {
      toast.error(err.message || 'AI review failed.');
    },
  });

  const applyMutation = trpc.writer.aiReviewApply.useMutation();

  const handleRun = () => {
    if (!serverDocId) return;
    setSuggestions([]);
    setHasRun(false);
    reviewMutation.mutate({ id: serverDocId, feedback: feedback.trim() });
  };

  const handleApply = (index: number) => {
    if (!serverDocId || applyingIndex !== null) return;
    const s = suggestions[index];
    setApplyingIndex(index);
    applyMutation.mutate(
      { id: serverDocId, find: s.find, replacement: s.replacement },
      {
        onSuccess: (article: any) => {
          // Server persisted the replacement — sync the editor to its content.
          if (typeof article?.content === 'string') {
            updateDoc({ content: article.content });
          }
          setSuggestions((prev) =>
            prev.map((row, i) => (i === index ? { ...row, status: 'applied' } : row)),
          );
          setApplyingIndex(null);
        },
        onError: (err: any) => {
          toast.error(err.message || 'Could not apply the suggestion.');
          setApplyingIndex(null);
        },
      },
    );
  };

  const handleReject = (index: number) => {
    setSuggestions((prev) =>
      prev.map((row, i) => (i === index ? { ...row, status: 'rejected' } : row)),
    );
  };

  const pendingCount = suggestions.filter((s) => s.status === 'pending').length;

  return (
    <div className="h-full flex flex-col overflow-hidden" style={{ background: colors.bgSurface }}>
      {/* ── Panel Header ────────────────────────────────── */}
      <div
        className="px-4 py-3 shrink-0 flex items-center justify-between"
        style={{ borderBottom: `1px solid ${colors.borderLight}`, background: colors.bgSurface }}
      >
        <div className="flex items-center gap-2">
          <SectionLabel>AI Review</SectionLabel>
          {pendingCount > 0 && (
            <span style={{ fontSize: typography.xs, color: colors.textMuted }}>
              {pendingCount} pending
            </span>
          )}
        </div>
        <button
          onClick={onCollapse}
          style={{
            padding: 4,
            borderRadius: 4,
            background: 'transparent',
            border: 'none',
            cursor: 'pointer',
            color: colors.textMuted,
            transition: 'background-color 0.15s',
          }}
          title="Collapse Panel"
        >
          <PanelLeftClose style={{ width: 14, height: 14 }} />
        </button>
      </div>

      {/* ── Tabs ─────────────────────────────────────────── */}
      <Tabs
        value={activeTab}
        onValueChange={(v) => setActiveTab(v as 'history' | 'ai-review')}
        className="flex-1 flex flex-col overflow-hidden gap-0"
      >
        <div className="px-4 pt-2 shrink-0">
          <TabsList className="grid w-full grid-cols-2 h-8">
            <TabsTrigger value="history" className="flex items-center gap-1.5">
              <History style={{ width: 12, height: 12 }} />
              History
            </TabsTrigger>
            <TabsTrigger value="ai-review" className="flex items-center gap-1.5">
              <Sparkles style={{ width: 12, height: 12 }} />
              AI Review
            </TabsTrigger>
          </TabsList>
        </div>

        {/* ── History Tab ──────────────────────────────── */}
        <TabsContent value="history" className="flex-1 overflow-y-auto px-4 pt-3 pb-4 m-0">
          {!serverDocId ? (
            <div className="flex flex-col items-center justify-center h-full text-center">
              <History style={{ width: 32, height: 32, color: colors.borderMedium, marginBottom: 8 }} />
              <span style={{ fontSize: typography.sm, color: colors.textMuted }}>No history yet</span>
              <span style={{ fontSize: typography.xs, color: colors.textFaint, marginTop: 4 }}>
                The article must be saved before its history is available.
              </span>
            </div>
          ) : revisionsQuery.isLoading ? (
            <div className="flex flex-col items-center justify-center h-full text-center">
              <Loader2
                style={{ width: 20, height: 20, color: colors.textMuted }}
                className="animate-spin"
              />
            </div>
          ) : revisionsQuery.isError ? (
            <div className="flex flex-col items-center justify-center h-full text-center">
              <span style={{ fontSize: typography.sm, color: colors.textMuted }}>
                Couldn't load revision history.
              </span>
              <span style={{ fontSize: typography.xs, color: colors.textFaint, marginTop: 4 }}>
                {(revisionsQuery.error as any)?.message || 'Please try again.'}
              </span>
            </div>
          ) : revisions.length === 0 ? (
            <div className="flex flex-col items-center justify-center h-full text-center">
              <History style={{ width: 32, height: 32, color: colors.borderMedium, marginBottom: 8 }} />
              <span style={{ fontSize: typography.sm, color: colors.textMuted }}>No revisions yet</span>
              <span style={{ fontSize: typography.xs, color: colors.textFaint, marginTop: 4 }}>
                They're captured every time you save changes.
              </span>
            </div>
          ) : (
            <div className="flex flex-col gap-2">
              {revisions.map((rev) => (
                <div
                  key={rev.id}
                  style={{
                    border: `1px solid ${colors.borderLight}`,
                    borderRadius: 8,
                    padding: 10,
                    background: colors.bgSurface,
                  }}
                >
                  <div className="flex items-center justify-between" style={{ marginBottom: 6 }}>
                    <span
                      style={{
                        fontSize: typography.xs,
                        fontWeight: 600,
                        color: colors.textMuted,
                        padding: '2px 6px',
                        borderRadius: 4,
                        background: colors.borderLight,
                      }}
                    >
                      {SOURCE_LABELS[rev.source] ?? rev.source}
                    </span>
                    <span
                      className="flex items-center gap-1"
                      style={{ fontSize: typography.xs, color: colors.textFaint }}
                    >
                      <Clock style={{ width: 10, height: 10 }} />
                      {timeAgo(rev.createdAt)}
                    </span>
                  </div>
                  <div style={{ fontSize: typography.xs, color: colors.text }}>{rev.excerpt}</div>
                  <div style={{ marginTop: 8 }}>
                    <button
                      onClick={() => handleRestore(rev.id)}
                      disabled={restoringId !== null}
                      className="flex items-center gap-1"
                      style={{
                        padding: '3px 8px',
                        borderRadius: 5,
                        border: `1px solid ${colors.borderLight}`,
                        cursor: restoringId !== null ? 'default' : 'pointer',
                        background: 'transparent',
                        color: colors.textMuted,
                        fontSize: typography.xs,
                        fontWeight: 600,
                        opacity: restoringId !== null && restoringId !== rev.id ? 0.5 : 1,
                      }}
                    >
                      {restoringId === rev.id ? (
                        <Loader2 style={{ width: 10, height: 10 }} className="animate-spin" />
                      ) : (
                        <RotateCcw style={{ width: 10, height: 10 }} />
                      )}
                      Restore
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </TabsContent>

        {/* ── AI Review Tab ────────────────────────────── */}
        <TabsContent value="ai-review" className="flex-1 flex flex-col overflow-hidden m-0">
          {/* ── Review Controls ─────────────────────────────── */}
          <div className="px-4 pt-3 pb-2 shrink-0 flex flex-col gap-2">
            <textarea
              value={feedback}
              onChange={(e) => setFeedback(e.target.value)}
              placeholder="Optional feedback, e.g. “make the tone more formal” — leave empty for a general review"
              rows={2}
              style={{
                width: '100%',
                resize: 'vertical',
                fontSize: typography.xs,
                padding: '6px 8px',
                borderRadius: 6,
                border: `1px solid ${colors.borderLight}`,
                background: colors.bgSurface,
                color: colors.text,
              }}
            />
            <button
              onClick={handleRun}
              disabled={!serverDocId || reviewMutation.isPending}
              className="flex items-center justify-center gap-1.5"
              style={{
                padding: '6px 10px',
                borderRadius: 6,
                border: 'none',
                cursor: !serverDocId || reviewMutation.isPending ? 'default' : 'pointer',
                background: colors.primary,
                color: '#fff',
                fontSize: typography.xs,
                fontWeight: 600,
                opacity: !serverDocId || reviewMutation.isPending ? 0.5 : 1,
              }}
              title={serverDocId ? 'Run AI review on this article' : 'Save the article first'}
            >
              {reviewMutation.isPending ? (
                <Loader2 style={{ width: 12, height: 12 }} className="animate-spin" />
              ) : (
                <Sparkles style={{ width: 12, height: 12 }} />
              )}
              {reviewMutation.isPending ? 'Reviewing…' : 'Run AI Review'}
            </button>
            {!serverDocId && (
              <span style={{ fontSize: typography.xs, color: colors.textFaint }}>
                The article must be saved before it can be reviewed.
              </span>
            )}
          </div>

          {/* ── Suggestions List ────────────────────────────── */}
          <div className="flex-1 overflow-y-auto px-4 pb-4">
            {suggestions.length === 0 ? (
              <div className="flex flex-col items-center justify-center h-full text-center">
                <Sparkles style={{ width: 32, height: 32, color: colors.borderMedium, marginBottom: 8 }} />
                <span style={{ fontSize: typography.sm, color: colors.textMuted }}>
                  {hasRun ? 'No suggestions — the article looks good' : 'No review yet'}
                </span>
                <span style={{ fontSize: typography.xs, color: colors.textFaint, marginTop: 4 }}>
                  {hasRun
                    ? 'Run again with specific feedback to dig deeper'
                    : 'Run an AI review to get suggested edits you can apply one by one'}
                </span>
              </div>
            ) : (
              <div className="flex flex-col gap-2">
                {suggestions.map((s, i) => (
                  <div
                    key={`${i}-${s.find.slice(0, 24)}`}
                    style={{
                      border: `1px solid ${colors.borderLight}`,
                      borderRadius: 8,
                      padding: 10,
                      opacity: s.status === 'rejected' ? 0.45 : 1,
                      background: colors.bgSurface,
                    }}
                  >
                    <div style={{ fontSize: typography.xs, color: colors.textMuted, marginBottom: 6 }}>
                      {s.issue || 'Suggested edit'}
                    </div>
                    <div style={{ fontSize: typography.xs, color: colors.text }}>
                      <span style={{ textDecoration: 'line-through', opacity: 0.6 }}>{s.find}</span>
                    </div>
                    <div style={{ fontSize: typography.xs, color: colors.text, marginTop: 2 }}>
                      {s.replacement || <em>(remove)</em>}
                    </div>
                    <div className="flex items-center gap-2" style={{ marginTop: 8 }}>
                      {s.status === 'pending' ? (
                        <>
                          <button
                            onClick={() => handleApply(i)}
                            disabled={applyingIndex !== null}
                            className="flex items-center gap-1"
                            style={{
                              padding: '3px 8px',
                              borderRadius: 5,
                              border: 'none',
                              cursor: applyingIndex !== null ? 'default' : 'pointer',
                              background: colors.primary,
                              color: '#fff',
                              fontSize: typography.xs,
                              fontWeight: 600,
                              opacity: applyingIndex !== null && applyingIndex !== i ? 0.5 : 1,
                            }}
                          >
                            {applyingIndex === i ? (
                              <Loader2 style={{ width: 10, height: 10 }} className="animate-spin" />
                            ) : (
                              <Check style={{ width: 10, height: 10 }} />
                            )}
                            Apply
                          </button>
                          <button
                            onClick={() => handleReject(i)}
                            className="flex items-center gap-1"
                            style={{
                              padding: '3px 8px',
                              borderRadius: 5,
                              border: `1px solid ${colors.borderLight}`,
                              cursor: 'pointer',
                              background: 'transparent',
                              color: colors.textMuted,
                              fontSize: typography.xs,
                            }}
                          >
                            <X style={{ width: 10, height: 10 }} />
                            Reject
                          </button>
                        </>
                      ) : (
                        <span
                          style={{
                            fontSize: typography.xs,
                            fontWeight: 600,
                            color: s.status === 'applied' ? colors.primary : colors.textFaint,
                          }}
                        >
                          {s.status === 'applied' ? 'Applied' : 'Rejected'}
                        </span>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </TabsContent>
      </Tabs>
    </div>
  );
}
