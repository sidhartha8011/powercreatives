/**
 * KIE.AI MARKETPLACE MODEL REGISTRY
 *
 * Single source of truth for all Kie.ai marketplace models.
 * Every marketplace model uses the unified Market API:
 *   POST /api/v1/jobs/createTask  { model, input, callBackUrl? }
 *   GET  /api/v1/jobs/recordInfo?taskId=...
 *
 * To add a new model: append one entry to the appropriate array below.
 * Zero code changes required — the generic client reads this registry.
 *
 * Dedicated-API models (GPT-4o Image, Flux Kontext, Runway, Veo, Luma)
 * are NOT in this file — they have unique endpoints and body formats.
 */

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

/** Asset types supported by Kie.ai */
export type KieAssetType = 'image' | 'video' | 'audio';

/** What a model can do */
export type KieCapability =
  | 'text-to-image'
  | 'image-to-image'
  | 'image-edit'
  | 'image-remix'
  | 'image-reframe'
  | 'upscale'
  | 'remove-background'
  | 'text-to-video'
  | 'image-to-video'
  | 'video-to-video'
  | 'motion-control'
  | 'avatar'
  | 'text-to-speech'
  | 'speech-to-text'
  | 'sound-effect'
  | 'audio-isolation'
  | 'text-to-dialogue';

/** How the model accepts image input (determines which field name to use) */
export type KieImageInputMode =
  | null              // No image input (text-only)
  | 'input_urls'      // Array field: input.input_urls = ["url1", "url2"]
  | 'image_url'       // Single field: input.image_url = "url"
  | 'image_urls'      // Array field: input.image_urls = ["url1"] (Kling/Veo style)
  | 'image'           // Single field: input.image = "url" (Recraft style)
  | 'video_url'            // Single field: input.video_url = "url" (video-to-video)
  | 'reference_image_urls'; // Array field: input.reference_image_urls = ["url"] (Ideogram Character)

/** How the model accepts aspect ratio */
export type KieAspectRatioMode =
  | null              // No aspect ratio support
  | 'aspect_ratio'    // input.aspect_ratio = "16:9"
  | 'image_size';     // input.image_size = "square_hd" (Ideogram/Qwen enum)

/** A single marketplace model definition — pure data, no logic */
export interface KieMarketplaceModel {
  /** Our internal model ID (used in DB, routing, frontend) */
  readonly id: string;
  /** Exact model name sent to the API (case-sensitive) */
  readonly modelName: string;
  /** Human-readable display name */
  readonly displayName: string;
  /** Vendor/brand for grouping in UI */
  readonly vendor: string;
  /** What type of asset this produces */
  readonly assetType: KieAssetType;
  /** What this model does */
  readonly capability: KieCapability;
  /** How this model accepts image input (null = text-only) */
  readonly imageInputMode: KieImageInputMode;
  /** How this model accepts aspect ratio (null = not supported) */
  readonly aspectRatioMode: KieAspectRatioMode;
  /** Whether this model accepts a duration parameter */
  readonly supportsDuration: boolean;
  /**
   * Valid duration values for this model (if supportsDuration is true).
   * The input mapper validates against this list and falls back to defaultDuration.
   * Example: ['4', '8', '12'] for Seedance 1.5 Pro
   */
  readonly validDurations?: readonly string[];
  /**
   * Default duration value when supportsDuration is true but caller doesn't provide one,
   * or when the provided value is not in validDurations.
   */
  readonly defaultDuration?: string;
  /**
   * Model-specific aspect ratio mapping.
   * Maps our normalized format ('portrait'|'landscape') to the model's expected value.
   * If not provided, the mapper uses standard '9:16'/'16:9' defaults.
   */
  readonly aspectRatioMap?: Readonly<Record<string, string>>;
  /** Whether this model accepts a negative_prompt parameter */
  readonly supportsNegativePrompt: boolean;
  /** Whether this model supports audio generation (sound/generate_audio parameter). Defaults to false if omitted. */
  readonly supportsAudio?: boolean;
  /** Maximum number of image inputs (0 = text-only) */
  readonly maxImageInputs: number;
  /** Brief description for UI */
  readonly description: string;
  /**
   * Additional required input fields with their default values.
   * The input mapper sends these when the caller doesn't provide them.
   * Key = exact API field name, Value = default value.
   * Example: { resolution: '1K' } or { quality: 'medium' }
   */
  readonly extraRequiredFields?: Readonly<Record<string, string | number | boolean>>;
}

