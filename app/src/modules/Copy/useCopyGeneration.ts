/**
 * USE COPY GENERATION HOOK
 *
 * Single hook that manages the entire copy generation lifecycle.
 * Bridges the frontend UI to the tRPC copy router.
 *
 * Responsibilities:
 *   - Collect form state → call trpc.copy.generate
 *   - Track generation progress (isGenerating, progress)
 *   - Store results in CopyResults format for the ResultsPanel
 *   - Handle card-level regeneration via trpc.copy.regenerateCard
 *   - Handle batch regeneration via trpc.copy.regenerateBatch
 *   - Handle audience duplication via trpc.copy.duplicateAudience
 *   - Handle inline text editing via trpc.copy.updateResult
 *   - Manage audiences/angles from auto-generation responses
 */

import { useState, useCallback, useRef } from 'react';
import { trpc } from '@/lib/trpc';
import type { ListItem, AngleItem } from '@/components/shared';
import type { GenerationMode } from '@/components/shared';
import type {
  CopyType,
  CopyVariation,
  CopyResults,
  CopyFormValues,
} from './types';

export type GenerateScope = 'all_types' | 'current_type' | 'current_audience';

export interface GenerateParams {
  copyTypes: CopyType[];
  audiences: {
    mode: GenerationMode;
    items: ListItem[];
    count: number;
  };
  angles: {
    mode: GenerationMode;
    items: AngleItem[];
    count: number;
  };
  modelId: string;
  formValues: CopyFormValues;
  /** Whether to enable research step for audience generation */
  useResearch?: boolean;
  /** Model ID for research step (empty = use generation model) */
  researchModelId?: string;
  scope: GenerateScope;
  scopeFilter?: {
    type?: CopyType;
    audienceId?: string;
  };
  visualAssets?: {
    colors?: string[];
    logo?: string;
    referenceImages?: Array<{ url: string; intent: string }>;
  };
}

export interface GenerationProgress {
  current: number;
  total: number;
  label: string;
}

export interface UseCopyGenerationReturn {
  /** Whether a generation is currently in progress */
  isGenerating: boolean;
  /** Progress of the current generation */
  progress: GenerationProgress | null;
  /** Generated results grouped by copy type */
  results: CopyResults;
  /** Audiences resolved from the last generation (may include auto-generated) */
  resolvedAudiences: ListItem[];
  /** Angles resolved from the last generation (may include auto-generated, with audienceId) */
  resolvedAngles: AngleItem[];
  /** Start a new generation */
  generate: (params: GenerateParams) => Promise<void>;
  /** Regenerate a single card */
  regenerateCard: (
    variationId: string,
    formValues: CopyFormValues,
    instruction?: string,
  ) => Promise<void>;
  /** Regenerate multiple cards in a batch */
  regenerateBatch: (
    variationIds: string[],
    formValues: CopyFormValues,
    instruction?: string,
  ) => Promise<void>;
  /** Duplicate an audience (copy all results to a new audience name) */
  duplicateAudience: (audienceId: string, audienceName: string) => Promise<void>;
  /** Rename an audience (updates DB + local state so regeneration uses the new name) */
  renameAudience: (audienceId: string, newName: string) => Promise<void>;
  /**
   * Duplicate a single copy card by variationId.
   * Pure state mutation — inserts an identical copy directly after the original.
   * No backend call: copy cards are not persisted in the DB.
   */
  duplicateCard: (variationId: string) => void;
  /** Update text fields of a single card (inline editing) */
  updateCardText: (
    variationId: string,
    updates: { headline?: string; body?: string; cta?: string; hashtags?: string; description?: string },
  ) => Promise<void>;
  /** Set of card IDs currently being regenerated */
  regeneratingCards: Set<string>;
  /** Last error message, if any */
  error: string | null;
  /** Clear error */
  clearError: () => void;
  /** Whether we have any real (non-mock) results */
  hasResults: boolean;
  /** The last job ID from the most recent generation */
  lastJobId: number | null;
  /** Research status from last generation (from SSE init event) */
  lastResearchStatus: string | null;
  /** Research error/skip reason from last generation */
  lastResearchError: string | null;
}

