/**
 * IMAGE MODULE — Configuration
 *
 * Single source of truth for Image module labels, defaults, and section config.
 * No hardcoded values in index.tsx — everything is driven from here.
 */

import type { DetailLevelValue } from "./types";

// ─── Detail Level Options ───────────────────────────────
export const DETAIL_LEVELS = [
  { value: 1, label: "Brief", description: "Short, punchy prompts" },
  { value: 2, label: "Moderate", description: "Balanced detail" },
  { value: 3, label: "Detailed", description: "Rich, descriptive prompts" },
] as const;

export const DEFAULT_DETAIL_LEVEL: DetailLevelValue = 2;
export const DEFAULT_SUGGESTION_COUNT: number = 3;

// ─── Suggestion Pill Config ─────────────────────────────
export const SUGGESTION_CONFIG = {
  /** Min characters in product brief before suggestions can be generated */
  minInputLength: 3,
  /** Max suggestions that can be requested */
  maxCount: 10,
  /** Min suggestions */
  minCount: 1,
} as const;

// ─── Production Defaults ────────────────────────────────
export const PRODUCTION_DEFAULTS: {
  numVersions: number;
  variationsPerModel: number;
  autoOptimizeBrief: boolean;
} = {
  numVersions: 3,
  variationsPerModel: 1,
  autoOptimizeBrief: true,
};

// ─── LocalStorage Keys ──────────────────────────────────
export const STORAGE_KEYS = {
  assets: "creative-machine-image-assets",
  versions: "creative-machine-image-versions",
} as const;

// ─── Global Style Config ──────────────────────────────────
export const STYLE_PRESETS = [
  { id: 'auto', label: 'Auto (Let AI decide)', prefix: '', suffix: '', negativePrompt: '' },
  { id: 'photographic', label: 'Photographic', prefix: 'A highly detailed photograph of', suffix: 'cinematic lighting, photorealistic, 8k resolution, highly detailed', negativePrompt: 'cartoon, illustration, drawing, painting, 3d render' },
  { id: 'digital-art', label: 'Digital Art', prefix: 'A beautiful digital artwork of', suffix: 'trending on artstation, masterpiece, vibrant colors, highly detailed illustration', negativePrompt: 'photo, realistic, bad anatomy' },
  { id: '3d-render', label: '3D Render', prefix: 'A 3D render of', suffix: 'octane render, unreal engine 5, ray tracing, highly detailed 3d model', negativePrompt: 'photo, 2d, illustration, drawing' }
] as const;

export const ASPECT_RATIOS = [
  { id: '1:1', label: 'Square (1:1)', width: 1024, height: 1024 },
  { id: '16:9', label: 'Landscape (16:9)', width: 1792, height: 1024 },
  { id: '4:3', label: 'Classic (4:3)', width: 1024, height: 768 },
  { id: '9:16', label: 'Portrait (9:16)', width: 1024, height: 1792 }
] as const;