// ---------------------------------------------------------------------------
// Image Models
// ---------------------------------------------------------------------------

export const KIE_MARKETPLACE_IMAGE_MODELS: readonly KieMarketplaceModel[] = [
  // --- Seedream ---
  {
    id: 'kie-seedream-3',
    modelName: 'bytedance/seedream',
    displayName: 'Seedream 3.0',
    vendor: 'Bytedance',
    assetType: 'image',
    capability: 'text-to-image',
    imageInputMode: null,
    aspectRatioMode: 'image_size',
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 0,
    description: 'Creative image generation with unique artistic styles',
  },
  {
    id: 'kie-seedream-4-t2i',
    modelName: 'bytedance/seedream-v4-text-to-image',
    displayName: 'Seedream 4.0',
    vendor: 'Seedream',
    assetType: 'image',
    capability: 'text-to-image',
    imageInputMode: null,
    aspectRatioMode: 'image_size',
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 0,
    description: 'Seedream 4.0 text-to-image generation',
  },
  {
    id: 'kie-seedream-4-edit',
    modelName: 'bytedance/seedream-v4-edit',
    displayName: 'Seedream 4.0 Edit',
    vendor: 'Seedream',
    assetType: 'image',
    capability: 'image-edit',
    imageInputMode: 'image_urls',
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 1,
    description: 'Seedream 4.0 image editing',
  },
  {
    id: 'kie-seedream-4.5-t2i',
    modelName: 'seedream/4.5-text-to-image',
    displayName: 'Seedream 4.5',
    vendor: 'Seedream',
    assetType: 'image',
    capability: 'text-to-image',
    imageInputMode: null,
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 0,
    description: 'Latest Seedream with highest quality',
    extraRequiredFields: { quality: 'basic' },
  },
  {
    id: 'kie-seedream-4.5-edit',
    modelName: 'seedream/4.5-edit',
    displayName: 'Seedream 4.5 Edit',
    vendor: 'Seedream',
    assetType: 'image',
    capability: 'image-edit',
    imageInputMode: 'image_urls',
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 1,
    description: 'Seedream 4.5 image editing',
    extraRequiredFields: { quality: 'basic' },
  },

  // --- Z-Image ---
  {
    id: 'kie-z-image',
    modelName: 'z-image',
    displayName: 'Z-Image',
    vendor: 'Z-Image',
    assetType: 'image',
    capability: 'text-to-image',
    imageInputMode: null,
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 0,
    description: 'Z-Image text-to-image generation',
  },

  // --- Google ---
  {
    id: 'kie-imagen4',
    modelName: 'google/imagen4',
    displayName: 'Imagen 4',
    vendor: 'Google',
    assetType: 'image',
    capability: 'text-to-image',
    imageInputMode: null,
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: false,
    supportsNegativePrompt: true,
    maxImageInputs: 0,
    description: 'Google Imagen 4 standard quality',
  },
  {
    id: 'kie-imagen4-fast',
    modelName: 'google/imagen4-fast',
    displayName: 'Imagen 4 Fast',
    vendor: 'Google',
    assetType: 'image',
    capability: 'text-to-image',
    imageInputMode: null,
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: false,
    supportsNegativePrompt: true,
    maxImageInputs: 0,
    description: 'Google Imagen 4 fast generation',
  },
  {
    id: 'kie-imagen4-ultra',
    modelName: 'google/imagen4-ultra',
    displayName: 'Imagen 4 Ultra',
    vendor: 'Google',
    assetType: 'image',
    capability: 'text-to-image',
    imageInputMode: null,
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: false,
    supportsNegativePrompt: true,
    maxImageInputs: 0,
    description: 'Google Imagen 4 highest quality',
  },
  {
    id: 'kie-nano-banana',
    modelName: 'google/nano-banana',
    displayName: 'Nano Banana',
    vendor: 'Google',
    assetType: 'image',
    capability: 'text-to-image',
    imageInputMode: null,
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 0,
    description: 'Google creative image model',
  },
  {
    id: 'kie-nano-banana-edit',
    modelName: 'google/nano-banana-edit',
    displayName: 'Nano Banana Edit',
    vendor: 'Google',
    assetType: 'image',
    capability: 'image-edit',
    imageInputMode: 'image_url',
    aspectRatioMode: null,
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 1,
    description: 'Google Nano Banana image editing',
  },
  {
    id: 'kie-nano-banana-pro-i2i',
    modelName: 'google/pro-image-to-image',
    displayName: 'Nano Banana Pro I2I',
    vendor: 'Google',
    assetType: 'image',
    capability: 'image-to-image',
    imageInputMode: 'image_url',
    aspectRatioMode: null,
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 1,
    description: 'Nano Banana Pro image-to-image',
  },

  // --- Flux-2 ---
  {
    id: 'kie-flux2-pro-t2i',
    modelName: 'flux-2/pro-text-to-image',
    displayName: 'Flux 2 Pro',
    vendor: 'Flux',
    assetType: 'image',
    capability: 'text-to-image',
    imageInputMode: null,
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 0,
    description: 'Flux 2 Pro text-to-image',
    extraRequiredFields: { resolution: '1K' },
  },
  {
    id: 'kie-flux2-pro-i2i',
    modelName: 'flux-2/pro-image-to-image',
    displayName: 'Flux 2 Pro I2I',
    vendor: 'Flux',
    assetType: 'image',
    capability: 'image-to-image',
    imageInputMode: 'input_urls',
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 8,
    description: 'Flux 2 Pro image-to-image (up to 8 images)',
    extraRequiredFields: { resolution: '1K' },
  },
  {
    id: 'kie-flux2-flex-t2i',
    modelName: 'flux-2/flex-text-to-image',
    displayName: 'Flux 2 Flex',
    vendor: 'Flux',
    assetType: 'image',
    capability: 'text-to-image',
    imageInputMode: null,
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 0,
    description: 'Flux 2 Flex text-to-image',
    extraRequiredFields: { resolution: '1K' },
  },
  {
    id: 'kie-flux2-flex-i2i',
    modelName: 'flux-2/flex-image-to-image',
    displayName: 'Flux 2 Flex I2I',
    vendor: 'Flux',
    assetType: 'image',
    capability: 'image-to-image',
    imageInputMode: 'input_urls',
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 8,
    description: 'Flux 2 Flex image-to-image',
    extraRequiredFields: { resolution: '1K' },
  },

  // --- Grok Imagine ---
  {
    id: 'kie-grok-imagine-t2i',
    modelName: 'grok-imagine/text-to-image',
    displayName: 'Grok Imagine',
    vendor: 'xAI',
    assetType: 'image',
    capability: 'text-to-image',
    imageInputMode: null,
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 0,
    description: 'xAI Grok image generation',
  },
  {
    id: 'kie-grok-imagine-i2i',
    modelName: 'grok-imagine/image-to-image',
    displayName: 'Grok Imagine I2I',
    vendor: 'xAI',
    assetType: 'image',
    capability: 'image-to-image',
    imageInputMode: 'input_urls',
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 1,
    description: 'xAI Grok image-to-image',
  },
  {
    id: 'kie-grok-imagine-upscale',
    modelName: 'grok-imagine/upscale',
    displayName: 'Grok Imagine Upscale',
    vendor: 'xAI',
    assetType: 'image',
    capability: 'upscale',
    imageInputMode: 'input_urls',
    aspectRatioMode: null,
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 1,
    description: 'xAI Grok image upscaling',
  },

  // --- GPT Image 1.5 (Market API version) ---
  {
    id: 'kie-gpt-image-1.5-t2i',
    modelName: 'gpt-image/1.5-text-to-image',
    displayName: 'GPT Image 1.5',
    vendor: 'OpenAI',
    assetType: 'image',
    capability: 'text-to-image',
    imageInputMode: null,
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 0,
    description: 'OpenAI GPT Image 1.5 text-to-image',
    extraRequiredFields: { quality: 'medium' },
  },
  {
    id: 'kie-gpt-image-1.5-i2i',
    modelName: 'gpt-image/1.5-image-to-image',
    displayName: 'GPT Image 1.5 I2I',
    vendor: 'OpenAI',
    assetType: 'image',
    capability: 'image-to-image',
    imageInputMode: 'input_urls',
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 16,
    description: 'OpenAI GPT Image 1.5 image-to-image (up to 16 images)',
    extraRequiredFields: { quality: 'medium' },
  },

  // --- GPT Image 2 (Market API version) ---
  {
    id: 'kie-gpt-image-2-t2i',
    modelName: 'gpt/gpt-image-2-text-to-image',
    displayName: 'GPT Image 2',
    vendor: 'OpenAI',
    assetType: 'image',
    capability: 'text-to-image',
    imageInputMode: null,
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 0,
    description: 'OpenAI GPT Image 2 text-to-image',
    extraRequiredFields: { quality: 'medium' },
  },
  {
    id: 'kie-gpt-image-2-i2i',
    modelName: 'gpt/gpt-image-2-image-to-image',
    displayName: 'GPT Image 2 I2I',
    vendor: 'OpenAI',
    assetType: 'image',
    capability: 'image-to-image',
    imageInputMode: 'input_urls',
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 16,
    description: 'OpenAI GPT Image 2 image-to-image (up to 16 images)',
    extraRequiredFields: { quality: 'medium' },
  },

  // --- Ideogram ---
  {
    id: 'kie-ideogram-character',
    modelName: 'ideogram/character',
    displayName: 'Ideogram Character',
    vendor: 'Ideogram',
    assetType: 'image',
    capability: 'image-to-image',
    imageInputMode: 'reference_image_urls',
    aspectRatioMode: 'image_size',
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 3,
    description: 'Ideogram with character consistency (requires reference images)',
  },
  {
    id: 'kie-ideogram-character-edit',
    modelName: 'ideogram/character-edit',
    displayName: 'Ideogram Character Edit',
    vendor: 'Ideogram',
    assetType: 'image',
    capability: 'image-edit',
    imageInputMode: 'image_url',
    aspectRatioMode: 'image_size',
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 1,
    description: 'Ideogram character-consistent editing',
  },
  {
    id: 'kie-ideogram-v3-reframe',
    modelName: 'ideogram/v3-reframe',
    displayName: 'Ideogram V3 Reframe',
    vendor: 'Ideogram',
    assetType: 'image',
    capability: 'image-reframe',
    imageInputMode: 'image_url',
    aspectRatioMode: 'image_size',
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 1,
    description: 'Ideogram V3 image reframing',
  },

  // --- Qwen ---
  {
    id: 'kie-qwen-t2i',
    modelName: 'qwen/text-to-image',
    displayName: 'Qwen T2I',
    vendor: 'Qwen',
    assetType: 'image',
    capability: 'text-to-image',
    imageInputMode: null,
    aspectRatioMode: 'image_size',
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 0,
    description: 'Qwen text-to-image generation',
  },
  {
    id: 'kie-qwen-i2i',
    modelName: 'qwen/image-to-image',
    displayName: 'Qwen I2I',
    vendor: 'Qwen',
    assetType: 'image',
    capability: 'image-to-image',
    imageInputMode: 'image_url',
    aspectRatioMode: null,
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 1,
    description: 'Qwen image-to-image',
  },
  {
    id: 'kie-qwen-edit',
    modelName: 'qwen/image-edit',
    displayName: 'Qwen Image Edit',
    vendor: 'Qwen',
    assetType: 'image',
    capability: 'image-edit',
    imageInputMode: 'image_url',
    aspectRatioMode: null,
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 1,
    description: 'Qwen image editing with mask support',
  },

  // --- Recraft ---
  {
    id: 'kie-recraft-crisp-upscale',
    modelName: 'recraft/crisp-upscale',
    displayName: 'Recraft Crisp Upscale',
    vendor: 'Recraft',
    assetType: 'image',
    capability: 'upscale',
    imageInputMode: 'image',
    aspectRatioMode: null,
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 1,
    description: 'Recraft crisp image upscaling',
  },
  {
    id: 'kie-recraft-remove-bg',
    modelName: 'recraft/remove-background',
    displayName: 'Recraft Remove BG',
    vendor: 'Recraft',
    assetType: 'image',
    capability: 'remove-background',
    imageInputMode: 'image',
    aspectRatioMode: null,
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 1,
    description: 'Recraft background removal',
  },

  // --- Topaz ---
  {
    id: 'kie-topaz-upscale',
    modelName: 'topaz/image-upscale',
    displayName: 'Topaz Image Upscale',
    vendor: 'Topaz',
    assetType: 'image',
    capability: 'upscale',
    imageInputMode: 'image_url',
    aspectRatioMode: null,
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 1,
    description: 'Topaz AI image upscaling',
  },
] as const;

