/**
 * useImageGeneration — Image generation orchestration hook
 *
 * Owns the full generation loop: brief optimization → concept generation
 * → per-model/per-version image generation. Returns assets, status, and
 * the handleGenerate trigger.
 *
 * Single responsibility: orchestrate API calls and mutate the asset state.
 * UI concerns (modals, tabs, detail view) stay in the parent component.
 */

import { useState, useMemo, useCallback, useEffect } from 'react';
import { trpc } from '@/lib/trpc';
import { toast } from 'sonner';
import { useSettings } from '@/contexts/AppContext';
import { useRowSelection } from '@/hooks/useRowSelection';
import type {
    AdVersion,
    GeneratedAsset,
    GenerationStatus,
    CostTier,
} from '@/types';
import { PRODUCTION_DEFAULTS, STORAGE_KEYS } from '../imageConfig';
import { DEFAULT_BRAND_TOGGLES } from '@/components/shared/ContextPanel';
import type { ContextData } from '@/components/shared/ContextPanel';
import type { SessionReferenceImage } from '@shared/referenceImageIntents';
import { TIER_CONFIG, useImageModelsForGeneration } from '@/hooks/useModelsForGeneration';

// ============================================================================
// Types
// ============================================================================

export interface AssetPipelinePayload {
    /** Base64 logo image to include in generation prompt */
    logoBase64?: string;
    /** Base64 subject image to include in generation prompt */
    subjectBase64?: string;
    /** Text overlay configuration */
    textOverlay?: {
        text: string;
        placement: string;
        optimize: boolean;
    };
}

export interface UseImageGenerationOptions {
    contextData: ContextData;
    formValues: Record<string, string | number | undefined>;
    sessionReferenceImages: SessionReferenceImage[];
    assetPipelinePayload: AssetPipelinePayload;
}

export interface UseImageGenerationReturn {
    // State
    selectedModels: string[];
    setSelectedModels: React.Dispatch<React.SetStateAction<string[]>>;
    numVersions: number;
    setNumVersions: (n: number) => void;
    variationsPerModel: number;
    setVariationsPerModel: (n: number) => void;
    autoOptimizeBrief: boolean;
    setAutoOptimizeBrief: (v: boolean) => void;
    expandedTiers: Record<CostTier, boolean>;
    status: GenerationStatus;
    adVersions: AdVersion[];
    setAdVersions: React.Dispatch<React.SetStateAction<AdVersion[]>>;
    assets: GeneratedAsset[];
    setAssets: React.Dispatch<React.SetStateAction<GeneratedAsset[]>>;
    activeTab: string | null;
    setActiveTab: React.Dispatch<React.SetStateAction<string | null>>;
    // Derived
    assetsByModel: Record<string, GeneratedAsset[]>;
    displayModels: Array<{ id: string; name: string; provider: string; costTier: CostTier }>;
    modelsByTier: Record<CostTier, Array<{ id: string; name: string; provider: string; costTier: CostTier }>>;
    isLoadingRegistry: boolean;
    hasIntegrations: boolean;
    // Multi-select (bulk actions)
    selectedAssetIds: Set<string>;
    selectedAssets: GeneratedAsset[];
    toggleAssetSelection: (id: string) => void;
    clearAssetSelection: () => void;
    selectAllForModel: (modelId: string) => void;
    selectAllForTab: (tabId: string) => void;
    bulkDownload: () => void;
    bulkRemove: () => void;
    // Actions
    handleGenerate: (productBrief: string) => Promise<void>;
    toggleModel: (modelId: string) => void;
    toggleTier: (tier: CostTier) => void;
    downloadAsset: (url: string, filename: string) => void;
    tierLabels: typeof TIER_CONFIG;
}

// ============================================================================
// Hook
// ============================================================================

