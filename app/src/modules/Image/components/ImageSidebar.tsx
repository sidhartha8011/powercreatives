/**
 * ImageSidebar — All sidebar controls for the Image module
 *
 * Pure presentational component. Receives all state/callbacks as props.
 * No business logic, no API calls — those live in the hooks.
 *
 * Sections:
 * 1. Brand / URL / Theme context (ContextPanel — includes brand identity)
 * 2. Generation Assets (subject, badges, text overlay)
 * 3. Product Brief + AI Suggestions
 * 4. Production Engines (model accordion)
 * 5. Production Parameters (sliders)
 */

import { memo } from 'react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Switch } from '@/components/ui/switch';

import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { ContextPanel, EnhancedBrandSection } from '@/components/shared';
import type { ContextData, ScrapedBusinessData } from '@/components/shared';
import { SessionReferenceImagePanel } from '@/components/shared/SessionReferenceImagePanel';
import type { SessionReferenceImage } from '@shared/referenceImageIntents';
import type { BrandAsset } from '@shared/brandTypes';
import { syncBrandAssetsToSession } from '@/lib/syncBrandAssetsToSession';
import {
    Sparkles, Loader2, Wand2, Lightbulb, Cpu, SlidersHorizontal,
    Settings2, ShieldCheck, Type, Check, ChevronDown, ChevronRight,
    Dices, Zap, X, Info,
} from 'lucide-react';
import type { DetailLevelValue } from '../types';
import type { LogoConfig, ReferenceAsset, CertificationConfig, TextOverlayConfig, TextPlacement, CostTier } from '@/types';
import type { UseImageGenerationReturn } from '../hooks/useImageGeneration';
import type { UseImageSuggestionsReturn } from '../hooks/useImageSuggestions';
import type { UseImageAssetsReturn } from '../hooks/useImageAssets';
import { PRODUCTION_DEFAULTS } from '../imageConfig';

// ============================================================================
// Props
// ============================================================================

interface ImageSidebarProps {
    // Shared context
    contextData: ContextData;
    onContextChange: (data: ContextData) => void;
    onUrlFetched?: (data: ScrapedBusinessData) => void;
    sessionReferenceImages: SessionReferenceImage[];
    onSessionReferenceImagesChange: (imgs: SessionReferenceImage[]) => void;
    // Product brief
    productBrief: string;
    onProductBriefChange: (v: string) => void;
    // Form state for EnhancedBrandSection
    formValues: Record<string, string | number | undefined>;
    onFormChange: (fieldId: string, value: string | number) => void;
    // Generation hook
    gen: Pick<
        UseImageGenerationReturn,
        | 'selectedModels' | 'numVersions' | 'setNumVersions'
        | 'variationsPerModel' | 'setVariationsPerModel'
        | 'autoOptimizeBrief' | 'setAutoOptimizeBrief'
        | 'expandedTiers' | 'toggleModel' | 'toggleTier' | 'tierLabels'
        | 'displayModels' | 'modelsByTier'
    >;
    // Suggestions hook
    sug: Pick<
        UseImageSuggestionsReturn,
        | 'suggestions' | 'contextSuggestions'
        | 'suggestionCount' | 'setSuggestionCount'
        | 'detailLevel' | 'setDetailLevel'
        | 'isLoadingSuggestions' | 'isLoadingContextSuggestions'
        | 'handleGenerateSuggestions' | 'handleGenerateContextSuggestions'
        | 'applySuggestion' | 'applyContextSuggestion'
    >;
    // Asset pipeline hook (generation-specific)
    asset: Pick<
        UseImageAssetsReturn,
        | 'subjectConfig' | 'subjectInputRef' | 'clearSubject'
        | 'handleFileUpload'
        | 'setSubjectConfig'
    >;
}


// ============================================================================
// Component
// ============================================================================

