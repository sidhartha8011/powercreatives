import { useState, useCallback, useMemo, useEffect } from 'react';
import { Check, HelpCircle } from 'lucide-react';
import { toast } from 'sonner';

import { Spinner } from '@/components/ui/spinner';
import { Button } from '@/components/ui/button';

import { trpc } from '@/lib/trpc';

import { isPostSubmitStatus } from '../types';

// Standalone client-facing design system — isolated from admin SPA tokens
import '../client-review.css';

// Import newly created reusable component modules
import { ClientStatusToolbar } from './ClientStatusToolbar';
import { CreativeAssetCard } from './CreativeAssetCard';
import { ClientCommentInspector, type CommentEntry } from './ClientCommentInspector';

interface ClientReviewPageProps {
  token: string;
}

/**
 * Detects whether a media asset is a video based on MIME type or file extension.
 * Single source of truth — eliminates duplication across counts, filters, and card components.
 */
export function isVideoAsset(item: { mimeType?: string; url?: string }): boolean {
  return !!(
    item.mimeType?.startsWith('video/') ||
    item.url?.endsWith('.mp4') ||
    item.url?.endsWith('.mov') ||
    item.url?.endsWith('.webm')
  );
}

/** localStorage key factory for draft persistence per token */
const getDraftKey = (token: string) => `pcm-review-draft-${token}`;

