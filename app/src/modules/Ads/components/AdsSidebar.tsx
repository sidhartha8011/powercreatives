/**
 * ADS SIDEBAR -- All sidebar controls for the Ads module
 *
 * Pure presentational component. Receives all state/callbacks as props.
 * No business logic -- those live in useAdsOrchestration.
 *
 * Layout:
 *   Collapsible accordion sections for Brand & Brief (shared context),
 *   then a segmented control [Image | Copy | Video] that shows one
 *   output-type panel at a time.
 *
 * Generate button lives in the module header (index.tsx), not here.
 */

import { memo, useCallback, useState } from 'react';
import { Textarea } from '@/components/ui/textarea';
import { Switch } from '@/components/ui/switch';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import {
  ContextPanel,
  EnhancedBrandSection,
  ThemeSelector,
  GlobalEngineSelector,
  GlobalProductionParameters,
  DEFAULT_BRAND_TOGGLES,
  ModeListBox,
  GroupedAnglesList,
  ReferenceAdsSection,
  CopyTypeSelector,
  AccordionSection,
} from '@/components/shared';
import type { ContextData, ScrapedBusinessData, GenerationMode, ListItem, AngleItem } from '@/components/shared';
import type { SessionReferenceImage } from '@shared/referenceImageIntents';
import { syncBrandAssetsToSession } from '@/lib/syncBrandAssetsToSession';
import {
  Wand2,
  Sparkles,
  Dices,
  ChevronDown,
  ImageIcon,
  FileText,
  Video,
} from 'lucide-react';

// ============================================================================
// Types
// ============================================================================

interface AdsGenSettings {
  audiencesMode: GenerationMode;
  audiencesCount: number;
  anglesMode: GenerationMode;
  anglesCount: number;
}

/** Which output-type tab is active in the segmented control */
type OutputTab = 'image' | 'copy' | 'video';



// ============================================================================
// Props
// ============================================================================

interface AdsSidebarProps {
  enabledOutputs: Record<string, boolean>;
  onEnabledOutputsChange: (val: Record<string, boolean>) => void;

  // -- Shared context --
  contextData: ContextData;
  onContextChange: (data: ContextData) => void;
  onUrlFetched?: (data: ScrapedBusinessData) => void;
  formValues: Record<string, string | number | undefined>;
  onFormChange: (fieldId: string, value: string | number) => void;
  onBatchChange: (updates: Record<string, string | undefined>) => void;
  sessionReferenceImages: SessionReferenceImage[];
  onSessionReferenceImagesChange: (imgs: SessionReferenceImage[]) => void;

  // -- Brief --
  brief: string;
  onBriefChange: (v: string) => void;

  // -- Copy types --
  selectedTypes: Record<string, boolean>;
  onSelectedTypesChange: (types: Record<string, boolean>) => void;

  // -- Audiences & Angles --
  audiences: ListItem[];
  onAudiencesChange: (items: ListItem[]) => void;
  angles: AngleItem[];
  onAnglesChange: (items: AngleItem[]) => void;
  genSettings: AdsGenSettings;
  onGenSettingsChange: (settings: AdsGenSettings) => void;

  // -- Text model --
  textModelId: string;
  onTextModelChange: (id: string) => void;

  // -- Image models --
  imageModelIds: string[];
  onImageModelsChange: (ids: string[]) => void;

  // -- Video model (fishbone) --
  videoModelId: string;
  onVideoModelChange: (id: string) => void;

  // -- Production params --
  imageVariations: number;
  onImageVariationsChange: (n: number) => void;

  // -- R2 variables --
  autoOptimizeBrief: boolean;
  onAutoOptimizeBriefChange: (v: boolean) => void;
  numVersions: number;
  onNumVersionsChange: (n: number) => void;

  // -- AI generation helpers --
  onGenerateAudiences?: () => Promise<ListItem[]>;
  onGenerateAngles?: () => Promise<AngleItem[]>;
}

// ============================================================================
// Component
// ============================================================================

