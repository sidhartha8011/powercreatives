/**
 * ApprovalSetContents — the contents of an approval card.
 *
 * Extracted from ClientReviewPage so there is ONE renderer for a card's assets
 * and TWO consumers: the public client page, and the admin modal. The admin does
 * not get a lookalike of the client view — it gets the client view, with
 * `isTeamMember` switching on the edit affordances `CreativeAssetCard` already
 * carries.
 *
 * It renders the assets and nothing around them: no hero, no toolbar, no submit
 * flow. Those belong to the page, which is why they stayed there.
 */

import type { CommentEntry } from '../types';
import type { MergedAsset } from '../hooks/useSetAssets';
import { CreativeAssetCard } from './CreativeAssetCard';

export interface ApprovalSetContentsProps {
  /** The assets to render, already filtered by the host. */
  assets: MergedAsset[];
  approvedVisualIds: string[];
  approvedCopyIds: string[];
  approvedArticleIds: string[];
  approvedCustomIds: string[];
  comments: Record<string, CommentEntry[]>;
  /** Team members see edit affordances; clients do not. */
  isTeamMember: boolean;
  /** Locked (submitted / post-submit) — approval controls disable. */
  isSubmitted: boolean;
  /** Display brand name, may carry a fallback. */
  brandName: string;
  brandLogoUrl?: string | null;
  /** RAW mapping for the opened document's property rows — no fallbacks. */
  setBrandName?: string | null;
  setProjectName?: string | null;
  /** Copy cards with no partner image fall back to this. */
  copyAssets: any[];
  mediaAssets: any[];
  primaryMediaUrl: string;
  onApprove: (id: string) => void;
  onAssetUpdate?: () => void;
  onOpenComments: (id: string) => void;
  /** Shown when the active filter matches nothing. */
  emptyLabel?: string;
  /**
   * The set's share token. Passed down because the asset-update route is
   * token-scoped and no card may read it from the URL — that only ever worked
   * on the public page.
   */
  publicToken?: string;
  /**
   * Where an opened document sheet is portalled. The admin modal supplies its
   * own content element; the public client page leaves it undefined and the
   * sheet portals to `document.body` as before.
   */
  documentContainer?: HTMLElement | null;
}

export function ApprovalSetContents({
  assets,
  approvedVisualIds,
  approvedCopyIds,
  approvedArticleIds,
  approvedCustomIds,
  comments,
  isTeamMember,
  isSubmitted,
  brandName,
  brandLogoUrl,
  setBrandName,
  setProjectName,
  copyAssets,
  mediaAssets,
  primaryMediaUrl,
  onApprove,
  onAssetUpdate,
  onOpenComments,
  emptyLabel = 'No assets found in this category.',
  publicToken,
  documentContainer,
}: ApprovalSetContentsProps) {
  if (assets.length === 0) {
    return (
      <div className="pcm-empty-state">
        <p>{emptyLabel}</p>
      </div>
    );
  }

  return (
    <div className="pcm-grid transition-all duration-300">
      {assets.map((item) => {
        const isApproved = item.type === 'media'
          ? approvedVisualIds.includes(item.id)
          : item.type === 'article'
            ? approvedArticleIds.includes(item.id)
            : item.type === 'custom'
              ? approvedCustomIds.includes(item.id)
              : approvedCopyIds.includes(item.id);

        const threadForAsset = comments[item.id] || [];
        const userRole = isTeamMember ? 'team' : 'client';
        const hasNewComment = threadForAsset.some((c) => {
          const isAuthorTeam = c.author === 'Team';
          const isSelf = (isTeamMember && isAuthorTeam) || (!isTeamMember && !isAuthorTeam);
          if (isSelf) return false;
          return !c.readBy?.includes(userRole);
        });

        // Pair a copy card with a corresponding media item.
        let pairedMediaUrl: string | null = null;
        let copyIndex: number | undefined;
        if (item.type === 'copy') {
          copyIndex = copyAssets.findIndex((c: any) => c.id === item.id);
          pairedMediaUrl = (mediaAssets.length > 0 && copyIndex !== -1)
            ? mediaAssets[copyIndex % mediaAssets.length]?.url
            : primaryMediaUrl;
        }

        return (
          <CreativeAssetCard
            key={item.id}
            asset={item.data}
            type={item.type}
            isApproved={isApproved}
            commentCount={threadForAsset.length}
            hasNewComment={hasNewComment}
            onApprove={onApprove}
            brandLogoUrl={brandLogoUrl}
            brandName={brandName}
            setBrandName={setBrandName}
            setProjectName={setProjectName}
            pairedMediaUrl={pairedMediaUrl}
            isSubmitted={isSubmitted}
            isTeamMember={isTeamMember}
            publicToken={publicToken}
            onAssetUpdate={onAssetUpdate}
            onOpenComments={onOpenComments}
            copyIndex={copyIndex}
            documentContainer={documentContainer}
          />
        );
      })}
    </div>
  );
}
