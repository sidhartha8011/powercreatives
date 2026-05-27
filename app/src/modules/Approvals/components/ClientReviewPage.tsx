import { useState, useCallback, useMemo, useEffect } from 'react';
import { Check, HelpCircle } from 'lucide-react';
import { toast } from 'sonner';

import { Spinner } from '@/components/ui/spinner';
import { Button } from '@/components/ui/button';

import { trpc } from '@/lib/trpc';

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
  // Threaded comments: array of CommentEntry per asset ID
  const [comments, setComments] = useState<Record<string, CommentEntry[]>>(draft?.comments ?? {});
  const [activeAssetIdForComment, setActiveAssetIdForComment] = useState<string | null>(null);
  const [clientName, setClientName] = useState(draft?.clientName ?? '');
  const [isSubmitted, setIsSubmitted] = useState(false);
  const [activeFilter, setActiveFilter] = useState<'all' | 'images' | 'videos' | 'copy'>('all');

  // Derived: board is read-only when already completed on server
  const isReadOnly = set?.status === 'completed';

  // Check if current user is a logged-in team member
  const isTeamMember = useMemo(() => {
    const config = (window as any).pcmConfig;
    return !!config?.user?.isLoggedIn;
  }, []);

  // Auto-save draft to localStorage on every state change
  useEffect(() => {
    if (isSubmitted) return;
    saveDraft(token, { approvedVisualIds, approvedCopyIds, comments, clientName });
  }, [token, approvedVisualIds, approvedCopyIds, comments, clientName, isSubmitted]);

  // Hydrate approved/comment state from server draft or completed review feedback
  // Handles both legacy string-per-asset format and new array-of-objects format
  useEffect(() => {
    if (set?.reviewFeedback) {
      setApprovedVisualIds(set.reviewFeedback.approvedVisualIds || []);
      setApprovedCopyIds(set.reviewFeedback.approvedCopyIds || []);

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
    if (isSubmitted || !set || set.status !== 'draft') return;

    const timer = setTimeout(() => {
      saveDraftMutation.mutate({
        token,
        feedback: {
          approvedVisualIds,
          approvedCopyIds,
          comments,
        },
      });
    }, 800); // 800ms debounce

    return () => clearTimeout(timer);
  }, [token, approvedVisualIds, approvedCopyIds, comments, isSubmitted, set]);

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

  // Toggles
  const handleToggleApprove = useCallback((id: string) => {
    if (isSubmitted || !set) return;

    // Check if ID belongs to media or copy
    const isMedia = set.snapshot.media?.some((m: any) => m.id === id);
    if (isMedia) {
      setApprovedVisualIds((prev) =>
        prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]
      );
    } else {
      setApprovedCopyIds((prev) =>
        prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]
      );
    }
  }, [set, isSubmitted]);

  // Thread change handler — receives the full updated thread array for an asset
  const handleThreadChange = useCallback((assetId: string, thread: CommentEntry[]) => {
    if (isSubmitted) return;
    setComments((prev) => {
      if (thread.length === 0) {
        // Remove the key entirely if thread is empty
        const next = { ...prev };
        delete next[assetId];
        return next;
      }
      return { ...prev, [assetId]: thread };
    });
  }, [isSubmitted]);

  const handleApproveAll = useCallback(() => {
    if (isSubmitted || !set) return;
    const mediaIds = (set.snapshot.media || []).map((m: any) => m.id);
    const copyIds = (set.snapshot.copy || []).map((c: any) => c.id);
    setApprovedVisualIds(mediaIds);
    setApprovedCopyIds(copyIds);
  }, [set, isSubmitted]);

  const handleSubmitReview = useCallback(() => {
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
        comments,
      },
    });
  }, [token, clientName, approvedVisualIds, approvedCopyIds, comments, submitMutation]);

  // Confirm submit handler — direct submission without name prompt
  const handleConfirmSubmit = useCallback(() => {
    submitMutation.mutate({
      token,
      clientName: 'Client',
      feedback: {
        approvedVisualIds,
        approvedCopyIds,
        comments,
      },
    });
  }, [token, approvedVisualIds, approvedCopyIds, comments, submitMutation]);

  const utils = trpc.useUtils();
  const handleAssetUpdate = useCallback(() => {
    utils.approvals.getPublicSet.invalidate({ token });
  }, [utils, token]);

  // Combined asset lists & counts
  const mediaAssets = useMemo(() => set?.snapshot?.media || [], [set]);
  const copyAssets = useMemo(() => set?.snapshot?.copy || [], [set]);
  
  const counts = useMemo(() => {
    const videos = mediaAssets.filter((item: any) => isVideoAsset(item)).length;
    const images = mediaAssets.length - videos;
    const copy = copyAssets.length;
    return { all: mediaAssets.length + copy, images, videos, copy };
  }, [mediaAssets, copyAssets]);

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
    return [...media, ...copy];
  }, [mediaAssets, copyAssets]);

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
    return allMergedAssets;
  }, [activeFilter, allMergedAssets]);

  const totalCount = counts.all;
  const approvedCount = approvedVisualIds.length + approvedCopyIds.length;
  const reviewedCount = approvedCount + Object.keys(comments).length;

  // Hero subtitle — describes asset breakdown for the client
  const heroSubtitle = useMemo(() => {
    const parts: string[] = [];
    if (counts.images > 0) parts.push(`${counts.images} bild${counts.images !== 1 ? 'er' : ''}`);
    if (counts.videos > 0) parts.push(`${counts.videos} video${counts.videos !== 1 ? 'r' : ''}`);
    if (counts.copy > 0) parts.push(`${counts.copy} textvariant${counts.copy !== 1 ? 'er' : ''}`);
    const assetSummary = parts.length > 0 ? parts.join(', ') : 'ditt kreativa material';
    return `Granska ${assetSummary}. Godkänn allt eftersom, eller signera hela paketet via verktygsfältet.`;
  }, [counts]);

  if (isLoading) {
    return (
      <div className="pcm-state-wrapper">
        <div className="pcm-glow pcm-glow-1" aria-hidden="true" />
        <div className="pcm-glow pcm-glow-2" aria-hidden="true" />
        <div className="pcm-glow pcm-glow-3" aria-hidden="true" />
        <div className="pcm-state-center">
          <Spinner className="w-8 h-8 text-primary" />
          <span className="pcm-state-text">Laddar granskning...</span>
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

  if (isSubmitted && !isTeamMember) {
    return (
      <div className="pcm-state-wrapper">
        <div className="pcm-glow pcm-glow-1" aria-hidden="true" />
        <div className="pcm-glow pcm-glow-2" aria-hidden="true" />
        <div className="pcm-glow pcm-glow-3" aria-hidden="true" />
        <div className="pcm-state-center">
          <div className="pcm-state-card">
            <div className="pcm-state-icon-circle">
              <Check className="pcm-state-icon pcm-state-icon-success" />
            </div>
            <h2 className="pcm-state-heading">Feedback inskickad!</h2>
            <p className="pcm-state-body">
              Tack! Dina godkännanden har skickats till teamet.
            </p>
          </div>
        </div>
      </div>
    );
  }

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
      {/* Hero Header */}
      <section className="pcm-hero max-w-[1280px] w-full mx-auto px-7 pt-12 pb-5 select-none">
        <div className="pcm-hero-eyebrow">
          {campaignName} · Granskning
        </div>
        <h1 className="pcm-hero-title">
          {totalCount} tillgång{totalCount !== 1 ? 'ar' : ''} <em>för din signering.</em>
        </h1>
        <p className="pcm-hero-subtitle">
          {heroSubtitle}
        </p>

        {/* Progress + Status — moved here from toolbar for cleaner layout */}
        <div className="pcm-hero-status-row">
          <div className="pcm-progress select-none">
            <span className="progress-count">
              {approvedCount} av {totalCount} godkända
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
            {totalCount > 0 && approvedCount === totalCount ? ' Omgång godkänd' : ' Inväntar din granskning'}
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
                  isSubmitted={isReadOnly || submitMutation.isPending}
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
          isReadOnly={isReadOnly}
          isTeamMember={isTeamMember}
          onClose={() => setActiveAssetIdForComment(null)}
          onThreadChange={handleThreadChange}
        />
      )}
    </div>
  );
}
