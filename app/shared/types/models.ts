/**
 * UNIFIED MODEL REGISTRY - Shared Types
 * 
 * Purpose: Define types for the unified models table
 * Used by: Settings UI (Model Registry), Image/Video modules, Backend routers
 * 
 * This is the single source of truth for model type definitions.
 */

/**
 * The six distinct capabilities a model can have
 */
export type ModelCapability = 
  | 'can_generate_image' 
  | 'can_edit_image' 
  | 'can_generate_video' 
  | 'can_edit_video'
  | 'can_generate_text'
  | 'can_vision';

/**
 * All capability keys for iteration
 */
export const ALL_MODEL_CAPABILITIES: ModelCapability[] = [
  'can_generate_image',
  'can_edit_image',
  'can_generate_video',
  'can_edit_video',
  'can_generate_text',
  'can_vision',
];

/**
 * Human-readable labels for capabilities
 */
export const MODEL_CAPABILITY_LABELS: Record<ModelCapability, string> = {
  can_generate_image: 'Generate Image',
  can_edit_image: 'Edit Image',
  can_generate_video: 'Generate Video',
  can_edit_video: 'Edit Video',
  can_generate_text: 'Generate Text',
  can_vision: 'Vision',
};

/**
 * Short labels for table headers
 */
export const MODEL_CAPABILITY_SHORT_LABELS: Record<ModelCapability, string> = {
  can_generate_image: 'Gen Img',
  can_edit_image: 'Edit Img',
  can_generate_video: 'Gen Vid',
  can_edit_video: 'Edit Vid',
  can_generate_text: 'Gen Text',
  can_vision: 'Vision',
};

/**
 * Cost tier for pricing grouping
 */
export type CostTier = 'budget' | 'standard' | 'premium';

/**
 * All cost tiers for iteration
 */
export const ALL_COST_TIERS: CostTier[] = ['budget', 'standard', 'premium'];

/**
 * Human-readable labels for cost tiers
 */
export const COST_TIER_LABELS: Record<CostTier, string> = {
  budget: 'Budget $',
  standard: 'Standard $$',
  premium: 'Premium $$$',
};

/**
 * Module types that models can be enabled/disabled for.
 * Extensible: add new modules here without refactoring.
 */
export type ModuleType = 'copy' | 'image' | 'video';

/**
 * All module types for iteration
 */
export const ALL_MODULE_TYPES: ModuleType[] = ['copy', 'image', 'video'];

/**
 * Human-readable labels for modules
 */
export const MODULE_TYPE_LABELS: Record<ModuleType, string> = {
  copy: 'Copy',
  image: 'Image',
  video: 'Video',
};

/**
 * Map from getForGeneration type to ModuleType
 */
export const GENERATION_TYPE_TO_MODULE: Record<string, ModuleType> = {
  text: 'copy',
  image: 'image',
  video: 'video',
};

/**
 * Per-module enablement map. Keys are ModuleType, values are booleans.
 */
export type EnabledModulesMap = Partial<Record<ModuleType, boolean>>;

/**
 * Derive default enabledModules from a model's capabilities.
 * A module is enabled by default if the model has the matching capability.
 */
export function deriveDefaultEnabledModules(caps: {
  canGenerateText: boolean;
  canGenerateImage: boolean;
  canEditImage: boolean;
  canGenerateVideo: boolean;
  canEditVideo: boolean;
}): EnabledModulesMap {
  return {
    copy: caps.canGenerateText,
    image: caps.canGenerateImage || caps.canEditImage,
    video: caps.canGenerateVideo || caps.canEditVideo,
  };
}

/**
 * Check if a model is enabled for a specific module.
 * If enabledModules is null/undefined, fall back to capability-based defaults.
 */
export function isModelEnabledForModule(
  model: Pick<ModelData, 'canGenerateText' | 'canGenerateImage' | 'canEditImage' | 'canGenerateVideo' | 'canEditVideo'> & { enabledModules: EnabledModulesMap | null },
  module: ModuleType,
): boolean {
  if (model.enabledModules && model.enabledModules[module] !== undefined) {
    return model.enabledModules[module]!;
  }
  // Fall back to capability-based defaults
  const defaults = deriveDefaultEnabledModules(model);
  return defaults[module] ?? false;
}

/**
 * Status of model configuration
 * - auto: Automatically detected from provider
 * - ai_suggested: AI analyzed and suggested capabilities/pricing
 * - confirmed: User manually confirmed/set configuration
 */
export type ModelStatus = 'auto' | 'ai_suggested' | 'confirmed';

/**
 * Human-readable labels for status
 */
export const MODEL_STATUS_LABELS: Record<ModelStatus, string> = {
  auto: 'Auto-detected',
  ai_suggested: 'AI Suggested',
  confirmed: 'Confirmed',
};

/**
 * Unified Model data structure
 * Represents a single model in the registry
 */
export interface ModelData {
  id: number;
  userId: number;
  
  // Identification
  modelId: string;
  provider: string;
  originalName: string;
  customName: string | null;
  
  // Capabilities (6 boolean flags)
  canGenerateImage: boolean;
  canEditImage: boolean;
  canGenerateVideo: boolean;
  canEditVideo: boolean;
  canGenerateText: boolean;
  canVision: boolean;
  
  // Pricing
  costTier: CostTier;
  
  // Status & Control
  status: ModelStatus;
  isEnabled: boolean;
  isAvailable: boolean;
  
