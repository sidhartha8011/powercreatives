/**
 * Which items a bulk "Generate (N)" actually generates.
 *
 * Pure and dependency-free ON PURPOSE — no React, no imports, no path aliases —
 * so tests/standalone/bulk_generate_test.mjs can import and execute THIS file
 * (node --experimental-strip-types) rather than a hand-copied transcription of
 * it. The sibling frontend tests transcribe their logic and pair it with static
 * source checks; that drifts. This cannot.
 *
 * The rules exist because a targeted generate is destructive. The server allows
 * a targeted call at ANY item status ("any current status is allowed",
 * strategy/service.php generate_next_item), so a naive loop over the ticked ids
 * would silently overwrite finished — possibly published — articles and would
 * double-generate items a worker had already claimed.
 */

/** The only fields the plan needs; keeps this file free of UI types. */
export interface GeneratableItem {
  id: number;
  status: string;
}

export interface BulkGeneratePlan {
  /** Items to generate, in selection order. */
  targets: GeneratableItem[];
  /** Selected items skipped because a worker already owns them ('generating'). */
  inFlight: number;
  /** Targets that already have an article — regenerating destroys it. */
  finished: GeneratableItem[];
  /** itemIds to POST, in order. Exactly one for a consolidated strategy. */
  calls: number[];
}

/**
 * An item whose article is already written. Three spellings are in the wild
 * ('written' | 'completed' | 'complete'), so the check lives in one place — the
 * overwrite confirm and isPublishableItem must agree on what "done" means, or
 * the confirm would miss items whose article it is about to destroy.
 */
export function isFinishedItemStatus(status: string): boolean {
  return status === 'written' || status === 'completed' || status === 'complete';
}

/**
 * @param items            Every item in the strategy.
 * @param selectedIds      The ticked ids.
 * @param consolidated     config.structure === 'consolidated' — ONE shared
 *                         article for every keyword, so N calls would
 *                         regenerate the same article N times (N expensive LLM
 *                         calls for one result). Collapses to a single call.
 * @param regenerateFinished  false => leave already-written articles alone.
 */
export function planBulkGenerate(
  items: GeneratableItem[],
  selectedIds: number[],
  consolidated: boolean,
  regenerateFinished: boolean,
): BulkGeneratePlan {
  const wanted = new Set(selectedIds);
  const selected = items.filter((it) => wanted.has(it.id));

  // Already claimed by a worker — re-issuing would double-generate.
  const candidates = selected.filter((it) => it.status !== 'generating');
  const inFlight = selected.length - candidates.length;

  const finished = candidates.filter((it) => isFinishedItemStatus(it.status));
  const targets = regenerateFinished
    ? candidates
    : candidates.filter((it) => !isFinishedItemStatus(it.status));

  const calls = consolidated
    ? (targets.length > 0 ? [targets[0].id] : [])
    : targets.map((it) => it.id);

  return { targets, inFlight, finished, calls };
}
