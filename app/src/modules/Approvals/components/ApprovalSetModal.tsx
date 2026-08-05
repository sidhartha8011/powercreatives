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

import type { ApprovalSet, CommentEntry } from '../types';
import { useSetAssets } from '../hooks/useSetAssets';
import { ApprovalSetContents } from './ApprovalSetContents';
import { isVideoAsset } from './ClientReviewPage';
import { setColumns } from '../kanban/setColumns';

export interface ApprovalSetModalProps {
  /** The FULL set — snapshot included. A board row alone is not enough. */
  set: ApprovalSet;
  onClose: () => void;
  /** Re-read the set after a mutation. */
  onChanged?: () => void;
}

const laneOptions = setColumns.map((c) => ({ value: c.id as string, label: c.label }));

export function ApprovalSetModal({ set, onClose, onChanged }: ApprovalSetModalProps) {
  const { mediaAssets, copyAssets, allMergedAssets, primaryMediaUrl } = useSetAssets(set, isVideoAsset);

  // Approval state is seeded from the stored feedback and kept locally so a
  // click responds immediately; the server is the authority and `onChanged`
  // re-reads it.
  const fb = set.reviewFeedback;
  const [approvedVisualIds, setVisual] = useState<string[]>(fb?.approvedVisualIds ?? []);
  const [approvedCopyIds, setCopy] = useState<string[]>(fb?.approvedCopyIds ?? []);
  const [approvedArticleIds, setArticle] = useState<string[]>(fb?.approvedArticleIds ?? []);
  const [approvedCustomIds, setCustom] = useState<string[]>(fb?.approvedCustomIds ?? []);

  const comments = useMemo<Record<string, CommentEntry[]>>(
    () => (fb?.comments as Record<string, CommentEntry[]>) ?? {},
    [fb]
  );

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
   * and the request is fired once per toggle rather than per render.
   */
  const handleToggleApprove = useCallback((id: string) => {
    const inBucket = (arr: any[] | undefined) => !!arr?.some((a: any) => a.id === id);
    const [list, setList, type] =
      inBucket(set.snapshot?.media) ? [approvedVisualIds, setVisual, 'media'] as const
      : inBucket(set.snapshot?.articles) ? [approvedArticleIds, setArticle, 'article'] as const
      : inBucket(set.snapshot?.custom) ? [approvedCustomIds, setCustom, 'custom'] as const
      : [approvedCopyIds, setCopy, 'copy'] as const;

    const nextApproved = !list.includes(id);
    setList(nextApproved ? [...list, id] : list.filter((x) => x !== id));
    approveMutation.mutate({ token: set.token, assetId: id, type, approved: nextApproved });
  }, [set, approvedVisualIds, approvedCopyIds, approvedArticleIds, approvedCustomIds, approveMutation]);

  const shareUrl = useMemo(() => buildPublicBoardUrl(set.token), [set.token]);

  return createPortal(
    <div className="pcm-notion-overlay" onClick={onClose} role="dialog" aria-label={`${set.name} — approval card`}>
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
                setId={set.id}
                shareUrl={shareUrl}
                defaultEmail={set.clientEmail}
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
            <h1 className="pcm-notion-title">{set.name}</h1>
            <ApprovalSetContents
              assets={allMergedAssets}
              approvedVisualIds={approvedVisualIds}
              approvedCopyIds={approvedCopyIds}
              approvedArticleIds={approvedArticleIds}
              approvedCustomIds={approvedCustomIds}
              comments={comments}
              isTeamMember
              isSubmitted={false}
              brandName={set.snapshot?.brandName || ''}
              brandLogoUrl={set.snapshot?.brandLogoUrl}
              setBrandName={set.snapshot?.brandName}
              setProjectName={set.snapshot?.projectName}
              copyAssets={copyAssets}
              mediaAssets={mediaAssets}
              primaryMediaUrl={primaryMediaUrl}
              onApprove={handleToggleApprove}
              onAssetUpdate={onChanged}
              onOpenComments={() => { /* threads open from the asset card itself */ }}
              emptyLabel="This card has no assets yet."
            />
          </div>
        </div>
      </div>
    </div>,
    document.body
  );
}