/**
 * Map a server result to the frontend CopyVariation type.
 * Defensively handles null/undefined fields from LLM responses.
 */
function toVariation(result: {
  id: number;
  jobId: number;
  copyType: string;
  audienceId: string;
  audienceName: string;
  angleName: string;
  headline: string;
  body: string;
  cta: string;
  hashtags: string[];
  description: string;
  modelUsed: string;
  error: string | null;
  createdAt: Date;
}): CopyVariation {
  const hashtags = Array.isArray(result.hashtags) ? result.hashtags : [];
  return {
    id: String(result.id),
    copyType: (result.copyType || 'social_ads') as CopyType,
    audienceId: result.audienceId || '',
    angleName: result.angleName || '',
    headline: result.headline || '',
    body: result.body || '',
    cta: result.cta || undefined,
    hashtags: hashtags.length > 0 ? hashtags : undefined,
    description: result.description || undefined,
    modelUsed: result.modelUsed || '',
    createdAt: result.createdAt ? new Date(result.createdAt) : new Date(),
  };
}

/**
 * Group an array of variations by copyType into a CopyResults map.
 */
function groupByCopyType(variations: CopyVariation[]): CopyResults {
  const grouped: CopyResults = {};
  for (const v of variations) {
    if (!grouped[v.copyType]) grouped[v.copyType] = [];
    grouped[v.copyType]!.push(v);
  }
  return grouped;
}

// ── SSE Event Types ──────────────────────────────────────
// These match the events sent by controller.php via PCM_SSE.

interface SSESetters {
  setProgress: (p: GenerationProgress) => void;
  setLastJobId: (id: number | null) => void;
  lastJobIdRef: React.MutableRefObject<number | null>;
  setResolvedAudiences: (items: ListItem[]) => void;
  setResolvedAngles: (items: AngleItem[]) => void;
  setResults: React.Dispatch<React.SetStateAction<CopyResults>>;
  setHasResults: (v: boolean) => void;
  setError: (msg: string | null) => void;
  setLastResearchStatus: (status: string | null) => void;
  setLastResearchError: (error: string | null) => void;
}

/**
 * Process a single SSE event from the copy generation stream.
 *
 * Event types:
 *   - init:     Metadata (jobId, totalCount, audiences, angles)
 *   - progress: { current, total, label }
 *   - result:   Single completed copy result (may have error)
 *   - done:     Final summary { jobId, completedCount, failedCount, totalCount }
 *   - error:    { message }
 */
