/**
 * ADS MODULE — Configuration constants
 *
 * Config-driven defaults for the Ad Composer.
 * Follows the same pattern as Image module's imageConfig.ts.
 */

/** Default production parameters for the Ads module */
export const ADS_DEFAULTS = {
  /** Number of image variations per model */
  imageVariations: 1,
  /** Default copy type to request from the Copy backend */
  copyType: 'social_ads' as const,
  /** Number of audiences (auto mode) — 1 keeps it simple */
  audienceCount: 1,
  /** Number of angles (auto mode) — 1 per audience */
  angleCount: 1,
} as const;

/** LocalStorage keys for persisting user preferences */
export const ADS_STORAGE_KEYS = {
  lastTextModel: 'pcm-ads-last-text-model',
  lastImageModels: 'pcm-ads-last-image-models',
  lastVideoModel: 'pcm-ads-last-video-model',
} as const;
