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
  MediaSlot,
  TextSlot,
  AdsPhase,
  AdsProgress,
} from '../types';
import { ADS_DEFAULTS } from '../adsConfig';
import { parseTextSSEStream } from '../utils/sseTextParser';
import type { ContextData } from '@/components/shared/ContextPanel';
import type { SessionReferenceImage } from '@shared/referenceImageIntents';
import { resolveGenerationPayload } from '@/lib/resolveGenerationPayload';
import { useImageModelsForGeneration } from '@/hooks/useModelsForGeneration';
import type { ListItem, AngleItem } from '@/components/shared';
import { useSettings } from '@/contexts/AppContext';
import { DEFAULT_BRAND_TOGGLES } from '@/components/shared/ContextPanel';

// ============================================================================
// Types
// ============================================================================

/** Audiences/Angles generation config — matches Copy module's pattern */
interface GenerationDimension {
  mode: 'auto' | 'manual';
  items?: { id: string; name: string }[];
  count: number;
}

export interface AdsGenerateParams {
  /** Creative brief / prompt */
  brief: string;
  /** Text model ID for copy generation */
  textModelId: string;
  /** Image model IDs for image generation */
  imageModelIds: string[];
  /** Video model ID (empty = skip video phase) */
  videoModelId: string;
  /** Which copy types to generate (e.g. ['social_ads', 'social_organic']) */
  copyTypes: string[];
  /** Audiences generation config (auto/manual + items + count) */
  audiences: GenerationDimension;
  /** Angles generation config (auto/manual + items + count) */
  angles: GenerationDimension;
  /** Number of variations per image model */
  imageVariations: number;
  /** Business context from ContextPanel + EnhancedBrandSection */
  formValues: Record<string, string | number | undefined>;
  /** Brand/theme context */
  contextData: ContextData;
  /** User-uploaded or selected reference images */
  sessionReferenceImages: SessionReferenceImage[];
  /** AI Enhance toggle */
  autoOptimizeBrief: boolean;
  /** Angles count slider */
  numVersions: number;
}

export interface UseAdsOrchestrationReturn {
  /** Current phase of the pipeline */
  phase: AdsPhase;
  /** Progress within current phase */
  progress: AdsProgress | null;
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
  /** Cancel ongoing generation */
  cancel: () => void;
  /** Start the full generation pipeline */
  generate: (params: AdsGenerateParams) => Promise<void>;
  /** Update text fields on a text slot (inline editing) */
  updateTextSlot: (
    slotId: string,
    updates: { headline?: string; body?: string; cta?: string; description?: string; hashtags?: string[] },
  ) => void;
  /** Regenerate a text slot (stub) */
  regenerateTextSlot: (slotId: string, instruction?: string) => void;
  /** Suggest audiences based on brief */
  generateAudiences: (params: {
    brief: string;
    textModelId: string;
    count: number;
    formValues: Record<string, string | number | undefined>;
  }) => Promise<ListItem[]>;
  /** Suggest angles based on brief and audiences */
  generateAngles: (params: {
    brief: string;
    textModelId: string;
    count: number;
    formValues: Record<string, string | number | undefined>;
    audiences: ListItem[];
  }) => Promise<AngleItem[]>;
}

// (Removed composition logic)
// buildBrandContext has been replaced by resolveGenerationPayload in @/lib/resolveGenerationPayload.ts

// ============================================================================
// Hook
// ============================================================================

