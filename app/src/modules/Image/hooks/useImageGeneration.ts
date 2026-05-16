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

import { useState, useMemo, useCallback, useEffect, useRef } from 'react';
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
import { getBrandLogo } from '@shared/brandAssetResolver';
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

    // Determine if any image inputs are active (reference subjects or logo)
    const toggles = { ...DEFAULT_BRAND_TOGGLES, ...contextData.brandToggles };
    const requiresImageInput = (!!toggles.useReferenceSubjects && sessionReferenceImages.length > 0) || !!toggles.useLogo;

    // Standardised display format — keep ALL models visible
    const displayModels = useMemo(
        () => {
            return imageModels.map((m) => ({ id: m.id, name: m.name, provider: m.provider, costTier: m.costTier, imageInputMode: m.imageInputMode }));
        },
        [imageModels],
    );

    const modelsByTier = useMemo(
        () => {
            const mapModel = (m: { id: string; name: string; provider: string; costTier: CostTier; imageInputMode: string | null }) =>
                ({ id: m.id, name: m.name, provider: m.provider, costTier: m.costTier, imageInputMode: m.imageInputMode });
            return {
                budget: modelsByTierRaw.budget.map(mapModel),
                standard: modelsByTierRaw.standard.map(mapModel),
                premium: modelsByTierRaw.premium.map(mapModel),
            };
        },
        [modelsByTierRaw],
    );

    // ── Selection state ──
    // Pre-select the user's default models from Settings (multi-select).
    // If a saved default no longer exists, warn but don't error — still select valid ones.
    const [selectedModels, setSelectedModels] = useState<string[]>(() => {
        const defaults = settings.defaultImageModels || [];
        if (defaults.length === 0) return [];
        const availableIds = new Set(imageModels.map((m) => m.id));
        return defaults.filter((id) => availableIds.has(id));
    });

    // Handle async loading: settings/models may arrive after initial render.
    const defaultAppliedRef = useRef(false);
    useEffect(() => {
        if (defaultAppliedRef.current) return;
        if (selectedModels.length > 0) { defaultAppliedRef.current = true; return; }

        const defaults = settings.defaultImageModels || [];
        if (defaults.length === 0) return;

        const availableIds = new Set(imageModels.map((m) => m.id));
        const valid = defaults.filter((id) => availableIds.has(id));
        const missing = defaults.filter((id) => !availableIds.has(id));

        if (valid.length > 0) {
            setSelectedModels(valid);
            defaultAppliedRef.current = true;
        }
        if (missing.length > 0 && imageModels.length > 0) {
            // Models existed when user saved settings but are no longer available
            toast.warning(`${missing.length} default model(s) no longer available — check Settings`);
        }
    }, [settings.defaultImageModels, imageModels]); // eslint-disable-line react-hooks/exhaustive-deps

    const [expandedTiers, setExpandedTiers] = useState<Record<CostTier, boolean>>({
        budget: false,
        standard: false,
        premium: false,
    });

    // Auto-deselect models that do not support image input when it's required.
    // Prevents user from having "ghost" selections that would fail silently.
    useEffect(() => {
        if (!requiresImageInput) return;
        const validIds = new Set(displayModels.filter(m => m.imageInputMode !== null).map((m) => m.id));
        const removed = selectedModels.filter((id) => !validIds.has(id));
        if (removed.length > 0) {
            setSelectedModels((prev) => prev.filter((id) => validIds.has(id)));
            toast.info(`${removed.length} model(s) deselected — they don't support image inputs`);
        }
    }, [requiresImageInput, displayModels]); // eslint-disable-line react-hooks/exhaustive-deps

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
            brandName: formValues.business_name,
            brandSummary: toggles.useSummary ? formValues.business_summary : undefined,
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

        // [PCM-SOND 2026-05-16] DIAGNOSTIC — grep "PCM-SOND" to find and remove
        console.log('[PCM-SOND] brandCtx →', brandCtx);
        console.log('[PCM-SOND] formValues →', formValues);
        console.log('[PCM-SOND] contextData.brand →', contextData.brand);
        console.log('[PCM-SOND] hasBrandCtx →', hasBrandCtx);

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
            // Combine Logo and Reference Images into a single input stream.
            // Logo resolution via canonical resolver — see app/shared/brandAssetResolver.ts.
            const logo = getBrandLogo(contextData.brand as any);

            const refUrls = [
                ...(toggles.useLogo && logo ? [logo.url] : []),
                ...(toggles.useReferenceSubjects ? sessionReferenceImages.filter((img) => img.fileKey !== logo?.fileKey).map((img) => img.url) : [])
            ];
            const refIntents = [
                ...(toggles.useLogo && logo ? ['auto'] : []),
                ...(toggles.useReferenceSubjects ? sessionReferenceImages.filter((img) => img.fileKey !== logo?.fileKey).map((img) => img.intent) : [])
            ];

            // Build asset pipeline context once (shared across all generations)
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
                  try {
                    const model = displayModels.find((m: { id: string; name: string; provider: string; costTier: CostTier }) => m.id === modelId);

                    // Resolve provider once per model
                    const resolvedProvider = model?.provider
                        ?? imageModels.find((m: { id: string; provider: string }) => m.id === modelId)?.provider
                        ?? '';

                    if (!resolvedProvider) {
                        console.error(`[useImageGeneration] No provider for model ${modelId} — skipping.`);
                        return; // Skip this model, don't crash everything
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
                                ...(refUrls.length > 0 ? { inputUrls: refUrls, referenceImageIntents: refIntents } : {}),
                                ...pipelineExtra,
                            });

                            // Replace placeholder with completed asset
                            const dbId = (result as any).id;
                            const dbPrompt = (result as any).prompt;
                            setAssets((prev) =>
                                prev.map((a) =>
                                    a.id === placeholderId
                                        ? { ...a, id: dbId ? String(dbId) : a.id, url: (result as any).url, thumbnailUrl: (result as any).url, prompt: dbPrompt ?? a.prompt, status: 'complete' as const }
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

                        // Progress update
                        completed++;
                        setStatus((prev) => ({
                            ...prev,
                            progress: 10 + ((completed / totalWork) * 85),
                            message: `Generating: ${version.name} (${model?.name ?? modelId})...`,
                        }));
                    }
                  } catch (modelError) {
                    // Catch-all for unexpected model-level failures (network, auth, etc.)
                    console.error(`[useImageGeneration] Model ${modelId} failed entirely:`, modelError);
                    toast.error(`${modelId} failed — other models continue.`);
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
        requiresImageInput,
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
