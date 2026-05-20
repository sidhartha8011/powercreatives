/**
 * ADS RESULTS GRID — Grid layout for composed ad creatives
 *
 * Displays ad creatives in a responsive card grid with:
 * - Empty state (before generation)
 * - Loading skeleton (during generation)
 * - Progress bar (per-phase progress)
 * - Populated grid (after generation)
 *
 * Follows the same visual patterns as Copy's ResultsPanel
 * but renders AdCard components instead of text-only cards.
 */

import React, { memo } from 'react';
import { Megaphone, Loader2, FileText, ImageIcon, Layers, Video, AlertTriangle } from 'lucide-react';
import { colors, typography } from '@/components/shared';
import type { MediaSlot, TextSlot, AdsProgress, AdsPhase } from '../types';
import { AdVisualCard } from './AdVisualCard';
import { AdCopyCard } from './AdCopyCard';
import { AssetDetailView } from '@/components/AssetDetailView';
import type { Asset } from '@/components/AssetDetailView/types';
import { useState } from 'react';

// ============================================================================
// Props
// ============================================================================

interface AdsResultsGridProps {
  mediaSlots: MediaSlot[];
  textSlots: TextSlot[];
  phase: AdsPhase;
  progress: AdsProgress | null;
  isGenerating: boolean;
  error: string | null;
  selectedVisualIds: string[];
  selectedCopyIds: string[];
  onSelectVisual: (id: string) => void;
  onSelectCopy: (id: string) => void;
  onDownloadVisual?: (id: string) => void;
  onUpdateTextSlot?: (slotId: string, updates: { headline?: string; body?: string; cta?: string; description?: string; hashtags?: string[] }) => void;
  onRegenerateTextSlot?: (slotId: string, instruction?: string) => void;
  audiences?: { id: string; name: string }[];
}

// ============================================================================
// Sub-components
// ============================================================================

/** Loading skeleton card */
function CardSkeleton() {
  return (
    <div
      className="rounded-xl animate-pulse overflow-hidden"
      style={{
        background: colors.bgSurface,
        border: `1px solid ${colors.borderLight}`,
      }}
    >
      <div className="w-full bg-gray-200" style={{ aspectRatio: '16/9' }} />
    </div>
  );
}

/** Empty state — shown before any generation */
function EmptyState() {
  return (
    <div
      className="flex-1 flex items-center justify-center mx-8 my-4"
      style={{ borderRadius: '15px', background: '#f8f8f7' }}
    >
      <div className="text-center max-w-md">
        <Megaphone
          className="w-12 h-12 mx-auto mb-4"
          style={{ color: colors.textGhost }}
        />
        <p
          className="text-sm font-medium mb-2"
          style={{ color: colors.textSecondary }}
        >
          Ready to create assets
        </p>
        <p className="text-xs leading-relaxed" style={{ color: colors.textFaint }}>
          Write a creative brief in the sidebar, select your text and image models,
          then click <strong>Generate Ads</strong> to create independent visuals and copy variants.
        </p>
      </div>
    </div>
  );
}

/** Phase label for progress display */
function getPhaseLabel(phase: AdsPhase): React.ReactNode {
  switch (phase) {
    case 'text_phase': return <span className="flex items-center gap-1.5"><FileText className="w-3.5 h-3.5" /> Generating copy</span>;
    case 'image_phase': return <span className="flex items-center gap-1.5"><ImageIcon className="w-3.5 h-3.5" /> Generating visuals</span>;
    case 'video_phase': return <span className="flex items-center gap-1.5"><Video className="w-3.5 h-3.5" /> Generating video</span>;
    default: return null;
  }
}

// ============================================================================
// Component
// ============================================================================