  // Per-module enablement
  enabledModules: EnabledModulesMap | null;
  
  // Provider-specific config
  providerMetadata: Record<string, unknown> | null;
  
  // Image input capability — from Kie marketplace registry.
  // null = model does NOT accept reference images (e.g. DALL-E).
  // Non-null = the API field name for image input (e.g. "image_urls").
  imageInputMode: string | null;
  
  // Metadata
  description: string | null;
  tags: string[];
  sortOrder: number;
  
  // Timestamps
  createdAt: Date;
  updatedAt: Date;
}

/**
 * Input for creating a new model entry
 */
export interface CreateModelInput {
  modelId: string;
  provider: string;
  originalName: string;
  customName?: string | null;
  canGenerateImage?: boolean;
  canEditImage?: boolean;
  canGenerateVideo?: boolean;
  canEditVideo?: boolean;
  canGenerateText?: boolean;
  canVision?: boolean;
  costTier?: CostTier;
  status?: ModelStatus;
  isEnabled?: boolean;
  isAvailable?: boolean;
  providerMetadata?: Record<string, unknown> | null;
  description?: string | null;
  tags?: string[];
  sortOrder?: number;
}

/**
 * Input for updating a model entry
 */
export interface UpdateModelInput {
  id: number;
  customName?: string | null;
  canGenerateImage?: boolean;
  canEditImage?: boolean;
  canGenerateVideo?: boolean;
  canEditVideo?: boolean;
  canGenerateText?: boolean;
  canVision?: boolean;
  costTier?: CostTier;
  status?: ModelStatus;
  isEnabled?: boolean;
  description?: string | null;
  tags?: string[];
  sortOrder?: number;
}

/**
 * Filter options for querying models
 */
export interface ModelFilter {
  provider?: string;
  capability?: ModelCapability;
  costTier?: CostTier;
  status?: ModelStatus;
  isEnabled?: boolean;
  isAvailable?: boolean;
}

/**
 * Get the display name for a model (custom name or original name)
 */
export function getModelDisplayName(model: Pick<ModelData, 'customName' | 'originalName'>): string {
  return model.customName || model.originalName;
}

/**
 * Check if a model has a specific capability
 */
export function modelHasCapability(model: ModelData, capability: ModelCapability): boolean {
  switch (capability) {
    case 'can_generate_image': return model.canGenerateImage;
    case 'can_edit_image': return model.canEditImage;
    case 'can_generate_video': return model.canGenerateVideo;
    case 'can_edit_video': return model.canEditVideo;
    case 'can_generate_text': return model.canGenerateText;
    case 'can_vision': return model.canVision;
    default: return false;
  }
}

/**
 * Get all capabilities a model has as an array
 */
export function getModelCapabilities(model: ModelData): ModelCapability[] {
  const capabilities: ModelCapability[] = [];
  if (model.canGenerateImage) capabilities.push('can_generate_image');
  if (model.canEditImage) capabilities.push('can_edit_image');
  if (model.canGenerateVideo) capabilities.push('can_generate_video');
  if (model.canEditVideo) capabilities.push('can_edit_video');
  if (model.canGenerateText) capabilities.push('can_generate_text');
  if (model.canVision) capabilities.push('can_vision');
  return capabilities;
}

/**
 * Convert database row to ModelData
 */
export function toModelData(row: {
  id: number;
  userId: number;
  modelId: string;
  provider: string;
  originalName: string;
  customName: string | null;
  canGenerateImage: boolean;
  canEditImage: boolean;
  canGenerateVideo: boolean;
  canEditVideo: boolean;
  canGenerateText: boolean;
  canVision: boolean;
  costTier: CostTier;
  status: ModelStatus;
  isEnabled: boolean;
  isAvailable: boolean;
  enabledModules?: unknown;
  providerMetadata?: unknown;
  imageInputMode?: string | null;
  description: string | null;
  tags: string | null;
  sortOrder: number;
  createdAt: Date;
  updatedAt: Date;
}): ModelData {
  // Parse enabledModules: could be a JSON string, object, or null
  let parsedModules: EnabledModulesMap | null = null;
  if (row.enabledModules) {
    if (typeof row.enabledModules === 'string') {
      try { parsedModules = JSON.parse(row.enabledModules); } catch { parsedModules = null; }
    } else if (typeof row.enabledModules === 'object') {
      parsedModules = row.enabledModules as EnabledModulesMap;
    }
  }
  
  return {
    id: row.id,
    userId: row.userId,
    modelId: row.modelId,
    provider: row.provider,
    originalName: row.originalName,
    customName: row.customName,
    canGenerateImage: row.canGenerateImage,
    canEditImage: row.canEditImage,
    canGenerateVideo: row.canGenerateVideo,
    canEditVideo: row.canEditVideo,
    canGenerateText: row.canGenerateText,
    canVision: row.canVision,
    costTier: row.costTier,
    status: row.status,
    isEnabled: row.isEnabled,
    isAvailable: row.isAvailable,
    enabledModules: parsedModules,
    providerMetadata: (row.providerMetadata && typeof row.providerMetadata === 'object') 
      ? row.providerMetadata as Record<string, unknown> 
      : null,
    imageInputMode: row.imageInputMode ?? null,
    description: row.description,
    tags: row.tags ? JSON.parse(row.tags) : [],
    sortOrder: row.sortOrder,
    createdAt: row.createdAt,
    updatedAt: row.updatedAt,
  };
}
