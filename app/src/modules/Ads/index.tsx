/**
 * ADS MODULE — Entry point / Orchestrator component
 *
 * The simplest module with the most power.
 * Wires the sidebar (input) to the results grid (output) via
 * the useAdsOrchestration hook that orchestrates Copy + Image backends.
 *
 * Layout: Split-panel (sidebar left, results right)
 * Pattern: Mirrors Image module's index.tsx structure
 */

import { useState, useCallback, useEffect, useMemo } from 'react';
import type { ContextData, ScrapedBusinessData } from '@/components/shared';
import { mapBrandToFormValues, mapScrapedToFormValues } from '@shared/brandTypes';
import { useTextModels } from '@/modules/Copy/useTextModels';
import { useImageModelsForGeneration, TIER_CONFIG } from '@/hooks/useModelsForGeneration';
import { useAdsOrchestration } from './hooks/useAdsOrchestration';
import { AdsSidebar } from './components/AdsSidebar';
import { AdsResultsGrid } from './components/AdsResultsGrid';
import { ADS_DEFAULTS } from './adsConfig';
import type { CostTier } from '@/types';

// ============================================================================
// Component
// ============================================================================

export function AdsModule() {
  // ── Shared context state (brand, URL, theme) ──
  // All fields must be initialized to prevent crashes in ContextPanel (value.url.trim())
  const [contextData, setContextData] = useState<ContextData>({
    brandId: undefined,
    brand: null,
    url: '',
    scrapedData: null,
    seasonEvent: '',
    campaignTheme: '',
  });
  const [formValues, setFormValues] = useState<Record<string, string | number | undefined>>({});

  // ── Brief ──
  const [brief, setBrief] = useState('');

  // ── Text model ──
  const { textModels, groups: textModelGroups } = useTextModels();
  const [textModelId, setTextModelId] = useState('');

  // Auto-select first text model when models load (useEffect avoids setState-during-render)
  useEffect(() => {
    if (!textModelId && textModels.length > 0) {
      setTextModelId(textModels[0].id);
    }
  }, [textModels, textModelId]);

  // ── Image models ──
  const [selectedImageModels, setSelectedImageModels] = useState<string[]>([]);

  // ── Video model (fishbone) ──
  const [videoModelId] = useState('');

  // ── Production params ──
  const [angles, setAngles] = useState(ADS_DEFAULTS.angleCount);
  const [imageVariations, setImageVariations] = useState(ADS_DEFAULTS.imageVariations);

  // ── Orchestration hook ──
  const orchestration = useAdsOrchestration();

  // ── Handlers ──
  const handleContextChange = useCallback((newCtx: ContextData) => {
    setContextData((prevCtx) => {
      // Check if brand changed
      const brandChanged = newCtx.brandId !== prevCtx.brandId;
      const brandDataUpdated = newCtx.brand !== prevCtx.brand;
      if ((brandChanged || brandDataUpdated) && newCtx.brand) {
        const mapped = mapBrandToFormValues(newCtx.brand as Record<string, any>);
        if (Object.keys(mapped).length > 0) {
          setFormValues((prevForm) => ({ ...prevForm, ...mapped }));
        }
      }
      return newCtx;
    });
  }, []);

  const handleUrlFetched = useCallback((scraped: ScrapedBusinessData) => {
    const mapped = mapScrapedToFormValues(scraped);
    if (Object.keys(mapped).length > 0) {
      setFormValues((prev) => ({ ...prev, ...mapped }));
    }
  }, []);

  const handleFormChange = useCallback((fieldId: string, value: string | number) => {
    setFormValues((prev) => ({ ...prev, [fieldId]: value }));
  }, []);

  const handleImageModelsChange = useCallback((ids: string[]) => {
    setSelectedImageModels(ids);
  }, []);

  const handleGenerate = useCallback(() => {
    orchestration.generate({
      brief,
      textModelId,
      imageModelIds: selectedImageModels,
      videoModelId,
      angles,
      imageVariations,
      formValues,
      contextData,
    });
  }, [brief, textModelId, selectedImageModels, videoModelId, angles, imageVariations, formValues, contextData, orchestration]);

  // ── Render ──
  return (
    <div className="flex h-full overflow-hidden">
      {/* Sidebar — input controls */}
      <AdsSidebar
        contextData={contextData}
        onContextChange={handleContextChange}
        onUrlFetched={handleUrlFetched}
        formValues={formValues}
        onFormChange={handleFormChange}
        brief={brief}
        onBriefChange={setBrief}
        textModelId={textModelId}
        onTextModelChange={setTextModelId}
        imageModelIds={selectedImageModels}
        onImageModelsChange={handleImageModelsChange}
        videoModelId={videoModelId}
        onVideoModelChange={() => {}} // Fishbone — no-op
        angles={angles}
        onAnglesChange={setAngles}
        imageVariations={imageVariations}
        onImageVariationsChange={setImageVariations}
        isGenerating={orchestration.isGenerating}
        onGenerate={handleGenerate}
      />

      {/* Results grid — output */}
      <div className="flex-1 overflow-hidden">
        <AdsResultsGrid
          creatives={orchestration.creatives}
          phase={orchestration.phase}
          progress={orchestration.progress}
          isGenerating={orchestration.isGenerating}
          error={orchestration.error}
          onTextUpdate={orchestration.updateCreativeText}
        />
      </div>
    </div>
  );
}