export const AdsResultsGrid = memo(function AdsResultsGrid({
  mediaSlots,
  textSlots,
  phase,
  progress,
  isGenerating,
  error,
  selectedVisualIds,
  selectedCopyIds,
  onSelectVisual,
  onSelectCopy,
  onDownloadVisual,
  onUpdateTextSlot,
  onRegenerateTextSlot,
  audiences = [],
}: AdsResultsGridProps) {
  const hasAssets = mediaSlots.length > 0 || textSlots.length > 0;
  
  // State for the Image Detail View Modal
  const [detailAsset, setDetailAsset] = useState<Asset | null>(null);

  // Helper to map MediaSlot -> Asset for the DetailView
  const handleOpenDetailView = (media: MediaSlot) => {
    const asset: Asset = {
      id: media.dbAssetId || 0,
      userId: 0,
      type: 'image',
      url: media.url || '',
      thumbnailUrl: media.thumbnailUrl,
      prompt: media.prompt,
      model: media.modelName,
      createdAt: new Date(),
      updatedAt: new Date(),
    };
    setDetailAsset(asset);
  };

  // State for active audience tab
  const [activeAudience, setActiveAudience] = React.useState<string | null>(
    audiences.length > 0 ? audiences[0].id : null
  );

  React.useEffect(() => {
    if (audiences.length > 0) {
      if (!activeAudience || !audiences.find((a) => a.id === activeAudience)) {
        setActiveAudience(audiences[0].id);
      }
    } else {
      setActiveAudience(null);
    }
  }, [audiences, activeAudience]);

  // Filter text slots by active audience if available
  const visibleTextSlots = React.useMemo(() => {
    if (!activeAudience) return textSlots;
    const currentAudienceName = audiences.find(a => a.id === activeAudience)?.name;
    if (!currentAudienceName) return textSlots;
    return textSlots.filter(t => t.audienceName === currentAudienceName);
  }, [textSlots, activeAudience, audiences]);

  return (
    <div className="h-full flex flex-col bg-slate-50">
      {/* ── Header ── */}
      <div
        className="flex items-center justify-between shrink-0"
        style={{
          padding: '1rem 2rem',
          borderBottom: `1px solid ${colors.borderLight}`,
          background: colors.bgSurface,
        }}
      >
        <div className="flex items-center gap-3">
          <h2
            className="font-bold tracking-tight"
            style={{ color: colors.text, fontSize: '1rem' }}
          >
            Generated Assets
          </h2>
          {hasAssets && (
            <span
              className="text-[11px] font-semibold px-2 py-0.5 rounded-full"
              style={{ background: '#f0fdf4', color: '#16a34a' }}
            >
              {mediaSlots.length} Visuals &middot; {textSlots.length} Copy
            </span>
          )}
        </div>

        {/* Phase indicator during generation */}
        {isGenerating && (
          <div className="flex items-center gap-2">
            <Loader2 className="w-4 h-4 animate-spin" style={{ color: '#2563eb' }} />
            <span className="text-xs font-medium" style={{ color: colors.textSecondary }}>
              {getPhaseLabel(phase)}
            </span>
          </div>
        )}
      </div>

      {/* ── Progress bar ── */}
      {isGenerating && progress && progress.total > 0 && (
        <div
          style={{
            padding: '0.5rem 2rem',
            borderBottom: `1px solid ${colors.borderLight}`,
            background: colors.bgSurface,
          }}
        >
          <div className="flex items-center gap-3">
            <div
              className="flex-1 h-1.5 rounded-full overflow-hidden"
              style={{ background: colors.bgHover }}
            >
              <div
                className="h-full rounded-full transition-all duration-500 ease-out"
                style={{
                  width: `${Math.max(2, (progress.current / progress.total) * 100)}%`,
                  background: progress.phase === 'text_phase'
                    ? 'linear-gradient(90deg, #3b82f6, #6366f1)'
                    : progress.phase === 'image_phase'
                      ? 'linear-gradient(90deg, #10b981, #059669)'
                      : 'linear-gradient(90deg, #f59e0b, #d97706)',
                }}
              />
            </div>
            <span
              className="text-xs font-medium shrink-0"
              style={{ color: colors.textSecondary }}
            >
              {progress.current} / {progress.total}
            </span>
          </div>
          <p className="text-[11px] mt-1" style={{ color: colors.textFaint }}>
            {progress.label}
          </p>
        </div>
      )}

      {/* ── Error banner ── */}
      {error && (
        <div
          style={{
            padding: '0.625rem 2rem',
            background: '#fef2f2',
            borderBottom: '1px solid #fecaca',
          }}
        >
          <p className="text-xs flex items-center gap-1.5" style={{ color: '#dc2626' }}>
            <AlertTriangle className="w-3.5 h-3.5" /> {error}
          </p>
        </div>
      )}

      {/* ── Empty state ── */}
      {!hasAssets && !isGenerating && <EmptyState />}

      {/* ── Populated grid ── */}
      {(hasAssets || isGenerating) && (
        <div className="flex-1 overflow-y-auto px-8 py-6 pb-32 space-y-10">
          
          {/* Visuals Section */}
          {(mediaSlots.length > 0 || (isGenerating && phase === 'image_phase')) && (
            <section>
              <h3 className="text-sm font-bold text-slate-700 mb-4 uppercase tracking-wider">
                Generated Visuals ({mediaSlots.length})
              </h3>
              <div className="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-4">
                {mediaSlots.map((media) => (
                  <AdVisualCard
                    key={media.id}
                    media={media}
                    isSelected={selectedVisualIds.includes(media.id)}
                    onSelect={() => onSelectVisual(media.id)}
                    onDownload={() => onDownloadVisual?.(media.id)}
                    onViewDetails={() => handleOpenDetailView(media)}
                  />
                ))}
              </div>
            </section>
          )}

          {/* Copy Variants Section */}
          {(textSlots.length > 0 || (isGenerating && phase === 'text_phase')) && (
            <section className="flex flex-col h-full">
              <div className="flex items-center justify-between mb-4">
                <h3 className="text-sm font-bold text-slate-700 uppercase tracking-wider">
                  Copy Variants ({visibleTextSlots.length})
                </h3>
              </div>

              {/* Level 2: Audience Sub-Tabs (mirrors Copy module) */}
              {audiences.length > 0 && (
                <div
                  className="flex items-center gap-1 mb-6 rounded-lg"
                  style={{
                    padding: '0.5rem',
                    background: colors.bgMuted,
                  }}
                >
                  {audiences.map((audience) => {
                    const isActive = audience.id === activeAudience;
                    // Count how many textSlots belong to this audience
                    const count = textSlots.filter(t => t.audienceName === audience.name).length;
                    
                    return (
                      <button
                        key={audience.id}
                        onClick={() => setActiveAudience(audience.id)}
                        className="flex items-center gap-2 transition-colors"
                        style={{
                          padding: '0.375rem 0.75rem',
                          borderRadius: '9999px',
                          background: isActive ? '#fff' : 'transparent',
                          color: isActive ? colors.text : colors.textSecondary,
                          boxShadow: isActive ? '0 1px 3px rgba(0,0,0,0.1)' : 'none',
                          fontWeight: isActive ? typography.semibold : typography.medium,
                          fontSize: typography.sm,
                        }}
                      >
                        <span className="truncate max-w-[150px]">{audience.name}</span>
                        <span
                          className="flex items-center justify-center text-[10px]"
                          style={{
                            minWidth: '1.25rem',
                            height: '1.25rem',
                            borderRadius: '9999px',
                            background: isActive ? colors.bgMuted : 'transparent',
                            color: isActive ? colors.textSecondary : colors.textGhost,
                          }}
                        >
                          {count}
                        </span>
                      </button>
                    );
                  })}
                </div>
              )}

              <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                {visibleTextSlots.map((text) => (
                  <AdCopyCard
                    key={text.id}
                    text={text}
                    isSelected={selectedCopyIds.includes(text.id)}
                    onSelect={() => onSelectCopy(text.id)}
                    onUpdate={(updates) => onUpdateTextSlot?.(text.id, updates)}
                    onRegenerate={(instruction) => onRegenerateTextSlot?.(text.id, instruction)}
                  />
                ))}
              </div>
            </section>
          )}

        </div>
      )}

      {/* ── Asset Detail View Modal ── */}
      {detailAsset && (
        <AssetDetailView
          asset={detailAsset}
          isOpen={true}
          onClose={() => setDetailAsset(null)}
          // These could be wired up to orchestrator in the future
          onVariationsCreated={() => {}}
          onAssetRefined={(refinedAsset) => {
            // Stub: Ideally we would update the mediaSlot in orchestrator
          }}
        />
      )}
    </div>
  );
});
