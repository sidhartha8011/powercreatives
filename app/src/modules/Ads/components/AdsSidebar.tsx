/**
 * ADS SIDEBAR — All sidebar controls for the Ads module
 *
 * Pure presentational component. Receives all state/callbacks as props.
 * No business logic — those live in useAdsOrchestration.
 *
 * Sections:
 * 1. Brand / URL / Theme context (ContextPanel — shared)
 * 2. Enhanced Brand Section (business info, colors, logo — shared)
 * 3. Theme selector (season, campaign — shared)
 * 4. Creative Brief (textarea)
 * 5. Copy Type selector (shared)
 * 6. Audiences (ModeListBox — shared)
 * 7. Angles (GroupedAnglesList — shared)
 * 8. Advanced Options (DynamicSection — tone/emoji/cta — shared)
 * 9. Reference Ads (shared)
 * 10. Writer Model (text model dropdown)
 * 11. Image Models (multi-select with tier accordion)
 * 12. Video Model (dropdown — disabled fishbone)
 * 12. Video Engine (dropdown)
 */

import { memo, useCallback } from 'react';
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
  DynamicSection,
  ReferenceAdsSection,
  CopyTypeSelector,
  ADVANCED_COPY_OPTIONS,
} from '@/components/shared';
import type { ContextData, ScrapedBusinessData, GenerationMode, ListItem, AngleItem } from '@/components/shared';
import type { SessionReferenceImage } from '@shared/referenceImageIntents';
import { syncBrandAssetsToSession } from '@/lib/syncBrandAssetsToSession';
import {
  Wand2,
  Sparkles,
  Dices,
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

// ============================================================================
// Props
// ============================================================================

interface AdsSidebarProps {
  // ── Shared context ──
  contextData: ContextData;
  onContextChange: (data: ContextData) => void;
  onUrlFetched?: (data: ScrapedBusinessData) => void;
  formValues: Record<string, string | number | undefined>;
  onFormChange: (fieldId: string, value: string | number) => void;
  onBatchChange: (updates: Record<string, string | undefined>) => void;
  sessionReferenceImages: SessionReferenceImage[];
  onSessionReferenceImagesChange: (imgs: SessionReferenceImage[]) => void;

  // ── Brief ──
  brief: string;
  onBriefChange: (v: string) => void;

  // ── Copy types ──
  selectedTypes: Record<string, boolean>;
  onSelectedTypesChange: (types: Record<string, boolean>) => void;

  // ── Audiences & Angles ──
  audiences: ListItem[];
  onAudiencesChange: (items: ListItem[]) => void;
  angles: AngleItem[];
  onAnglesChange: (items: AngleItem[]) => void;
  genSettings: AdsGenSettings;
  onGenSettingsChange: (settings: AdsGenSettings) => void;

  // ── Text model ──
  textModelId: string;
  onTextModelChange: (id: string) => void;

  // ── Image models ──
  imageModelIds: string[];
  onImageModelsChange: (ids: string[]) => void;

  // ── Video model (fishbone) ──
  videoModelId: string;
  onVideoModelChange: (id: string) => void;

  // ── Production params ──
  imageVariations: number;
  onImageVariationsChange: (n: number) => void;

  // ── R2 variables ──
  autoOptimizeBrief: boolean;
  onAutoOptimizeBriefChange: (v: boolean) => void;
  numVersions: number;
  onNumVersionsChange: (n: number) => void;

  // ── AI generation helpers ──
  onGenerateAudiences?: () => Promise<ListItem[]>;
  onGenerateAngles?: () => Promise<AngleItem[]>;
}

// ============================================================================
// Component
// ============================================================================

export const AdsSidebar = memo(function AdsSidebar({
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

  // ── Audience handlers ──
  const handleAddAudience = useCallback((name: string) => {
    const id = `audience_${Date.now()}`;
    onAudiencesChange([...audiences, { id, name }]);
  }, [audiences, onAudiencesChange]);

  const handleRemoveAudience = useCallback((id: string) => {
    onAudiencesChange(audiences.filter((a) => a.id !== id));
  }, [audiences, onAudiencesChange]);

  return (
    <aside
      className="shrink-0 border-r border-border overflow-y-auto bg-muted/20"
      style={{ width: '22%', minWidth: '280px', maxWidth: '380px' }}
    >
      <div className="p-4 space-y-6">

        {/* ================================================================
         *  SHARED — Brand, context, brief (applies to all output types)
         * ================================================================ */}

        {/* Brand / URL Context */}
        <ContextPanel
          value={contextData}
          onChange={(newData) => {
            onContextChange(newData);
            const synced = syncBrandAssetsToSession(contextData, newData, sessionReferenceImages);
            if (synced) onSessionReferenceImagesChange(synced);
          }}
          onUrlFetched={onUrlFetched}
          hideTheme
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

        {/* Theme (season, campaign) */}
        <ThemeSelector
          seasonEvent={contextData.seasonEvent}
          campaignTheme={contextData.campaignTheme}
          onSeasonChange={(seasonEvent) => onContextChange({ ...contextData, seasonEvent })}
          onCampaignThemeChange={(campaignTheme) => onContextChange({ ...contextData, campaignTheme })}
        />

        {/* Creative Brief + AI Enhance toggle */}
        <section>
          <div className="flex items-center justify-between mb-3">
            <div className="flex items-center gap-2">
              <Wand2 className="w-4 h-4 text-muted-foreground" />
              <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                Creative Brief
              </h3>
            </div>
            <div className="flex items-center gap-1.5">
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
          </div>
          <Textarea
            value={brief}
            onChange={(e) => onBriefChange(e.target.value)}
            placeholder="Describe what you want to advertise..."
            className="min-h-[100px] text-sm resize-none"
          />
        </section>

        {/* ================================================================
         *  IMAGE — Engine, angles, production parameters
         * ================================================================ */}
        <div className="pt-2 border-t border-border">
          <h3 className="text-[10px] font-bold uppercase tracking-widest text-muted-foreground mb-4">
            Image Settings
          </h3>

          {/* Image Engine (model selection) */}
          <div className="space-y-3">
            <div className="bg-muted/30 rounded-lg p-2 border border-border">
              <GlobalEngineSelector
                type="image"
                title="Image"
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
          </div>
        </div>

        {/* ================================================================
         *  COPY — Engine, types, audiences, angles, reference ads
         * ================================================================ */}
        <div className="pt-2 border-t border-border">
          <h3 className="text-[10px] font-bold uppercase tracking-widest text-muted-foreground mb-4">
            Copy Settings
          </h3>

          <div className="space-y-4">
            {/* Copy Engine (text model) */}
            <div className="bg-muted/30 rounded-lg p-2 border border-border">
              <GlobalEngineSelector
                type="text"
                title="Copy"
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
          </div>
        </div>

        {/* ================================================================
         *  VIDEO — Engine (fishbone / placeholder)
         * ================================================================ */}
        <div className="pt-2 border-t border-border">
          <h3 className="text-[10px] font-bold uppercase tracking-widest text-muted-foreground mb-4">
            Video Settings
          </h3>
          <div className="bg-muted/30 rounded-lg p-2 border border-border">
            <GlobalEngineSelector
              type="video"
              title="Video"
              selectedIds={videoModelId ? [videoModelId] : []}
              onChange={(ids) => onVideoModelChange(ids[0] || '')}
              multiSelect={false}
            />
          </div>
        </div>

        {/* Bottom spacer */}
        <div className="h-4" />
      </div>
    </aside>
  );
});
