/**
 * NICHE COLORS — Deterministic color palette for niche pills.
 *
 * Each unique niche string gets a consistent color (bg + text) based on
 * a hash of the niche name. This ensures the same niche always gets the
 * same color across sessions and renders.
 *
 * The palette uses soft, accessible background colors with darker text
 * for readability against white table backgrounds.
 */

/** Curated palette of soft pill colors: [bg, text, border] */
const NICHE_PALETTE: [string, string, string][] = [
  ["#EFF6FF", "#1D4ED8", "#BFDBFE"], // blue
  ["#F0FDF4", "#15803D", "#BBF7D0"], // green
  ["#FFF7ED", "#C2410C", "#FED7AA"], // orange
  ["#FAF5FF", "#7E22CE", "#E9D5FF"], // purple
  ["#FEF2F2", "#DC2626", "#FECACA"], // red
  ["#ECFDF5", "#047857", "#A7F3D0"], // emerald
  ["#FDF4FF", "#A21CAF", "#F5D0FE"], // fuchsia
  ["#FFFBEB", "#B45309", "#FDE68A"], // amber
  ["#F0F9FF", "#0369A1", "#BAE6FD"], // sky
  ["#FDF2F8", "#BE185D", "#FBCFE8"], // pink
  ["#F5F3FF", "#6D28D9", "#DDD6FE"], // violet
  ["#F0FDFA", "#0F766E", "#99F6E4"], // teal
  ["#FEFCE8", "#A16207", "#FEF08A"], // yellow
  ["#F1F5F9", "#475569", "#CBD5E1"], // slate
  ["#FFF1F2", "#E11D48", "#FECDD3"], // rose
];

/**
 * Simple string hash → index into palette.
 * Deterministic: same string always produces same index.
 */
function hashString(str: string): number {
  let hash = 0;
  for (let i = 0; i < str.length; i++) {
    const char = str.charCodeAt(i);
    hash = (hash << 5) - hash + char;
    hash |= 0; // Convert to 32-bit integer
  }
  return Math.abs(hash);
}

export interface NicheColor {
  bg: string;
  text: string;
  border: string;
}

/**
 * Get a deterministic color for a niche name.
 * Returns null if niche is null/empty.
 */
export function getNicheColor(niche: string | null): NicheColor | null {
  if (!niche) return null;
  const index = hashString(niche) % NICHE_PALETTE.length;
  const [bg, text, border] = NICHE_PALETTE[index];
  return { bg, text, border };
}

/**
 * Build a map of niche → color for a list of niches.
 * Useful for legend/filter UI.
 */
export function buildNicheColorMap(
  niches: string[]
): Map<string, NicheColor> {
  const map = new Map<string, NicheColor>();
  for (const niche of niches) {
    const color = getNicheColor(niche);
    if (color) map.set(niche, color);
  }
  return map;
}
