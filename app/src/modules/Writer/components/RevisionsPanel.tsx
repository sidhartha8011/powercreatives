/**
 * WRITER MODULE — Revisions Panel
 *
 * Right-side drawer showing the active article's revision HISTORY QUEUE —
 * nothing else. Per the 20260715 card: "when you click on the revisions …
 * It should be a history queue" — the AI Review pipeline lives in its OWN
 * panel (AiReviewPanel), not behind a toggle in this drawer.
 *
 * - Revisions come from trpc.writer.revisions (server-persisted articles only).
 * - Restore snapshots the current version first, then swaps the content in
 *   (server-side), and syncs the editor via the active-document atom.
 */

import { useState } from 'react';
import { Clock, History, Loader2, PanelLeftClose, RotateCcw } from 'lucide-react';
import { useAtomValue, useSetAtom } from 'jotai';
import { colors, typography, SectionLabel } from '@/components/shared';
import { trpc } from '@/lib/trpc';
import { toast } from 'sonner';
import { activeDocumentAtom, updateActiveDocumentAtom } from '../store';

interface Props {
  onCollapse?: () => void;
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

export function RevisionsPanel({ onCollapse }: Props) {
  const activeDoc = useAtomValue(activeDocumentAtom);
  const updateDoc = useSetAtom(updateActiveDocumentAtom);

  // History exists for the stored article — local-only drafts (UUID ids from
  // the persistence hook) have no server-side revisions yet.
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

  return (
    <div className="h-full flex flex-col overflow-hidden" style={{ background: colors.bgSurface }}>
      {/* ── Panel Header ────────────────────────────────── */}
      <div
        className="px-4 py-3 shrink-0 flex items-center justify-between"
        style={{ borderBottom: `1px solid ${colors.borderLight}`, background: colors.bgSurface }}
      >
        <div className="flex items-center gap-2">
          <SectionLabel>Revisions</SectionLabel>
          {revisions.length > 0 && (
            <span style={{ fontSize: typography.xs, color: colors.textMuted }}>
              {revisions.length}
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

      {/* ── History queue ────────────────────────────────── */}
      <div className="flex-1 overflow-y-auto px-4 pt-3 pb-4">
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
      </div>
    </div>
  );
}
