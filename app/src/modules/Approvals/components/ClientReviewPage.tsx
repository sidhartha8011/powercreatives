import { useState, useCallback, useMemo } from 'react';
import { CheckCircle2, MessageSquare, Send, Check, Heart, HelpCircle, Loader2 } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Input } from '@/components/ui/input';
import { colors, typography, shadows } from '@/components/shared/design-tokens';
import { trpc } from '@/lib/trpc';

interface ClientReviewPageProps {
  token: string;
}

export function ClientReviewPage({ token }: ClientReviewPageProps) {
  // Fetch public set data by token
  const { data: set, isLoading, error, refetch } = trpc.approvals.getPublicSet.useQuery({ token }) as any;

  // Local Review State
  const [approvedVisualIds, setApprovedVisualIds] = useState<string[]>([]);
  const [approvedCopyIds, setApprovedCopyIds] = useState<string[]>([]);
  const [comments, setComments] = useState<Record<string, string>>({});
  const [activeCommentId, setActiveCommentId] = useState<string | null>(null);
  const [tempCommentText, setTempCommentText] = useState('');
  const [clientName, setClientName] = useState('');
  const [isSubmitted, setIsSubmitted] = useState(false);

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
  const handleToggleVisual = useCallback((id: string) => {
    if (isSubmitted) return;
    setApprovedVisualIds((prev) =>
      prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]
    );
  }, [isSubmitted]);

  const handleToggleCopy = useCallback((id: string) => {
    if (isSubmitted) return;
    setApprovedCopyIds((prev) =>
      prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]
    );
  }, [isSubmitted]);

  // Comment managers
  const handleOpenComment = useCallback((id: string) => {
    if (isSubmitted) return;
    setActiveCommentId(id);
    setTempCommentText(comments[id] || '');
  }, [comments, isSubmitted]);

  const handleSaveComment = useCallback(() => {
    if (!activeCommentId) return;
    setComments((prev) => ({
      ...prev,
      [activeCommentId]: tempCommentText.trim(),
    }));
    setActiveCommentId(null);
    setTempCommentText('');
    toast.success('Comment saved!');
  }, [activeCommentId, tempCommentText]);

  const handleClearComment = useCallback((id: string) => {
    if (isSubmitted) return;
    setComments((prev) => {
      const next = { ...prev };
      delete next[id];
      return next;
    });
    toast.info('Comment removed');
  }, [isSubmitted]);

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

  // Combined asset counts
  const mediaCount = set?.snapshot?.media?.length || 0;
  const copyCount = set?.snapshot?.copy?.length || 0;
  const totalCount = mediaCount + copyCount;
  const reviewedCount = approvedVisualIds.length + approvedCopyIds.length + Object.keys(comments).length;

  if (isLoading) {
    return (
      <div className="fixed inset-0 flex flex-col items-center justify-center bg-slate-50 gap-3">
        <Spinner className="w-8 h-8" style={{ color: colors.primary }} />
        <span style={{ fontSize: typography.sm, color: colors.textSecondary }}>Loading client review board...</span>
      </div>
    );
  }

  if (error || !set) {
    return (
      <div className="fixed inset-0 flex flex-col items-center justify-center bg-slate-50 p-6 text-center">
        <div className="max-w-md p-6 rounded-lg bg-white shadow-lg border" style={{ borderColor: colors.border }}>
          <HelpCircle className="w-12 h-12 text-red-500 mx-auto mb-3" />
          <h2 style={{ fontSize: typography.title, fontWeight: typography.bold, color: colors.text }}>
            Invalid or Expired Board
          </h2>
          <p className="mt-2 text-sm" style={{ color: colors.textSecondary }}>
            This approval set link is invalid, expired, or has been revoked. Please ask the creator for a new link.
          </p>
        </div>
      </div>
    );
  }

  // Pre-select primary media for mockups
  const primaryMediaUrl = set.snapshot.media?.[0]?.url || '';

  if (isSubmitted || set.status === 'completed') {
    return (
      <div className="fixed inset-0 flex flex-col items-center justify-center bg-slate-50 p-6 text-center">
        <div className="max-w-md p-8 rounded-lg bg-white shadow-xl border space-y-4" style={{ borderColor: colors.border }}>
          <div className="w-16 h-16 rounded-full bg-emerald-100 flex items-center justify-center mx-auto">
            <Check className="w-8 h-8 text-emerald-600" />
          </div>
          <h2 style={{ fontSize: typography.title, fontWeight: typography.bold, color: colors.text }}>
            Review Submitted!
          </h2>
          <p className="text-sm" style={{ color: colors.textSecondary }}>
            Thank you! Your approvals and comments have been locked and sent. The creative team has been notified via webhooks and will review your comments.
          </p>
          <div className="pt-2">
            <Badge className="bg-emerald-100 text-emerald-700 hover:bg-emerald-100 px-3 py-1">
              Status: Reviewed & Closed
            </Badge>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-slate-50 flex flex-col pb-28 font-sans" style={{ color: colors.text }}>
      {/* Brand Header */}
      <header className="sticky top-0 z-30 w-full px-6 py-4 bg-white/95 backdrop-blur border-b flex justify-between items-center shadow-xs"
        style={{ borderColor: colors.border }}
      >
        <div className="flex items-center gap-3">
          {set.snapshot.brandLogoUrl && (
            <img
              src={set.snapshot.brandLogoUrl}
              alt="Brand logo"
              className="w-8 h-8 rounded-full border bg-white object-contain"
            />
          )}
          <div>
            <h2 className="text-sm font-semibold" style={{ color: colors.text }}>
              {set.snapshot.brandName || 'Client Board'}
            </h2>
            <span style={{ fontSize: typography.xs, color: colors.textMuted }}>
              Campaign: {set.name}
            </span>
          </div>
        </div>
        <div>
          <Badge className="bg-blue-100 text-blue-800 hover:bg-blue-100 py-1 px-3">
            Client Review Stage
          </Badge>
        </div>
      </header>

      {/* Main Container */}
      <main className="max-w-5xl w-full mx-auto p-6 grid grid-cols-1 md:grid-cols-2 gap-8 items-start">
        
        {/* =====================================================================
            Left Side: Media Asset Gallery
            ===================================================================== */}
        <section className="space-y-4">
          <h3 className="text-sm font-bold uppercase tracking-wider" style={{ color: colors.textSecondary }}>
            1. Review Media Assets ({mediaCount})
          </h3>
          <div className="grid grid-cols-1 gap-4">
            {set.snapshot.media?.map((media: any) => {
              const isApproved = approvedVisualIds.includes(media.id);
              const comment = comments[media.id];

              return (
                <div
                  key={media.id}
                  className="rounded-xl overflow-hidden bg-white border transition-all shadow-sm flex flex-col"
                  style={{
                    borderColor: isApproved ? '#10b981' : colors.border,
                    boxShadow: isApproved ? '0 4px 12px rgba(16,185,129,0.08)' : shadows.card,
                  }}
                >
                  {/* Image render */}
                  <div className="relative aspect-video bg-black flex items-center justify-center group">
                    <img
                      src={media.url}
                      alt="Ad creative visual"
                      className="w-full h-full object-cover"
                    />
                    {isApproved && (
                      <div className="absolute inset-0 bg-emerald-500/10 flex items-center justify-center">
                        <Badge className="bg-emerald-500 text-white hover:bg-emerald-500 px-3 py-1 gap-1 text-xs">
                          <Check className="w-3.5 h-3.5" /> Selected & Approved
                        </Badge>
                      </div>
                    )}
                  </div>

                  {/* Actions */}
                  <div className="p-3 bg-white flex items-center justify-between border-t" style={{ borderColor: colors.borderLight }}>
                    <div className="flex items-center gap-1.5">
                      <Button
                        size="sm"
                        variant={isApproved ? "default" : "outline"}
                        className="h-8 gap-1.5 text-xs font-semibold transition-all"
                        style={{
                          background: isApproved ? '#10b981' : undefined,
                          borderColor: isApproved ? '#10b981' : undefined,
                        }}
                        onClick={() => handleToggleVisual(media.id)}
                      >
                        <CheckCircle2 className="w-4 h-4" />
                        {isApproved ? 'Approved' : 'Approve Media'}
                      </Button>
                      <Button
                        size="sm"
                        variant="outline"
                        className="h-8 gap-1 text-xs"
                        onClick={() => handleOpenComment(media.id)}
                      >
                        <MessageSquare className="w-3.5 h-3.5" />
                        {comment ? 'Edit Comment' : 'Add Comment'}
                      </Button>
                    </div>
                  </div>

                  {/* Display comment if exists */}
                  {comment && (
                    <div className="px-3 pb-3 bg-white">
                      <div className="p-2.5 rounded italic text-xs flex justify-between items-start gap-2"
                        style={{ background: '#fffbeb', borderLeft: '3px solid #f59e0b' }}
                      >
                        <span className="min-w-0 break-words flex-1">"{comment}"</span>
                        <button
                          onClick={() => handleClearComment(media.id)}
                          className="text-[10px] text-red-500 hover:underline shrink-0 font-semibold"
                        >
                          Remove
                        </button>
                      </div>
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        </section>

        {/* =====================================================================
            Right Side: Copy Cards inside Mockups
            ===================================================================== */}
        <section className="space-y-4">
          <h3 className="text-sm font-bold uppercase tracking-wider" style={{ color: colors.textSecondary }}>
            2. Review Ad Copy Mockups ({copyCount})
          </h3>
          <div className="space-y-6">
            {set.snapshot.copy?.map((copy: any, index: number) => {
              const isApproved = approvedCopyIds.includes(copy.id);
              const comment = comments[copy.id];
              
              // Pair copy card with corresponding media or fallback
              const creativeUrl = set.snapshot.media?.[index % mediaCount]?.url || primaryMediaUrl;

              return (
                <div
                  key={copy.id}
                  className="rounded-xl overflow-hidden bg-white border transition-all"
                  style={{
                    borderColor: isApproved ? '#10b981' : colors.border,
                    boxShadow: isApproved ? '0 4px 16px rgba(16,185,129,0.08)' : shadows.card,
                  }}
                >
                  {/* Meta Ad Mockup Header */}
                  <div className="p-3 flex items-center justify-between border-b" style={{ borderColor: colors.borderLight }}>
                    <div className="flex items-center gap-2">
                      {set.snapshot.brandLogoUrl ? (
                        <img
                          src={set.snapshot.brandLogoUrl}
                          alt="Brand logo"
                          className="w-8 h-8 rounded-full border bg-white object-contain"
                        />
                      ) : (
                        <div className="w-8 h-8 rounded-full bg-slate-200 border flex items-center justify-center font-bold text-xs">
                          {set.snapshot.brandName?.[0] || 'C'}
                        </div>
                      )}
                      <div>
                        <span className="text-xs font-semibold block leading-tight">
                          {set.snapshot.brandName || 'Brand'}
                        </span>
                        <span className="text-[10px]" style={{ color: colors.textFaint }}>
                          Sponsored
                        </span>
                      </div>
                    </div>
                  </div>

                  {/* Meta Ad Text */}
                  <div className="px-3 py-2 text-xs leading-relaxed" style={{ background: '#ffffff', color: '#1c1e21' }}>
                    <p className="whitespace-pre-wrap">{copy.body}</p>
                  </div>

                  {/* Mockup Ad Creative */}
                  {creativeUrl && (
                    <div className="relative aspect-video bg-black">
                      <img
                        src={creativeUrl}
                        alt="Ad preview"
                        className="w-full h-full object-cover"
                      />
                    </div>
                  )}

                  {/* Meta Headline & CTA footer layout */}
                  <div className="p-3 flex items-center justify-between" style={{ background: '#f0f2f5', borderTop: '1px solid #ddd' }}>
                    <div className="flex-1 min-w-0 pr-2">
                      <span className="text-[10px] uppercase block truncate" style={{ color: '#606770' }}>
                        {copy.description || 'DYNAMIC PREVIEW'}
                      </span>
                      <span className="text-xs font-bold block truncate text-slate-800">
                        {copy.headline}
                      </span>
                    </div>
                    {copy.cta && (
                      <span className="text-[10px] font-semibold uppercase px-3 py-1.5 border rounded bg-white select-none text-slate-700 shadow-xs">
                        {copy.cta}
                      </span>
                    )}
                  </div>

                  {/* Actions */}
                  <div className="p-3 bg-white flex items-center justify-between border-t" style={{ borderColor: colors.borderLight }}>
                    <div className="flex items-center gap-1.5">
                      <Button
                        size="sm"
                        variant={isApproved ? "default" : "outline"}
                        className="h-8 gap-1.5 text-xs font-semibold transition-all"
                        style={{
                          background: isApproved ? '#10b981' : undefined,
                          borderColor: isApproved ? '#10b981' : undefined,
                        }}
                        onClick={() => handleToggleCopy(copy.id)}
                      >
                        <CheckCircle2 className="w-4 h-4" />
                        {isApproved ? 'Approved' : 'Approve Copy'}
                      </Button>
                      <Button
                        size="sm"
                        variant="outline"
                        className="h-8 gap-1 text-xs"
                        onClick={() => handleOpenComment(copy.id)}
                      >
                        <MessageSquare className="w-3.5 h-3.5" />
                        {comment ? 'Edit Comment' : 'Add Comment'}
                      </Button>
                    </div>
                  </div>

                  {/* Display comment if exists */}
                  {comment && (
                    <div className="px-3 pb-3 bg-white">
                      <div className="p-2.5 rounded italic text-xs flex justify-between items-start gap-2"
                        style={{ background: '#fffbeb', borderLeft: '3px solid #f59e0b' }}
                      >
                        <span className="min-w-0 break-words flex-1">"{comment}"</span>
                        <button
                          onClick={() => handleClearComment(copy.id)}
                          className="text-[10px] text-red-500 hover:underline shrink-0 font-semibold"
                        >
                          Remove
                        </button>
                      </div>
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        </section>
      </main>

      {/* =====================================================================
          Granular Comment Popup Dialog
          ===================================================================== */}
      {activeCommentId && (
        <Dialog open={!!activeCommentId} onOpenChange={() => setActiveCommentId(null)}>
          <DialogContent className="sm:max-w-md">
            <DialogHeader>
              <DialogTitle className="flex items-center gap-2">
                <MessageSquare className="w-5 h-5" style={{ color: colors.primary }} />
                Leave Feedback Comment
              </DialogTitle>
              <DialogDescription>
                Provide detailed feedback or requested tweaks on this specific ad creative component.
              </DialogDescription>
            </DialogHeader>

            <div className="py-4">
              <Textarea
                value={tempCommentText}
                onChange={(e) => setTempCommentText(e.target.value)}
                placeholder="Type your feedback here... (e.g. Can we change the CTA from 'Learn More' to 'Shop Now'?)"
                rows={4}
                className="text-xs"
              />
            </div>

            <DialogFooter>
              <Button variant="ghost" onClick={() => setActiveCommentId(null)}>
                Cancel
              </Button>
              <Button onClick={handleSaveComment} style={{ background: colors.primary }}>
                Save Comment
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      )}

      {/* =====================================================================
          Consolidated Sticky Review control footer
          ===================================================================== */}
      <footer className="fixed bottom-0 left-0 right-0 z-40 bg-white border-t px-6 py-4 shadow-2xl flex flex-col md:flex-row md:items-center justify-between gap-4 max-w-5xl mx-auto rounded-t-xl"
        style={{ borderColor: colors.border }}
      >
        <div className="flex flex-col md:flex-row md:items-center gap-4 flex-1">
          <div className="space-y-1">
            <div className="flex items-center gap-2 text-xs font-semibold text-slate-700">
              <Clock className="w-4 h-4 text-blue-500" />
              <span>Feedback Progress:</span>
              <span style={{ color: colors.primary }}>{reviewedCount} total actions</span>
            </div>
            <p style={{ fontSize: typography.xs, color: colors.textMuted }}>
              Your progress will be bundled and sent in a single consolidated webhook notification.
            </p>
          </div>

          <div className="max-w-[240px] w-full shrink-0">
            <Input
              value={clientName}
              onChange={(e) => setClientName(e.target.value)}
              placeholder="Your Name (Required)"
              className="h-9 text-xs"
              disabled={submitMutation.isLoading}
            />
          </div>
        </div>

        <div className="shrink-0 flex gap-2">
          <Button
            onClick={handleSubmitReview}
            disabled={submitMutation.isLoading || !clientName.trim()}
            className="h-9 font-semibold gap-2 text-xs"
            style={{ background: colors.primary }}
          >
            {submitMutation.isLoading ? (
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