export function useImageGeneration({
    contextData,
    formValues,
    sessionReferenceImages,
    assetPipelinePayload,
}: UseImageGenerationOptions): UseImageGenerationReturn {

    // ── Settings (for Menu Intelligence model selection) ──
    const { settings } = useSettings();

    // ── tRPC mutations ──
    const generateConceptsMutation = trpc.image.generateConcepts.useMutation();
    const generateImageMutation = trpc.image.generate.useMutation();
    const optimizeBriefMutation = trpc.image.optimizeBrief.useMutation();

    // ── Model registry (generation-capable image models only) ──
    const {
        imageModels,
        imageModelsByTier: modelsByTierRaw,
        isLoading: isLoadingRegistry,
        hasModels: hasIntegrations,
    } = useImageModelsForGeneration();

    // Determine if reference image filter is active:
    // user toggled "Reference Subjects" ON and has at least one image loaded.
    const toggles = { ...DEFAULT_BRAND_TOGGLES, ...contextData.brandToggles };
    const referenceFilterActive = !!toggles.useReferenceSubjects && sessionReferenceImages.length > 0;

    // Standardised display format — filtered by image-input capability when reference images active
    const displayModels = useMemo(
        () => {
            const mapped = imageModels.map((m) => ({ id: m.id, name: m.name, provider: m.provider, costTier: m.costTier, imageInputMode: m.imageInputMode }));
            if (!referenceFilterActive) return mapped;
            // Only show models that accept image input
            return mapped.filter((m) => m.imageInputMode !== null);
        },
        [imageModels, referenceFilterActive],
    );

    const modelsByTier = useMemo(
        () => {
            const mapModel = (m: { id: string; name: string; provider: string; costTier: CostTier; imageInputMode: string | null }) =>
                ({ id: m.id, name: m.name, provider: m.provider, costTier: m.costTier, imageInputMode: m.imageInputMode });
            const filterFn = referenceFilterActive
                ? (m: { imageInputMode: string | null }) => m.imageInputMode !== null
                : () => true;
            return {
                budget: modelsByTierRaw.budget.map(mapModel).filter(filterFn),
                standard: modelsByTierRaw.standard.map(mapModel).filter(filterFn),
                premium: modelsByTierRaw.premium.map(mapModel).filter(filterFn),
            };
        },
        [modelsByTierRaw, referenceFilterActive],
    );

    // ── Selection state ──
    const [selectedModels, setSelectedModels] = useState<string[]>([]);
    const [expandedTiers, setExpandedTiers] = useState<Record<CostTier, boolean>>({
        budget: false,
        standard: false,
        premium: false,
    });

    // Auto-deselect models that disappear when the reference filter activates.
    // Prevents user from having "ghost" selections that would fail silently.
    useEffect(() => {
        if (!referenceFilterActive) return;
        const availableIds = new Set(displayModels.map((m) => m.id));
        const removed = selectedModels.filter((id) => !availableIds.has(id));
        if (removed.length > 0) {
            setSelectedModels((prev) => prev.filter((id) => availableIds.has(id)));
            toast.info(`${removed.length} model(s) deselected — they don't support reference images`);
        }
    }, [referenceFilterActive, displayModels]); // eslint-disable-line react-hooks/exhaustive-deps

    // ── Production parameters ──
    const [numVersions, setNumVersions] = useState(PRODUCTION_DEFAULTS.numVersions);
    const [variationsPerModel, setVariationsPerModel] = useState(PRODUCTION_DEFAULTS.variationsPerModel);
    const [autoOptimizeBrief, setAutoOptimizeBrief] = useState(PRODUCTION_DEFAULTS.autoOptimizeBrief);

    // ── Output state ──
    const [status, setStatus] = useState<GenerationStatus>({ isGenerating: false, progress: 0, message: '' });
    const [adVersions, setAdVersions] = useState<AdVersion[]>([]);
    const [activeTab, setActiveTab] = useState<string | null>(null);
    const [assets, setAssets] = useState<GeneratedAsset[]>([]);

    // ── Derived: group assets by model for the active version tab ──
    const assetsByModel = useMemo<Record<string, GeneratedAsset[]>>(() => {
        const grouped: Record<string, GeneratedAsset[]> = {};
        selectedModels.forEach((id) => { grouped[id] = []; });
        assets
            .filter((a) => a.versionId === activeTab)
            .forEach((a) => {
                if (grouped[a.modelId]) grouped[a.modelId].push(a);
                else grouped[a.modelId] = [a];
            });
        return grouped;
    }, [assets, selectedModels, activeTab]);

    // ── Tier accordion toggle ──
    const toggleTier = useCallback((tier: CostTier) => {
        setExpandedTiers((prev) => ({ ...prev, [tier]: !prev[tier] }));
    }, []);

    // ── Model selection toggle ──
    const toggleModel = useCallback((modelId: string) => {
        setSelectedModels((prev) =>
            prev.includes(modelId) ? prev.filter((m) => m !== modelId) : [...prev, modelId],
        );
    }, []);

    // ── Download helper ──
    const downloadAsset = useCallback((url: string, filename: string) => {
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        link.target = '_blank';
        link.click();
    }, []);

    // ── Multi-select via shared useRowSelection hook ──
    // Reuses the same selection primitive as Brands, Templates, Keywords.
    // Domain-specific operations (selectAllForModel, selectAllForTab) compose on top.
    const selection = useRowSelection<string>();

    // ── Multi-select: derived selected assets ──
    const selectedAssets = useMemo(
        () => assets.filter((a) => selection.selectedIds.has(a.id)),
        [assets, selection.selectedIds],
    );

    // ── Multi-select: select/deselect all completed assets for a model ──
    const selectAllForModel = useCallback((modelId: string) => {
        const modelAssets = (assetsByModel[modelId] ?? []).filter((a) => a.status === 'complete');
        const ids = modelAssets.map((a) => a.id);
        selection.toggleAll(ids);
    }, [assetsByModel, selection]);

    // ── Multi-select: select/deselect all completed assets for a given tab ──
    const selectAllForTab = useCallback((tabId: string) => {
        const tabAssets = assets.filter(
            (a) => a.versionId === tabId && a.status === 'complete',
        );
        const ids = tabAssets.map((a) => a.id);
        selection.toggleAll(ids);
    }, [assets, selection]);

    // ── Bulk download: sequentially download all selected assets ──
    const bulkDownload = useCallback(() => {
        const toDownload = selectedAssets.filter((a) => a.status === 'complete' && a.url);
        if (toDownload.length === 0) return;
        toDownload.forEach((asset, i) => {
            // Stagger downloads slightly to avoid browser blocking
            setTimeout(() => {
                downloadAsset(asset.url!, `${asset.modelName}-${asset.id}.png`);
            }, i * 200);
        });
        toast.success(`Downloading ${toDownload.length} images`);
    }, [selectedAssets, downloadAsset]);

    // ── Bulk remove: remove selected assets from session ──
    const bulkRemove = useCallback(() => {
        const idsToRemove = new Set(selection.selectedIds);
        setAssets((prev) => prev.filter((a) => !idsToRemove.has(a.id)));
        selection.clearAll();
        toast.success(`Removed ${idsToRemove.size} images`);
    }, [selection]);

    // ── buildColorContext removed: Colors are now handled purely via Prompt Templates ──

    // ── Main generation handler ──
    const handleGenerate = useCallback(async (productBrief: string) => {
        if (!productBrief.trim()) {
            toast.error('Please enter a product brief');
            return;
        }
        if (selectedModels.length === 0) {
            toast.error('Please select at least one model');
            return;
        }

        setStatus({ isGenerating: true, progress: 0, message: 'Generating creative concepts...' });
        setAssets([]);

        // toggles is already computed at hook scope (line 122) — reuse it

        // Build brand context ONCE — reused for optimize_brief, suggest_concepts,
        // AND generate_single (final_prompt resolution). Fixes DRY violation.
        const brandCtx = {
            name: (contextData.brand as any)?.name,
            summary: toggles.useSummary ? ((contextData.brand as any)?.businessSummary || formValues.business_summary) : undefined,
            brandColors: toggles.useColors ? (contextData.brand as any)?.colors : undefined,
            niche: formValues.niche,
            location: formValues.location,
            phone: formValues.phone,
            website: formValues.website,
            language: formValues.language,
            seasonEvent: contextData.seasonEvent,
            campaignTheme: contextData.campaignTheme,
            url: contextData.url,
        };
        const hasBrandCtx = contextData.brand || Object.keys(formValues).length > 0;

        try {
            // 0. Optionally optimize the brief via LLM
            let briefToUse = productBrief;
            if (autoOptimizeBrief) {
                setStatus((prev) => ({ ...prev, progress: 5, message: 'Optimizing your brief...' }));
                try {
                    const optimized = await optimizeBriefMutation.mutateAsync({
                        brief: productBrief,
                        modelId: settings.defaultImageTextModel || undefined,
                        brandContext: hasBrandCtx ? brandCtx : undefined,
                    });
                    briefToUse = (optimized as any).optimizedBrief ?? productBrief;
                } catch {
                    // Non-fatal — continue with original brief
                    console.warn('[useImageGeneration] Brief optimization failed, using original.');
                }
            }

            // 1. Build creative angles (concepts)
            // Rule: Angle 1 = ALWAYS the user's exact prompt (raw or enhanced).
            //        Angles 2..N = AI-generated creative variations.
            //        This ensures the user's intent is always represented as-is.

            // Angle 1: the user's own prompt, no AI rewriting
            const anchorVersion: AdVersion = {
                id: crypto.randomUUID(),
                name: 'Original',
                description: briefToUse,
            };

            let generatedVersions: AdVersion[] = [anchorVersion];

            // Angles 2..N: AI-generated creative concepts (only if user wants >1 angle)
            if (numVersions > 1) {
                setStatus((prev) => ({ ...prev, progress: 10, message: 'AI is conceptualizing creative angles...' }));

                    const conceptsResult = await generateConceptsMutation.mutateAsync({
                    prompt: briefToUse,
                    count: numVersions - 1,
                    modelId: settings.defaultImageTextModel || undefined,
                    brandContext: hasBrandCtx ? brandCtx : undefined,
                    referenceImages: toggles.useReferenceSubjects ? sessionReferenceImages.map((img) => ({ url: img.url, intent: img.intent })) : [],
                });

                const conceptsList = (conceptsResult as any).concepts ?? [];
                const aiVersions: AdVersion[] = conceptsList.map((concept: any) => ({
                    id: crypto.randomUUID(),
                    name: concept.name,
                    description: concept.description,
                }));

                generatedVersions = [anchorVersion, ...aiVersions];
            }

            setAdVersions(generatedVersions);
            setActiveTab(generatedVersions[0]?.id ?? null);

            // 2. Generate images: concepts sequential, models PARALLEL, variations sequential.
            // This maximizes throughput — different providers run concurrently
            // while respecting per-provider rate limits via sequential variations.
            const totalWork = generatedVersions.length * selectedModels.length * variationsPerModel;
            let completed = 0;
            const refUrls = toggles.useReferenceSubjects ? sessionReferenceImages.map((img) => img.url) : [];
            const refIntents = toggles.useReferenceSubjects ? sessionReferenceImages.map((img) => img.intent) : [];

            // Build asset pipeline context once (shared across all generations)
            // NOTE: logoBase64 and subjectBase64 are intentionally NOT sent here.
            // No backend provider handles them — they were dead code.
            const pipelineExtra: Record<string, unknown> = {};
            if (assetPipelinePayload.textOverlay?.text) {
                pipelineExtra.textOverlay = assetPipelinePayload.textOverlay;
            }

            for (const version of generatedVersions) {
                // Build prompt once per concept (shared across all models)
                const isAnchor = version.name === 'Original';
                const fullPrompt = isAnchor
                    ? version.description
                    : `${version.description}. Product: ${productBrief}`;

                // Fire ALL models in parallel for this concept
                const modelTasks = selectedModels.map(async (modelId) => {
                    const model = displayModels.find((m: { id: string; name: string; provider: string; costTier: CostTier }) => m.id === modelId);

                    // Resolve provider once per model
                    const resolvedProvider = model?.provider
                        ?? imageModels.find((m: { id: string; provider: string }) => m.id === modelId)?.provider
                        ?? '';

                    if (!resolvedProvider) {
                        throw new Error(`No provider found for model ${modelId}. Check the model registry.`);
                    }

                    // Variations run sequentially within each model (rate-limit safe)
                    for (let v = 0; v < variationsPerModel; v++) {
                        const placeholderId = crypto.randomUUID();
                        const placeholder: GeneratedAsset = {
                            id: placeholderId,
                            versionId: version.id,
                            type: 'image',
                            prompt: fullPrompt,
                            modelId,
                            modelName: model?.name ?? modelId,
                            status: 'processing',
                            createdAt: new Date(),
                        };
                        setAssets((prev) => [...prev, placeholder]);

                        try {
                            const result = await generateImageMutation.mutateAsync({
                                prompt: fullPrompt,
                                model: modelId,
                                provider: resolvedProvider,
                                // Pass brand context so backend resolves the final_prompt template
                                brandContext: hasBrandCtx ? brandCtx : undefined,
                                ...(refUrls.length > 0 ? { referenceImageUrls: refUrls, referenceImageIntents: refIntents } : {}),
                                ...pipelineExtra,
                            });

                            // Replace placeholder with completed asset
                            const dbId = (result as any).id;
                            setAssets((prev) =>
                                prev.map((a) =>
                                    a.id === placeholderId
                                        ? { ...a, id: dbId ? String(dbId) : a.id, url: (result as any).url, thumbnailUrl: (result as any).url, status: 'complete' as const }
                                        : a,
                                ),
                            );
                        } catch (error) {
                            const errorMessage =
                                error instanceof Error ? error.message
                                    : typeof error === 'object' && error !== null && 'message' in error
                                        ? String((error as { message: unknown }).message)
                                        : 'Unknown error';

                            setAssets((prev) =>
                                prev.map((a) =>
                                    a.id === placeholderId ? { ...a, status: 'failed' as const, errorMessage } : a,
                                ),
                            );
                        }

                        // Progress update — React batches setState so concurrent
                        // updates to `completed` are safe via closure capture
                        completed++;
                        setStatus((prev) => ({
                            ...prev,
                            progress: 10 + ((completed / totalWork) * 85),
                            message: `Generating: ${version.name} (${model?.name ?? modelId})...`,
                        }));
                    }
                });

                // Wait for all models to finish for this concept before moving to next
                await Promise.allSettled(modelTasks);
            }

            setStatus({ isGenerating: false, progress: 100, message: 'Generation complete!' });
            toast.success('All images generated!');
        } catch (error) {
            console.error('[useImageGeneration] handleGenerate failed:', error);
            setStatus({ isGenerating: false, progress: 0, message: '' });
            toast.error('Failed to generate images. Please try again.');
        }
    }, [
        selectedModels,
        numVersions,
        variationsPerModel,
        autoOptimizeBrief,
        contextData,
        sessionReferenceImages,
        assetPipelinePayload,
        displayModels,
        generateConceptsMutation,
        generateImageMutation,
        optimizeBriefMutation,
        settings.defaultImageTextModel,
        formValues,
    ]);

    return {
        // State
        selectedModels,
        setSelectedModels,
        numVersions,
        setNumVersions,
        variationsPerModel,
        setVariationsPerModel,
        autoOptimizeBrief,
        setAutoOptimizeBrief,
        expandedTiers,
        status,
        adVersions,
        setAdVersions,
        assets,
        setAssets,
        activeTab,
        setActiveTab,
        // Derived
        assetsByModel,
        displayModels,
        modelsByTier,
        isLoadingRegistry,
        hasIntegrations,
        // Multi-select (bulk actions) — delegated to shared useRowSelection<string>
        selectedAssetIds: selection.selectedIds,
        selectedAssets,
        toggleAssetSelection: selection.toggle,
        clearAssetSelection: selection.clearAll,
        selectAllForModel,
        selectAllForTab,
        bulkDownload,
        bulkRemove,
        // Actions
        handleGenerate,
        toggleModel,
        toggleTier,
        downloadAsset,
        tierLabels: TIER_CONFIG,
    };
}
