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
 * 5. Writer Model (text model dropdown)
 * 6. Image Models (multi-select with tier accordion)
 * 7. Video Model (dropdown — disabled fishbone)
 * 8. Production Parameters (variations slider)
 * 9. Generate button
 */

import { memo } from 'react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import {
  ContextPanel,
  EnhancedBrandSection,
  ThemeSelector,
  GlobalEngineSelector,
  GlobalProductionParameters,
  DEFAULT_BRAND_TOGGLES,
} from '@/components/shared';
import type { ContextData, ScrapedBusinessData } from '@/components/shared';
import type { SessionReferenceImage } from '@shared/referenceImageIntents';
import {
  Wand2,
  Cpu,
  SlidersHorizontal,
  Zap,
  Megaphone,
  Loader2,
} from 'lucide-react';

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
  sessionReferenceImages: SessionReferenceImage[];
  onSessionReferenceImagesChange: (imgs: SessionReferenceImage[]) => void;

  // ── Brief ──
  brief: string;
  onBriefChange: (v: string) => void;

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
  angles: number;
  onAnglesChange: (n: number) => void;
  imageVariations: number;
  onImageVariationsChange: (n: number) => void;

  // ── Generate ──
  isGenerating: boolean;
  onGenerate: () => void;
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
  sessionReferenceImages,
  onSessionReferenceImagesChange,
  brief,
  onBriefChange,
  textModelId,
  onTextModelChange,
  imageModelIds,
  onImageModelsChange,
  videoModelId,
  onVideoModelChange,
  angles,
  onAnglesChange,
  imageVariations,
  onImageVariationsChange,
  isGenerating,
  onGenerate,
}: AdsSidebarProps) {
  const canGenerate = brief.trim().length > 0
    && textModelId
    && imageModelIds.length > 0
    && !isGenerating;

  // Determine if image inputs (logo/reference) are active
  const toggles = { ...DEFAULT_BRAND_TOGGLES, ...contextData.brandToggles };
  const requiresImageInput = !!toggles.useLogo;

  return (
    <aside
      className="shrink-0 border-r border-border overflow-y-auto bg-muted/20"
      style={{ width: '22%', minWidth: '280px', maxWidth: '380px' }}
    >
      <div className="p-4 space-y-6">
        {/* 1. Brand / URL Context */}
        <ContextPanel
          value={contextData}
          onChange={onContextChange}
          onUrlFetched={onUrlFetched}
          hideTheme
        />

        {/* 2. Enhanced Brand Section */}
        <EnhancedBrandSection
          contextData={contextData}
          onContextChange={onContextChange}
          formValues={formValues}
          onFormChange={onFormChange}
          referenceImages={sessionReferenceImages}
          onReferenceImagesChange={onSessionReferenceImagesChange}
        />

        {/* 3. Theme Selector */}
        <ThemeSelector
          seasonEvent={contextData.seasonEvent}
          campaignTheme={contextData.campaignTheme}
          onSeasonChange={(seasonEvent) => onContextChange({ ...contextData, seasonEvent })}
          onCampaignThemeChange={(campaignTheme) => onContextChange({ ...contextData, campaignTheme })}
        />

        {/* 4. Creative Brief */}
        <section>
          <div className="flex items-center gap-2 mb-3">
            <Wand2 className="w-4 h-4 text-muted-foreground" />
            <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
              Creative Brief
            </h3>
          </div>
          <Textarea
            value={brief}
            onChange={(e) => onBriefChange(e.target.value)}
            placeholder="Describe what you want to advertise..."
            className="min-h-[100px] text-sm resize-none"
          />
        </section>

        {/* 5. Models */}
        <section>
          <div className="mb-3">
            <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
              Models
            </h3>
          </div>
          
          <div className="space-y-1 bg-muted/30 rounded-lg p-2 border border-border">
            <GlobalEngineSelector
              type="text"
              title="Copy"
              selectedIds={textModelId ? [textModelId] : []}
              onChange={(ids) => onTextModelChange(ids[0] || '')}
              multiSelect={false}
            />
            
            <GlobalEngineSelector
              type="image"
              title="Image"
              selectedIds={imageModelIds}
              onChange={onImageModelsChange}
              multiSelect={true}
              requiresImageInput={requiresImageInput}
            />

            <GlobalEngineSelector
              type="video"
              title="Video"
              selectedIds={videoModelId ? [videoModelId] : []}
              onChange={(ids) => onVideoModelChange(ids[0] || '')}
              multiSelect={false}
            />
          </div>
        </section>

        {/* 6. Production Parameters */}
        <GlobalProductionParameters
          angles={angles}
          onAnglesChange={onAnglesChange}
          variations={imageVariations}
          onVariationsChange={onImageVariationsChange}
        />

        {/* 9. Generate Button */}
        <Button
          onClick={onGenerate}
          disabled={!canGenerate}
          className="w-full gap-2"
          size="lg"
        >
          {isGenerating ? (
            <>
              <Loader2 className="w-4 h-4 animate-spin" />
              Generating Ads...
            </>
          ) : (
            <>
              <Megaphone className="w-4 h-4" />
              Generate Ads
            </>
          )}
        </Button>
      </div>
    </aside>
  );
});
