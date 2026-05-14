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

import { useState, useMemo, useCallback } from 'react';
import { trpc } from '@/lib/trpc';
import { toast } from 'sonner';
import { useSettings } from '@/contexts/AppContext';
import type {
    AdVersion,
    GeneratedAsset,
    GenerationStatus,
    CostTier,
} from '@/types';
import { PRODUCTION_DEFAULTS, STORAGE_KEYS } from '../imageConfig';
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

    // Standardised display format
    const displayModels = useMemo(
        () => imageModels.map((m: { id: string; name: string; provider: string; costTier: CostTier }) => ({ id: m.id, name: m.name, provider: m.provider, costTier: m.costTier })),
        [imageModels],
    );

    const modelsByTier = useMemo(
        () => ({
            budget: modelsByTierRaw.budget.map((m: { id: string; name: string; provider: string; costTier: CostTier }) => ({ id: m.id, name: m.name, provider: m.provider, costTier: m.costTier })),
            standard: modelsByTierRaw.standard.map((m: { id: string; name: string; provider: string; costTier: CostTier }) => ({ id: m.id, name: m.name, provider: m.provider, costTier: m.costTier })),
            premium: modelsByTierRaw.premium.map((m: { id: string; name: string; provider: string; costTier: CostTier }) => ({ id: m.id, name: m.name, provider: m.provider, costTier: m.costTier })),
        }),
        [modelsByTierRaw],
    );

    // ── Selection state ──
    const [selectedModels, setSelectedModels] = useState<string[]>([]);
    const [expandedTiers, setExpandedTiers] = useState<Record<CostTier, boolean>>({
        budget: false,
        standard: false,
        premium: false,
    });

    // ── Production parameters ──
    const [numVersions, setNumVersions] = useState(PRODUCTION_DEFAULTS.numVersions);
    const [variationsPerModel, setVariationsPerModel] = useState(PRODUCTION_DEFAULTS.variationsPerModel);
    const [autoOptimizeBrief, setAutoOptimizeBrief] = useState(PRODUCTION_DEFAULTS.autoOptimizeBrief);

    // ── Output state ──
    const [status, setStatus] = useState<GenerationStatus>({ isGenerating: false, progress: 0, message: '' });
    const [adVersions, setAdVersions] = useState<AdVersion[]>([]);
    const [activeTab, setActiveTab] = useState<string | null>(null);
    const [assets, setAssets] = useState<GeneratedAsset[]>([]);

    // ── Multi-select state for bulk actions ──
    const [selectedAssetIds, setSelectedAssetIds] = useState<Set<string>>(new Set());

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

    // ── Multi-select: derived selected assets ──
    const selectedAssets = useMemo(
        () => assets.filter((a) => selectedAssetIds.has(a.id)),
        [assets, selectedAssetIds],
    );

    // ── Multi-select: toggle a single asset ──
    const toggleAssetSelection = useCallback((id: string) => {
        setSelectedAssetIds((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    }, []);

    // ── Multi-select: clear all selections ──
    const clearAssetSelection = useCallback(() => {
        setSelectedAssetIds(new Set());
    }, []);

    // ── Multi-select: select/deselect all completed assets for a model ──
    const selectAllForModel = useCallback((modelId: string) => {
        const modelAssets = (assetsByModel[modelId] ?? []).filter((a) => a.status === 'complete');
        setSelectedAssetIds((prev) => {
            const next = new Set(prev);
            const allSelected = modelAssets.every((a) => next.has(a.id));
            if (allSelected) {
                // Deselect all for this model
                modelAssets.forEach((a) => next.delete(a.id));
            } else {
                // Select all for this model
                modelAssets.forEach((a) => next.add(a.id));
            }
            return next;
        });
    }, [assetsByModel]);

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
        const idsToRemove = new Set(selectedAssetIds);
        setAssets((prev) => prev.filter((a) => !idsToRemove.has(a.id)));
        setSelectedAssetIds(new Set());
        toast.success(`Removed ${idsToRemove.size} images`);
    }, [selectedAssetIds]);

    // ── Build brand color context string ──
    const buildColorContext = useCallback((brand: ContextData['brand']): string => {
        const colors = ((brand as any)?.colors as string[] | null) ?? [];
        if (colors.length === 0) return '';
        const parts: string[] = [];
        if (colors[0]) parts.push(`primary ${colors[0]}`);
        if (colors[1]) parts.push(`secondary ${colors[1]}`);
        colors.slice(2, 4).forEach((c) => parts.push(c));
        return ` Use brand colors: ${parts.join(', ')}.`;
    }, []);

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

        try {
            // 0. Optionally optimize the brief via LLM
            let briefToUse = productBrief;
            if (autoOptimizeBrief) {
                setStatus((prev) => ({ ...prev, progress: 5, message: 'Optimizing your brief...' }));
                try {
                    const optimized = await optimizeBriefMutation.mutateAsync({
                        brief: productBrief,
                        // Menu Intelligence model selection
                        modelId: settings.defaultImageTextModel || undefined,
                        brandContext: contextData.brand ? { name: (contextData.brand as any).name } : undefined,
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
                    // Menu Intelligence model selection
                    modelId: settings.defaultImageTextModel || undefined,
                    brandContext: contextData.brand ? {
                        name: (contextData.brand as any).name,
                        summary: (contextData.brand as any).businessSummary,
                    } : undefined,
                    referenceImages: sessionReferenceImages.map((img) => ({ url: img.url, intent: img.intent })),
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

            // 2. Generate images for each concept × model × variation
            const totalWork = generatedVersions.length * selectedModels.length * variationsPerModel;
            let completed = 0;
            const colorCtx = buildColorContext(contextData.brand);
            const refUrls = sessionReferenceImages.map((img) => img.url);
            const refIntents = sessionReferenceImages.map((img) => img.intent);

            for (const version of generatedVersions) {
                for (const modelId of selectedModels) {
                    const model = displayModels.find((m: { id: string; name: string; provider: string; costTier: CostTier }) => m.id === modelId);

                    for (let v = 0; v < variationsPerModel; v++) {
                        setStatus((prev) => ({
                            ...prev,
                            progress: 10 + ((completed / totalWork) * 85),
                            message: `Generating: ${version.name} (${model?.name ?? modelId})...`,
                        }));

                        // Build the actual prompt that will be sent to the image model.
                        // Anchor angle: description IS the prompt → send directly.
                        // AI angles: combine concept description with product brief.
                        const isAnchor = version.name === 'Original';
                        const fullPrompt = isAnchor
                            ? `${version.description}${colorCtx}`
                            : `${version.description}. Product: ${productBrief}${colorCtx}`;

                        // Place a processing placeholder immediately for optimistic UI
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

                            // Build asset pipeline context for the API payload
                            const pipelineExtra: Record<string, unknown> = {};
                            if (assetPipelinePayload.logoBase64) {
                                pipelineExtra.logoBase64 = assetPipelinePayload.logoBase64;
                            }
                            if (assetPipelinePayload.subjectBase64) {
                                pipelineExtra.subjectBase64 = assetPipelinePayload.subjectBase64;
                            }
                            if (assetPipelinePayload.textOverlay?.text) {
                                pipelineExtra.textOverlay = assetPipelinePayload.textOverlay;
                            }

                            // Resolve provider — first from displayModels, then from full imageModels list.
                            // An empty provider causes a PHP 400 error, so we must guard against it.
                            const resolvedProvider = model?.provider
                                ?? imageModels.find((m: { id: string; provider: string }) => m.id === modelId)?.provider
                                ?? '';

                            if (!resolvedProvider) {
                                throw new Error(`No provider found for model ${modelId}. Check the model registry.`);
                            }

                            const result = await generateImageMutation.mutateAsync({
                                prompt: fullPrompt,
                                model: modelId,
                                provider: model?.provider ?? '',
                                ...(refUrls.length > 0 ? { referenceImageUrls: refUrls, referenceImageIntents: refIntents } : {}),
                                ...pipelineExtra,
                            });

                            // Replace placeholder with completed asset
                            // CRITICAL: Capture result.id (DB autoincrement) so Save-to-Project
                            // sends the real pcm_assets.id, not the frontend UUID placeholder.
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

                        completed++;
                    }
                }
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
        buildColorContext,
        settings.defaultImageTextModel,
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
        // Multi-select (bulk actions)
        selectedAssetIds,
        selectedAssets,
        toggleAssetSelection,
        clearAssetSelection,
        selectAllForModel,
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
