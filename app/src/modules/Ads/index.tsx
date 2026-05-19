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
import { useBrandSync } from './hooks/useBrandSync';
import { AdsSidebar } from './components/AdsSidebar';
import { AdsResultsGrid } from './components/AdsResultsGrid';
import { ADS_DEFAULTS } from './adsConfig';
import { BulkActionBar } from '@/components/shared/BulkActionBar';
import { Download } from 'lucide-react';
import { toast } from 'sonner';
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

  // ── Selection State (Client Board) ──
  const [selectedVisualIds, setSelectedVisualIds] = useState<string[]>([]);
  const [selectedCopyIds, setSelectedCopyIds] = useState<string[]>([]);

  // ── Orchestration hook ──
  const orchestration = useAdsOrchestration();

  // ── Sync Hook ──
  const { handleContextChange, handleUrlFetched } = useBrandSync(setFormValues, setContextData);

  // ── Handlers ──

  const handleFormChange = useCallback((fieldId: string, value: string | number) => {
    setFormValues((prev) => ({ ...prev, [fieldId]: value }));
  }, []);

  const handleImageModelsChange = useCallback((ids: string[]) => {
    setSelectedImageModels(ids);
  }, []);

  const handleGenerate = useCallback(() => {
    // Clear selection on new generation
    setSelectedVisualIds([]);
    setSelectedCopyIds([]);
    
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

  const handleSelectVisual = useCallback((id: string) => {
    setSelectedVisualIds((prev) => prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]);
  }, []);

  const handleSelectCopy = useCallback((id: string) => {
    setSelectedCopyIds((prev) => prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]);
  }, []);

  const clearSelection = useCallback(() => {
    setSelectedVisualIds([]);
    setSelectedCopyIds([]);
  }, []);

  const handleExport = useCallback(() => {
    const total = selectedVisualIds.length + selectedCopyIds.length;
    toast.success(`Exporting ${total} assets for Meta...`);
    // Stub for actual ZIP export logic
    setTimeout(() => clearSelection(), 1000);
  }, [selectedVisualIds.length, selectedCopyIds.length, clearSelection]);

  const selectionCount = selectedVisualIds.length + selectedCopyIds.length;

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
          mediaSlots={orchestration.mediaSlots}
          textSlots={orchestration.textSlots}
          phase={orchestration.phase}
          progress={orchestration.progress}
          isGenerating={orchestration.isGenerating}
          error={orchestration.error}
          selectedVisualIds={selectedVisualIds}
          selectedCopyIds={selectedCopyIds}
          onSelectVisual={handleSelectVisual}
          onSelectCopy={handleSelectCopy}
          onUpdateTextSlot={orchestration.updateTextSlot}
          onRegenerateTextSlot={orchestration.regenerateTextSlot}
          onDownloadVisual={(id) => toast.success('Image downloaded')}
        />
      </div>

      {/* ── Client Board Export Bar ── */}
      <BulkActionBar count={selectionCount} onClear={clearSelection}>
        <BulkActionBar.Action
          icon={Download}
          label="Export for Meta (ZIP)"
          onClick={handleExport}
        />
      </BulkActionBar>
    </div>
  );
}