// ---------------------------------------------------------------------------
// Video Models
// ---------------------------------------------------------------------------

export const KIE_MARKETPLACE_VIDEO_MODELS: readonly KieMarketplaceModel[] = [
  // --- Kling ---
  {
    id: 'kie-kling-2.6-t2v',
    modelName: 'kling-2.6/text-to-video',
    displayName: 'Kling 2.6 T2V',
    vendor: 'Kling',
    assetType: 'video',
    capability: 'text-to-video',
    imageInputMode: null,
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: true,
    supportsNegativePrompt: false,
    supportsAudio: true,
    maxImageInputs: 0,
    description: 'Kling 2.6 text-to-video',
    validDurations: ['5', '10'],
    defaultDuration: '5',
    extraRequiredFields: { sound: true, multi_shots: false },
  },
  {
    id: 'kie-kling-2.6-i2v',
    modelName: 'kling-2.6/image-to-video',
    displayName: 'Kling 2.6 I2V',
    vendor: 'Kling',
    assetType: 'video',
    capability: 'image-to-video',
    imageInputMode: 'image_urls',
    aspectRatioMode: null,
    supportsDuration: true,
    supportsNegativePrompt: false,
    supportsAudio: true,
    maxImageInputs: 1,
    description: 'Kling 2.6 image-to-video',
    validDurations: ['5', '10'],
    defaultDuration: '5',
    extraRequiredFields: { sound: true },
  },
  {
    id: 'kie-kling-3.0',
    modelName: 'kling-3.0/video',
    displayName: 'Kling 3.0',
    vendor: 'Kling',
    assetType: 'video',
    capability: 'text-to-video',
    imageInputMode: 'image_urls',
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: true,
    supportsNegativePrompt: false,
    supportsAudio: true,
    maxImageInputs: 1,
    description: 'Latest Kling 3.0 video generation',
    validDurations: ['5', '10'],
    defaultDuration: '5',
    extraRequiredFields: { mode: 'pro', sound: true, multi_shots: false },
  },

  // --- Sora2 ---
  {
    id: 'kie-sora2-t2v',
    modelName: 'sora-2-text-to-video',
    displayName: 'Sora 2 T2V',
    vendor: 'OpenAI',
    assetType: 'video',
    capability: 'text-to-video',
    imageInputMode: null,
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 0,
    description: 'OpenAI Sora 2 text-to-video',
    supportsAudio: true,
    aspectRatioMap: { portrait: 'portrait', landscape: 'landscape' },
    extraRequiredFields: { n_frames: '10', remove_watermark: true, upload_method: 's3' },
  },
  {
    id: 'kie-sora2-i2v',
    modelName: 'sora-2-image-to-video',
    displayName: 'Sora 2 I2V',
    vendor: 'OpenAI',
    assetType: 'video',
    capability: 'image-to-video',
    imageInputMode: 'input_urls',
    aspectRatioMode: 'aspect_ratio',
    aspectRatioMap: { portrait: 'portrait', landscape: 'landscape' },
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 1,
    description: 'OpenAI Sora 2 image-to-video',
    supportsAudio: true,
    extraRequiredFields: { n_frames: '10', remove_watermark: true, upload_method: 's3' },
  },
  {
    id: 'kie-sora2-pro-t2v',
    modelName: 'sora-2-pro-text-to-video',
    displayName: 'Sora 2 Pro T2V',
    vendor: 'OpenAI',
    assetType: 'video',
    capability: 'text-to-video',
    imageInputMode: null,
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 0,
    description: 'OpenAI Sora 2 Pro text-to-video',
    supportsAudio: true,
    aspectRatioMap: { portrait: 'portrait', landscape: 'landscape' },
    extraRequiredFields: { n_frames: '10', size: 'high', remove_watermark: true, upload_method: 's3' },
  },
  {
    id: 'kie-sora2-pro-i2v',
    modelName: 'sora-2-pro-image-to-video',
    displayName: 'Sora 2 Pro I2V',
    vendor: 'OpenAI',
    assetType: 'video',
    capability: 'image-to-video',
    imageInputMode: 'input_urls',
    aspectRatioMode: 'aspect_ratio',
    aspectRatioMap: { portrait: 'portrait', landscape: 'landscape' },
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 1,
    description: 'OpenAI Sora 2 Pro image-to-video',
    supportsAudio: true,
    extraRequiredFields: { n_frames: '10', size: 'high', remove_watermark: true, upload_method: 's3' },
  },

  // --- Bytedance ---
  {
    id: 'kie-bytedance-seedance-1.5-pro',
    modelName: 'bytedance/seedance-1.5-pro',
    displayName: 'Seedance 1.5 Pro',
    vendor: 'Bytedance',
    assetType: 'video',
    capability: 'text-to-video',
    imageInputMode: 'input_urls',
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: true,
    supportsNegativePrompt: false,
    supportsAudio: true,
    maxImageInputs: 2,
    description: 'Bytedance Seedance 1.5 Pro (text or image to video)',
    validDurations: ['4', '8', '12'],
    defaultDuration: '8',
    extraRequiredFields: { resolution: '720p', generate_audio: true },
  },
  {
    id: 'kie-bytedance-v1-pro-t2v',
    modelName: 'bytedance/v1-pro-text-to-video',
    displayName: 'Bytedance V1 Pro T2V',
    vendor: 'Bytedance',
    assetType: 'video',
    capability: 'text-to-video',
    imageInputMode: null,
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: true,
    supportsNegativePrompt: false,
    maxImageInputs: 0,
    description: 'Bytedance V1 Pro text-to-video',
    supportsAudio: false,
    validDurations: ['5', '10'],
    defaultDuration: '5',
    extraRequiredFields: { resolution: '720p' },
  },
  {
    id: 'kie-bytedance-v1-pro-i2v',
    modelName: 'bytedance/v1-pro-image-to-video',
    displayName: 'Bytedance V1 Pro I2V',
    vendor: 'Bytedance',
    assetType: 'video',
    capability: 'image-to-video',
    imageInputMode: 'image_url',
    aspectRatioMode: null,
    supportsDuration: true,
    supportsNegativePrompt: false,
    maxImageInputs: 1,
    description: 'Bytedance V1 Pro image-to-video',
    supportsAudio: false,
  },

  // --- Hailuo ---
  {
    id: 'kie-hailuo-02-t2v-pro',
    modelName: 'hailuo/02-text-to-video-pro',
    displayName: 'Hailuo 02 T2V Pro',
    vendor: 'Hailuo',
    assetType: 'video',
    capability: 'text-to-video',
    imageInputMode: null,
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 0,
    description: 'Hailuo 02 Pro text-to-video',
    supportsAudio: false,
  },
  {
    id: 'kie-hailuo-02-i2v-pro',
    modelName: 'hailuo/02-image-to-video-pro',
    displayName: 'Hailuo 02 I2V Pro',
    vendor: 'Hailuo',
    assetType: 'video',
    capability: 'image-to-video',
    imageInputMode: 'input_urls',
    aspectRatioMode: null,
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 1,
    description: 'Hailuo 02 Pro image-to-video',
    supportsAudio: false,
  },
  {
    id: 'kie-hailuo-2.3-i2v-pro',
    modelName: 'hailuo/2-3-image-to-video-pro',
    displayName: 'Hailuo 2.3 I2V Pro',
    vendor: 'Hailuo',
    assetType: 'video',
    capability: 'image-to-video',
    imageInputMode: 'input_urls',
    aspectRatioMode: null,
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 1,
    description: 'Latest Hailuo 2.3 Pro image-to-video',
    supportsAudio: false,
  },

  // --- Wan ---
  {
    id: 'kie-wan-2.6-t2v',
    modelName: 'wan/2-6-text-to-video',
    displayName: 'Wan 2.6 T2V',
    vendor: 'Wan',
    assetType: 'video',
    capability: 'text-to-video',
    imageInputMode: null,
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 0,
    description: 'Wan 2.6 text-to-video',
    supportsAudio: true,
  },
  {
    id: 'kie-wan-2.6-i2v',
    modelName: 'wan/2-6-image-to-video',
    displayName: 'Wan 2.6 I2V',
    vendor: 'Wan',
    assetType: 'video',
    capability: 'image-to-video',
    imageInputMode: 'input_urls',
    aspectRatioMode: null,
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 1,
    description: 'Wan 2.6 image-to-video',
    supportsAudio: true,
  },

  // --- Grok Imagine Video ---
  {
    id: 'kie-grok-imagine-t2v',
    modelName: 'grok-imagine/text-to-video',
    displayName: 'Grok Imagine T2V',
    vendor: 'xAI',
    assetType: 'video',
    capability: 'text-to-video',
    imageInputMode: null,
    aspectRatioMode: 'aspect_ratio',
    supportsDuration: true,
    supportsNegativePrompt: false,
    maxImageInputs: 0,
    description: 'xAI Grok text-to-video',
    supportsAudio: true,
    validDurations: ['6', '10'],
    defaultDuration: '6',
    extraRequiredFields: { mode: 'normal', resolution: '720p' },
  },
  {
    id: 'kie-grok-imagine-i2v',
    modelName: 'grok-imagine/image-to-video',
    displayName: 'Grok Imagine I2V',
    vendor: 'xAI',
    assetType: 'video',
    capability: 'image-to-video',
    imageInputMode: 'image_urls',
    aspectRatioMode: null,
    supportsDuration: true,
    supportsNegativePrompt: false,
    maxImageInputs: 1,
    description: 'xAI Grok image-to-video',
    supportsAudio: true,
  },
] as const;