/** Load draft from localStorage (returns null if none exists) */
function loadDraft(token: string) {
  try {
    const raw = localStorage.getItem(getDraftKey(token));
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}

/** Save draft to localStorage */
function saveDraft(token: string, data: {
  approvedVisualIds: string[];
  approvedCopyIds: string[];
  approvedArticleIds: string[];
  approvedCustomIds: string[];
  comments: Record<string, CommentEntry[]>;
  clientName: string;
}) {
  try {
    localStorage.setItem(getDraftKey(token), JSON.stringify(data));
  } catch {
    // Storage full or blocked — fail silently, draft is a convenience feature
  }
}

/** Clear draft after successful submission */
function clearDraft(token: string) {
  try {
    localStorage.removeItem(getDraftKey(token));
  } catch {
    // Ignore
  }
}

export function ClientReviewPage({ token }: ClientReviewPageProps) {
  // Fetch public set data by token
  const { data: set, isLoading, error } = trpc.approvals.getPublicSet.useQuery({ token }) as any;

  // Restore draft from localStorage (if user refreshed mid-review)
  const draft = useMemo(() => loadDraft(token), [token]);

  // Local Review State — initialized from draft if available
  const [approvedVisualIds, setApprovedVisualIds] = useState<string[]>(draft?.approvedVisualIds ?? []);
  const [approvedCopyIds, setApprovedCopyIds] = useState<string[]>(draft?.approvedCopyIds ?? []);
  const [approvedArticleIds, setApprovedArticleIds] = useState<string[]>(draft?.approvedArticleIds ?? []);
  const [approvedCustomIds, setApprovedCustomIds] = useState<string[]>(draft?.approvedCustomIds ?? []);
  // Threaded comments: array of CommentEntry per asset ID
  const [comments, setComments] = useState<Record<string, CommentEntry[]>>(draft?.comments ?? {});
  const [activeAssetIdForComment, setActiveAssetIdForComment] = useState<string | null>(null);
  const [clientName, setClientName] = useState(draft?.clientName ?? '');
  const [isSubmitted, setIsSubmitted] = useState(false);
  const [activeFilter, setActiveFilter] = useState<'all' | 'images' | 'videos' | 'copy' | 'articles' | 'custom'>('all');

  // Server-persisted truth: set has been submitted and entered the team pipeline.
  // Survives page refresh (unlike isSubmitted, which is local React state).
  const isReadOnly = isPostSubmitStatus(set?.status);

  // Combined lockdown: either we just submitted in this session, or the server
  // says the set is past the client-review phase. Every interactivity guard,
  // every "thanks" view, and the read-only props to children must consult this
  // instead of isSubmitted alone — otherwise refresh-then-re-submit re-fires
  // the outbound webhook.
  const isLocked = isSubmitted || isReadOnly;

  // Check if current user is a logged-in team member
  const isTeamMember = useMemo(() => {
    const config = (window as any).pcmConfig;
    return !!config?.user?.isLoggedIn;
  }, []);

  // Auto-save draft to localStorage on every state change
  useEffect(() => {
    if (isLocked) return;
    saveDraft(token, { approvedVisualIds, approvedCopyIds, approvedArticleIds, approvedCustomIds, comments, clientName });
  }, [token, approvedVisualIds, approvedCopyIds, approvedArticleIds, approvedCustomIds, comments, clientName, isLocked]);

  // Hydrate approved/comment state from server draft or completed review feedback
  // Handles both legacy string-per-asset format and new array-of-objects format
  useEffect(() => {
    if (set?.reviewFeedback) {
      setApprovedVisualIds(set.reviewFeedback.approvedVisualIds || []);
      setApprovedCopyIds(set.reviewFeedback.approvedCopyIds || []);
      setApprovedArticleIds(set.reviewFeedback.approvedArticleIds || []);
      setApprovedCustomIds(set.reviewFeedback.approvedCustomIds || []);

      // Migrate legacy comments (string) to new format (CommentEntry[])
      const rawComments = set.reviewFeedback.comments || {};
      const migrated: Record<string, CommentEntry[]> = {};
      for (const [assetId, value] of Object.entries(rawComments)) {
        if (typeof value === 'string') {
          // Legacy single-string → wrap in an array
          migrated[assetId] = [{
            id: `legacy-${assetId}`,
            author: 'Client',
            text: value,
            createdAt: new Date().toISOString(),
            status: 'New',
            parentId: null,
          }];
        } else if (Array.isArray(value)) {
          migrated[assetId] = value as CommentEntry[];
        }
      }
      setComments(migrated);
    }
  }, [set?.reviewFeedback]);

  // Save draft mutation (autosave)
  const saveDraftMutation = trpc.approvals.saveReviewDraft.useMutation({
    onError: (err: any) => {
      console.error('Failed to autosave review draft:', err.message);
    },
  });

  // Debounced autosave effect to synchronize approvals and comments in real-time
  useEffect(() => {
    if (isLocked || !set || set.status !== 'draft') return;

    const timer = setTimeout(() => {
      saveDraftMutation.mutate({
        token,
        feedback: {
          approvedVisualIds,
          approvedCopyIds,
          approvedArticleIds,
          comments,
        },
      });
    }, 800); // 800ms debounce

    return () => clearTimeout(timer);
  }, [token, approvedVisualIds, approvedCopyIds, approvedArticleIds, comments, isLocked, set]);

  // Submit mutation
  const submitMutation = trpc.approvals.submitReview.useMutation({
    onSuccess: () => {
      setIsSubmitted(true);
      clearDraft(token);
      toast.success('Your feedback has been sent to the design team!');
    },
    onError: (err: any) => {
      toast.error(err.message || 'Failed to submit feedback.');
    },
  });

  // Shared cache utils (used to refetch when the set auto-advances to launch).
  const utils = trpc.useUtils();

  // Live per-asset approval — persists immediately so the server can
  // auto-advance the set to 'launch' and fire the team webhook once every
  // asset is approved (the server enforces idempotency).
  const approveAssetMutation = trpc.approvals.approveAsset.useMutation({
    onSuccess: (data: any) => {
      if (data?.status && isPostSubmitStatus(data.status)) {
        utils.approvals.getPublicSet.invalidate({ token });
      }
    },
    onError: (err: any) => {
      console.error('Failed to persist approval:', err.message);
    },
  });

  // New client comment → notifies the team via webhook.
  const addPublicCommentMutation = trpc.approvals.addPublicComment.useMutation({
    onError: (err: any) => console.error('Failed to post comment:', err.message),
  });
  // New team reply → emails the client.
  const addTeamCommentMutation = trpc.approvals.addTeamComment.useMutation({
    onError: (err: any) => console.error('Failed to post reply:', err.message),
  });

  // Toggles
  const handleToggleApprove = useCallback((id: string) => {
    if (isLocked || !set) return;

    // Check if ID belongs to media, article, custom, or copy (default)
    const isMedia = set.snapshot.media?.some((m: any) => m.id === id);
    const isArticle = set.snapshot.articles?.some((a: any) => a.id === id);
    const isCustom = set.snapshot.custom?.some((c: any) => c.id === id);

    // Compute the next approval state so the server persists the same value.
    const currentlyApproved = isMedia
      ? approvedVisualIds.includes(id)
      : isArticle
        ? approvedArticleIds.includes(id)
        : isCustom
          ? approvedCustomIds.includes(id)
          : approvedCopyIds.includes(id);
    const nextApproved = !currentlyApproved;

    if (isMedia) {
      setApprovedVisualIds((prev) =>
        prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]
      );
    } else if (isArticle) {
      setApprovedArticleIds((prev) =>
        prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]
      );
    } else if (isCustom) {
      setApprovedCustomIds((prev) =>
        prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]
      );
    } else {
      setApprovedCopyIds((prev) =>
        prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]
      );
    }

    // Persist immediately; the server flips the set to 'launch' + fires the
    // team webhook when this completes full approval.
    approveAssetMutation.mutate({ token, assetId: id, approved: nextApproved });
  }, [set, isLocked, approvedVisualIds, approvedArticleIds, approvedCustomIds, approvedCopyIds, token, approveAssetMutation]);

  // New-comment handler — routes through the dedicated endpoint so the team
  // (client comment) or client (team reply) is notified, and so the comment
  // persists even after the set has left 'draft'.
  const handleCommentAdded = useCallback((assetId: string, entry: CommentEntry) => {
    if (!set || !entry.text?.trim()) return;
    const parentId = entry.parentId ?? undefined;
    if (isTeamMember) {
      addTeamCommentMutation.mutate({ id: Number(set.id), assetId, body: entry.text, parentId });
    } else {
      addPublicCommentMutation.mutate({ token, assetId, body: entry.text, parentId, author: entry.author });
    }
  }, [set, token, isTeamMember, addTeamCommentMutation, addPublicCommentMutation]);

  // Thread change handler — receives the full updated thread array for an asset.
  // Intentionally NOT gated by isLocked: comment threads stay open for ongoing
  // client/team conversation even after the set is submitted/launched.
  const handleThreadChange = useCallback((assetId: string, thread: CommentEntry[]) => {
    setComments((prev) => {
      if (thread.length === 0) {
        // Remove the key entirely if thread is empty
        const next = { ...prev };
        delete next[assetId];
        return next;
      }
      return { ...prev, [assetId]: thread };
    });
  }, []);

  const handleApproveAll = useCallback(() => {
    if (isLocked || !set) return;
    const mediaIds = (set.snapshot.media || []).map((m: any) => m.id);
    const copyIds = (set.snapshot.copy || []).map((c: any) => c.id);
    const articleIds = (set.snapshot.articles || []).map((a: any) => a.id);
    const customIds = (set.snapshot.custom || []).map((c: any) => c.id);
    setApprovedVisualIds(mediaIds);
    setApprovedCopyIds(copyIds);
    setApprovedArticleIds(articleIds);
    setApprovedCustomIds(customIds);
    // Persist + let the server advance to 'launch' and notify the team.
    approveAssetMutation.mutate({ token, approveAll: true });
  }, [set, isLocked, token, approveAssetMutation]);

  const handleSubmitReview = useCallback(() => {
    if (isLocked) return;
    if (!clientName.trim()) {
      toast.error('Please enter your name before submitting.');
      return;
    }

    submitMutation.mutate({
      token,
      clientName: clientName.trim(),
      feedback: {
        approvedVisualIds,
        approvedCopyIds,
        approvedArticleIds,
        approvedCustomIds,
        comments,
      },
    });
  }, [token, clientName, approvedVisualIds, approvedCopyIds, approvedArticleIds, approvedCustomIds, comments, submitMutation, isLocked]);

  // Confirm submit handler — direct submission without name prompt
  const handleConfirmSubmit = useCallback(() => {
    if (isLocked) return;
    submitMutation.mutate({
      token,
      clientName: 'Client',
      feedback: {
        approvedVisualIds,
        approvedCopyIds,
        approvedArticleIds,
        approvedCustomIds,
        comments,
      },
    });
  }, [token, approvedVisualIds, approvedCopyIds, approvedArticleIds, approvedCustomIds, comments, submitMutation, isLocked]);

  const handleAssetUpdate = useCallback(() => {
    utils.approvals.getPublicSet.invalidate({ token });
  }, [utils, token]);

  // Combined asset lists & counts
  const mediaAssets = useMemo(() => set?.snapshot?.media || [], [set]);
  const copyAssets = useMemo(() => set?.snapshot?.copy || [], [set]);
  const articleAssets = useMemo(() => set?.snapshot?.articles || [], [set]);
  const customAssets = useMemo(() => set?.snapshot?.custom || [], [set]);

  const counts = useMemo(() => {
    const videos = mediaAssets.filter((item: any) => isVideoAsset(item)).length;
    const images = mediaAssets.length - videos;
    const copy = copyAssets.length;
    const articles = articleAssets.length;
    const custom = customAssets.length;
    return { all: mediaAssets.length + copy + articles + custom, images, videos, copy, articles, custom };
  }, [mediaAssets, copyAssets, articleAssets, customAssets]);

  const allMergedAssets = useMemo(() => {
    const media = mediaAssets.map((item: any) => ({
      id: item.id,
      type: 'media' as const,
      data: item
    }));
    const copy = copyAssets.map((item: any) => ({
      id: item.id,
      type: 'copy' as const,
      data: item
    }));
    const articles = articleAssets.map((item: any) => ({
      id: item.id,
      type: 'article' as const,
      data: item
    }));
    const custom = customAssets.map((item: any) => ({
      id: item.id,
      type: 'custom' as const,
      data: item
    }));
    return [...media, ...copy, ...articles, ...custom];
  }, [mediaAssets, copyAssets, articleAssets, customAssets]);

  const activeAssetForComment = useMemo(() => {
    if (!activeAssetIdForComment) return null;
    const found = allMergedAssets.find((a) => a.id === activeAssetIdForComment);
    return found ? found : null;
  }, [activeAssetIdForComment, allMergedAssets]);

  const filteredAssets = useMemo(() => {
    if (activeFilter === 'all') return allMergedAssets;
    if (activeFilter === 'images') return allMergedAssets.filter(item => item.type === 'media' && !isVideoAsset(item.data));
    if (activeFilter === 'videos') return allMergedAssets.filter(item => item.type === 'media' && isVideoAsset(item.data));
    if (activeFilter === 'copy') return allMergedAssets.filter(item => item.type === 'copy');
    if (activeFilter === 'articles') return allMergedAssets.filter(item => item.type === 'article');
    if (activeFilter === 'custom') return allMergedAssets.filter(item => item.type === 'custom');
    return allMergedAssets;
  }, [activeFilter, allMergedAssets]);

  const totalCount = counts.all;
  const approvedCount = approvedVisualIds.length + approvedCopyIds.length + approvedArticleIds.length + approvedCustomIds.length;
  const reviewedCount = approvedCount + Object.keys(comments).length;

  // Hero subtitle — describes asset breakdown for the client
  const heroSubtitle = useMemo(() => {
    const parts: string[] = [];
    if (counts.images > 0) parts.push(`${counts.images} image${counts.images !== 1 ? 's' : ''}`);
    if (counts.videos > 0) parts.push(`${counts.videos} video${counts.videos !== 1 ? 's' : ''}`);
    if (counts.copy > 0) parts.push(`${counts.copy} copy variant${counts.copy !== 1 ? 's' : ''}`);
    if (counts.articles > 0) parts.push(`${counts.articles} article${counts.articles !== 1 ? 's' : ''}`);
    const assetSummary = parts.length > 0 ? parts.join(', ') : 'your creative material';
    return `Review ${assetSummary}. Approve items as you go, or sign off the whole set from the toolbar.`;
  }, [counts]);

  if (isLoading) {
    return (
      <div className="pcm-state-wrapper">
        <div className="pcm-glow pcm-glow-1" aria-hidden="true" />
        <div className="pcm-glow pcm-glow-2" aria-hidden="true" />
        <div className="pcm-glow pcm-glow-3" aria-hidden="true" />
        <div className="pcm-state-center">
          <Spinner className="w-8 h-8 text-primary" />
          <span className="pcm-state-text">Loading review...</span>
        </div>
      </div>
    );
  }

  if (error || !set) {
    return (
      <div className="pcm-state-wrapper">
        <div className="pcm-glow pcm-glow-1" aria-hidden="true" />
        <div className="pcm-glow pcm-glow-2" aria-hidden="true" />
        <div className="pcm-glow pcm-glow-3" aria-hidden="true" />
        <div className="pcm-state-center">
          <div className="pcm-state-card">
            <HelpCircle className="pcm-state-icon pcm-state-icon-error" />
            <h2 className="pcm-state-heading">Invalid or Expired Board</h2>
            <p className="pcm-state-body">
              This approval set link is invalid, expired, or has been revoked. Please ask the creator for a new link.
            </p>
          </div>
        </div>
      </div>
    );
  }

  // Intentionally NO full-screen "thank you" takeover when the set is locked.
  // The client keeps access to the board (approvals become read-only) so they
  // can still open asset threads and continue the conversation after approval.
  // A banner (rendered below) communicates the locked/approved state.

  const primaryMediaUrl = mediaAssets[0]?.url || '';
  const brandName = set.snapshot.brandName || 'Client Board';
  const campaignName = set.name || 'Creative Review';
  const studioName = set.snapshot.studioName || 'Studio';

  return (
    <div className="pcm-client-review" style={{ display: 'flex', flexDirection: 'column', paddingBottom: '9rem', minHeight: '100vh' }}>
      {/* Ambient blue gradient blobs matching the login screen */}
      <div className="pcm-glow pcm-glow-1" aria-hidden="true" />
      <div className="pcm-glow pcm-glow-2" aria-hidden="true" />
      <div className="pcm-glow pcm-glow-3" aria-hidden="true" />

      {/* Locked/approved banner — board stays accessible so the client can
          continue commenting even after submitting/approving. */}
      {isLocked && (
        <div style={{ maxWidth: 1280, width: '100%', margin: '0 auto', padding: '14px 28px 0' }}>
          <div
            style={{
              display: 'flex', alignItems: 'center', gap: 10,
              background: 'rgba(16,185,129,0.10)', border: '1px solid rgba(16,185,129,0.30)',
              color: '#065f46', borderRadius: 12, padding: '10px 16px', fontSize: 13, fontWeight: 500,
            }}
          >
            <Check className="w-4 h-4" style={{ flexShrink: 0 }} />
            <span>This set has been approved and sent to the team. You can still open any asset to read and add comments.</span>
          </div>
        </div>
      )}

      {/* Hero Header */}
      <section className="pcm-hero max-w-[1280px] w-full mx-auto px-7 pt-12 pb-5 select-none">
        <div className="pcm-hero-eyebrow">
          {campaignName} · Granskning
        </div>
        <h1 className="pcm-hero-title">
          {totalCount} asset{totalCount !== 1 ? "s" : ""} <em>for your sign-off.</em>
        </h1>
        <p className="pcm-hero-subtitle">
          {heroSubtitle}
        </p>

        {/* Progress + Status — moved here from toolbar for cleaner layout */}
        <div className="pcm-hero-status-row">
          <div className="pcm-progress select-none">
            <span className="progress-count">
              {approvedCount} of {totalCount} approved
            </span>
            <div className="pcm-progress-bar">
              <div style={{ width: `${totalCount > 0 ? (approvedCount / totalCount) * 100 : 0}%` }} />
            </div>
          </div>
          <span
            className={`pcm-bar-status ${totalCount > 0 && approvedCount === totalCount ? 'is-approved' : ''}`}
            title="Status"
          >
            <span className="dot" aria-hidden="true" />
            {totalCount > 0 && approvedCount === totalCount ? ' Round approved' : ' Awaiting your review'}
          </span>
        </div>
      </section>

      {/* Sticky Statusbar */}
      <ClientStatusToolbar
        activeFilter={activeFilter}
        onFilterChange={setActiveFilter}
        counts={counts}
        approvedCount={approvedCount}
        totalCount={totalCount}
        createdAt={set.createdAt}
        onApproveAll={handleApproveAll}
        onConfirmSubmit={handleConfirmSubmit}
        isSubmitting={submitMutation.isPending}
        isConfirmPending={submitMutation.isPending}
        isReadOnly={isReadOnly}
        isSaving={saveDraftMutation.isPending}
      />

      {/* Grid container */}
      <main className="max-w-[1280px] w-full mx-auto px-7 mt-4">
        {filteredAssets.length === 0 ? (
          <div className="pcm-empty-state">
            <p>No assets found in this category.</p>
          </div>
        ) : (
          <div className="pcm-grid transition-all duration-300">
            {filteredAssets.map((item) => {
              const isApproved = item.type === 'media'
                ? approvedVisualIds.includes(item.id)
                : item.type === 'article'
                  ? approvedArticleIds.includes(item.id)
                  : item.type === 'custom'
                    ? approvedCustomIds.includes(item.id)
                    : approvedCopyIds.includes(item.id);
              const threadForAsset = comments[item.id] || [];
              const userRole = isTeamMember ? 'team' : 'client';
              const hasNewComment = threadForAsset.some(c => {
                const isAuthorTeam = c.author === 'Team';
                const isCurrentTeam = isTeamMember;
                const isSelf = (isCurrentTeam && isAuthorTeam) || (!isCurrentTeam && !isAuthorTeam);
                if (isSelf) return false;
                return !c.readBy?.includes(userRole);
              });

              // Pair copy card with corresponding media item
              let pairedMediaUrl = null;
              if (item.type === 'copy') {
                const copyIndex = copyAssets.findIndex((c: any) => c.id === item.id);
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
                  onApprove={handleToggleApprove}
                  brandLogoUrl={set.snapshot.brandLogoUrl}
                  brandName={brandName}
                  pairedMediaUrl={pairedMediaUrl}
                  isSubmitted={isLocked || submitMutation.isPending}
                  isTeamMember={isTeamMember}
                  onAssetUpdate={handleAssetUpdate}
                  onOpenComments={(id) => setActiveAssetIdForComment(id)}
                  copyIndex={item.type === 'copy' ? copyAssets.findIndex((c: any) => c.id === item.id) : undefined}
                />
              );
            })}
          </div>
        )}
      </main>

      {/* Floating Comment Inspector Drawer panel */}
      {activeAssetForComment && (
        <ClientCommentInspector
          asset={activeAssetForComment.data}
          type={activeAssetForComment.type}
          thread={comments[activeAssetForComment.id] || []}
          authorName={clientName || (isTeamMember ? 'Team' : 'Client')}
          // Comments stay open even after submit/launch so the client and team
          // can keep the conversation going (approvals themselves remain locked).
          isReadOnly={false}
          isTeamMember={isTeamMember}
          onClose={() => setActiveAssetIdForComment(null)}
          onThreadChange={handleThreadChange}
          onCommentAdded={handleCommentAdded}
        />
      )}
    </div>
  );
}
