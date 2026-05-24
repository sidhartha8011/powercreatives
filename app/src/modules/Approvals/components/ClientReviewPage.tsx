import { useState, useCallback, useMemo } from 'react';
import { Send, Check, Clock, Loader2, Heart, HelpCircle } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { colors, shadows } from '@/components/shared/design-tokens';
import { trpc } from '@/lib/trpc';

// Import newly created reusable component modules
import { ClientBreadcrumbs } from './ClientBreadcrumbs';
import { ClientStatusToolbar } from './ClientStatusToolbar';
import { CreativeAssetCard } from './CreativeAssetCard';

interface ClientReviewPageProps {
  token: string;
}

export function ClientReviewPage({ token }: ClientReviewPageProps) {
  // Fetch public set data by token
  const { data: set, isLoading, error } = trpc.approvals.getPublicSet.useQuery({ token }) as any;

  // Local Review State
  const [approvedVisualIds, setApprovedVisualIds] = useState<string[]>([]);
  const [approvedCopyIds, setApprovedCopyIds] = useState<string[]>([]);
  const [comments, setComments] = useState<Record<string, string>>({});
  const [clientName, setClientName] = useState('');
  const [isSubmitted, setIsSubmitted] = useState(false);
  const [activeFilter, setActiveFilter] = useState<'all' | 'images' | 'videos' | 'copy'>('all');

  // Submit mutation
  const submitMutation = trpc.approvals.submitReview.useMutation({
    onSuccess: () => {
      setIsSubmitted(true);
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

  // Comment managers
  const handleSaveComment = useCallback((id: string, text: string) => {
    if (isSubmitted) return;
    setComments((prev) => ({
      ...prev,
      [id]: text
    }));
    toast.success('Comment saved!');
  }, [isSubmitted]);

  const handleClearComment = useCallback((id: string) => {
    if (isSubmitted) return;
    setComments((prev) => {
      const next = { ...prev };
      delete next[id];
      return next;
    });
    toast.info('Comment removed');
  }, [isSubmitted]);

  const handleApproveAll = useCallback(() => {
    if (isSubmitted || !set) return;
    const mediaIds = (set.snapshot.media || []).map((m: any) => m.id);
    const copyIds = (set.snapshot.copy || []).map((c: any) => c.id);
    setApprovedVisualIds(mediaIds);
    setApprovedCopyIds(copyIds);
    toast.success('All creative assets marked as approved!');
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

  // Combined asset lists & counts
  const mediaAssets = useMemo(() => set?.snapshot?.media || [], [set]);
  const copyAssets = useMemo(() => set?.snapshot?.copy || [], [set]);
  
  const counts = useMemo(() => {
    const images = mediaAssets.filter((item: any) => !(
      item.mimeType?.startsWith('video/') ||
      item.url?.endsWith('.mp4') ||
      item.url?.endsWith('.mov') ||
      item.url?.endsWith('.webm')
    )).length;

    const videos = mediaAssets.filter((item: any) => (
      item.mimeType?.startsWith('video/') ||
      item.url?.endsWith('.mp4') ||
      item.url?.endsWith('.mov') ||
      item.url?.endsWith('.webm')
    )).length;

    const copy = copyAssets.length;
    return {
      all: mediaAssets.length + copy,
      images,
      videos,
      copy
    };
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

  const filteredAssets = useMemo(() => {
    if (activeFilter === 'all') {
      return allMergedAssets;
    }
    if (activeFilter === 'images') {
      return allMergedAssets.filter(item => item.type === 'media' && !(
        item.data.mimeType?.startsWith('video/') ||
        item.data.url?.endsWith('.mp4') ||
        item.data.url?.endsWith('.mov') ||
        item.data.url?.endsWith('.webm')
      ));
    }
    if (activeFilter === 'videos') {
      return allMergedAssets.filter(item => item.type === 'media' && (
        item.data.mimeType?.startsWith('video/') ||
        item.data.url?.endsWith('.mp4') ||
        item.data.url?.endsWith('.mov') ||
        item.data.url?.endsWith('.webm')
      ));
    }
    if (activeFilter === 'copy') {
      return allMergedAssets.filter(item => item.type === 'copy');
    }
    return allMergedAssets;
  }, [activeFilter, allMergedAssets]);

  const totalCount = counts.all;
  const approvedCount = approvedVisualIds.length + approvedCopyIds.length;
  const reviewedCount = approvedCount + Object.keys(comments).length;

  // Background Aurora styled gradient configurations
  const pageWrapperStyle = useMemo(() => ({
    background: '#f6f0ea',
    backgroundImage: `
      radial-gradient(at 12% 8%,  #ffd9c2 0px, transparent 45%),
      radial-gradient(at 88% 12%, #d8d0ff 0px, transparent 45%),
      radial-gradient(at 50% 92%, #c9f2dc 0px, transparent 50%),
      radial-gradient(at 92% 78%, #ffe1ec 0px, transparent 42%)
    `,
    backgroundAttachment: 'fixed' as const,
    minHeight: '100vh',
  }), []);

  if (isLoading) {
    return (
      <div className="fixed inset-0 flex flex-col items-center justify-center bg-slate-50 gap-3">
        <Spinner className="w-8 h-8" style={{ color: colors.primary }} />
        <span className="text-sm text-muted-foreground">Loading client review board...</span>
      </div>
    );
  }

  if (error || !set) {
    return (
      <div className="fixed inset-0 flex flex-col items-center justify-center bg-slate-50 p-6 text-center">
        <div className="max-w-md p-6 rounded-2xl bg-white shadow-lg border" style={{ borderColor: colors.border }}>
          <HelpCircle className="w-12 h-12 text-red-500 mx-auto mb-3" />
          <h2 className="text-lg font-bold text-foreground">
            Invalid or Expired Board
          </h2>
          <p className="mt-2 text-sm text-muted-foreground">
            This approval set link is invalid, expired, or has been revoked. Please ask the creator for a new link.
          </p>
        </div>
      </div>
    );
  }

  if (isSubmitted || set.status === 'completed') {
    return (
      <div className="fixed inset-0 flex flex-col items-center justify-center bg-slate-50 p-6 text-center">
        <div className="max-w-md p-8 rounded-2xl bg-white shadow-xl border space-y-4" style={{ borderColor: colors.border }}>
          <div className="w-16 h-16 rounded-full bg-emerald-100 flex items-center justify-center mx-auto">
            <Check className="w-8 h-8 text-emerald-600" />
          </div>
          <h2 className="text-lg font-bold text-foreground">
            Review Submitted!
          </h2>
          <p className="text-sm text-muted-foreground leading-relaxed">
            Thank you! Your approvals and comments have been locked and sent. The creative team has been notified and will review your comments.
          </p>
          <div className="pt-2">
            <Badge className="bg-emerald-100 text-emerald-700 hover:bg-emerald-100 px-3 py-1 font-semibold">
              Status: Reviewed & Closed
            </Badge>
          </div>
        </div>
      </div>
    );
  }

  const primaryMediaUrl = mediaAssets[0]?.url || '';
  const brandName = set.snapshot.brandName || 'Client Board';
  const campaignName = set.name || 'Creative Review';

  return (
    <div style={pageWrapperStyle} className="font-sans flex flex-col pb-36 text-foreground">
      {/* Notion-Style Breadcrumb Header */}
      <ClientBreadcrumbs
        brandName={brandName}
        campaignName={campaignName}
        shareUrl={window.location.href}
      />

      {/* Hero Header */}
      <section className="max-w-5xl w-full mx-auto px-4 md:px-6 pt-12 pb-6">
        <div className="text-[10px] font-bold uppercase tracking-widest text-muted-foreground/80 mb-2.5">
          Round 1 · Asset Review
        </div>
        <h1 className="font-serif italic font-medium text-4xl md:text-5xl lg:text-6.5xl leading-none text-foreground tracking-tight max-w-2xl">
          {totalCount} creative{totalCount !== 1 ? 's' : ''},<br />for your sign-off.
        </h1>
        <p className="mt-4 max-w-xl text-sm leading-relaxed text-slate-700">
          The campaign drop, told in images, motion pieces, and written rooms. Approve items below, then submit your feedback to our creative design team.
        </p>
      </section>

      {/* Sticky Frosted Glass Statusbar */}
      <ClientStatusToolbar
        activeFilter={activeFilter}
        onFilterChange={setActiveFilter}
        counts={counts}
        approvedCount={approvedCount}
        totalCount={totalCount}
        createdAt={set.createdAt}
        onApproveAll={handleApproveAll}
        isSubmitting={submitMutation.isPending}
      />

      {/* Grid container */}
      <main className="max-w-5xl w-full mx-auto px-4 md:px-6 mt-4">
        {filteredAssets.length === 0 ? (
          <div className="w-full text-center py-16 bg-white/40 backdrop-blur-md rounded-2xl border border-border/40 select-none">
            <p className="text-sm font-semibold text-muted-foreground">No assets found in this category.</p>
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 transition-all duration-300">
            {filteredAssets.map((item) => {
              const isApproved = item.type === 'media'
                ? approvedVisualIds.includes(item.id)
                : approvedCopyIds.includes(item.id);
              const comment = comments[item.id];

              // Pair copy card with corresponding media item
              let pairedMediaUrl = null;
              if (item.type === 'copy') {
                const copyIndex = copyAssets.findIndex((c: any) => c.id === item.id);
                pairedMediaUrl = mediaAssets[copyIndex % mediaAssets.length]?.url || primaryMediaUrl;
              }

              return (
                <CreativeAssetCard
                  key={item.id}
                  asset={item.data}
                  type={item.type}
                  isApproved={isApproved}
                  comment={comment}
                  onApprove={handleToggleApprove}
                  onCommentSave={handleSaveComment}
                  onCommentRemove={handleClearComment}
                  brandLogoUrl={set.snapshot.brandLogoUrl}
                  brandName={brandName}
                  pairedMediaUrl={pairedMediaUrl}
                  isSubmitted={submitMutation.isPending}
                />
              );
            })}
          </div>
        )}
      </main>

      {/* Sticky review submission bottom footer */}
      <footer 
        className="fixed bottom-4 left-4 right-4 md:left-6 md:right-6 z-40 bg-white/90 backdrop-blur-xl border px-5 py-3.5 shadow-2xl flex flex-col md:flex-row md:items-center justify-between gap-4 max-w-5xl mx-auto rounded-2xl transition-all duration-200"
        style={{ borderColor: colors.border }}
      >
        <div className="flex flex-col md:flex-row md:items-center gap-4 flex-1">
          <div className="space-y-0.5 select-none">
            <div className="flex items-center gap-2 text-xs font-bold text-slate-800">
              <Clock className="w-4 h-4 text-blue-500" />
              <span>Feedback Progress:</span>
              <span className="text-blue-600">{reviewedCount} total actions</span>
            </div>
            <p className="text-[10px] text-muted-foreground">
              Your feedback is securely saved locally and will be locked and sent in a single consolidated submission.
            </p>
          </div>

          <div className="max-w-[240px] w-full shrink-0">
            <Input
              value={clientName}
              onChange={(e) => setClientName(e.target.value)}
              placeholder="Your Name (Required)"
              className="h-9 text-xs bg-white/80"
              disabled={submitMutation.isPending}
            />
          </div>
        </div>

        <div className="shrink-0 flex gap-2">
          <Button
            onClick={handleSubmitReview}
            disabled={submitMutation.isPending || !clientName.trim()}
            className="h-9 font-bold gap-2 text-xs cursor-pointer select-none bg-blue-600 hover:bg-blue-700 text-white border-blue-600 active:translate-y-0.5"
          >
            {submitMutation.isPending ? (
              <>
                <Loader2 className="w-4 h-4 animate-spin" />
                Submitting review...
              </>
            ) : (
              <>
                <Send className="w-4 h-4" />
                Submit Feedback to Team
              </>
            )}
          </Button>
        </div>
      </footer>
    </div>
  );
}