export function useAdsOrchestration(): UseAdsOrchestrationReturn {
  // ── State ──
  const [phase, setPhase] = useState<AdsPhase>('idle');
  const [progress, setProgress] = useState<AdsProgress | null>(null);
  const [textSlots, setTextSlots] = useState<TextSlot[]>([]);
  const [mediaSlots, setMediaSlots] = useState<MediaSlot[]>([]);
  const [error, setError] = useState<string | null>(null);

  // ── Image model registry — needed to resolve provider from modelId ──
  const { imageModels } = useImageModelsForGeneration();

  // ── tRPC mutations for image generation ──
  const generateImageMutation = trpc.image.generate.useMutation();
  // Async pair for Kie.ai models — the blocking /image/generate poll loop
  // gets killed by shared hosts' timeouts on slow models (same fix as the
  // Image module's useImageGeneration).
  const createImageTaskMutation = trpc.image.createTask.useMutation();
  const imageTaskResultMutation = trpc.image.taskResult.useMutation();
  const optimizeBriefMutation = trpc.image.optimizeBrief.useMutation();
  const generateConceptsMutation = trpc.image.generateConcepts.useMutation();
  const suggestMutation = trpc.copy.suggest.useMutation();

  // ── Settings ──
  const { settings } = useSettings();

  // ── Abort controller ref for cancellation ──
  const abortRef = useRef<AbortController | null>(null);

  const isGenerating = phase !== 'idle' && phase !== 'complete' && phase !== 'error';

  // ── Clear error ──
  const clearError = useCallback(() => setError(null), []);

  // ── Cancel ongoing generation ──
  const cancel = useCallback(() => {
    abortRef.current?.abort();
    setPhase('idle');
    setProgress(null);
    toast.info('Generation cancelled');
  }, []);

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

    // Brand toggles — same source the image phase uses (line ~295). Controls
    // which brand signals are included, matching the standalone Copy module.
    const toggles = { ...DEFAULT_BRAND_TOGGLES, ...params.contextData.brandToggles };

    // Toggle-aware form values — mirror Copy: drop the business summary when the
    // user has toggled it off, and inject the brief under the key the Copy
    // service reads (`creativeBrief`).
    const finalFormValues: Record<string, string | number | undefined> = {
      ...params.formValues,
      creativeBrief: params.brief,
    };
    if (!toggles.useSummary) {
      finalFormValues.business_summary = '';
    }

    // Build the payload to MATCH the standalone Copy module's /copy/generate
    // request, so Ads gets the same audience market-research grounding (the real
    // driver of brand-specific copy). Only `module: 'ads'` differs (set below).
    const input = {
      copyTypes: params.copyTypes.length > 0 ? params.copyTypes : [ADS_DEFAULTS.copyType],
      audiences: params.audiences.mode === 'manual' && params.audiences.items?.length
        ? { mode: 'manual' as const, items: params.audiences.items }
        : { mode: 'auto' as const, count: params.audiences.count },
      angles: params.angles.mode === 'manual' && params.angles.items?.length
        ? { mode: 'manual' as const, items: params.angles.items }
        : { mode: 'auto' as const, count: params.angles.count },
      modelId: params.textModelId,
      formValues: finalFormValues,
      // Audience market research — standalone Copy sends these; Ads previously
      // omitted them, so its audiences (and copy) were generated without
      // grounding and read generically. Default on, matching the Copy tab.
      useResearch: true,
      researchModelId: settings.defaultCopyResearchModel ?? '',
      scope: 'all_types' as const,
    };

    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': config.nonce,
      },
      body: JSON.stringify({
        ...input,
        // Tell the Copy service to resolve prompts from the 'ads' namespace.
        // This enables independent prompt customization in Settings → Ads tab
        // without affecting the Copy module's prompts.
        module: 'ads',
      }),
      signal,
    });

    if (!response.ok) {
      let errorMsg = `${response.status} ${response.statusText}`;
      try {
        const errData = await response.json();
        if (errData && errData.message) {
          errorMsg = errData.message;
        }
      } catch (e) {
        // Ignore JSON parse errors and fallback to statusText
      }
      throw new Error(errorMsg);
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
    signal: AbortSignal,
  ): Promise<MediaSlot[]> {
    setPhase('image_phase');

    const { imageModelIds, imageVariations, brief, formValues, contextData, sessionReferenceImages } = params;
    
    const payloadParams = resolveGenerationPayload({
      contextData,
      formValues,
      sessionReferenceImages,
    });
    
    const brandCtx = payloadParams.brandContext;
    const refUrls = payloadParams.inputUrls;
    const refIntents = payloadParams.referenceImageIntents;

    // 0. Optionally optimize the brief via LLM
    let briefToUse = brief;
    if (params.autoOptimizeBrief) {
      setProgress((prev) => ({ ...prev, phase: 'image_phase', current: 0, total: 100, label: 'Optimizing creative brief...' }));
      try {
        const optimized = await optimizeBriefMutation.mutateAsync({
          brief: brief,
          modelId: settings?.defaultImageTextModel || '',
          brandContext: brandCtx,
        });
        briefToUse = (optimized as any).optimizedBrief ?? brief;
      } catch (err) {
        console.warn('[AdsOrchestration] Brief optimization failed, using original.', err);
      }
    }

    // 1. Build creative angles (concepts)
    interface Concept {
      name: string;
      description: string;
    }
    
    let conceptsList: Concept[] = [{
      name: 'Original',
      description: briefToUse,
    }];

    if (params.numVersions > 1) {
      setProgress((prev) => ({ ...prev, phase: 'image_phase', current: 0, total: 100, label: 'AI is conceptualizing creative angles...' }));
      try {
        const toggles = { ...DEFAULT_BRAND_TOGGLES, ...contextData.brandToggles };
        const conceptsResult = await generateConceptsMutation.mutateAsync({
          prompt: briefToUse,
          count: params.numVersions - 1,
          modelId: settings?.defaultImageTextModel || '',
          brandContext: brandCtx,
          referenceImages: toggles.useReferenceSubjects
            ? sessionReferenceImages.map((img) => ({ url: img.url, intent: img.intent }))
            : [],
        });
        const generated = (conceptsResult as any).concepts ?? [];
        conceptsList = [
          { name: 'Original', description: briefToUse },
          ...generated.map((c: any) => ({ name: c.name, description: c.description }))
        ];
      } catch (err) {
        console.warn('[AdsOrchestration] Concepts generation failed, using original brief as fallback.', err);
      }
    }

    // Calculate total images: concepts * models * variations
    const totalImages = conceptsList.length * imageModelIds.length * imageVariations;
    let completed = 0;

    setProgress({
      phase: 'image_phase',
      current: 0,
      total: totalImages,
      label: 'Generating images...',
    });

    const completedSlots: MediaSlot[] = [];

    // Loop over each version/concept sequentially
    for (const version of conceptsList) {
      if (signal.aborted) break;

      const isAnchor = version.name === 'Original';
      const fullPrompt = isAnchor
        ? version.description
        : `${version.description}. Product: ${brief}`;

      // Fire all models in parallel for this concept
      const modelTasks = imageModelIds.map(async (modelId) => {
        try {
          const registryModel = imageModels.find((m) => m.id === modelId);
          const resolvedProvider = registryModel?.provider ?? '';

          if (!resolvedProvider) {
            console.error(`[AdsOrchestration] No provider found for model ${modelId} — skipping.`);
            completed += imageVariations;
            return;
          }

          // Variations run sequentially within each model (rate-limit safe)
          for (let v = 0; v < imageVariations; v++) {
            if (signal.aborted) return;

            const placeholderId = crypto.randomUUID();
            const placeholder: MediaSlot = {
              id: placeholderId,
              type: 'image',
              status: 'processing',
              modelName: registryModel?.name ?? modelId,
              modelId,
              provider: resolvedProvider,
              prompt: fullPrompt,
              conceptName: version.name,
            };

            // Use functional updater to safely add placeholder
            setMediaSlots((prev) => [...prev, placeholder]);

            try {
              const payload = {
                prompt: fullPrompt,
                model: modelId,
                provider: resolvedProvider,
                brandContext: brandCtx,
                ...(refUrls.length > 0 ? { inputUrls: refUrls, referenceImageIntents: refIntents } : {}),
              };

              let result: any;
              if (resolvedProvider === 'kieai') {
                // Async path: create the task, poll every 5s (12-min cap,
                // tolerate transient poll errors). Short requests survive
                // shared-hosting timeouts and worker limits.
                const task: any = await createImageTaskMutation.mutateAsync(payload);
                const deadline = Date.now() + 12 * 60_000;
                let pollErrors = 0;
                result = null;
                while (Date.now() < deadline) {
                  if (signal.aborted) throw new Error('Generation cancelled.');
                  await new Promise((r) => setTimeout(r, 5000));
                  let poll: any;
                  try {
                    poll = await imageTaskResultMutation.mutateAsync({
                      ...payload,
                      prompt: task.prompt ?? fullPrompt,
                      taskId: task.taskId,
                    });
                  } catch (pollError) {
                    if (++pollErrors >= 3) throw pollError;
                    continue;
                  }
                  pollErrors = 0;
                  if (poll.status === 'completed') { result = poll.asset; break; }
                  if (poll.status === 'failed') {
                    throw new Error(poll.error || 'Generation failed.');
                  }
                }
                if (!result) {
                  throw new Error('Timed out after 12 minutes — the task may still finish on Kie.ai.');
                }
              } else {
                result = await generateImageMutation.mutateAsync(payload);
              }

              // Update placeholder with completed result
              const completedSlot: MediaSlot = {
                ...placeholder,
                status: 'complete',
                url: (result as any).url,
                thumbnailUrl: (result as any).url,
                prompt: (result as any).prompt ?? fullPrompt,
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
              label: `Images: ${completed}/${totalImages} (${version.name})`,
            });
          }
        } catch (modelError) {
          console.error(`[AdsOrchestration] Model ${modelId} failed:`, modelError);
        }
      });

      // Wait for all models to finish for this concept before moving to next
      await Promise.allSettled(modelTasks);
    }

    return completedSlots;
  }

