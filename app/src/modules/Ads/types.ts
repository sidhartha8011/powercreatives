/**
 * ADS MODULE — Type definitions
 *
 * Core types for the Ad Composer orchestration module.
 * Designed to be extensible: `MediaSlot.type` supports 'image' | 'video'
 * so video generation can be added without breaking existing contracts.
 */

// ============================================================================
// Media Slot — supports image now, video later (fishbone)
// ============================================================================

export type MediaType = 'image' | 'video';
export type MediaStatus = 'pending' | 'processing' | 'complete' | 'failed';

/**
 * A single media asset attached to an AdCreative.
 * Extensible: when video is added, just set type='video' and populate videoUrl.
 */
export interface MediaSlot {
  /** Unique identifier for this media slot */
  id: string;
  /** Type of media — image now, video later */
  type: MediaType;
  /** Current generation status */
  status: MediaStatus;
  /** Final URL of the generated asset (image or video) */
  url?: string;
  /** Thumbnail URL for preview (same as url for images, poster for video) */
  thumbnailUrl?: string;
  /** Name of the model that generated this asset */
  modelName: string;
  /** Model ID used for generation */
  modelId: string;
  /** Provider (openai, kie, etc.) */
  provider: string;
  /** The prompt used for generation */
  prompt: string;
  /** Error message if generation failed */
  errorMessage?: string;
  /** Database asset ID (for refine/variations via existing Image endpoints) */
  dbAssetId?: number;
}

// ============================================================================
// Text Slot — copy content for an ad
// ============================================================================

export interface TextSlot {
  /** Unique identifier (maps to CopyVariation.id from backend) */
  id: string;
  /** Headline text */
  headline: string;
  /** Body/description text */
  body: string;
  /** Call-to-action text */
  cta?: string;
  /** Hashtags array */
  hashtags?: string[];
  /** Audience name this was generated for */
  audienceName?: string;
  /** Angle/creative direction name */
  angleName?: string;
  /** Model that generated this text */
  modelUsed: string;
}

// ============================================================================
// Orchestration State
// ============================================================================

/** Phases of the generation pipeline */
export type AdsPhase =
  | 'idle'
  | 'text_phase'
  | 'image_phase'
  | 'video_phase'   // Fishbone — not active yet
  | 'complete'
  | 'error';

/** Progress tracker for the generation pipeline */
export interface AdsProgress {
  /** Current phase of the pipeline */
  phase: AdsPhase;
  /** Number of completed items in current phase */
  current: number;
  /** Total items expected in current phase */
  total: number;
  /** Human-readable label for current status */
  label: string;
}

// ============================================================================
// Form State
// ============================================================================

/** User inputs from the sidebar */
export interface AdsFormState {
  /** Creative brief / prompt */
  brief: string;
  /** Selected text model ID for copy generation */
  textModelId: string;
  /** Selected image model IDs (multi-select, like Image module) */
  imageModelIds: string[];
  /** Selected video model ID (fishbone — empty string = disabled) */
  videoModelId: string;
  /** Number of image variations per model */
  imageVariations: number;
}
