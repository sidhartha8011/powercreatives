/**
 * Shared poll-runner for async Kie.ai generation tasks.
 *
 * Shared hosts kill the blocking /image/generate-style requests on slow
 * models, so Kie generations run as: create task (fast) → poll task-result
 * (fast) until completed/failed. This helper owns the polling loop —
 * interval, hard cap, transient-error tolerance, and optional abort.
 *
 * The create/poll calls are passed in (tRPC mutations stay owned by the
 * calling hook) so this stays a pure async utility.
 */

export interface RunKieTaskOptions {
  /** Creates the upstream task; resolves to { taskId, prompt? }. */
  createTask: () => Promise<{ taskId: string; prompt?: string }>;
  /** Polls /image/task-result (or /video/task-result) with the given body. */
  pollTask: (body: Record<string, unknown>) => Promise<{
    status: string;
    asset?: unknown;
    error?: string;
    [key: string]: unknown;
  }>;
  /** Base body re-sent on every poll (taskId + resolved prompt are added). */
  payload: Record<string, unknown>;
  /** Poll interval in ms (default 5s; videos use 10s). */
  pollMs?: number;
  /** Hard cap in ms (default 12 min; videos use 20). */
  capMs?: number;
  /** Optional abort signal (e.g. the Ads orchestration's cancel). */
  signal?: AbortSignal;
}

/**
 * Resolves to the completed poll response (use `.asset` for image flows,
 * the full response for video). Throws on failure, timeout, or abort.
 */
export async function runKieTask(opts: RunKieTaskOptions): Promise<Record<string, unknown>> {
  const { createTask, pollTask, payload, pollMs = 5000, capMs = 12 * 60_000, signal } = opts;

  const task = await createTask();
  const deadline = Date.now() + capMs;
  let pollErrors = 0;

  while (Date.now() < deadline) {
    if (signal?.aborted) throw new Error('Generation cancelled.');
    await new Promise((r) => setTimeout(r, pollMs));
    let poll: Awaited<ReturnType<typeof pollTask>>;
    try {
      poll = await pollTask({
        ...payload,
        prompt: task.prompt ?? payload.prompt,
        taskId: task.taskId,
      });
    } catch (pollError) {
      // Transient poll failures (network blips) don't kill the upstream task.
      if (++pollErrors >= 3) throw pollError;
      continue;
    }
    pollErrors = 0;
    if (poll.status === 'completed') return poll;
    if (poll.status === 'failed') throw new Error(poll.error || 'Generation failed.');
  }

  throw new Error('Timed out — the task may still finish on Kie.ai.');
}
