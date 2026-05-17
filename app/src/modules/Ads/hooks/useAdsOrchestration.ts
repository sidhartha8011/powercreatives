/**
 * USE ADS ORCHESTRATION HOOK
 *
 * Central orchestrator for the Ads module. Manages the full pipeline:
 *   1. Text phase:  POST /copy/generate (SSE) → TextSlot[]
 *   2. Image phase: POST /image/generate × N (parallel) → MediaSlot[]
 *   3. Compose:     Round-robin text[i] + image[i] → AdCreative[]
 *   4. Video phase: (fishbone — disabled, easy to activate)
 *
 * Key design decisions:
 * - Does NOT import useCopyGeneration or useImageGeneration hooks.
 *   Instead calls the same REST endpoints directly. This prevents
 *   tight coupling and state conflicts between modules.
 * - Uses Promise.allSettled for image generation so one model failure
 *   doesn't block results from other models.
 * - SSE parsing is extracted to utils/sseTextParser.ts for testability.
 */

import { useState, useCallback, useRef } from 'react';
import { trpc } from '@/lib/trpc';
import { toast } from 'sonner';
import type {
  AdCreative,
  MediaSlot,
  TextSlot,
  AdsPhase,
  AdsProgress,
} from '../types';
import { ADS_DEFAULTS } from '../adsConfig';
import { parseTextSSEStream } from '../utils/sseTextParser';
import type { ContextData } from '@/components/shared/ContextPanel';

// ============================================================================
// Types
// ============================================================================

export interface AdsGenerateParams {
  /** Creative brief / prompt */
  brief: string;
  /** Text model ID for copy generation */
  textModelId: string;
  /** Image model IDs for image generation */
  imageModelIds: string[];
  /** Video model ID (empty = skip video phase) */
  videoModelId: string;
  /** Number of variations per image model */
  imageVariations: number;
  /** Business context from ContextPanel + EnhancedBrandSection */
  formValues: Record<string, string | number | undefined>;
  /** Brand/theme context */
  contextData: ContextData;
}

export interface UseAdsOrchestrationReturn {
  /** Current phase of the pipeline */
  phase: AdsPhase;
  /** Progress within current phase */
  progress: AdsProgress | null;
  /** Assembled ad creatives (the final output) */
  creatives: AdCreative[];
  /** Raw text results (before composition) */
  textSlots: TextSlot[];
  /** Raw media results (before composition) */
  mediaSlots: MediaSlot[];
  /** Whether generation is active */
  isGenerating: boolean;
  /** Last error message */
  error: string | null;
  /** Clear error */
  clearError: () => void;
  /** Start the full generation pipeline */
  generate: (params: AdsGenerateParams) => Promise<void>;
  /** Update text fields on a creative (inline editing) */
  updateCreativeText: (
    creativeId: string,
    updates: { headline?: string; body?: string; cta?: string },
  ) => void;
}

// ============================================================================
// Composition — round-robin text + image → AdCreative
// ============================================================================

/**
 * Combine text and media slots into AdCreative[] using round-robin.
 * If there are more texts than images, the last image is reused.
 * If there are more images than texts, excess images get empty text.
 */
function composeCreatives(texts: TextSlot[], medias: MediaSlot[]): AdCreative[] {
  const maxLen = Math.max(texts.length, medias.length);
  const creatives: AdCreative[] = [];

  for (let i = 0; i < maxLen; i++) {
    const text = texts[i] ?? texts[texts.length - 1];
    const media = medias[i] ?? medias[medias.length - 1];

    // Skip if we have neither text nor media
    if (!text && !media) continue;

    creatives.push({
      id: `ad-${i}-${Date.now()}`,
      media: media ?? {
        id: `placeholder-${i}`,
        type: 'image',
        status: 'pending',
        modelName: '',
        modelId: '',
        provider: '',
        prompt: '',
      },
      text: text ?? {
        id: `empty-text-${i}`,
        headline: '',
        body: '',
        modelUsed: '',
      },
      createdAt: new Date(),
    });
  }

  return creatives;
}

/**
 * Build brand context object matching what Copy/Image backends expect.
 * Pure function — no React dependencies.
 */
function buildBrandContext(
  formValues: Record<string, string | number | undefined>,
  contextData: ContextData,
) {
  return {
    brandName: formValues.business_name,
    brandSummary: formValues.business_summary,
    niche: formValues.niche,
    location: formValues.location,
    phone: formValues.phone,
    website: formValues.website,
    language: formValues.language,
    seasonEvent: contextData.seasonEvent,
    campaignTheme: contextData.campaignTheme,
    url: contextData.url,
  };
}

