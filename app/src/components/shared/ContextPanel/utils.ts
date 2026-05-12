import type { ContextData } from "./types";

/** Normalize URL for consistent duplicate detection */
export function normalizeUrl(url: string): string {
  try {
    const u = new URL(url.startsWith("http") ? url : `https://${url}`);
    return `${u.protocol}//${u.hostname}${u.pathname}`.replace(/\/$/, "");
  } catch {
    return url.trim().toLowerCase();
  }
}

/** Build initial empty context data */
export function createEmptyContextData(): ContextData {
  return {
    brandId: undefined,
    brand: null,
    url: "",
    scrapedData: null,
    seasonEvent: "",
    campaignTheme: "",
  };
}