function handleSSEEvent(
  event: string,
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  payload: any,
  params: GenerateParams,
  allVariations: CopyVariation[],
  s: SSESetters,
): void {
  switch (event) {
    case 'init': {
      // Store job ID
      s.setLastJobId(payload.jobId);
      s.lastJobIdRef.current = payload.jobId;
      s.setProgress({ current: 0, total: payload.totalCount, label: 'Starting generation...' });

      // Update resolved audiences
      if (payload.audiences) {
        s.setResolvedAudiences(
          payload.audiences.map((a: { id: string; name: string }) => ({
            id: a.id,
            name: a.name,
          })),
        );
      }

      // Update resolved angles (preserve audienceId — Gap #4 fix)
      if (payload.angles) {
        s.setResolvedAngles(
          payload.angles.map((a: { id: string; name: string; audienceId?: string }) => ({
            id: a.id,
            name: a.name,
            audienceId: a.audienceId,
          })),
        );
      }

      // Update research status from backend (for research toggle icon)
      s.setLastResearchStatus(payload.researchStatus ?? null);
      s.setLastResearchError(payload.researchError ?? null);
      break;
    }

    case 'progress': {
      s.setProgress({
        current: payload.current,
        total: payload.total,
        label: payload.label || `Generating ${payload.current + 1} of ${payload.total}...`,
      });
      break;
    }

    case 'result': {
      // Skip error results
      if (payload.error) break;

      // Convert to CopyVariation and append
      const variation = toVariation(payload);
      allVariations.push(variation);

      // Progressively update results grouped by copy type
      const grouped = groupByCopyType(allVariations);

      s.setResults((prev) => {
        if (params.scope === 'all_types') {
          return grouped;
        } else if (params.scope === 'current_type' && params.scopeFilter?.type) {
          return {
            ...prev,
            [params.scopeFilter.type]: grouped[params.scopeFilter.type] ?? [],
          };
        } else if (params.scope === 'current_audience' && params.scopeFilter?.audienceId) {
          const targetType = params.scopeFilter.type ?? params.copyTypes[0];
          const prevTypeResults = prev[targetType] ?? [];
          const otherAudiences = prevTypeResults.filter(
            (v) => v.audienceId !== params.scopeFilter!.audienceId,
          );
          const newAudienceResults = (grouped[targetType] ?? []).filter(
            (v) => v.audienceId === params.scopeFilter!.audienceId,
          );
          return {
            ...prev,
            [targetType]: [...otherAudiences, ...newAudienceResults],
          };
        }
        return grouped;
      });

      s.setHasResults(true);
      break;
    }

    case 'done': {
      const { completedCount, failedCount, totalCount } = payload;
      s.setProgress({
        current: completedCount,
        total: totalCount,
        label: `Generated ${completedCount} of ${totalCount}`,
      });
      if (failedCount > 0) {
        s.setError(
          `${failedCount} of ${totalCount} generations failed. Successfully generated ${completedCount}.`,
        );
      }
      break;
    }

    case 'error': {
      s.setError(payload.message || 'Generation failed');
      break;
    }

    default:
      // Ignore unknown events (e.g. SSE comments, keep-alive)
      break;
  }
}