// ============================================================================
// Hook
// ============================================================================

export function useAdsOrchestration(): UseAdsOrchestrationReturn {
  // ── State ──
  const [phase, setPhase] = useState<AdsPhase>('idle');
  const [progress, setProgress] = useState<AdsProgress | null>(null);
  const [creatives, setCreatives] = useState<AdCreative[]>([]);
  const [textSlots, setTextSlots] = useState<TextSlot[]>([]);
  const [mediaSlots, setMediaSlots] = useState<MediaSlot[]>([]);
  const [error, setError] = useState<string | null>(null);

  // ── tRPC mutations for image generation ──
  const generateImageMutation = trpc.image.generate.useMutation();

  // ── Abort controller ref for cancellation ──
  const abortRef = useRef<AbortController | null>(null);

  const isGenerating = phase !== 'idle' && phase !== 'complete' && phase !== 'error';

  // ── Clear error ──
  const clearError = useCallback(() => setError(null), []);

  // ══════════════════════════════════════════════════════════════════
  // PHASE 1: Text Generation (SSE stream from /copy/generate)
  // ══════════════════════════════════════════════════════════════════

  async function runTextPhase(
    params: AdsGenerateParams,
    signal: AbortSignal,
  ): Promise<TextSlot[]> {
    setPhase('text_phase');
    setProgress({ phase: 'text_phase', current: 0, total: 0, label: 'Generating ad copy...' });

    const config = window.pcmConfig ?? { restUrl: '/wp-json/pcm/v1/', nonce: '' };
    const url = `${config.restUrl}copy/generate`;

    // Build payload matching Copy controller's expected input
    const input = {
      copyTypes: [ADS_DEFAULTS.copyType],
      audiences: { mode: 'auto' as const, count: ADS_DEFAULTS.audienceCount },
      angles: { mode: 'auto' as const, count: ADS_DEFAULTS.angleCount },
      modelId: params.textModelId,
      formValues: {
        ...params.formValues,
        // Inject the brief as the business description if not already set
        business_summary: params.formValues.business_summary || params.brief,
      },
      scope: 'all_types' as const,
    };

    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': config.nonce,
      },
      body: JSON.stringify(input),
      signal,
    });

    if (!response.ok) {
      throw new Error(`Copy generation failed: ${response.status} ${response.statusText}`);
    }

    // Delegate to extracted SSE parser utility
    return parseTextSSEStream(response, {
      onProgress: setProgress,
      onTextSlot: (_slot, allSlots) => setTextSlots(allSlots),
    });
  }

  // ══════════════════════════════════════════════════════════════════
  // PHASE 2: Image Generation (parallel mutations)
  // ══════════════════════════════════════════════════════════════════

  async function runImagePhase(
    params: AdsGenerateParams,
    textCount: number,
    signal: AbortSignal,
  ): Promise<MediaSlot[]> {
    setPhase('image_phase');

    const { imageModelIds, imageVariations, brief, formValues, contextData } = params;
    const brandCtx = buildBrandContext(formValues, contextData);

    // Calculate total images: at least as many as text slots
    const totalImages = Math.max(textCount, imageModelIds.length * imageVariations);
    let completed = 0;

    setProgress({
      phase: 'image_phase',
      current: 0,
      total: totalImages,
      label: 'Generating images...',
    });

    // Use functional updater pattern to avoid race conditions
    // between parallel model tasks sharing mutable state
    const completedSlots: MediaSlot[] = [];

    const modelTasks = imageModelIds.map(async (modelId) => {
      for (let v = 0; v < imageVariations; v++) {
        if (signal.aborted) return;

        const placeholderId = crypto.randomUUID();
        const placeholder: MediaSlot = {
          id: placeholderId,
          type: 'image',
          status: 'processing',
          modelName: modelId,
          modelId,
          provider: '',  // Backend resolves from modelId via registry
          prompt: brief,
        };

        // Use functional updater to safely add placeholder
        setMediaSlots((prev) => [...prev, placeholder]);

        try {
          const result = await generateImageMutation.mutateAsync({
            prompt: brief,
            model: modelId,
            provider: '', // Backend resolves from modelId via registry
            brandContext: brandCtx,
          });

          // Update placeholder with completed result using functional updater
          const completedSlot: MediaSlot = {
            ...placeholder,
            status: 'complete',
            url: (result as any).url,
            thumbnailUrl: (result as any).url,
            prompt: (result as any).prompt ?? brief,
            dbAssetId: (result as any).id,
          };

          completedSlots.push(completedSlot);

          setMediaSlots((prev) =>
            prev.map((m) => m.id === placeholderId ? completedSlot : m),
          );
        } catch (err) {
          const msg = err instanceof Error ? err.message : 'Image generation failed';
          setMediaSlots((prev) =>
            prev.map((m) =>
              m.id === placeholderId
                ? { ...m, status: 'failed' as const, errorMessage: msg }
                : m,
            ),
          );
        }

        completed++;
        setProgress({
          phase: 'image_phase',
          current: completed,
          total: totalImages,
          label: `Images: ${completed}/${totalImages}`,
        });
      }
    });

    await Promise.allSettled(modelTasks);
    return completedSlots;
  }

  // ══════════════════════════════════════════════════════════════════
  // PHASE 3: Compose (round-robin text + image → AdCreative)
  // ══════════════════════════════════════════════════════════════════

  function runComposePhase(texts: TextSlot[], medias: MediaSlot[]): AdCreative[] {
    setPhase('compose_phase');
    setProgress({ phase: 'compose_phase', current: 0, total: 1, label: 'Assembling ad creatives...' });

    const composed = composeCreatives(texts, medias);

    setProgress({ phase: 'compose_phase', current: 1, total: 1, label: `${composed.length} ads assembled` });
    return composed;
  }

  // ══════════════════════════════════════════════════════════════════
  // PHASE 4: Video (fishbone — disabled, ready to activate)
  // ══════════════════════════════════════════════════════════════════

  // async function runVideoPhase(
  //   creatives: AdCreative[],
  //   videoModelId: string,
  //   signal: AbortSignal,
  // ): Promise<AdCreative[]> {
  //   setPhase('video_phase');
  //   // For each creative, generate a video from the image + brief
  //   // using POST /video/generate, then update media.type to 'video'
  //   return creatives;
  // }

  // ══════════════════════════════════════════════════════════════════
  // Main Generate — orchestrates all phases
  // ══════════════════════════════════════════════════════════════════

  const generate = useCallback(async (params: AdsGenerateParams) => {
    // Validation
    if (!params.brief.trim()) {
      toast.error('Please enter a creative brief');
      return;
    }
    if (!params.textModelId) {
      toast.error('Please select a text model');
      return;
    }
    if (params.imageModelIds.length === 0) {
      toast.error('Please select at least one image model');
      return;
    }

    // Reset state
    setError(null);
    setTextSlots([]);
    setMediaSlots([]);
    setCreatives([]);

    // Abort any previous generation
    abortRef.current?.abort();
    const controller = new AbortController();
    abortRef.current = controller;

    try {
      // Phase 1: Generate text
      const texts = await runTextPhase(params, controller.signal);

      if (texts.length === 0) {
        throw new Error('No copy was generated. Check your text model and try again.');
      }

      // Phase 2: Generate images
      const images = await runImagePhase(params, texts.length, controller.signal);

      if (images.length === 0) {
        throw new Error('No images were generated. Check your image models and try again.');
      }

      // Phase 3: Compose
      const composed = runComposePhase(texts, images);
      setCreatives(composed);

      // Phase 4: Video (fishbone — skip for now)
      // if (params.videoModelId) {
      //   composed = await runVideoPhase(composed, params.videoModelId, controller.signal);
      //   setCreatives(composed);
      // }

      setPhase('complete');
      toast.success(`${composed.length} ad creatives generated!`);
    } catch (err) {
      if (controller.signal.aborted) return; // User cancelled

      const message = err instanceof Error ? err.message : 'Generation failed';
      setError(message);
      setPhase('error');
      toast.error(message);
      console.error('[AdsOrchestration] Generate failed:', err);
    }
  }, [generateImageMutation]);

  // ── Update creative text (inline editing) ──
  const updateCreativeText = useCallback(
    (creativeId: string, updates: { headline?: string; body?: string; cta?: string }) => {
      setCreatives((prev) =>
        prev.map((c) =>
          c.id === creativeId
            ? {
                ...c,
                text: {
                  ...c.text,
                  headline: updates.headline ?? c.text.headline,
                  body: updates.body ?? c.text.body,
                  cta: updates.cta !== undefined ? updates.cta : c.text.cta,
                },
              }
            : c,
        ),
      );
    },
    [],
  );

  return {
    phase,
    progress,
    creatives,
    textSlots,
    mediaSlots,
    isGenerating,
    error,
    clearError,
    generate,
    updateCreativeText,
  };
}