// ---------------------------------------------------------------------------
// Audio Models
// ---------------------------------------------------------------------------

export const KIE_MARKETPLACE_AUDIO_MODELS: readonly KieMarketplaceModel[] = [
  {
    id: 'kie-elevenlabs-tts-turbo',
    modelName: 'elevenlabs/text-to-speech-turbo-2-5',
    displayName: 'ElevenLabs TTS Turbo 2.5',
    vendor: 'ElevenLabs',
    assetType: 'audio',
    capability: 'text-to-speech',
    imageInputMode: null,
    aspectRatioMode: null,
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 0,
    description: 'Fast text-to-speech',
  },
  {
    id: 'kie-elevenlabs-tts-multilingual',
    modelName: 'elevenlabs/text-to-speech-multilingual-v2',
    displayName: 'ElevenLabs Multilingual V2',
    vendor: 'ElevenLabs',
    assetType: 'audio',
    capability: 'text-to-speech',
    imageInputMode: null,
    aspectRatioMode: null,
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 0,
    description: 'Multilingual text-to-speech',
  },
  {
    id: 'kie-elevenlabs-dialogue',
    modelName: 'elevenlabs/text-to-dialogue-v3',
    displayName: 'ElevenLabs Dialogue V3',
    vendor: 'ElevenLabs',
    assetType: 'audio',
    capability: 'text-to-dialogue',
    imageInputMode: null,
    aspectRatioMode: null,
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 0,
    description: 'Multi-speaker dialogue generation',
  },
  {
    id: 'kie-elevenlabs-sfx',
    modelName: 'elevenlabs/sound-effect-v2',
    displayName: 'ElevenLabs Sound Effects V2',
    vendor: 'ElevenLabs',
    assetType: 'audio',
    capability: 'sound-effect',
    imageInputMode: null,
    aspectRatioMode: null,
    supportsDuration: false,
    supportsNegativePrompt: false,
    maxImageInputs: 0,
    description: 'AI sound effect generation',
  },
] as const;

