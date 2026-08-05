/**
 * ApprovalSetModal — an approval card, opened.
 *
 * This is THE CLIENT VIEW, not a lookalike: it renders `ApprovalSetContents`,
 * the same component the public page renders, with `isTeamMember` switching on
 * the affordances `CreativeAssetCard` already carries. What the team sees is
 * what the client sees, plus the ability to act on it.
 *
 * It replaced a branch that opened `CardDocumentView` — the Notion page — for a
 * card whose snapshot happened to hold a document. That was wrong: a document is
 * a SUB-ITEM of a card, never the card itself, so a card holding a document plus
 * three copies showed the document and silently hid the rest.
 *
 * Shell geometry is the Notion card's (`.pcm-notion-overlay` / `-modal`), on the
 * owner's instruction that the modal must not get bigger.
 */

import { useCallback, useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import { Share2, X } from 'lucide-react';

import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { ApprovalSharePanel } from '@/components/shared/ApprovalSharePanel';
import { buildPublicBoardUrl } from '@/components/shared/approvalSets';
import { trpc } from '@/lib/trpc';

import { isPostSubmitStatus, type ApprovalSet, type CommentEntry } from '../types';
import { useSetAssets } from '../hooks/useSetAssets';
import { ApprovalSetContents } from './ApprovalSetContents';
import { ClientCommentInspector } from './ClientCommentInspector';
import { isVideoAsset } from './ClientReviewPage';
import { setColumns } from '../kanban/setColumns';

export interface ApprovalSetModalProps {
  /**
   * The BOARD ROW. Always available the instant the card is clicked, and it
   * carries the name and status — which is why the modal can open immediately,
   * correctly titled, while the contents are still in flight.
   */
  row: ApprovalSet;
  /**
   * The FULL set, snapshot included. Absent until the fetch lands.
   *
   * The waiting state renders THIS SAME SHELL. It previously fell back to
   * `CardDocumentView` — the Notion page — so on a slow host the first thing you
   * saw on clicking a card was the document view, for as long as the request
   * took. On this machine that is tens of seconds, which is why the card looked
   * like it still opened as a document.
   */
  set?: ApprovalSet;
  onClose: () => void;
  /** Re-read the set after a mutation. */
  onChanged?: () => void;
  /**
   * Scroll to and highlight one asset once the contents land — set when the card
   * was opened from a sub-asset row, so "click the item" lands ON the item
   * rather than at the top of a card that may hold twenty.
   */
  focusAssetId?: string | null;
}

const laneOptions = setColumns.map((c) => ({ value: c.id as string, label: c.label }));

export function ApprovalSetModal({ row, set, onClose, onChanged, focusAssetId }: ApprovalSetModalProps) {
  const isLoading = !set;
  const { mediaAssets, copyAssets, allMergedAssets, primaryMediaUrl } = useSetAssets(set, isVideoAsset);

  /**
   * A card past client review is locked: the server refuses approval changes
   * (`POST_SUBMIT_STATUSES`), so the UI must refuse them too. This was
   * hardcoded `false`, which showed Approve as live on a launch/live/archived
   * card and let the button lie about what the server would accept.
   */
  const isLocked = isPostSubmitStatus(row.status);

  // Approval state is seeded from the stored feedback and kept locally so a
  // click responds immediately; the server is the authority and `onChanged`
  // re-reads it.
  const fb = set?.reviewFeedback;
  const [approvedVisualIds, setVisual] = useState<string[]>([]);
  const [approvedCopyIds, setCopy] = useState<string[]>([]);
  const [approvedArticleIds, setArticle] = useState<string[]>([]);
  const [approvedCustomIds, setCustom] = useState<string[]>([]);
  const [comments, setComments] = useState<Record<string, CommentEntry[]>>({});
  const [activeAssetId, setActiveAssetId] = useState<string | null>(null);

  // Seed once the fetch lands. Keyed on the set id so reopening a different
  // card cannot inherit the previous card's approvals.
  useEffect(() => {
    if (!set) return;
    setVisual(set.reviewFeedback?.approvedVisualIds ?? []);
    setCopy(set.reviewFeedback?.approvedCopyIds ?? []);
    setArticle(set.reviewFeedback?.approvedArticleIds ?? []);
    setCustom(set.reviewFeedback?.approvedCustomIds ?? []);
    setComments((set.reviewFeedback?.comments as Record<string, CommentEntry[]>) ?? {});
  }, [set?.id, fb]);

  useEffect(() => {
    const handleKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', handleKey);
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', handleKey);
      document.body.style.overflow = '';
    };
  }, [onClose]);

  const approveMutation = trpc.approvals.approveAsset.useMutation({
    onSuccess: () => onChanged?.(),
    onError: (err: any) => console.error('Approve failed:', err?.message),
  });

  /**
   * Approve on the client's behalf.
   *
   * Uses the existing token-scoped route — there is no ownership-scoped approve
   * endpoint, and the admin holds the token, so no new route was needed. That
   * route is rate-limited server-side, which is why state flips locally first
   * and the request fires once per toggle rather than per render.
   */
  const handleToggleApprove = useCallback((id: string) => {
    if (!set || isLocked) return;
    const inBucket = (arr: any[] | undefined) => !!arr?.some((a: any) => a.id === id);
    const [list, setList, type] =
      inBucket(set.snapshot?.media) ? [approvedVisualIds, setVisual, 'media'] as const
      : inBucket(set.snapshot?.articles) ? [approvedArticleIds, setArticle, 'article'] as const
      : inBucket(set.snapshot?.custom) ? [approvedCustomIds, setCustom, 'custom'] as const
      : [approvedCopyIds, setCopy, 'copy'] as const;

    const nextApproved = !list.includes(id);
    setList(nextApproved ? [...list, id] : list.filter((x) => x !== id));
    approveMutation.mutate({ token: row.token, assetId: id, type, approved: nextApproved });
  }, [set, isLocked, row.token, approvedVisualIds, approvedCopyIds, approvedArticleIds, approvedCustomIds, approveMutation]);

  const activeAsset = useMemo(
    () => (activeAssetId ? allMergedAssets.find((a) => a.id === activeAssetId) ?? null : null),
    [activeAssetId, allMergedAssets]
  );

  const handleThreadChange = useCallback((assetId: string, thread: CommentEntry[]) => {
    setComments((prev) => ({ ...prev, [assetId]: thread }));
  }, []);

  /**
   * Bring the requested asset into view once the contents have rendered.
   *
   * Runs only after loading finishes, because the element does not exist before
   * then. `block: 'center'` rather than the default so the asset lands where the
   * eye already is, not jammed against the top edge of the scroll container.
   */
  useEffect(() => {
    if (isLoading || !focusAssetId) return;
    const el = document.querySelector(`[data-asset-id="${CSS.escape(focusAssetId)}"]`);
    if (el) el.scrollIntoView({ block: 'center', behavior: 'smooth' });
  }, [isLoading, focusAssetId, allMergedAssets.length]);

  const shareUrl = useMemo(() => buildPublicBoardUrl(row.token), [row.token]);

  return createPortal(
    <div className="pcm-notion-overlay" onClick={onClose} role="dialog" aria-label={`${row.name} — approval card`}>
      <div className="pcm-notion-modal" onClick={(e) => e.stopPropagation()}>
        <div className="pcm-notion-topbar">
          <Popover>
            <PopoverTrigger asChild>
              <button type="button" className="pcm-notion-iconbtn" aria-label="Share this approval card">
                <Share2 className="w-[16px] h-[16px]" />
              </button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-[420px] p-0">
              {/* The EXISTING share popover, unchanged. */}
              <ApprovalSharePanel
                setId={row.id}
                shareUrl={shareUrl}
                defaultEmail={row.clientEmail}
                lanes={laneOptions}
                defaultLaneAfterSend="client"
                onSaved={() => onChanged?.()}
              />
            </PopoverContent>
          </Popover>

          <button type="button" className="pcm-notion-iconbtn" onClick={onClose} aria-label="Close">
            <X className="w-[18px] h-[18px]" />
          </button>
        </div>

        <div className="pcm-notion-page">
          <div className="pcm-set-modal-body">
            <h1 className="pcm-notion-title">{row.name}</h1>

            {isLoading ? (
              <p className="pcm-notion-loading">Loading this card…</p>
            ) : (
              <ApprovalSetContents
                assets={allMergedAssets}
                approvedVisualIds={approvedVisualIds}
                approvedCopyIds={approvedCopyIds}
                approvedArticleIds={approvedArticleIds}
                approvedCustomIds={approvedCustomIds}
                comments={comments}
                isTeamMember
                isSubmitted={isLocked}
                brandName={set?.snapshot?.brandName || ''}
                brandLogoUrl={set?.snapshot?.brandLogoUrl}
                setBrandName={set?.snapshot?.brandName}
                setProjectName={set?.snapshot?.projectName}
                copyAssets={copyAssets}
                mediaAssets={mediaAssets}
                primaryMediaUrl={primaryMediaUrl}
                onApprove={handleToggleApprove}
                onAssetUpdate={onChanged}
                onOpenComments={setActiveAssetId}
                emptyLabel="This card has no assets yet."
              />
            )}
          </div>
        </div>
      </div>

      {/* Comment threads. This was a no-op stub, so Comment did nothing at all
          in the admin. It is the same inspector the client page uses. */}
      {activeAsset && (
        <ClientCommentInspector
          asset={activeAsset.data}
          type={activeAsset.type}
          thread={comments[activeAsset.id] || []}
          authorName="Team"
          isTeamMember
          isReadOnly={false}
          onClose={() => setActiveAssetId(null)}
          onThreadChange={handleThreadChange}
        />
      )}
    </div>,
    document.body
  );
}
