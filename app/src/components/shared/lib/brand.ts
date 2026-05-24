/**
 * Brand display helpers.
 *
 * Pure functions for rendering brand "marks" (initials, mono-letter avatars)
 * consistently across modules that show a brand association.
 */

/**
 * Returns a single uppercase character to use as a brand mark when no logo
 * is available. Falls through to "?" only when input is genuinely empty —
 * not a silent fallback, but an explicit unknown signal.
 */
export function getBrandInitial(name: string | null | undefined): string {
  if (!name) return '?';
  const trimmed = name.trim();
  if (!trimmed) return '?';
  return trimmed.charAt(0).toUpperCase();
}
