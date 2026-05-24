import { useState, useCallback, useMemo } from 'react';
import { Send, Check, Clock, Loader2, HelpCircle } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { colors } from '@/components/shared/design-tokens';
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

  if (isLoading) {
    return (
      <div className="fixed inset-0 flex flex-col items-center justify-center bg-slate-50 gap-3">
        <Spinner className="w-8 h-8 text-primary" />
        <span className="text-sm text-muted-foreground">Loading client review board...</span>
      </div>
    );
  }

  if (error || !set) {
    return (
      <div className="fixed inset-0 flex flex-col items-center justify-center bg-slate-50 p-6 text-center">
        <div className="max-w-md p-6 rounded-2xl bg-white shadow-lg border border-border">
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
        <div className="max-w-md p-8 rounded-2xl bg-white shadow-xl border border-border space-y-4">
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
    <div className="aurora-mesh-bg font-sans flex flex-col pb-36 text-foreground min-h-screen">
      {/* 
        Inject the exact design CSS stylesheet rules directly from board-14-aurora-statusbar.html.
        This provides perfect pixel identical rendering matches for blurs, shadows, spacing, and gradients.
      */}
      <style>{`
        :root {
          --ink: #1d1d1f;
          --ink-2: #4a4239;
          --ink-3: #6f6a64;
          --ink-4: #8a7d6d;
          --line: rgba(0,0,0,0.06);
          --approve: #18a957;
          --approve-soft: rgba(24,169,87,0.12);
        }

        .aurora-mesh-bg {
          background: #f6f0ea;
          background-image:
            radial-gradient(at 12% 8%,  #ffd9c2 0px, transparent 45%),
            radial-gradient(at 88% 12%, #d8d0ff 0px, transparent 45%),
            radial-gradient(at 50% 92%, #c9f2dc 0px, transparent 50%),
            radial-gradient(at 92% 78%, #ffe1ec 0px, transparent 42%);
          background-attachment: fixed;
          -webkit-font-smoothing: antialiased;
          -moz-osx-font-smoothing: grayscale;
        }

        /* ---------- breadcrumbs ---------- */
        .pcm-crumb {
          height: 48px;
          display: flex; align-items: center;
          padding: 0 22px;
          background: rgba(246,240,234,0.72);
          backdrop-filter: blur(24px) saturate(180%);
          -webkit-backdrop-filter: blur(24px) saturate(180%);
          border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        .pcm-crumb-mark {
          width: 22px; height: 22px; border-radius: 6px;
          background: linear-gradient(135deg, #ff9c6a 0%, #c876ff 100%);
          display: grid; place-items: center;
          color: white; font-size: 11px; font-weight: 700;
          letter-spacing: -0.02em;
          margin-right: 8px;
          box-shadow: 0 2px 6px rgba(200,118,255,0.25);
        }
        .pcm-crumb-piece {
          font-size: 13px; color: var(--ink-3); font-weight: 500;
          padding: 4px 8px; border-radius: 6px;
          letter-spacing: -0.005em;
          transition: background .15s ease, color .15s ease;
        }
        .pcm-crumb-piece:hover { background: rgba(0,0,0,0.04); color: var(--ink-2); }
        .pcm-crumb-piece.current { color: var(--ink); }
        .pcm-crumb-sep { color: var(--ink-4); font-size: 12px; padding: 0 1px; }
        .pcm-crumb-icon {
          appearance: none; border: none; cursor: pointer;
          background: transparent; color: var(--ink-3);
          width: 30px; height: 30px; border-radius: 7px;
          display: grid; place-items: center;
          transition: background .15s ease, color .15s ease;
        }
        .pcm-crumb-icon:hover { background: rgba(0,0,0,0.05); color: var(--ink); }

        /* ---------- toolbar ---------- */
        .pcm-toolbar {
          max-width: 1280px;
          margin: 28px auto 22px;
          padding: 0 28px;
          position: sticky;
          top: 48px;
          z-index: 40;
        }
        .pcm-toolbar-inner {
          display: flex; align-items: center; gap: 4px;
          background: rgba(255,255,255,0.62);
          backdrop-filter: blur(28px) saturate(180%);
          -webkit-backdrop-filter: blur(28px) saturate(180%);
          border: 1px solid rgba(255,255,255,0.7);
          border-radius: 14px;
          padding: 5px;
          box-shadow:
            0 1px 0 rgba(255,255,255,0.7) inset,
            0 6px 20px rgba(35,18,8,0.05);
        }
        .pcm-toolbar-divider {
          width: 1px; align-self: stretch;
          background: rgba(0,0,0,0.07);
          margin: 6px 8px;
        }
        .pcm-bar-divider {
          width: 1px; height: 18px;
          background: rgba(0,0,0,0.08);
          flex-shrink: 0;
        }
        .pcm-toolbar-right {
          margin-left: auto;
          display: flex; align-items: center; gap: 14px;
          padding-left: 4px;
        }
        .pcm-context-group {
          display: inline-flex; align-items: center; gap: 8px;
        }

        /* ---------- filter chips ---------- */
        .filters {
          display: inline-flex; gap: 2px;
        }
        .chip {
          appearance: none; border: none; background: transparent;
          font-family: inherit; font-size: 13px; font-weight: 500;
          color: var(--ink-3); padding: 8px 14px; border-radius: 9px;
          cursor: pointer; letter-spacing: -0.005em;
          transition: background .15s ease, color .15s ease;
        }
        .chip:hover { color: var(--ink-2); background: rgba(0,0,0,0.035); }
        .chip.active {
          background: white; color: var(--ink);
          box-shadow: 0 1px 2px rgba(0,0,0,0.06), 0 0 0 0.5px rgba(0,0,0,0.04);
        }
        .chip.active:hover { background: white; }

        /* ---------- progress ---------- */
        .pcm-progress {
          display: flex; align-items: center; gap: 10px;
          font-size: 12px; color: var(--ink-3); font-weight: 500;
          padding-left: 4px;
        }
        .pcm-progress-bar {
          width: 88px; height: 3px;
          background: rgba(0,0,0,0.08);
          border-radius: 99px; overflow: hidden;
        }
        .pcm-progress-bar > div {
          height: 100%; background: var(--ink);
          border-radius: 99px;
          transition: width .35s cubic-bezier(.4,0,.2,1);
        }

        /* ---------- status pill ---------- */
        .pcm-bar-status {
          display: inline-flex; align-items: center; gap: 7px;
          font-size: 12.5px; color: var(--ink-2); font-weight: 500;
          letter-spacing: -0.005em;
          padding: 7px 12px 7px 10px;
          background: rgba(212,160,23,0.10);
          border: 1px solid rgba(212,160,23,0.20);
          border-radius: 99px;
          white-space: nowrap;
        }
        .pcm-bar-status .dot {
          width: 7px; height: 7px; border-radius: 99px;
          background: #d4a017;
          box-shadow: 0 0 0 3px rgba(212,160,23,0.18);
          flex-shrink: 0;
        }
        .pcm-bar-status.is-approved {
          background: rgba(24,169,87,0.10);
          border-color: rgba(24,169,87,0.22);
        }
        .pcm-bar-status.is-approved .dot {
          background: #18a957;
          box-shadow: 0 0 0 3px rgba(24,169,87,0.18);
        }

        /* ---------- deadline ---------- */
        .pcm-bar-deadline {
          font-size: 12.5px; color: var(--ink-3); font-weight: 500;
          letter-spacing: -0.005em;
          display: inline-flex; align-items: baseline; gap: 5px;
          padding: 0 4px 0 8px;
          white-space: nowrap;
        }
        .pcm-bar-deadline b { font-weight: 600; color: var(--ink-2); }
        .pcm-bar-deadline .countdown { color: var(--ink-4); }
        .pcm-bar-deadline.is-urgent b,
        .pcm-bar-deadline.is-urgent .countdown { color: #b8801a; }
        .pcm-bar-deadline.is-overdue b,
        .pcm-bar-deadline.is-overdue .countdown { color: #c2410c; }

        /* ---------- approve all button ---------- */
        .pcm-approve-all {
          appearance: none; border: none; cursor: pointer;
          color: white;
          font-family: inherit; font-size: 13px; font-weight: 600;
          padding: 8px 16px; border-radius: 9px;
          letter-spacing: -0.005em;
          background-color: var(--approve);
          background-image: linear-gradient(105deg,
            rgba(255,255,255,0) 35%,
            rgba(255,255,255,0.32) 47%,
            rgba(255,255,255,0.55) 50%,
            rgba(255,255,255,0.32) 53%,
            rgba(255,255,255,0) 65%
          );
          background-size: 250% 100%;
          background-position: 220% 0;
          background-repeat: no-repeat;
          box-shadow: 0 1px 2px rgba(24,169,87,0.3), 0 2px 8px rgba(24,169,87,0.22);
          transition: transform .15s ease, box-shadow .15s ease, background-color .15s ease;
          display: inline-flex; align-items: center; gap: 7px;
          animation: approveShimmer 5.5s ease-in-out infinite;
        }
        .pcm-approve-all:hover {
          background-color: #15974d;
          box-shadow: 0 1px 2px rgba(24,169,87,0.35), 0 4px 12px rgba(24,169,87,0.3);
        }
        .pcm-approve-all:active { transform: translateY(1px); }
        @keyframes approveShimmer {
          0%   { background-position: 220% 0; }
          55%  { background-position: -120% 0; }
          100% { background-position: -120% 0; }
        }
        @media (prefers-reduced-motion: reduce) {
          .pcm-approve-all { animation: none; }
        }

        /* ---------- grid ---------- */
        .pcm-grid {
          display: grid;
          grid-template-columns: repeat(3, 1fr);
          gap: 22px;
        }
        @media (max-width: 1024px) { .pcm-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 640px)  { .pcm-grid { grid-template-columns: 1fr; } }

        /* ---------- cards ---------- */
        .pcm-card {
          position: relative;
          background: rgba(255,255,255,0.7);
          backdrop-filter: blur(30px) saturate(180%);
          -webkit-backdrop-filter: blur(30px) saturate(180%);
          border: 1px solid rgba(255,255,255,0.75);
          border-radius: 16px;
          box-shadow:
            0 1px 0 rgba(255,255,255,0.85) inset,
            0 12px 28px rgba(50,30,15,0.06);
          overflow: hidden;
          display: flex; flex-direction: column;
          height: 400px;
          transition: transform .2s ease, box-shadow .25s ease;
        }
        .pcm-card:hover {
          transform: translateY(-2px);
          box-shadow:
            0 1px 0 rgba(255,255,255,0.85) inset,
            0 18px 38px rgba(50,30,15,0.09);
        }

        /* ---------- card media ---------- */
        .pcm-card-media {
          position: relative; height: 210px;
          background: #efe7df; overflow: hidden;
        }
        .pcm-card-media img {
          width: 100%; height: 100%; object-fit: cover; display: block;
          transition: transform .6s cubic-bezier(.2,.6,.2,1);
        }
        .pcm-card:hover .pcm-card-media img { transform: scale(1.03); }
        .pcm-card-media video {
          width: 100%; height: 100%; object-fit: cover; display: block;
        }

        /* ---------- badge ---------- */
        .pcm-badge {
          position: absolute; left: 10px; top: 10px;
          background: rgba(0,0,0,0.55);
          color: white;
          padding: 4px 8px 4px 6px;
          border-radius: 99px;
          font-size: 10.5px; font-weight: 500;
          letter-spacing: 0.02em;
          display: inline-flex; align-items: center; gap: 4px;
          backdrop-filter: blur(8px);
        }
        .pcm-badge svg { width: 10px; height: 10px; }

        /* ---------- duration ---------- */
        .pcm-duration {
          position: absolute; right: 10px; bottom: 10px;
          background: rgba(0,0,0,0.6);
          color: white;
          padding: 2px 7px; border-radius: 5px;
          font-size: 11px; font-weight: 500;
          backdrop-filter: blur(8px);
          font-variant-numeric: tabular-nums;
        }

        /* ---------- card body ---------- */
        .pcm-card-body {
          padding: 12px 14px 0;
          flex: 1; display: flex; flex-direction: column; min-height: 0;
        }
        .pcm-typestrip {
          display: flex; align-items: center; gap: 7px;
          font-size: 10.5px; color: var(--ink-4); font-weight: 600;
          letter-spacing: 0.05em; text-transform: uppercase;
        }
        .pcm-typestrip .dot { width: 3px; height: 3px; background: #c8bdb1; border-radius: 99px; }
        .pcm-card-title {
          margin-top: 5px;
          font-size: 14px; font-weight: 600;
          letter-spacing: -0.012em; color: var(--ink);
          line-height: 1.3;
        }

        /* ---------- copy card variant ---------- */
        .pcm-card.copy .pcm-copy-body {
          padding: 18px 18px 0;
          flex: 1;
          display: flex; flex-direction: column; gap: 9px;
          overflow: hidden;
        }
        .pcm-copy-platform {
          font-size: 10.5px; font-weight: 600;
          letter-spacing: 0.08em; text-transform: uppercase;
          color: var(--ink-4);
        }
        .pcm-copy-headline {
          font-size: 17px; font-weight: 600;
          letter-spacing: -0.016em; color: var(--ink);
          line-height: 1.22;
        }
        .pcm-copy-text {
          font-size: 12.5px; color: var(--ink-2); line-height: 1.5;
        }
        .pcm-copy-meta {
          margin-top: auto; padding-top: 6px;
          font-size: 11px; color: var(--ink-4); font-weight: 500;
          display: flex; align-items: center; gap: 10px;
        }
        .pcm-kv { display: inline-flex; align-items: center; gap: 4px; }
        .pcm-kv b { font-weight: 600; color: var(--ink-2); }

        /* ---------- card actions ---------- */
        .pcm-card-actions {
          margin-top: auto;
          padding: 10px 12px;
          display: flex; align-items: center; gap: 6px;
          border-top: 1px solid rgba(0,0,0,0.05);
        }
        .pcm-btn {
          appearance: none; border: none; cursor: pointer;
          font-family: inherit; font-size: 12.5px; font-weight: 500;
          padding: 7px 12px; border-radius: 99px;
          display: inline-flex; align-items: center; gap: 5px;
          transition: background .15s ease, color .15s ease;
          letter-spacing: -0.005em;
        }
        .pcm-btn-approve {
          background: var(--ink); color: white;
        }
        .pcm-btn-approve:hover { background: #000; }
        .pcm-btn-comment {
          background: transparent; color: var(--ink-2);
        }
        .pcm-btn-comment:hover { background: rgba(0,0,0,0.05); }
        .pcm-btn svg { width: 13px; height: 13px; }

        /* ---------- card status ---------- */
        .pcm-status {
          margin-left: auto;
          font-size: 11px; color: var(--ink-4); font-weight: 500;
          display: inline-flex; align-items: center; gap: 5px;
        }
        .pcm-status .pulse {
          width: 6px; height: 6px; background: #d8c8b0; border-radius: 99px;
        }

        /* ---------- approved state ---------- */
        .pcm-card.approved {
          background: linear-gradient(180deg, rgba(220,246,230,0.78), rgba(255,255,255,0.7));
        }
        .pcm-card.approved .pcm-status { color: var(--approve); }
        .pcm-card.approved .pcm-status .pulse { background: var(--approve); }
        .pcm-card.approved .pcm-btn-approve {
          background: var(--approve);
        }
      `}</style>

      {/* Notion-Style Breadcrumb Header */}
      <ClientBreadcrumbs
        brandName={brandName}
        campaignName={campaignName}
        shareUrl={window.location.href}
      />

      {/* Hero Header */}
      <section className="max-w-[1280px] w-full mx-auto px-7 pt-12 pb-5 select-none">
        <div className="text-[11.5px] font-semibold uppercase tracking-widest text-muted-foreground/80 mb-3.5">
          Round 1 · Asset Review
        </div>
        <h1 className="font-serif italic font-normal text-5xl md:text-[64px] leading-[1.0] text-foreground tracking-tight max-w-2xl">
          {totalCount} creative{totalCount !== 1 ? 's' : ''},<br />for your sign-off.
        </h1>
        <p className="mt-4.5 max-w-[580px] text-[15.5px] leading-[1.55] text-[#4a4239]">
          The summer drop, told in three image moments, two motion pieces, and four written rooms. Approve items below, then submit your feedback to our creative design team.
        </p>
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
        isSubmitting={submitMutation.isPending}
      />

      {/* Grid container */}
      <main className="max-w-[1280px] w-full mx-auto px-7 mt-4">
        {filteredAssets.length === 0 ? (
          <div className="w-full text-center py-16 bg-white/40 backdrop-blur-md rounded-2xl border border-border/40 select-none">
            <p className="text-sm font-semibold text-muted-foreground">No assets found in this category.</p>
          </div>
        ) : (
          <div className="pcm-grid transition-all duration-300">
            {filteredAssets.map((item) => {
              const isApproved = item.type === 'media'
                ? approvedVisualIds.includes(item.id)
                : approvedCopyIds.includes(item.id);
              const comment = comments[item.id];

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
        className="fixed bottom-4 left-4 right-4 md:left-6 md:right-6 z-40 bg-white/90 backdrop-blur-xl border border-border px-5 py-3.5 shadow-2xl flex flex-col md:flex-row md:items-center justify-between gap-4 max-w-5xl mx-auto rounded-2xl transition-all duration-200"
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