export function useCopyGeneration(): UseCopyGenerationReturn {
  const [isGenerating, setIsGenerating] = useState(false);
  const [progress, setProgress] = useState<GenerationProgress | null>(null);
  const [results, setResults] = useState<CopyResults>({});
  const [resolvedAudiences, setResolvedAudiences] = useState<ListItem[]>([]);
  const [resolvedAngles, setResolvedAngles] = useState<AngleItem[]>([]);
  const [regeneratingCards, setRegeneratingCards] = useState<Set<string>>(new Set());
  const [error, setError] = useState<string | null>(null);
  const [hasResults, setHasResults] = useState(false);
  const [lastJobId, setLastJobId] = useState<number | null>(null);
  const [lastResearchStatus, setLastResearchStatus] = useState<string | null>(null);
  const [lastResearchError, setLastResearchError] = useState<string | null>(null);

  // Keep a ref to lastJobId for use in callbacks without stale closures
  const lastJobIdRef = useRef<number | null>(null);

  // Note: generateMutation removed — SSE streaming uses fetch() directly
  const regenCardMutation = trpc.copy.regenerateCard.useMutation();
  const regenBatchMutation = trpc.copy.regenerateBatch.useMutation();
  const duplicateAudienceMutation = trpc.copy.duplicateAudience.useMutation();
  const renameAudienceMutation = trpc.copy.renameAudience.useMutation();
  const updateResultMutation = trpc.copy.updateResult.useMutation();

  // ── Generate (SSE streaming) ──
  // Uses fetch() + ReadableStream to consume progressive SSE events
  // from POST /copy/generate. Each 'result' event is appended to state
  // immediately, giving the user progressive card rendering.
  const generate = useCallback(
    async (params: GenerateParams) => {
      setIsGenerating(true);
      setError(null);
      setProgress({ current: 0, total: 0, label: 'Preparing...' });

      try {
        const input = {
          copyTypes: params.copyTypes,
          audiences: {
            mode: params.audiences.mode,
            items:
              params.audiences.mode === 'manual'
                ? params.audiences.items
                : undefined,
            count:
              params.audiences.mode === 'auto'
                ? params.audiences.count
                : undefined,
          },
          angles: {
            mode: params.angles.mode,
            items:
              params.angles.mode === 'manual'
                ? params.angles.items
                : undefined,
            count:
              params.angles.mode === 'auto'
                ? params.angles.count
                : undefined,
          },
          modelId: params.modelId,
          formValues: params.formValues,
          useResearch: params.useResearch,
          researchModelId: params.researchModelId,
          scope: params.scope,
          scopeFilter: params.scopeFilter,
          visualAssets: params.visualAssets,
        };

        // Build the WP REST URL for copy/generate
        const config = window.pcmConfig ?? { restUrl: '/wp-json/pcm/v1/', nonce: '' };
        const url = `${config.restUrl}copy/generate`;

        // POST with SSE stream response
        const response = await fetch(url, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': config.nonce,
          },
          body: JSON.stringify(input),
        });

        if (!response.ok) {
          throw new Error(`Server returned ${response.status}: ${response.statusText}`);
        }

        // Read the SSE stream line by line
        const reader = response.body?.getReader();
        if (!reader) throw new Error('No response body');

        const decoder = new TextDecoder();
        let buffer = '';
        let currentEvent = '';
        let currentData = '';
        const allVariations: import('./types').CopyVariation[] = [];

        // Reset results based on scope
        if (params.scope === 'all_types') {
          setResults({});
        }

        while (true) {
          const { done, value } = await reader.read();
          if (done) break;

          buffer += decoder.decode(value, { stream: true });
          const lines = buffer.split('\n');
          // Keep the last incomplete line in the buffer
          buffer = lines.pop() ?? '';

          for (const line of lines) {
            // SSE format: "event: <name>" or "data: <json>" or empty line (event boundary)
            if (line.startsWith('event: ')) {
              currentEvent = line.slice(7).trim();
            } else if (line.startsWith('data: ')) {
              // Multi-line data: SSE spec allows multiple "data:" lines per event.
              // Each line's content is joined with a newline per the SSE spec.
              currentData += (currentData ? '\n' : '') + line.slice(6);
            } else if (line.trim() === '' && currentEvent) {
              // Empty line = end of event — process it
              try {
                const payload = JSON.parse(currentData);
                handleSSEEvent(currentEvent, payload, params, allVariations, {
                  setProgress,
                  setLastJobId,
                  lastJobIdRef,
                  setResolvedAudiences,
                  setResolvedAngles,
                  setResults,
                  setHasResults,
                  setError,
                  setLastResearchStatus,
                  setLastResearchError,
                });
              } catch (parseErr) {
                console.warn('[CopyGeneration] Failed to parse SSE data:', currentData, parseErr);
              }
              // Reset for next event
              currentEvent = '';
              currentData = '';
            }
          }
        }
      } catch (err) {
        const message =
          err instanceof Error ? err.message : 'Generation failed';
        setError(message);
        console.error('[CopyGeneration] Generate failed:', err);
      } finally {
        setIsGenerating(false);
      }
    },
    [],
  );

  // ── Regenerate Card ──
  const regenerateCard = useCallback(
    async (
      variationId: string,
      formValues: CopyFormValues,
      instruction?: string,
    ) => {
      setRegeneratingCards((prev) => new Set(prev).add(variationId));
      setError(null);

      try {
        const resultId = parseInt(variationId, 10);
        if (isNaN(resultId)) {
          throw new Error('Invalid variation ID');
        }

        const response = await regenCardMutation.mutateAsync({
          resultId,
          formValues,
          instruction,
        });

        if (response) {
          const newVariation = toVariation(response);

          // Replace the old variation with the new one
          setResults((prev) => {
            const updated = { ...prev };
            const type = newVariation.copyType;
            const typeResults = [...(updated[type] ?? [])];
            const idx = typeResults.findIndex((v) => v.id === variationId);
            if (idx >= 0) {
              typeResults[idx] = newVariation;
            } else {
              typeResults.push(newVariation);
            }
            updated[type] = typeResults;
            return updated;
          });
        }
      } catch (err) {
        const message =
          err instanceof Error ? err.message : 'Regeneration failed';
        setError(message);
        console.error('[CopyGeneration] Regenerate card failed:', err);
      } finally {
        setRegeneratingCards((prev) => {
          const next = new Set(prev);
          next.delete(variationId);
          return next;
        });
      }
    },
    [regenCardMutation],
  );

  // ── Regenerate Batch ──
  const regenerateBatch = useCallback(
    async (
      variationIds: string[],
      formValues: CopyFormValues,
      instruction?: string,
    ) => {
      // Mark all cards as regenerating
      setRegeneratingCards((prev) => {
        const next = new Set(prev);
        for (const id of variationIds) next.add(id);
        return next;
      });
      setError(null);

      try {
        const resultIds = variationIds
          .map((id) => parseInt(id, 10))
          .filter((id) => !isNaN(id));

        if (resultIds.length === 0) {
          throw new Error('No valid variation IDs');
        }

        const response = await regenBatchMutation.mutateAsync({
          resultIds,
          formValues,
          instruction,
        });

        // Replace old variations with new ones
        setResults((prev) => {
          const updated = { ...prev };
          for (const item of response.results) {
            if (!item.newResult) continue;
            const newVariation = toVariation(item.newResult);
            const type = newVariation.copyType;
            const typeResults = [...(updated[type] ?? [])];
            const originalId = String(item.originalId);
            const idx = typeResults.findIndex((v) => v.id === originalId);
            if (idx >= 0) {
              typeResults[idx] = newVariation;
            } else {
              typeResults.push(newVariation);
            }
            updated[type] = typeResults;
          }
          return updated;
        });

        if (response.failedCount > 0) {
          setError(
            `${response.failedCount} of ${response.totalCount} batch regenerations failed.`,
          );
        }
      } catch (err) {
        const message =
          err instanceof Error ? err.message : 'Batch regeneration failed';
        setError(message);
        console.error('[CopyGeneration] Regenerate batch failed:', err);
      } finally {
        setRegeneratingCards((prev) => {
          const next = new Set(prev);
          for (const id of variationIds) next.delete(id);
          return next;
        });
      }
    },
    [regenBatchMutation],
  );

  // ── Duplicate Audience ──
  const duplicateAudience = useCallback(
    async (audienceId: string, audienceName: string) => {
      setError(null);

      const jobId = lastJobIdRef.current;
      if (!jobId) {
        setError('No active job to duplicate from');
        return;
      }

      try {
        const response = await duplicateAudienceMutation.mutateAsync({
          jobId,
          audienceId,
          audienceName,
        });

        // Add the new audience to resolved audiences
        setResolvedAudiences((prev) => [
          ...prev,
          { id: response.newAudienceId, name: response.newAudienceName },
        ]);

        // Add new results to the results state
        const newVariations = response.results
          .filter((r: { error: string | null }) => !r.error)
          .map(toVariation);

        setResults((prev) => {
          const updated = { ...prev };
          for (const v of newVariations) {
            if (!updated[v.copyType]) updated[v.copyType] = [];
            updated[v.copyType]!.push(v);
          }
          return updated;
        });
      } catch (err) {
        const message =
          err instanceof Error ? err.message : 'Audience duplication failed';
        setError(message);
        console.error('[CopyGeneration] Duplicate audience failed:', err);
      }
    },
    [duplicateAudienceMutation],
  );

  // ── Duplicate Card (pure state mutation) ──
  // Finds the variation by ID and inserts an identical clone directly after it.
  // No backend call — copy cards are not persisted in the DB, so duplication
  // is a client-side-only operation.
  const duplicateCard = useCallback(
    (variationId: string) => {
      setResults((prev) => {
        const updated = { ...prev };
        for (const type of Object.keys(updated) as CopyType[]) {
          const arr = updated[type];
          if (!arr) continue;
          const idx = arr.findIndex((v) => v.id === variationId);
          if (idx === -1) continue;
          const original = arr[idx];
          // Create a deep clone with a new client-only ID and a descriptive angleName.
          // The id uses a timestamp suffix to guarantee uniqueness in local state.
          const clone: CopyVariation = {
            ...original,
            id: `${original.id}-copy-${Date.now()}`,
            angleName: `Copy of ${original.angleName ?? 'Card'}`,
            createdAt: new Date(),
          };
          updated[type] = [...arr.slice(0, idx + 1), clone, ...arr.slice(idx + 1)];
          break;
        }
        return updated;
      });
    },
    [],
  );

  // ── Rename Audience ──
  const renameAudience = useCallback(
    async (audienceId: string, newName: string) => {
      setError(null);

      const jobId = lastJobIdRef.current;
      if (!jobId) {
        setError('No active job to rename audience in');
        return;
      }

      // Optimistic update: update resolvedAudiences immediately
      setResolvedAudiences((prev) =>
        prev.map((a) => (a.id === audienceId ? { ...a, name: newName } : a)),
      );

      // Optimistic update: update audienceName on all matching results
      setResults((prev) => {
        const updated = { ...prev };
        for (const type of Object.keys(updated) as CopyType[]) {
          const typeResults = updated[type];
          if (!typeResults) continue;
          const hasMatch = typeResults.some((v) => v.audienceId === audienceId);
          if (hasMatch) {
            updated[type] = typeResults.map((v) =>
              v.audienceId === audienceId ? { ...v, audienceName: newName } : v,
            );
          }
        }
        return updated;
      });

      try {
        await renameAudienceMutation.mutateAsync({
          jobId,
          audienceId,
          newName,
        });
      } catch (err) {
        const message =
          err instanceof Error ? err.message : 'Failed to rename audience';
        setError(message);
        console.error('[CopyGeneration] Rename audience failed:', err);
        // Note: we don't rollback the optimistic update here because
        // the user would need to re-type anyway. The error message is shown.
      }
    },
    [renameAudienceMutation],
  );

  // ── Update Card Text (Inline Editing) ──
  const updateCardText = useCallback(
    async (
      variationId: string,
      updates: { headline?: string; body?: string; cta?: string; hashtags?: string; description?: string },
    ) => {
      setError(null);

      const resultId = parseInt(variationId, 10);
      if (isNaN(resultId)) {
        setError('Invalid variation ID');
        return;
      }

      // Optimistic update: immediately update local state
      const previousResults = { ...results };
      setResults((prev) => {
        const updated = { ...prev };
        for (const type of Object.keys(updated) as CopyType[]) {
          const typeResults = updated[type];
          if (!typeResults) continue;
          const idx = typeResults.findIndex((v) => v.id === variationId);
          if (idx >= 0) {
            const current = typeResults[idx];
            updated[type] = [...typeResults];
            updated[type]![idx] = {
              ...current,
              headline: updates.headline ?? current.headline,
              body: updates.body ?? current.body,
              cta: updates.cta !== undefined ? updates.cta || undefined : current.cta,
              hashtags: updates.hashtags !== undefined
                ? updates.hashtags.split(',').filter(Boolean)
                : current.hashtags,
              description: updates.description !== undefined
                ? updates.description || undefined
                : current.description,
            };
            break;
          }
        }
        return updated;
      });

      try {
        await updateResultMutation.mutateAsync({
          resultId,
          ...updates,
        });
      } catch (err) {
        // Rollback on error
        setResults(previousResults);
        const message =
          err instanceof Error ? err.message : 'Failed to save edits';
        setError(message);
        console.error('[CopyGeneration] Update card text failed:', err);
      }
    },
    [updateResultMutation, results],
  );

  const clearError = useCallback(() => setError(null), []);

  return {
    isGenerating,
    progress,
    results,
    resolvedAudiences,
    resolvedAngles,
    generate,
    regenerateCard,
    regenerateBatch,
    duplicateAudience,
    renameAudience,
    duplicateCard,
    updateCardText,
    regeneratingCards,
    error,
    clearError,
    hasResults,
    lastJobId,
    lastResearchStatus,
    lastResearchError,
  };
}