export const AdsSidebar = memo(function AdsSidebar({
  enabledOutputs,
  onEnabledOutputsChange,
  contextData,
  onContextChange,
  onUrlFetched,
  formValues,
  onFormChange,
  onBatchChange,
  sessionReferenceImages,
  onSessionReferenceImagesChange,
  brief,
  onBriefChange,
  selectedTypes,
  onSelectedTypesChange,
  audiences,
  onAudiencesChange,
  angles,
  onAnglesChange,
  genSettings,
  onGenSettingsChange,
  textModelId,
  onTextModelChange,
  imageModelIds,
  onImageModelsChange,
  videoModelId,
  onVideoModelChange,
  imageVariations,
  onImageVariationsChange,
  autoOptimizeBrief,
  onAutoOptimizeBriefChange,
  numVersions,
  onNumVersionsChange,
  onGenerateAudiences,
  onGenerateAngles,
}: AdsSidebarProps) {

  // Determine if image inputs (logo/reference) are active
  const toggles = { ...DEFAULT_BRAND_TOGGLES, ...contextData.brandToggles };
  const requiresImageInput = !!toggles.useLogo;

  // -- Audience handlers --
  const handleAddAudience = useCallback((name: string) => {
    const id = `audience_${Date.now()}`;
    onAudiencesChange([...audiences, { id, name }]);
  }, [audiences, onAudiencesChange]);

  const handleRemoveAudience = useCallback((id: string) => {
    onAudiencesChange(audiences.filter((a) => a.id !== id));
  }, [audiences, onAudiencesChange]);

  return (
    <aside className="shrink-0 border-r border-border overflow-y-auto w-[22%] min-w-[280px] max-w-[380px] bg-sidebar">
      <div className="p-3 space-y-4">

        {/* ================================================================
         *  BRAND & CONTEXT — Accordion
         * ================================================================ */}
        <AccordionSection title="Brand" defaultOpen={true}>
          {/* Brand / URL Context */}
          <ContextPanel
            moduleId="ads"
            value={contextData}
            onChange={(newData) => {
              onContextChange(newData);
              const synced = syncBrandAssetsToSession(contextData, newData, sessionReferenceImages);
              if (synced) onSessionReferenceImagesChange(synced);
            }}
            onUrlFetched={onUrlFetched}
            hideTheme
            hideBrandHeader
          />

          {/* Business Info & Brand Assets (logo, colors, subjects) */}
          <EnhancedBrandSection
            contextData={contextData}
            onContextChange={onContextChange}
            formValues={formValues}
            onFormChange={onFormChange}
            referenceImages={sessionReferenceImages}
            onReferenceImagesChange={onSessionReferenceImagesChange}
          />
        </AccordionSection>

        {/* Theme (season, campaign) — standalone */}
        <AccordionSection title="Theme" defaultOpen={false}>
          <ThemeSelector
            seasonEvent={contextData.seasonEvent}
            campaignTheme={contextData.campaignTheme}
            onSeasonChange={(seasonEvent) => onContextChange({ ...contextData, seasonEvent })}
            onCampaignThemeChange={(campaignTheme) => onContextChange({ ...contextData, campaignTheme })}
            bare
          />
        </AccordionSection>

        {/* ================================================================
         *  CREATIVE BRIEF — Accordion
         * ================================================================ */}
        <AccordionSection
          title="Creative Brief"
          defaultOpen={true}
        >
          {/* AI Enhance toggle */}
          <div className="flex items-center justify-end gap-1.5">
            <Tooltip>
              <TooltipTrigger asChild>
                <label
                  htmlFor="auto-optimize"
                  className={`flex items-center gap-1 text-[11px] font-medium cursor-pointer select-none transition-colors ${autoOptimizeBrief
                      ? 'text-primary'
                      : 'text-muted-foreground hover:text-foreground'
                    }`}
                >
                  <Sparkles className={`w-3.5 h-3.5 transition-all ${autoOptimizeBrief
                      ? 'text-primary drop-shadow-[0_0_4px_hsl(var(--primary)/0.4)]'
                      : 'text-muted-foreground'
                    }`} />
                  AI Enhance
                </label>
              </TooltipTrigger>
              <TooltipContent side="left" className="max-w-[220px] text-xs">
                When enabled, AI rewrites your brief into a more detailed, optimized image prompt before generation
              </TooltipContent>
            </Tooltip>
            <Switch
              id="auto-optimize"
              checked={autoOptimizeBrief}
              onCheckedChange={onAutoOptimizeBriefChange}
              className="h-4 w-7 data-[state=checked]:bg-primary"
            />
          </div>

          <Textarea
            value={brief}
            onChange={(e) => onBriefChange(e.target.value)}
            placeholder="Describe what you want to advertise..."
            className="min-h-[100px] text-sm resize-none"
          />
        </AccordionSection>

        {/* ================================================================
         *  COPY OUTPUTS — Accordion
         * ================================================================ */}
        <AccordionSection
          title="Copy Settings"
          defaultOpen={false}
          disabled={!enabledOutputs.copy}
          onDisabledClick={() => onEnabledOutputsChange({ ...enabledOutputs, copy: true })}
          headerAction={
            <Switch
              checked={enabledOutputs.copy}
              onCheckedChange={(val) => onEnabledOutputsChange({ ...enabledOutputs, copy: val })}
            />
          }
        >
          {/* Select Copy Engine */}
          <div className="space-y-2">
            <span className="text-xs font-medium text-muted-foreground block">Copy Engine</span>
            <GlobalEngineSelector
              type="text"
              selectedIds={textModelId ? [textModelId] : []}
              onChange={(ids) => onTextModelChange(ids[0] || '')}
              multiSelect={false}
            />
          </div>

          {/* Copy Type (Social Ads / Social Organic) */}
          <CopyTypeSelector
            selection={selectedTypes}
            onChange={onSelectedTypesChange}
          />

          {/* Audiences */}
          <ModeListBox
            label="Audiences"
            mode={genSettings.audiencesMode}
            onModeChange={(m) => onGenSettingsChange({ ...genSettings, audiencesMode: m })}
            items={audiences}
            onAddItem={handleAddAudience}
            onRemoveItem={handleRemoveAudience}
            onItemsGenerated={onAudiencesChange}
            placeholder="Add audience..."
            autoCount={genSettings.audiencesCount}
            onAutoCountChange={(c) => onGenSettingsChange({ ...genSettings, audiencesCount: c })}
            onGenerate={onGenerateAudiences}
          />

          {/* Angles (grouped by audience) */}
          <GroupedAnglesList
            mode={genSettings.anglesMode}
            onModeChange={(m) => onGenSettingsChange({ ...genSettings, anglesMode: m })}
            items={angles}
            onItemsChange={onAnglesChange}
            audiences={audiences}
            autoCount={genSettings.anglesCount}
            onAutoCountChange={(c) => onGenSettingsChange({ ...genSettings, anglesCount: c })}
            onGenerate={onGenerateAngles}
            onItemsGenerated={onAnglesChange}
          />

          {/* Reference Ads */}
          <ReferenceAdsSection
            values={formValues}
            onChange={onFormChange}
            onBatchChange={onBatchChange}
          />
        </AccordionSection>

        {/* ================================================================
         *  IMAGE OUTPUTS — Accordion
         * ================================================================ */}
        <AccordionSection
          title="Image Settings"
          defaultOpen={false}
          disabled={!enabledOutputs.image}
          onDisabledClick={() => onEnabledOutputsChange({ ...enabledOutputs, image: true })}
          headerAction={
            <Switch
              checked={enabledOutputs.image}
              onCheckedChange={(val) => onEnabledOutputsChange({ ...enabledOutputs, image: val })}
            />
          }
        >
          {/* Select Image Engines */}
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <span className="text-xs font-medium text-muted-foreground">Image Engines</span>
              <span className="text-xs text-muted-foreground">{imageModelIds.length} selected</span>
            </div>
            <GlobalEngineSelector
              type="image"
              selectedIds={imageModelIds}
              onChange={onImageModelsChange}
              multiSelect={true}
              requiresImageInput={requiresImageInput}
            />
          </div>

          {/* Angles (Scenes) slider */}
          <div>
            <div className="flex items-center justify-between mb-2">
              <label className="text-xs text-muted-foreground flex items-center gap-1.5">
                <Dices className="w-3 h-3" />Angles (Scenes)
              </label>
              <span className="text-xs font-mono bg-muted px-2 py-0.5 rounded">{numVersions}</span>
            </div>
            <input
              type="range" min="1" max="8" value={numVersions}
              onChange={(e) => onNumVersionsChange(parseInt(e.target.value) || 1)}
              className="w-full h-1.5 bg-muted rounded-full appearance-none cursor-pointer accent-primary"
            />
            <div className="flex justify-between text-[10px] text-muted-foreground mt-1">
              <span>1</span><span>8</span>
            </div>
          </div>

          {/* Production Parameters (variations per model) */}
          <GlobalProductionParameters
            variations={imageVariations}
            onVariationsChange={onImageVariationsChange}
          />
        </AccordionSection>

        {/* ================================================================
         *  VIDEO OUTPUTS — Accordion
         * ================================================================ */}
        <AccordionSection
          title="Video Settings"
          defaultOpen={false}
          disabled={!enabledOutputs.video}
          onDisabledClick={() => onEnabledOutputsChange({ ...enabledOutputs, video: true })}
          headerAction={
            <Switch
              checked={enabledOutputs.video}
              onCheckedChange={(val) => onEnabledOutputsChange({ ...enabledOutputs, video: val })}
            />
          }
        >
          {/* Select Video Engine */}
          <div className="space-y-2">
            <span className="text-xs font-medium text-muted-foreground block">Video Engine</span>
            <GlobalEngineSelector
              type="video"
              selectedIds={videoModelId ? [videoModelId] : []}
              onChange={(ids) => onVideoModelChange(ids[0] || '')}
              multiSelect={false}
            />
          </div>
          <p className="text-[11px] text-muted-foreground text-center py-2">
            Video generation is coming soon
          </p>
        </AccordionSection>

        {/* Bottom spacer */}
        <div className="h-4" />
      </div>
    </aside>
  );
});