// ---------------------------------------------------------------------------
// Combined registry + lookup helpers
// ---------------------------------------------------------------------------

/** All marketplace models in a single flat array */
export const KIE_MARKETPLACE_MODELS: readonly KieMarketplaceModel[] = [
  ...KIE_MARKETPLACE_IMAGE_MODELS,
  ...KIE_MARKETPLACE_VIDEO_MODELS,
  ...KIE_MARKETPLACE_AUDIO_MODELS,
];

/** Fast lookup by internal model ID */
const _byId = new Map<string, KieMarketplaceModel>(
  KIE_MARKETPLACE_MODELS.map((m) => [m.id, m]),
);

/** Fast lookup by API model name */
const _byModelName = new Map<string, KieMarketplaceModel>(
  KIE_MARKETPLACE_MODELS.map((m) => [m.modelName, m]),
);

/** Get a marketplace model by our internal ID. Returns undefined if not found. */
export function getKieMarketplaceModelById(id: string): KieMarketplaceModel | undefined {
  return _byId.get(id);
}

/** Get a marketplace model by its API model name. Returns undefined if not found. */
export function getKieMarketplaceModelByName(modelName: string): KieMarketplaceModel | undefined {
  return _byModelName.get(modelName);
}

/** Check if a model ID belongs to a known marketplace model */
export function isKieMarketplaceModel(id: string): boolean {
  return _byId.has(id);
}

/** Get all marketplace models filtered by asset type */
export function getKieMarketplaceModelsByType(assetType: KieAssetType): readonly KieMarketplaceModel[] {
  return KIE_MARKETPLACE_MODELS.filter((m) => m.assetType === assetType);
}

/** Get all unique vendors */
export function getKieMarketplaceVendors(): string[] {
  return Array.from(new Set(KIE_MARKETPLACE_MODELS.map((m) => m.vendor)));
}