export const ImageSidebar = memo(function ImageSidebar({
    contextData,
    onContextChange,
    onUrlFetched,
    sessionReferenceImages,
    onSessionReferenceImagesChange,
    productBrief,
    onProductBriefChange,
    formValues,
    onFormChange,
    gen,
    sug,
    asset,
}: ImageSidebarProps) {
    const { selectedModels, numVersions, variationsPerModel, autoOptimizeBrief,
        expandedTiers, toggleModel, toggleTier, tierLabels, modelsByTier } = gen;

    const { suggestions, contextSuggestions, suggestionCount, detailLevel,
        isLoadingSuggestions, isLoadingContextSuggestions,
        handleGenerateSuggestions, handleGenerateContextSuggestions,
        applySuggestion, applyContextSuggestion } = sug;

    const { subjectConfig, subjectInputRef, clearSubject,
        handleFileUpload, setSubjectConfig } = asset;

    const hasContext = !!(contextData.brand || contextData.url || contextData.seasonEvent || contextData.campaignTheme);

    // Brand assets available for the From Brand picker in pipeline slots
    const brandAssets: BrandAsset[] = ((contextData.brand as any)?.assets as BrandAsset[] | null) ?? [];

    return (
        <aside className="shrink-0 border-r border-border overflow-y-auto bg-muted/20" style={{ width: '22%', minWidth: '280px', maxWidth: '380px' }}>
            <div className="p-4 space-y-6">

                {/* 1. Brand / URL / Theme Context
                 * Theme is rendered inside ContextPanel but collapsed by default.
                 * We do NOT pass hideTheme because ThemeSelector already has its own
                 * accordion with defaultExpanded — we override that to false below. */}
                <ContextPanel
                    value={contextData}
                    onChange={(newData) => {
                        onContextChange(newData);
                        // Sync brand assets → session reference images when brand or assets change
                        const synced = syncBrandAssetsToSession(contextData, newData, sessionReferenceImages);
                        if (synced) onSessionReferenceImagesChange(synced);
                    }}
                    onUrlFetched={onUrlFetched}
                />

                <EnhancedBrandSection
                    contextData={contextData}
                    onContextChange={onContextChange}
                    formValues={formValues}
                    onFormChange={onFormChange}
                    referenceImages={sessionReferenceImages}
                    onReferenceImagesChange={onSessionReferenceImagesChange}
                />



                {/* --- Reference Images: HIDDEN ---
                 * Reference images are NOT removed from the codebase.
                 * They serve two purposes in the generation pipeline:
                 *   1. LLM multimodal input: sent as image_url parts to GPT-4o/Claude/Gemini
                 *      during concept/suggestion generation (build_multimodal_user_message)
                 *   2. Image model input: forwarded as inputUrls to the provider (Flux/Kling/etc),
                 *      though most models only accept maxImageInputs=1.
                 * Hidden because brand assets now cover the primary use case (From Brand picker).
                 * Kept in code for future re-enablement when multi-image models become standard.
                 * The syncBrandAssetsToSession() call in ContextPanel.onChange still runs,
                 * keeping the sessionReferenceImages array populated for generation.
                 * --- */}

                {/* 4. Generate Suggestions from Context */}
                <section>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => handleGenerateContextSuggestions(contextData, sessionReferenceImages)}
                        disabled={isLoadingContextSuggestions || !hasContext}
                        className="w-full gap-2 text-xs"
                    >
                        {isLoadingContextSuggestions ? (
                            <><Loader2 className="w-3 h-3 animate-spin" />Generating...</>
                        ) : (
                            <><Sparkles className="w-3 h-3" />Generate Suggestions</>
                        )}
                    </Button>

                    {contextSuggestions.length > 0 && (
                        <div className="mt-3 space-y-2">
                            {contextSuggestions.map((sug, i) => (
                                <button
                                    key={i}
                                    onClick={() => applyContextSuggestion(sug, onProductBriefChange)}
                                    className="w-full text-left p-2.5 text-xs bg-muted/50 hover:bg-primary/10 border border-border hover:border-primary/30 rounded-lg transition-colors group"
                                >
                                    <div className="flex items-start gap-2">
                                        <Sparkles className="w-3 h-3 mt-0.5 text-muted-foreground group-hover:text-primary shrink-0" />
                                        <span className="line-clamp-3">{sug}</span>
                                    </div>
                                </button>
                            ))}
                        </div>
                    )}
                </section>

                {/* 5. Product Brief + AI Suggestions */}
                <section>
                    <div className="flex items-center justify-between mb-3">
                        <div className="flex items-center gap-2">
                            <Wand2 className="w-4 h-4 text-muted-foreground" />
                            <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Product Brief</h3>
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
                                onCheckedChange={(checked) => gen.setAutoOptimizeBrief(checked)}
                                className="h-4 w-7 data-[state=checked]:bg-primary"
                            />
                        </div>
                    </div>

                    <Textarea
                        value={productBrief}
                        onChange={(e) => onProductBriefChange(e.target.value)}
                        placeholder="Describe your product or service..."
                        className="min-h-[100px] text-sm resize-none"
                    />

                    {/* Suggestion Controls */}
                    <div className="mt-3 space-y-3">
                        <div className="flex items-center gap-3">
                            <div className="flex items-center gap-2">
                                <label className="text-xs text-muted-foreground">Num.</label>
                                <input
                                    type="number" min="1" max="10"
                                    value={suggestionCount}
                                    onChange={(e) => sug.setSuggestionCount(Math.max(1, Math.min(10, parseInt(e.target.value) || 3)))}
                                    className="w-12 h-7 text-xs text-center bg-muted border border-border rounded px-1"
                                />
                            </div>
                            <div className="flex items-center gap-2">
                                <label className="text-xs text-muted-foreground">Detail</label>
                                <input
                                    type="number" min="1" max="3"
                                    value={detailLevel}
                                    onChange={(e) => sug.setDetailLevel(Math.max(1, Math.min(3, parseInt(e.target.value) || 2)) as DetailLevelValue)}
                                    className="w-12 h-7 text-xs text-center bg-muted border border-border rounded px-1"
                                />
                            </div>
                        </div>

                        <Button
                            variant="outline" size="sm"
                            onClick={() => handleGenerateSuggestions(productBrief, contextData, sessionReferenceImages)}
                            disabled={!productBrief.trim() || productBrief.length < 3 || isLoadingSuggestions}
                            className="w-full gap-2 text-xs"
                        >
                            {isLoadingSuggestions ? (
                                <><Loader2 className="w-3 h-3 animate-spin" />Generating...</>
                            ) : (
                                <><Lightbulb className="w-3 h-3" />Get AI Suggestions</>
                            )}
                        </Button>

                        {suggestions.length > 0 && (
                            <div className="space-y-2">
                                {suggestions.map((s, i) => (
                                    <button
                                        key={i}
                                        onClick={() => applySuggestion(s, onProductBriefChange)}
                                        className="w-full text-left p-2.5 text-xs bg-muted/50 hover:bg-primary/10 border border-border hover:border-primary/30 rounded-lg transition-colors group"
                                    >
                                        <div className="flex items-start gap-2">
                                            <Lightbulb className="w-3 h-3 mt-0.5 text-muted-foreground group-hover:text-primary shrink-0" />
                                            <span className="line-clamp-3">{s}</span>
                                        </div>
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                </section>

                {/* 6. Production Engines */}
                <section>
                    <div className="flex items-center justify-between mb-3">
                        <div className="flex items-center gap-2">
                            <Cpu className="w-4 h-4 text-muted-foreground" />
                            <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Production Engines</h3>
                        </div>
                        <span className="text-xs text-muted-foreground">{selectedModels.length} selected</span>
                    </div>

                    <div className="space-y-3">
                        {(['budget', 'standard', 'premium'] as CostTier[]).map((tier) => {
                            const models = modelsByTier[tier];
                            if (models.length === 0) return null;
                            const tierInfo = tierLabels[tier];
                            const selectedInTier = models.filter((m: { id: string }) => selectedModels.includes(m.id)).length;

                            return (
                                <div key={tier} className="border border-border rounded-lg overflow-hidden">
                                    <button
                                        onClick={() => toggleTier(tier)}
                                        className="w-full flex items-center justify-between px-3 py-2 bg-muted/50 hover:bg-muted/70 transition-colors"
                                    >
                                        <div className="flex items-center gap-2">
                                            {expandedTiers[tier] ? (
                                                <ChevronDown className="w-4 h-4 text-muted-foreground" />
                                            ) : (
                                                <ChevronRight className="w-4 h-4 text-muted-foreground" />
                                            )}
                                            <span className={`text-sm font-semibold ${tierInfo.color}`}>{tierInfo.icon}</span>
                                            <span className="text-sm font-medium">{tierInfo.label}</span>
                                        </div>
                                        <span className="text-xs text-muted-foreground">{selectedInTier}/{models.length}</span>
                                    </button>

                                    {expandedTiers[tier] && (
                                        <div className="p-2 space-y-1 bg-background">
                                            {models.map((model: { id: string; name: string; provider: string }) => (
                                                <button
                                                    key={model.id}
                                                    onClick={() => toggleModel(model.id)}
                                                    className={`w-full flex items-center gap-3 px-3 py-2 rounded-lg text-left transition-colors ${selectedModels.includes(model.id)
                                                        ? 'bg-primary/10 border border-primary/30'
                                                        : 'hover:bg-muted/50'
                                                        }`}
                                                >
                                                    <div className={`w-5 h-5 rounded flex items-center justify-center ${selectedModels.includes(model.id) ? 'bg-primary text-primary-foreground' : 'bg-muted'
                                                        }`}>
                                                        {selectedModels.includes(model.id) && <Check className="w-3 h-3" />}
                                                    </div>
                                                    <div className="flex-1 min-w-0">
                                                        <div className="text-sm font-medium truncate">{model.name}</div>
                                                        <div className="text-xs text-muted-foreground capitalize">{model.provider}</div>
                                                    </div>
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </section>

                {/* 7. Production Parameters */}
                <section>
                    <div className="flex items-center gap-2 mb-3">
                        <SlidersHorizontal className="w-4 h-4 text-muted-foreground" />
                        <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Production Parameters</h3>
                    </div>
                    <div className="space-y-4">
                        {/* Angles */}
                        <div>
                            <div className="flex items-center justify-between mb-2">
                                <label className="text-xs text-muted-foreground flex items-center gap-1.5">
                                    <Dices className="w-3 h-3" />Angles (Scenes)
                                </label>
                                <span className="text-xs font-mono bg-muted px-2 py-0.5 rounded">{numVersions}</span>
                            </div>
                            <input
                                type="range" min="1" max="8" value={numVersions}
                                onChange={(e) => gen.setNumVersions(parseInt(e.target.value) || PRODUCTION_DEFAULTS.numVersions)}
                                className="w-full h-1.5 bg-muted rounded-full appearance-none cursor-pointer accent-primary"
                            />
                            <div className="flex justify-between text-[10px] text-muted-foreground mt-1">
                                <span>1</span><span>8</span>
                            </div>
                        </div>

                        {/* Variations */}
                        <div>
                            <div className="flex items-center justify-between mb-2">
                                <label className="text-xs text-muted-foreground flex items-center gap-1.5">
                                    <Zap className="w-3 h-3" />Images per Engine
                                </label>
                                <span className="text-xs font-mono bg-muted px-2 py-0.5 rounded">{variationsPerModel}</span>
                            </div>
                            <input
                                type="range" min="1" max="4" value={variationsPerModel}
                                onChange={(e) => gen.setVariationsPerModel(parseInt(e.target.value) || PRODUCTION_DEFAULTS.variationsPerModel)}
                                className="w-full h-1.5 bg-muted rounded-full appearance-none cursor-pointer accent-primary"
                            />
                            <div className="flex justify-between text-[10px] text-muted-foreground mt-1">
                                <span>1</span><span>4</span>
                            </div>
                        </div>
                    </div>
                </section>

                {/* Old position of Asset Pipeline — moved to position 2 (after Brand dropdown) */}
            </div>
        </aside>
    );
});
