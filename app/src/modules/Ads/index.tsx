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
import type { GenerationMode, ListItem, AngleItem } from '@/components/shared';
import type { SessionReferenceImage } from '@shared/referenceImageIntents';
import { mapBrandToFormValues, mapScrapedToFormValues } from '@shared/brandTypes';
import { useTextModels } from '@/modules/Copy/useTextModels';
import { useAdsOrchestration } from './hooks/useAdsOrchestration';
import { useBrandSync } from './hooks/useBrandSync';
import { AdsSidebar } from './components/AdsSidebar';
import { AdsResultsGrid } from './components/AdsResultsGrid';
import { ADS_DEFAULTS } from './adsConfig';
import { BulkActionBar } from '@/components/shared/BulkActionBar';
import { Download, Share2 } from 'lucide-react';
import { toast } from 'sonner';
import { exportToMetaAdsZip } from './utils/metaAdsExport';
import { CreateApprovalSetDialog } from './components/CreateApprovalSetDialog';

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
  const [sessionReferenceImages, setSessionReferenceImages] = useState<SessionReferenceImage[]>([]);
  const [formValues, setFormValues] = useState<Record<string, string | number | undefined>>({});

  // ── Brief ──
  const [brief, setBrief] = useState('');

  // ── Copy type selection (social_ads, social_organic) ──
  const [selectedTypes, setSelectedTypes] = useState<Record<string, boolean>>({
    social_ads: true,
    social_organic: false,
  });

  // ── Audiences & Angles ──
  const [audiences, setAudiences] = useState<ListItem[]>([]);
  const [angles, setAngles] = useState<AngleItem[]>([]);

  // ── Generation settings (mode + count for audiences/angles) ──
  const [genSettings, setGenSettings] = useState({
    audiencesMode: 'auto' as GenerationMode,
    audiencesCount: 3,
    anglesMode: 'auto' as GenerationMode,
    anglesCount: 3,
  });

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
  const [imageVariations, setImageVariations] = useState<number>(ADS_DEFAULTS.imageVariations);

  // ── R2 parameters ──
  const [autoOptimizeBrief, setAutoOptimizeBrief] = useState(false);
  const [numVersions, setNumVersions] = useState<number>(1);

  // ── Selection State (Client Board) ──
  const [selectedVisualIds, setSelectedVisualIds] = useState<string[]>([]);
  const [selectedCopyIds, setSelectedCopyIds] = useState<string[]>([]);
  const [isShareDialogOpen, setIsShareDialogOpen] = useState(false);

  // ── Orchestration hook ──
  const orchestration = useAdsOrchestration();

  // ── Sync Hook ──
  const { handleContextChange, handleUrlFetched } = useBrandSync(setFormValues, setContextData);

  // ── Handlers ──

  const handleFormChange = useCallback((fieldId: string, value: string | number) => {
    setFormValues((prev) => ({ ...prev, [fieldId]: value }));
  }, []);

  /** Batch update multiple form fields in a single render (used by ReferenceAdsSection for re-indexing) */
  const handleBatchChange = useCallback((updates: Record<string, string | undefined>) => {
    setFormValues((prev) => {
      const next = { ...prev };
      for (const [key, val] of Object.entries(updates)) {
        if (val === undefined) {
          delete next[key];
        } else {
          next[key] = val;
        }
      }
      return next;
    });
  }, []);

  const handleImageModelsChange = useCallback((ids: string[]) => {
    setSelectedImageModels(ids);
  }, []);

  // Active copy types for generation
  const activeTypes = Object.keys(selectedTypes).filter((t) => selectedTypes[t]);

  const handleGenerate = useCallback(() => {
    // Clear selection on new generation
    setSelectedVisualIds([]);
    setSelectedCopyIds([]);
    
    orchestration.generate({
      brief,
      textModelId,
      imageModelIds: selectedImageModels,
      videoModelId,
      // Pass dynamic generation settings instead of hardcoded values
      copyTypes: activeTypes,
      audiences: {
        mode: genSettings.audiencesMode,
        items: audiences,
        count: genSettings.audiencesCount,
      },
      angles: {
        mode: genSettings.anglesMode,
        items: angles,
        count: genSettings.anglesCount,
      },
      imageVariations,
      formValues,
      contextData,
      sessionReferenceImages,
      autoOptimizeBrief,
      numVersions,
    });
  }, [brief, textModelId, selectedImageModels, videoModelId, activeTypes, genSettings, audiences, angles, imageVariations, formValues, contextData, sessionReferenceImages, autoOptimizeBrief, numVersions, orchestration]);

  const handleGenerateAudiences = useCallback(async () => {
    return orchestration.generateAudiences({
      brief,
      textModelId,
      count: genSettings.audiencesCount,
      formValues,
    });
  }, [brief, textModelId, genSettings.audiencesCount, formValues, orchestration]);

  const handleGenerateAngles = useCallback(async () => {
    return orchestration.generateAngles({
      brief,
      textModelId,
      count: genSettings.anglesCount,
      formValues,
      audiences,
    });
  }, [brief, textModelId, genSettings.anglesCount, formValues, audiences, orchestration]);

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

  const selectionCount = selectedVisualIds.length + selectedCopyIds.length;

  const [isExporting, setIsExporting] = useState(false);

  const handleExport = useCallback(async () => {
    if (selectionCount === 0) return;
    
    setIsExporting(true);
    toast.info(`Exporting ${selectionCount} assets for Meta...`);
    
    try {
      const selectedTextSlots = orchestration.textSlots.filter(t => selectedCopyIds.includes(t.id));
      const selectedMediaSlots = orchestration.mediaSlots.filter(m => selectedVisualIds.includes(m.id));
      
      // If none selected, fallback to exporting everything?
      // Wait, BulkActionBar only shows when selectionCount > 0
      
      await exportToMetaAdsZip(selectedTextSlots, selectedMediaSlots);
      toast.success('Export complete!');
      clearSelection();
    } catch (err: any) {
      console.error(err);
      toast.error('Export failed: ' + err.message);
    } finally {
      setIsExporting(false);
    }
  }, [selectionCount, selectedCopyIds, selectedVisualIds, orchestration.textSlots, orchestration.mediaSlots, clearSelection]);

  // ── Render ──
  return (
    <div className="flex h-full overflow-hidden">
      {/* Sidebar — input controls */}
      <AdsSidebar
        contextData={contextData}
        onContextChange={handleContextChange}
        onUrlFetched={handleUrlFetched}
        sessionReferenceImages={sessionReferenceImages}
        onSessionReferenceImagesChange={setSessionReferenceImages}
        formValues={formValues}
        onFormChange={handleFormChange}
        onBatchChange={handleBatchChange}
        brief={brief}
        onBriefChange={setBrief}
        selectedTypes={selectedTypes}
        onSelectedTypesChange={setSelectedTypes}
        audiences={audiences}
        onAudiencesChange={setAudiences}
        angles={angles}
        onAnglesChange={setAngles}
        genSettings={genSettings}
        onGenSettingsChange={setGenSettings}
        textModelId={textModelId}
        onTextModelChange={setTextModelId}
        imageModelIds={selectedImageModels}
        onImageModelsChange={handleImageModelsChange}
        videoModelId={videoModelId}
        onVideoModelChange={() => {}} // Fishbone — no-op
        imageVariations={imageVariations}
        onImageVariationsChange={setImageVariations}
        autoOptimizeBrief={autoOptimizeBrief}
        onAutoOptimizeBriefChange={setAutoOptimizeBrief}
        numVersions={numVersions}
        onNumVersionsChange={setNumVersions}
        isGenerating={orchestration.isGenerating}
        onGenerate={handleGenerate}
        onCancel={orchestration.cancel}
        onGenerateAudiences={handleGenerateAudiences}
        onGenerateAngles={handleGenerateAngles}
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
          audiences={audiences}
        />
      </div>

      {/* ── Client Board Export Bar ── */}
      <BulkActionBar count={selectionCount} onClear={clearSelection}>
        <BulkActionBar.Action
          icon={Share2}
          label="Share with Client"
          onClick={() => setIsShareDialogOpen(true)}
        />
        <BulkActionBar.Action
          icon={Download}
          label={isExporting ? "Exporting..." : "Export for Meta (ZIP)"}
          onClick={handleExport}
        />
      </BulkActionBar>

      <CreateApprovalSetDialog
        isOpen={isShareDialogOpen}
        onClose={() => setIsShareDialogOpen(false)}
        selectedVisualIds={selectedVisualIds}
        selectedCopyIds={selectedCopyIds}
        mediaSlots={orchestration.mediaSlots}
        textSlots={orchestration.textSlots}
        brandId={contextData.brandId}
        projectId={contextData.brand ? (contextData.brand as any).projectId : null}
        brandName={contextData.brand ? contextData.brand.name : null}
        brandLogoUrl={contextData.brand ? (contextData.brand as any).logoUrl : null}
      />
    </div>
  );
}
