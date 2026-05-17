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
} from '@/components/shared';
import type { ContextData, ScrapedBusinessData } from '@/components/shared';
import {
  Wand2,
  Cpu,
  PenLine,
  ImageIcon,
  Video,
  SlidersHorizontal,
  Zap,
  ChevronDown,
  ChevronRight,
  Check,
  Megaphone,
  Loader2,
  Lock,
} from 'lucide-react';
import type { CostTier } from '@/types';
import { TIER_CONFIG } from '@/hooks/useModelsForGeneration';
import type { TextModel, TextModelGroup } from '@/modules/Copy/useTextModels';

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

  // ── Brief ──
  brief: string;
  onBriefChange: (v: string) => void;

  // ── Text model ──
  textModelId: string;
  onTextModelChange: (id: string) => void;
  textModels: TextModel[];
  textModelGroups: TextModelGroup[];

  // ── Image models ──
  imageModelIds: string[];
  onToggleImageModel: (id: string) => void;
  imageModelsByTier: Record<CostTier, Array<{ id: string; name: string; provider: string; costTier: CostTier }>>;
  expandedTiers: Record<CostTier, boolean>;
  onToggleTier: (tier: CostTier) => void;

  // ── Video model (fishbone) ──
  videoModelId: string;
  onVideoModelChange: (id: string) => void;

  // ── Production params ──
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
  brief,
  onBriefChange,
  textModelId,
  onTextModelChange,
  textModels,
  textModelGroups,
  imageModelIds,
  onToggleImageModel,
  imageModelsByTier,
  expandedTiers,
  onToggleTier,
  videoModelId,
  onVideoModelChange,
  imageVariations,
  onImageVariationsChange,
  isGenerating,
  onGenerate,
}: AdsSidebarProps) {
  const canGenerate = brief.trim().length > 0
    && textModelId
    && imageModelIds.length > 0
    && !isGenerating;

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

        {/* 5. Writer Model (text) */}
        <section>
          <div className="flex items-center gap-2 mb-3">
            <PenLine className="w-4 h-4 text-muted-foreground" />
            <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
              Writer Model
            </h3>
          </div>
          <select
            value={textModelId}
            onChange={(e) => onTextModelChange(e.target.value)}
            className="w-full h-9 text-sm bg-background border border-border rounded-lg px-3 focus:outline-none focus:ring-1 focus:ring-primary"
          >
            <option value="">Select model...</option>
            {textModelGroups.map((group) => (
              <optgroup key={group.tier} label={group.label}>
                {group.models.map((m) => (
                  <option key={m.id} value={m.id}>{m.name}</option>
                ))}
              </optgroup>
            ))}
          </select>
        </section>

        {/* 6. Image Models (multi-select accordion) */}
        <section>
          <div className="flex items-center justify-between mb-3">
            <div className="flex items-center gap-2">
              <ImageIcon className="w-4 h-4 text-muted-foreground" />
              <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                Image Models
              </h3>
            </div>
            <span className="text-xs text-muted-foreground">
              {imageModelIds.length} selected
            </span>
          </div>

          <div className="space-y-2">
            {(['budget', 'standard', 'premium'] as CostTier[]).map((tier) => {
              const models = imageModelsByTier[tier];
              if (!models || models.length === 0) return null;

              const tierInfo = TIER_CONFIG[tier];
              const selectedInTier = models.filter((m) => imageModelIds.includes(m.id)).length;
              const isExpanded = expandedTiers[tier];

              return (
                <div key={tier} className="border border-border rounded-lg overflow-hidden">
                  <button
                    onClick={() => onToggleTier(tier)}
                    className="w-full flex items-center justify-between px-3 py-2 bg-muted/50 hover:bg-muted/70 transition-colors"
                  >
                    <div className="flex items-center gap-2">
                      {isExpanded
                        ? <ChevronDown className="w-4 h-4 text-muted-foreground" />
                        : <ChevronRight className="w-4 h-4 text-muted-foreground" />
                      }
                      <span className={`text-sm font-semibold ${tierInfo.color}`}>
                        {tierInfo.icon}
                      </span>
                      <span className="text-sm font-medium">{tierInfo.label}</span>
                    </div>
                    <span className="text-xs text-muted-foreground">
                      {selectedInTier}/{models.length}
                    </span>
                  </button>

                  {isExpanded && (
                    <div className="p-2 space-y-1 bg-background">
                      {models.map((model) => {
                        const isSelected = imageModelIds.includes(model.id);
                        return (
                          <button
                            key={model.id}
                            type="button"
                            onClick={() => onToggleImageModel(model.id)}
                            className={`w-full flex items-center gap-3 px-3 py-2 rounded-lg text-left transition-colors ${
                              isSelected
                                ? 'bg-primary/10 border border-primary/30'
                                : 'hover:bg-muted/50'
                            }`}
                          >
                            <div className={`w-5 h-5 rounded flex items-center justify-center ${
                              isSelected ? 'bg-primary text-primary-foreground' : 'bg-muted'
                            }`}>
                              {isSelected && <Check className="w-3 h-3" />}
                            </div>
                            <div className="flex-1 min-w-0">
                              <div className="text-sm font-medium truncate">{model.name}</div>
                              <div className="text-xs text-muted-foreground capitalize">{model.provider}</div>
                            </div>
                          </button>
                        );
                      })}
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        </section>

        {/* 7. Video Model (fishbone — disabled) */}
        <section className="opacity-50">
          <div className="flex items-center gap-2 mb-3">
            <Video className="w-4 h-4 text-muted-foreground" />
            <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
              Video Model
            </h3>
            <span className="flex items-center gap-1 text-[10px] text-muted-foreground bg-muted px-2 py-0.5 rounded-full">
              <Lock className="w-3 h-3" />
              Coming soon
            </span>
          </div>
          <select
            disabled
            className="w-full h-9 text-sm bg-muted border border-border rounded-lg px-3 cursor-not-allowed"
          >
            <option>Not available yet</option>
          </select>
        </section>

        {/* 8. Production Parameters */}
        <section>
          <div className="flex items-center gap-2 mb-3">
            <SlidersHorizontal className="w-4 h-4 text-muted-foreground" />
            <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
              Production Parameters
            </h3>
          </div>

          {/* Image variations per model */}
          <div>
            <div className="flex items-center justify-between mb-2">
              <label className="text-xs text-muted-foreground flex items-center gap-1.5">
                <Zap className="w-3 h-3" />Images per Model
              </label>
              <span className="text-xs font-mono bg-muted px-2 py-0.5 rounded">
                {imageVariations}
              </span>
            </div>
            <input
              type="range"
              min="1"
              max="4"
              value={imageVariations}
              onChange={(e) => onImageVariationsChange(parseInt(e.target.value) || 1)}
              className="w-full h-1.5 bg-muted rounded-full appearance-none cursor-pointer accent-primary"
            />
            <div className="flex justify-between text-[10px] text-muted-foreground mt-1">
              <span>1</span><span>4</span>
            </div>
          </div>
        </section>

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
