/**
 * ADS MODULE — SSE Text Parser Utility
 *
 * Extracted from useAdsOrchestration to keep hook under 400 lines.
 * Handles the SSE stream from POST /copy/generate and converts
 * results into TextSlot[] progressively.
 *
 * SSE event format matches Copy module's controller.php:
 *   - init:     { totalCount, audiences, angles }
 *   - progress: { current, total, label }
 *   - result:   { id, headline, body, cta, hashtags, audienceName, angleName, modelUsed }
 *   - done:     { completedCount, failedCount, totalCount }
 *   - error:    { message }
 */

import type { TextSlot, AdsProgress } from '../types';

// ============================================================================
// TextSlot mapper — matches Copy backend SSE 'result' payload shape
// ============================================================================

/**
 * Convert a single SSE 'result' event payload into a TextSlot.
 * Fields match the Copy controller's CopyVariation output.
 */
export function toTextSlot(payload: {
  id: number;
  headline: string;
  body: string;
  cta: string;
  hashtags: string[];
  audienceName: string;
  angleName: string;
  modelUsed: string;
  description?: string;
}): TextSlot {
  return {
    id: String(payload.id),
    headline: payload.headline || '',
    body: payload.body || '',
    cta: payload.cta || undefined,
    hashtags: Array.isArray(payload.hashtags) && payload.hashtags.length > 0
      ? payload.hashtags
      : undefined,
    audienceName: payload.audienceName || undefined,
    angleName: payload.angleName || undefined,
    modelUsed: payload.modelUsed || '',
  };
}

// ============================================================================
// SSE Stream Reader
// ============================================================================

export interface SSECallbacks {
  /** Called when progress updates arrive */
  onProgress: (progress: AdsProgress) => void;
  /** Called each time a new text slot is parsed from the stream */
  onTextSlot: (slot: TextSlot, allSlots: TextSlot[]) => void;
}

/**
 * Read and parse an SSE stream from /copy/generate.
 * Returns the collected TextSlot[] after the stream completes.
 *
 * Protocol follows the exact same format as useCopyGeneration's
 * SSE parser, but only extracts the fields relevant for Ads.
 */
export async function parseTextSSEStream(
  response: Response,
  callbacks: SSECallbacks,
): Promise<TextSlot[]> {
  const reader = response.body?.getReader();
  if (!reader) throw new Error('No response body from copy/generate');

  const decoder = new TextDecoder();
  let buffer = '';
  let currentEvent = '';
  let currentData = '';
  const collectedTexts: TextSlot[] = [];

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
        // Multi-line data: SSE spec allows multiple "data:" lines per event
        currentData += (currentData ? '\n' : '') + line.slice(6);
      } else if (line.trim() === '' && currentEvent) {
        // Empty line = end of event — process it
        try {
          const payload = JSON.parse(currentData);

          switch (currentEvent) {
            case 'init':
              callbacks.onProgress({
                phase: 'text_phase',
                current: 0,
                total: payload.totalCount ?? 0,
                label: 'Starting copy generation...',
              });
              break;

            case 'progress':
              callbacks.onProgress({
                phase: 'text_phase',
                current: payload.current ?? 0,
                total: payload.total ?? 0,
                label: payload.label || 'Generating copy...',
              });
              break;

            case 'result':
              if (!payload.error) {
                const slot = toTextSlot(payload);
                collectedTexts.push(slot);
                callbacks.onTextSlot(slot, [...collectedTexts]);
              }
              break;

            case 'done':
              callbacks.onProgress({
                phase: 'text_phase',
                current: payload.completedCount ?? collectedTexts.length,
                total: payload.totalCount ?? collectedTexts.length,
                label: `Copy: ${payload.completedCount ?? collectedTexts.length} generated`,
              });
              break;

            case 'error':
              console.warn('[AdsOrchestration] SSE error event:', payload.message);
              break;
          }
        } catch (parseErr) {
          console.warn('[AdsOrchestration] Failed to parse SSE data:', currentData);
        }

        // Reset for next event
        currentEvent = '';
        currentData = '';
      }
    }
  }

  return collectedTexts;
}
