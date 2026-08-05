/**
 * FeedbackDialog — modal viewer for client review feedback.
 *
 * Pure presentational component. Receives the approval set whose feedback
 * should be shown (or null to close) and an onClose callback. Owns no
 * data, no mutations.
 *
 * Extracted verbatim from the previous inline dialog in Approvals/index.tsx
 * so the kanban refactor can stay focused on the board itself.
 */

import { CheckCircle2, Clock, MessageSquare } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { colors } from '@/components/shared/design-tokens';

import type { ApprovalSet, CommentEntry, SnapshotAsset } from '../types';

export interface FeedbackDialogProps {
  set: ApprovalSet | null;
  onClose: () => void;
}

export function FeedbackDialog({ set, onClose }: FeedbackDialogProps) {
  if (!set) return null;

  const approvedVisual = new Set(set.reviewFeedback?.approvedVisualIds ?? []);
  const approvedCopy = new Set(set.reviewFeedback?.approvedCopyIds ?? []);
  const comments = set.reviewFeedback?.comments ?? {};

  return (
    <Dialog open onOpenChange={(open) => { if (!open) onClose(); }}>
      <DialogContent className="sm:max-w-lg max-h-[85vh] flex flex-col p-6">
        <DialogHeader className="shrink-0">
          <DialogTitle className="flex items-center gap-2">
            <MessageSquare className="w-5 h-5 text-amber-500" />
            Client Review Feedback
          </DialogTitle>
          <DialogDescription>
            Detailed comments and approval checklist for set: <b>{set.name}</b>
          </DialogDescription>
        </DialogHeader>

        <div className="flex-1 overflow-y-auto py-4 space-y-4 pr-1 min-h-0">
          <FeedbackSection
            label="Media Asset Approvals & Comments"
            items={set.snapshot?.media ?? []}
            approvedIds={approvedVisual}
            comments={comments}
            renderPreview={(m) => (
              <img
                src={m.url}
                alt="Ad Media preview"
                className="w-16 h-16 rounded object-cover border shrink-0 bg-white"
              />
            )}
            renderMeta={(m) => (
              <span
                className="text-[10px] uppercase font-mono px-1.5 py-0.5 rounded"
                style={{ background: colors.borderLight, color: colors.textSecondary }}
              >
                {m.type ?? 'media'}
              </span>
            )}
          />

          <FeedbackSection
            label="Ad Copy Approvals & Comments"
            items={set.snapshot?.copy ?? []}
            approvedIds={approvedCopy}
            comments={comments}
            renderPreview={() => null}
            renderMeta={(t) => (
              <span className="text-xs font-semibold" style={{ color: colors.textSecondary }}>
                {t.audienceName ?? 'Ad Variation'} — {t.angleName ?? 'Angle'}
              </span>
            )}
            renderBody={(t) => (
              <div className="p-2 rounded bg-white border text-[11px] space-y-1">
                <p><b>Headline:</b> {t.headline}</p>
                <p className="line-clamp-2"><b>Body:</b> {t.body}</p>
              </div>
            )}
          />
        </div>

        <DialogFooter className="shrink-0 pt-4 border-t" style={{ borderColor: colors.border }}>
          <Button onClick={onClose} className="w-full">
            Close Feedback Viewer
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ─── Section ─────────────────────────────────────────────────────

interface FeedbackSectionProps {
  label: string;
  items: SnapshotAsset[];
  approvedIds: Set<string>;
  /** Threaded comments per asset id. Each thread is a flat array linked
   *  via parentId; this dialog renders them as a chronological list. */
  comments: Record<string, CommentEntry[]>;
  renderPreview: (item: SnapshotAsset) => React.ReactNode;
  renderMeta: (item: SnapshotAsset) => React.ReactNode;
  renderBody?: (item: SnapshotAsset) => React.ReactNode;
}

function FeedbackSection({
  label,
  items,
  approvedIds,
  comments,
  renderPreview,
  renderMeta,
  renderBody,
}: FeedbackSectionProps) {
  if (items.length === 0) return null;

  return (
    <div className="space-y-2">
      <span className="text-xs font-semibold uppercase tracking-wider block" style={{ color: colors.textFaint }}>
        {label}
      </span>
      {items.map((item) => {
        const isApproved = approvedIds.has(item.id);
        const thread = comments[item.id] ?? [];
        const hasPreview = renderPreview(item) !== null;

        return (
          <div
            key={item.id}
            className="p-3 rounded-lg border space-y-2"
            style={{ background: colors.bgPage, borderColor: colors.border }}
          >
            <div className={hasPreview ? 'flex gap-3 items-start' : ''}>
              {hasPreview && renderPreview(item)}
              <div className="flex-1 min-w-0 space-y-1.5">
                <div className="flex items-center gap-2">
                  {renderMeta(item)}
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

                {renderBody?.(item)}

                {thread.length > 0 ? (
                  <ul className="space-y-1.5">
                    {thread.map((c) => (
                      <li
                        key={c.id}
                        className="text-xs p-2 rounded space-y-1"
                        style={{ background: '#fffbeb', borderLeft: '3px solid #f59e0b', color: colors.text }}
                      >
                        <div className="flex items-center justify-between gap-2">
                          <span className="font-semibold">{c.author}</span>
                          <span className="text-[10px]" style={{ color: colors.textMuted }}>
                            {c.status}
                          </span>
                        </div>
                        <p className="italic">“{c.text}”</p>
                      </li>
                    ))}
                  </ul>
                ) : (
                  <p className="text-xs" style={{ color: colors.textMuted }}>No comment left.</p>
                )}
              </div>
            </div>
          </div>
        );
      })}
    </div>
  );
}
