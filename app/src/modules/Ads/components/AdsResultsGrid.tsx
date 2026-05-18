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
import type { AdCreative, AdsProgress, AdsPhase } from '../types';
import { AdCard } from './AdCard';

// ============================================================================
// Props
// ============================================================================

interface AdsResultsGridProps {
  /** Generated ad creatives */
  creatives: AdCreative[];
  /** Current pipeline phase */
  phase: AdsPhase;
  /** Progress within current phase */
  progress: AdsProgress | null;
  /** Whether generation is active */
  isGenerating: boolean;
  /** Error message */
  error: string | null;
  /** Called when a text field on a card is edited */
  onTextUpdate?: (
    creativeId: string,
    updates: { headline?: string; body?: string; cta?: string },
  ) => void;
  /** Called when an image is clicked (detail view) */
  onImageClick?: (creative: AdCreative) => void;
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
      {/* Image skeleton */}
      <div className="w-full bg-gray-200" style={{ aspectRatio: '16/9' }} />
      {/* Text skeleton */}
      <div style={{ padding: '0.875rem 1rem' }}>
        <div className="h-2 w-16 rounded bg-blue-100 mb-2" />
        <div className="h-3.5 w-3/4 rounded bg-gray-200 mb-2" />
        <div className="space-y-1.5 mb-3">
          <div className="h-2.5 w-full rounded bg-gray-100" />
          <div className="h-2.5 w-5/6 rounded bg-gray-100" />
        </div>
        <div className="h-6 w-24 rounded-full bg-blue-50" />
      </div>
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
          Ready to create ads
        </p>
        <p className="text-xs leading-relaxed" style={{ color: colors.textFaint }}>
          Write a creative brief in the sidebar, select your text and image models,
          then click <strong>Generate Ads</strong> to create complete ad creatives
          with images and copy.
        </p>
      </div>
    </div>
  );
}

/** Phase label for progress display */
function getPhaseLabel(phase: AdsPhase): React.ReactNode {
  switch (phase) {
    case 'text_phase': return <span className="flex items-center gap-1.5"><FileText className="w-3.5 h-3.5" /> Generating copy</span>;
    case 'image_phase': return <span className="flex items-center gap-1.5"><ImageIcon className="w-3.5 h-3.5" /> Generating images</span>;
    case 'compose_phase': return <span className="flex items-center gap-1.5"><Layers className="w-3.5 h-3.5" /> Assembling ads</span>;
    case 'video_phase': return <span className="flex items-center gap-1.5"><Video className="w-3.5 h-3.5" /> Generating video</span>;
    default: return null;
  }
}

// ============================================================================
// Component
// ============================================================================

export const AdsResultsGrid = memo(function AdsResultsGrid({
  creatives,
  phase,
  progress,
  isGenerating,
  error,
  onTextUpdate,
  onImageClick,
}: AdsResultsGridProps) {
  const hasCreatives = creatives.length > 0;

  return (
    <div className="h-full flex flex-col">
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
            Ad Creatives
          </h2>
          {hasCreatives && (
            <span
              className="text-[11px] font-semibold px-2 py-0.5 rounded-full"
              style={{ background: '#f0fdf4', color: '#16a34a' }}
            >
              {creatives.length} ads
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
      {!hasCreatives && !isGenerating && <EmptyState />}

      {/* ── Loading skeleton ── */}
      {!hasCreatives && isGenerating && (
        <div className="flex-1 overflow-y-auto">
          <div
            className="mx-auto"
            style={{
              maxWidth: '100%',
              padding: '2rem clamp(1rem, 5vw, 4rem)',
            }}
          >
            <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
              {Array.from({ length: 6 }).map((_, i) => (
                <CardSkeleton key={i} />
              ))}
            </div>
          </div>
        </div>
      )}

      {/* ── Populated grid ── */}
      {hasCreatives && (
        <div className="flex-1 overflow-y-auto">
          <div
            className="mx-auto"
            style={{
              maxWidth: '100%',
              padding: '2rem clamp(1rem, 5vw, 4rem)',
            }}
          >
            <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
              {creatives.map((creative) => (
                <AdCard
                  key={creative.id}
                  creative={creative}
                  onTextUpdate={onTextUpdate}
                  onImageClick={onImageClick}
                />
              ))}
            </div>
          </div>
        </div>
      )}
    </div>
  );
});
