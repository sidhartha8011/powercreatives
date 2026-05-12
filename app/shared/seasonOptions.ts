/**
 * SEASON OPTIONS
 *
 * Single source of truth for season/event options.
 * Used by both client (copyConfig.ts dropdown) and server (copyPrompts.ts label mapping).
 *
 * To add a new season: add an entry here — both frontend and backend pick it up automatically.
 */

// ============================================
// Season Definitions
// ============================================

export interface SeasonOption {
  /** Machine-readable value (stored in DB / sent to server) */
  value: string;
  /** Human-readable label (displayed in UI and injected into LLM prompts) */
  label: string;
}

/**
 * All available season/event options.
 * The first entry (value: '') represents "no season selected".
 */
export const SEASON_OPTIONS: SeasonOption[] = [
  { value: "", label: "None" },
  { value: "spring", label: "Spring Sale" },
  { value: "summer", label: "Summer Campaign" },
  { value: "fall", label: "Fall / Back to School" },
  { value: "winter", label: "Winter / Holiday" },
  { value: "black_friday", label: "Black Friday" },
  { value: "cyber_monday", label: "Cyber Monday" },
  { value: "valentines", label: "Valentine's Day" },
  { value: "easter", label: "Easter" },
  { value: "mothers_day", label: "Mother's Day" },
  { value: "fathers_day", label: "Father's Day" },
  { value: "new_year", label: "New Year" },
  { value: "custom", label: "Custom Event" },
];

// ============================================
// Derived Helpers
// ============================================

/**
 * Lookup map: season code → human-readable label.
 * Derived from SEASON_OPTIONS to avoid duplication.
 */
export const SEASON_LABEL_MAP: Record<string, string> = Object.fromEntries(
  SEASON_OPTIONS.filter((o) => o.value !== "").map((o) => [o.value, o.label]),
);

/**
 * Get the human-readable label for a season code.
 * Returns the code itself as fallback for unknown values.
 */
export function getSeasonLabel(code: string | undefined): string {
  if (!code) return "";
  return SEASON_LABEL_MAP[code] ?? code;
}
