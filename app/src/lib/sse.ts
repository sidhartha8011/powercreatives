/**
 * Shared SSE Stream Parser
 *
 * Generisk utility för att konsumera Server-Sent Events (SSE) från en
 * fetch() Response med ReadableStream. Återanvändbar av alla moduler
 * som behöver SSE-konsumtion (Writer, Copy, etc.).
 *
 * Hanterar:
 * - Chunked TextDecoder streaming
 * - Multi-line SSE data (per SSE spec)
 * - Event boundary detection (empty line)
 * - JSON parsing med graceful error logging
 *
 * @example
 *   const response = await fetch(url, { method: 'POST', ... });
 *   await parseSSEStream(response, (event, payload) => {
 *     if (event === 'done') handleResult(payload.result);
 *     if (event === 'error') handleError(payload.message);
 *   });
 */

/**
 * Parse a fetch Response body as an SSE stream and invoke the callback
 * for each fully-parsed event.
 *
 * @param response - The fetch Response whose body is a text/event-stream.
 * @param onEvent  - Callback invoked with (eventName, parsedJSONPayload).
 */
export async function parseSSEStream(
  response: Response,
  onEvent: (event: string, payload: any) => void,
): Promise<void> {
  const reader = response.body?.getReader();
  if (!reader) throw new Error('No response body — cannot read SSE stream.');

  const decoder = new TextDecoder();
  let buffer = '';
  let currentEvent = '';
  let currentData = '';

  while (true) {
    const { done, value } = await reader.read();
    if (done) break;

    buffer += decoder.decode(value, { stream: true });
    const lines = buffer.split('\n');
    // Keep the last (potentially incomplete) line in the buffer
    buffer = lines.pop() ?? '';

    for (const line of lines) {
      if (line.startsWith('event: ')) {
        // SSE event type line
        currentEvent = line.slice(7).trim();
      } else if (line.startsWith('data: ')) {
        // SSE data line — may be multi-line per spec (joined with newline)
        currentData += (currentData ? '\n' : '') + line.slice(6);
      } else if (line.trim() === '' && currentEvent) {
        // Empty line = end of SSE event — parse and dispatch
        try {
          const payload = JSON.parse(currentData);
          onEvent(currentEvent, payload);
        } catch (parseErr) {
          console.warn('[SSE] Failed to parse event data:', currentData, parseErr);
        }
        // Reset for next event
        currentEvent = '';
        currentData = '';
      }
      // Lines starting with ':' are SSE comments (heartbeats) — ignored
    }
  }
}
