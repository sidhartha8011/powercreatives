import { clsx, type ClassValue } from "clsx";
import { twMerge } from "tailwind-merge";

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

/**
 * Extract a human-readable error message from an unknown catch value.
 *
 * Handles: Error instances, objects with .message, strings, and unknown types.
 * Use this in every catch block instead of inline instanceof checks.
 *
 * @param error  The caught value (unknown type).
 * @param fallback  Default message if no useful info can be extracted.
 * @returns A human-readable error string.
 *
 * @example
 * ```ts
 * catch (error) {
 *   toast.error(getErrorMessage(error, 'Failed to generate'));
 * }
 * ```
 */
export function getErrorMessage(error: unknown, fallback = 'An unexpected error occurred'): string {
  if (error instanceof Error) return error.message;
  if (typeof error === 'string') return error;
  if (typeof error === 'object' && error !== null && 'message' in error) {
    return String((error as { message: unknown }).message);
  }
  return fallback;
}

/**
 * Copy text to clipboard. Uses Clipboard API (HTTPS) with execCommand fallback (HTTP dev).
 * Pure utility — no React dependency. Use via `useClipboard` hook for component feedback.
 */
export async function copyToClipboard(text: string): Promise<boolean> {
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text);
      return true;
    }
  } catch { /* Clipboard API unavailable (insecure context) — fall through */ }

  // Fallback: hidden textarea + execCommand (works on HTTP)
  const el = document.createElement('textarea');
  el.value = text;
  el.style.cssText = 'position:fixed;left:-9999px;opacity:0';
  document.body.appendChild(el);
  el.select();
  const ok = document.execCommand('copy');
  document.body.removeChild(el);
  return ok;
}
