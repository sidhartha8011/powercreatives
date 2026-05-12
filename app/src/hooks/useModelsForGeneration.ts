/**
 * USE MODELS FOR GENERATION HOOK
 * 
 * Purpose: Provide models for Image and Video generation modules
 * Uses the unified Model Registry as the source of truth
 * 
 * This hook bridges the new unified Model Registry with the existing
 * Image and Video modules, providing backward-compatible interfaces.
 */

import { useMemo } from "react";
import { trpc } from "@/lib/trpc";
import { KIE_MARKETPLACE_VIDEO_MODELS, KIE_MARKETPLACE_IMAGE_MODELS, KIE_MARKETPLACE_AUDIO_MODELS } from "@shared/kieMarketplaceModels";
import type { ModelData, CostTier } from "@shared/types/models";

/**
 * Model format for generation modules
 */
export interface GenerationModel {
  id: string;
  name: string;
  provider: string;
  costTier: CostTier;
  /** Original model ID from provider */
  originalModelId: string;
  /** Database ID for registry operations */
  registryId: number;
  /** Whether this model supports audio generation */
  supportsAudio: boolean;
}

/**
 * Tier configuration for UI display
 */
export const TIER_CONFIG: Record<CostTier, { label: string; icon: string; color: string; bgColor: string }> = {
  budget: { label: 'Budget', icon: '$', color: 'text-green-600', bgColor: 'bg-green-100' },
  standard: { label: 'Standard', icon: '$$', color: 'text-yellow-600', bgColor: 'bg-yellow-100' },
  premium: { label: 'Premium', icon: '$$$', color: 'text-red-500', bgColor: 'bg-red-100' },
};

/**
 * Pre-built lookup map for marketplace model capabilities.
 * Maps model ID → supportsAudio. Built once at module load.
 */
const marketplaceAudioSupport = new Map<string, boolean>(
  [
    ...KIE_MARKETPLACE_VIDEO_MODELS,
    ...KIE_MARKETPLACE_IMAGE_MODELS,
    ...KIE_MARKETPLACE_AUDIO_MODELS,
  ].map(m => [m.id, m.supportsAudio ?? false])
);

/**
 * Dedicated API models that always generate audio (no parameter needed).
 * These models are NOT in the marketplace registry but produce audio natively.
 * Add new dedicated audio models here when onboarded.
 */
const DEDICATED_AUDIO_MODELS = new Set<string>([
  'kie-veo-3.1',         // Veo 3.1 Fast: "All videos ship with background audio by default"
  'kie-veo-3.1-quality', // Veo 3.1 Quality: same audio support, 1080p output
]);

/**
 * Convert ModelData from registry to GenerationModel format.
 * Enriches DB data with marketplace capabilities (supportsAudio).
 */
function toGenerationModel(model: ModelData): GenerationModel {
  return {
    id: model.modelId,
    name: model.customName || model.originalName,
    provider: model.provider,
    costTier: model.costTier,
    originalModelId: model.modelId,
    registryId: model.id,
    supportsAudio: marketplaceAudioSupport.get(model.modelId) ?? DEDICATED_AUDIO_MODELS.has(model.modelId),
  };
}

/**
 * Hook to get models for image generation
 */
export function useImageModelsForGeneration() {
  // Fetch models that can generate images
  const { data: models = [], isLoading } = trpc.models.getForGeneration.useQuery(
    { type: "image" },
  );

  // Convert to GenerationModel format
  const imageModels = useMemo(() =>
    models.map(toGenerationModel),
    [models]
  );

  // Group by cost tier
  const modelsByTier = useMemo(() => ({
    budget: imageModels.filter(m => m.costTier === "budget"),
    standard: imageModels.filter(m => m.costTier === "standard"),
    premium: imageModels.filter(m => m.costTier === "premium"),
  }), [imageModels]);

  return {
    imageModels,
    imageModelsByTier: modelsByTier,
    isLoading,
    hasModels: imageModels.length > 0,
  };
}

/**
 * Hook to get models for video generation
 */
export function useVideoModelsForGeneration() {
  // Fetch models that can generate videos
  const { data: models = [], isLoading } = trpc.models.getForGeneration.useQuery(
    { type: "video" },
  );

  // Convert to GenerationModel format
  const videoModels = useMemo(() =>
    models.map(toGenerationModel),
    [models]
  );

  // Group by cost tier
  const modelsByTier = useMemo(() => ({
    budget: videoModels.filter(m => m.costTier === "budget"),
    standard: videoModels.filter(m => m.costTier === "standard"),
    premium: videoModels.filter(m => m.costTier === "premium"),
  }), [videoModels]);

  return {
    videoModels,
    videoModelsByTier: modelsByTier,
    isLoading,
    hasModels: videoModels.length > 0,
  };
}

/**
 * Hook to get models for image editing
 */
export function useImageModelsForEditing() {
  // Fetch models that can edit images
  const { data: models = [], isLoading } = trpc.models.getForEditing.useQuery(
    { type: "image" },
  );

  // Convert to GenerationModel format
  const editModels = useMemo(() =>
    models.map(toGenerationModel),
    [models]
  );

  // Group by cost tier
  const modelsByTier = useMemo(() => ({
    budget: editModels.filter(m => m.costTier === "budget"),
    standard: editModels.filter(m => m.costTier === "standard"),
    premium: editModels.filter(m => m.costTier === "premium"),
  }), [editModels]);

  return {
    editModels,
    editModelsByTier: modelsByTier,
    isLoading,
    hasModels: editModels.length > 0,
  };
}

/**
 * Hook to get models for video editing
 */
export function useVideoModelsForEditing() {
  // Fetch models that can edit videos
  const { data: models = [], isLoading } = trpc.models.getForEditing.useQuery(
    { type: "video" },
  );

  // Convert to GenerationModel format
  const editModels = useMemo(() =>
    models.map(toGenerationModel),
    [models]
  );

  // Group by cost tier
  const modelsByTier = useMemo(() => ({
    budget: editModels.filter(m => m.costTier === "budget"),
    standard: editModels.filter(m => m.costTier === "standard"),
    premium: editModels.filter(m => m.costTier === "premium"),
  }), [editModels]);

  return {
    editModels,
    editModelsByTier: modelsByTier,
    isLoading,
    hasModels: editModels.length > 0,
  };
}

/**
 * Combined hook for backward compatibility with existing useModelRegistry
 * This provides the same interface as the old hook but uses the new unified registry
 */
export function useModelsForGeneration() {
  const {
    imageModels,
    imageModelsByTier,
    isLoading: isLoadingImage,
    hasModels: hasImageModels,
  } = useImageModelsForGeneration();

  const {
    videoModels,
    videoModelsByTier,
    isLoading: isLoadingVideo,
    hasModels: hasVideoModels,
  } = useVideoModelsForGeneration();

  return {
    // Image models
    imageModels,
    imageModelsByTier,

    // Video models
    videoModels,
    videoModelsByTier,

    // Loading state
    isLoading: isLoadingImage || isLoadingVideo,

    // Has models
    hasImageModels,
    hasVideoModels,
    hasModels: hasImageModels || hasVideoModels,
  };
}
