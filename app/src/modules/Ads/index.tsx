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
  const [contextData, setContextData] = useState<ContextData>({});
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
  const {
    imageModelsByTier: rawImageModelsByTier,
  } = useImageModelsForGeneration();

  const [selectedImageModels, setSelectedImageModels] = useState<string[]>([]);
  const [expandedTiers, setExpandedTiers] = useState<Record<CostTier, boolean>>({
    budget: false,
    standard: false,
    premium: false,
  });

  // Standardize image models for sidebar display (memoized to prevent re-renders)
  const imageModelsByTier = useMemo(() => ({
    budget: rawImageModelsByTier.budget.map((m) => ({
      id: m.id, name: m.name, provider: m.provider, costTier: m.costTier,
    })),
    standard: rawImageModelsByTier.standard.map((m) => ({
      id: m.id, name: m.name, provider: m.provider, costTier: m.costTier,
    })),
    premium: rawImageModelsByTier.premium.map((m) => ({
      id: m.id, name: m.name, provider: m.provider, costTier: m.costTier,
    })),
  }), [rawImageModelsByTier]);

  // ── Video model (fishbone) ──
  const [videoModelId] = useState('');

  // ── Production params ──
  const [imageVariations, setImageVariations] = useState(ADS_DEFAULTS.imageVariations);

  // ── Orchestration hook ──
  const orchestration = useAdsOrchestration();

  // ── Handlers ──
  const handleContextChange = useCallback((data: ContextData) => {
    setContextData(data);
  }, []);

  const handleUrlFetched = useCallback((data: ScrapedBusinessData) => {
    // Populate form values from scraped URL data
    if (data.businessName) setFormValues((prev) => ({ ...prev, business_name: data.businessName }));
    if (data.description) setFormValues((prev) => ({ ...prev, business_summary: data.description }));
    if (data.phone) setFormValues((prev) => ({ ...prev, phone: data.phone }));
    if (data.niche) setFormValues((prev) => ({ ...prev, niche: data.niche }));
  }, []);

  const handleFormChange = useCallback((fieldId: string, value: string | number) => {
    setFormValues((prev) => ({ ...prev, [fieldId]: value }));
  }, []);

  const handleToggleImageModel = useCallback((modelId: string) => {
    setSelectedImageModels((prev) =>
      prev.includes(modelId)
        ? prev.filter((id) => id !== modelId)
        : [...prev, modelId],
    );
  }, []);

  const handleToggleTier = useCallback((tier: CostTier) => {
    setExpandedTiers((prev) => ({ ...prev, [tier]: !prev[tier] }));
  }, []);

  const handleGenerate = useCallback(() => {
    orchestration.generate({
      brief,
      textModelId,
      imageModelIds: selectedImageModels,
      videoModelId,
      imageVariations,
      formValues,
      contextData,
    });
  }, [brief, textModelId, selectedImageModels, videoModelId, imageVariations, formValues, contextData, orchestration]);

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
        textModels={textModels}
        textModelGroups={textModelGroups}
        imageModelIds={selectedImageModels}
        onToggleImageModel={handleToggleImageModel}
        imageModelsByTier={imageModelsByTier}
        expandedTiers={expandedTiers}
        onToggleTier={handleToggleTier}
        videoModelId={videoModelId}
        onVideoModelChange={() => {}} // Fishbone — no-op
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