// (Removed compose phase)
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
    if (params.autoOptimizeBrief || params.numVersions > 1) {
      if (!settings?.defaultImageTextModel) {
        toast.error('Menu Intelligence saknas. Välj en modell i Settings → Module Defaults.');
        return;
      }
    }

    // Reset state
    setError(null);
    setTextSlots([]);
    setMediaSlots([]);

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
      const images = await runImagePhase(params, controller.signal);

      if (images.length === 0) {
        throw new Error('No images were generated. Check your image models and try again.');
      }

      // Composition phase removed - assets are kept decoupled

      // Phase 4: Video (fishbone — skip for now)
      // if (params.videoModelId) {
      //   composed = await runVideoPhase(composed, params.videoModelId, controller.signal);
      //   setCreatives(composed);
      // }

      setPhase('complete');
      toast.success(`${images.length} visuals and ${texts.length} copy variants generated!`);
    } catch (err) {
      if (controller.signal.aborted) return; // User cancelled

      const message = err instanceof Error ? err.message : 'Generation failed';
      setError(message);
      setPhase('error');
      toast.error(message);
      console.error('[AdsOrchestration] Generate failed:', err);
    }
  }, [generateImageMutation, createImageTaskMutation, imageTaskResultMutation, imageModels, settings, optimizeBriefMutation, generateConceptsMutation]);

  // ── Update text (inline editing) ──
  const updateTextSlot = useCallback(
    (slotId: string, updates: { headline?: string; body?: string; cta?: string; description?: string; hashtags?: string[] }) => {
      setTextSlots((prev) =>
        prev.map((c) =>
          c.id === slotId
            ? {
                ...c,
                headline: updates.headline ?? c.headline,
                body: updates.body ?? c.body,
                cta: updates.cta !== undefined ? updates.cta : c.cta,
                description: updates.description !== undefined ? updates.description : c.description,
                hashtags: updates.hashtags !== undefined ? updates.hashtags : c.hashtags,
              }
            : c,
        ),
      );
    },
    [],
  );

  // ── Regenerate text slot (stub) ──
  const regenerateTextSlot = useCallback((slotId: string, instruction?: string) => {
    toast.info('Regenerate copy functionality will be implemented in the next iteration.');
  }, []);

  // ── Suggest Audiences (Backend Query) ──
  const generateAudiences = useCallback(async (params: {
    brief: string;
    textModelId: string;
    count: number;
    formValues: Record<string, string | number | undefined>;
  }) => {
    if (!params.brief.trim()) throw new Error('Please enter a creative brief first.');
    if (!params.textModelId) throw new Error('Please select a text model first.');
    const res = await suggestMutation.mutateAsync({
      type: 'audiences',
      count: params.count,
      formValues: { ...params.formValues, creativeBrief: params.brief },
      modelId: params.textModelId,
      module: 'ads',
    });
    return (res as any).items ?? [];
  }, [suggestMutation]);

  // ── Suggest Angles (Backend Query) ──
  const generateAngles = useCallback(async (params: {
    brief: string;
    textModelId: string;
    count: number;
    formValues: Record<string, string | number | undefined>;
    audiences: ListItem[];
  }) => {
    if (!params.brief.trim()) throw new Error('Please enter a creative brief first.');
    if (!params.textModelId) throw new Error('Please select a text model first.');
    const res = await suggestMutation.mutateAsync({
      type: 'angles',
      count: params.count,
      audiences: params.audiences.length > 0 ? params.audiences : undefined,
      formValues: { ...params.formValues, creativeBrief: params.brief },
      modelId: params.textModelId,
      module: 'ads',
    });
    return (res as any).items ?? [];
  }, [suggestMutation]);

  return {
    phase,
    progress,
    textSlots,
    mediaSlots,
    isGenerating,
    error,
    clearError,
    generate,
    cancel,
    updateTextSlot,
    regenerateTextSlot,
    generateAudiences,
    generateAngles,
  };
}
